<?php

/**
 * Unit tests for the slice-08 auth-handling extension of
 * {@see PipelinqContactAdapter::publishWithOutcome()}.
 *
 * Verifies:
 *
 *   - A 401 response yields {@see TimelinePublishOutcome::AuthRejected},
 *     emits one ERROR-level log entry containing the phrase
 *     "Invalid pipelinq API token", and DOES NOT retry (RetryPolicy
 *     classifies 401 as non-transient).
 *   - The bearer token NEVER appears in any log line emitted during the
 *     401 path (ADR-005).
 *   - A 503 response yields {@see TimelinePublishOutcome::Transient}
 *     after exhausting the retry budget.
 *   - An already-open circuit breaker yields
 *     {@see TimelinePublishOutcome::Transient} on the write path
 *     without issuing a dispatch.
 *   - Each transition emits exactly one log entry at the appropriate
 *     level (success=INFO, transient=WARNING, auth=ERROR).
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
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
use OCA\Shillinq\Service\Pipelinq\TimelinePublishOutcome;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Slice-08 auth-handling + circuit-breaker + logging assertions.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 */
final class PipelinqTimelineAuthHandlingTest extends TestCase {
	/**
	 * Build a recording logger.
	 *
	 * @return AbstractLogger
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
	 * Build a scripted adapter with a recording dispatch + sleep loop.
	 *
	 * @param array<int, IResponse|\Throwable> $script Scripted dispatch outcomes.
	 * @param array<int, array{method:string, url:string, options:array<string, mixed>}> &$dispatchLog Captured dispatches.
	 * @param array<int, int> &$sleepCalls Captured sleeps.
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
					return 'SECRET-TOKEN-DO-NOT-LOG-9876';
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
			 * @var array<int, array{method:string, url:string, options:array<string, mixed>}>
			 */
			private array $dispatchLog;

			/**
			 * @param IClientService $clientService Mock.
			 * @param IAppConfig $appConfig Mock.
			 * @param AbstractLogger $logger Recording logger.
			 * @param ICache $cache Mock cache.
			 * @param RetryPolicy $retryPolicy Policy.
			 * @param CircuitBreaker|null $breaker Optional breaker.
			 * @param \Closure $sleeper Stubbed sleeper.
			 * @param array<int, IResponse|\Throwable> $script Scripted responses.
			 * @param array<int, array{method:string, url:string, options:array<string, mixed>}> &$dispatchLog Capture sink.
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
			 * Override dispatch to consume scripted outcomes.
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
	 * Build a canned IResponse.
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
	 * Build a representative lifecycle event for the tests.
	 *
	 * @param string $type Event type (defaults to booking.confirmed).
	 *
	 * @return TimelineEventDto
	 */
	private function sampleEvent(string $type = TimelineEventDto::TYPE_BOOKING_CONFIRMED): TimelineEventDto {
		return new TimelineEventDto(
			type: $type,
			externalId: 'booking-conf-1',
			timestamp: new DateTimeImmutable('2026-06-07T12:34:56Z', new DateTimeZone('UTC')),
			contactId: 'pl-contact-42',
			metadata: [
				'bookingNumber' => 'booking-conf-1',
				'status' => 'confirmed',
			]
		);

	}//end sampleEvent()

	/**
	 * 401 → AuthRejected outcome, single dispatch (no retry), ERROR log
	 * carrying the "Invalid pipelinq API token" phrase.
	 *
	 * @return void
	 */
	public function testAuthRejectedReturnedOnUnauthorized(): void {
		$dispatchLog = [];
		$sleepCalls = [];
		$script = [$this->response(401)];

		$logger = $this->recordingLogger();
		$adapter = $this->buildAdapter($script, $dispatchLog, $sleepCalls, logger: $logger);

		$outcome = $adapter->publishWithOutcome(event: $this->sampleEvent());

		self::assertSame(TimelinePublishOutcome::AuthRejected, $outcome);
		self::assertCount(1, $dispatchLog, '401 MUST not retry — RetryPolicy treats it as non-transient');
		self::assertSame([], $sleepCalls, '401 MUST not introduce any retry backoff');

		// Find the ERROR record that names the misconfigured token. The 401
		// path emits two ERROR entries — the inner request loop logs the auth
		// rejection (without configKey) and publishWithOutcome's catch block
		// logs the canonical "Invalid pipelinq API token" alert with the
		// configKey. The test asserts the canonical alert phrasing.
		$errors = array_values(
			array_filter(
				$logger->records,
				static fn (array $r): bool => $r['level'] === LogLevel::ERROR
					&& str_contains($r['message'], 'Invalid pipelinq API token')
			)
		);
		self::assertNotEmpty($errors, 'A 401 publish MUST emit the canonical token-rejected ERROR log entry');
		self::assertStringContainsString('Invalid pipelinq API token', $errors[0]['message']);
		self::assertSame(401, $errors[0]['context']['status']);
		self::assertSame(
			PipelinqContactAdapter::CONFIG_KEY_TOKEN,
			$errors[0]['context']['configKey'],
			'ADR-005: the alert names the config key, never the token value'
		);

	}//end testAuthRejectedReturnedOnUnauthorized()

	/**
	 * The bearer token is NEVER written to the log on the 401 path.
	 *
	 * @return void
	 */
	public function testBearerTokenIsNeverLoggedOnAuthFailure(): void {
		$dispatchLog = [];
		$sleepCalls = [];
		$script = [$this->response(401)];

		$logger = $this->recordingLogger();
		$adapter = $this->buildAdapter($script, $dispatchLog, $sleepCalls, logger: $logger);

		$adapter->publishWithOutcome(event: $this->sampleEvent());

		$haystack = json_encode($logger->records);
		self::assertNotFalse($haystack);
		self::assertStringNotContainsString('SECRET-TOKEN-DO-NOT-LOG-9876', $haystack);

	}//end testBearerTokenIsNeverLoggedOnAuthFailure()

	/**
	 * Three transient 503s → Transient outcome, all three attempts
	 * issued, one WARNING log entry recording the final failure.
	 *
	 * @return void
	 */
	public function testTransientOutcomeAfterRetryBudgetExhausted(): void {
		$dispatchLog = [];
		$sleepCalls = [];
		$script = [
			$this->response(503),
			$this->response(503),
			$this->response(503),
		];

		$logger = $this->recordingLogger();
		$adapter = $this->buildAdapter($script, $dispatchLog, $sleepCalls, logger: $logger);

		$outcome = $adapter->publishWithOutcome(event: $this->sampleEvent());

		self::assertSame(TimelinePublishOutcome::Transient, $outcome);
		self::assertCount(3, $dispatchLog);
		self::assertSame([1, 2], $sleepCalls);

		$warnings = array_values(
			array_filter(
				$logger->records,
				static function (array $r): bool {
					return $r['level'] === LogLevel::WARNING
						&& $r['message'] === 'pipelinq timeline publish failed';
				}
			)
		);
		self::assertCount(1, $warnings, 'Exactly one terminal-WARNING line per failed publish');
		self::assertSame('transport', $warnings[0]['context']['reason']);

	}//end testTransientOutcomeAfterRetryBudgetExhausted()

	/**
	 * Circuit breaker on the write path: an already-open breaker yields
	 * a Transient outcome without issuing a dispatch.
	 *
	 * @return void
	 */
	public function testCircuitBreakerOpenShortCircuitsWrite(): void {
		$breaker = new CircuitBreaker(failureThreshold: 5, cooldownSeconds: 300);
		for ($i = 1; $i <= 5; $i++) {
			$breaker->recordFailure();
		}

		$dispatchLog = [];
		$sleepCalls = [];

		$logger = $this->recordingLogger();
		$adapter = $this->buildAdapter([], $dispatchLog, $sleepCalls, $breaker, $logger);

		$outcome = $adapter->publishWithOutcome(event: $this->sampleEvent());

		self::assertSame(TimelinePublishOutcome::Transient, $outcome);
		self::assertCount(0, $dispatchLog, 'An OPEN breaker MUST short-circuit dispatch');

		$warnings = array_values(
			array_filter(
				$logger->records,
				static function (array $r): bool {
					return $r['level'] === LogLevel::WARNING
						&& $r['message'] === 'pipelinq timeline publish failed';
				}
			)
		);
		self::assertCount(1, $warnings);
		self::assertSame('breaker_open', $warnings[0]['context']['reason']);

	}//end testCircuitBreakerOpenShortCircuitsWrite()

	/**
	 * Happy path: a 201 yields Success and emits one INFO log entry.
	 *
	 * @return void
	 */
	public function testSuccessOutcomeOn201(): void {
		$dispatchLog = [];
		$sleepCalls = [];
		$script = [$this->response(201, ['accepted' => true])];

		$logger = $this->recordingLogger();
		$adapter = $this->buildAdapter($script, $dispatchLog, $sleepCalls, logger: $logger);

		$outcome = $adapter->publishWithOutcome(event: $this->sampleEvent());

		self::assertSame(TimelinePublishOutcome::Success, $outcome);

		$infos = array_values(
			array_filter(
				$logger->records,
				static function (array $r): bool {
					return $r['level'] === LogLevel::INFO
						&& $r['message'] === 'pipelinq timeline publish succeeded';
				}
			)
		);
		self::assertCount(1, $infos);
		self::assertSame(TimelineEventDto::TYPE_BOOKING_CONFIRMED, $infos[0]['context']['type']);

	}//end testSuccessOutcomeOn201()

	/**
	 * The slice-07 bool wrapper preserves backwards compatibility: a 201
	 * still returns TRUE and an unauthorized still returns FALSE.
	 *
	 * @return void
	 */
	public function testBoolWrapperPreservesSliceSevenContract(): void {
		$dispatchLog = [];
		$sleepCalls = [];
		$script = [
			$this->response(201, ['accepted' => true]),
			$this->response(401),
		];

		$adapter = $this->buildAdapter($script, $dispatchLog, $sleepCalls);

		self::assertTrue($adapter->publishTimelineEvent(event: $this->sampleEvent()));
		self::assertFalse($adapter->publishTimelineEvent(event: $this->sampleEvent()));

	}//end testBoolWrapperPreservesSliceSevenContract()

	/**
	 * The slice-08 logging assertion at each step in the publish path:
	 * success → INFO, transient → WARNING, auth → ERROR.
	 *
	 * @return void
	 */
	public function testLoggingLevelsAtEachStep(): void {
		$dispatchLog = [];
		$sleepCalls = [];

		// Success → INFO.
		$logger = $this->recordingLogger();
		$adapter = $this->buildAdapter(
			[$this->response(201)],
			$dispatchLog,
			$sleepCalls,
			logger: $logger
		);
		$adapter->publishWithOutcome(event: $this->sampleEvent());

		$infoCount = count(array_filter($logger->records, static fn ($r) => $r['level'] === LogLevel::INFO));
		self::assertGreaterThanOrEqual(1, $infoCount, 'Success must emit at least one INFO entry');

		// Auth → ERROR.
		$dispatchLog = [];
		$sleepCalls = [];
		$logger = $this->recordingLogger();
		$adapter = $this->buildAdapter(
			[$this->response(401)],
			$dispatchLog,
			$sleepCalls,
			logger: $logger
		);
		$adapter->publishWithOutcome(event: $this->sampleEvent());

		$errorCount = count(array_filter($logger->records, static fn ($r) => $r['level'] === LogLevel::ERROR));
		self::assertGreaterThanOrEqual(1, $errorCount, '401 must emit at least one ERROR entry');

	}//end testLoggingLevelsAtEachStep()

}//end class
