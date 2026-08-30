<?php

/**
 * Unit tests for LandedCostAllocationService.
 *
 * Covers pro-rata allocation of a receipt's landed cost across its lines
 * into the per-line unit cost, and the balanced capitalisation posting
 * (debit Inventory / credit landed-cost clearing).
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
use OCA\Shillinq\Service\LandedCostAllocationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * LandedCostAllocationService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LandedCostAllocationServiceTest extends TestCase {
	/**
	 * Build the service + shared poster wired to an in-memory ObjectService.
	 *
	 * @param InMemoryObjectService $os The stub.
	 *
	 * @return LandedCostAllocationService
	 */
	private function makeService(InMemoryObjectService $os): LandedCostAllocationService {
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

		return new LandedCostAllocationService(
			appConfig: $appConfig,
			poster: $poster,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeService()

	/**
	 * Two receipt lines (300,00 + 240,00 = 540,00) with a 54,00 landed cost
	 * allocate 30,00 / 24,00 by value, yielding unit costs 11,00 and 13,20,
	 * and post ONE balanced GLTransaction of 54,00.
	 *
	 * @return void
	 */
	public function testValueBasisAllocationAndBalancedPosting(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			schema: 'StockMove',
			rows: [
				[
					'id' => 'mv-a',
					'itemId' => 'GT-10',
					'movementType' => 'receipt',
					'referenceDocumentUri' => 'PO-2026-001',
					'destinationLocationId' => 'WH-A',
					'quantity' => 30.0,
					'unitCost' => 10.0,
					'lifecycleState' => 'posted',
					'postedAt' => '2026-04-01T09:00:00Z',
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'mv-b',
					'itemId' => 'GT-20',
					'movementType' => 'receipt',
					'referenceDocumentUri' => 'PO-2026-001',
					'destinationLocationId' => 'WH-A',
					'quantity' => 20.0,
					'unitCost' => 12.0,
					'lifecycleState' => 'posted',
					'postedAt' => '2026-04-01T09:00:00Z',
					'administrationId' => 'adm-1',
				],
			]
		);

		$service = $this->makeService(os: $os);

		// Landed cost EUR 54,00 = 5400 cents.
		$result = $service->allocate(
			administrationId: 'adm-1',
			receiptReference: 'PO-2026-001',
			landedCostCents: 5400,
			basis: 'value'
		);

		self::assertTrue($result['allocated']);
		self::assertSame(5400, $result['totalAllocatedCents']);

		$lines = $result['lines'];
		self::assertCount(2, $lines);
		self::assertSame(3000, $lines[0]['allocatedCents']);
		self::assertSame(11.0, $lines[0]['landedUnitCost']);
		self::assertSame(2400, $lines[1]['allocatedCents']);
		self::assertSame(13.2, $lines[1]['landedUnitCost']);

		// Balanced posting: debitCents === creditCents === 5400.
		$posting = $result['posting'];
		self::assertTrue($posting['posted']);
		self::assertTrue($posting['balanced']);
		self::assertSame(5400, $posting['debitCents']);
		self::assertSame(5400, $posting['creditCents']);

		// One GLTransaction + two balanced GLLines.
		self::assertCount(1, $os->dump(schema: 'GLTransaction'));
		$glLines = $os->dump(schema: 'GLLine');
		self::assertCount(2, $glLines);

		$debit = array_values(array_filter($glLines, static fn (array $l): bool => $l['side'] === 'debit'));
		$credit = array_values(array_filter($glLines, static fn (array $l): bool => $l['side'] === 'credit'));
		self::assertSame(54.0, $debit[0]['amount']);
		self::assertSame('1300', $debit[0]['accountNumber']);
		self::assertSame(54.0, $credit[0]['amount']);
		self::assertSame('1305', $credit[0]['accountNumber']);
		self::assertSame($debit[0]['amount'], $credit[0]['amount']);

	}//end testValueBasisAllocationAndBalancedPosting()

	/**
	 * A landed cost that does not divide evenly is distributed with a
	 * largest-remainder pass so the allocated cents sum EXACTLY to the input
	 * (no rounding leak — the posting stays balanced).
	 *
	 * @return void
	 */
	public function testLargestRemainderKeepsAllocationExact(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			schema: 'StockMove',
			rows: [
				[
					'id' => 'mv-a',
					'itemId' => 'A',
					'movementType' => 'receipt',
					'referenceDocumentUri' => 'R-1',
					'destinationLocationId' => 'WH-A',
					'quantity' => 1.0,
					'unitCost' => 1.0,
					'lifecycleState' => 'posted',
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'mv-b',
					'itemId' => 'B',
					'movementType' => 'receipt',
					'referenceDocumentUri' => 'R-1',
					'destinationLocationId' => 'WH-A',
					'quantity' => 1.0,
					'unitCost' => 1.0,
					'lifecycleState' => 'posted',
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'mv-c',
					'itemId' => 'C',
					'movementType' => 'receipt',
					'referenceDocumentUri' => 'R-1',
					'destinationLocationId' => 'WH-A',
					'quantity' => 1.0,
					'unitCost' => 1.0,
					'lifecycleState' => 'posted',
					'administrationId' => 'adm-1',
				],
			]
		);

		$service = $this->makeService(os: $os);

		// 100 cents over three equal lines -> 34 + 33 + 33 = 100.
		$result = $service->allocate(
			administrationId: 'adm-1',
			receiptReference: 'R-1',
			landedCostCents: 100,
			basis: 'value'
		);

		self::assertTrue($result['allocated']);
		$sum = 0;
		foreach ($result['lines'] as $line) {
			$sum += (int)$line['allocatedCents'];
		}

		self::assertSame(100, $sum);
		self::assertSame(100, $result['totalAllocatedCents']);
		self::assertSame(100, $result['posting']['debitCents']);
		self::assertSame($result['posting']['debitCents'], $result['posting']['creditCents']);

	}//end testLargestRemainderKeepsAllocationExact()
}//end class
