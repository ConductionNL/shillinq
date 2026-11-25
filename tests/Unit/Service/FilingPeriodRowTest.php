<?php

/**
 * Unit tests for FilingPeriodRow.
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
 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\FilingPeriodRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The period end and label of a return row.
 */
final class FilingPeriodRowTest extends TestCase {

	/**
	 * Rows with their expected period end and label.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: string}>
	 */
	public static function rows(): array {
		return [
			'quarter from its end' => [['periodType' => 'quarter', 'periodEnd' => '2026-06-30'], '2026-06-30', '2026-Q2'],
			'quarter from its numbers' => [['periodType' => 'quarter', 'periodYear' => 2026, 'periodQuarter' => 4], '2026-12-31', '2026-Q4'],
			'month from its numbers' => [['periodType' => 'month', 'periodYear' => 2026, 'periodMonth' => 2], '2026-02-28', '2026-02'],
			'month from its end' => [['periodType' => 'month', 'periodEnd' => '2026-03-31'], '2026-03-31', '2026-03'],
			'year' => [['periodType' => 'year', 'periodYear' => 2025], '2025-12-31', '2025'],
			'default type is quarter' => [['periodYear' => 2026, 'periodQuarter' => 1], '2026-03-31', '2026-Q1'],
			'the end wins over the numbers' => [
				['periodType' => 'quarter', 'periodYear' => 2026, 'periodQuarter' => 1, 'periodEnd' => '2026-04-30'],
				'2026-04-30',
				'2026-Q1',
			],
			'no year' => [['periodType' => 'quarter', 'periodQuarter' => 2], null, '-Q2'],
			'no quarter' => [['periodType' => 'quarter', 'periodYear' => 2026], null, '2026-Q0'],
			'month out of range' => [['periodType' => 'month', 'periodYear' => 2026, 'periodMonth' => 13], null, '2026-13'],
			'unparseable end' => [['periodType' => 'quarter', 'periodEnd' => 'soon'], null, '-Q0'],
		];

	}//end rows()

	/**
	 * The period end and the label match.
	 *
	 * @param array<string, mixed> $row   The return row.
	 * @param string|null          $end   The expected period end.
	 * @param string               $label The expected label.
	 *
	 * @return void
	 */
	#[DataProvider('rows')]
	public function testPeriodEndAndLabel(array $row, ?string $end, string $label): void {
		$period = new FilingPeriodRow();

		self::assertSame(expected: $end, actual: $period->periodEnd(row: $row));
		self::assertSame(expected: $label, actual: $period->label(row: $row));

	}//end testPeriodEndAndLabel()
}//end class
