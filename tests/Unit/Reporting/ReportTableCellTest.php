<?php

/**
 * Unit tests for ReportTableCell: text written through a cell lands in its own table slot.
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
 * @covers \OCA\Shillinq\Reporting\ReportTableCell
 * @covers \OCA\Shillinq\Reporting\ReportTable
 */
final class ReportTableCellTest extends TestCase {

	public function testAddTextFillsOnlyItsOwnCellAndDefaultsTheStyle(): void {
		$table = new ReportTable('ledger');
		$first = $table->addCell(3.5, ['bgColor' => 'eeeeee']);
		$second = $table->addCell(2);
		$table->addRow();
		$third = $table->addCell();

		self::assertSame($first, $first->addText('Revenue'));
		$third->addText('1.000,00', 'amount', ['alignment' => 'right']);
		$second->addText('Q1', 'header');

		self::assertSame(
			[
				'type' => 'table',
				'styleKey' => 'ledger',
				'rows' => [
					[
						['widthCm' => 3.5, 'cellStyle' => ['bgColor' => 'eeeeee'], 'text' => 'Revenue', 'textStyle' => 'value', 'textOptions' => []],
						['widthCm' => 2, 'cellStyle' => [], 'text' => 'Q1', 'textStyle' => 'header', 'textOptions' => []],
					],
					[
						['widthCm' => 0, 'cellStyle' => [], 'text' => '1.000,00', 'textStyle' => 'amount', 'textOptions' => ['alignment' => 'right']],
					],
				],
			],
			$table->toArray()
		);
	}

	public function testAStringOptionBecomesAParagraphStyleReference(): void {
		$table = new ReportTable();
		$table->addCell()->addText('Total', 'label', 'pRight');

		$cell = $table->toArray()['rows'][0][0];
		self::assertSame('label', $cell['textStyle']);
		self::assertSame(['style' => 'pRight'], $cell['textOptions']);
		self::assertSame('reportTable', $table->toArray()['styleKey']);
	}

	public function testWritingTwiceKeepsTheLastText(): void {
		$table = new ReportTable();
		$table->addCell()->addText('draft')->addText('final', 'bold');

		self::assertSame('final', $table->toArray()['rows'][0][0]['text']);
		self::assertSame('bold', $table->toArray()['rows'][0][0]['textStyle']);
	}
}
