<?php

/**
 * Unit tests for CreditScoreService.
 *
 * Covers cache-vs-fetch decision, fresh-snapshot persistence, evaluateForInvoice
 * warning shape, and the fallback path when the fetch adapter returns null /
 * throws. Hermetic — no NC bootstrap, no OR runtime.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\CreditScoreFetchAdapterInterface;
use OCA\Shillinq\Service\Dunning\CreditScoreService;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__ . '/../InMemoryObjectService.php';

/**
 * CreditScoreService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CreditScoreServiceTest extends TestCase {
	/**
	 * Build a service wired against an in-memory OR + an arbitrary fetch adapter.
	 *
	 * @param InMemoryObjectService $os Stub OR.
	 * @param CreditScoreFetchAdapterInterface $fetch Fetch adapter (test-controlled).
	 *
	 * @return CreditScoreService
	 */
	private function makeService(
		InMemoryObjectService $os,
		CreditScoreFetchAdapterInterface $fetch,
	): CreditScoreService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'shillinq',
					'dunning.credit_score_cache_days' => '30',
					'dunning.credit_score_warning_threshold' => '3.0',
				];
				return $values[$key] ?? $default;
			}
		);

		return new CreditScoreService(
			container: $container,
			appConfig: $appConfig,
			logger: new NullLogger(),
			fetch: $fetch,
		);

	}//end makeService()

	/**
	 * Task-19: fresh cache short-circuits the fetch path.
	 *
	 * @return void
	 */
	public function testFreshCacheShortCircuitsFetch(): void {
		$os = new InMemoryObjectService();
		$os->seed(schema: 'CreditScore', rows: [
			[
				'id' => 'cs-1',
				'administrationId' => 'adm-1',
				'customerId' => 'klant-1',
				'provider' => 'GRAYDON',
				'scoreDate' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d'),
				'score' => 6.4,
				'scoreScale' => '1-10',
			],
		]);
		$fetch = $this->makeNeverFetch();
		$service = $this->makeService(os: $os, fetch: $fetch);

		$score = $service->getOrRefresh(administrationId: 'adm-1', customerId: 'klant-1', provider: 'GRAYDON');

		self::assertNotNull($score);
		self::assertSame(6.4, (float)$score['score']);
		self::assertCount(1, $os->dump(schema: 'CreditScore'), 'no new snapshot persisted');

	}//end testFreshCacheShortCircuitsFetch()

	/**
	 * Task-19: stale cache triggers a fetch; a returned snapshot is persisted.
	 *
	 * @return void
	 */
	public function testStaleCacheTriggersFetchAndPersists(): void {
		$os = new InMemoryObjectService();
		$os->seed(schema: 'CreditScore', rows: [
			[
				'id' => 'cs-old',
				'administrationId' => 'adm-1',
				'customerId' => 'klant-1',
				'provider' => 'GRAYDON',
				'scoreDate' => (new \DateTimeImmutable('-100 days'))->format('Y-m-d'),
				'score' => 2.0,
				'scoreScale' => '1-10',
			],
		]);
		$fetch = new class implements CreditScoreFetchAdapterInterface {
			public function fetch(string $administrationId, string $customerId, string $provider): ?array {
				return [
					'score' => 7.1,
					'scoreScale' => '1-10',
					'paymentRiskIndication' => 'LOW',
				];
			}
		};
		$service = $this->makeService(os: $os, fetch: $fetch);

		$score = $service->getOrRefresh(administrationId: 'adm-1', customerId: 'klant-1', provider: 'GRAYDON');

		self::assertNotNull($score);
		self::assertSame(7.1, (float)$score['score']);
		self::assertSame('adm-1', $score['administrationId']);
		self::assertSame('GRAYDON', $score['provider']);
		self::assertSame(2, count($os->dump(schema: 'CreditScore')), 'fresh snapshot persisted');

	}//end testStaleCacheTriggersFetchAndPersists()

	/**
	 * Task-19: when the fetch adapter returns null, the stale cached snapshot
	 * is still surfaced (no UI blank-out).
	 *
	 * @return void
	 */
	public function testFetchNullFallsBackToCachedSnapshot(): void {
		$os = new InMemoryObjectService();
		$os->seed(schema: 'CreditScore', rows: [
			[
				'id' => 'cs-old',
				'administrationId' => 'adm-1',
				'customerId' => 'klant-1',
				'provider' => 'GRAYDON',
				'scoreDate' => (new \DateTimeImmutable('-100 days'))->format('Y-m-d'),
				'score' => 2.5,
				'scoreScale' => '1-10',
			],
		]);
		$fetch = new class implements CreditScoreFetchAdapterInterface {
			public function fetch(string $administrationId, string $customerId, string $provider): ?array {
				return null;
			}
		};
		$service = $this->makeService(os: $os, fetch: $fetch);

		$score = $service->getOrRefresh(administrationId: 'adm-1', customerId: 'klant-1', provider: 'GRAYDON');

		self::assertNotNull($score);
		self::assertSame(2.5, (float)$score['score']);
		self::assertCount(1, $os->dump(schema: 'CreditScore'), 'no new snapshot persisted on fetch null');

	}//end testFetchNullFallsBackToCachedSnapshot()

	/**
	 * Task-19: a throwing fetch adapter falls back to cached snapshot (fail-soft).
	 *
	 * @return void
	 */
	public function testFetchThrowFallsBackToCache(): void {
		$os = new InMemoryObjectService();
		$os->seed(schema: 'CreditScore', rows: [
			[
				'id' => 'cs-old',
				'administrationId' => 'adm-1',
				'customerId' => 'klant-1',
				'provider' => 'GRAYDON',
				'scoreDate' => (new \DateTimeImmutable('-100 days'))->format('Y-m-d'),
				'score' => 4.0,
				'scoreScale' => '1-10',
			],
		]);
		$fetch = new class implements CreditScoreFetchAdapterInterface {
			public function fetch(string $administrationId, string $customerId, string $provider): ?array {
				throw new RuntimeException('upstream unavailable');
			}
		};
		$service = $this->makeService(os: $os, fetch: $fetch);

		$score = $service->getOrRefresh(administrationId: 'adm-1', customerId: 'klant-1', provider: 'GRAYDON');

		self::assertNotNull($score);
		self::assertSame(4.0, (float)$score['score']);

	}//end testFetchThrowFallsBackToCache()

	/**
	 * Task-19: evaluateForInvoice flags a low score below threshold.
	 *
	 * @return void
	 */
	public function testEvaluateForInvoiceFlagsLowScore(): void {
		$service = $this->makeService(os: new InMemoryObjectService(), fetch: $this->makeNeverFetch());

		$score = [
			'customerId' => 'klant-1',
			'score' => 2.4,
			'scoreScale' => '1-10',
			'creditLimitAdvice' => 5000.0,
		];

		$result = $service->evaluateForInvoice(score: $score, invoiceAmount: 12000.0);

		self::assertTrue($result['warning']);
		self::assertTrue($result['deelfacturatieAdvies']);
		self::assertSame(5000.0, $result['creditLimitAdvice']);
		self::assertStringContainsString('klant-1', $result['message']);

	}//end testEvaluateForInvoiceFlagsLowScore()

	/**
	 * Task-19: evaluateForInvoice stays silent for a healthy score under the limit.
	 *
	 * @return void
	 */
	public function testEvaluateForInvoiceQuietForHealthyScore(): void {
		$service = $this->makeService(os: new InMemoryObjectService(), fetch: $this->makeNeverFetch());

		$score = [
			'customerId' => 'klant-1',
			'score' => 8.0,
			'scoreScale' => '1-10',
			'creditLimitAdvice' => 50000.0,
		];

		$result = $service->evaluateForInvoice(score: $score, invoiceAmount: 1500.0);

		self::assertFalse($result['warning']);
		self::assertFalse($result['deelfacturatieAdvies']);

	}//end testEvaluateForInvoiceQuietForHealthyScore()

	/**
	 * Build a fetch adapter the tests can assert never gets called.
	 *
	 * @return CreditScoreFetchAdapterInterface
	 */
	private function makeNeverFetch(): CreditScoreFetchAdapterInterface {
		return new class implements CreditScoreFetchAdapterInterface {
			public function fetch(string $administrationId, string $customerId, string $provider): ?array {
				throw new RuntimeException('fetch must not be called in this scenario');
			}
		};

	}//end makeNeverFetch()

}//end class
