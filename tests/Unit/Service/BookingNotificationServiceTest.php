<?php

/**
 * Unit tests for BookingNotificationService.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BookingNotificationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingNotificationService.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 */
class BookingNotificationServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var BookingNotificationService
	 */
	private BookingNotificationService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig
			->method('getValueString')
			->willReturn('shillinq');

		$this->service = new BookingNotificationService(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end setUp()

	/**
	 * Rebuild the service over a given ObjectService.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test
	 * that needs a seeded — or a refusing — store has to rebuild the subject
	 * rather than re-point the container. The container stays: it still
	 * resolves `IClientService` for the Openconnector send path.
	 *
	 * @param ObjectServiceInterface $objectService The object service to inject.
	 *
	 * @return void
	 */
	private function rebuildServiceWith(ObjectServiceInterface $objectService): void {
		$this->service = new BookingNotificationService(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $objectService,
		);
	}//end rebuildServiceWith()

	/**
	 * An ObjectService that refuses every call the app makes on it.
	 *
	 * Models "OpenRegister is unavailable" — the condition the fail-open
	 * paths are asserted against, which used to be injected by making the
	 * container throw on get().
	 *
	 * @param string $message The refusal message.
	 *
	 * @return ObjectServiceInterface
	 */
	private function refusingObjectService(string $message): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		foreach (['setRegister', 'setSchema', 'findAll', 'find', 'saveObject'] as $method) {
			$objectService->method($method)->willThrowException(new \RuntimeException($message));
		}

		return $objectService;
	}//end refusingObjectService()

	/**
	 * Template renders booking variables correctly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	public function testRenderTemplateSubstitutesBookingVariables(): void {
		$template = [
			'subject' => 'Boeking {{ booking.organizer }} - {{ booking.startTime | date(\'d M Y\') }}',
			'body' => 'Hallo {{ booking.guestName }}, bevestigd.',
		];

		$booking = [
			'organizer' => 'Tandarts Jansen',
			'startTime' => '2026-06-15T10:30:00Z',
			'guestName' => 'Alice',
		];

		$rendered = $this->service->renderTemplate(
			template: $template,
			booking: $booking,
			recipient: []
		);

		static::assertSame(expected: 'Boeking Tandarts Jansen - 15 Jun 2026', actual: $rendered['subject']);
		static::assertSame(expected: 'Hallo Alice, bevestigd.', actual: $rendered['body']);
	}//end testRenderTemplateSubstitutesBookingVariables()

	/**
	 * Missing template variables render as empty string, not error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	public function testRenderTemplateMissingVariableRendersEmpty(): void {
		$template = [
			'subject' => 'Locatie: {{ booking.location }}',
			'body' => '{{ booking.missing }}',
		];

		$rendered = $this->service->renderTemplate(
			template: $template,
			booking: ['organizer' => 'Test'],
			recipient: []
		);

		static::assertSame(expected: 'Locatie: ', actual: $rendered['subject']);
		static::assertSame(expected: '', actual: $rendered['body']);
	}//end testRenderTemplateMissingVariableRendersEmpty()

	/**
	 * Recipient variables are substituted from the recipient array.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	public function testRenderTemplateSubstitutesRecipientVariables(): void {
		$template = [
			'subject' => 'For {{ recipient.name }}',
			'body' => 'Email: {{ recipient.email }}',
		];

		$rendered = $this->service->renderTemplate(
			template: $template,
			booking: [],
			recipient: ['name' => 'Bob', 'email' => 'bob@example.com']
		);

		static::assertSame(expected: 'For Bob', actual: $rendered['subject']);
		static::assertSame(expected: 'Email: bob@example.com', actual: $rendered['body']);
	}//end testRenderTemplateSubstitutesRecipientVariables()

	/**
	 * System variable appName substitutes correctly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	public function testRenderTemplateSystemAppName(): void {
		$template = ['subject' => '{{ system.appName }}', 'body' => ''];

		$rendered = $this->service->renderTemplate(
			template: $template,
			booking: [],
			recipient: []
		);

		static::assertSame(expected: 'Bookings', actual: $rendered['subject']);
	}//end testRenderTemplateSystemAppName()

	/**
	 * Condition "true" always evaluates to true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionTrueReturnsTrue(): void {
		$result = $this->service->evaluateCondition(condition: 'true', booking: []);
		static::assertTrue(condition: $result);
	}//end testEvaluateConditionTrueReturnsTrue()

	/**
	 * Empty condition defaults to true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionEmptyReturnsTrue(): void {
		$result = $this->service->evaluateCondition(condition: '', booking: []);
		static::assertTrue(condition: $result);
	}//end testEvaluateConditionEmptyReturnsTrue()

	/**
	 * Condition "false" evaluates to false.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionFalseReturnsFalse(): void {
		$result = $this->service->evaluateCondition(condition: 'false', booking: []);
		static::assertFalse(condition: $result);
	}//end testEvaluateConditionFalseReturnsFalse()

	/**
	 * String equality condition evaluates correctly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionStringEquality(): void {
		$booking = ['status' => 'confirmed'];

		$trueResult = $this->service->evaluateCondition(
			condition: "booking.status == 'confirmed'",
			booking: $booking
		);
		static::assertTrue(condition: $trueResult);

		$falseResult = $this->service->evaluateCondition(
			condition: "booking.status == 'pending'",
			booking: $booking
		);
		static::assertFalse(condition: $falseResult);
	}//end testEvaluateConditionStringEquality()

	/**
	 * Numeric greater-than condition evaluates correctly for price thresholds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionNumericGreaterThan(): void {
		$trueResult = $this->service->evaluateCondition(
			condition: 'booking.price > 100',
			booking: ['price' => 150]
		);
		static::assertTrue(condition: $trueResult);

		$falseResult = $this->service->evaluateCondition(
			condition: 'booking.price > 100',
			booking: ['price' => 50]
		);
		static::assertFalse(condition: $falseResult);
	}//end testEvaluateConditionNumericGreaterThan()

	/**
	 * Conditional recipient rule is skipped when condition is false.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateRecipientRulesSkipsWhenConditionFalse(): void {
		$rules = [
			['role' => 'admin_group', 'channels' => ['email'], 'condition' => 'booking.price > 100'],
		];

		$matched = $this->service->evaluateRecipientRules(rules: $rules, booking: ['price' => 50]);

		static::assertCount(expectedCount: 0, haystack: $matched);
	}//end testEvaluateRecipientRulesSkipsWhenConditionFalse()

	/**
	 * Recipient rule is included when condition is true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateRecipientRulesIncludesWhenConditionTrue(): void {
		$rules = [
			['role' => 'customer', 'channels' => ['email'], 'condition' => 'true'],
			['role' => 'organizer', 'channels' => ['email'], 'condition' => "booking.status == 'confirmed'"],
		];

		$matched = $this->service->evaluateRecipientRules(rules: $rules, booking: ['status' => 'confirmed']);

		static::assertCount(expectedCount: 2, haystack: $matched);
	}//end testEvaluateRecipientRulesIncludesWhenConditionTrue()

	/**
	 * IsRateLimited returns false when object service throws (fail-open).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function testIsRateLimitedReturnsFalseOnServiceError(): void {
		$this->rebuildServiceWith($this->refusingObjectService('Service unavailable'));

		$this->logger
			->expects(static::once())
			->method('warning');

		$result = $this->service->isRateLimited(
			bookingId: 'booking-123',
			organizerId: 'user-1'
		);

		static::assertFalse(condition: $result);
	}//end testIsRateLimitedReturnsFalseOnServiceError()

	/**
	 * IsRateLimited returns false for empty IDs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function testIsRateLimitedReturnsFalseForEmptyIds(): void {
		$result = $this->service->isRateLimited(bookingId: '', organizerId: '');
		static::assertFalse(condition: $result);
	}//end testIsRateLimitedReturnsFalseForEmptyIds()

	/**
	 * IsDuplicate returns false when object service throws (fail-open).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function testIsDuplicateReturnsFalseOnServiceError(): void {
		$this->rebuildServiceWith($this->refusingObjectService('Service unavailable'));

		$this->logger
			->expects(static::once())
			->method('warning');

		$result = $this->service->isDuplicate(
			bookingId: 'booking-123',
			recipient: 'alice@example.com',
			triggerType: 'created'
		);

		static::assertFalse(condition: $result);
	}//end testIsDuplicateReturnsFalseOnServiceError()

	/**
	 * IsOptedOut returns false when object service throws (fail-open).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
	 */
	public function testIsOptedOutReturnsFalseOnServiceError(): void {
		$this->rebuildServiceWith($this->refusingObjectService('Service unavailable'));

		$this->logger
			->expects(static::once())
			->method('warning');

		$result = $this->service->isOptedOut(
			recipient: 'alice@example.com',
			triggerType: 'reminder'
		);

		static::assertFalse(condition: $result);
	}//end testIsOptedOutReturnsFalseOnServiceError()

	/**
	 * IsOptedOut returns false when no opt-out record exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
	 */
	public function testIsOptedOutReturnsFalseWhenNoRecord(): void {
		// phpcs:disable
		$objectService = new class {
			public function setRegister(string $register): static {
				return $this;
			}
			public function setSchema(string $schema): static {
				return $this;
			}
			public function findAll(array $config = []): array {
				return [];
			}
		};
		// phpcs:enable
		$this->rebuildServiceWith(new DuckObjectServiceAdapter($objectService));

		$result = $this->service->isOptedOut(
			recipient: 'alice@example.com',
			triggerType: 'created'
		);

		static::assertFalse(condition: $result);
	}//end testIsOptedOutReturnsFalseWhenNoRecord()

	/**
	 * SendViaOpenconnector returns false when HTTP client throws.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-5
	 */
	public function testSendViaOpenconnectorReturnsFalseOnException(): void {
		// phpcs:disable CustomSn.Functions.NamedParameters
		$clientService = $this->getMockBuilder(className: \stdClass::class)->addMethods(['newClient'])->getMock();
		$this->container->method('get')->willReturn($clientService);
		// phpcs:enable CustomSn.Functions.NamedParameters
		$clientService->method('newClient')->willThrowException(new \RuntimeException('timeout'));

		$this->logger->expects(static::once())->method('warning');

		$result = $this->service->sendViaOpenconnector(
			channel: 'email',
			recipient: 'alice@example.com',
			subject: 'Test',
			body: 'Body',
			templateId: 'tmpl-1'
		);

		static::assertFalse(condition: $result);
	}//end testSendViaOpenconnectorReturnsFalseOnException()

	/**
	 * A recording ObjectService double: answers findAll per schema from a
	 * queue and records every query and save.
	 *
	 * @param array<int,array<int,mixed>> $answers The findAll answers, in order.
	 *
	 * @return object
	 */
	private function recordingObjectService(array $answers = []): object {
		return new class($answers) {

			/**
			 * Every findAll query as [register, schema, config].
			 *
			 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
			 */
			public array $queries = [];

			/**
			 * Every saved object as [register, schema, object].
			 *
			 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
			 */
			public array $saves = [];

			/**
			 * The register being addressed.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema being addressed.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Hold the answers.
			 *
			 * @param array<int,array<int,mixed>> $answers The answers.
			 */
			public function __construct(private array $answers) {
			}//end __construct()

			/**
			 * Address a register.
			 *
			 * @param mixed $register The register.
			 *
			 * @return self
			 */
			public function setRegister($register): self {
				$this->register = (string)$register;
				return $this;
			}//end setRegister()

			/**
			 * Address a schema.
			 *
			 * @param mixed $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema($schema): self {
				$this->schema = (string)$schema;
				return $this;
			}//end setSchema()

			/**
			 * Record the query and give the next answer.
			 *
			 * @param array<string,mixed> $config The query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $config = []): array {
				$this->queries[] = [$this->register, $this->schema, $config];
				return (array_shift($this->answers) ?? []);
			}//end findAll()

			/**
			 * Record the save.
			 *
			 * @param array<string,mixed> $object The object.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null): array {
				$this->saves[] = [(string)$register, (string)$schema, $object];
				return $object;
			}//end saveObject()
		};

	}//end recordingObjectService()

	/**
	 * Pins the rate-limit queries and thresholds: a full booking hour stops
	 * before the organizer is asked, a full organizer day limits, under both
	 * limits passes, and an empty booking id asks only for the organizer.
	 *
	 * @return void
	 */
	public function testTheRateLimitQueriesAndThresholds(): void {
		$cases = [
			[[[1, 2]], 'b-1', 'o-1', true, ['bookingId']],
			[[[1], [1, 2, 3]], 'b-1', 'o-1', true, ['bookingId', 'organizerId']],
			[[[1], [1, 2]], 'b-1', 'o-1', false, ['bookingId', 'organizerId']],
			[[[1, 2, 3]], '', 'o-1', true, ['organizerId']],
			[[[1, 2, 3]], 'b-1', '', true, ['bookingId']],
		];
		foreach ($cases as [$answers, $bookingId, $organizerId, $limited, $keys]) {
			$store = $this->recordingObjectService($answers);
			$this->rebuildServiceWith(new DuckObjectServiceAdapter($store));

			self::assertSame($limited, $this->service->isRateLimited(bookingId: $bookingId, organizerId: $organizerId, rateLimitPerBooking: 2, rateLimitPerOrganizer: 3));
			self::assertSame($keys, array_map(static fn (array $q): string => (string)array_key_first($q[2]['filters']), $store->queries));
			foreach ($store->queries as $query) {
				self::assertSame(['shillinq', 'NotificationDelivery'], [$query[0], $query[1]]);
				self::assertSame(['bookingId' => 3, 'organizerId' => 4][array_key_first($query[2]['filters'])], $query[2]['limit']);
				self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:00:00Z$/', (string)$query[2]['filters']['sentAt[gte]']);
			}
		}

		self::assertSame(['bookingId' => 'b-1', 'sentAt[gte]' => $store->queries[0][2]['filters']['sentAt[gte]']], $store->queries[0][2]['filters']);

	}//end testTheRateLimitQueriesAndThresholds()

	/**
	 * Pins the deduplication query and its answer.
	 *
	 * @return void
	 */
	public function testTheDeduplicationQueryAndAnswer(): void {
		$cases = [
			[[[['id' => 'd-1']]], true],
			[[[]], false],
		];
		foreach ($cases as [$answers, $duplicate]) {
			$store = $this->recordingObjectService($answers);
			$this->rebuildServiceWith(new DuckObjectServiceAdapter($store));

			self::assertSame($duplicate, $this->service->isDuplicate(bookingId: 'b-1', recipient: 'a@example.com', triggerType: 'created', windowMinutes: 7));
			$query = $store->queries[0];
			self::assertSame(['shillinq', 'NotificationDelivery', 1], [$query[0], $query[1], $query[2]['limit']]);
			self::assertSame(['bookingId', 'recipient', 'triggerType', 'sentAt[gte]'], array_keys($query[2]['filters']));
			self::assertSame(['b-1', 'a@example.com', 'created'], array_slice(array_values($query[2]['filters']), 0, 3));
			$since = new \DateTimeImmutable((string)$query[2]['filters']['sentAt[gte]']);
			self::assertEqualsWithDelta(time() - 420, $since->getTimestamp(), 5);
		}

	}//end testTheDeduplicationQueryAndAnswer()

	/**
	 * Pins the openconnector post (endpoint, payload, timeout) and its status mapping.
	 *
	 * @return void
	 */
	public function testTheOpenconnectorPostAndItsStatusMapping(): void {
		foreach ([200 => true, 299 => true, 300 => false, 404 => false] as $status => $sent) {
			$response = new class($status) {
				/**
				 * Hold the status.
				 *
				 * @param int $status The status code.
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
			$client = new class($response) {
				/**
				 * Every post as [url, options].
				 *
				 * @var array<int,array{0:string,1:array<string,mixed>}>
				 */
				public array $posted = [];

				/**
				 * Hold the response.
				 *
				 * @param object $response The response.
				 */
				public function __construct(private object $response) {
				}//end __construct()

				/**
				 * Record a post.
				 *
				 * @param string $url The URL.
				 * @param array<string,mixed> $options The options.
				 *
				 * @return object
				 */
				public function post(string $url, array $options): object {
					$this->posted[] = [$url, $options];
					return $this->response;
				}//end post()
			};
			$clientService = new class($client) {
				/**
				 * Hold the client.
				 *
				 * @param object $client The client.
				 */
				public function __construct(private object $client) {
				}//end __construct()

				/**
				 * The client.
				 *
				 * @return object
				 */
				public function newClient(): object {
					return $this->client;
				}//end newClient()
			};
			$container = $this->createMock(originalClassName: ContainerInterface::class);
			$container->method('get')->with('OCP\Http\Client\IClientService')->willReturn($clientService);
			$service = new BookingNotificationService(container: $container, appConfig: $this->appConfig, logger: $this->logger, objectService: $this->createMock(ObjectServiceInterface::class));

			self::assertSame($sent, $service->sendViaOpenconnector(channel: 'sms', recipient: '+31600000000', subject: 'S', body: 'B', templateId: 't-1'), (string)$status);
			self::assertSame(
				[
					[
						'/openconnector/api/notifications/send',
						['json' => ['channelAdapter' => 'sms', 'recipient' => '+31600000000', 'subject' => 'S', 'body' => 'B', 'templateId' => 't-1'], 'timeout' => 10],
					],
				],
				$client->posted
			);
		}

	}//end testTheOpenconnectorPostAndItsStatusMapping()

	/**
	 * Pins the channel fallback of a dispatch: the first channel that posts
	 * is audited as sent with the failures before it as its retry count; when
	 * none posts, one failed row names every channel and an error is logged.
	 *
	 * @return void
	 */
	public function testADispatchFallsBackPerChannelAndAuditsTheOutcome(): void {
		foreach ([[[500, 204], 'sent'], [[500, 503], 'failed']] as [$statuses, $outcome]) {
			$client = new class($statuses) {
				/**
				 * Every posted payload.
				 *
				 * @var array<int,array<string,mixed>>
				 */
				public array $payloads = [];

				/**
				 * Hold the statuses to answer, in order.
				 *
				 * @param array<int,int> $statuses The statuses.
				 */
				public function __construct(private array $statuses) {
				}//end __construct()

				/**
				 * Record a post and answer the next status.
				 *
				 * @param string $url The URL.
				 * @param array<string,mixed> $options The options.
				 *
				 * @return object
				 */
				public function post(string $url, array $options): object {
					$this->payloads[] = $options['json'];
					$status = (int)array_shift($this->statuses);
					return new class($status) {
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
			$container = $this->createMock(originalClassName: ContainerInterface::class);
			$container->method('get')->willReturn($clientService);
			$logger = $this->createMock(originalClassName: LoggerInterface::class);
			$logger->expects($outcome === 'failed' ? static::once() : static::never())->method('error')->with(
				'Shillinq: all notification channels failed',
				['bookingId' => 'b-1', 'recipient' => 'a@example.com']
			);
			$store = $this->recordingObjectService();
			$service = new BookingNotificationService(container: $container, appConfig: $this->appConfig, logger: $logger, objectService: new DuckObjectServiceAdapter($store));

			$service->dispatchNotification(
				trigger: ['name' => 'Bevestiging', 'eventType' => 'created'],
				recipient: ['role' => 'customer', 'channels' => ['email', 'sms'], 'address' => 'a@example.com'],
				template: ['id' => 't-1', 'name' => 'Welkom', 'subject' => 'Hallo', 'body' => 'Tekst'],
				booking: ['id' => 'b-1']
			);

			self::assertSame(['email', 'sms'], array_column($client->payloads, 'channelAdapter'));
			self::assertSame(['a@example.com', 'Hallo', 'Tekst', 't-1'], array_slice(array_values($client->payloads[1]), 1));
			self::assertCount(1, $store->saves);
			[$register, $schema, $row] = $store->saves[0];
			self::assertSame(['shillinq', 'NotificationDelivery'], [$register, $schema]);
			unset($row['sentAt']);
			$expected = [
				'triggerName' => 'Bevestiging',
				'triggerType' => 'created',
				'bookingId' => 'b-1',
				'recipient' => 'a@example.com',
				'channel' => 'sms',
				'templateName' => 'Welkom',
				'status' => 'sent',
				'failureReason' => null,
				'retryCount' => 1,
			];
			if ($outcome === 'failed') {
				$expected = array_replace($expected, ['channel' => 'email/sms', 'status' => 'failed', 'failureReason' => 'all channels exhausted', 'retryCount' => 2]);
			}

			self::assertSame($expected, $row);
		}

	}//end testADispatchFallsBackPerChannelAndAuditsTheOutcome()

	/**
	 * RecordAuditTrail logs error and continues when object service throws.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
	 */
	public function testRecordAuditTrailLogsErrorOnServiceThrow(): void {
		$this->rebuildServiceWith($this->refusingObjectService('DB unavailable'));

		$this->logger
			->expects(static::once())
			->method('error');

		// Must not throw.
		$this->service->recordAuditTrail(
			notification: [
				'triggerName' => 'Test',
				'triggerType' => 'created',
				'bookingId' => 'b-1',
				'recipient' => 'alice@example.com',
				'channel' => 'email',
				'templateName' => 'Test Template',
			],
			status: 'sent',
			reason: ''
		);
	}//end testRecordAuditTrailLogsErrorOnServiceThrow()
}//end class
