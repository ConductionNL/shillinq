<?php

/**
 * Unit tests for CogsPosterService.
 *
 * Covers REQ-INV-007:
 *  - balanced GLTransaction posted when accounts configured
 *  - WARNING + status=adjusted + pendingCogs=true when accounts missing
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * CogsPosterService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CogsPosterServiceTest extends TestCase {

	/**
	 * Build a service with custom appConfig values.
	 *
	 * @param InMemoryObjectService $os The stub.
	 * @param array<string,string> $config Config overrides.
	 * @param LoggerInterface|null $logger Optional logger to assert.
	 *
	 * @return CogsPosterService
	 */
	private function makeService(
		InMemoryObjectService $os,
		array $config = [],
		?LoggerInterface $logger = null,
	): CogsPosterService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		$logger ??= $this->createStub(LoggerInterface::class);

		return new CogsPosterService(
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeService()

	/**
	 * REQ-INV-007 happy path: configured accounts produce a balanced
	 * 2-line GLTransaction.
	 *
	 * @return void
	 */
	public function testBalancedTransactionWhenAccountsConfigured(): void {
		$os = new InMemoryObjectService();
		$service = $this->makeService(
			os: $os,
			config: [
				'register' => 'shillinq',
				'cogs_account' => '5100',
				'inventory_account' => '1300',
			]
		);

		$move = [
			'id' => 'mv-1',
			'movementNumber' => 'SM-2026-0010',
			'itemId' => 'HP-200-B',
			'quantity' => 5.0,
			'movementType' => 'issue',
			'sourceLocationId' => 'Magazijn Zuid',
			'postedAt' => '2026-06-01T09:00:00Z',
			'administrationId' => 'adm-1',
		];
		$valuation = [
			'id' => 'iv-1',
			'productId' => 'HP-200-B',
			'warehouse' => 'Magazijn Zuid',
			'status' => 'active',
			'pendingCogs' => false,
			'valuationMethod' => 'FIFO',
		];

		// 5 * 89.00 = 445.00 -> 44500 cents.
		$result = $service->postCogs(move: $move, valuation: $valuation, cogsCents: 44500);

		self::assertTrue($result['posted']);
		self::assertSame(44500, $result['cogsCents']);

		$txnRows = $os->setSchema('GLTransaction')->findAll();
		self::assertCount(1, $txnRows);
		self::assertSame('mv-1', $txnRows[0]['sourceReference']);
		self::assertSame('EUR', $txnRows[0]['currency']);

		$lines = $os->setSchema('GLLine')->findAll();
		self::assertCount(2, $lines);

		$debits = array_filter($lines, static fn (array $l): bool => $l['side'] === 'debit');
		$credits = array_filter($lines, static fn (array $l): bool => $l['side'] === 'credit');
		self::assertCount(1, $debits);
		self::assertCount(1, $credits);

		$debit = array_values($debits)[0];
		$credit = array_values($credits)[0];

		self::assertSame('5100', $debit['accountNumber']);
		self::assertSame(445.0, (float)$debit['amount']);
		self::assertSame('1300', $credit['accountNumber']);
		self::assertSame(445.0, (float)$credit['amount']);

	}//end testBalancedTransactionWhenAccountsConfigured()

	/**
	 * REQ-INV-007 fail-soft: when accounts are not configured, no
	 * transaction is posted, a WARNING is logged, and the snapshot is
	 * set to status=adjusted + pendingCogs=true.
	 *
	 * @return void
	 */
	public function testMissingAccountsAdjustsValuationAndWarns(): void {
		$os = new InMemoryObjectService();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('GL accounts not configured'), $this->anything());

		$service = $this->makeService(
			os: $os,
			config: ['register' => 'shillinq'],
			logger: $logger
		);

		$move = [
			'id' => 'mv-1',
			'movementNumber' => 'SM-2026-0010',
			'itemId' => 'HP-200-B',
			'quantity' => 5.0,
			'movementType' => 'issue',
			'sourceLocationId' => 'Magazijn Zuid',
			'postedAt' => '2026-06-01T09:00:00Z',
			'administrationId' => 'adm-1',
		];
		$valuation = [
			'id' => 'iv-1',
			'productId' => 'HP-200-B',
			'warehouse' => 'Magazijn Zuid',
			'status' => 'active',
			'pendingCogs' => false,
			'valuationMethod' => 'FIFO',
		];

		$result = $service->postCogs(move: $move, valuation: $valuation, cogsCents: 44500);

		self::assertFalse($result['posted']);
		self::assertSame('adjusted', $result['valuation']['status']);
		self::assertTrue($result['valuation']['pendingCogs']);

		// No GL rows posted.
		self::assertCount(0, $os->setSchema('GLTransaction')->findAll());
		self::assertCount(0, $os->setSchema('GLLine')->findAll());

	}//end testMissingAccountsAdjustsValuationAndWarns()

	/**
	 * Zero / negative COGS amount is a no-op (no GL posting).
	 *
	 * @return void
	 */
	public function testZeroCogsIsNoop(): void {
		$os = new InMemoryObjectService();
		$service = $this->makeService(
			os: $os,
			config: [
				'cogs_account' => '5100',
				'inventory_account' => '1300',
			]
		);

		$result = $service->postCogs(move: ['id' => 'mv-1'], valuation: ['id' => 'iv-1'], cogsCents: 0);
		self::assertFalse($result['posted']);
		self::assertCount(0, $os->setSchema('GLTransaction')->findAll());

	}//end testZeroCogsIsNoop()

}//end class
