<?php

/**
 * Lifecycle-event integration tests for the customer-bridge chain.
 *
 * Slice 10 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Verifies that the publish path is symmetric across the four booking
 * lifecycle event types (created / confirmed / cancelled / completed)
 * by driving four distinct {@see TimelineEventDto} instances through
 * the slice-02 adapter and asserting one POST per type lands on the
 * mock server with the correct payload.
 *
 * Why an integration test rather than a Playwright spec for task 5:
 *
 *   - The slice-08 lifecycle listener (`ObjectTransitionedEvent` →
 *     publish on confirmed/cancelled/completed) lands in a separate
 *     in-flight slice; per the chain rule "Slice 10 is self-contained"
 *     we exercise the publish-contract layer here and leave the UI
 *     lifecycle event sequence to slice 08's own UI verification.
 *   - The chain guarantee at the publish boundary IS what matters for
 *     the timeline view: any well-formed lifecycle event reaches
 *     pipelinq the same way `booking.created` does (single POST, fixed
 *     payload). This test pins that down.
 *
 * Maps to tasks.md L14:
 *   "E2E: booking lifecycle triggers all four timeline events
 *    (created, confirmed, cancelled, completed appear in the timeline)".
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\Pipelinq\CircuitBreaker;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\RetryPolicy;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Tests\Integration\Pipelinq\PipelinqMockServer;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * All four lifecycle event types publish through the same adapter
 * contract. Asserts one POST per type with the fixed payload shape.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-10-integration-e2e-tests/tasks.md
 */
final class CustomerBridgeLifecycleEventsTest extends TestCase {

	/**
	 * Build a no-op recording logger.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * @param mixed $level Level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {

			}//end log()

		};

	}//end logger()

	/**
	 * Build a minimal in-process cache stub.
	 *
	 * @return ICache
	 */
	private function cache(): ICache {
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
			 * @param string $prefix Key prefix.
			 *
			 * @return bool
			 */
			public function clear($prefix = ''): bool {
				$this->store = [];
				return true;
			}//end clear()

			/**
			 * @return bool
			 */
			public static function isAvailable(): bool {
				return true;
			}//end isAvailable()

		};

	}//end cache()

	/**
	 * Build a PipelinqContactAdapter wired to a {@see PipelinqMockServer}
	 * for the publish path (POST `/api/v1/timeline`).
	 *
	 * @param PipelinqMockServer $mock Mock router.
	 *
	 * @return PipelinqContactAdapter
	 */
	private function buildAdapter(PipelinqMockServer $mock): PipelinqContactAdapter {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === PipelinqContactAdapter::CONFIG_KEY_ENDPOINT) {
					return 'https://pipelinq.test';
				}

				if ($key === PipelinqContactAdapter::CONFIG_KEY_TOKEN) {
					return 'lifecycle-test-token';
				}

				return $default;
			}
		);

		$clientService = $this->createMock(IClientService::class);
		$cache = $this->cache();
		$logger = $this->logger();
		$sleeper = static function (int $seconds): void {
		};

		return new class($clientService, $appConfig, $logger, $cache, new RetryPolicy(), null, $sleeper, $mock) extends PipelinqContactAdapter {
			/**
			 * @var PipelinqMockServer
			 */
			private PipelinqMockServer $mock;

			/**
			 * @param IClientService $clientService Mock client service.
			 * @param IAppConfig $appConfig Mock config.
			 * @param AbstractLogger $logger Logger.
			 * @param ICache $cache Cache.
			 * @param RetryPolicy $retryPolicy Retry policy.
			 * @param CircuitBreaker|null $breaker Optional breaker.
			 * @param \Closure $sleeper Stubbed sleeper.
			 * @param PipelinqMockServer $mock Mock router.
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
			) {
				parent::__construct($clientService, $appConfig, $logger, $cache, $retryPolicy, $breaker, $sleeper);
				$this->mock = $mock;

			}//end __construct()

			/**
			 * Route the publish through the mock and return 201.
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

				$body = ($options['body'] ?? null);
				$body = is_string($body) ? $body : null;
				$this->mock->dispatch(method: $method, path: $path, body: $body);

				return new IntegrationTestResponse(
					statusCode: 201,
					body: '{"accepted":true}'
				);

			}//end dispatch()

		};

	}//end buildAdapter()

	/**
	 * All four lifecycle event types publish via the adapter and each
	 * lands as exactly one POST with the correct fixed payload.
	 *
	 * @return void
	 */
	public function testAllFourLifecycleEventsPublishViaTimeline(): void {
		$mock = new PipelinqMockServer();
		$adapter = $this->buildAdapter($mock);

		$contactId = 'pl-contact-lifecycle-1';
		$externalId = 'booking-lifecycle-0001';
		$when = new DateTimeImmutable('2026-06-08T10:00:00Z', new DateTimeZone('UTC'));

		$eventTypes = [
			'booking.created',
			'booking.confirmed',
			'booking.cancelled',
			'booking.completed',
		];

		foreach ($eventTypes as $type) {
			$dto = new TimelineEventDto(
				type: $type,
				externalId: $externalId,
				timestamp: $when,
				contactId: $contactId,
				metadata: [
					'bookingNumber' => $externalId,
					'phase' => $type,
				]
			);

			$ok = $adapter->publishTimelineEvent(event: $dto);
			self::assertTrue($ok, sprintf('publishTimelineEvent for %s must succeed', $type));
		}

		// Exactly four POSTs landed on the mock.
		$requests = $mock->getRequests();
		$posts = array_values(array_filter(
			$requests,
			static fn (array $r): bool => $r['method'] === 'POST'
		));
		self::assertCount(4, $posts, 'expected one POST per lifecycle event');

		// Each POST carries the expected payload type field.
		$observedTypes = [];
		foreach ($posts as $request) {
			self::assertSame('/timeline', $request['path']);
			$body = json_decode((string)$request['body'], true);
			self::assertIsArray($body);
			self::assertSame($externalId, $body['externalId']);
			self::assertSame($contactId, $body['contactId']);
			self::assertSame('2026-06-08T10:00:00Z', $body['timestamp']);
			self::assertIsArray($body['metadata']);
			$observedTypes[] = (string)$body['type'];
		}

		self::assertSame($eventTypes, $observedTypes, 'lifecycle event order must be preserved');

	}//end testAllFourLifecycleEventsPublishViaTimeline()

	/**
	 * The DTO renders the same payload shape across all four event
	 * types — the contract pinned for slice 08's UI to consume.
	 *
	 * @return void
	 */
	public function testTimelineEventDtoShapeIsStableAcrossLifecycleTypes(): void {
		$when = new DateTimeImmutable('2026-06-08T10:00:00Z', new DateTimeZone('UTC'));

		$expectedKeys = ['type', 'externalId', 'timestamp', 'contactId', 'metadata'];

		foreach (['booking.created', 'booking.confirmed', 'booking.cancelled', 'booking.completed'] as $type) {
			$dto = new TimelineEventDto(
				type: $type,
				externalId: 'b-1',
				timestamp: $when,
				contactId: 'c-1',
				metadata: ['phase' => $type]
			);

			$payload = $dto->toPayload();
			self::assertSame($expectedKeys, array_keys($payload), sprintf('payload keys must be stable for %s', $type));
			self::assertSame($type, $payload['type']);

			// CloudEvents envelope also exposes the same data block.
			$cloudEvent = $dto->toCloudEvent();
			self::assertSame($type, $cloudEvent['type']);
			self::assertSame($payload, $cloudEvent['data']);
		}

	}//end testTimelineEventDtoShapeIsStableAcrossLifecycleTypes()

}//end class
