<?php

/**
 * Unit tests for the PipelinqContactAdapter request loop.
 *
 * Exercises the resilient transport contract: bounded exponential-backoff
 * retries, non-transient errors short-circuiting the loop, the circuit
 * breaker opening after 5 consecutive failures, and fail-fast behaviour
 * while the breaker is open. The adapter's HTTP dispatch is overridden in
 * a test subclass so tests run without a real HTTP client.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\Service\Pipelinq\CircuitBreaker;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\PipelinqTransportException;
use OCA\Shillinq\Service\Pipelinq\RetryPolicy;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Verifies retry policy + circuit breaker behaviour end-to-end through
 * the adapter request loop.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 */
final class PipelinqContactAdapterTest extends TestCase {
	/**
	 * Build a test subclass that exposes `request()` publicly and replaces
	 * the HTTP dispatch with a scripted queue of canned responses /
	 * exceptions.
	 *
	 * @param array<int, IResponse|\Throwable> $script Ordered list of dispatch outcomes.
	 * @param array<int, int> &$dispatchCalls Captured attempt counter (output).
	 * @param array<int, int> &$sleepCalls Captured sleep durations (output).
	 * @param CircuitBreaker|null $breaker Optional shared breaker.
	 *
	 * @return PipelinqContactAdapter
	 */
	private function buildAdapter(
		array $script,
		array &$dispatchCalls,
		array &$sleepCalls,
		?CircuitBreaker $breaker = null,
	): PipelinqContactAdapter {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === PipelinqContactAdapter::CONFIG_KEY_ENDPOINT) {
					return 'https://pipelinq.test';
				}

				if ($key === PipelinqContactAdapter::CONFIG_KEY_TOKEN) {
					return 'super-secret-token';
				}

				return $default;
			}
		);

		$logger = new class extends AbstractLogger {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Log level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

		$clientService = $this->createMock(IClientService::class);
		$cache = $this->createMock(ICache::class);

		$sleeper = function (int $seconds) use (&$sleepCalls): void {
			$sleepCalls[] = $seconds;
		};

		return new class($clientService, $appConfig, $logger, $cache, new RetryPolicy(), $breaker, $sleeper, $script, $dispatchCalls) extends PipelinqContactAdapter {
			/**
			 * @var array<int, IResponse|\Throwable>
			 */
			private array $script;

			/**
			 * @var array<int, int>
			 */
			private array $dispatchCalls;

			/**
			 * @param IClientService $clientService Mock client service.
			 * @param IAppConfig $appConfig Mock config.
			 * @param AbstractLogger $logger Recording logger.
			 * @param ICache $cache Mock cache.
			 * @param RetryPolicy $retryPolicy Retry policy.
			 * @param CircuitBreaker|null $breaker Optional shared breaker.
			 * @param \Closure $sleeper Stubbed sleeper.
			 * @param array<int, IResponse|\Throwable> $script Scripted dispatch outcomes.
			 * @param array<int, int> &$dispatchCalls Captured counter.
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
				array &$dispatchCalls,
			) {
				parent::__construct($clientService, $appConfig, $logger, $cache, $retryPolicy, $breaker, $sleeper);
				$this->script = $script;
				$this->dispatchCalls = & $dispatchCalls;
			}//end __construct()

			/**
			 * Bypass the real HTTP dispatch with a scripted queue.
			 *
			 * @param string $method HTTP method.
			 * @param string $url Full URL.
			 * @param array<string, mixed> $options Guzzle-shaped options.
			 *
			 * @return IResponse
			 */
			protected function dispatch(string $method, string $url, array $options): IResponse {
				$this->dispatchCalls[] = 1;
				$outcome = array_shift($this->script);
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				if ($outcome === null) {
					throw new \RuntimeException('script exhausted');
				}

				return $outcome;
			}//end dispatch()

			/**
			 * Expose the protected request loop to the test.
			 *
			 * @param string $method HTTP method.
			 * @param string $path Path.
			 * @param array<string, mixed>|null $payload Body payload.
			 *
			 * @return array<string, mixed>
			 */
			public function callRequest(string $method, string $path, ?array $payload = null): array {
				return $this->request($method, $path, $payload);
			}//end callRequest()
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
	 * Retry logic: a transient 503 on the first attempt succeeds on the
	 * second; the loop ran twice, slept 1s in between, and the breaker
	 * stayed CLOSED.
	 *
	 * @return void
	 */
	public function testRetryLogicSucceedsOnSecondAttempt(): void {
		$dispatchCalls = [];
		$sleepCalls = [];
		$script = [
			$this->response(503),
			$this->response(200, ['contactId' => 'abc-123']),
		];

		$adapter = $this->buildAdapter($script, $dispatchCalls, $sleepCalls);
		$body = $adapter->callRequest('GET', '/contacts/abc-123');

		self::assertSame(['contactId' => 'abc-123'], $body);
		self::assertCount(2, $dispatchCalls);
		self::assertSame([1], $sleepCalls, 'First retry waits 1s per the exponential schedule');
		self::assertSame(CircuitBreaker::STATE_CLOSED, $adapter->circuitState());

	}//end testRetryLogicSucceedsOnSecondAttempt()

	/**
	 * Three transient failures exhaust the retry budget and surface the
	 * last error to the caller. Sleep schedule = 1s + 2s (the policy
	 * waits BETWEEN attempts; no sleep after the final failure).
	 *
	 * @return void
	 */
	public function testRetryBudgetExhaustedAfterThreeTransientFailures(): void {
		$dispatchCalls = [];
		$sleepCalls = [];
		$script = [
			$this->response(502),
			$this->response(502),
			$this->response(502),
		];

		$adapter = $this->buildAdapter($script, $dispatchCalls, $sleepCalls);

		try {
			$adapter->callRequest('GET', '/contacts/abc-123');
			self::fail('Expected PipelinqTransportException');
		} catch (PipelinqTransportException $e) {
			self::assertSame(502, $e->statusCode());
		}

		self::assertCount(3, $dispatchCalls, 'All 3 attempts must be issued');
		self::assertSame([1, 2], $sleepCalls, 'Backoff schedule 1s + 2s between the 3 attempts');

	}//end testRetryBudgetExhaustedAfterThreeTransientFailures()

	/**
	 * Non-transient 404 short-circuits the retry loop on the first attempt.
	 *
	 * @return void
	 */
	public function testNonTransientErrorIsNotRetried(): void {
		$dispatchCalls = [];
		$sleepCalls = [];
		$script = [
			$this->response(404),
		];

		$adapter = $this->buildAdapter($script, $dispatchCalls, $sleepCalls);

		try {
			$adapter->callRequest('GET', '/contacts/missing');
			self::fail('Expected PipelinqTransportException');
		} catch (PipelinqTransportException $e) {
			self::assertSame(404, $e->statusCode());
		}

		self::assertCount(1, $dispatchCalls, '4xx must not be retried');
		self::assertSame([], $sleepCalls, 'No backoff before the loop exits');

	}//end testNonTransientErrorIsNotRetried()

	/**
	 * Five consecutive failed requests open the breaker; the 6th call
	 * fails fast WITHOUT issuing a request.
	 *
	 * @return void
	 */
	public function testCircuitBreakerOpensAfterFiveConsecutiveFailures(): void {
		$dispatchCalls = [];
		$sleepCalls = [];

		// Each request retries 3 times before giving up — 5 failing
		// requests = 15 scripted 502s.
		$script = [];
		for ($i = 1; $i <= 15; $i++) {
			$script[] = $this->response(502);
		}

		$breaker = new CircuitBreaker(failureThreshold: 5, cooldownSeconds: 300);
		$adapter = $this->buildAdapter($script, $dispatchCalls, $sleepCalls, $breaker);

		for ($req = 1; $req <= 5; $req++) {
			try {
				$adapter->callRequest('GET', '/contacts/abc-123');
				self::fail('Expected failure on request ' . $req);
			} catch (PipelinqTransportException) {
				// Expected.
			}
		}

		self::assertSame(CircuitBreaker::STATE_OPEN, $adapter->circuitState());
		self::assertCount(15, $dispatchCalls);

		// 6th call must fail fast without dispatching.
		try {
			$adapter->callRequest('GET', '/contacts/abc-123');
			self::fail('Expected fast-fail PipelinqTransportException');
		} catch (PipelinqTransportException $e) {
			self::assertTrue($e->isCircuitOpen(), 'Exception must mark the open-circuit fast-fail');
			self::assertSame(0, $e->statusCode());
		}

		self::assertCount(15, $dispatchCalls, 'No request issued while OPEN');

	}//end testCircuitBreakerOpensAfterFiveConsecutiveFailures()

	/**
	 * After the 5-minute cooldown the breaker moves to HALF_OPEN; a
	 * successful probe closes it again.
	 *
	 * @return void
	 */
	public function testCircuitBreakerHalfOpensAfterCooldownAndClosesOnSuccess(): void {
		$dispatchCalls = [];
		$sleepCalls = [];

		$now = 1000;
		$breaker = new CircuitBreaker(
			failureThreshold: 5,
			cooldownSeconds: 300,
			clock: static function () use (&$now): int {
				return $now;
			}
		);

		// Trip the breaker directly so we don't have to script 15 failures.
		for ($i = 1; $i <= 5; $i++) {
			$breaker->recordFailure();
		}

		self::assertSame(CircuitBreaker::STATE_OPEN, $breaker->state());

		// 301 seconds later → HALF_OPEN.
		$now += 301;
		self::assertSame(CircuitBreaker::STATE_HALF_OPEN, $breaker->state());

		$script = [$this->response(200, ['contactId' => 'after-cooldown'])];
		$adapter = $this->buildAdapter($script, $dispatchCalls, $sleepCalls, $breaker);

		$body = $adapter->callRequest('GET', '/contacts/after-cooldown');
		self::assertSame(['contactId' => 'after-cooldown'], $body);
		self::assertSame(CircuitBreaker::STATE_CLOSED, $adapter->circuitState());
		self::assertCount(1, $dispatchCalls);

	}//end testCircuitBreakerHalfOpensAfterCooldownAndClosesOnSuccess()

	/**
	 * The bearer token must never appear in any log line; only redacted
	 * context is recorded.
	 *
	 * @return void
	 */
	public function testBearerTokenIsNeverLogged(): void {
		$dispatchCalls = [];
		$sleepCalls = [];
		$script = [$this->response(502), $this->response(502), $this->response(502)];

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

		$logger = new class extends AbstractLogger {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Log level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

		$clientService = $this->createMock(IClientService::class);
		$cache = $this->createMock(ICache::class);

		$adapter = new class($clientService, $appConfig, $logger, $cache, new RetryPolicy(), null, function (int $seconds) use (&$sleepCalls): void {
			$sleepCalls[] = $seconds;
		},
			$script,
			$dispatchCalls
		) extends PipelinqContactAdapter {

			/**
			 * @var array<int, IResponse|\Throwable>
			 */
			private array $script;

			/**
			 * @var array<int, int>
			 */
			private array $dispatchCalls;

			/**
			 * @param IClientService $clientService Mock.
			 * @param IAppConfig $appConfig Mock.
			 * @param AbstractLogger $logger Recording.
			 * @param ICache $cache Mock.
			 * @param RetryPolicy $retryPolicy Policy.
			 * @param CircuitBreaker|null $breaker Optional breaker.
			 * @param \Closure $sleeper Stubbed sleeper.
			 * @param array<int, IResponse|\Throwable> $script Scripted outcomes.
			 * @param array<int, int> &$dispatchCalls Capture.
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
				array &$dispatchCalls,
			) {
				parent::__construct($clientService, $appConfig, $logger, $cache, $retryPolicy, $breaker, $sleeper);
				$this->script = $script;
				$this->dispatchCalls = & $dispatchCalls;
			}//end __construct()

			/**
			 * @param string $method HTTP method.
			 * @param string $url Full URL.
			 * @param array<string, mixed> $options Options.
			 *
			 * @return IResponse
			 */
			protected function dispatch(string $method, string $url, array $options): IResponse {
				$this->dispatchCalls[] = 1;
				$outcome = array_shift($this->script);
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				if ($outcome === null) {
					throw new \RuntimeException('script exhausted');
				}

				return $outcome;
			}//end dispatch()

			/**
			 * @param string $method HTTP method.
			 * @param string $path Path.
			 * @param array<string, mixed>|null $payload Payload.
			 *
			 * @return array<string, mixed>
			 */
			public function callRequest(string $method, string $path, ?array $payload = null): array {
				return $this->request($method, $path, $payload);
			}//end callRequest()
		};

		try {
			$adapter->callRequest('POST', '/contacts', ['foo' => 'bar']);
		} catch (PipelinqTransportException) {
			// Expected — we just want the side-effect log lines.
		}

		$haystack = json_encode($logger->records);
		self::assertNotFalse($haystack);
		self::assertStringNotContainsString('NEVER-LOG-ME-1234567890', $haystack, 'Bearer token must never reach the log');

	}//end testBearerTokenIsNeverLogged()
}//end class
