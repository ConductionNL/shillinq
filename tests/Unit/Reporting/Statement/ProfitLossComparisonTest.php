<?php

/**
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Reporting\Statement
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-financial-statements
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Statement;

use OCA\Shillinq\Reporting\ProfitLossComparison;
use PHPUnit\Framework\TestCase;

/**
 * The compared profit and loss (REQ-RFS-001).
 *
 * @covers \OCA\Shillinq\Reporting\ProfitLossComparison
 * @uses   \OCA\Shillinq\Reporting\StatementCalculator
 * @uses   \OCA\Shillinq\Reporting\StatementPeriods
 * @uses   \OCA\Shillinq\Service\FiscalYearContextService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class ProfitLossComparisonTest extends TestCase {

	/**
	 * A director compares September year to date with the same period last year.
	 *
	 * @return void
	 */
	public function testSeptemberYearToDateAgainstLastYear(): void {
		$comparison = new ProfitLossComparison(VanDijkLedger::calculator($this, VanDijkLedger::rows()));

		$statement = $comparison->compare(VanDijkLedger::ADM, '2026-01-01', '2026-09-30', ['previous-year']);

		self::assertSame(
			[['key' => 'current', 'from' => '2026-01-01', 'to' => '2026-09-30'], ['key' => 'previous-year', 'from' => '2025-01-01', 'to' => '2025-09-30']],
			$statement['columns']
		);
		self::assertSame(['amounts' => ['current' => 180000.0, 'previous-year' => 150000.0], 'differences' => ['previous-year' => 30000.0]], $statement['totals']['revenue']);
		self::assertSame(['amounts' => ['current' => 48000.0, 'previous-year' => 30000.0], 'differences' => ['previous-year' => 18000.0]], $statement['totals']['result']);
		self::assertSame(
			[
				'accountNumber' => '4100',
				'label' => '4100 Huisvesting',
				'amounts' => ['current' => 36000.0, 'previous-year' => 30000.0],
				'ranges' => ['current' => ['from' => '2026-01-01', 'to' => '2026-09-30'], 'previous-year' => ['from' => '2025-01-01', 'to' => '2025-09-30']],
				'differences' => ['previous-year' => 6000.0],
			],
			$statement['expense'][1]
		);
	}

	/**
	 * An account found only in a comparison column shows zero in the others.
	 *
	 * @return void
	 */
	public function testAnAccountOnlyInOneColumnShowsZeroElsewhere(): void {
		$comparison = new ProfitLossComparison(VanDijkLedger::calculator($this, VanDijkLedger::rows()));

		$statement = $comparison->compare(VanDijkLedger::ADM, '2025-04-01', '2025-06-30', ['previous-period', 'previous-year']);

		self::assertSame(['current', 'previous-period', 'previous-year'], array_column($statement['columns'], 'key'));
		self::assertSame(['4000', '4100'], array_column($statement['expense'], 'accountNumber'));
		self::assertSame(['current' => 90000.0, 'previous-period' => 0.0, 'previous-year' => 0.0], $statement['expense'][0]['amounts']);
		self::assertSame(['previous-period' => 90000.0, 'previous-year' => 90000.0], $statement['expense'][0]['differences']);
	}

	/**
	 * Without comparisons there is one column and no differences.
	 *
	 * @return void
	 */
	public function testWithoutComparisons(): void {
		$comparison = new ProfitLossComparison(VanDijkLedger::calculator($this, VanDijkLedger::rows()));

		$statement = $comparison->compare(VanDijkLedger::ADM, '2026-01-01', '2026-09-30', []);

		self::assertCount(1, $statement['columns']);
		self::assertSame(['amounts' => ['current' => 132000.0], 'differences' => []], $statement['totals']['expense']);
		self::assertSame(VanDijkLedger::ADM, $statement['administrationId']);
	}
}
