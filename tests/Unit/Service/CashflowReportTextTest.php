<?php

/**
 * Characterisation tests for the text report of CashflowPdfRenderer::render().
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
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CashflowPdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the complete text payload with every section, the scenario result
 * variants and the filename.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class CashflowReportTextTest extends TestCase {

	/**
	 * Every section, in order, line for line.
	 *
	 * @return void
	 */
	public function testFullPayload(): void {
		$result = (new CashflowPdfRenderer())->render(
			[
				'horizonId' => 'h-1',
				'horizonStart' => '2026-01-05',
				'horizonEnd' => '2026-04-05',
				'administrationId' => 'adm',
				'modelVersion' => 'v2',
				'rolledOn' => '2026-01-04',
			],
			[
				['weekNumber' => 2, 'inflows_total' => 10.5, 'outflows_total' => 3, 'netMovement' => 7.5, 'closingBalance' => 100, 'bufferStatus' => 'green'],
				[],
			],
			[
				'name' => 'Dip',
				'description' => 'Late payers',
				'result' => ['minBufferWeek' => 7, 'minBufferAmount' => -50, 'onderschrijdingBuffer' => true],
			],
			[['customerId' => 'cust-1', 'gemiddeldeAfwijking' => '+12d', 'reliabilityScore' => 0.875]],
			[['label' => 'Rent', 'frequency' => 'monthly', 'standardAmount' => 1200]]
		);

		self::assertMatchesRegularExpression('/^cashflow-h-1-\d{4}-\d{2}-\d{2}\.txt$/', $result['filename']);
		self::assertSame('text/plain; charset=utf-8', $result['mimeType']);
		self::assertSame(
			[
				'13-WEEK CASHFLOW FORECAST',
				'==========================',
				'',
				'Horizon: 2026-01-05 .. 2026-04-05',
				'Administration: adm',
				'Model: v2',
				'Rolled on: 2026-01-04',
				'',
				'WEEK-BY-WEEK SUMMARY',
				'--------------------',
				'Week   Inflows      Outflows     Net          Eind Saldo   Buffer',
				'2      10.50        3.00         7.50         100.00       green',
				'?      0.00         0.00         0.00         0.00         ?',
				'',
				'TOP CUSTOMERS (BETALINGSGEDRAG)',
				'--------------------------------',
				'cust-1                         avg offset +12d, confidence 0.88',
				'',
				'RECURRING COSTS',
				'---------------',
				'Rent                           monthly      1200.00 EUR (FIXED)',
				'',
				'SCENARIO: Dip',
				'---------',
				'Late payers',
				'Min buffer week: 7',
				'Min buffer bedrag: -50',
				'Buffer breached: YES',
				'',
				'METHODOLOGY',
				'-----------',
				'BTW: Belastingdienst calendar (Q1 due Apr 30, Q2 due Jul 31, Q3 due Oct 31, Q4 due Jan 31).',
				'IB/VPB: peilmaanden May/Sep/Nov, basis = prior-year aanslag x growth rate.',
				'AR projection: customer-specific 12-month rolling betalingsgedrag with confidence score.',
				'Recurring costs: declarative registry with CPI indexing on annual items.',
			],
			explode("\n", $result['payload'])
		);

	}//end testFullPayload()

	/**
	 * Scenario variants: not breached, and without a result block.
	 *
	 * @return void
	 */
	public function testScenarioVariants(): void {
		$renderer = new CashflowPdfRenderer();
		$notBreached = $renderer->render([], [], ['result' => ['onderschrijdingBuffer' => 'yes']])['payload'];
		self::assertStringContainsString(
			"SCENARIO: ?\n---------\n\nMin buffer week: ?\nMin buffer bedrag: ?\nBuffer breached: NO\n",
			$notBreached
		);

		$noResult = $renderer->render([], [], ['name' => 'S', 'result' => 'x'])['payload'];
		self::assertStringContainsString("SCENARIO: S\n---------\n\n\nMETHODOLOGY", $noResult);
		self::assertStringStartsWith('cashflow-horizon-', $renderer->render([], [])['filename']);

	}//end testScenarioVariants()
}//end class
