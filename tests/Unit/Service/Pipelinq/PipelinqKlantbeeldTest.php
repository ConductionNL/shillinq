<?php

/**
 * Unit tests for the PipelinqContactAdapter klantbeeld read path.
 *
 * Slice 04 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Exercises {@see PipelinqContactAdapter::fetchKlantbeeld()} against the
 * three legitimate outcomes the spec defines:
 *
 *   1. Available + non-empty — page of {@see KlantbeeldTransaction} rows.
 *   2. Available + empty — empty array, no error logged.
 *   3. Unavailable — klantbeeld 5xx exhausts the retry budget while
 *      Contact succeeded; the envelope carries the "unavailable" marker
 *      so the UI can keep the profile rendered.
 *
 * Also covers limit/offset clamping (default 5, max 100, ≥0) and the
 * pagination behaviour (offset advances the window).
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\Service\Pipelinq\CircuitBreaker;
use OCA\Shillinq\Service\Pipelinq\KlantbeeldResult;
use OCA\Shillinq\Service\Pipelinq\KlantbeeldTransaction;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\RetryPolicy;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Verifies the klantbeeld read path: success / empty / pagination /
 * unavailable / Contact-still-usable.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
 */
final class PipelinqKlantbeeldTest extends TestCase {
	/**
	 * Build a klantbeeld-aware adapter with scripted HTTP responses.
	 *
	 * @param array<int, IResponse|\Throwable> $script Scripted dispatch outcomes.
	 * @param array<int, string> &$dispatchUrls Captured URLs (for offset assertion).
	 * @param array<int, int> &$sleepCalls Captured sleep durations.
	 * @param CircuitBreaker|null $breaker Optional shared breaker.
	 * @param AbstractLogger|null $logger Recording logger (created when null).
	 *
	 * @return PipelinqContactAdapter
	 */
	private function buildAdapter(
		array $script,
		array &$dispatchUrls,
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
					return 'super-secret-token';
				}

				return $default;
			}
		);

		if ($logger === null) {
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
		}

		$clientService = $this->createMock(IClientService::class);
		$cache = $this->createMock(ICache::class);

		$sleeper = function (int $seconds) use (&$sleepCalls): void {
			$sleepCalls[] = $seconds;
		};

		return new class($clientService, $appConfig, $logger, $cache, new RetryPolicy(), $breaker, $sleeper, $script, $dispatchUrls) extends PipelinqContactAdapter {
			/**
			 * @var array<int, IResponse|\Throwable>
			 */
			private array $script;

			/**
			 * @var array<int, string>
			 */
			private array $dispatchUrls;

			/**
			 * @param IClientService $clientService Mock client service.
			 * @param IAppConfig $appConfig Mock config.
			 * @param AbstractLogger $logger Recording logger.
			 * @param ICache $cache Mock cache.
			 * @param RetryPolicy $retryPolicy Policy.
			 * @param CircuitBreaker|null $breaker Optional shared breaker.
			 * @param \Closure $sleeper Stubbed sleeper.
			 * @param array<int, IResponse|\Throwable> $script Scripted outcomes.
			 * @param array<int, string> &$dispatchUrls Captured URLs.
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
				array &$dispatchUrls,
			) {
				parent::__construct($clientService, $appConfig, $logger, $cache, $retryPolicy, $breaker, $sleeper);
				$this->script = $script;
				$this->dispatchUrls = & $dispatchUrls;
			}//end __construct()

			/**
			 * @param string $method HTTP method.
			 * @param string $url Full URL.
			 * @param array<string, mixed> $options Options.
			 *
			 * @return IResponse
			 */
			protected function dispatch(string $method, string $url, array $options): IResponse {
				$this->dispatchUrls[] = $url;
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
	 * Klantbeeld success: a 200 response with transactions returns an
	 * available envelope carrying rows mapped to value objects.
	 *
	 * @return void
	 */
	public function testKlantbeeldLoadedSuccessfully(): void {
		$dispatchUrls = [];
		$sleepCalls = [];
		$script = [
			$this->response(
				200,
				[
					'transactions' => [
						[
							'date' => '2026-06-05',
							'description' => 'Invoice INV-2026-001',
							'amount' => 1250.50,
							'currency' => 'EUR',
							'status' => 'paid',
						],
						[
							'date' => '2026-05-28',
							'description' => 'Invoice INV-2026-002',
							'amount' => 845.00,
							'currency' => 'EUR',
							'status' => 'pending',
						],
					],
				]
			),
		];

		$adapter = $this->buildAdapter($script, $dispatchUrls, $sleepCalls);
		$result = $adapter->fetchKlantbeeld('abc-123');

		self::assertInstanceOf(KlantbeeldResult::class, $result);
		self::assertFalse($result->isUnavailable());
		self::assertFalse($result->isEmpty());
		self::assertCount(2, $result->transactions);
		self::assertSame(5, $result->limit, 'Default limit is 5');
		self::assertSame(0, $result->offset, 'Default offset is 0');

		$first = $result->transactions[0];
		self::assertInstanceOf(KlantbeeldTransaction::class, $first);
		self::assertSame('2026-06-05', $first->date);
		self::assertSame('Invoice INV-2026-001', $first->description);
		self::assertSame(1250.50, $first->amount);
		self::assertSame('EUR', $first->currency);
		self::assertSame('paid', $first->status);

		// Dispatch URL must carry the klantbeeld path + limit/offset query.
		self::assertCount(1, $dispatchUrls);
		self::assertStringContainsString('/api/v1/contacts/abc-123/klantbeeld', $dispatchUrls[0]);
		self::assertStringContainsString('limit=5', $dispatchUrls[0]);
		self::assertStringContainsString('offset=0', $dispatchUrls[0]);

	}//end testKlantbeeldLoadedSuccessfully()

	/**
	 * Empty `transactions` array is a valid result, not an error.
	 *
	 * @return void
	 */
	public function testEmptyKlantbeeldIsValid(): void {
		$dispatchUrls = [];
		$sleepCalls = [];

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

		$script = [$this->response(200, ['transactions' => []])];

		$adapter = $this->buildAdapter($script, $dispatchUrls, $sleepCalls, null, $logger);
		$result = $adapter->fetchKlantbeeld('abc-123');

		self::assertFalse($result->isUnavailable());
		self::assertTrue($result->isEmpty());
		self::assertSame([], $result->transactions);

		// No WARNING / ERROR for an empty result.
		$bad = array_filter(
			$logger->records,
			static fn (array $r): bool => in_array($r['level'], ['warning', 'error', 'critical', 'alert', 'emergency'], true)
		);
		self::assertSame([], $bad, 'Empty transactions must not log a warning');

	}//end testEmptyKlantbeeldIsValid()

	/**
	 * Pagination: a different offset hits the backend with the requested
	 * window and the envelope echoes it back.
	 *
	 * @return void
	 */
	public function testKlantbeeldPaginationOffsetAdvancesWindow(): void {
		$dispatchUrls = [];
		$sleepCalls = [];

		$script = [
			$this->response(
				200,
				[
					'transactions' => [
						[
							'date' => '2026-04-01',
							'description' => 'Invoice INV-2025-011',
							'amount' => 50.0,
							'currency' => 'EUR',
							'status' => 'paid',
						],
					],
				]
			),
		];

		$adapter = $this->buildAdapter($script, $dispatchUrls, $sleepCalls);
		$result = $adapter->fetchKlantbeeld('abc-123', 5, 5);

		self::assertFalse($result->isUnavailable());
		self::assertSame(5, $result->limit);
		self::assertSame(5, $result->offset);
		self::assertCount(1, $result->transactions);

		self::assertCount(1, $dispatchUrls);
		self::assertStringContainsString('limit=5', $dispatchUrls[0]);
		self::assertStringContainsString('offset=5', $dispatchUrls[0]);

	}//end testKlantbeeldPaginationOffsetAdvancesWindow()

	/**
	 * Klantbeeld 5xx exhausts retries while Contact remained available;
	 * the adapter MUST return an unavailable marker (NOT throw), and the
	 * profile (handled in slice 03/05) stays unaffected.
	 *
	 * @return void
	 */
	public function testKlantbeeldUnavailableWhileContactAvailable(): void {
		$dispatchUrls = [];
		$sleepCalls = [];

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

		// 3 attempts, all 502; the retry policy gives up + the adapter
		// converts the transport exception into a klantbeeld-specific
		// "unavailable" envelope.
		$script = [
			$this->response(502),
			$this->response(502),
			$this->response(502),
		];

		$adapter = $this->buildAdapter($script, $dispatchUrls, $sleepCalls, null, $logger);
		$result = $adapter->fetchKlantbeeld('abc-123', 5, 0);

		self::assertTrue($result->isUnavailable(), 'Result must mark klantbeeld as unavailable');
		self::assertSame([], $result->transactions);
		self::assertSame(5, $result->limit);
		self::assertSame(0, $result->offset);

		// A WARNING is logged so observability picks the outage up.
		$warnings = array_filter(
			$logger->records,
			static fn (array $r): bool => $r['level'] === 'warning' && str_contains((string)$r['message'], 'klantbeeld')
		);
		self::assertNotSame([], $warnings, 'Adapter must log a klantbeeld-specific warning');

	}//end testKlantbeeldUnavailableWhileContactAvailable()

	/**
	 * Klantbeeld GET that times out (network-layer exception) is treated as
	 * an unavailable result.
	 *
	 * @return void
	 */
	public function testKlantbeeldTimeoutIsUnavailable(): void {
		$dispatchUrls = [];
		$sleepCalls = [];

		// Three transport-layer exceptions exhaust the retry budget.
		$script = [
			new \RuntimeException('connect timed out'),
			new \RuntimeException('connect timed out'),
			new \RuntimeException('connect timed out'),
		];

		$adapter = $this->buildAdapter($script, $dispatchUrls, $sleepCalls);
		$result = $adapter->fetchKlantbeeld('abc-123');

		self::assertTrue($result->isUnavailable());
		self::assertCount(3, $dispatchUrls, 'All 3 retries must be attempted');

	}//end testKlantbeeldTimeoutIsUnavailable()

	/**
	 * 404 on klantbeeld means the contact has no klantbeeld available;
	 * surface as an empty available page (not as a hard "missing"). The
	 * canonical "contact missing" answer belongs to slice 03's profile
	 * fetch.
	 *
	 * @return void
	 */
	public function testKlantbeeldNotFoundReturnsEmptyAvailable(): void {
		$dispatchUrls = [];
		$sleepCalls = [];
		$script = [$this->response(404)];

		$adapter = $this->buildAdapter($script, $dispatchUrls, $sleepCalls);
		$result = $adapter->fetchKlantbeeld('does-not-exist');

		self::assertFalse($result->isUnavailable());
		self::assertTrue($result->isEmpty());
		self::assertSame([], $result->transactions);
		self::assertCount(1, $dispatchUrls, '404 is not retried');

	}//end testKlantbeeldNotFoundReturnsEmptyAvailable()

	/**
	 * The page-size argument is clamped to [1, 100] before the request is
	 * issued; the dispatched URL never carries a limit outside the spec
	 * range.
	 *
	 * @return void
	 */
	public function testKlantbeeldLimitIsClamped(): void {
		$dispatchUrls = [];
		$sleepCalls = [];
		$script = [
			$this->response(200, ['transactions' => []]),
			$this->response(200, ['transactions' => []]),
			$this->response(200, ['transactions' => []]),
		];

		$adapter = $this->buildAdapter($script, $dispatchUrls, $sleepCalls);

		// limit=0 → default 5.
		$r1 = $adapter->fetchKlantbeeld('abc-123', 0, 0);
		self::assertSame(5, $r1->limit);
		self::assertStringContainsString('limit=5', $dispatchUrls[0]);

		// limit=500 → capped at 100.
		$r2 = $adapter->fetchKlantbeeld('abc-123', 500, 0);
		self::assertSame(100, $r2->limit);
		self::assertStringContainsString('limit=100', $dispatchUrls[1]);

		// Negative offset → 0.
		$r3 = $adapter->fetchKlantbeeld('abc-123', 5, -10);
		self::assertSame(0, $r3->offset);
		self::assertStringContainsString('offset=0', $dispatchUrls[2]);

	}//end testKlantbeeldLimitIsClamped()

	/**
	 * A 200 response with a missing or malformed `transactions` key is
	 * treated as unavailable (and logged).
	 *
	 * @return void
	 */
	public function testKlantbeeldMalformedBodyIsUnavailable(): void {
		$dispatchUrls = [];
		$sleepCalls = [];

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

		$script = [$this->response(200, ['transactions' => 'not-an-array'])];
		$adapter = $this->buildAdapter($script, $dispatchUrls, $sleepCalls, null, $logger);
		$result = $adapter->fetchKlantbeeld('abc-123');

		self::assertTrue($result->isUnavailable());
		$warnings = array_filter(
			$logger->records,
			static fn (array $r): bool => $r['level'] === 'warning'
		);
		self::assertNotSame([], $warnings);

	}//end testKlantbeeldMalformedBodyIsUnavailable()

	/**
	 * A row with missing optional fields still produces a valid Transaction
	 * (defaults to empty string / 0.0) — partial rows must not abort the page.
	 *
	 * @return void
	 */
	public function testKlantbeeldPartialRowGetsDefaults(): void {
		$dispatchUrls = [];
		$sleepCalls = [];
		$script = [
			$this->response(
				200,
				[
					'transactions' => [
						['date' => '2026-06-05'],
						['amount' => '99.99'],
					],
				]
			),
		];

		$adapter = $this->buildAdapter($script, $dispatchUrls, $sleepCalls);
		$result = $adapter->fetchKlantbeeld('abc-123');

		self::assertFalse($result->isUnavailable());
		self::assertCount(2, $result->transactions);

		self::assertSame('2026-06-05', $result->transactions[0]->date);
		self::assertSame('', $result->transactions[0]->description);
		self::assertSame(0.0, $result->transactions[0]->amount);

		// Numeric-string amount is coerced to float.
		self::assertSame(99.99, $result->transactions[1]->amount);

	}//end testKlantbeeldPartialRowGetsDefaults()
}//end class
