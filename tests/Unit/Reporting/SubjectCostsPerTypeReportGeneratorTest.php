<?php

/**
 * Unit tests for the "Zaakkosten per zaaktype" report.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\CostSource\ClassifiedSubjectCostSource;
use OCA\Shillinq\Reporting\CostSource\ReportPeriod;
use OCA\Shillinq\Reporting\CostSource\SubjectCostGrouping;
use OCA\Shillinq\Reporting\CostSource\SubjectCostReport;
use OCA\Shillinq\Reporting\CostSource\SubjectCostSourceConfiguration;
use OCA\Shillinq\Reporting\CostSource\TaskFieldRollup;
use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\Generator\Iv3ReportGenerator;
use OCA\Shillinq\Reporting\Generator\SubjectCostsPerTypeReportGenerator;
use OCA\Shillinq\Reporting\ReportCatalogue;
use OCA\Shillinq\Service\SubjectCostAggregator;
use OCA\Shillinq\Service\SubjectCostService;
use OCA\Shillinq\Support\FleetAppId;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCA\Shillinq\Tests\Unit\Reporting\CostSource\CostSourceWorld;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for SubjectCostsPerTypeReportGenerator.
 */
#[CoversClass(SubjectCostsPerTypeReportGenerator::class)]
#[CoversClass(SubjectCostReport::class)]
#[CoversClass(SubjectCostGrouping::class)]
#[UsesClass(ClassifiedSubjectCostSource::class)]
#[UsesClass(TaskFieldRollup::class)]
#[UsesClass(SubjectCostSourceConfiguration::class)]
#[UsesClass(ReportPeriod::class)]
#[UsesClass(SubjectCostService::class)]
#[UsesClass(SubjectCostAggregator::class)]
#[UsesClass(FleetAppId::class)]
#[UsesClass(ObjectIdentifier::class)]
#[UsesClass(GeneratedFile::class)]
#[UsesClass(Iv3ReportGenerator::class)]
#[UsesClass(ReportCatalogue::class)]
final class SubjectCostsPerTypeReportGeneratorTest extends TestCase {

	private CostSourceWorld $world;

	protected function setUp(): void {
		$this->world = new CostSourceWorld($this);
		$this->world->rates = ['ann' => 10000, 'bob' => 5000];
		$this->world->caseType('t-vth', 'Omgevingsvergunning', '8.3');
		$this->world->caseType('t-bzw', 'Bezwaar', null);
		$this->world->caseWithHours('c1', 't-vth', [['ann', 10.0, '2026-07-01']]);
		$this->world->caseWithHours('c2', 't-vth', [['ann', 2.5, '2026-08-01']]);
		$this->world->caseWithHours('c3', 't-bzw', [['bob', 8.0, '2026-09-01']]);
		$this->world->caseWithHours('c4', 't-bzw', [['nobody', 2.0, '2026-09-02']]);
	}//end setUp()

	/**
	 * The generator under test over the world.
	 *
	 * @return SubjectCostsPerTypeReportGenerator
	 */
	private function generator(): SubjectCostsPerTypeReportGenerator {
		return new SubjectCostsPerTypeReportGenerator($this->world->report());
	}//end generator()

	/**
	 * It is the catalogue's per-type report, in CSV.
	 *
	 * @return void
	 */
	public function testItIsTheCatalogueEntryInCsv(): void {
		self::assertSame('subject-costs-per-type', SubjectCostsPerTypeReportGenerator::reportType());
		self::assertSame(['csv'], SubjectCostsPerTypeReportGenerator::supportedFormats());
		self::assertSame('Zaakkosten per zaaktype', ReportCatalogue::byId('subject-costs-per-type')['label']);
	}//end testItIsTheCatalogueEntryInCsv()

	/**
	 * Cases are grouped by their case type, incomplete ones listed and not summed.
	 *
	 * @return void
	 */
	public function testCasesAreGroupedByTheirType(): void {
		$sections = $this->generator()->sections(['period' => '2026-Q3']);

		self::assertSame(
			[
				['typeId' => 't-bzw', 'typeTitle' => 'Bezwaar', 'objects' => 2, 'hours' => 10.0, 'costCents' => 40000, 'incomplete' => ['c4']],
				['typeId' => 't-vth', 'typeTitle' => 'Omgevingsvergunning', 'objects' => 2, 'hours' => 12.5, 'costCents' => 125000, 'incomplete' => []],
			],
			$sections[0]['groups']
		);
		self::assertSame(['objects' => 4, 'hours' => 22.5, 'costCents' => 165000, 'incomplete' => 1], $sections[0]['totals']);
	}//end testCasesAreGroupedByTheirType()

	/**
	 * Over a period the per-type total equals the Iv3 case cost section total.
	 *
	 * @return void
	 */
	public function testThePeriodTotalEqualsTheIv3CaseCostSection(): void {
		$iv3 = new Iv3ReportGenerator($this->createMock(ContainerInterface::class), new NullLogger(), $this->world->report());

		$perTaskField = $iv3->subjectCostsPerTaskField(['period' => '2026-Q3']);
		$perType = $this->generator()->sections(['period' => '2026-Q3']);

		self::assertSame($perType[0]['totals'], $perTaskField[0]['totals']);
		self::assertSame(['8.3', ''], array_column($perTaskField[0]['groups'], 'taskField'));
	}//end testThePeriodTotalEqualsTheIv3CaseCostSection()

	/**
	 * The CSV holds one line per type with period, type, cases, hours, cost and incomplete, then the total.
	 *
	 * @return void
	 */
	public function testTheGroupingExportsToCsv(): void {
		$file = $this->generator()->generate(['period' => '2026-Q3', 'administrationId' => 'adm-1'], 'csv');

		self::assertSame('subject-costs-per-type-2026-Q3.csv', $file->fileName);
		self::assertSame('text/csv', $file->mimeType);
		self::assertSame(
			"period,type,objects,hours,cost,incomplete\n"
			. "2026-Q3,Bezwaar,2,10.00,400.00,1\n"
			. "2026-Q3,Omgevingsvergunning,2,12.50,1250.00,0\n"
			. "2026-Q3,Total,4,22.50,1650.00,1\n",
			$file->content
		);
	}//end testTheGroupingExportsToCsv()

	/**
	 * A missing app is named, with no amount and no EUR 0.00.
	 *
	 * @return void
	 */
	public function testAMissingAppIsNamedNotShownAsZero(): void {
		foreach (['dossiq' => 'Case costs per case type come from Dossiq. Dossiq is not installed.', 'humaniq' => 'Hours come from Humaniq. Humaniq is not installed.'] as $missing => $message) {
			$this->world->installed = array_values(array_diff(['dossiq', 'humaniq'], [$missing]));

			$file = $this->generator()->generate(['period' => '2026-Q3'], 'csv');
			$sections = $this->generator()->sections(['period' => '2026-Q3']);

			self::assertSame("period,type,objects,hours,cost,incomplete\n2026-Q3,\"$message\"\n", $file->content);
			self::assertStringNotContainsString('0.00', $file->content);
			self::assertSame([], $sections[0]['groups']);
			self::assertNull($sections[0]['totals']);
		}
	}//end testAMissingAppIsNamedNotShownAsZero()

	/**
	 * An empty or odd period still renders a named file.
	 *
	 * @return void
	 */
	public function testAFileWithoutAPeriodIsStillNamed(): void {
		$file = $this->generator()->generate(['period' => ['2026'], 'administrationId' => 7], 'csv');

		self::assertSame('subject-costs-per-type.csv', $file->fileName);
		self::assertSame("period,type,objects,hours,cost,incomplete\n,Total,0,0.00,0.00,0\n", $file->content);
	}//end testAFileWithoutAPeriodIsStillNamed()
}//end class
