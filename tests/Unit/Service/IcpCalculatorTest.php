<?php

/**
 * Unit tests for IcpCalculator.
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IcpCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the side-effect-free ICP arithmetic and transform helper.
 *
 * Covers REQ-ICP-002 (periodicity threshold), REQ-ICP-003 (aggregation + credit
 * note signs), REQ-ICP-004 (reconciliation tolerance), REQ-ICP-005 (XBRL
 * composition), REQ-ICP-006 (triangulation separation), Task 22 (account routing)
 * and REQ-ICP-010 (audit CSV).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IcpCalculatorTest extends TestCase {

	/**
	 * The calculator under test.
	 *
	 * @var IcpCalculator
	 */
	private IcpCalculator $calc;

	/**
	 * Set up the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new IcpCalculator();

	}//end setUp()

	/**
	 * Mixed goods and services to the same buyer produce two lines (REQ-ICP-003).
	 *
	 * @return void
	 */
	public function testAggregatesMixedGoodsAndServices(): void {
		$supplies = [
			['buyerVatId' => 'BE0123456789', 'supplyType' => 'L', 'amountExclVat' => 20000.0],
			['buyerVatId' => 'BE0123456789', 'supplyType' => 'L', 'amountExclVat' => 10000.0],
			['buyerVatId' => 'BE0123456789', 'supplyType' => 'S', 'amountExclVat' => 5000.0],
		];

		$lines = $this->calc->aggregateLines(supplies: $supplies);

		self::assertCount(2, $lines);
		self::assertSame('BE0123456789', $lines[0]['buyerVatId']);
		self::assertSame('L', $lines[0]['supplyType']);
		self::assertSame(30000.0, $lines[0]['amountExclVat']);
		self::assertSame('S', $lines[1]['supplyType']);
		self::assertSame(5000.0, $lines[1]['amountExclVat']);

	}//end testAggregatesMixedGoodsAndServices()

	/**
	 * Credit notes (negative amounts) net against invoices with sign preserved (REQ-ICP-003).
	 *
	 * @return void
	 */
	public function testCreditNoteSignPreserved(): void {
		$supplies = [
			['buyerVatId' => 'BE0123456789', 'supplyType' => 'L', 'amountExclVat' => 1000.0],
			['buyerVatId' => 'BE0123456789', 'supplyType' => 'L', 'amountExclVat' => -500.0],
		];

		$lines = $this->calc->aggregateLines(supplies: $supplies);

		self::assertCount(1, $lines);
		self::assertSame(500.0, $lines[0]['amountExclVat']);

	}//end testCreditNoteSignPreserved()

	/**
	 * Triangulation lines are never merged with goods, even for the same buyer (REQ-ICP-006).
	 *
	 * @return void
	 */
	public function testTriangulationReportedSeparately(): void {
		$supplies = [
			['buyerVatId' => 'FR0123456789', 'supplyType' => 'L', 'amountExclVat' => 10000.0],
			['buyerVatId' => 'FR0123456789', 'supplyType' => 'T', 'amountExclVat' => 5000.0],
		];

		$lines = $this->calc->aggregateLines(supplies: $supplies);

		self::assertCount(2, $lines);
		$byType = [];
		foreach ($lines as $line) {
			$byType[$line['supplyType']] = $line['amountExclVat'];
		}

		self::assertSame(10000.0, $byType['L']);
		self::assertSame(5000.0, $byType['T']);

	}//end testTriangulationReportedSeparately()

	/**
	 * Totals fold triangulation into goods and report it separately (REQ-ICP-002, REQ-ICP-006).
	 *
	 * @return void
	 */
	public function testTotals(): void {
		$lines = [
			['buyerVatId' => 'BE0123456789', 'supplyType' => 'L', 'amountExclVat' => 25000.0],
			['buyerVatId' => 'DE0987654321', 'supplyType' => 'S', 'amountExclVat' => 12500.0],
			['buyerVatId' => 'FR0555666777', 'supplyType' => 'T', 'amountExclVat' => 5000.0],
		];

		$totals = $this->calc->totals(lines: $lines);

		self::assertSame(42500.0, $totals['total']);
		self::assertSame(30000.0, $totals['totalGoods']);
		self::assertSame(12500.0, $totals['totalServices']);
		self::assertSame(5000.0, $totals['totalTriangulation']);

	}//end testTotals()

	/**
	 * The EUR 50,000 goods threshold is breached only above the limit (REQ-ICP-002).
	 *
	 * @return void
	 */
	public function testPeriodicityBreachAtThreshold(): void {
		$below = $this->calc->periodicityBreach(
			quarterSupplies: [['supplyType' => 'L', 'amountExclVat' => 49800.0]]
		);
		self::assertFalse($below['breached']);

		// Exactly EUR 50,000 is not a breach (strictly greater).
		$exact = $this->calc->periodicityBreach(
			quarterSupplies: [['supplyType' => 'L', 'amountExclVat' => 50000.0]]
		);
		self::assertFalse($exact['breached']);

		$over = $this->calc->periodicityBreach(
			quarterSupplies: [
				['supplyType' => 'L', 'amountExclVat' => 49800.0],
				['supplyType' => 'T', 'amountExclVat' => 300.0],
			]
		);
		self::assertTrue($over['breached']);
		self::assertSame(50100.0, $over['goodsCumulative']);

	}//end testPeriodicityBreachAtThreshold()

	/**
	 * Services do not count toward the goods threshold (REQ-ICP-002).
	 *
	 * @return void
	 */
	public function testServicesDoNotCountTowardThreshold(): void {
		$decision = $this->calc->periodicityBreach(
			quarterSupplies: [['supplyType' => 'S', 'amountExclVat' => 80000.0]]
		);
		self::assertFalse($decision['breached']);
		self::assertSame(0.0, $decision['goodsCumulative']);

	}//end testServicesDoNotCountTowardThreshold()

	/**
	 * Reconciliation matches exactly and within the EUR 1 tolerance (REQ-ICP-004).
	 *
	 * @return void
	 */
	public function testReconciliationWithinTolerance(): void {
		self::assertTrue($this->calc->reconcile(icpTotal: 87450.12, rubriek3b: 87450.12)['matches']);
		self::assertTrue($this->calc->reconcile(icpTotal: 87450.50, rubriek3b: 87450.00)['matches']);

	}//end testReconciliationWithinTolerance()

	/**
	 * Reconciliation fails beyond tolerance and flags a missing BTW-aangifte (REQ-ICP-004).
	 *
	 * @return void
	 */
	public function testReconciliationMismatchAndMissing(): void {
		$mismatch = $this->calc->reconcile(icpTotal: 87450.12, rubriek3b: 87200.00);
		self::assertFalse($mismatch['matches']);
		self::assertFalse($mismatch['missing']);
		self::assertSame(25012, $mismatch['differenceCents']);

		$missing = $this->calc->reconcile(icpTotal: 1000.0, rubriek3b: null);
		self::assertFalse($missing['matches']);
		self::assertTrue($missing['missing']);

	}//end testReconciliationMismatchAndMissing()

	/**
	 * Supply types route to their zero-rated RGS accounts (Task 22).
	 *
	 * @return void
	 */
	public function testAccountRouting(): void {
		self::assertSame('8190', $this->calc->accountForSupplyType(supplyType: 'L'));
		self::assertSame('8195', $this->calc->accountForSupplyType(supplyType: 'S'));
		self::assertSame('8196', $this->calc->accountForSupplyType(supplyType: 'T'));
		self::assertSame('', $this->calc->accountForSupplyType(supplyType: 'X'));

	}//end testAccountRouting()

	/**
	 * The composed XBRL is well-formed, carries the period and one element per line (REQ-ICP-005).
	 *
	 * @return void
	 */
	public function testComposeXbrlWellFormed(): void {
		$lines = [
			['buyerVatId' => 'BE0123456789', 'supplyType' => 'L', 'amountExclVat' => 30000.0],
			['buyerVatId' => 'BE0123456789', 'supplyType' => 'S', 'amountExclVat' => 5000.0],
		];

		$xml = $this->calc->composeXbrl(period: '2026-Q2', filerVatId: 'NL0987654321', lines: $lines);

		$doc = new \DOMDocument();
		$loaded = $doc->loadXML($xml);
		self::assertTrue($loaded, 'composed XBRL must be well-formed XML');
		self::assertStringContainsString('<gl-cor:periodIdentifier>2026-Q2</gl-cor:periodIdentifier>', $xml);
		self::assertSame(
			2,
			substr_count($xml, '<bd-i:IntracommunautairePrestatieMutatieSpecificatie>'),
			'one element per aggregated line'
		);
		self::assertStringContainsString('<bd-i:supplyCode>L</bd-i:supplyCode>', $xml);
		self::assertStringContainsString('<bd-i:supplyCode>S</bd-i:supplyCode>', $xml);
		self::assertStringContainsString('<bd-i:amount>30000.00</bd-i:amount>', $xml);

	}//end testComposeXbrlWellFormed()

	/**
	 * The audit-trail CSV carries a header plus one row per supply with VIES request IDs (REQ-ICP-010).
	 *
	 * @return void
	 */
	public function testBuildSuppliesCsv(): void {
		$supplies = [
			[
				'invoiceId' => 'INV-001',
				'buyerVatId' => 'BE0123456789',
				'supplyType' => 'L',
				'amountExclVat' => 25000.0,
				'viesValidationId' => 'vies-be',
			],
			[
				'invoiceId' => 'INV-002',
				'buyerVatId' => 'DE0987654321',
				'supplyType' => 'S',
				'amountExclVat' => 12500.0,
				'viesValidationId' => 'vies-de',
			],
		];
		$requestIds = ['vies-be' => 'BE-2026-001', 'vies-de' => 'DE-2026-001'];

		$csv = $this->calc->buildSuppliesCsv(supplies: $supplies, requestIds: $requestIds);
		$rows = array_values(array_filter(explode("\n", $csv)));

		self::assertCount(3, $rows);
		self::assertStringContainsString('invoiceRef', $rows[0]);
		self::assertStringContainsString('viesRequestId', $rows[0]);
		self::assertStringContainsString('BE-2026-001', $rows[1]);
		self::assertStringContainsString('"25000.00"', $rows[1]);

	}//end testBuildSuppliesCsv()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
