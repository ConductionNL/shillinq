<?php

/**
 * Integration tests for the bookings-pipelinq customer-bridge chain.
 *
 * Slice 10 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Exercises the full chain (slices 02-09) end-to-end against the
 * slice-01 {@see PipelinqMockServer} so the tests confirm that:
 *
 *   1. Creating a booking triggers a POST to the timeline endpoint —
 *      the listener (slice 07) handles the {@see ObjectCreatedEvent},
 *      the adapter (slice 02/07) packages the fixed payload, and the
 *      booking row is "saved" (the test fakes the save by treating the
 *      event dispatch as the commit boundary) so the booking commit
 *      is never blocked on a publish failure (giant decision D3).
 *
 *   2. Loading the booking detail view returns the linked pipelinq
 *      Contact (slice 03) + the most recent klantbeeld transactions
 *      (slice 04). The mock returns the bundled `contact-…` /
 *      `klantbeeld-…` fixtures so the assertions cover the actual
 *      parser/DTO chain.
 *
 *   3. Graceful degradation: when pipelinq returns 5xx (forced via
 *      `PipelinqMockServer::forceStatus(503)`), the booking is still
 *      "saved", the synchronous publish fails, the listener hands the
 *      event off to the retry queue, the detail view returns a
 *      profile error (`contactError`), and klantbeeld is hidden /
 *      replaced with the unavailable envelope so the UI keeps
 *      rendering.
 *
 * The harness wires the {@see PipelinqContactAdapter} on top of an
 * anonymous subclass that overrides {@see PipelinqContactAdapter::dispatch()}
 * and routes the request through the in-process mock server. That
 * lets the integration suite assert real adapter behaviour (cache,
 * retry, breaker, fixture parsing) against the canonical mock without
 * a network socket — the design.md scaffold for member 10.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-10-integration-e2e-tests/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Integration;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\BookingCreatedTimelinePublishListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Pipelinq\CircuitBreaker;
use OCA\Shillinq\Service\Pipelinq\KlantbeeldResult;
use OCA\Shillinq\Service\Pipelinq\PipelinqContact;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\RetryPolicy;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use OCA\Shillinq\Tests\Integration\Pipelinq\PipelinqMockServer;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * End-to-end integration tests over the customer-bridge chain.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-10-integration-e2e-tests/tasks.md
 */
final class CustomerBridgeIntegrationTest extends TestCase {

	/**
	 * Canonical externalId mirroring the bundled fixture files
	 * (`contact-org-kvk-12345678.json`, `klantbeeld-org-kvk-12345678.json`).
	 *
	 * @var string
	 */
	private const EXTERNAL_ID = 'org-kvk-12345678';

	/**
	 * Booking id used across the test suite.
	 *
	 * @var string
	 */
	private const BOOKING_ID = 'booking-int-2026-0001';

	/**
	 * Build a recording logger so tests can assert on emitted log lines
	 * (and confirm the bearer token never leaks into them).
	 *
	 * @return AbstractLogger Anonymous PSR logger with `$records`.
	 */
	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = [
					'level' => (string)$level,
					'message' => (string)$message,
					'context' => $context,
				];

			}//end log()
		};

	}//end recordingLogger()

	/**
	 * Build a PipelinqContactAdapter wired to a {@see PipelinqMockServer}.
	 *
	 * The anonymous subclass overrides {@see PipelinqContactAdapter::dispatch()}
	 * and dispatches the call through the in-process mock router after
	 * normalising the URL (the adapter calls `/api/v1/contacts/…`, the
	 * mock router only knows `/contacts/…` per its slice-01 contract).
	 * For the `/api/v1/timeline` publish endpoint the harness returns a
	 * direct 201 / 503 outcome based on the `$forcedStatusQueue` (an
	 * out-of-band per-attempt queue, unlike the mock's single-shot
	 * `forceStatus()`).
	 *
	 * @param PipelinqMockServer $mock In-process mock router.
	 * @param ICache $cache Cache layer (in-process).
	 * @param AbstractLogger $logger Recording logger.
	 * @param array<int, int> &$forcedStatusQueue Per-attempt status overrides; first non-empty entry wins.
	 *
	 * @return PipelinqContactAdapter
	 */
	private function buildAdapter(
		PipelinqMockServer $mock,
		ICache $cache,
		AbstractLogger $logger,
		array &$forcedStatusQueue,
	): PipelinqContactAdapter {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === PipelinqContactAdapter::CONFIG_KEY_ENDPOINT) {
					return 'https://pipelinq.test';
				}

				if ($key === PipelinqContactAdapter::CONFIG_KEY_TOKEN) {
					return 'integration-bearer-token-DO-NOT-LOG';
				}

				return $default;
			}
		);

		$clientService = $this->createMock(IClientService::class);

		// No sleeping in tests — backoff is exercised in unit tests; the
		// integration suite just needs the call surface.
		$sleeper = static function (int $seconds): void {
		};

		return new class($clientService, $appConfig, $logger, $cache, new RetryPolicy(), null, $sleeper, $mock, $forcedStatusQueue) extends PipelinqContactAdapter {
			/**
			 * @var PipelinqMockServer
			 */
			private PipelinqMockServer $mock;

			/**
			 * @var array<int, int>
			 */
			private array $forcedStatusQueue;

			/**
			 * @param IClientService $clientService Mock client service.
			 * @param IAppConfig $appConfig Mock config.
			 * @param AbstractLogger $logger Recording logger.
			 * @param ICache $cache Cache layer.
			 * @param RetryPolicy $retryPolicy Retry policy.
			 * @param CircuitBreaker|null $breaker Optional breaker.
			 * @param \Closure $sleeper Stubbed sleeper.
			 * @param PipelinqMockServer $mock Mock router.
			 * @param array<int, int> &$forcedStatusQueue Per-attempt status overrides.
			 */
			public function __construct(
				IClientService $clientService,
				IAppConfig $appConfig,
				$logger,
				ICache $cache,
				RetryPolicy $retryPolicy,
				?CircuitBreaker $breaker,
				\Closure $sleeper,
				PipelinqMockServer $mock,
				array &$forcedStatusQueue,
			) {
				parent::__construct($clientService, $appConfig, $logger, $cache, $retryPolicy, $breaker, $sleeper);
				$this->mock = $mock;
				$this->forcedStatusQueue = & $forcedStatusQueue;

			}//end __construct()

			/**
			 * Route the request through the mock server.
			 *
			 * Strips the `/api/v1` adapter prefix + the query string so
			 * the slice-01 mock router (which only knows `/contacts/…`,
			 * `/klantbeeld/…`, `/contacts/…/timeline`) matches. When
			 * `$forcedStatusQueue` carries a value its head is consumed
			 * as the synthetic status for this attempt — this is the
			 * per-attempt analogue of the mock's single-shot
			 * `forceStatus()`, used to drive retry/breaker scenarios.
			 *
			 * @param string $method HTTP method.
			 * @param string $url Full URL.
			 * @param array<string, mixed> $options Guzzle-shaped options.
			 *
			 * @return IResponse
			 */
			protected function dispatch(string $method, string $url, array $options): IResponse {
				$path = (string)parse_url($url, PHP_URL_PATH);
				if (str_starts_with($path, '/api/v1') === true) {
					$path = substr($path, strlen('/api/v1'));
				}

				if ($path === '/timeline') {
					$payload = ($options['body'] ?? null);
					$body = is_string($payload) ? $payload : null;

					// Record the dispatch in the mock's request history
					// so assertions can confirm the POST shape.
					$this->mock->dispatch(method: $method, path: '/timeline', body: $body);

					if (empty($this->forcedStatusQueue) === false) {
						$forced = (int)array_shift($this->forcedStatusQueue);
						return new IntegrationTestResponse(
							statusCode: $forced,
							body: '{"error":"forced status ' . $forced . '"}'
						);
					}

					return new IntegrationTestResponse(
						statusCode: 201,
						body: '{"accepted":true}'
					);
				}//end if

				// Klantbeeld is fetched via `/contacts/{id}/klantbeeld?…`.
				// The slice-01 mock router knows `/klantbeeld/{id}`, so
				// remap.
				if (preg_match('#^/contacts/([^/]+)/klantbeeld$#', $path, $m) === 1) {
					$path = '/klantbeeld/' . $m[1];
				}

				if (empty($this->forcedStatusQueue) === false) {
					$forced = (int)array_shift($this->forcedStatusQueue);
					// Still record the request so assertions can count it.
					$this->mock->dispatch(method: $method, path: $path, body: null);
					return new IntegrationTestResponse(
						statusCode: $forced,
						body: '{"error":"forced status ' . $forced . '"}'
					);
				}

				$result = $this->mock->dispatch(method: $method, path: $path, body: null);
				return new IntegrationTestResponse(
					statusCode: $result['status'],
					body: $result['body']
				);

			}//end dispatch()
		};

	}//end buildAdapter()

	/**
	 * Build an in-process cache stub.
	 *
	 * Backed by a regular PHP array — enough for the integration tests
	 * (TTL is irrelevant within a single test method).
	 *
	 * @return ICache
	 */
	private function inMemoryCache(): ICache {
		return new class implements ICache {
			/**
			 * @var array<string, mixed>
			 */
			private array $store = [];

			/**
			 * @param string $key Cache key.
			 *
			 * @return mixed
			 */
			public function get($key) {
				return ($this->store[$key] ?? null);
			}//end get()

			/**
			 * @param string $key Cache key.
			 * @param mixed $value Value.
			 * @param int $ttl TTL seconds.
			 *
			 * @return bool
			 */
			public function set($key, $value, $ttl = 0): bool {
				$this->store[$key] = $value;
				return true;
			}//end set()

			/**
			 * @param string $key Cache key.
			 *
			 * @return bool
			 */
			public function hasKey($key): bool {
				return array_key_exists($key, $this->store);
			}//end hasKey()

			/**
			 * @param string $key Cache key.
			 *
			 * @return bool
			 */
			public function remove($key): bool {
				unset($this->store[$key]);
				return true;
			}//end remove()

			/**
			 * @param string $prefix Key prefix to wipe.
			 *
			 * @return bool
			 */
			public function clear($prefix = ''): bool {
				if ($prefix === '') {
					$this->store = [];
					return true;
				}

				foreach (array_keys($this->store) as $key) {
					if (str_starts_with((string)$key, $prefix) === true) {
						unset($this->store[$key]);
					}
				}

				return true;
			}//end clear()

			/**
			 * @return bool
			 */
			public static function isAvailable(): bool {
				return true;
			}//end isAvailable()
		};

	}//end inMemoryCache()

	/**
	 * Build a recording {@see TimelineRetryQueue} so the integration test
	 * can assert hand-off semantics on synchronous publish failure.
	 *
	 * @param array<int, TimelineEventDto> &$enqueueCalls Capture sink.
	 *
	 * @return TimelineRetryQueue
	 */
	private function recordingQueue(array &$enqueueCalls): TimelineRetryQueue {
		return new class($enqueueCalls) implements TimelineRetryQueue {
			/**
			 * @var array<int, TimelineEventDto>
			 */
			private array $sink;

			/**
			 * @param array<int, TimelineEventDto> &$sink Capture sink.
			 */
			public function __construct(array &$sink) {
				$this->sink = & $sink;

			}//end __construct()

			/**
			 * @param TimelineEventDto $event Event to enqueue.
			 *
			 * @return void
			 */
			public function enqueue(TimelineEventDto $event): void {
				$this->sink[] = $event;

			}//end enqueue()
		};

	}//end recordingQueue()

	/**
	 * Build the canonical Appointment payload used by the suite.
	 *
	 * Mirrors the slice-05 BookingDetailController booking shape.
	 *
	 * @return array<string, mixed>
	 */
	private function appointmentPayload(): array {
		return [
			'appointmentId' => self::BOOKING_ID,
			'administrationId' => 'admin-test',
			'pipelinqContactId' => self::EXTERNAL_ID,
			'serviceId' => 'baking-consult',
			'resourceId' => 'baker-anna',
			'customerId' => 'cust-001',
			'customerName' => 'Bakkerij de Zon B.V.',
			'customerEmail' => 'info@bakkerij-de-zon.nl',
			'startTime' => '2026-06-08T10:00:00Z',
			'endTime' => '2026-06-08T11:00:00Z',
			'status' => 'pending',
			'notes' => 'Quarterly bakery review.',
			'createdAt' => '2026-06-07T09:30:00Z',
			'updatedAt' => '2026-06-07T09:30:00Z',
		];

	}//end appointmentPayload()

	/**
	 * Wrap an Appointment payload in an ObjectEntity carrying the numeric
	 * schema **id**, exactly as OpenRegister stamps it
	 * (`setSchema((string) $schema->getId())`).
	 *
	 * A hand-built entity carrying the slug is a shape production never
	 * produces; the `appointment` slug arrives through the resolver.
	 *
	 * @param array<string, mixed> $appointment Payload.
	 *
	 * @return ObjectEntity
	 */
	private function appointmentEntity(array $appointment): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('7001');
		$entity->setObject($appointment);
		return $entity;
	}//end appointmentEntity()

	/**
	 * Build a ListenerSchemaResolver stub that reports a given schema slug.
	 *
	 * @param string $slug Slug the resolver resolves the entity's id to.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function schemaResolver(string $slug = 'appointment'): ListenerSchemaResolver {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);
		return $resolver;
	}//end schemaResolver()

	/**
	 * Integration scenario 1 — create booking publishes a timeline event.
	 *
	 * GIVEN the pipelinq mock server is reachable AND a booking carries
	 * a linked pipelinq Contact id; WHEN the booking-created event
	 * fires (the listener's commit boundary); THEN a POST is asserted
	 * to the timeline endpoint with the fixed JSON payload AND the
	 * booking row is preserved (still in pending status, never
	 * destructively rewritten by the listener).
	 *
	 * Maps to tasks.md L7:
	 *   "Integration: create booking → timeline event publishes
	 *    (assert POST payload + booking saved + confirmation logged)".
	 *
	 * @return void
	 */
	public function testCreateBookingPublishesTimelineEvent(): void {
		$mock = new PipelinqMockServer();
		$cache = $this->inMemoryCache();
		$logger = $this->recordingLogger();
		$forcedStatusQueue = [];
		$adapter = $this->buildAdapter($mock, $cache, $logger, $forcedStatusQueue);
		$enqueueCalls = [];
		$queue = $this->recordingQueue($enqueueCalls);

		$listener = new BookingCreatedTimelinePublishListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			schemaResolver: $this->schemaResolver(),
			logger: $logger
		);

		// The persisted appointment row — equivalent to the OR "save"
		// boundary. The listener fires immediately after persist.
		$appointment = $this->appointmentPayload();

		$listener->handle(new ObjectCreatedEvent($this->appointmentEntity($appointment)));

		// 1. The mock router observed exactly one POST to the publish
		// endpoint, carrying the fixed payload contract.
		$requests = $mock->getRequests();
		$posts = array_values(array_filter($requests, static fn (array $r): bool => $r['method'] === 'POST'));
		self::assertCount(1, $posts, 'expected exactly one POST to pipelinq timeline');
		self::assertSame('/timeline', $posts[0]['path']);

		$body = (string)$posts[0]['body'];
		self::assertNotSame('', $body, 'publish body must be present');
		$decoded = json_decode($body, true);
		self::assertIsArray($decoded);
		self::assertSame('booking.created', $decoded['type']);
		self::assertSame(self::BOOKING_ID, $decoded['externalId']);
		self::assertSame(self::EXTERNAL_ID, $decoded['contactId']);
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			(string)$decoded['timestamp']
		);
		self::assertIsArray($decoded['metadata']);
		self::assertSame(self::BOOKING_ID, $decoded['metadata']['bookingNumber']);
		self::assertSame('baking-consult', $decoded['metadata']['service']);

		// 2. Booking row remains intact — the listener is read-only
		// and never mutates the appointment.
		self::assertSame('pending', $appointment['status']);

		// 3. Confirmation log line is emitted at INFO level by the
		// adapter's success path.
		$infos = array_values(
			array_filter(
				$logger->records,
				static fn (array $r): bool => $r['level'] === 'info'
					&& str_contains($r['message'], 'pipelinq timeline publish succeeded')
			)
		);
		self::assertNotEmpty($infos, 'expected an INFO-level publish-succeeded log line');

		// 4. No retry hand-off because the synchronous publish succeeded.
		self::assertSame([], $enqueueCalls, 'no retry hand-off expected on success');

		// 5. The bearer token must NEVER appear in any log line.
		foreach ($logger->records as $record) {
			$encoded = json_encode($record);
			self::assertStringNotContainsString('integration-bearer-token-DO-NOT-LOG', (string)$encoded);
		}

	}//end testCreateBookingPublishesTimelineEvent()

	/**
	 * Integration scenario 2 — profile card data renders in detail view.
	 *
	 * GIVEN the mock returns Contact + klantbeeld via the bundled
	 * fixtures; WHEN the slice-03 + slice-04 reads run (the
	 * BookingDetailController's effective data dependencies); THEN
	 * the contact carries name/email/phone AND up to 5 transactions
	 * are returned.
	 *
	 * Maps to tasks.md L8:
	 *   "Integration: customer profile card displays in detail view
	 *    (name, email, phone + up to 5 transactions)".
	 *
	 * @return void
	 */
	public function testProfileCardDisplaysInDetailView(): void {
		$mock = new PipelinqMockServer();
		$cache = $this->inMemoryCache();
		$logger = $this->recordingLogger();
		$forcedStatusQueue = [];
		$adapter = $this->buildAdapter($mock, $cache, $logger, $forcedStatusQueue);

		// Slice-03: Contact lookup hydrates the profile.
		$contact = $adapter->getContact(externalId: self::EXTERNAL_ID);
		self::assertInstanceOf(PipelinqContact::class, $contact);
		self::assertTrue($contact->isFound(), 'contact fixture must be parsed as found');
		self::assertSame('Bakkerij de Zon B.V.', $contact->legalName);
		self::assertSame('info@bakkerij-de-zon.nl', $contact->email);
		self::assertSame('+31 6 12345678', $contact->phone);
		self::assertSame('Zonneplein 10, 1234 AB Amsterdam', $contact->address);
		self::assertSame('12345678', $contact->kvkNumber);

		// Slice-04: Klantbeeld history hydrates the transaction list.
		$klantbeeld = $adapter->fetchKlantbeeld(externalId: self::EXTERNAL_ID, limit: 5);
		self::assertInstanceOf(KlantbeeldResult::class, $klantbeeld);
		self::assertFalse($klantbeeld->isUnavailable());
		self::assertFalse($klantbeeld->isEmpty());
		self::assertLessThanOrEqual(5, count($klantbeeld->transactions));
		self::assertNotEmpty($klantbeeld->transactions);
		$first = $klantbeeld->transactions[0];
		self::assertSame('2026-05-23', $first->date);
		self::assertSame('Invoice INV-2026-0103', $first->description);
		self::assertSame(850.00, $first->amount);
		self::assertSame('EUR', $first->currency);
		self::assertSame('open', $first->status);

		// The mock observed exactly one GET per read (no retries on 2xx).
		$requests = $mock->getRequests();
		$gets = array_values(array_filter($requests, static fn (array $r): bool => $r['method'] === 'GET'));
		self::assertCount(2, $gets, 'expected one contact GET + one klantbeeld GET');
		self::assertSame('/contacts/' . self::EXTERNAL_ID, $gets[0]['path']);
		self::assertSame('/klantbeeld/' . self::EXTERNAL_ID, $gets[1]['path']);

		// Bearer token never reaches logs.
		foreach ($logger->records as $record) {
			self::assertStringNotContainsString(
				'integration-bearer-token-DO-NOT-LOG',
				(string)json_encode($record)
			);
		}

	}//end testProfileCardDisplaysInDetailView()

	/**
	 * Integration scenario 3 — graceful degradation when pipelinq is
	 * unavailable.
	 *
	 * GIVEN the pipelinq mock returns 5xx (forced); WHEN a booking is
	 * created and its detail view loaded; THEN
	 *
	 *   - the booking is still "saved" (the listener never raises),
	 *   - the synchronous publish fails and the event is queued for
	 *     retry (slice 09 hand-off),
	 *   - the Contact read surfaces a transport exception which the
	 *     detail controller would turn into a `contactError`,
	 *   - the klantbeeld envelope reports unavailable so the UI keeps
	 *     rendering the profile shell with history hidden.
	 *
	 * Maps to tasks.md L9:
	 *   "Integration: graceful degradation when pipelinq unavailable
	 *    (booking saved, event queued, card shows error, history hidden)".
	 *
	 * @return void
	 */
	public function testGracefulDegradationWhenPipelinqUnavailable(): void {
		$mock = new PipelinqMockServer();
		$cache = $this->inMemoryCache();
		$logger = $this->recordingLogger();
		$forcedStatusQueue = [];
		$adapter = $this->buildAdapter($mock, $cache, $logger, $forcedStatusQueue);
		$enqueueCalls = [];
		$queue = $this->recordingQueue($enqueueCalls);

		// === Publish path — booking-created event with pipelinq 503. ===
		$listener = new BookingCreatedTimelinePublishListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			schemaResolver: $this->schemaResolver(),
			logger: $logger
		);

		// Prime one 503 per retry attempt — the adapter's request loop
		// re-issues `dispatch()` up to RetryPolicy::MAX_ATTEMPTS times
		// on transient 5xx. Unlike the mock's single-shot
		// `forceStatus()`, the harness queue is consumed one entry per
		// dispatch so every attempt sees 503.
		for ($i = 0; $i < RetryPolicy::MAX_ATTEMPTS; $i++) {
			$forcedStatusQueue[] = 503;
		}

		$appointment = $this->appointmentPayload();
		$listener->handle(new ObjectCreatedEvent($this->appointmentEntity($appointment)));

		// 1. Booking row is preserved — the listener never throws or
		// mutates the appointment.
		self::assertSame('pending', $appointment['status']);

		// 2. The event was handed off to the retry queue.
		self::assertCount(1, $enqueueCalls, 'expected exactly one retry hand-off');
		$queued = $enqueueCalls[0];
		self::assertSame('booking.created', $queued->type());
		self::assertSame(self::BOOKING_ID, $queued->externalId());
		self::assertSame(self::EXTERNAL_ID, $queued->contactId());

		// 3. A WARNING log line marks the publish failure (the
		// confirmation INFO line is absent on this path).
		$warnings = array_values(
			array_filter(
				$logger->records,
				static fn (array $r): bool => $r['level'] === 'warning'
					&& str_contains($r['message'], 'pipelinq timeline publish failed')
			)
		);
		self::assertNotEmpty($warnings, 'expected a WARNING-level publish-failed log line');

		$infos = array_values(
			array_filter(
				$logger->records,
				static fn (array $r): bool => $r['level'] === 'info'
					&& str_contains($r['message'], 'pipelinq timeline publish succeeded')
			)
		);
		self::assertSame([], $infos, 'no success log line expected on 5xx path');

		// === Detail-view read paths — Contact 503 + klantbeeld 503. ===
		// Reset the request history so the read-path assertions only
		// count the GETs we're about to issue. We also need a fresh
		// adapter — the publish path already recorded failures on the
		// shared circuit breaker, which would otherwise short-circuit
		// the reads. The fresh adapter still talks to the same
		// recording mock so request history accumulates as expected.
		$mock->reset();
		$readForcedQueue = [];
		$readAdapter = $this->buildAdapter($mock, $cache, $logger, $readForcedQueue);

		// Contact read with 5xx surfaces a TransportException (the
		// controller catches it and translates to `contactError`).
		// Prime one 5xx per retry attempt in the budget.
		for ($i = 0; $i < RetryPolicy::MAX_ATTEMPTS; $i++) {
			$readForcedQueue[] = 503;
		}

		$contactException = null;
		try {
			$readAdapter->getContact(externalId: self::EXTERNAL_ID);
		} catch (\Throwable $e) {
			$contactException = $e;
		}

		self::assertNotNull($contactException, 'Contact read must propagate transport error when no cache');
		self::assertSame(
			'OCA\\Shillinq\\Service\\Pipelinq\\PipelinqTransportException',
			$contactException::class
		);

		// Klantbeeld envelope reports unavailable (never throws by
		// contract — the UI keeps rendering the profile).
		$klantbeeldForcedQueue = [];
		$klantbeeldAdapter = $this->buildAdapter($mock, $cache, $logger, $klantbeeldForcedQueue);
		for ($i = 0; $i < RetryPolicy::MAX_ATTEMPTS; $i++) {
			$klantbeeldForcedQueue[] = 503;
		}

		$klantbeeld = $klantbeeldAdapter->fetchKlantbeeld(externalId: self::EXTERNAL_ID);
		self::assertTrue($klantbeeld->isUnavailable(), 'klantbeeld must report unavailable on 5xx');
		self::assertSame([], $klantbeeld->transactions);

		// Bearer token never leaked across any of the WARNING / ERROR
		// log lines that the failure path emitted.
		foreach ($logger->records as $record) {
			self::assertStringNotContainsString(
				'integration-bearer-token-DO-NOT-LOG',
				(string)json_encode($record)
			);
		}

	}//end testGracefulDegradationWhenPipelinqUnavailable()
}//end class

/**
 * Minimal in-process {@see IResponse} implementation used by the
 * integration harness to wrap the mock router's response envelope.
 *
 * Kept here (not as a public test fixture) because it is an internal
 * scaffold detail of the customer-bridge integration suite.
 */
final class IntegrationTestResponse implements IResponse {
	/**
	 * @param int $statusCode HTTP status code.
	 * @param string $body Response body.
	 */
	public function __construct(
		private readonly int $statusCode,
		private readonly string $body,
	) {

	}//end __construct()

	/**
	 * @return string
	 */
	public function getBody() {
		return $this->body;
	}//end getBody()

	/**
	 * @return int
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * @param string $key Header name.
	 *
	 * @return string
	 */
	public function getHeader(string $key): string {
		return '';
	}//end getHeader()

	/**
	 * @return array<string, string>
	 */
	public function getHeaders(): array {
		return [];
	}//end getHeaders()
}//end class
