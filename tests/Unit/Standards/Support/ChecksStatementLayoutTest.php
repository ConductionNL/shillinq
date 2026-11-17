<?php

/**
 * Unit tests for the ChecksStatementLayout predicates.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Standards\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Support;

use OCA\Shillinq\Standards\Support\ChecksStatementLayout;
use PHPUnit\Framework\TestCase;

/**
 * A check provider stand-in exposing the trait's predicates.
 */
final class StatementLayoutProbe {
	use ChecksStatementLayout;

	/**
	 * Call one of the trait's predicates.
	 *
	 * @param string            $predicate The predicate name.
	 * @param array<int, mixed> $args      Its arguments.
	 *
	 * @return bool
	 */
	public static function check(string $predicate, mixed ...$args): bool {
		return self::$predicate(...$args);
	}//end check()
}//end class

/**
 * @covers \OCA\Shillinq\Standards\Support\ChecksStatementLayout
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class ChecksStatementLayoutTest extends TestCase {

	/**
	 * A balance sheet with all five numeric components is complete.
	 *
	 * @return void
	 */
	public function testABalanceSheetNeedsAllFiveNumericComponents(): void {
		$bs = ['fixedAssets' => 10, 'currentAssets' => '5.5', 'equity' => 7, 'provisions' => 0, 'liabilities' => 8.5];

		$this->assertTrue(StatementLayoutProbe::check('balanceLayoutComplete', ['balanceSheet' => $bs]));
		$this->assertFalse(StatementLayoutProbe::check('balanceLayoutComplete', ['balanceSheet' => ['provisions' => 'n/a'] + $bs]));
		unset($bs['liabilities']);
		$this->assertFalse(StatementLayoutProbe::check('balanceLayoutComplete', ['balanceSheet' => $bs]));
		$this->assertFalse(StatementLayoutProbe::check('balanceLayoutComplete', ['balanceSheet' => 'flat']));
		$this->assertFalse(StatementLayoutProbe::check('balanceLayoutComplete', []));
	}//end testABalanceSheetNeedsAllFiveNumericComponents()

	/**
	 * A profit-and-loss account needs its four numeric lines.
	 *
	 * @return void
	 */
	public function testAProfitAndLossNeedsItsFourNumericLines(): void {
		$pl = ['netTurnover' => 100, 'operatingExpenses' => 60, 'financialResult' => -2, 'result' => '38'];

		$this->assertTrue(StatementLayoutProbe::check('pnlLayoutComplete', ['profitAndLoss' => $pl]));
		$this->assertFalse(StatementLayoutProbe::check('pnlLayoutComplete', ['profitAndLoss' => ['result' => null] + $pl]));
		unset($pl['financialResult']);
		$this->assertFalse(StatementLayoutProbe::check('pnlLayoutComplete', ['profitAndLoss' => $pl]));
		$this->assertFalse(StatementLayoutProbe::check('pnlLayoutComplete', ['balanceSheet' => []]));
	}//end testAProfitAndLossNeedsItsFourNumericLines()

	/**
	 * Component codes match case-insensitively after trimming.
	 *
	 * @return void
	 */
	public function testComponentsMatchCaseInsensitivelyAfterTrimming(): void {
		$o = ['components' => [' Balance ', 'PNL']];

		$this->assertTrue(StatementLayoutProbe::check('hasComponent', $o, 'balance'));
		$this->assertTrue(StatementLayoutProbe::check('hasComponent', $o, 'pnl'));
		$this->assertFalse(StatementLayoutProbe::check('hasComponent', $o, 'notes'));
		$this->assertFalse(StatementLayoutProbe::check('hasComponent', ['components' => 'balance'], 'balance'));
	}//end testComponentsMatchCaseInsensitivelyAfterTrimming()
}//end class
