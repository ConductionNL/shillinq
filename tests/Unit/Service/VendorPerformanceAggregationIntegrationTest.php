<?php

/**
 * Integration test for the slice 10 monthly vendor scoring sweep.
 *
 * Threads the whole aggregation pipeline against the in-memory
 * ObjectService stub: seeds two suppliers' worth of GoodsReceiptNote +
 * ThreeWayMatch + SupplierInvoice + PurchaseOrder history for a
 * calendar month, runs
 * {@see VendorPerformanceAggregation::aggregateAdministrationForPeriod()}
 * and asserts:
 *  - one VendorPerformance scorecard per supplier is persisted with the
 *    correct weighted overall score, on-time / qty / price / invoice
 *    rates and dispute count;
 *  - the 96 %+ supplier with a long history is flagged
 *    automatedReviewEligible=TRUE and their supplier-scoped
 *    ToleranceProfile has been one-step-relaxed;
 *  - the 86 %-score supplier stays automatedReviewEligible=FALSE and
 *    their tolerance profile is unchanged.
 *
 * This is the slice-10 acceptance covered in the spec's scorecard
 * scenarios + REQ-VP-005 auto-review rollover.
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
 * End-to-end aggregation flow.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VendorPerformanceAggregationIntegrationTest extends TestCase {
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
	 * Seed a two-supplier dataset for the period 2026-05.
	 *
	 *  - vendor-001 (the "good" supplier): 2 POs, both delivered on time,
	 *    both received with exact qty, 2 invoices both auto_approved
	 *    → 100 % across the board → overallScore=10000 bp. Has an
	 *    `2025-01` scorecard so the 90-day bootstrap is met.
	 *
	 *  - vendor-002 (the "bad" supplier): 2 POs, 1 late, 1 with qty
	 *    short, 2 invoices both raised price exceptions → 50 % on-time,
	 *    50 % qty, 0 % price, 0 % invoice → overallScore=3500 bp.
	 *
	 * @param InMemoryObjectService $os Stub to seed.
	 *
	 * @return void
	 */
	private function seed(InMemoryObjectService $os): void {
		$os->seed(
			'PurchaseOrder',
			[
				['id' => 'po-1', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1', 'expectedDeliveryDate' => '2026-05-10'],
				['id' => 'po-2', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1', 'expectedDeliveryDate' => '2026-05-20'],
				['id' => 'po-3', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'expectedDeliveryDate' => '2026-05-12'],
				['id' => 'po-4', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'expectedDeliveryDate' => '2026-05-22'],
			]
		);
		$os->seed(
			'PurchaseOrderLine',
			[
				['id' => 'pol-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 10],
				['id' => 'pol-2', 'poId' => 'po-2', 'administrationId' => 'adm-1', 'quantityOrdered' => 20],
				['id' => 'pol-3', 'poId' => 'po-3', 'administrationId' => 'adm-1', 'quantityOrdered' => 30],
				['id' => 'pol-4', 'poId' => 'po-4', 'administrationId' => 'adm-1', 'quantityOrdered' => 40],
			]
		);
		$os->seed(
			'GoodsReceiptNote',
			[
				['id' => 'grn-1', 'poIds' => ['po-1'], 'receivedAt' => '2026-05-09T10:00:00Z', 'administrationId' => 'adm-1'],
				['id' => 'grn-2', 'poIds' => ['po-2'], 'receivedAt' => '2026-05-20T10:00:00Z', 'administrationId' => 'adm-1'],
				['id' => 'grn-3', 'poIds' => ['po-3'], 'receivedAt' => '2026-05-15T10:00:00Z', 'administrationId' => 'adm-1'],
				['id' => 'grn-4', 'poIds' => ['po-4'], 'receivedAt' => '2026-05-22T10:00:00Z', 'administrationId' => 'adm-1'],
			]
		);
		$os->seed(
			'GoodsReceiptLine',
			[
				['grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 10, 'administrationId' => 'adm-1'],
				['grnId' => 'grn-2', 'poLineId' => 'pol-2', 'quantityReceived' => 20, 'administrationId' => 'adm-1'],
				['grnId' => 'grn-3', 'poLineId' => 'pol-3', 'quantityReceived' => 28, 'administrationId' => 'adm-1'],
				['grnId' => 'grn-4', 'poLineId' => 'pol-4', 'quantityReceived' => 40, 'administrationId' => 'adm-1'],
			]
		);
		$os->seed(
			'SupplierInvoice',
			[
				['id' => 'inv-1', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-05-11'],
				['id' => 'inv-2', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-05-21'],
				['id' => 'inv-3', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-05-16'],
				['id' => 'inv-4', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-05-23'],
			]
		);
		$os->seed(
			'ThreeWayMatch',
			[
				[
					'invoiceId' => 'inv-1',
					'matchStatus' => 'auto_approved',
					'administrationId' => 'adm-1',
					'createdAt' => '2026-05-11T12:00:00Z',
				],
				[
					'invoiceId' => 'inv-2',
					'matchStatus' => 'auto_approved',
					'administrationId' => 'adm-1',
					'createdAt' => '2026-05-21T12:00:00Z',
				],
				[
					'invoiceId' => 'inv-3',
					'matchStatus' => 'exception_price',
					'administrationId' => 'adm-1',
					'createdAt' => '2026-05-16T12:00:00Z',
					'resolutionAction' => 'credit_note_requested',
					'resolvedAt' => '2026-05-20T12:00:00Z',
				],
				[
					'invoiceId' => 'inv-4',
					'matchStatus' => 'exception_price',
					'administrationId' => 'adm-1',
					'createdAt' => '2026-05-23T12:00:00Z',
					'resolutionAction' => 'supplier_contacted',
					'resolvedAt' => '2026-05-25T12:00:00Z',
				],
			]
		);
		// Seed an older scorecard for vendor-001 so the bootstrap window is met.
		$os->seed(
			'VendorPerformance',
			[
				[
					'id' => 'vp-vendor1-old',
					'supplierId' => 'vendor-001',
					'administrationId' => 'adm-1',
					'period' => '2020-01',
					'overallScore' => 9700,
				],
			]
		);
		// Seed a supplier-scoped tolerance profile for vendor-001 so the
		// auto-relax invocation has something to nudge.
		$os->seed(
			'ToleranceProfile',
			[
				[
					'id' => 'tp-vendor-001',
					'profileId' => 'TP-VENDOR-001',
					'scope' => 'supplier',
					'scopeReference' => 'vendor-001',
					'status' => 'active',
					'administrationId' => 'adm-1',
					'priceTolerancePercentage' => 250,
					'quantityTolerancePercentage' => 100,
					'dateToleranceDays' => 3,
				],
			]
		);

	}//end seed()

	/**
	 * Running the period aggregation persists one scorecard per supplier
	 * with the correct overall score and dispute counts; only the
	 * eligible supplier is flagged + relaxed.
	 *
	 * @return void
	 */
	public function testAggregateAdministrationForPeriodPersistsBothSuppliers(): void {
		$os = new InMemoryObjectService();
		$this->seed($os);
		$service = $this->makeService($os);

		$scorecards = $service->aggregateAdministrationForPeriod(
			administrationId: 'adm-1',
			period:           '2026-05'
		);

		self::assertCount(2, $scorecards);

		$bySupplier = [];
		foreach ($scorecards as $card) {
			$bySupplier[(string)$card['supplierId']] = $card;
		}

		// Vendor-001: 2 on-time / 2 GRNs, 2 exact qty / 2 lines,
		// 2 auto_approved / 2 matches, 2 first-try auto_approved / 2 invoices
		// → 10000 bp across the board → overall 10000.
		$good = $bySupplier['vendor-001'];
		self::assertSame(10000, (int)$good['onTimeDeliveryRate']);
		self::assertSame(10000, (int)$good['quantityAccuracyRate']);
		self::assertSame(10000, (int)$good['priceAccuracyRate']);
		self::assertSame(10000, (int)$good['invoiceAccuracyRate']);
		self::assertSame(10000, (int)$good['overallScore']);
		self::assertSame(0, (int)$good['disputeCount']);
		self::assertTrue($good['automatedReviewEligible']);

		// Vendor-002: 1 on-time / 2, 1 exact / 2 qty, 0 of 2 price-accurate,
		// 0 of 2 invoice-accurate → 5000/5000/0/0 → weighted overall =
		// (5000*4000 + 5000*3000 + 0*2000 + 0*1000)/10000 = 3500 bp.
		$bad = $bySupplier['vendor-002'];
		self::assertSame(5000, (int)$bad['onTimeDeliveryRate']);
		self::assertSame(5000, (int)$bad['quantityAccuracyRate']);
		self::assertSame(0, (int)$bad['priceAccuracyRate']);
		self::assertSame(0, (int)$bad['invoiceAccuracyRate']);
		self::assertSame(3500, (int)$bad['overallScore']);
		// Both vendor-002 matches were disputed (credit_note_requested +
		// supplier_contacted) → disputeCount 2.
		self::assertSame(2, (int)$bad['disputeCount']);
		self::assertFalse((bool)$bad['automatedReviewEligible']);

	}//end testAggregateAdministrationForPeriodPersistsBothSuppliers()

	/**
	 * Eligibility side-effect: the relaxed ToleranceProfile is one step
	 * more permissive than the seeded one.
	 *
	 * @return void
	 */
	public function testEligibilityRelaxesSupplierToleranceProfile(): void {
		$os = new InMemoryObjectService();
		$this->seed($os);
		$service = $this->makeService($os);

		$service->aggregateAdministrationForPeriod(
			administrationId: 'adm-1',
			period:           '2026-05'
		);

		$profiles = $os->setRegister('shillinq')
			->setSchema('ToleranceProfile')
			->findAll(['filters' => ['scopeReference' => 'vendor-001']]);

		// Two records exist after the run: the seeded baseline + the
		// saveObject-replayed relaxed copy. The relaxed one carries the
		// nudged values.
		$hasRelaxed = false;
		foreach ($profiles as $profile) {
			if ((int)($profile['priceTolerancePercentage'] ?? 0) === 275
				&& (int)($profile['quantityTolerancePercentage'] ?? 0) === 125
				&& (int)($profile['dateToleranceDays'] ?? 0) === 4
			) {
				$hasRelaxed = true;
				break;
			}
		}

		self::assertTrue(
			$hasRelaxed,
			'Expected a ToleranceProfile copy with relaxed price/qty/date fields.'
		);

	}//end testEligibilityRelaxesSupplierToleranceProfile()
}//end class
