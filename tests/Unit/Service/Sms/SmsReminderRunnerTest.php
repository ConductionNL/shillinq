<?php

/**
 * Unit tests for SmsReminderRunner.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sms;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Sms\LogSmsProviderAdapter;
use OCA\Shillinq\Service\Sms\SmsOptOutPolicy;
use OCA\Shillinq\Service\Sms\SmsPhoneNumberNormalizer;
use OCA\Shillinq\Service\Sms\SmsProviderAdapterInterface;
use OCA\Shillinq\Service\Sms\SmsReminder;
use OCA\Shillinq\Service\Sms\SmsReminderDispatcher;
use OCA\Shillinq\Service\Sms\SmsReminderRunner;
use OCA\Shillinq\Service\Sms\SmsSendResult;
use OCA\Shillinq\Service\Sms\SmsTemplateRenderer;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Due reminders reach the provider; without one the channel shows not connected.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
 */
#[CoversClass(SmsReminderRunner::class)]
#[UsesClass(SmsReminderDispatcher::class)]
#[UsesClass(SmsOptOutPolicy::class)]
#[UsesClass(SmsPhoneNumberNormalizer::class)]
#[UsesClass(SmsTemplateRenderer::class)]
#[UsesClass(SmsReminder::class)]
#[UsesClass(SmsSendResult::class)]
#[UsesClass(LogSmsProviderAdapter::class)]
final class SmsReminderRunnerTest extends TestCase {

	/**
	 * Rows answered per schema; a Throwable is thrown instead.
	 *
	 * @var array<string, mixed>
	 */
	private array $rows = [];

	/**
	 * Every findAll: [register, schema, config].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $queries = [];

	/**
	 * Every saveObject: [register, schema, object, uuid, rbac].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every provider send: [connectorId, number, body, senderId].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $sends = [];

	/**
	 * The runner over a recording store and the given adapter.
	 *
	 * @param SmsProviderAdapterInterface|null $adapter The bound provider; null for a recording one.
	 *
	 * @return SmsReminderRunner
	 */
	private function runner(?SmsProviderAdapterInterface $adapter = null): SmsReminderRunner {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('bookings');

		$scope = ['register' => '', 'schema' => ''];
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnCallback(
			function (string|int $register) use (&$scope, $store): ObjectServiceInterface {
				$scope['register'] = (string)$register;
				return $store;
			}
		);
		$store->method('setSchema')->willReturnCallback(
			function (string|int $schema) use (&$scope, $store): ObjectServiceInterface {
				$scope['schema'] = (string)$schema;
				return $store;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$scope): array {
				$this->queries[] = [$scope['register'], $scope['schema'], $config];
				$answer = ($this->rows[$scope['schema']] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true): array {
				$this->saves[] = [(string)$register, (string)$schema, $object, $uuid, $_rbac];
				return $object;
			}
		);

		if ($adapter === null) {
			$adapter = new class($this) implements SmsProviderAdapterInterface {
				/**
				 * Hold the test that records sends.
				 *
				 * @param object $owner The test.
				 */
				public function __construct(private object $owner) {
				}//end __construct()

				/**
				 * Record the send and answer sent.
				 *
				 * @param string      $connectorId   The connector.
				 * @param string      $e164Recipient The number.
				 * @param string      $body          The body.
				 * @param string|null $senderId      The sender.
				 *
				 * @return SmsSendResult
				 */
				public function send(string $connectorId, string $e164Recipient, string $body, ?string $senderId = null): SmsSendResult {
					$this->owner->recordSend(send: [$connectorId, $e164Recipient, $body, $senderId]);
					return new SmsSendResult(SmsSendResult::STATUS_SENT, 'mb-1', null, 1);
				}//end send()
			};
		}

		$renderer = new SmsTemplateRenderer();
		$normalizer = new SmsPhoneNumberNormalizer();

		return new SmsReminderRunner(
			objectService: $store,
			appConfig: $appConfig,
			dispatcher: new SmsReminderDispatcher(new SmsOptOutPolicy(), $normalizer, $renderer, $adapter),
			adapter: $adapter,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end runner()

	/**
	 * Record one provider send (called by the recording adapter).
	 *
	 * @param array<int, mixed> $send The send.
	 *
	 * @return void
	 */
	public function recordSend(array $send): void {
		$this->sends[] = $send;
	}//end recordSend()

	/**
	 * An active channel with a provider connector.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function channel(array $overrides = []): array {
		return array_replace(
			[
				'id' => 'ch-1',
				'status' => 'active',
				'connectionState' => 'connected',
				'providerConfig' => ['connectorId' => 'conn-mb'],
				'messageTemplate' => 'Hi {{customerName}}, see you {{bookingDate}} at {{bookingTime}} ({{bookingRef}})',
				'sendMinutesBefore' => 60,
				'senderId' => 'Kapsalon',
			],
			$overrides
		);
	}//end channel()

	/**
	 * A confirmed appointment in 45 minutes.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function appointment(array $overrides = []): array {
		return array_replace(
			[
				'id' => 'ap-1',
				'appointmentId' => 'A-0412',
				'status' => 'confirmed',
				'customerName' => 'Ann',
				'customerPhone' => '0612345678',
				'startTime' => '2026-12-03T09:45:00+00:00',
			],
			$overrides
		);
	}//end appointment()

	/**
	 * The saves made on one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function savesOn(string $schema): array {
		return array_values(array_filter($this->saves, static fn (array $save): bool => $save[1] === $schema));
	}//end savesOn()

	/**
	 * A due reminder is handed to the provider and the appointment records the send.
	 *
	 * @return void
	 */
	public function testADueReminderIsHandedToTheProviderAndRecorded(): void {
		$runner = $this->runner();
		$this->rows['BookingSmsReminderChannel'] = [$this->channel()];
		$this->rows['Appointment'] = [$this->appointment()];

		$report = $runner->run(now: new DateTimeImmutable('2026-12-03T09:00:00+00:00'));

		self::assertSame(['channels' => 1, 'notConnected' => 0, 'sent' => 1, 'pending' => 0, 'failed' => 0, 'skipped' => 0], $report);
		self::assertSame([['conn-mb', '+31612345678', 'Hi Ann, see you 03-12-2026 at 10:45 (A-0412)', 'Kapsalon']], $this->sends);
		self::assertSame(
			[
				['bookings', 'BookingSmsReminderChannel', ['filters' => ['status' => 'active'], 'limit' => 200]],
				[
					'bookings',
					'Appointment',
					[
						'filters' => ['status' => 'confirmed', 'startTime[gt]' => '2026-12-03T09:00:00+00:00', 'startTime[lte]' => '2026-12-03T10:00:00+00:00'],
						'limit' => 200,
					],
				],
			],
			$this->queries
		);

		$saves = $this->savesOn(schema: 'Appointment');
		self::assertCount(1, $saves);
		self::assertSame('ap-1', $saves[0][3]);
		self::assertFalse($saves[0][4]);
		self::assertSame('sent', $saves[0][2]['smsReminderStatus']);
		self::assertSame('2026-12-03T09:00:00+00:00', $saves[0][2]['smsReminderAt']);
		self::assertSame('mb-1', $saves[0][2]['smsReminderReference']);
		self::assertSame([], $this->savesOn(schema: 'BookingSmsReminderChannel'), 'a channel already showing connected is not rewritten');
	}//end testADueReminderIsHandedToTheProviderAndRecorded()

	/**
	 * With the log adapter bound nothing is sent and every active channel shows not connected.
	 *
	 * @return void
	 */
	public function testTheLogAdapterMarksTheChannelNotConnectedAndSendsNothing(): void {
		$renderer = new SmsTemplateRenderer();
		$log = new LogSmsProviderAdapter($this->createMock(LoggerInterface::class), new SmsPhoneNumberNormalizer(), $renderer);
		$runner = $this->runner(adapter: $log);
		$this->rows['BookingSmsReminderChannel'] = [$this->channel(), $this->channel(overrides: ['id' => 'ch-2', 'connectionState' => 'not-connected'])];
		$this->rows['Appointment'] = [$this->appointment()];

		self::assertFalse($runner->isConnected());
		$report = $runner->run(now: new DateTimeImmutable('2026-12-03T09:00:00+00:00'));

		self::assertSame(['channels' => 2, 'notConnected' => 2, 'sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0], $report);
		self::assertSame([], $this->savesOn(schema: 'Appointment'));
		self::assertCount(0, array_filter($this->queries, static fn (array $q): bool => $q[1] === 'Appointment'), 'no appointment is read');
		$channelSaves = $this->savesOn(schema: 'BookingSmsReminderChannel');
		self::assertCount(1, $channelSaves, 'only the channel that still showed connected is rewritten');
		self::assertSame('ch-1', $channelSaves[0][3]);
		self::assertSame('not-connected', $channelSaves[0][2]['connectionState']);
	}//end testTheLogAdapterMarksTheChannelNotConnectedAndSendsNothing()

	/**
	 * A real provider marks a channel connected again.
	 *
	 * @return void
	 */
	public function testARealProviderMarksTheChannelConnected(): void {
		$runner = $this->runner();
		$this->rows['BookingSmsReminderChannel'] = [$this->channel(overrides: ['connectionState' => 'not-connected'])];

		self::assertTrue($runner->isConnected());
		$runner->run(now: new DateTimeImmutable('2026-12-03T09:00:00+00:00'));

		$channelSaves = $this->savesOn(schema: 'BookingSmsReminderChannel');
		self::assertCount(1, $channelSaves);
		self::assertSame('connected', $channelSaves[0][2]['connectionState']);
	}//end testARealProviderMarksTheChannelConnected()

	/**
	 * An appointment already reminded is not sent again; an opted-out one is recorded as skipped.
	 *
	 * @return void
	 */
	public function testRemindedAppointmentsAreLeftAndOptOutsAreSkipped(): void {
		$runner = $this->runner();
		$this->rows['BookingSmsReminderChannel'] = [$this->channel()];
		$this->rows['Appointment'] = [
			$this->appointment(overrides: ['id' => 'ap-done', 'smsReminderStatus' => 'sent']),
			$this->appointment(overrides: ['id' => 'ap-out', 'smsOptOut' => true]),
			$this->appointment(overrides: ['id' => 'ap-bad', 'customerPhone' => '12']),
		];

		$report = $runner->run(now: new DateTimeImmutable('2026-12-03T09:00:00+00:00'));

		self::assertSame(['channels' => 1, 'notConnected' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 1, 'skipped' => 1], $report);
		self::assertSame([], $this->sends);
		$saves = $this->savesOn(schema: 'Appointment');
		self::assertSame(['ap-out', 'ap-bad'], array_map(static fn (array $save): string => $save[3], $saves));
		self::assertSame(['skipped', 'failed'], array_map(static fn (array $save): string => $save[2]['smsReminderStatus'], $saves));
		self::assertNull($saves[0][2]['smsReminderReference']);
	}//end testRemindedAppointmentsAreLeftAndOptOutsAreSkipped()

	/**
	 * A channel without a lead time uses a day; a store that refuses reads answers an empty run.
	 *
	 * @return void
	 */
	public function testTheDefaultLeadTimeAndAnUnavailableStore(): void {
		$runner = $this->runner();
		$this->rows['BookingSmsReminderChannel'] = [$this->channel(overrides: ['sendMinutesBefore' => 0])];
		$this->rows['Appointment'] = new RuntimeException('down');

		$report = $runner->run(now: new DateTimeImmutable('2026-12-03T09:00:00+01:00'));

		self::assertSame(1, $report['channels']);
		self::assertSame(0, $report['sent']);
		$appointmentQuery = array_values(array_filter($this->queries, static fn (array $q): bool => $q[1] === 'Appointment'))[0][2];
		self::assertSame('2026-12-03T08:00:00+00:00', $appointmentQuery['filters']['startTime[gt]']);
		self::assertSame('2026-12-04T08:00:00+00:00', $appointmentQuery['filters']['startTime[lte]']);

		$this->rows['BookingSmsReminderChannel'] = new RuntimeException('down');
		self::assertSame(0, $runner->run(now: new DateTimeImmutable('2026-12-03T09:00:00+00:00'))['channels']);
	}//end testTheDefaultLeadTimeAndAnUnavailableStore()
}//end class
