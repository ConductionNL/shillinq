<?php

/**
 * Booking SMS reminder runner.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Finds the appointments whose SMS reminder is due on each active SMS
 * channel and hands each to SmsReminderDispatcher, which sends it to the
 * provider bound to SmsProviderAdapterInterface. The outcome is recorded on
 * the appointment, so one appointment is reminded once per channel run.
 *
 * While the bound provider is the log adapter no message can leave the
 * instance: the runner then sends nothing and marks every active channel as
 * not connected, instead of recording a send that never happened
 * (REQ-SMS-021).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-messages-and-embedding/specs/notification-booking-sms-reminders/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sms;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the due SMS reminders of every active channel and records each outcome.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/specs/notification-booking-sms-reminders/spec.md
 */
class SmsReminderRunner {
	/**
	 * The channel state while a real provider is bound.
	 */
	public const CONNECTED = 'connected';

	/**
	 * The channel state while the bound provider is the log adapter.
	 */
	public const NOT_CONNECTED = 'not-connected';

	/**
	 * Minutes before the start a channel sends when it declares none.
	 */
	private const DEFAULT_MINUTES_BEFORE = 1440;

	/**
	 * At most this many appointments per channel per run.
	 */
	private const PAGE = 200;

	/**
	 * The time zone the date and time in a message are written in.
	 */
	private const MESSAGE_TIMEZONE = 'Europe/Amsterdam';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface      $objectService OpenRegister's object service.
	 * @param IAppConfig                  $appConfig     The register slug.
	 * @param SmsReminderDispatcher       $dispatcher    Gates, renders and sends one reminder.
	 * @param SmsProviderAdapterInterface $adapter       The bound provider; the log adapter means not connected.
	 * @param LoggerInterface             $logger        Logger (never a number or a body).
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly SmsReminderDispatcher $dispatcher,
		private readonly SmsProviderAdapterInterface $adapter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a real SMS provider is bound; the log adapter sends nothing.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
	 */
	public function isConnected(): bool {
		return ($this->adapter instanceof LogSmsProviderAdapter) === false;
	}//end isConnected()

	/**
	 * Send every due reminder and answer what happened, per outcome status.
	 *
	 * @param DateTimeImmutable $now The time of the run.
	 *
	 * @return array<string, int> Counts: channels, notConnected, and one per send status.
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
	 */
	public function run(DateTimeImmutable $now): array {
		$report = ['channels' => 0, 'notConnected' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];
		$state = self::NOT_CONNECTED;
		if ($this->isConnected() === true) {
			$state = self::CONNECTED;
		}

		foreach ($this->activeChannels() as $channel) {
			$report['channels']++;
			$this->markConnection(channel: $channel, state: $state);
			if ($state === self::NOT_CONNECTED) {
				$report['notConnected']++;
				continue;
			}

			foreach ($this->dueAppointments(channel: $channel, now: $now) as $appointment) {
				$status = $this->remind(channel: $channel, appointment: $appointment, now: $now);
				$report[$status] = (($report[$status] ?? 0) + 1);
			}
		}

		return $report;
	}//end run()

	/**
	 * Send one reminder and record its outcome on the appointment.
	 *
	 * @param array<string, mixed> $channel     The channel.
	 * @param array<string, mixed> $appointment The appointment.
	 * @param DateTimeImmutable    $now         The time of the run.
	 *
	 * @return string The outcome status.
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/specs/notification-booking-sms-reminders/spec.md#requirement-a-reminder-sms-goes-to-the-configured-provider-at-its-send-time-req-sms-021
	 */
	private function remind(array $channel, array $appointment, DateTimeImmutable $now): string {
		$result = $this->dispatcher->dispatch(
			channel: $channel,
			recipient: [
				'phone' => (string)($appointment['customerPhone'] ?? ''),
				'smsOptOut' => (bool)($appointment['smsOptOut'] ?? false),
			],
			variables: self::variables(appointment: $appointment)
		);

		$appointment['smsReminderStatus'] = $result->status;
		$appointment['smsReminderAt'] = $now->format('c');
		$appointment['smsReminderReference'] = $result->providerReference;
		$this->save(object: $appointment, schema: 'Appointment');

		return $result->status;
	}//end remind()

	/**
	 * The template variables of an appointment.
	 *
	 * @param array<string, mixed> $appointment The appointment.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
	 */
	private static function variables(array $appointment): array {
		$variables = [
			'customerName' => (string)($appointment['customerName'] ?? ''),
			'bookingRef' => (string)($appointment['appointmentId'] ?? ($appointment['id'] ?? '')),
			'bookingLocation' => (string)($appointment['location'] ?? ''),
			'bookingDate' => '',
			'bookingTime' => '',
		];

		$start = self::time(value: ($appointment['startTime'] ?? null));
		if ($start !== null) {
			$local = $start->setTimezone(new DateTimeZone(self::MESSAGE_TIMEZONE));
			$variables['bookingDate'] = $local->format('d-m-Y');
			$variables['bookingTime'] = $local->format('H:i');
		}

		return $variables;
	}//end variables()

	/**
	 * The confirmed appointments starting within the channel's lead time that were not reminded yet.
	 *
	 * @param array<string, mixed> $channel The channel.
	 * @param DateTimeImmutable    $now     The time of the run.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
	 */
	private function dueAppointments(array $channel, DateTimeImmutable $now): array {
		$minutes = (int)($channel['sendMinutesBefore'] ?? self::DEFAULT_MINUTES_BEFORE);
		if ($minutes <= 0) {
			$minutes = self::DEFAULT_MINUTES_BEFORE;
		}

		$utc = $now->setTimezone(new DateTimeZone('UTC'));
		$rows = $this->findAll(
			schema: 'Appointment',
			filters: [
				'status' => 'confirmed',
				'startTime[gt]' => $utc->format('c'),
				'startTime[lte]' => $utc->modify('+' . $minutes . ' minutes')->format('c'),
			]
		);

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => (string)($row['smsReminderStatus'] ?? '') === ''
			)
		);
	}//end dueAppointments()

	/**
	 * The active SMS channels.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
	 */
	private function activeChannels(): array {
		return $this->findAll(schema: 'BookingSmsReminderChannel', filters: ['status' => 'active']);
	}//end activeChannels()

	/**
	 * Write the connection state on a channel when it differs from what it shows.
	 *
	 * @param array<string, mixed> $channel The channel.
	 * @param string               $state   CONNECTED or NOT_CONNECTED.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/specs/notification-booking-sms-reminders/spec.md#requirement-a-reminder-sms-goes-to-the-configured-provider-at-its-send-time-req-sms-021
	 */
	private function markConnection(array $channel, string $state): void {
		if (($channel['connectionState'] ?? null) === $state) {
			return;
		}

		$channel['connectionState'] = $state;
		$this->save(object: $channel, schema: 'BookingSmsReminderChannel');
	}//end markConnection()

	/**
	 * Objects of one schema as arrays; an unavailable store answers none.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$found = $this->objectService
				->setRegister($this->registerSlug())
				->setSchema($schema)
				->findAll(['filters' => $filters, 'limit' => self::PAGE]);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: SMS reminders could not read ' . $schema, ['exception' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach ($found as $object) {
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			if (is_array($object) === true) {
				$rows[] = $object;
			}
		}

		return $rows;
	}//end findAll()

	/**
	 * Save one object, logging a failed write.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string               $schema The schema.
	 *
	 * @return void
	 */
	private function save(array $object, string $schema): void {
		$uuid = null;
		if ((string)($object['id'] ?? '') !== '') {
			$uuid = (string)$object['id'];
		}

		try {
			$this->objectService->saveObject(
				object: $object,
				register: $this->registerSlug(),
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
			);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: an SMS reminder outcome could not be saved on ' . $schema, ['exception' => $e->getMessage()]);
		}
	}//end save()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function registerSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end registerSlug()

	/**
	 * A date-time value, or null when it does not parse.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function time(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end time()
}//end class
