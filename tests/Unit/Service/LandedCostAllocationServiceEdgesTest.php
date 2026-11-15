<?php

/**
 * Edge-case unit tests for LandedCostAllocationService.
 *
 * Covers the guard envelopes, the quantity basis, the degenerate zero-weight
 * split, the InventoryValuation bump, the account / register fallbacks and
 * the row-normalisation paths of asArray().
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\GlAdjustmentPosting;
use OCA\Shillinq\Service\InventoryGlAdjustmentPoster;
use OCA\Shillinq\Service\LandedCostAllocationService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * LandedCostAllocationService edge-case tests.
 */
#[CoversClass(LandedCostAllocationService::class)]
#[UsesClass(InventoryGlAdjustmentPoster::class)]
#[UsesClass(GlAdjustmentPosting::class)]
final class LandedCostAllocationServiceEdgesTest extends TestCase {

	/**
	 * Build the service around an object service and an optional config map.
	 *
	 * @param ObjectServiceInterface $objectService The object service double.
	 * @param array<string,string>   $config        Config key => value overrides.
	 * @param LoggerInterface|null   $logger        Optional logger mock.
	 *
	 * @return LandedCostAllocationService
	 */
	private function makeService(
		ObjectServiceInterface $objectService,
		array $config = [],
		?LoggerInterface $logger = null
	): LandedCostAllocationService {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				if (array_key_exists($key, $config) === true) {
					return $config[$key];
				}

				return $default;
			}
		);

		$logger = ($logger ?? $this->createStub(LoggerInterface::class));
		$poster = new InventoryGlAdjustmentPoster(
			appConfig: $appConfig,
			logger: $logger,
			objectService: $objectService,
		);

		return new LandedCostAllocationService(
			appConfig: $appConfig,
			poster: $poster,
			logger: $logger,
			objectService: $objectService,
		);

	}//end makeService()

	/**
	 * A receipt line row.
	 *
	 * @param string $id       Move id.
	 * @param string $itemId   Item id.
	 * @param float  $quantity Quantity.
	 * @param float  $unitCost Unit cost.
	 *
	 * @return array<string,mixed>
	 */
	private function move(string $id, string $itemId, float $quantity, float $unitCost): array {
		return [
			'id' => $id,
			'itemId' => $itemId,
			'movementType' => 'receipt',
			'referenceDocumentUri' => 'R-9',
			'quantity' => $quantity,
			'unitCost' => $unitCost,
			'administrationId' => 'adm-1',
		];

	}//end move()

	/**
	 * Missing administration or receipt reference is refused without a lookup.
	 *
	 * @return void
	 */
	public function testMissingIdentifiersAreRefused(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->expects($this->never())->method('findAll');
		$service = $this->makeService(objectService: $os);

		$result = $service->allocate(administrationId: '', receiptReference: 'R-1', landedCostCents: 100);
		self::assertSame(
			['allocated' => false, 'message' => 'administrationId and receiptReference are required'],
			$result
		);

		$result = $service->allocate(administrationId: 'adm-1', receiptReference: '', landedCostCents: 100);
		self::assertFalse($result['allocated']);

	}//end testMissingIdentifiersAreRefused()

	/**
	 * A zero or negative landed cost is refused.
	 *
	 * @return void
	 */
	public function testNonPositiveLandedCostIsRefused(): void {
		$service = $this->makeService(objectService: new InMemoryObjectServiceStub());

		$result = $service->allocate(administrationId: 'adm-1', receiptReference: 'R-1', landedCostCents: 0);

		self::assertSame(
			['allocated' => false, 'message' => 'landedCostCents non-positive — nothing to allocate'],
			$result
		);

	}//end testNonPositiveLandedCostIsRefused()

	/**
	 * A receipt with no lines is reported and nothing is posted.
	 *
	 * @return void
	 */
	public function testNoReceiptLinesReportsReference(): void {
		$os = new InMemoryObjectServiceStub();
		$service = $this->makeService(objectService: $os);

		$result = $service->allocate(administrationId: 'adm-1', receiptReference: 'R-404', landedCostCents: 500);

		self::assertSame(
			['allocated' => false, 'message' => 'no receipt lines found for reference R-404'],
			$result
		);
		self::assertSame([], $os->saved);

	}//end testNoReceiptLinesReportsReference()

	/**
	 * Quantity basis allocates by units and bumps each active valuation
	 * snapshot's totalValue and unitCost by the allocated share.
	 *
	 * @return void
	 */
	public function testQuantityBasisBumpsActiveValuations(): void {
		$os = new InMemoryObjectServiceStub(
			data: [
				'StockMove' => [
					$this->move(id: 'mv-1', itemId: 'IT-1', quantity: 3.0, unitCost: 10.0),
					$this->move(id: 'mv-2', itemId: 'IT-2', quantity: 1.0, unitCost: 100.0),
				],
				'InventoryValuation' => [
					[
						'id' => 'val-1',
						'productId' => 'IT-1',
						'status' => 'active',
						'administrationId' => 'adm-1',
						'quantity' => 3.0,
						'totalValue' => 30.0,
					],
				],
			]
		);
		$service = $this->makeService(objectService: $os);

		$result = $service->allocate(
			administrationId: 'adm-1',
			receiptReference: 'R-9',
			landedCostCents: 400,
			basis: 'quantity',
			postingDate: '2026-05-20T10:00:00Z'
		);

		self::assertTrue($result['allocated']);
		self::assertSame('quantity', $result['basis']);
		self::assertSame(300, $result['lines'][0]['allocatedCents']);
		self::assertSame(100, $result['lines'][1]['allocatedCents']);
		self::assertSame(11.0, $result['lines'][0]['landedUnitCost']);
		self::assertSame(101.0, $result['lines'][1]['landedUnitCost']);
		self::assertSame('2026-05-20', $result['posting']['transaction']['postingDate']);
		self::assertSame('2026-Q2', $result['posting']['transaction']['periodId']);
		self::assertSame('Landed cost — R-9 — EUR 4,00', $result['posting']['transaction']['description']);

		$valuations = array_values(
			array_filter($os->saved, static fn (array $s): bool => $s['schema'] === 'InventoryValuation')
		);
		self::assertCount(1, $valuations);
		self::assertSame('val-1', $valuations[0]['object']['id']);
		self::assertSame(33.0, $valuations[0]['object']['totalValue']);
		self::assertSame(11.0, $valuations[0]['object']['unitCost']);

	}//end testQuantityBasisBumpsActiveValuations()

	/**
	 * Lines with zero weight split the cost evenly (remainder to the first
	 * lines), keep their original unit cost, and an unknown basis is reported
	 * as 'value'. Empty configured accounts fall back to the defaults, and the
	 * posting date comes from the first line's draftedAt.
	 *
	 * @return void
	 */
	public function testZeroWeightSplitsEvenlyWithDefaultAccounts(): void {
		$moveA = $this->move(id: 'mv-a', itemId: '', quantity: 0.0, unitCost: 5.0);
		$moveA['draftedAt'] = '2026-11-02T08:00:00Z';
		$moveB = $this->move(id: 'mv-b', itemId: '', quantity: 0.0, unitCost: 7.0);
		$os = new InMemoryObjectServiceStub(data: ['StockMove' => [$moveA, $moveB]]);
		$service = $this->makeService(
			objectService: $os,
			config: [
				'register' => '',
				LandedCostAllocationService::CFG_INVENTORY_ACCOUNT => '  ',
				LandedCostAllocationService::CFG_LANDED_COST_CLEARING_ACCOUNT => '',
			]
		);

		$result = $service->allocate(
			administrationId: 'adm-1',
			receiptReference: 'R-9',
			landedCostCents: 101,
			basis: 'bogus'
		);

		self::assertSame('value', $result['basis']);
		self::assertSame(51, $result['lines'][0]['allocatedCents']);
		self::assertSame(50, $result['lines'][1]['allocatedCents']);
		self::assertSame(5.0, $result['lines'][0]['landedUnitCost']);
		self::assertSame(7.0, $result['lines'][1]['landedUnitCost']);
		self::assertSame(101, $result['totalAllocatedCents']);
		self::assertSame('2026-11-02', $result['posting']['transaction']['postingDate']);
		self::assertSame('2026-Q4', $result['posting']['transaction']['periodId']);

		$glLines = array_values(
			array_filter($os->saved, static fn (array $s): bool => $s['schema'] === 'GLLine')
		);
		self::assertSame('1300', $glLines[0]['object']['accountNumber']);
		self::assertSame('1305', $glLines[1]['object']['accountNumber']);

	}//end testZeroWeightSplitsEvenlyWithDefaultAccounts()

	/**
	 * Configured accounts are used verbatim (trimmed), and entity rows from
	 * the engine are normalised through jsonSerialize().
	 *
	 * @return void
	 */
	public function testConfiguredAccountsAndEntityRows(): void {
		$os = new InMemoryObjectServiceStub(
			data: ['StockMove' => [$this->move(id: 'mv-1', itemId: 'IT-1', quantity: 2.0, unitCost: 4.0)]],
			findAllRendersEntities: true
		);
		$service = $this->makeService(
			objectService: $os,
			config: [
				LandedCostAllocationService::CFG_INVENTORY_ACCOUNT => ' 3000 ',
				LandedCostAllocationService::CFG_LANDED_COST_CLEARING_ACCOUNT => '1399',
			]
		);

		$result = $service->allocate(
			administrationId: 'adm-1',
			receiptReference: 'R-9',
			landedCostCents: 200,
			postingDate: '2026-02-01'
		);

		self::assertSame('mv-1', $result['lines'][0]['moveId']);
		self::assertSame(5.0, $result['lines'][0]['landedUnitCost']);
		self::assertSame('2026-Q1', $result['posting']['transaction']['periodId']);
		$glLines = array_values(
			array_filter($os->saved, static fn (array $s): bool => $s['schema'] === 'GLLine')
		);
		self::assertSame('3000', $glLines[0]['object']['accountNumber']);
		self::assertSame('1399', $glLines[1]['object']['accountNumber']);

	}//end testConfiguredAccountsAndEntityRows()

	/**
	 * Rows exposing only getObject() are normalised through it; a non-array
	 * getObject() payload yields an empty row; a failing valuation lookup is
	 * logged and does not abort the allocation.
	 *
	 * @return void
	 */
	public function testGetObjectRowsAndValuationFailureIsLogged(): void {
		$row = new class {
			/**
			 * Payload accessor.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return ['id' => 'mv-g', 'itemId' => 'IT-G', 'quantity' => 1.0, 'unitCost' => 2.0, 'postedAt' => '2026-07-07'];
			}
		};
		$emptyRow = new class {
			/**
			 * Non-array payload accessor.
			 *
			 * @return string
			 */
			public function getObject(): string {
				return 'not-an-array';
			}
		};

		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('setRegister')->willReturnSelf();
		$os->method('setSchema')->willReturnSelf();
		$os->method('findAll')->willReturnOnConsecutiveCalls(
			[$row, $emptyRow],
			[42]
		);
		$os->method('saveObject')->willReturn(new Support\ObjectEntityStub(payload: ['id' => 'gl-1']));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'LandedCostAllocationService: failed to bump valuation snapshot',
			[
				'itemId' => 'IT-G',
				'exception' => 'LandedCostAllocationService: unsupported row type from ObjectService',
			]
		);

		$service = $this->makeService(objectService: $os, logger: $logger);
		$result = $service->allocate(administrationId: 'adm-1', receiptReference: 'R-9', landedCostCents: 50);

		self::assertTrue($result['allocated']);
		self::assertSame('mv-g', $result['lines'][0]['moveId']);
		self::assertSame(50, $result['lines'][0]['allocatedCents']);
		self::assertSame('', $result['lines'][1]['moveId']);
		self::assertSame(0, $result['lines'][1]['allocatedCents']);

	}//end testGetObjectRowsAndValuationFailureIsLogged()

	/**
	 * A scalar receipt row is an unsupported type that propagates.
	 *
	 * @return void
	 */
	public function testUnsupportedRowTypeThrows(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('setRegister')->willReturnSelf();
		$os->method('setSchema')->willReturnSelf();
		$os->method('findAll')->willReturn(['raw-string']);

		$service = $this->makeService(objectService: $os);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unsupported row type from ObjectService');
		$service->allocate(administrationId: 'adm-1', receiptReference: 'R-9', landedCostCents: 50);

	}//end testUnsupportedRowTypeThrows()

	/**
	 * A JsonSerializable row whose serialisation is not an array is treated
	 * as an empty line, and the valuation lookup returning no rows skips the bump.
	 *
	 * @return void
	 */
	public function testNonArrayJsonSerializeRowIsEmpty(): void {
		$scalarJson = new class implements \JsonSerializable {
			/**
			 * Serialise to a scalar.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'scalar';
			}
		};

		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('setRegister')->willReturnSelf();
		$os->method('setSchema')->willReturnSelf();
		$os->method('findAll')->willReturnOnConsecutiveCalls(
			[$scalarJson, ['id' => 'mv-2', 'itemId' => 'IT-2', 'quantity' => 1.0, 'unitCost' => 1.0]],
			[]
		);
		$os->expects($this->exactly(3))->method('saveObject')->willReturn(new Support\ObjectEntityStub(payload: ['id' => 'x']));

		$service = $this->makeService(objectService: $os);
		$result = $service->allocate(
			administrationId: 'adm-1',
			receiptReference: 'R-9',
			landedCostCents: 10,
			postingDate: '2026-03-03'
		);

		self::assertSame('', $result['lines'][0]['moveId']);
		self::assertSame(0, $result['lines'][0]['allocatedCents']);
		self::assertSame(10, $result['lines'][1]['allocatedCents']);

	}//end testNonArrayJsonSerializeRowIsEmpty()
}//end class
