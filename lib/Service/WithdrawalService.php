<?php

/**
 * Withdrawal Service
 *
 * Whether a consumer may still withdraw from a booking made online, and why
 * not when they may not (sales-cancellation REQ-SCX-005, design D5).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The withdrawal rule.
 *
 * An appointment is withdrawable when a consumer booked it through the booking
 * widget, fewer than 14 days have passed since it was booked, it has not
 * started, it is still booked, and its service is not marked exempt with a
 * named reason. Every "no" names its reason, so the portal can say why the
 * button is absent and offer the ordinary cancellation instead.
 *
 * A widget booking carries a name, an e-mail address and a phone number and no
 * company or VAT number, so a booking through the widget is a consumer's.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
 */
class WithdrawalService {

	/**
	 * The withdrawal period for a service, in days from the booking (Directive 2011/83/EU, article 9).
	 *
	 * @var int
	 */
	public const PERIOD_DAYS = 14;

	/**
	 * Withdrawable.
	 *
	 * @var string
	 */
	public const WITHDRAWABLE = 'withdrawable';

	/**
	 * Not booked online through the widget.
	 *
	 * @var string
	 */
	public const NOT_ONLINE = 'not-booked-online';

	/**
	 * The withdrawal period has ended.
	 *
	 * @var string
	 */
	public const PERIOD_ENDED = 'period-ended';

	/**
	 * The appointment has started.
	 *
	 * @var string
	 */
	public const STARTED = 'started';

	/**
	 * The appointment is no longer booked (cancelled or completed).
	 *
	 * @var string
	 */
	public const NOT_BOOKED = 'not-booked';

	/**
	 * The service is exempt from withdrawal.
	 *
	 * @var string
	 */
	public const EXEMPT = 'exempt';

	/**
	 * The appointment states that are still booked.
	 *
	 * @var list<string>
	 */
	private const BOOKED_STATES = ['pending_confirmation', 'confirmed'];

	/**
	 * OpenRegister's object service, read through the container.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'shillinq';

	/**
	 * The deposit states in which money was taken and is refunded.
	 *
	 * @var list<string>
	 */
	private const PAID_DEPOSIT_STATES = ['authorized', 'captured'];

	/**
	 * Constructor.
	 *
	 * @param IL10N                          $l10n         Translates the reasons.
	 * @param ContainerInterface|null        $container    The container, for OpenRegister's object service.
	 * @param CancellationService|null       $cancellation Applies the cancellation.
	 * @param DepositPaymentAdapterInterface|null $deposits Refunds a deposit.
	 * @param LoggerInterface|null           $logger       The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly ?ContainerInterface $container = null,
		private readonly ?CancellationService $cancellation = null,
		private readonly ?DepositPaymentAdapterInterface $deposits = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Withdraw the consumer from an appointment: cancel without a fee, refund in full, record it.
	 *
	 * Refused, with the reason, when the appointment is no longer withdrawable.
	 * The appointment is cancelled with reason `withdrawal` and no fee whatever
	 * the cancellation policy says. A paid deposit is refunded in full through
	 * the deposit adapter; while the adapter is dormant, or when it refuses,
	 * the refund is recorded as due 14 days after the withdrawal, for staff.
	 * The deposit's invoice is named on the record to be credited.
	 *
	 * @param string            $appointmentId The appointment's id.
	 * @param string            $channel       `portal` or `guest-link`.
	 * @param DateTimeImmutable $now           The moment of the withdrawal.
	 *
	 * @return array{withdrawn: bool, code: string, reason: string|null, withdrawal: array<string, mixed>|null, appointment: array<string, mixed>|null}
	 *         The outcome; `withdrawal` is the saved record.
	 *
	 * @throws RuntimeException When OpenRegister cannot be reached.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.2
	 */
	public function withdraw(string $appointmentId, string $channel, DateTimeImmutable $now): array {
		if ($this->container === null || $this->cancellation === null || $this->deposits === null) {
			throw new RuntimeException('The withdrawal service is not wired.');
		}

		$objects = $this->container->get(self::OBJECT_SERVICE);
		$appointment = $this->read(objects: $objects, schema: 'Appointment', id: $appointmentId);
		if ($appointment === null) {
			return ['withdrawn' => false, 'code' => self::NOT_ONLINE, 'reason' => null, 'withdrawal' => null, 'appointment' => null];
		}

		$answer = $this->isWithdrawable(appointment: $appointment, service: $this->serviceOf(objects: $objects, appointment: $appointment), now: $now);
		if ($answer['withdrawable'] === false) {
			return ['withdrawn' => false, 'code' => $answer['code'], 'reason' => $answer['reason'], 'withdrawal' => null, 'appointment' => $appointment];
		}

		$deposit = $this->depositOf(objects: $objects, appointment: $appointment);
		$record = $this->record(appointment: $appointment, deposit: $deposit, channel: $channel, now: $now);
		$withdrawal = $this->save(objects: $objects, schema: 'Withdrawal', id: null, data: $record);

		$cancelled = $this->cancellation->initiateCancellation(appointment: $appointment, reason: 'withdrawal', cancelledAt: $now);
		// No fee on a withdrawal, whatever the policy said (article 13).
		$cancelled['refundAmount'] = (int)($appointment['appointmentCost'] ?? 0);
		$cancelled['status'] = 'cancelled';
		$cancelled['withdrawal'] = ['withdrawalId' => (string)($withdrawal['id'] ?? ''), 'withdrawnAt' => $now->format(DATE_ATOM)];
		$cancelled = $this->save(objects: $objects, schema: 'Appointment', id: $appointmentId, data: self::payload(row: $cancelled));

		return ['withdrawn' => true, 'code' => self::WITHDRAWABLE, 'reason' => null, 'withdrawal' => $withdrawal, 'appointment' => $cancelled];
	}//end withdraw()

	/**
	 * The withdrawal record, with the refund started or recorded as due.
	 *
	 * @param array<string, mixed>      $appointment The appointment.
	 * @param array<string, mixed>|null $deposit     Its paid deposit, if any.
	 * @param string                    $channel     `portal` or `guest-link`.
	 * @param DateTimeImmutable         $now         The moment of the withdrawal.
	 *
	 * @return array<string, mixed> The record to save.
	 */
	private function record(array $appointment, ?array $deposit, string $channel, DateTimeImmutable $now): array {
		if ($channel !== 'portal') {
			$channel = 'guest-link';
		}

		$record = [
			'appointmentId' => (string)($appointment['id'] ?? ($appointment['appointmentId'] ?? '')),
			'administrationId' => ($appointment['administrationId'] ?? null),
			'requestedAt' => $now->format(DATE_ATOM),
			'channel' => $channel,
			'acknowledgedAt' => null,
			'refundAmount' => 0,
			'refundStatus' => 'none',
			'refundDueBy' => null,
			'depositPaymentId' => null,
			'invoiceId' => null,
		];
		if ($deposit === null) {
			return $record;
		}

		$record['refundAmount'] = (int)($deposit['amount'] ?? 0);
		$record['depositPaymentId'] = (string)($deposit['id'] ?? '');
		$record['invoiceId'] = ($deposit['arInvoiceId'] ?? null);
		$record['refundStatus'] = $this->refund(deposit: $deposit, correlation: $record['appointmentId']);
		if ($record['refundStatus'] !== 'initiated') {
			$record['refundDueBy'] = $now->modify('+'.self::PERIOD_DAYS.' days')->format('Y-m-d');
		}

		return $record;
	}//end record()

	/**
	 * Refund a deposit in full: `initiated`, or `due` while the adapter is dormant, or `failed`.
	 *
	 * @param array<string, mixed> $deposit     The deposit.
	 * @param string               $correlation The appointment, for the gateway's correlation id.
	 *
	 * @return string The refund status.
	 */
	private function refund(array $deposit, string $correlation): string {
		if ($this->deposits->isDormant() === true) {
			return 'due';
		}

		try {
			$this->deposits->initiateRefund(
				(string)($deposit['paymentIntentId'] ?? ''),
				[
					'amount' => ['value' => (int)($deposit['amount'] ?? 0), 'currency' => (string)($deposit['currencyCode'] ?? 'EUR')],
					'reason' => 'bookingCancelled',
					'metadata' => ['depositPaymentId' => (string)($deposit['id'] ?? ''), 'creditNoteId' => null, 'correlationId' => 'withdrawal-'.$correlation],
				]
			);
		} catch (Throwable $e) {
			$this->logger?->error('Shillinq: refunding a deposit for a withdrawal failed', ['exception' => $e->getMessage()]);
			return 'failed';
		}

		return 'initiated';
	}//end refund()

	/**
	 * The appointment's paid deposit, or null.
	 *
	 * @param object               $objects     OpenRegister's object service.
	 * @param array<string, mixed> $appointment The appointment.
	 *
	 * @return array<string, mixed>|null The deposit.
	 */
	private function depositOf(object $objects, array $appointment): ?array {
		$id = trim((string)($appointment['depositPaymentId'] ?? ''));
		if ($id === '') {
			return null;
		}

		$deposit = $this->read(objects: $objects, schema: 'DepositPayment', id: $id);
		if ($deposit === null || in_array((string)($deposit['state'] ?? ''), self::PAID_DEPOSIT_STATES, true) === false) {
			return null;
		}

		return $deposit;
	}//end depositOf()

	/**
	 * The booked service, read by its serviceId, or null.
	 *
	 * @param object               $objects     OpenRegister's object service.
	 * @param array<string, mixed> $appointment The appointment.
	 *
	 * @return array<string, mixed>|null The service.
	 */
	private function serviceOf(object $objects, array $appointment): ?array {
		$serviceId = trim((string)($appointment['serviceId'] ?? ''));
		if ($serviceId === '') {
			return null;
		}

		$rows = $objects->setRegister(self::REGISTER)->setSchema('Service')
			->findAll(config: ['filters' => ['serviceId' => $serviceId], 'limit' => 1], _rbac: false, _multitenancy: false);

		return ObjectIdentifier::recordWithId(candidate: ($rows[0] ?? null));
	}//end serviceOf()

	/**
	 * One object, read outside RBAC and tenancy, or null.
	 *
	 * @param object $objects OpenRegister's object service.
	 * @param string $schema  The schema.
	 * @param string $id      The id.
	 *
	 * @return array<string, mixed>|null The object, with its id.
	 */
	private function read(object $objects, string $schema, string $id): ?array {
		try {
			$found = ObjectIdentifier::findScoped(
				scoped: $objects->setRegister(self::REGISTER)->setSchema($schema),
				id: $id,
				named: ['_rbac' => false, '_multitenancy' => false]
			);
		} catch (Throwable $notFound) {
			return null;
		}

		$row = ObjectIdentifier::recordWithId(candidate: $found);
		if ($row !== null && (string)($row['id'] ?? '') === '') {
			$row['id'] = $id;
		}

		return $row;
	}//end read()

	/**
	 * Save an object and return it as an array with its id.
	 *
	 * @param object               $objects OpenRegister's object service.
	 * @param string               $schema  The schema.
	 * @param string|null          $id      The id to update, or null for a new object.
	 * @param array<string, mixed> $data    The object.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	private function save(object $objects, string $schema, ?string $id, array $data): array {
		$saved = $objects->saveObject(object: $data, register: self::REGISTER, schema: $schema, uuid: $id, _rbac: false, _multitenancy: false);

		return (ObjectIdentifier::recordWithId(candidate: $saved) ?? $data);
	}//end save()

	/**
	 * An object's own fields, without the metadata a read adds.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private static function payload(array $row): array {
		unset($row['@self'], $row['id']);

		return $row;
	}//end payload()

	/**
	 * Whether the appointment can still be withdrawn, and why not.
	 *
	 * @param array<string, mixed>      $appointment The appointment, with its `@self.created` moment.
	 * @param array<string, mixed>|null $service     The booked service, or null when it cannot be read.
	 * @param DateTimeImmutable         $now         The moment of the question.
	 *
	 * @return array{withdrawable: bool, code: string, reason: string|null, periodEndsOn: string|null}
	 *         The answer; `periodEndsOn` is the last day a withdrawal is accepted, when the booking moment is known.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
	 */
	public function isWithdrawable(array $appointment, ?array $service, DateTimeImmutable $now): array {
		$bookedAt = self::bookedAt(appointment: $appointment);
		$periodEndsOn = null;
		if ($bookedAt !== null) {
			$periodEndsOn = self::periodEnd(bookedAt: $bookedAt)->modify('-1 day')->format('Y-m-d');
		}

		if ((string)($appointment['source'] ?? '') !== 'widget' || $bookedAt === null) {
			return $this->refusal(
				code: self::NOT_ONLINE,
				reason: $this->l10n->t('This booking was not made online, so there is no right of withdrawal.'),
				periodEndsOn: $periodEndsOn
			);
		}

		if (in_array((string)($appointment['status'] ?? ''), self::BOOKED_STATES, true) === false) {
			return $this->refusal(
				code: self::NOT_BOOKED,
				reason: $this->l10n->t('This booking is no longer open, so it cannot be withdrawn.'),
				periodEndsOn: $periodEndsOn
			);
		}

		$exemption = self::exemptionOf(service: $service);
		if ($exemption !== null) {
			return $this->refusal(
				code: self::EXEMPT,
				reason: $this->l10n->t('This booking cannot be withdrawn: %s. You can still cancel it under the cancellation policy.', [$exemption]),
				periodEndsOn: $periodEndsOn
			);
		}

		if ($now >= self::periodEnd(bookedAt: $bookedAt)) {
			return $this->refusal(
				code: self::PERIOD_ENDED,
				reason: $this->l10n->t('The withdrawal period ended on %s. You can still cancel under the cancellation policy.', [$periodEndsOn]),
				periodEndsOn: $periodEndsOn
			);
		}

		$start = self::instant(value: ($appointment['startTime'] ?? null));
		if ($start !== null && $now >= $start) {
			return $this->refusal(
				code: self::STARTED,
				reason: $this->l10n->t('This appointment has started, so it can no longer be withdrawn.'),
				periodEndsOn: $periodEndsOn
			);
		}

		return ['withdrawable' => true, 'code' => self::WITHDRAWABLE, 'reason' => null, 'periodEndsOn' => $periodEndsOn];
	}//end isWithdrawable()

	/**
	 * The moment the withdrawal period ends: 14 days after the booking.
	 *
	 * @param DateTimeImmutable $bookedAt When the appointment was booked.
	 *
	 * @return DateTimeImmutable The first moment a withdrawal is no longer accepted.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
	 */
	public static function periodEnd(DateTimeImmutable $bookedAt): DateTimeImmutable {
		return $bookedAt->modify('+'.self::PERIOD_DAYS.' days');
	}//end periodEnd()

	/**
	 * When the appointment was booked: the object's creation moment.
	 *
	 * @param array<string, mixed> $appointment The appointment.
	 *
	 * @return DateTimeImmutable|null The moment, or null when unknown.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
	 */
	public static function bookedAt(array $appointment): ?DateTimeImmutable {
		$self = $appointment['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		return self::instant(value: ($self['created'] ?? ($appointment['created'] ?? null)));
	}//end bookedAt()

	/**
	 * The service's named exemption, or null when it is not exempt.
	 *
	 * An exemption without a reason does not count: staff must name it.
	 *
	 * @param array<string, mixed>|null $service The service.
	 *
	 * @return string|null The exemption reason.
	 */
	private static function exemptionOf(?array $service): ?string {
		if ($service === null || ($service['withdrawalExempt'] ?? false) !== true) {
			return null;
		}

		$reason = trim((string)($service['withdrawalExemptionReason'] ?? ''));
		if ($reason === '') {
			return null;
		}

		return $reason;
	}//end exemptionOf()

	/**
	 * A "no" with its reason.
	 *
	 * @param string      $code         The reason code.
	 * @param string      $reason       The sentence for the consumer.
	 * @param string|null $periodEndsOn The last day of the withdrawal period.
	 *
	 * @return array{withdrawable: false, code: string, reason: string, periodEndsOn: string|null}
	 */
	private function refusal(string $code, string $reason, ?string $periodEndsOn): array {
		return ['withdrawable' => false, 'code' => $code, 'reason' => $reason, 'periodEndsOn' => $periodEndsOn];
	}//end refusal()

	/**
	 * An ISO-8601 moment, in UTC.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null The moment, or null when it cannot be read.
	 */
	private static function instant(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			return null;
		}
	}//end instant()
}//end class
