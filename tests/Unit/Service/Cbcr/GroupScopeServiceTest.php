<?php

/**
 * Unit tests for GroupScopeService: the EUR 750M scope rules for the
 * country-by-country report and the minimum tax, and the deadlines.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/specs/bookkeeping-cbcr-pillar2/spec.md#requirement-req-cbc-001-drempelwaarde-detectie-eur-750m
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Cbcr;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Shillinq\Service\Cbcr\GroupScopeService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The service over an in-memory register.
 *
 * @covers \OCA\Shillinq\Service\Cbcr\GroupScopeService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GroupScopeServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The service over a group whose statements carry these revenues.
	 *
	 * @param array<int,float> $revenue      Consolidated revenue per year.
	 * @param string           $yearEnd      The group's fiscal year end.
	 * @param string           $upeAdmin     The UPE's administration.
	 *
	 * @return GroupScopeService
	 */
	private function service(array $revenue, string $yearEnd = '2025-12-31', string $upeAdmin = 'adm-holding'): GroupScopeService {
		$statements = [];
		foreach ($revenue as $year => $amount) {
			// An interim statement first, then the year-end one that counts.
			$statements[] = ['id' => 'cis-' . $year . '-q2', 'consolidationGroupId' => 'grp-1', 'reportDate' => $year . '-03-31', 'totalRevenue' => ($amount / 2)];
			$statements[] = ['id' => 'cis-' . $year, 'consolidationGroupId' => 'grp-1', 'reportDate' => $year . substr($yearEnd, 4), 'totalRevenue' => $amount];
		}

		$this->store = new class([
			'GroupEntityRegistry' => [['id' => 'upe-1', 'entityName' => 'Voorbeeld Holding B.V.', 'administrationId' => $upeAdmin, 'thresholdCrossed' => null]],
			'ConsolidationGroup' => [['id' => 'grp-1', 'groupName' => 'Voorbeeld', 'parentAdministrationId' => 'adm-holding', 'fiscalYearEnd' => $yearEnd]],
			'ConsolidatedIncomeStatement' => $statements,
		]) {
			/**
			 * Rows per schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $rows;

			/**
			 * Saves made, in order: schema and uuid.
			 *
			 * @var array<int,array{0:string,1:?string}>
			 */
			public array $saves = [];

			/**
			 * The selected schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $rows The rows.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}//end __construct()

			/**
			 * Select the register (ignored).
			 *
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Select the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * The rows whose fields equal every filter.
			 *
			 * @param array<string,mixed> $config The query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config): array {
				$filters = (array)($config['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Store a row: in place when the uuid names one, else new.
			 *
			 * @param array<string,mixed> $object The row.
			 * @param string|null         $uuid   The id to update.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, ?string $uuid = null): array {
				$this->saves[] = [$this->schema, $uuid];
				foreach (($this->rows[$this->schema] ?? []) as $index => $row) {
					if ($uuid !== null && ($row['id'] ?? null) === $uuid) {
						$this->rows[$this->schema][$index] = ['id' => $uuid] + $object;
						return $this->rows[$this->schema][$index];
					}
				}

				$stored = ['id' => ($uuid ?? strtolower($this->schema) . '-' . (count($this->rows[$this->schema] ?? []) + 1))] + $object;
				$this->rows[$this->schema][] = $stored;
				return $stored;
			}//end saveObject()
		};

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new GroupScopeService(new DuckObjectServiceAdapter($this->store), $config);
	}//end service()

	/**
	 * The assessments stored.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function assessments(): array {
		return ($this->store->rows['GroupScopeAssessment'] ?? []);
	}//end assessments()

	/**
	 * "Groep overschrijdt EUR 750M-drempel in FY2025": EUR 720M in 2024 and
	 * EUR 805M in 2025 put 2026 in scope for the country-by-country report,
	 * due 31 December 2027, but not yet for the minimum tax.
	 *
	 * @return void
	 */
	public function testCrossingTheThresholdBringsTheCbcReportOnly(): void {
		$service = $this->service(revenue: [2024 => 720000000.0, 2025 => 805000000.0]);

		$assessment = $service->assess('upe-1', 2026, [], 'tax.director', new DateTimeImmutable('2026-02-10T09:00:00+00:00'));

		$this->assertTrue($assessment['cbcrInScope']);
		$this->assertFalse($assessment['pillar2InScope']);
		$this->assertSame(2026, $assessment['firstCbcrYear']);
		$this->assertNull($assessment['firstPillar2Year']);
		$this->assertSame('2027-12-31', $assessment['cbcrFilingDeadline']);
		$this->assertNull($assessment['girFilingDeadline']);
		$this->assertNull($assessment['bijheffingReturnDeadline']);
		$this->assertSame(['2026-01-01', '2026-12-31'], [$assessment['fiscalYearStart'], $assessment['fiscalYearEnd']]);
		$this->assertSame(
			[
				['year' => 2021, 'amount' => null, 'source' => 'none'],
				['year' => 2022, 'amount' => null, 'source' => 'none'],
				['year' => 2023, 'amount' => null, 'source' => 'none'],
				['year' => 2024, 'amount' => 720000000.0, 'source' => 'consolidated-statements'],
				['year' => 2025, 'amount' => 805000000.0, 'source' => 'consolidated-statements'],
				['year' => 2026, 'amount' => null, 'source' => 'none'],
			],
			$assessment['revenueByYear']
		);
		$this->assertSame(['tax.director', '2026-02-10T09:00:00+00:00'], [$assessment['assessedBy'], $assessment['assessedAt']]);

		$upe = $this->store->rows['GroupEntityRegistry'][0];
		$this->assertTrue($upe['thresholdCrossed']);
		$this->assertSame('2026-02-10T09:00:00+00:00', $upe['thresholdCrossedAt']);
	}//end testCrossingTheThresholdBringsTheCbcReportOnly()

	/**
	 * "Pillar Two follows two years in four": with EUR 790M in 2026 the
	 * minimum tax applies to 2027 as its first year, so the GloBE information
	 * return is due 30 June 2029 (18 months) and the top-up tax return 31
	 * August 2029 (20 months); the year before is handed back for comparison.
	 *
	 * @return void
	 */
	public function testTwoYearsInFourBringTheMinimumTaxWithFirstYearTerms(): void {
		$service = $this->service(revenue: [2024 => 720000000.0, 2025 => 805000000.0, 2026 => 790000000.0]);
		$service->assess('upe-1', 2026, [], 'system', new DateTimeImmutable('2026-02-10T09:00:00+00:00'));

		$assessment = $service->assess('upe-1', 2027, [], 'system', new DateTimeImmutable('2027-02-10T09:00:00+00:00'));

		$this->assertTrue($assessment['pillar2InScope']);
		$this->assertSame(2027, $assessment['firstPillar2Year']);
		$this->assertSame(2026, $assessment['firstCbcrYear']);
		$this->assertSame('2029-06-30', $assessment['girFilingDeadline']);
		$this->assertSame('2029-08-31', $assessment['bijheffingReturnDeadline']);
		$this->assertSame('2028-12-31', $assessment['cbcrFilingDeadline']);
		$this->assertFalse($assessment['previous']['pillar2InScope']);
		$this->assertSame(2026, $assessment['previous']['fiscalYear']);

		$later = $service->assess('upe-1', 2028, [2027 => 810000000.0], 'system', new DateTimeImmutable('2028-02-10T09:00:00+00:00'));
		$this->assertSame(2027, $later['firstPillar2Year']);
		$this->assertSame('2030-03-31', $later['girFilingDeadline'], 'after the first year: 15 months');
		$this->assertSame('2030-05-31', $later['bijheffingReturnDeadline'], 'after the first year: 17 months');
		$this->assertSame(['year' => 2027, 'amount' => 810000000.0, 'source' => 'entered'], $later['revenueByYear'][4]);
	}//end testTwoYearsInFourBringTheMinimumTaxWithFirstYearTerms()

	/**
	 * A group under the threshold is in scope for nothing and has no deadlines;
	 * reassessing the same year updates the stored assessment in place and
	 * keeps the amounts entered before.
	 *
	 * @return void
	 */
	public function testAGroupUnderTheThresholdIsOutOfScopeAndReassessmentUpdatesInPlace(): void {
		$service = $this->service(revenue: [2025 => 400000000.0]);

		$first = $service->assess('upe-1', 2026, [2024 => 900000000.0], 'system', new DateTimeImmutable('2026-02-10T09:00:00+00:00'));
		$this->assertFalse($first['cbcrInScope']);
		$this->assertFalse($first['pillar2InScope']);
		$this->assertNull($first['cbcrFilingDeadline']);
		$this->assertNull($first['firstCbcrYear']);

		$again = $service->assess('upe-1', 2026, [], 'system', new DateTimeImmutable('2026-03-10T09:00:00+00:00'));
		$this->assertCount(1, $this->assessments());
		$this->assertSame(['year' => 2024, 'amount' => 900000000.0, 'source' => 'entered'], $again['revenueByYear'][3]);
		$this->assertSame(['year' => 2025, 'amount' => 400000000.0, 'source' => 'consolidated-statements'], $again['revenueByYear'][4]);
		$this->assertFalse($this->store->rows['GroupEntityRegistry'][0]['thresholdCrossed']);
		$this->assertSame(['GroupScopeAssessment', $first['id']], $this->store->saves[2]);
	}//end testAGroupUnderTheThresholdIsOutOfScopeAndReassessmentUpdatesInPlace()

	/**
	 * A broken fiscal year (ending 30 June) runs its deadlines from 30 June;
	 * statements count for the fiscal year their date falls in.
	 *
	 * @return void
	 */
	public function testABrokenFiscalYearRunsFromItsOwnEnd(): void {
		$service = $this->service(revenue: [2025 => 800000000.0], yearEnd: '2025-06-30');

		$assessment = $service->assess('upe-1', 2026, [], 'system', new DateTimeImmutable('2025-09-01T09:00:00+00:00'));

		$this->assertSame(['2025-07-01', '2026-06-30'], [$assessment['fiscalYearStart'], $assessment['fiscalYearEnd']]);
		$this->assertTrue($assessment['cbcrInScope']);
		$this->assertSame('2027-06-30', $assessment['cbcrFilingDeadline']);
	}//end testABrokenFiscalYearRunsFromItsOwnEnd()

	/**
	 * Without a consolidation group the entered amounts decide; an unknown
	 * ultimate parent is refused.
	 *
	 * @return void
	 */
	public function testWithoutStatementsTheEnteredRevenueDecides(): void {
		$service = $this->service(revenue: [], upeAdmin: 'adm-unconsolidated');

		$assessment = $service->assess('upe-1', 2026, [2025 => 750000000.0, 2024 => 760000000.0], 'system', new DateTimeImmutable('2026-02-10T09:00:00+00:00'));

		$this->assertTrue($assessment['cbcrInScope']);
		$this->assertTrue($assessment['pillar2InScope'], 'exactly EUR 750M counts');
		$this->assertSame(2025, $assessment['firstCbcrYear']);
		$this->assertSame(2026, $assessment['firstPillar2Year']);

		$this->expectException(InvalidArgumentException::class);
		$service->assess('upe-unknown', 2026);
	}//end testWithoutStatementsTheEnteredRevenueDecides()
}//end class
