<?php

/**
 * Unit tests for ReportTable: rows and cells accumulate in order, and only an existing cell is ever filled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;


use OCA\Shillinq\Reporting\ReportTable;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Reporting\ReportTable
 * @uses   \OCA\Shillinq\Reporting\ReportTableCell
 */
final class ReportTableTest extends TestCase {

	public function testANewTableHasNoRows(): void {
		self::assertSame(['type' => 'table', 'styleKey' => 'reportTable', 'rows' => []], (new ReportTable())->toArray());
	}//end testANewTableHasNoRows()

	public function testAFirstCellStartsARowAndCellsAppendToTheLastRow(): void {
		$table = new ReportTable('ledger');
		$table->addCell(3.5, ['bgColor' => 'eeeeee']);
		$table->addCell();
		$table->addRow()->addRow();
		$table->addCell(2);

		$rows = $table->toArray()['rows'];

		self::assertCount(3, $rows);
		self::assertSame(
			[
				['widthCm' => 3.5, 'cellStyle' => ['bgColor' => 'eeeeee'], 'text' => '', 'textStyle' => 'value', 'textOptions' => []],
				['widthCm' => 0, 'cellStyle' => [], 'text' => '', 'textStyle' => 'value', 'textOptions' => []],
			],
			$rows[0]
		);
		self::assertSame([], $rows[1], 'an explicitly started row stays empty');
		self::assertSame(2, $rows[2][0]['widthCm']);
	}//end testAFirstCellStartsARowAndCellsAppendToTheLastRow()

	public function testFillingACellKeepsArrayOptionsAndWrapsAStringStyle(): void {
		$table = new ReportTable();
		$table->addCell();
		$table->addCell();

		$table->fillCell(0, 0, '1.000,00', 'amount', ['alignment' => 'end']);
		$table->fillCell(0, 1, 'Totaal', 'amountBold', 'tight');

		$row = $table->toArray()['rows'][0];
		self::assertSame(['1.000,00', 'amount', ['alignment' => 'end']], [$row[0]['text'], $row[0]['textStyle'], $row[0]['textOptions']]);
		self::assertSame(['Totaal', 'amountBold', ['style' => 'tight']], [$row[1]['text'], $row[1]['textStyle'], $row[1]['textOptions']]);
	}//end testFillingACellKeepsArrayOptionsAndWrapsAStringStyle()

	public function testFillingACellThatDoesNotExistChangesNothing(): void {
		$table = new ReportTable();
		$table->addCell();
		$before = $table->toArray();

		$table->fillCell(0, 1, 'x', 'value', []);
		$table->fillCell(5, 0, 'x', 'value', []);

		self::assertSame($before, $table->toArray());
	}//end testFillingACellThatDoesNotExistChangesNothing()
}//end class
