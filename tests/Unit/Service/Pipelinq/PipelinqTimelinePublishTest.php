<?php

/**
 * Unit tests for the PipelinqContactAdapter::publishTimelineEvent() path.
 *
 * Slice 07 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Exercises the publish-side semantics on top of the slice-02 transport:
 *
 *   - 201 Created → returns TRUE and POSTs to `/api/v1/timeline` with
 *     the fixed JSON payload (type, externalId, timestamp, contactId,
 *     metadata).
 *   - Transient 5xx → retries up to 3 times and ultimately returns
 *     FALSE; the adapter does NOT raise on publish failure.
 *   - Open breaker → returns FALSE without issuing a dispatch.
 *   - The bearer token never appears in any log line.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\Pipelinq\CircuitBreaker;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\RetryPolicy;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Adapter publishTimelineEvent semantics: success / retry / breaker /
 * payload contract / token redaction.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 */
final class PipelinqTimelinePublishTest extends TestCase {
	/**
	 * Build a recording logger that captures every log call.
	 *
	 * @return AbstractLogger Anonymous logger with `$records`.
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
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

	}//end recordingLogger()

	/**
	 * Build a scripted adapter that captures dispatch calls + sleep
	 * durations and uses the supplied response queue.
	 *
	 * @param array<int, IResponse|\Throwable> $script Scripted dispatch outcomes.
	 * @param array<int, array{method:string, url:string, options: array<string, mixed>}> &$dispatchLog Captured dispatches.
	 * @param array<int, int> &$sleepCalls Captured sleep durations.
	 * @param CircuitBreaker|null $breaker Optional shared breaker.
	 * @param AbstractLogger|null $logger Logger override.
	 *
	 * @return PipelinqContactAdapter
	 */
	private function buildAdapter(
		array $script,
		array &$dispatchLog,
		array &$sleepCalls,
		?CircuitBreaker $breaker = null,
		?AbstractLogger $logger = null,
	): PipelinqContactAdapter {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === PipelinqContactAdapter::CONFIG_KEY_ENDPOINT) {
					return 'https://pipelinq.test';
				}

				if ($key === PipelinqContactAdapter::CONFIG_KEY_TOKEN) {
					return 'NEVER-LOG-ME-1234567890';
				}

				return $default;
			}
		);

		if ($logger === null) {
			$logger = $this->recordingLogger();
		}

		$clientService = $this->createMock(IClientService::class);
		$cache = $this->createMock(ICache::class);

		$sleeper = function (int $seconds) use (&$sleepCalls): void {
			$sleepCalls[] = $seconds;
		};

		return new class($clientService, $appConfig, $logger, $cache, new RetryPolicy(), $breaker, $sleeper, $script, $dispatchLog) extends PipelinqContactAdapter {
			/**
			 * @var array<int, IResponse|\Throwable>
			 */
			private array $script;

			/**
			 * @var array<int, array{method:string, url:string, options: array<string, mixed>}>
			 */
			private array $dispatchLog;

			/**
			 * @param IClientService $clientService Mock.
			 * @param IAppConfig $appConfig Mock.
			 * @param AbstractLogger $logger Recording logger.
			 * @param ICache $cache Mock cache.
			 * @param RetryPolicy $retryPolicy Retry policy.
			 * @param CircuitBreaker|null $breaker Optional breaker.
			 * @param \Closure $sleeper Stubbed sleeper.
			 * @param array<int, IResponse|\Throwable> $script Scripted outcomes.
			 * @param array<int, array{method:string, url:string, options: array<string, mixed>}> &$dispatchLog Capture sink.
			 */
			public function __construct(
				IClientService $clientService,
				IAppConfig $appConfig,
				$logger,
				ICache $cache,
				RetryPolicy $retryPolicy,
				?CircuitBreaker $breaker,
				\Closure $sleeper,
				array $script,
				array &$dispatchLog,
			) {
				parent::__construct($clientService, $appConfig, $logger, $cache, $retryPolicy, $breaker, $sleeper);
				$this->script = $script;
				$this->dispatchLog = & $dispatchLog;
			}//end __construct()

			/**
			 * Override dispatch to consume the scripted queue + record the call.
			 *
			 * @param string $method HTTP method.
			 * @param string $url Full URL.
			 * @param array<string, mixed> $options Guzzle-shaped options.
			 *
			 * @return IResponse
			 */
			protected function dispatch(string $method, string $url, array $options): IResponse {
				$this->dispatchLog[] = ['method' => $method, 'url' => $url, 'options' => $options];
				$outcome = array_shift($this->script);
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				if ($outcome === null) {
					throw new \RuntimeException('script exhausted');
				}

				return $outcome;
			}//end dispatch()
		};

	}//end buildAdapter()

	/**
	 * Build a canned IResponse with the given status and JSON body.
	 *
	 * @param int $statusCode HTTP status code.
	 * @param array<string, mixed> $body JSON-serialisable body.
	 *
	 * @return IResponse
	 */
	private function response(int $statusCode, array $body = []): IResponse {
		$encoded = json_encode($body, JSON_THROW_ON_ERROR);

		return new class($statusCode, (string)$encoded) implements IResponse {
			/**
			 * @param int $statusCode HTTP status.
			 * @param string $body Body string.
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
		};

	}//end response()

	/**
	 * Build a representative timeline event for the tests.
	 *
	 * @return TimelineEventDto
	 */
	private function sampleEvent(): TimelineEventDto {
		return new TimelineEventDto(
			type: TimelineEventDto::TYPE_BOOKING_CREATED,
			externalId: 'booking-abc-123',
			timestamp: new DateTimeImmutable('2026-06-06T12:34:56Z', new DateTimeZone('UTC')),
			contactId: 'pl-contact-42',
			metadata: [
				'bookingNumber' => 'booking-abc-123',
				'service' => 'haircut',
			]
		);

	}//end sampleEvent()

	/**
	 * A 201 Created publishes the fixed payload to `/api/v1/timeline`
	 * with a Bearer token + returns TRUE.
	 *
	 * @return void
	 */
	public function testSuccessPostsFixedPayloadAndReturnsTrue(): void {
		$dispatchLog = [];
		$sleepCalls = [];
		$script = [$this->response(201, ['accepted' => true])];

		$adapter = $this->buildAdapter($script, $dispatchLog, $sleepCalls);

		$result = $adapter->publishTimelineEvent(event: $this->sampleEvent());

		self::assertTrue($result);
		self::assertCount(1, $dispatchLog);
		self::assertSame('POST', $dispatchLog[0]['method']);
		self::assertSame('https://pipelinq.test/api/v1/timeline', $dispatchLog[0]['url']);

		$options = $dispatchLog[0]['options'];
		self::assertSame('application/json', $options['headers']['Content-Type']);
		self::assertSame('Bearer NEVER-LOG-ME-1234567890', $options['headers']['Authorization']);

		$body = json_decode((string)$options['body'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(
			[
				'type' => 'booking.created',
				'externalId' => 'booking-abc-123',
				'timestamp' => '2026-06-06T12:34:56Z',
				'contactId' => 'pl-contact-42',
				'metadata' => [
					'bookingNumber' => 'booking-abc-123',
					'service' => 'haircut',
				],
			],
			$body,
			'POST body must match the design.md fixed payload contract.'
		);

	}//end testSuccessPostsFixedPayloadAndReturnsTrue()

	/**
	 * Three transient 503s exhaust the retry budget; the publish method
	 * returns FALSE (per the spec — best-effort, never raise).
	 *
	 * @return void
	 */
	public function testTransientFailureRetriesThenReturnsFalse(): void {
		$dispatchLog = [];
		$sleepCalls = [];
		$script = [
			$this->response(503),
			$this->response(503),
			$this->response(503),
		];

		$adapter = $this->buildAdapter($script, $dispatchLog, $sleepCalls);

		$result = $adapter->publishTimelineEvent(event: $this->sampleEvent());

		self::assertFalse($result);
		self::assertCount(3, $dispatchLog, 'All 3 attempts must be issued');
		self::assertSame([1, 2], $sleepCalls, 'Backoff schedule 1s + 2s between the 3 attempts');

	}//end testTransientFailureRetriesThenReturnsFalse()

	/**
	 * An already-open breaker fast-fails the publish without issuing a
	 * dispatch.
	 *
	 * @return void
	 */
	public function testOpenBreakerFastFailsAndReturnsFalse(): void {
		$breaker = new CircuitBreaker(failureThreshold: 5, cooldownSeconds: 300);
		for ($i = 1; $i <= 5; $i++) {
			$breaker->recordFailure();
		}

		$dispatchLog = [];
		$sleepCalls = [];
		$adapter = $this->buildAdapter([], $dispatchLog, $sleepCalls, $breaker);

		$result = $adapter->publishTimelineEvent(event: $this->sampleEvent());

		self::assertFalse($result);
		self::assertCount(0, $dispatchLog, 'No request must be issued while OPEN');

	}//end testOpenBreakerFastFailsAndReturnsFalse()

	/**
	 * The bearer token never appears in any log line, even when every
	 * attempt fails.
	 *
	 * @return void
	 */
	public function testBearerTokenIsNeverLogged(): void {
		$dispatchLog = [];
		$sleepCalls = [];
		$script = [
			$this->response(502),
			$this->response(502),
			$this->response(502),
		];

		$logger = $this->recordingLogger();
		$adapter = $this->buildAdapter($script, $dispatchLog, $sleepCalls, logger: $logger);

		$adapter->publishTimelineEvent(event: $this->sampleEvent());

		$haystack = json_encode($logger->records);
		self::assertNotFalse($haystack);
		self::assertStringNotContainsString('NEVER-LOG-ME-1234567890', $haystack);

	}//end testBearerTokenIsNeverLogged()

}//end class
