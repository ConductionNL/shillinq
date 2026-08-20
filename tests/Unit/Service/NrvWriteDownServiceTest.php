<?php

/**
 * Unit tests for NrvWriteDownService.
 *
 * Covers lower-of-cost-or-NRV (RJ 220 / IAS 2.9): a balanced write-down
 * posting when NRV < cost, and a strict no-op (never writing inventory up)
 * when NRV >= cost.
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
 * @spec openspec/changes/inventory-accounting-correctness/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InventoryGlAdjustmentPoster;
use OCA\Shillinq\Service\NrvWriteDownService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * NrvWriteDownService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class NrvWriteDownServiceTest extends TestCase {
	/**
	 * Build the service + shared poster wired to an in-memory ObjectService.
	 *
	 * @param InMemoryObjectService $os The stub.
	 *
	 * @return NrvWriteDownService
	 */
	private function makeService(InMemoryObjectService $os): NrvWriteDownService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === 'register') {
					return 'shillinq';
				}

				return $default;
			}
		);

		$logger = $this->createStub(LoggerInterface::class);

		$poster = new InventoryGlAdjustmentPoster(
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

		return new NrvWriteDownService(
			appConfig: $appConfig,
			poster: $poster,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeService()

	/**
	 * Build a cost-basis valuation snapshot.
	 *
	 * @param float $unitCost Cost per unit.
	 * @param float $quantity On-hand quantity.
	 *
	 * @return array<string,mixed>
	 */
	private function valuation(float $unitCost, float $quantity): array {
		return [
			'id' => 'iv-1',
			'productId' => 'P1',
			'warehouse' => 'WH-A',
			'status' => 'active',
			'quantity' => $quantity,
			'unitCost' => $unitCost,
			'totalValue' => ($quantity * $unitCost),
			'valuationMethod' => 'FIFO',
			'administrationId' => 'adm-1',
		];

	}//end valuation()

	/**
	 * NRV below cost: writes down by (cost - NRV) * qty and posts a balanced
	 * transaction (debit write-down expense, credit inventory).
	 *
	 * @return void
	 */
	public function testWriteDownPostsWhenNrvBelowCost(): void {
		$os = new InMemoryObjectService();
		$service = $this->makeService(os: $os);

		// 100 units @ cost 10,00, NRV 7,00 -> write-down 3,00 * 100 = 300,00 = 30000 cents.
		$result = $service->writeDown(
			valuation: $this->valuation(unitCost: 10.0, quantity: 100.0),
			nrvPerUnit: 7.0,
			periodId: '2026-Q4'
		);

		self::assertTrue($result['posted']);
		self::assertSame(30000, $result['writeDownCents']);

		// Balanced posting.
		$posting = $result['posting'];
		self::assertTrue($posting['balanced']);
		self::assertSame(30000, $posting['debitCents']);
		self::assertSame(30000, $posting['creditCents']);

		$glLines = $os->dump(schema: 'GLLine');
		self::assertCount(2, $glLines);
		$debit = array_values(array_filter($glLines, static fn (array $l): bool => $l['side'] === 'debit'));
		$credit = array_values(array_filter($glLines, static fn (array $l): bool => $l['side'] === 'credit'));
		self::assertSame('7050', $debit[0]['accountNumber']);
		self::assertSame(300.0, $debit[0]['amount']);
		self::assertSame('1300', $credit[0]['accountNumber']);
		self::assertSame(300.0, $credit[0]['amount']);

		// Snapshot re-marked to NRV.
		$valuation = $result['valuation'];
		self::assertSame(7.0, (float)$valuation['unitCost']);
		self::assertSame(700.0, (float)$valuation['totalValue']);
		self::assertSame('adjusted', $valuation['status']);

	}//end testWriteDownPostsWhenNrvBelowCost()

	/**
	 * NRV above cost: strict no-op — the lower-of-cost-or-NRV rule never
	 * writes inventory up. No posting, no snapshot change.
	 *
	 * @return void
	 */
	public function testNoWriteDownWhenNrvAboveCost(): void {
		$os = new InMemoryObjectService();
		$service = $this->makeService(os: $os);

		$result = $service->writeDown(
			valuation: $this->valuation(unitCost: 10.0, quantity: 100.0),
			nrvPerUnit: 12.0,
			periodId: '2026-Q4'
		);

		self::assertFalse($result['posted']);
		self::assertSame(0, $result['writeDownCents']);
		self::assertSame([], $os->dump(schema: 'GLTransaction'));
		self::assertSame([], $os->dump(schema: 'GLLine'));

	}//end testNoWriteDownWhenNrvAboveCost()

	/**
	 * NRV exactly equal to cost: also a no-op (>= cost).
	 *
	 * @return void
	 */
	public function testNoWriteDownWhenNrvEqualsCost(): void {
		$os = new InMemoryObjectService();
		$service = $this->makeService(os: $os);

		$result = $service->writeDown(
			valuation: $this->valuation(unitCost: 10.0, quantity: 100.0),
			nrvPerUnit: 10.0,
			periodId: '2026-Q4'
		);

		self::assertFalse($result['posted']);
		self::assertSame(0, $result['writeDownCents']);

	}//end testNoWriteDownWhenNrvEqualsCost()

	/**
	 * Batch run over an administration writes down only the SKUs whose NRV is
	 * below cost, aggregating the total.
	 *
	 * @return void
	 */
	public function testRunForAdministrationWritesDownOnlyBelowCost(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			schema: 'InventoryValuation',
			rows: [
				[
					'id' => 'iv-1',
					'productId' => 'P1',
					'warehouse' => 'WH-A',
					'status' => 'active',
					'quantity' => 100.0,
					'unitCost' => 10.0,
					'totalValue' => 1000.0,
					'valuationMethod' => 'FIFO',
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'iv-2',
					'productId' => 'P2',
					'warehouse' => 'WH-A',
					'status' => 'active',
					'quantity' => 50.0,
					'unitCost' => 8.0,
					'totalValue' => 400.0,
					'valuationMethod' => 'FIFO',
					'administrationId' => 'adm-1',
				],
			]
		);

		$service = $this->makeService(os: $os);

		// P1 NRV 7,00 < 10,00 -> write down 300,00. P2 NRV 9,00 >= 8,00 -> skip.
		$result = $service->runForAdministration(
			administrationId: 'adm-1',
			periodId: '2026-Q4',
			nrvBySku: [
				'P1' => 7.0,
				'P2' => 9.0,
			]
		);

		self::assertSame(1, $result['writeDownCount']);
		self::assertSame(30000, $result['totalWriteDownCents']);

	}//end testRunForAdministrationWritesDownOnlyBelowCost()
}//end class
