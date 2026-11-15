<?php

/**
 * Edge-case unit tests for VendorPerformanceAggregation.
 *
 * Covers the skip branches of the four rate computations, the clamps of
 * weightedOverall and autoRelaxToleranceProfile, the refusal branches of
 * setAutoReviewEligible, quarter periods and year wrap of the prior period,
 * resolution-day edge cases and the query / persistence failure paths.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\VendorPerformanceAggregation;
use OCA\Shillinq\Service\VendorScorecardRun;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * VendorPerformanceAggregation edge-case tests.
 */
#[CoversClass(VendorPerformanceAggregation::class)]
#[UsesClass(VendorScorecardRun::class)]
final class VendorPerformanceAggregationEdgesTest extends TestCase {

	/**
	 * Build the service.
	 *
	 * @param ObjectServiceInterface $objectService Object service double.
	 * @param LoggerInterface|null   $logger        Optional logger mock.
	 * @param string                 $register      Configured register slug.
	 *
	 * @return VendorPerformanceAggregation
	 */
	private function makeService(
		ObjectServiceInterface $objectService,
		?LoggerInterface $logger = null,
		string $register = 'shillinq'
	): VendorPerformanceAggregation {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);

		return new VendorPerformanceAggregation(
			appConfig: $appConfig,
			logger: ($logger ?? $this->createStub(LoggerInterface::class)),
			objectService: $objectService,
		);

	}//end makeService()

	/**
	 * GRNs without a receipt date or without a resolvable expected date are
	 * not scored; a short receivedAt is compared verbatim.
	 *
	 * @return void
	 */
	public function testOnTimeDeliverySkipsUnscorableGrns(): void {
		$service = $this->makeService(objectService: new InMemoryObjectServiceStub());
		$poIds = [
			'po-1' => ['id' => 'po-1', 'expectedDeliveryDate' => '2026-05-10T00:00:00Z'],
			'po-2' => ['id' => 'po-2', 'expectedDeliveryDate' => '2026-05-20'],
			'po-blank' => ['id' => 'po-blank', 'expectedDeliveryDate' => '  '],
		];
		$grns = [
			['id' => 'g0', 'receivedAt' => '', 'poIds' => ['po-1']],
			['id' => 'g1', 'receivedAt' => '2026-05-15T10:00:00Z', 'poIds' => ['po-unknown', 'po-blank']],
			['id' => 'g2', 'receivedAt' => '2026-05-15T10:00:00Z', 'poIds' => ['po-2', 'po-1']],
			['id' => 'g3', 'receivedAt' => '2026-05-09', 'poIds' => ['po-1']],
			['id' => 'g4', 'receivedAt' => '2026', 'poIds' => ['po-1']],
		];

		// Scored: g2 (late vs earliest 05-10), g3 (on time), g4 ('2026' < '2026-05-10', on time).
		self::assertSame(6666, $service->computeOnTimeDeliveryRate(grns: $grns, poIds: $poIds));
		self::assertSame(0, $service->computeOnTimeDeliveryRate(grns: [$grns[0], $grns[1]], poIds: $poIds));

	}//end testOnTimeDeliverySkipsUnscorableGrns()

	/**
	 * Quantity accuracy skips GRNs without id, lines without a PO line and
	 * lines whose PO line is missing; quantities compare as thousandths
	 * across int / float / numeric-string / non-numeric values.
	 *
	 * @return void
	 */
	public function testQuantityAccuracyHandlesSkipsAndValueShapes(): void {
		$os = new InMemoryObjectServiceStub(
			data: [
				'GoodsReceiptLine' => [
					['id' => 'l1', 'grnId' => 'g1', 'administrationId' => 'adm-1', 'poLineId' => '', 'quantityReceived' => 1],
					['id' => 'l2', 'grnId' => 'g1', 'administrationId' => 'adm-1', 'poLineId' => 'pl-missing', 'quantityReceived' => 1],
					['id' => 'l3', 'grnId' => 'g1', 'administrationId' => 'adm-1', 'poLineId' => 'pl-1', 'quantityReceived' => 2.5],
					['id' => 'l4', 'grnId' => 'g1', 'administrationId' => 'adm-1', 'poLineId' => 'pl-2', 'quantityReceived' => 3],
					['id' => 'l5', 'grnId' => 'g1', 'administrationId' => 'adm-1', 'poLineId' => 'pl-3', 'quantityReceived' => null],
				],
				'PurchaseOrderLine' => [
					['id' => 'pl-1', 'administrationId' => 'adm-1', 'quantityOrdered' => '2.5'],
					['id' => 'pl-2', 'administrationId' => 'adm-1', 'quantityOrdered' => 3.0],
					['id' => 'pl-3', 'administrationId' => 'adm-1', 'quantityOrdered' => 1],
				],
			]
		);
		$service = $this->makeService(objectService: $os);

		$rate = $service->computeQuantityAccuracyRate(
			grns: [['receivedAt' => '2026-05-01'], ['id' => 'g1']],
			administrationId: 'adm-1'
		);

		// Eligible l3, l4, l5; accurate l3 (2.5 == '2.5') and l4 (3 == 3.0).
		self::assertSame(6666, $rate);

	}//end testQuantityAccuracyHandlesSkipsAndValueShapes()

	/**
	 * Invoice accuracy ignores matches for other invoices and scores the
	 * chronologically first match per invoice, even when it arrives later.
	 *
	 * @return void
	 */
	public function testInvoiceAccuracyPicksEarliestMatchPerInvoice(): void {
		$service = $this->makeService(objectService: new InMemoryObjectServiceStub());
		$matches = [
			['invoiceId' => 'inv-other', 'matchStatus' => 'auto_approved', 'createdAt' => '2026-05-01'],
			['invoiceId' => 'inv-1', 'matchStatus' => 'auto_approved', 'createdAt' => '2026-05-10'],
			['invoiceId' => 'inv-1', 'matchStatus' => 'exception_price', 'createdAt' => '2026-05-02'],
			['invoiceId' => 'inv-1', 'matchStatus' => 'auto_approved', 'createdAt' => ''],
			['invoiceId' => 'inv-2', 'matchStatus' => 'auto_approved', 'createdAt' => '2026-05-03'],
		];

		$rate = $service->computeInvoiceAccuracyRate(
			matches: $matches,
			invoiceIds: ['inv-1' => true, 'inv-2' => true]
		);

		// inv-1 first match is exception_price; inv-2 is auto_approved: 1 of 2.
		self::assertSame(5000, $rate);

	}//end testInvoiceAccuracyPicksEarliestMatchPerInvoice()

	/**
	 * The weighted overall score clamps to 0..10000.
	 *
	 * @return void
	 */
	public function testWeightedOverallClamps(): void {
		$service = $this->makeService(objectService: new InMemoryObjectServiceStub());

		self::assertSame(0, $service->weightedOverall(onTimeBp: -10000, qtyBp: 0, priceBp: 0, invoiceBp: 0));
		self::assertSame(10000, $service->weightedOverall(onTimeBp: 20000, qtyBp: 20000, priceBp: 0, invoiceBp: 0));
		self::assertSame(7000, $service->weightedOverall(onTimeBp: 10000, qtyBp: 10000, priceBp: 0, invoiceBp: 0));

	}//end testWeightedOverallClamps()

	/**
	 * A non-numeric prior overallScore reads as zero for the trend.
	 *
	 * @return void
	 */
	public function testScoreTrendTreatsNonNumericPriorAsZero(): void {
		$service = $this->makeService(objectService: new InMemoryObjectServiceStub());

		self::assertSame('improving', $service->computeScoreTrend(currentBp: 50, priorCard: ['overallScore' => 'n/a']));
		self::assertSame('stable', $service->computeScoreTrend(currentBp: 49, priorCard: ['overallScore' => ['x']]));
		self::assertSame('declining', $service->computeScoreTrend(currentBp: 0, priorCard: ['overallScore' => 50.9]));

	}//end testScoreTrendTreatsNonNumericPriorAsZero()

	/**
	 * A high score is still refused without scorecards, without any dated
	 * scorecard, or when the first period cannot be parsed.
	 *
	 * @return void
	 */
	public function testAutoReviewEligibleRefusals(): void {
		$noCards = $this->makeService(objectService: new InMemoryObjectServiceStub());
		self::assertFalse($noCards->setAutoReviewEligible(overallScoreBp: 9900, administrationId: 'adm-1', supplierId: 'sup-1'));

		$undated = $this->makeService(
			objectService: new InMemoryObjectServiceStub(
				data: ['VendorPerformance' => [['supplierId' => 'sup-1', 'administrationId' => 'adm-1', 'period' => '']]]
			)
		);
		self::assertFalse($undated->setAutoReviewEligible(overallScoreBp: 9900, administrationId: 'adm-1', supplierId: 'sup-1'));

		$malformed = $this->makeService(
			objectService: new InMemoryObjectServiceStub(
				data: [
					'VendorPerformance' => [
						['supplierId' => 'sup-1', 'administrationId' => 'adm-1', 'period' => '2020-13'],
						['supplierId' => 'sup-1', 'administrationId' => 'adm-1', 'period' => ''],
					],
				]
			)
		);
		self::assertFalse($malformed->setAutoReviewEligible(overallScoreBp: 9900, administrationId: 'adm-1', supplierId: 'sup-1'));

	}//end testAutoReviewEligibleRefusals()

	/**
	 * Relaxing a supplier profile clamps each tolerance at its ceiling and
	 * reads int, numeric-string and float values.
	 *
	 * @return void
	 */
	public function testAutoRelaxToleranceProfileClampsAtCeilings(): void {
		$os = new InMemoryObjectServiceStub(
			data: [
				'ToleranceProfile' => [
					[
						'id' => 'tp-1',
						'scope' => 'supplier',
						'scopeReference' => 'sup-1',
						'status' => 'active',
						'administrationId' => 'adm-1',
						'priceTolerancePercentage' => 990,
						'quantityTolerancePercentage' => '980',
						'dateToleranceDays' => 20.7,
					],
				],
			]
		);
		$service = $this->makeService(objectService: $os);

		$saved = $service->autoRelaxToleranceProfile(administrationId: 'adm-1', supplierId: 'sup-1');

		self::assertSame('tp-1', $saved['id']);
		self::assertSame(1000, $saved['priceTolerancePercentage']);
		self::assertSame(1000, $saved['quantityTolerancePercentage']);
		self::assertSame(14, $saved['dateToleranceDays']);
		self::assertCount(1, $os->saved);
		self::assertSame('ToleranceProfile', $os->saved[0]['schema']);

	}//end testAutoRelaxToleranceProfileClampsAtCeilings()

	/**
	 * Empty inputs aggregate nothing and read nothing.
	 *
	 * @return void
	 */
	public function testAggregateWithEmptyInputsReturnsNothing(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->expects($this->never())->method('findAll');
		$service = $this->makeService(objectService: $os);

		self::assertSame([], $service->aggregateAdministrationForPeriod(administrationId: '', period: '2026-05'));
		self::assertSame([], $service->aggregateAdministrationForPeriod(administrationId: 'adm-1', period: ''));

	}//end testAggregateWithEmptyInputsReturnsNothing()

	/**
	 * A quarter period scores the supplier invoiced inside the quarter,
	 * trends against the prior quarter of the previous year, counts
	 * disputes and averages resolution days (negative spans count, malformed
	 * dates are skipped).
	 *
	 * @return void
	 */
	public function testQuarterAggregationScoresAgainstPriorQuarter(): void {
		$os = new InMemoryObjectServiceStub(
			data: [
				'SupplierInvoice' => [
					['id' => 'inv-1', 'supplierId' => 'sup-a', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-03-31'],
					['id' => 'inv-2', 'supplierId' => 'sup-a', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-04-01'],
					['id' => 'inv-3', 'supplierId' => 'sup-a', 'administrationId' => 'adm-1'],
					['id' => 'inv-4', 'supplierId' => '  ', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-01-10'],
				],
				'ThreeWayMatch' => [
					[
						'id' => 'm1', 'invoiceId' => 'inv-1', 'administrationId' => 'adm-1',
						'createdAt' => '2026-02-01T00:00:00Z', 'resolvedAt' => '2026-01-30T00:00:00Z',
						'matchStatus' => 'auto_approved', 'resolutionAction' => 'credit_note_requested',
					],
					[
						'id' => 'm2', 'invoiceId' => 'inv-1', 'administrationId' => 'adm-1',
						'createdAt' => '2026-01-15T00:00:00Z', 'resolvedAt' => 'not-a-date',
						'matchStatus' => 'within_tolerance',
					],
					[
						'id' => 'm3', 'invoiceId' => '', 'administrationId' => 'adm-1',
						'createdAt' => '2026-02-02T00:00:00Z', 'matchStatus' => 'auto_approved',
					],
					[
						'id' => 'm4', 'invoiceId' => 'inv-1', 'administrationId' => 'adm-1',
						'createdAt' => '2026-02-10T00:00:00Z', 'resolvedAt' => '2026-02-14T00:00:00Z',
						'matchStatus' => 'exception_price', 'resolutionAction' => 'supplier_contacted',
					],
				],
				'VendorPerformance' => [
					['id' => 'vp-prior', 'supplierId' => 'sup-a', 'administrationId' => 'adm-1', 'period' => '2025-Q4', 'overallScore' => 2000],
				],
			]
		);
		$service = $this->makeService(objectService: $os);

		$cards = $service->aggregateAdministrationForPeriod(administrationId: 'adm-1', period: '2026-Q1');

		self::assertCount(1, $cards);
		$card = $cards[0];
		self::assertSame('sup-a', $card['supplierId']);
		self::assertSame('2026-Q1', $card['period']);
		self::assertSame(0, $card['onTimeDeliveryRate']);
		self::assertSame(6666, $card['priceAccuracyRate']);
		self::assertSame(0, $card['invoiceAccuracyRate']);
		self::assertSame(2, $card['disputeCount']);
		self::assertSame(1, $card['averageResolutionDays']);
		self::assertSame(1333, $card['overallScore']);
		self::assertSame('declining', $card['scoreTrend']);
		self::assertFalse($card['automatedReviewEligible']);

	}//end testQuarterAggregationScoresAgainstPriorQuarter()

	/**
	 * January trends against December of the previous year, and an
	 * empty register slug falls back to 'shillinq'.
	 *
	 * @return void
	 */
	public function testJanuaryTrendsAgainstPreviousDecember(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->expects($this->atLeastOnce())->method('setRegister')->with('shillinq')->willReturnSelf();
		$schema = '';
		$os->method('setSchema')->willReturnCallback(
			static function (string $slug) use (&$schema, &$os) {
				$schema = $slug;
				return $os;
			}
		);
		$os->method('findAll')->willReturnCallback(
			static function (array $config) use (&$schema): array {
				if ($schema === 'VendorPerformance' && ($config['filters']['period'] ?? '') === '2025-12') {
					return [['id' => 'vp-dec', 'overallScore' => '100']];
				}

				return [];
			}
		);
		$os->expects($this->once())->method('saveObject')
			->with($this->callback(static fn (array $o): bool => $o['scoreTrend'] === 'declining' && $o['period'] === '2026-01'))
			->willReturn(new Support\ObjectEntityStub(payload: ['id' => 'vp-new', 'scoreTrend' => 'declining']));

		$service = $this->makeService(objectService: $os, register: '');
		$saved = $service->calculateMonthlyScore(administrationId: 'adm-1', supplierId: 'sup-a', period: '2026-01');

		self::assertSame(['id' => 'vp-new', 'scoreTrend' => 'declining'], $saved);

	}//end testJanuaryTrendsAgainstPreviousDecember()

	/**
	 * A failing scorecard save is logged per supplier and does not abort the
	 * aggregation; a failing query is logged and read as no rows.
	 *
	 * @return void
	 */
	public function testAggregationLogsPersistAndQueryFailures(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('setRegister')->willReturnSelf();
		$schema = '';
		$os->method('setSchema')->willReturnCallback(
			static function (string $slug) use (&$schema, &$os) {
				$schema = $slug;
				return $os;
			}
		);
		$os->method('findAll')->willReturnCallback(
			static function () use (&$schema): array {
				if ($schema === 'SupplierInvoice') {
					return [['id' => 'inv-1', 'supplierId' => 'sup-a', 'invoiceDate' => '2026-05-05']];
				}

				if ($schema === 'ThreeWayMatch') {
					throw new RuntimeException('match query broke');
				}

				return [];
			}
		);
		$os->method('saveObject')->willThrowException(new RuntimeException('disk full'));

		$logger = $this->createMock(LoggerInterface::class);
		$errors = [];
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$errors): void {
				$errors[] = [$message, $context];
			}
		);
		$logger->expects($this->once())->method('warning')->with(
			'VendorPerformanceAggregation: scorecard failed',
			[
				'administrationId' => 'adm-1',
				'supplierId' => 'sup-a',
				'period' => '2026-05',
				'exception' => 'Failed to persist VendorPerformance',
			]
		);

		$service = $this->makeService(objectService: $os, logger: $logger);
		$cards = $service->aggregateAdministrationForPeriod(administrationId: 'adm-1', period: '2026-05');

		self::assertSame([], $cards);
		self::assertContains(
			['VendorPerformanceAggregation: failed to query OpenRegister', ['schema' => 'ThreeWayMatch', 'exception' => 'match query broke']],
			$errors
		);
		self::assertContains(
			['VendorPerformanceAggregation: failed to persist object', ['schema' => 'VendorPerformance', 'exception' => 'disk full']],
			$errors
		);

	}//end testAggregationLogsPersistAndQueryFailures()
}//end class
