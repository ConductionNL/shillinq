<?php

/**
 * Unit tests for BookingNotificationService::evaluateEventTrigger() and its loaders.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Service\BookingNotificationService;
use OCA\Shillinq\Service\NotificationDedupKey;
use OCA\Shillinq\Service\NotificationDispatch;
use OCA\Shillinq\Service\NotificationRateLimit;
use OCA\Shillinq\Service\OpenconnectorNotification;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives the full trigger pipeline: load triggers, recipients, opt-out,
 * rate-limit, dedup, template lookup, dispatch and audit.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-14
 */
#[CoversClass(BookingNotificationService::class)]
#[UsesClass(NotificationDispatch::class)]
#[UsesClass(OpenconnectorNotification::class)]
#[UsesClass(NotificationRateLimit::class)]
#[UsesClass(NotificationDedupKey::class)]
#[UsesClass(ObjectIdentifier::class)]
final class BookingNotificationServiceTriggersTest extends TestCase {

	/**
	 * Rows answered by findAll, keyed by schema; a callable receives the config.
	 *
	 * @var array<string,mixed>
	 */
	private array $rows = [];

	/**
	 * Every findAll call as [register, schema, config].
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $queries = [];

	/**
	 * Every saveObject call as [register, schema, object].
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * Every openconnector post payload.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $posts = [];

	/**
	 * Template answered by find(), or a Throwable to throw.
	 *
	 * @var mixed
	 */
	private mixed $found = null;

	/**
	 * Ids passed to find().
	 *
	 * @var array<int,string>
	 */
	private array $findIds = [];

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the service over a recording ObjectService and a duck HTTP client.
	 *
	 * @param string $registerSlug The configured register slug.
	 * @param int    $httpStatus   Status the openconnector post answers.
	 *
	 * @return BookingNotificationService
	 */
	private function service(string $registerSlug = 'bookings', int $httpStatus = 202): BookingNotificationService {
		$this->logger = $this->createMock(LoggerInterface::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($registerSlug);

		$scope = ['register' => '', 'schema' => ''];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnCallback(
			function (string|int $register) use (&$scope, $objectService): ObjectServiceInterface {
				$scope['register'] = (string)$register;
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnCallback(
			function (string|int $schema) use (&$scope, $objectService): ObjectServiceInterface {
				$scope['schema'] = (string)$schema;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$scope): array {
				$this->queries[] = [$scope['register'], $scope['schema'], $config];
				$answer = ($this->rows[$scope['schema']] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				if (is_callable($answer) === true) {
					return $answer($config);
				}

				return $answer;
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id) {
				$this->findIds[] = (string)$id;
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null): ObjectEntityStub {
				unset($object['sentAt']);
				$this->saves[] = [(string)$register, (string)$schema, $object];
				return new ObjectEntityStub(payload: $object);
			}
		);

		$client = new class($httpStatus, $this) {
			/**
			 * Hold the status to answer and the owning test.
			 *
			 * @param int    $status The status.
			 * @param object $owner  The test case recording posts.
			 */
			public function __construct(private int $status, private object $owner) {
			}//end __construct()

			/**
			 * Record a post and answer the status.
			 *
			 * @param string              $url     The URL.
			 * @param array<string,mixed> $options The options.
			 *
			 * @return object
			 */
			public function post(string $url, array $options): object {
				$this->owner->recordPost(payload: ['url' => $url] + $options['json']);
				return new class($this->status) {
					/**
					 * Hold the status.
					 *
					 * @param int $status The status.
					 */
					public function __construct(private int $status) {
					}//end __construct()

					/**
					 * The status code.
					 *
					 * @return int
					 */
					public function getStatusCode(): int {
						return $this->status;
					}//end getStatusCode()
				};
			}//end post()
		};
		$clientService = $this->getMockBuilder(className: \stdClass::class)->addMethods(['newClient'])->getMock();
		$clientService->method('newClient')->willReturn($client);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCP\Http\Client\IClientService')->willReturn($clientService);

		return new BookingNotificationService(
			container: $container,
			appConfig: $appConfig,
			logger: $this->logger,
			objectService: $objectService,
		);
	}//end service()

	/**
	 * Record one openconnector post (called by the duck client).
	 *
	 * @param array<string,mixed> $payload The posted payload.
	 *
	 * @return void
	 */
	public function recordPost(array $payload): void {
		$this->posts[] = $payload;
	}//end recordPost()

	/**
	 * The booking payload used throughout.
	 *
	 * @return array<string,mixed>
	 */
	private function booking(): array {
		return [
			'id' => 'b-1',
			'organizerUserId' => 'org-1',
			'guestName' => 'Ann',
			'guestEmail' => 'ann@example.com',
			'organizerEmail' => 'olga@example.com',
			'adminEmail' => '',
			'status' => 'confirmed',
			'amount' => 3,
			'startTime' => '2026-03-05T10:00:00+00:00',
		];
	}//end booking()

	/**
	 * One trigger with a single customer recipient on email.
	 *
	 * @param array<string,mixed> $overrides Trigger overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function trigger(array $overrides = []): array {
		return array_replace(
			[
				'name' => 'Confirm',
				'eventType' => 'created',
				'templateId' => 'tpl-1',
				'recipients' => [['role' => 'customer', 'channels' => ['email']]],
			],
			$overrides
		);
	}//end trigger()

	/**
	 * Queries made against one schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function queriesFor(string $schema): array {
		return array_values(
			array_map(
				static fn (array $q): array => $q[2],
				array_filter($this->queries, static fn (array $q): bool => $q[1] === $schema)
			)
		);
	}//end queriesFor()

	/**
	 * The full happy path renders the template, posts it and audits 'sent';
	 * opted-out, unresolvable and condition-false recipients are handled per rule.
	 *
	 * @return void
	 */
	public function testEvaluateEventTriggerDispatchesAndAuditsEachRecipient(): void {
		$service = $this->service();
		$this->rows['BookingNotificationTrigger'] = [
			$this->trigger(
				overrides: [
					'recipients' => [
						['role' => 'customer', 'channels' => ['email']],
						['role' => 'organizer', 'channels' => ['chat'], 'condition' => "booking.status != 'cancelled'"],
						['role' => 'admin_group', 'channels' => ['email']],
						['role' => 'auditor', 'channels' => ['email']],
						['role' => 'customer', 'channels' => ['sms'], 'condition' => 'booking.amount < 2'],
					],
				]
			),
		];
		$this->rows['NotificationOptOut'] = static fn (array $config): array => match ($config['filters']['recipient']) {
			'olga@example.com' => [['optedOutTriggerTypes' => ['created', 'changed']]],
			default => [],
		};
		$this->found = new ObjectEntityStub(
			payload: [
				'name' => 'Welcome',
				'subject' => 'Hi {{ booking.guestName }}',
				'body' => "On {{ booking.startTime | date('d-m-Y') }} for {{ recipient.address }}{{ booking.missing | date('Y') }}",
			]
		);

		$service->evaluateEventTrigger(eventType: 'created', booking: $this->booking());

		self::assertSame(
			[['bookings', 'BookingNotificationTrigger', ['filters' => ['eventType' => 'created', 'active' => true], 'limit' => 100]]],
			array_values(array_filter($this->queries, static fn (array $q): bool => $q[1] === 'BookingNotificationTrigger'))
		);
		self::assertSame(
			[
				['filters' => ['recipient' => 'ann@example.com'], 'limit' => 1],
				['filters' => ['recipient' => 'olga@example.com'], 'limit' => 1],
			],
			$this->queriesFor(schema: 'NotificationOptOut')
		);
		self::assertSame(['tpl-1'], $this->findIds);

		self::assertSame(
			[
				[
					'url' => '/openconnector/api/notifications/send',
					'channelAdapter' => 'email',
					'recipient' => 'ann@example.com',
					'subject' => 'Hi Ann',
					'body' => 'On 05-03-2026 for ann@example.com',
					'templateId' => '',
				],
			],
			$this->posts
		);

		self::assertSame(
			[
				[
					'bookings',
					'NotificationDelivery',
					[
						'triggerName' => 'Confirm',
						'triggerType' => 'created',
						'bookingId' => 'b-1',
						'recipient' => 'ann@example.com',
						'channel' => 'email',
						'templateName' => 'Welcome',
						'status' => 'sent',
						'failureReason' => null,
						'retryCount' => 0,
					],
				],
				[
					'bookings',
					'NotificationDelivery',
					[
						'triggerName' => 'Confirm',
						'triggerType' => 'created',
						'bookingId' => 'b-1',
						'recipient' => 'olga@example.com',
						'channel' => 'chat',
						'templateName' => '',
						'status' => 'skipped',
						'failureReason' => 'opt-out',
						'retryCount' => 0,
					],
				],
			],
			$this->saves
		);

	}//end testEvaluateEventTriggerDispatchesAndAuditsEachRecipient()

	/**
	 * Rate-limit and dedup queries are scoped to booking, organizer and recipient.
	 *
	 * @return void
	 */
	public function testEvaluateEventTriggerQueriesLimitsAndDedup(): void {
		$service = $this->service();
		$this->rows['BookingNotificationTrigger'] = [
			$this->trigger(overrides: ['rateLimitPerBookingPerHour' => 3, 'rateLimitPerOrganizerPerDay' => 7, 'deduplicationWindowMinutes' => 15]),
		];
		$this->found = new ObjectEntityStub(payload: ['subject' => 's', 'body' => 'b']);

		$service->evaluateEventTrigger(eventType: 'created', booking: $this->booking());

		$deliveries = $this->queriesFor(schema: 'NotificationDelivery');
		self::assertCount(3, $deliveries);
		foreach ($deliveries as $index => $query) {
			self::assertArrayHasKey('sentAt[gte]', $query['filters']);
			unset($deliveries[$index]['filters']['sentAt[gte]']);
		}

		self::assertSame(
			[
				['filters' => ['bookingId' => 'b-1'], 'limit' => 4],
				['filters' => ['organizerId' => 'org-1'], 'limit' => 8],
				['filters' => ['bookingId' => 'b-1', 'recipient' => 'ann@example.com', 'triggerType' => 'created'], 'limit' => 1],
			],
			$deliveries
		);
		self::assertCount(1, $this->posts);

	}//end testEvaluateEventTriggerQueriesLimitsAndDedup()

	/**
	 * A full booking hour stops the send with a 'rate-limited' warning.
	 *
	 * @return void
	 */
	public function testBookingHourLimitSuppressesSend(): void {
		$service = $this->service();
		$this->rows['BookingNotificationTrigger'] = [$this->trigger(overrides: ['rateLimitPerBookingPerHour' => 2])];
		$this->rows['NotificationDelivery'] = static fn (array $config): array => isset($config['filters']['organizerId']) ? [] : [['id' => 1], ['id' => 2]];
		$this->logger->expects(self::once())
			->method('warning')
			->with('Shillinq: notification rate-limited', ['bookingId' => 'b-1', 'triggerType' => 'created']);

		$service->evaluateEventTrigger(eventType: 'created', booking: $this->booking());

		self::assertSame([], $this->posts);
		self::assertSame([], $this->saves);
		self::assertSame([], $this->findIds);

	}//end testBookingHourLimitSuppressesSend()

	/**
	 * A full organizer day stops the send even when the booking hour has room.
	 *
	 * @return void
	 */
	public function testOrganizerDayLimitSuppressesSend(): void {
		$service = $this->service();
		$this->rows['BookingNotificationTrigger'] = [$this->trigger(overrides: ['rateLimitPerOrganizerPerDay' => 1])];
		$this->rows['NotificationDelivery'] = static fn (array $config): array => isset($config['filters']['organizerId']) ? [['id' => 9]] : [];
		$this->logger->expects(self::once())->method('warning')->with('Shillinq: notification rate-limited', self::anything());

		$booking = $this->booking();
		unset($booking['organizerUserId']);
		$booking['organizer'] = 'org-legacy';
		$service->evaluateEventTrigger(eventType: 'created', booking: $booking);

		self::assertSame('org-legacy', $this->queriesFor(schema: 'NotificationDelivery')[1]['filters']['organizerId']);
		self::assertSame([], $this->posts);

	}//end testOrganizerDayLimitSuppressesSend()

	/**
	 * A recent identical delivery is deduplicated with an info log.
	 *
	 * @return void
	 */
	public function testDuplicateSuppressesSend(): void {
		$service = $this->service();
		$this->rows['BookingNotificationTrigger'] = [$this->trigger()];
		$this->rows['NotificationDelivery'] = static fn (array $config): array => isset($config['filters']['recipient']) ? [['id' => 'd']] : [];
		$this->logger->expects(self::once())
			->method('info')
			->with('Shillinq: notification deduplicated', ['bookingId' => 'b-1', 'recipient' => 'ann@example.com']);

		$service->evaluateEventTrigger(eventType: 'created', booking: $this->booking());

		self::assertSame([], $this->posts);
		self::assertSame([], $this->findIds);

	}//end testDuplicateSuppressesSend()

	/**
	 * A global opt-out skips the recipient; the trigger name falls back to the event type.
	 *
	 * @return void
	 */
	public function testGlobalOptOutSkipsRecipient(): void {
		$service = $this->service(registerSlug: '');
		$trigger = $this->trigger(overrides: ['recipients' => [['role' => 'customer']]]);
		unset($trigger['name']);
		$this->rows['BookingNotificationTrigger'] = [$trigger];
		$this->rows['NotificationOptOut'] = [['globalOptOut' => true, 'optedOutTriggerTypes' => []]];

		$service->evaluateEventTrigger(eventType: 'cancelled', booking: $this->booking());

		self::assertSame([], $this->posts);
		self::assertCount(1, $this->saves);
		self::assertSame(['shillinq', 'NotificationDelivery'], [$this->saves[0][0], $this->saves[0][1]]);
		self::assertSame('cancelled', $this->saves[0][2]['triggerName']);
		self::assertSame('email', $this->saves[0][2]['channel']);
		self::assertSame('skipped', $this->saves[0][2]['status']);
		self::assertSame(['shillinq'], array_values(array_unique(array_column($this->queries, 0))));

	}//end testGlobalOptOutSkipsRecipient()

	/**
	 * With no register configured every lookup and the audit use 'shillinq';
	 * a booking without organizer skips the per-organizer limit query.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterAndNoOrganizer(): void {
		$service = $this->service(registerSlug: '');
		$this->rows['BookingNotificationTrigger'] = [$this->trigger()];
		$this->found = new ObjectEntityStub(payload: ['name' => 'T', 'subject' => 'S', 'body' => 'B']);
		$booking = $this->booking();
		unset($booking['organizerUserId']);

		$service->evaluateEventTrigger(eventType: 'created', booking: $booking);

		$deliveries = $this->queriesFor(schema: 'NotificationDelivery');
		self::assertCount(2, $deliveries);
		self::assertSame(['bookingId' => 'b-1'], array_diff_key($deliveries[0]['filters'], ['sentAt[gte]' => true]));
		self::assertArrayHasKey('recipient', $deliveries[1]['filters']);
		self::assertSame(['shillinq'], array_values(array_unique(array_column($this->queries, 0))));
		self::assertSame('shillinq', $this->saves[0][0]);
		self::assertSame('sent', $this->saves[0][2]['status']);
		self::assertCount(1, $this->posts);

	}//end testUnconfiguredRegisterAndNoOrganizer()

	/**
	 * A failing trigger load is logged and nothing is dispatched.
	 *
	 * @return void
	 */
	public function testTriggerLoadFailureIsLogged(): void {
		$service = $this->service();
		$this->rows['BookingNotificationTrigger'] = new RuntimeException('no schema');
		$this->logger->expects(self::once())
			->method('error')
			->with('Shillinq: failed to load notification triggers', ['exception' => 'no schema']);

		$service->evaluateEventTrigger(eventType: 'created', booking: $this->booking());

		self::assertSame([], $this->posts);
		self::assertSame([], $this->saves);

	}//end testTriggerLoadFailureIsLogged()

	/**
	 * Without a templateId the active template for the event type is used (entity row).
	 *
	 * @return void
	 */
	public function testTemplateFallsBackToEventTypeEntity(): void {
		$service = $this->service();
		$this->rows['BookingNotificationTrigger'] = [$this->trigger(overrides: ['templateId' => ''])];
		$template = new ObjectEntity();
		$template->setObject(['name' => 'Default', 'subject' => 'Booked {{ booking.id }}', 'body' => '{{ booking.startTime | date(\'Y\') }}']);
		$this->rows['BookingNotificationTemplate'] = [$template];

		$service->evaluateEventTrigger(eventType: 'created', booking: $this->booking());

		self::assertSame([], $this->findIds);
		self::assertSame(
			[['filters' => ['trigger' => 'created', 'active' => true], 'limit' => 1]],
			$this->queriesFor(schema: 'BookingNotificationTemplate')
		);
		self::assertSame('Booked b-1', $this->posts[0]['subject']);
		self::assertSame('2026', $this->posts[0]['body']);
		self::assertSame('Default', $this->saves[0][2]['templateName']);

	}//end testTemplateFallsBackToEventTypeEntity()

	/**
	 * An unknown templateId falls back to the event-type template answered as an array.
	 *
	 * @return void
	 */
	public function testTemplateFallsBackWhenTemplateIdUnknown(): void {
		$service = $this->service();
		$this->rows['BookingNotificationTrigger'] = [$this->trigger()];
		$this->rows['BookingNotificationTemplate'] = [['name' => 'Array', 'subject' => 'S', 'body' => 'B']];

		$service->evaluateEventTrigger(eventType: 'created', booking: $this->booking());

		self::assertSame(['tpl-1'], $this->findIds);
		self::assertSame('S', $this->posts[0]['subject']);
		self::assertSame('Array', $this->saves[0][2]['templateName']);

	}//end testTemplateFallsBackWhenTemplateIdUnknown()

	/**
	 * A failing template lookup is logged and an empty template is still sent.
	 *
	 * @return void
	 */
	public function testTemplateLoadFailureSendsEmptyTemplate(): void {
		$service = $this->service();
		$this->rows['BookingNotificationTrigger'] = [$this->trigger()];
		$this->found = new RuntimeException('template store down');
		$this->logger->expects(self::once())
			->method('warning')
			->with('Shillinq: failed to load notification template', ['exception' => 'template store down']);

		$service->evaluateEventTrigger(eventType: 'created', booking: $this->booking());

		self::assertSame('', $this->posts[0]['subject']);
		self::assertSame('', $this->posts[0]['body']);
		self::assertSame('', $this->saves[0][2]['templateName']);

	}//end testTemplateLoadFailureSendsEmptyTemplate()

	/**
	 * evaluateCondition() handles !=, <, and unrecognised expressions (fail-open with a warning).
	 *
	 * @return void
	 */
	public function testEvaluateConditionOperators(): void {
		$service = $this->service();
		$this->logger->expects(self::once())
			->method('warning')
			->with('Shillinq: unrecognised condition expression, defaulting to true', ['condition' => 'booking.amount >= 3']);

		$booking = ['status' => 'confirmed', 'amount' => 3];
		self::assertTrue($service->evaluateCondition(condition: "booking.status != 'cancelled'", booking: $booking));
		self::assertFalse($service->evaluateCondition(condition: "booking.status != 'confirmed'", booking: $booking));
		self::assertTrue($service->evaluateCondition(condition: 'booking.amount < 4', booking: $booking));
		self::assertFalse($service->evaluateCondition(condition: 'booking.amount < 3', booking: $booking));
		self::assertTrue($service->evaluateCondition(condition: '  booking.amount >= 3 ', booking: $booking));

	}//end testEvaluateConditionOperators()

	/**
	 * An unparseable date under the date filter renders the raw value.
	 *
	 * @return void
	 */
	public function testDateFilterKeepsUnparseableValue(): void {
		$rendered = $this->service()->renderTemplate(
			template: ['subject' => "{{ booking.startTime | date('Y') }}", 'body' => '{{booking.missing|date("Y")}}x'],
			booking: ['startTime' => 'not a date'],
			recipient: []
		);

		self::assertSame(['subject' => 'not a date', 'body' => 'x'], $rendered);

	}//end testDateFilterKeepsUnparseableValue()

	/**
	 * Trigger-specific opt-out only matches the listed trigger types.
	 *
	 * @return void
	 */
	public function testIsOptedOutPerTriggerType(): void {
		$service = $this->service();
		$this->rows['NotificationOptOut'] = [['globalOptOut' => false, 'optedOutTriggerTypes' => ['reminder']]];

		self::assertTrue($service->isOptedOut(recipient: 'a@example.com', triggerType: 'reminder'));
		self::assertFalse($service->isOptedOut(recipient: 'a@example.com', triggerType: 'created'));

	}//end testIsOptedOutPerTriggerType()
}//end class
