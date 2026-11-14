<?php

/**
 * Unit tests for CreditScoreService's cache window: a cached snapshot without a
 * readable score date is never fresh, and a refreshed snapshot is normalised
 * with the query's identity before it is stored.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
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

require_once __DIR__ . '/../InMemoryObjectService.php';

/**
 * @covers \OCA\Shillinq\Service\Dunning\CreditScoreService
 * @uses   \OCA\Shillinq\Service\Dunning\CreditScoreQuery
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class CreditScoreServiceFreshnessTest extends TestCase {

	/**
	 * A service over an in-memory object store, an empty register setting and a fetch adapter.
	 *
	 * @param InMemoryObjectService            $os    The object store.
	 * @param CreditScoreFetchAdapterInterface $fetch The fetch adapter.
	 *
	 * @return CreditScoreService
	 */
	private function service(InMemoryObjectService $os, CreditScoreFetchAdapterInterface $fetch): CreditScoreService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				// An empty register setting falls back to the default register.
				$values = ['register' => '', 'dunning.credit_score_cache_days' => '30'];
				return ($values[$key] ?? $default);
			}
		);

		return new CreditScoreService(container: $container, appConfig: $appConfig, logger: new NullLogger(), fetch: $fetch);
	}//end service()

	/**
	 * A cached snapshot with the given score date.
	 *
	 * @param string $scoreDate The score date.
	 *
	 * @return InMemoryObjectService
	 */
	private function cachedWith(string $scoreDate): InMemoryObjectService {
		$os = new InMemoryObjectService();
		$os->seed(
			schema: 'CreditScore',
			rows: [
				[
					'id' => 'cs-1',
					'administrationId' => 'adm-1',
					'customerId' => 'klant-1',
					'provider' => 'GRAYDON',
					'scoreDate' => $scoreDate,
					'score' => 5.0,
				],
			]
		);

		return $os;
	}//end cachedWith()

	/**
	 * A fetch adapter that must be asked exactly once and answers the given snapshot.
	 *
	 * @param array<string, mixed>|null $answer The snapshot it returns.
	 *
	 * @return CreditScoreFetchAdapterInterface
	 */
	private function fetchOnce(?array $answer): CreditScoreFetchAdapterInterface {
		$fetch = $this->createMock(CreditScoreFetchAdapterInterface::class);
		$fetch->expects($this->once())
			->method('fetch')
			->with('adm-1', 'klant-1', 'GRAYDON')
			->willReturn($answer);

		return $fetch;
	}//end fetchOnce()

	/**
	 * A cached snapshot without a score date is stale, so the provider is asked.
	 *
	 * @return void
	 */
	public function testAnUndatedSnapshotIsNotFresh(): void {
		$os = $this->cachedWith(scoreDate: '');

		$score = $this->service(os: $os, fetch: $this->fetchOnce(answer: null))
			->getOrRefresh(administrationId: 'adm-1', customerId: 'klant-1', provider: 'GRAYDON');

		self::assertSame('cs-1', $score['id']);
		self::assertCount(1, $os->dump(schema: 'CreditScore'));
	}//end testAnUndatedSnapshotIsNotFresh()

	/**
	 * A cached snapshot with an unreadable score date is stale, so the provider is asked.
	 *
	 * @return void
	 */
	public function testAnUnreadableScoreDateIsNotFresh(): void {
		$os = $this->cachedWith(scoreDate: 'not-a-date');

		$score = $this->service(os: $os, fetch: $this->fetchOnce(answer: null))
			->getOrRefresh(administrationId: 'adm-1', customerId: 'klant-1', provider: 'GRAYDON');

		self::assertSame('cs-1', $score['id']);
	}//end testAnUnreadableScoreDateIsNotFresh()

	/**
	 * A refreshed snapshot keeps its own identity fields and dates itself when the provider gave no date.
	 *
	 * @return void
	 */
	public function testARefreshedSnapshotIsNormalisedAndStored(): void {
		$os = $this->cachedWith(scoreDate: 'not-a-date');

		$score = $this->service(
			os: $os,
			fetch: $this->fetchOnce(answer: ['score' => 8.0, 'provider' => 'CREDITSAFE', 'scoreDate' => ''])
		)->getOrRefresh(administrationId: 'adm-1', customerId: 'klant-1', provider: 'GRAYDON');

		self::assertSame('adm-1', $score['administrationId']);
		self::assertSame('klant-1', $score['customerId']);
		self::assertSame('CREDITSAFE', $score['provider']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $score['scoreDate']);
		self::assertCount(2, $os->dump(schema: 'CreditScore'));
	}//end testARefreshedSnapshotIsNormalisedAndStored()
}//end class
