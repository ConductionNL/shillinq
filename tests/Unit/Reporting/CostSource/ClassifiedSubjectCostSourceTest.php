<?php

/**
 * Unit tests for the classified subject cost source and its per-taakveld section.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting\CostSource
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
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\CostSource;

use OCA\Shillinq\Reporting\CostSource\ClassifiedSubjectCostSource;
use OCA\Shillinq\Reporting\CostSource\ReportPeriod;
use OCA\Shillinq\Reporting\CostSource\SubjectCostGrouping;
use OCA\Shillinq\Reporting\CostSource\SubjectCostReport;
use OCA\Shillinq\Reporting\CostSource\SubjectCostSourceConfiguration;
use OCA\Shillinq\Reporting\CostSource\TaskFieldRollup;
use OCA\Shillinq\Service\SubjectCostAggregator;
use OCA\Shillinq\Service\SubjectCostService;
use OCA\Shillinq\Support\FleetAppId;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ClassifiedSubjectCostSource and SubjectCostReport::perTaskField().
 */
#[CoversClass(ClassifiedSubjectCostSource::class)]
#[CoversClass(SubjectCostReport::class)]
#[CoversClass(SubjectCostGrouping::class)]
#[CoversClass(TaskFieldRollup::class)]
#[CoversClass(SubjectCostSourceConfiguration::class)]
#[CoversClass(ReportPeriod::class)]
#[UsesClass(SubjectCostService::class)]
#[UsesClass(SubjectCostAggregator::class)]
#[UsesClass(FleetAppId::class)]
#[UsesClass(ObjectIdentifier::class)]
final class ClassifiedSubjectCostSourceTest extends TestCase {

	private CostSourceWorld $world;

	protected function setUp(): void {
		$this->world = new CostSourceWorld($this);
		$this->world->rates = ['ann' => 10000, 'bob' => 5000];
	}//end setUp()

	/**
	 * Old and new codes land in one row.
	 *
	 * @return void
	 */
	public function testARefinementCodeIsCountedUnderItsParent(): void {
		$this->world->caseType('t-old', 'Huishoudelijke hulp oud', '6.71');
		$this->world->caseType('t-new', 'Hulp bij het huishouden', '6.71a');
		$this->world->caseWithHours('c1', 't-old', [['ann', 10.0, '2026-07-10']]);
		$this->world->caseWithHours('c2', 't-new', [['bob', 10.0, '2026-08-01']]);

		$sections = $this->world->report()->perTaskField('2026-Q3');

		self::assertCount(1, $sections);
		self::assertNull($sections[0]['message']);
		self::assertSame(
			[['taskField' => '6.71', 'types' => [], 'objects' => 2, 'hours' => 20.0, 'costCents' => 150000, 'incomplete' => []]],
			$sections[0]['groups']
		);
		self::assertSame(['objects' => 2, 'hours' => 20.0, 'costCents' => 150000, 'incomplete' => 0], $sections[0]['totals']);
	}//end testARefinementCodeIsCountedUnderItsParent()

	/**
	 * An unpriced hour withholds the cost, not the case.
	 *
	 * @return void
	 */
	public function testAnIncompleteCostCountsHoursAndIsListedNotSummed(): void {
		$this->world->caseType('t-vth', 'Omgevingsvergunning', '8.3');
		$this->world->caseWithHours('c1', 't-vth', [['ann', 4.0, '2026-09-30']]);
		$this->world->caseWithHours('c2', 't-vth', [['nobody', 3.0, '2026-07-01'], ['ann', 1.0, '2026-07-01']]);

		$groups = $this->world->report()->perTaskField('2026-Q3')[0]['groups'];

		self::assertSame(
			[['taskField' => '8.3', 'types' => [], 'objects' => 2, 'hours' => 8.0, 'costCents' => 40000, 'incomplete' => ['c2']]],
			$groups
		);
	}//end testAnIncompleteCostCountsHoursAndIsListedNotSummed()

	/**
	 * Types without a taakveld form one row naming them; it sorts last.
	 *
	 * @return void
	 */
	public function testTypesWithoutATaskFieldFormOneRowNamingThem(): void {
		$this->world->caseType('t-a', 'Bezwaar', null);
		$this->world->caseType('t-b', 'Klacht', '');
		$this->world->caseType('t-c', 'Omgevingsvergunning', '8.3');
		$this->world->caseWithHours('c1', 't-a', [['ann', 1.0, '2026-07-01']]);
		$this->world->caseWithHours('c2', 't-b', [['ann', 1.0, '2026-07-01']]);
		$this->world->caseWithHours('c3', 't-a', [['ann', 1.0, '2026-07-01']]);
		$this->world->caseWithHours('c4', 't-c', [['ann', 1.0, '2026-07-01']]);

		$groups = $this->world->report()->perTaskField('2026-Q3')[0]['groups'];

		self::assertSame(['8.3', ''], array_column($groups, 'taskField'));
		self::assertSame(['Bezwaar', 'Klacht'], $groups[1]['types']);
		self::assertSame(3, $groups[1]['objects']);
		self::assertSame(30000, $groups[1]['costCents']);
	}//end testTypesWithoutATaskFieldFormOneRowNamingThem()

	/**
	 * Only the period's hours of the source app's objects count, costed at the period's rate.
	 *
	 * @return void
	 */
	public function testOnlyThePeriodsHoursOfTheSourceAppCount(): void {
		$this->world->caseType('t', 'Omgevingsvergunning', '8.3');
		$this->world->caseWithHours('c1', 't', [['ann', 2.0, '2026-06-30'], ['ann', 3.0, '2026-07-01T09:00:00+02:00'], ['ann', 4.0, '2026-10-01']]);
		$this->world->hours[] = ['employeeId' => 'ann', 'hours' => 50, 'date' => '2026-07-02', 'administrationId' => 'adm-1', 'domainObjectRef' => 'p1', 'domainObjectType' => 'pipelinq:lead'];
		$this->world->hours[] = ['employeeId' => 'ann', 'hours' => 50, 'date' => 'gisteren', 'administrationId' => 'adm-1', 'domainObjectRef' => 'c1', 'domainObjectType' => 'dossiq:case'];

		$rows = $this->world->source()->read('2026-Q3')[0]['rows'];

		self::assertSame(
			[['objectId' => 'c1', 'typeId' => 't', 'typeTitle' => 'Omgevingsvergunning', 'taskField' => '8.3', 'hours' => 3.0, 'costCents' => 30000, 'complete' => true]],
			$rows
		);
		self::assertSame(['2026-09'], $this->world->ratePeriods);
	}//end testOnlyThePeriodsHoursOfTheSourceAppCount()

	/**
	 * Another administration's hours are not the caller's to see.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsHoursAreLeftOut(): void {
		$this->world->caseType('t', 'Omgevingsvergunning', '8.3');
		$this->world->caseWithHours('c1', 't', [['ann', 2.0, '2026-07-01']]);

		self::assertSame([], $this->world->source()->read('2026-Q3', ['adm-2'])[0]['rows']);
		self::assertCount(1, $this->world->source()->read('2026-Q3', ['adm-1'])[0]['rows']);
	}//end testAnotherAdministrationsHoursAreLeftOut()

	/**
	 * A case that cannot be read still counts, without a type or taakveld.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseCountsWithoutAType(): void {
		$this->world->caseWithHours('c1', 'gone', [['ann', 2.0, '2026-07-01']]);
		unset($this->world->objects['dossiq/case']['c1']);

		$rows = $this->world->source()->read('2026-Q3')[0]['rows'];

		self::assertSame(['', '', ''], [$rows[0]['typeId'], $rows[0]['typeTitle'], $rows[0]['taskField']]);
		self::assertSame(20000, $rows[0]['costCents']);
	}//end testAnUnreadableCaseCountsWithoutAType()

	/**
	 * Dossiq is not installed: the section names it and shows no amount.
	 *
	 * @return void
	 */
	public function testAMissingSourceAppIsNamedAndShowsNoFigure(): void {
		$this->world->installed = ['humaniq'];
		$this->world->caseType('t', 'Omgevingsvergunning', '8.3');
		$this->world->caseWithHours('c1', 't', [['ann', 2.0, '2026-07-01']]);

		$report = $this->world->report();
		$sections = $report->perTaskField('2026-Q3');

		self::assertSame(
			[['source' => 'dossiq-case', 'message' => 'Case costs per task field come from Dossiq. Dossiq is not installed.', 'groups' => [], 'totals' => null]],
			$sections
		);
		self::assertSame(
			[
				['subjectCostsPerTaskField', 'objects', 'hours', 'cost', 'incomplete', 'types'],
				['Case costs per task field come from Dossiq. Dossiq is not installed.'],
			],
			$report->taskFieldCsvLines($sections)
		);
	}//end testAMissingSourceAppIsNamedAndShowsNoFigure()

	/**
	 * Humaniq is not installed: the hours app is named.
	 *
	 * @return void
	 */
	public function testAMissingHoursAppIsNamed(): void {
		$this->world->installed = ['dossiq'];

		self::assertSame('Hours come from Humaniq. Humaniq is not installed.', $this->world->report()->perTaskField('2026-Q3')[0]['message']);
	}//end testAMissingHoursAppIsNamed()

	/**
	 * An unreadable period reads no rows.
	 *
	 * @return void
	 */
	public function testAnUnreadablePeriodReadsNothing(): void {
		$this->world->caseType('t', 'Omgevingsvergunning', '8.3');
		$this->world->caseWithHours('c1', 't', [['ann', 2.0, '2026-07-01']]);

		self::assertSame([], $this->world->source()->read('volgend kwartaal')[0]['rows']);
	}//end testAnUnreadablePeriodReadsNothing()

	/**
	 * The CSV lines carry the rows, the no-taakveld row by its label.
	 *
	 * @return void
	 */
	public function testTheCsvLinesHoldTheRows(): void {
		$this->world->caseType('t-a', 'Bezwaar', null);
		$this->world->caseType('t-b', 'Wmo', '6.71b');
		$this->world->caseWithHours('c1', 't-a', [['ann', 1.5, '2026-07-01']]);
		$this->world->caseWithHours('c2', 't-b', [['nobody', 1.0, '2026-07-01']]);

		$report = $this->world->report();

		self::assertSame(
			[
				['subjectCostsPerTaskField', 'objects', 'hours', 'cost', 'incomplete', 'types'],
				['6.71', '1', '1.00', '0.00', '1', ''],
				['No task field', '1', '1.50', '150.00', '0', 'Bezwaar'],
			],
			$report->taskFieldCsvLines($report->perTaskField('2026-Q3'))
		);
	}//end testTheCsvLinesHoldTheRows()

	/**
	 * Periods are a year, a quarter or a month.
	 *
	 * @return void
	 */
	public function testPeriodsAreAYearAQuarterOrAMonth(): void {
		self::assertSame(['2026-01-01', '2026-12-31'], ReportPeriod::range('2026'));
		self::assertSame(['2026-10-01', '2026-12-31'], ReportPeriod::range('2026-q4'));
		self::assertSame(['2028-02-01', '2028-02-29'], ReportPeriod::range('2028-02'));
		self::assertNull(ReportPeriod::range('2026-13'));
		self::assertNull(ReportPeriod::range('2026-Q5'));
		self::assertFalse(ReportPeriod::contains(20260701, ['2026-07-01', '2026-09-30']));
		self::assertTrue(ReportPeriod::contains('2026-09-30T23:00:00Z', ['2026-07-01', '2026-09-30']));
	}//end testPeriodsAreAYearAQuarterOrAMonth()

	/**
	 * An incomplete source entry or an unreadable file configures nothing.
	 *
	 * @return void
	 */
	public function testAnIncompleteSourceIsLeftOut(): void {
		$file = tempnam(sys_get_temp_dir(), 'src');
		$complete = array_fill_keys(SubjectCostSourceConfiguration::REQUIRED, 'x');
		file_put_contents($file, json_encode(['sources' => [$complete, array_merge($complete, ['schema' => ' ']), 'junk']]));

		self::assertSame([$complete], (new SubjectCostSourceConfiguration($file))->sources());
		self::assertSame([], (new SubjectCostSourceConfiguration($file . '-missing'))->sources());
		unlink($file);

		$shipped = (new SubjectCostSourceConfiguration())->sources();
		self::assertSame(['dossiq', 'dossiq', 'case', 'caseType', 'caseType', 'iv3TaskField'], [$shipped[0]['app'], $shipped[0]['register'], $shipped[0]['schema'], $shipped[0]['typeSchema'], $shipped[0]['typeProperty'], $shipped[0]['taskFieldProperty']]);
	}//end testAnIncompleteSourceIsLeftOut()
}//end class
