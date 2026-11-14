<?php

/**
 * Contract between the report generators and the report catalogue.
 *
 * ReportGenerationService finds a generator by the catalogue id its
 * reportType() declares, and narrows the requested format to the generator's
 * supportedFormats(). A generator whose type is not in the catalogue is never
 * reached, two generators with one type shadow each other, and a format the
 * catalogue does not offer can never be asked for.
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
 * @spec openspec/specs/reporting-report-library/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator;
use OCA\Shillinq\Reporting\Generator\BalanceSheetReportGenerator;
use OCA\Shillinq\Reporting\Generator\BbvJaarstukkenReportGenerator;
use OCA\Shillinq\Reporting\Generator\GeneralLedgerReportGenerator;
use OCA\Shillinq\Reporting\Generator\Iv3ReportGenerator;
use OCA\Shillinq\Reporting\Generator\ManagementLetterReportGenerator;
use OCA\Shillinq\Reporting\Generator\ProfitLossReportGenerator;
use OCA\Shillinq\Reporting\Generator\RuleAuditReportGenerator;
use OCA\Shillinq\Reporting\Generator\SaftReportGenerator;
use OCA\Shillinq\Reporting\Generator\TrialBalanceReportGenerator;
use OCA\Shillinq\Reporting\Generator\VatReturnReportGenerator;
use OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator;
use OCA\Shillinq\Reporting\ReportCatalogue;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every concrete generator answers one catalogue entry, in formats it offers.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\BalanceSheetReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\BbvJaarstukkenReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\GeneralLedgerReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\Iv3ReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\ManagementLetterReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\ProfitLossReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\RuleAuditReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\SaftReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\TrialBalanceReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\VatReturnReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Reporting\ReportCatalogue
 */
class ReportGeneratorCatalogueContractTest extends TestCase {

	/**
	 * The concrete generators, as ReportGenerationService discovers them.
	 *
	 * @var array<int, class-string<ReportGeneratorInterface>>
	 */
	private const GENERATORS = [
		AnnualAccountsReportGenerator::class,
		BalanceSheetReportGenerator::class,
		BbvJaarstukkenReportGenerator::class,
		GeneralLedgerReportGenerator::class,
		Iv3ReportGenerator::class,
		ManagementLetterReportGenerator::class,
		ProfitLossReportGenerator::class,
		RuleAuditReportGenerator::class,
		SaftReportGenerator::class,
		TrialBalanceReportGenerator::class,
		VatReturnReportGenerator::class,
		XafAuditfileGenerator::class,
	];

	/**
	 * The generators, one data set each.
	 *
	 * @return array<string, array{0: class-string<ReportGeneratorInterface>}>
	 */
	public static function generators(): array {
		$sets = [];
		foreach (self::GENERATORS as $class) {
			$sets[(new ReflectionClass($class))->getShortName()] = [$class];
		}

		return $sets;
	}//end generators()

	/**
	 * The list above is every instantiable generator in the directory, so no generator escapes the contract.
	 *
	 * @return void
	 */
	public function testTheListNamesEveryConcreteGenerator(): void {
		$found = [];
		foreach ((glob(__DIR__ . '/../../../lib/Reporting/Generator/*.php') ?: []) as $file) {
			$class = 'OCA\\Shillinq\\Reporting\\Generator\\' . basename($file, '.php');
			if (class_exists($class) === true
				&& in_array(ReportGeneratorInterface::class, class_implements($class), true) === true
				&& (new ReflectionClass($class))->isInstantiable() === true
			) {
				$found[] = $class;
			}
		}

		sort($found);
		$expected = self::GENERATORS;
		sort($expected);
		self::assertSame($expected, $found);
	}//end testTheListNamesEveryConcreteGenerator()

	/**
	 * A generator's report type is a catalogue entry, and the formats it renders are ones the entry offers.
	 *
	 * @param class-string<ReportGeneratorInterface> $class The generator.
	 *
	 * @dataProvider generators
	 *
	 * @return void
	 */
	public function testTheGeneratorAnswersACatalogueEntryInItsFormats(string $class): void {
		$entry = ReportCatalogue::byId($class::reportType());
		self::assertNotNull($entry, $class::reportType() . ' is not in the catalogue');

		$formats = $class::supportedFormats();
		self::assertNotSame([], $formats);
		self::assertSame([], array_values(array_diff($formats, $entry['formats'])));
	}//end testTheGeneratorAnswersACatalogueEntryInItsFormats()

	/**
	 * No two generators claim one report type, or one would shadow the other.
	 *
	 * @return void
	 */
	public function testEveryReportTypeHasOneGenerator(): void {
		$types = array_map(static fn (string $class): string => $class::reportType(), self::GENERATORS);

		self::assertSame($types, array_values(array_unique($types)));
	}//end testEveryReportTypeHasOneGenerator()
}//end class
