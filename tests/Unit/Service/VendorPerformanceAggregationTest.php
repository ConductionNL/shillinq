<?php

/**
 * Unit tests for VendorPerformanceAggregation (slice 10 of
 * bookkeeping-purchase-order-3way).
 *
 * Covers REQ-PO3W-008 / REQ-VP-001 / REQ-VP-005:
 *  - weighted overall score (40 / 30 / 20 / 10 weighting) → integer
 *    basis-point output;
 *  - rateBp clamps for divide-by-zero + 100 % ceiling;
 *  - eligibility threshold (≥9600 bp) AND 90-day bootstrap (first-period
 *    age) — both conditions required;
 *  - trend bucketing (improving ≥+50 bp, declining ≤-50 bp, stable
 *    inside the band, stable when no prior card exists);
 *  - on-time + quantity + price + invoice rate computations against
 *    seeded GRN / PO line / SupplierInvoice / ThreeWayMatch rows;
 *  - autoRelaxToleranceProfile() bumps the three tolerance fields when
 *    a supplier-scoped profile exists; no-op (null) otherwise.
 *
 * Built on the same {@see InMemoryObjectService} stub used by the slice
 * 06/07/09 tests so the OR API surface stays consistent across the chain.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\VendorPerformanceAggregation;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * VendorPerformanceAggregation unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VendorPerformanceAggregationTest extends TestCase {
	/**
	 * Build a service wired against the supplied in-memory stub.
	 *
	 * @param InMemoryObjectService $os In-memory OR stub.
	 *
	 * @return VendorPerformanceAggregation
	 */
	private function makeService(InMemoryObjectService $os): VendorPerformanceAggregation {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);

		$logger = $this->createStub(LoggerInterface::class);

		return new VendorPerformanceAggregation(
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeService()

	/**
	 * Weighted overall: 9800/9950/9700/9900 → 98.25 % = 9825 bp.
	 *
	 * @return void
	 */
	public function testWeightedOverallComputesIntegerBasisPoints(): void {
		$service = $this->makeService(new InMemoryObjectService());

		$score = $service->weightedOverall(
			onTimeBp:  9800,
			qtyBp:     9950,
			priceBp:   9700,
			invoiceBp: 9900
		);

		// (9800*4000 + 9950*3000 + 9700*2000 + 9900*1000) / 10000.
		// = (39200000 + 29850000 + 19400000 + 9900000) / 10000.
		// = 98350000 / 10000 = 9835.
		self::assertSame(9835, $score);

	}//end testWeightedOverallComputesIntegerBasisPoints()

	/**
	 * Weighted overall clamps to [0, 10000].
	 *
	 * @return void
	 */
	public function testWeightedOverallClampsToMax(): void {
		$service = $this->makeService(new InMemoryObjectService());

		$score = $service->weightedOverall(
			onTimeBp:  10000,
			qtyBp:     10000,
			priceBp:   10000,
			invoiceBp: 10000
		);

		self::assertSame(10000, $score);

	}//end testWeightedOverallClampsToMax()

	/**
	 * Trend bucketing: +49 bp → stable, +50 bp → improving, -50 bp →
	 * declining, null prior → stable.
	 *
	 * @return void
	 */
	public function testComputeScoreTrendBuckets(): void {
		$service = $this->makeService(new InMemoryObjectService());

		self::assertSame('stable', $service->computeScoreTrend(currentBp: 9700, priorCard: ['overallScore' => 9651]));
		self::assertSame('improving', $service->computeScoreTrend(currentBp: 9700, priorCard: ['overallScore' => 9650]));
		self::assertSame('declining', $service->computeScoreTrend(currentBp: 9600, priorCard: ['overallScore' => 9650]));
		self::assertSame('stable', $service->computeScoreTrend(currentBp: 9700, priorCard: null));

	}//end testComputeScoreTrendBuckets()

	/**
	 * Eligibility: below 9600 bp is FALSE regardless of age.
	 *
	 * @return void
	 */
	public function testSetAutoReviewEligibleBelowThreshold(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'VendorPerformance',
			[
				[
					'id' => 'card-1',
					'supplierId' => 'vendor-001',
					'administrationId' => 'adm-1',
					'period' => '2020-01',
					'overallScore' => 8500,
				],
			]
		);
		$service = $this->makeService($os);

		$eligible = $service->setAutoReviewEligible(
			overallScoreBp:   8500,
			administrationId: 'adm-1',
			supplierId:       'vendor-001'
		);

		self::assertFalse($eligible);

	}//end testSetAutoReviewEligibleBelowThreshold()

	/**
	 * Eligibility: above 9600 bp but bootstrap NOT met → FALSE.
	 *
	 * @return void
	 */
	public function testSetAutoReviewEligibleRespectsBootstrap(): void {
		$os = new InMemoryObjectService();
		// First card is "today" so the bootstrap age is 0 days.
		$os->seed(
			'VendorPerformance',
			[
				[
					'id' => 'card-1',
					'supplierId' => 'vendor-001',
					'administrationId' => 'adm-1',
					'period' => date('Y-m'),
					'overallScore' => 9800,
				],
			]
		);
		$service = $this->makeService($os);

		$eligible = $service->setAutoReviewEligible(
			overallScoreBp:   9800,
			administrationId: 'adm-1',
			supplierId:       'vendor-001'
		);

		self::assertFalse($eligible);

	}//end testSetAutoReviewEligibleRespectsBootstrap()

	/**
	 * Eligibility: above 9600 bp AND bootstrap met → TRUE.
	 *
	 * @return void
	 */
	public function testSetAutoReviewEligibleHappyPath(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'VendorPerformance',
			[
				[
					'id' => 'card-old',
					'supplierId' => 'vendor-001',
					'administrationId' => 'adm-1',
					// Period that is older than 90 days.
					'period' => '2020-01',
					'overallScore' => 9000,
				],
			]
		);
		$service = $this->makeService($os);

		$eligible = $service->setAutoReviewEligible(
			overallScoreBp:   9800,
			administrationId: 'adm-1',
			supplierId:       'vendor-001'
		);

		self::assertTrue($eligible);

	}//end testSetAutoReviewEligibleHappyPath()

	/**
	 * AutoRelaxToleranceProfile bumps the three tolerance fields when the
	 * supplier has a scope=supplier ToleranceProfile.
	 *
	 * @return void
	 */
	public function testAutoRelaxToleranceProfileBumpsFields(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'ToleranceProfile',
			[
				[
					'id' => 'tp-1',
					'profileId' => 'TP-VENDOR-001',
					'scope' => 'supplier',
					'scopeReference' => 'vendor-001',
					'status' => 'active',
					'administrationId' => 'adm-1',
					'priceTolerancePercentage' => 200,
					'quantityTolerancePercentage' => 100,
					'dateToleranceDays' => 2,
				],
			]
		);

		$service = $this->makeService($os);

		$updated = $service->autoRelaxToleranceProfile(
			administrationId: 'adm-1',
			supplierId:       'vendor-001'
		);

		self::assertNotNull($updated);
		self::assertSame(225, (int)$updated['priceTolerancePercentage']);
		self::assertSame(125, (int)$updated['quantityTolerancePercentage']);
		self::assertSame(3, (int)$updated['dateToleranceDays']);

	}//end testAutoRelaxToleranceProfileBumpsFields()

	/**
	 * AutoRelaxToleranceProfile is a no-op (null) when no supplier-scoped
	 * profile exists.
	 *
	 * @return void
	 */
	public function testAutoRelaxToleranceProfileNoOpWithoutProfile(): void {
		$os = new InMemoryObjectService();
		$service = $this->makeService($os);

		$updated = $service->autoRelaxToleranceProfile(
			administrationId: 'adm-1',
			supplierId:       'vendor-001'
		);

		self::assertNull($updated);

	}//end testAutoRelaxToleranceProfileNoOpWithoutProfile()

	/**
	 * On-time delivery rate: 2 of 3 GRNs on or before expectedDeliveryDate.
	 *
	 * @return void
	 */
	public function testComputeOnTimeDeliveryRate(): void {
		$service = $this->makeService(new InMemoryObjectService());

		$poIds = [
			'po-1' => ['id' => 'po-1', 'expectedDeliveryDate' => '2026-05-10'],
			'po-2' => ['id' => 'po-2', 'expectedDeliveryDate' => '2026-05-20'],
			'po-3' => ['id' => 'po-3', 'expectedDeliveryDate' => '2026-05-15'],
		];
		$grns = [
			['receivedAt' => '2026-05-10T08:00:00Z', 'poIds' => ['po-1']],
			['receivedAt' => '2026-05-21T08:00:00Z', 'poIds' => ['po-2']],
			['receivedAt' => '2026-05-12T08:00:00Z', 'poIds' => ['po-3']],
		];

		$rate = $service->computeOnTimeDeliveryRate(grns: $grns, poIds: $poIds);

		// 2 / 3 = 6666 bp (integer division).
		self::assertSame(6666, $rate);

	}//end testComputeOnTimeDeliveryRate()

	/**
	 * Quantity accuracy: 2 of 3 GRN lines have qtyReceived = qtyOrdered.
	 *
	 * @return void
	 */
	public function testComputeQuantityAccuracyRate(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				['id' => 'pol-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 10],
				['id' => 'pol-2', 'administrationId' => 'adm-1', 'quantityOrdered' => 20],
				['id' => 'pol-3', 'administrationId' => 'adm-1', 'quantityOrdered' => 30],
			]
		);
		$os->seed(
			'GoodsReceiptLine',
			[
				['grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 10, 'administrationId' => 'adm-1'],
				['grnId' => 'grn-1', 'poLineId' => 'pol-2', 'quantityReceived' => 18, 'administrationId' => 'adm-1'],
				['grnId' => 'grn-1', 'poLineId' => 'pol-3', 'quantityReceived' => 30, 'administrationId' => 'adm-1'],
			]
		);
		$service = $this->makeService($os);

		$rate = $service->computeQuantityAccuracyRate(
			grns: [['id' => 'grn-1']],
			administrationId: 'adm-1'
		);

		// 2 / 3 = 6666 bp.
		self::assertSame(6666, $rate);

	}//end testComputeQuantityAccuracyRate()

	/**
	 * Price accuracy: 3 of 4 matches landed auto_approved/within_tolerance.
	 *
	 * @return void
	 */
	public function testComputePriceAccuracyRate(): void {
		$service = $this->makeService(new InMemoryObjectService());

		$matches = [
			['matchStatus' => 'auto_approved'],
			['matchStatus' => 'within_tolerance'],
			['matchStatus' => 'auto_approved'],
			['matchStatus' => 'exception_price'],
		];

		$rate = $service->computePriceAccuracyRate(matches: $matches);

		// 3 / 4 = 7500 bp.
		self::assertSame(7500, $rate);

	}//end testComputePriceAccuracyRate()

	/**
	 * Invoice accuracy: counts the FIRST match per invoice — a later
	 * within_tolerance does NOT recover a first-try exception.
	 *
	 * @return void
	 */
	public function testComputeInvoiceAccuracyRateUsesFirstMatch(): void {
		$service = $this->makeService(new InMemoryObjectService());

		$matches = [
			['invoiceId' => 'inv-1', 'matchStatus' => 'exception_price', 'createdAt' => '2026-05-10T09:00:00Z'],
			['invoiceId' => 'inv-1', 'matchStatus' => 'within_tolerance', 'createdAt' => '2026-05-11T09:00:00Z'],
			['invoiceId' => 'inv-2', 'matchStatus' => 'auto_approved', 'createdAt' => '2026-05-12T09:00:00Z'],
			['invoiceId' => 'inv-3', 'matchStatus' => 'auto_approved', 'createdAt' => '2026-05-13T09:00:00Z'],
		];
		$invoiceIds = ['inv-1' => true, 'inv-2' => true, 'inv-3' => true];

		$rate = $service->computeInvoiceAccuracyRate(matches: $matches, invoiceIds: $invoiceIds);

		// 2 / 3 invoices auto_approved on first try.
		self::assertSame(6666, $rate);

	}//end testComputeInvoiceAccuracyRateUsesFirstMatch()

	/**
	 * RateBp returns 0 on zero-denominator.
	 *
	 * @return void
	 */
	public function testRateBpZeroDenominator(): void {
		$service = $this->makeService(new InMemoryObjectService());

		$rate = $service->computePriceAccuracyRate(matches: []);

		self::assertSame(0, $rate);

	}//end testRateBpZeroDenominator()
}//end class
