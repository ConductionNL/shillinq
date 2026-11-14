<?php

/**
 * Tests for the toelichting (notes) of the annual-accounts report.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Pins the notes blocks per statement shape.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AnnualAccountsNotesTest extends TestCase {

	/**
	 * The blocks buildNotes() writes for a statement.
	 *
	 * @param array<string,mixed> $statement The FinancialStatement.
	 *
	 * @return array<int,mixed>
	 */
	private function notes(array $statement): array {
		$generator = new AnnualAccountsReportGenerator($this->createMock(ContainerInterface::class));
		$method = new ReflectionMethod(AnnualAccountsReportGenerator::class, 'buildNotes');
		$method->setAccessible(true);
		$section = new ReportSection();
		$arguments = [$section, $statement];
		if ($method->getNumberOfParameters() === 3) {
			$arguments[] = 'EUR';
		}

		$method->invokeArgs($generator, $arguments);

		return $section->toArray();

	}//end notes()

	/**
	 * The text of every text block.
	 *
	 * @param array<int,mixed> $blocks The section blocks.
	 *
	 * @return list<string>
	 */
	private static function texts(array $blocks): array {
		return array_values(array_map(static fn (array $block): string => $block['text'], array_filter($blocks, static fn (array $block): bool => $block['type'] === 'text')));

	}//end texts()

	/**
	 * A Dutch statement with every optional note.
	 *
	 * @return void
	 */
	public function testAFullDutchStatement(): void {
		$blocks = $this->notes(
			[
				'jurisdiction' => 'nl', 'sizeClass' => 'small', 'priorYearComparatives' => true, 'itemsNetted' => 1,
				'components' => ['balance', 7], 'audited' => true, 'auditorType' => 'RA', 'preparedDate' => '2027-03-01',
				'adoptionDate' => '2027-05-01', 'filedDate' => '',
			]
		);

		self::assertSame(
			[
				'Deze jaarrekening is opgesteld op grond van Titel 9 Boek 2 BW, met toepassing van het regime voor de grootteklasse "small".',
				'De posten zijn voorzien van vergelijkende cijfers over het voorgaande boekjaar (art. 2:363 BW).',
				'Er is gebruikgemaakt van saldering van posten. Het bruto-principe (art. 2:363 BW) verbiedt saldering tenzij dit uitdrukkelijk is toegestaan.',
				'Aanwezige onderdelen: balance, 7.',
			],
			self::texts($blocks)
		);
		self::assertSame(
			[
				['type' => 'textBreak', 'count' => 1],
				['type' => 'heading', 'text' => 'Controle en deponering', 'depth' => 2],
			],
			array_values(array_slice($blocks, 4, 2))
		);
		self::assertSame(
			['Gecontroleerd' => 'Ja', 'Accountantskwalificatie' => 'RA', 'Opgemaakt op' => '2027-03-01', 'Vastgesteld op' => '2027-05-01'],
			$blocks[6]['rows']
		);

	}//end testAFullDutchStatement()

	/**
	 * A foreign statement without optional notes; false comparatives say they are missing.
	 *
	 * @return void
	 */
	public function testAForeignStatementAndMissingComparatives(): void {
		$blocks = $this->notes(['jurisdiction' => 'BE', 'framework' => 'IFRS', 'priorYearComparatives' => false, 'itemsNetted' => false, 'components' => []]);

		self::assertSame(
			[
				'Deze jaarrekening is opgesteld op grond van het toepasselijke verslaggevingsstelsel (IFRS).',
				'Vergelijkende cijfers over het voorgaande boekjaar ontbreken; dit dient nog te worden aangevuld (art. 2:363 BW).',
			],
			self::texts($blocks)
		);
		self::assertSame(['Gecontroleerd' => 'Nee'], $blocks[4]['rows']);

	}//end testAForeignStatementAndMissingComparatives()

	/**
	 * An empty statement: the Dutch intro only; truthy non-true audited reads as not audited.
	 *
	 * @return void
	 */
	public function testAnEmptyStatement(): void {
		$blocks = $this->notes(['audited' => 1, 'components' => 'not-a-list']);

		self::assertSame(['Deze jaarrekening is opgesteld op grond van Titel 9 Boek 2 BW.'], self::texts($blocks));
		self::assertSame(['Gecontroleerd' => 'Nee'], $blocks[3]['rows']);
		self::assertCount(4, $blocks);

	}//end testAnEmptyStatement()
}//end class
