<?php

/**
 * Unit tests for ReportSection: blocks are kept in insertion order and serialised with their options.
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


use OCA\Shillinq\Reporting\ReportSection;
use OCA\Shillinq\Reporting\ReportTable;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Reporting\ReportSection
 * @uses   \OCA\Shillinq\Reporting\ReportTable
 * @uses   \OCA\Shillinq\Reporting\ReportTableCell
 */
final class ReportSectionTest extends TestCase {

	public function testAnEmptySectionSerialisesToNoBlocks(): void {
		self::assertSame([], (new ReportSection())->toArray());
	}//end testAnEmptySectionSerialisesToNoBlocks()

	public function testEveryBlockKeepsItsDefaultsAndInsertionOrder(): void {
		$section = new ReportSection();

		$section->addCover('Jaarrekening 2026', '', ['Acme B.V.', 'KvK 12345678'])
			->addTitle('Balans')
			->addText('Toelichting')
			->addTextBreak()
			->addNote('Geen gegevens')
			->addDetailsTable(['Periode' => '2026', 'Valuta' => 'EUR']);

		self::assertSame(
			[
				['type' => 'cover', 'title' => 'Jaarrekening 2026', 'subtitle' => '', 'meta' => ['Acme B.V.', 'KvK 12345678']],
				['type' => 'heading', 'text' => 'Balans', 'depth' => 2],
				['type' => 'text', 'text' => 'Toelichting', 'style' => 'value', 'paragraphStyle' => 'default'],
				['type' => 'textBreak', 'count' => 1],
				['type' => 'note', 'text' => 'Geen gegevens'],
				['type' => 'detailsTable', 'rows' => ['Periode' => '2026', 'Valuta' => 'EUR']],
			],
			$section->toArray()
		);
	}//end testEveryBlockKeepsItsDefaultsAndInsertionOrder()

	public function testGivenStylesAndDepthsArePassedThrough(): void {
		$section = (new ReportSection())
			->addTitle('Jaarrekening', 1)
			->addText('1.234,00', 'amountBold', ['alignment' => 'end'])
			->addTextBreak(3);

		self::assertSame(
			[
				['type' => 'heading', 'text' => 'Jaarrekening', 'depth' => 1],
				['type' => 'text', 'text' => '1.234,00', 'style' => 'amountBold', 'paragraphStyle' => ['alignment' => 'end']],
				['type' => 'textBreak', 'count' => 3],
			],
			$section->toArray()
		);
	}//end testGivenStylesAndDepthsArePassedThrough()

	public function testAnAmountTableKeepsItsLinesTotalAndCurrency(): void {
		$lines = [['label' => 'Omzet', 'amount' => 1000.5], ['label' => 'Kosten', 'amount' => null, 'bold' => true]];

		$blocks = (new ReportSection())
			->addAmountTable('Post', 'Bedrag', $lines, ['label' => 'Totaal', 'amount' => 1000.5], 'EUR')
			->addAmountTable('Soort', 'Aantal', [], null, '')
			->toArray();

		self::assertSame(
			['type' => 'amountTable', 'labelHead' => 'Post', 'amountHead' => 'Bedrag', 'lines' => $lines, 'total' => ['label' => 'Totaal', 'amount' => 1000.5], 'currency' => 'EUR'],
			$blocks[0]
		);
		self::assertNull($blocks[1]['total']);
		self::assertSame('', $blocks[1]['currency'], 'a count column carries no currency');
	}//end testAnAmountTableKeepsItsLinesTotalAndCurrency()

	public function testATableIsSerialisedWhereItWasAddedWithItsLaterCells(): void {
		$section = new ReportSection();
		$section->addTitle('Grootboek');
		$table = $section->addTable('ledger');
		$section->addNote('Einde');

		$table->addCell(4)->addText('8000');

		self::assertInstanceOf(ReportTable::class, $table);
		$blocks = $section->toArray();
		self::assertSame(['heading', 'table', 'note'], array_column($blocks, 'type'));
		self::assertSame('ledger', $blocks[1]['styleKey']);
		self::assertSame('8000', $blocks[1]['rows'][0][0]['text'], 'a cell filled after the table was added still serialises');
		self::assertSame('reportTable', (new ReportSection())->addTable()->toArray()['styleKey']);
	}//end testATableIsSerialisedWhereItWasAddedWithItsLaterCells()
}//end class
