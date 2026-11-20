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

use InvalidArgumentException;
use OCA\Shillinq\Reporting\StatementPeriods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Statement date ranges and comparison columns.
 *
 * @covers \OCA\Shillinq\Reporting\StatementPeriods
 */
class StatementPeriodsTest extends TestCase {

	/**
	 * Comparison ranges.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array{from: string, to: string}}>
	 */
	public static function comparisonCases(): array {
		return [
			'year to date, last year' => ['previous-year', '2026-01-01', '2026-09-30', ['from' => '2025-01-01', 'to' => '2025-09-30']],
			'leap day, last year' => ['previous-year', '2024-02-29', '2024-02-29', ['from' => '2023-02-28', 'to' => '2023-02-28']],
			'quarter, previous period' => ['previous-period', '2026-07-01', '2026-09-30', ['from' => '2026-03-31', 'to' => '2026-06-30']],
			'one day, previous period' => ['previous-period', '2026-03-01', '2026-03-01', ['from' => '2026-02-28', 'to' => '2026-02-28']],
		];
	}

	/**
	 * Each comparison covers the expected range.
	 *
	 * @param string                           $kind     The comparison.
	 * @param string                           $from     Start.
	 * @param string                           $to       End.
	 * @param array{from: string, to: string}  $expected The range.
	 *
	 * @return void
	 */
	#[DataProvider('comparisonCases')]
	public function testComparisonRange(string $kind, string $from, string $to, array $expected): void {
		self::assertSame($expected, StatementPeriods::comparisonRange($kind, $from, $to));
	}

	/**
	 * Comparisons are read from a list, in a fixed order, and unknown ones refused.
	 *
	 * @return void
	 */
	public function testComparisons(): void {
		self::assertSame([], StatementPeriods::comparisons(''));
		self::assertSame(['previous-period', 'previous-year'], StatementPeriods::comparisons(' previous-year , previous-period '));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('compare takes previous-period and previous-year.');
		StatementPeriods::comparisons('previous-year,budget');
	}

	/**
	 * Malformed, impossible, reversed and over-long ranges are refused.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function refusedRanges(): array {
		return [
			'empty from' => ['', '2026-01-01', 'from must be a date as YYYY-MM-DD.'],
			'impossible date' => ['2026-02-30', '2026-03-01', 'from must be a date as YYYY-MM-DD.'],
			'bad to' => ['2026-01-01', '30-09-2026', 'to must be a date as YYYY-MM-DD.'],
			'reversed' => ['2026-09-30', '2026-01-01', 'to must not be before from.'],
			'too long' => ['2010-01-01', '2026-01-01', 'The range may span at most ten years.'],
		];
	}

	/**
	 * Each refused range names why.
	 *
	 * @param string $from    Start.
	 * @param string $to      End.
	 * @param string $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusedRanges')]
	public function testRefusedRanges(string $from, string $to, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		StatementPeriods::assertRange($from, $to);
	}

	/**
	 * A good range passes and a good date parses.
	 *
	 * @return void
	 */
	public function testAcceptedRangeAndDate(): void {
		StatementPeriods::assertRange('2026-01-01', '2026-09-30');
		self::assertSame('2026-09-30', StatementPeriods::date('2026-09-30', 'asOf')->format('Y-m-d'));
	}

	/**
	 * Period labels from the generate dialog.
	 *
	 * @return void
	 */
	public function testRangeOfLabel(): void {
		self::assertSame(['from' => '2026-01-01', 'to' => '2026-12-31'], StatementPeriods::rangeOfLabel('2026'));
		self::assertSame(['from' => '2026-07-01', 'to' => '2026-09-30'], StatementPeriods::rangeOfLabel('2026-Q3'));
		self::assertSame(['from' => '2024-02-01', 'to' => '2024-02-29'], StatementPeriods::rangeOfLabel('2024-02'));
		self::assertNull(StatementPeriods::rangeOfLabel('Q1'));
		self::assertNull(StatementPeriods::rangeOfLabel('2026-13'));
	}
}
