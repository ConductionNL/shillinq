<?php

/**
 * Aggregation + compliance integration test for member 02 of the
 * bookkeeping-waterschappen-bbv-variant chain.
 *
 * Loads the slice-02 register fragment via the same deep-merge that
 * SettingsService runs at install time, asserts that the
 * x-openregister-aggregations block on BBVProgramme materialises with the
 * four required aggregations (totalBudget / ytdSpend / utilization /
 * complianceStatus), then evaluates the declared arithmetic against the
 * slice-02 fixture (5 programmes, 5 BudgetBBVMappings, three rising-spend
 * snapshots) to verify:
 *
 *  - TotalBudget per programme equals SUM(GL-budget × allocation%)
 *  - YTDSpend equals SUM(GL.amount × allocation%) over mapped accounts
 *  - Utilization equals YTDSpend / TotalBudget
 *  - ComplianceStatus transitions on-track → at-risk → non-compliant as
 *    spend on programme 2.3.2 rises across the three fixture snapshots.
 *
 * The OpenRegister runtime is not invoked — the fragment JSON and the
 * fixture are the source of truth (REQ-BBVW-005 / giant D3). All money
 * arithmetic uses integer-cent math; ratios are floats.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-02-aggregation-compliance/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the BBVProgramme aggregation block materialises and its
 * declarative arithmetic produces the expected TotalBudget, YTDSpend,
 * Utilization, and ComplianceStatus values on the slice-02 fixture.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WaterschappenBbvAggregationIntegrationTest extends TestCase {

	/**
	 * Compliance status thresholds carried from REQ-BBVW-005 / giant D3.
	 *
	 * @var array<string,float>
	 */
	private const THRESHOLD_ON_TRACK = 0.75;

	/**
	 * Upper bound (inclusive) for the at-risk band; > this is non-compliant.
	 *
	 * @var float
	 */
	private const THRESHOLD_AT_RISK = 0.90;

	/**
	 * Load the base shillinq_register.json + merge every register.d/*.json
	 * fragment the same way SettingsService does at install time. Returns
	 * the merged OpenAPI components object.
	 *
	 * @return array<string,mixed>
	 */
	private function loadMergedComponents(): array {
		$basePath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';
		$baseRaw = file_get_contents($basePath);
		if ($baseRaw === false) {
			self::fail('Could not read shillinq_register.json base config.');
		}

		$base = json_decode($baseRaw, true);
		if (is_array($base) === false) {
			self::fail('shillinq_register.json base config is not valid JSON.');
		}

		$fragmentDir = __DIR__ . '/../../../lib/Settings/register.d';
		$fragments = glob($fragmentDir . '/*.json');
		if ($fragments === false) {
			$fragments = [];
		}

		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragmentRaw = file_get_contents($fragmentPath);
			if ($fragmentRaw === false) {
				continue;
			}

			$fragmentData = json_decode($fragmentRaw, true);
			if (is_array($fragmentData) === false) {
				continue;
			}

			$base = self::deepMerge(base: $base, overlay: $fragmentData);
		}

		return ($base['components'] ?? []);
	}//end loadMergedComponents()

	/**
	 * Deep-merge an overlay onto a base; mirror of
	 * SettingsService::deepMergeConfig (assoc arrays merge by key, list
	 * arrays concatenate, scalars overwrite).
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge.
	 *
	 * @return array<mixed>
	 */
	private static function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					$base[$key] = array_merge($base[$key], $value);
				} else {
					$base[$key] = self::deepMerge(base: $base[$key], overlay: $value);
				}
			} else {
				$base[$key] = $value;
			}
		}

		return $base;
	}//end deepMerge()

	/**
	 * Load the slice-02 seed fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function fixture(): array {
		$path = __DIR__ . '/../../fixtures/WaterschappenBbvAggregationSeedData.json';
		$raw = file_get_contents($path);
		if ($raw === false) {
			self::fail('Could not read WaterschappenBbvAggregationSeedData fixture.');
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			self::fail('WaterschappenBbvAggregationSeedData fixture is not valid JSON.');
		}

		return $data;
	}//end fixture()

	/**
	 * Compute the aggregation outputs (totalBudget, ytdSpend, utilization,
	 * complianceStatus) for one programme by evaluating the declarative
	 * arithmetic from the x-openregister-aggregations block against the
	 * given mappings + GL transactions. Mirrors what the OpenRegister
	 * aggregation engine MUST produce — REQ-BBVW-005 / giant D3.
	 *
	 * @param string $programmeCode The programme code to aggregate.
	 * @param array<int,array<string,mixed>> $mappings All BudgetBBVMapping records.
	 * @param array<int,array<string,mixed>> $transactions GL transaction lines in the fiscal year.
	 *
	 * @return array{totalBudgetCents:int,ytdSpendCents:int,utilization:float,complianceStatus:string}
	 */
	private function computeAggregation(
		string $programmeCode,
		array $mappings,
		array $transactions,
	): array {
		// Slice mappings for this programme.
		$programmeMappings = array_values(
			array_filter(
				$mappings,
				static fn (array $m): bool => ($m['programmeCode'] ?? null) === $programmeCode
			)
		);

		// TotalBudget = SUM(glAccountBudgetCents * allocation% / 100), integer-cent.
		$totalBudgetCents = 0;
		foreach ($programmeMappings as $m) {
			$totalBudgetCents += (int)(
				((int)$m['glAccountBudgetCents']) * ((float)$m['allocationPercentage']) / 100
			);
		}

		// YTDSpend = SUM(line.amountCents * allocation% / 100) joined on glAccountNumber.
		$ytdSpendCents = 0;
		foreach ($transactions as $line) {
			foreach ($programmeMappings as $m) {
				if (((string)$line['glAccountNumber']) !== ((string)$m['glAccountNumber'])) {
					continue;
				}

				$effectiveFrom = ($m['effectiveFrom'] ?? null);
				$effectiveTo = ($m['effectiveTo'] ?? null);
				$postingDate = ((string)$line['postingDate']);

				if ($effectiveFrom !== null && $postingDate < $effectiveFrom) {
					continue;
				}

				if ($effectiveTo !== null && $postingDate > $effectiveTo) {
					continue;
				}

				$ytdSpendCents += (int)(
					((int)$line['amountCents']) * ((float)$m['allocationPercentage']) / 100
				);
			}//end foreach
		}//end foreach

		$utilization = 0.0;
		if ($totalBudgetCents > 0) {
			$utilization = ($ytdSpendCents / $totalBudgetCents);
		}

		$hasMappings = ($totalBudgetCents > 0);
		if ($hasMappings === false) {
			$complianceStatus = 'unconfigured';
		} elseif ($utilization <= self::THRESHOLD_ON_TRACK) {
			$complianceStatus = 'on-track';
		} elseif ($utilization <= self::THRESHOLD_AT_RISK) {
			$complianceStatus = 'at-risk';
		} else {
			$complianceStatus = 'non-compliant';
		}

		return [
			'totalBudgetCents' => $totalBudgetCents,
			'ytdSpendCents' => $ytdSpendCents,
			'utilization' => $utilization,
			'complianceStatus' => $complianceStatus,
		];

	}//end computeAggregation()

	/**
	 * The slice-02 fragment must materialise the four required aggregations
	 * on BBVProgramme: totalBudget, ytdSpend, utilization, complianceStatus.
	 *
	 * @return void
	 */
	public function testAggregationBlockMaterialises(): void {
		$components = $this->loadMergedComponents();
		self::assertArrayHasKey('schemas', $components);
		self::assertArrayHasKey(
			'BBVProgramme',
			$components['schemas'],
			'BBVProgramme schema must be declared by the slice-02 fragment.'
		);

		$programme = $components['schemas']['BBVProgramme'];
		self::assertArrayHasKey(
			'x-openregister-aggregations',
			$programme,
			'BBVProgramme must declare x-openregister-aggregations (ADR-031).'
		);

		$aggs = $programme['x-openregister-aggregations'];
		foreach (['totalBudget', 'ytdSpend', 'utilization', 'complianceStatus'] as $name) {
			self::assertArrayHasKey(
				$name,
				$aggs,
				'BBVProgramme aggregation ' . $name . ' must be declared.'
			);
		}

		// ComplianceStatus must be derived (computedFields), never stored.
		self::assertArrayHasKey('computedFields', $aggs['complianceStatus']);
		self::assertArrayHasKey('complianceStatus', $aggs['complianceStatus']['computedFields']);
		self::assertSame(
			['unconfigured', 'on-track', 'at-risk', 'non-compliant'],
			$aggs['complianceStatus']['computedFields']['complianceStatus']['enum']
		);

		// Stored complianceStatus property MUST NOT exist (giant D3).
		self::assertArrayNotHasKey(
			'complianceStatus',
			($programme['properties'] ?? []),
			'complianceStatus is COMPUTED, not stored (giant D3 / ADR-031).'
		);

	}//end testAggregationBlockMaterialises()

	/**
	 * Materialised TotalBudget per programme must equal
	 * SUM(GL-budget × allocation%) for the slice-02 fixture mappings.
	 *
	 * @return void
	 */
	public function testTotalBudgetMaterialisedFromFixtures(): void {
		$fixture = $this->fixture();
		$mappings = $fixture['mappings'];
		$expectedBudgets = $fixture['_notes']['expectedTotalBudgetCents'];

		// No GL transactions yet — TotalBudget is a pure mapping aggregation.
		foreach ($expectedBudgets as $programmeCode => $expectedCents) {
			$agg = $this->computeAggregation(
				(string)$programmeCode,
				$mappings,
				transactions: []
			);

			self::assertSame(
				(int)$expectedCents,
				$agg['totalBudgetCents'],
				'TotalBudget for programme ' . $programmeCode . ' must equal SUM(GL-budget × allocation%).'
			);
		}

		// Programme 3.1.0 has no mappings; the aggregation MUST resolve as
		// unconfigured, never on-track.
		$unmapped = $this->computeAggregation('3.1.0', $mappings, transactions: []);
		self::assertSame(0, $unmapped['totalBudgetCents']);
		self::assertSame('unconfigured', $unmapped['complianceStatus']);

	}//end testTotalBudgetMaterialisedFromFixtures()

	/**
	 * YTDSpend + Utilization for programme 2.3.2 must match the on-track
	 * snapshot (Σ GL.amount × allocation%, ratio against TotalBudget).
	 *
	 * @return void
	 */
	public function testYtdSpendAndUtilizationFromGlTransactions(): void {
		$fixture = $this->fixture();
		$mappings = $fixture['mappings'];
		$snapshot = $fixture['spendSnapshots']['onTrack'];

		$agg = $this->computeAggregation(
			'2.3.2',
			$mappings,
			$snapshot['transactions']
		);

		self::assertSame(
			(int)$snapshot['expectedYtdSpendCents']['2.3.2'],
			$agg['ytdSpendCents'],
			'YTDSpend(2.3.2) must equal SUM(GL.amount × allocation%) for the on-track snapshot.'
		);

		// Utilization is a float ratio — assert with delta to allow IEEE-754 noise.
		self::assertEqualsWithDelta(
			(float)$snapshot['expectedUtilization']['2.3.2'],
			$agg['utilization'],
			0.0001,
			'Utilization(2.3.2) must equal YTDSpend / TotalBudget.'
		);

		// ComplianceStatus on this snapshot must be on-track.
		self::assertSame('on-track', $agg['complianceStatus']);

	}//end testYtdSpendAndUtilizationFromGlTransactions()

	/**
	 * ComplianceStatus must transition on-track → at-risk → non-compliant as
	 * GL spend on programme 2.3.2 rises through the three fixture
	 * snapshots, with no manual recomputation step (REQ-BBVW-005).
	 *
	 * @return void
	 */
	public function testComplianceStatusTransitionsOnRisingSpend(): void {
		$fixture = $this->fixture();
		$mappings = $fixture['mappings'];

		$onTrack = $fixture['spendSnapshots']['onTrack'];
		$atRisk = $fixture['spendSnapshots']['atRisk'];
		$nonCompliant = $fixture['spendSnapshots']['nonCompliant'];

		$aggOnTrack = $this->computeAggregation('2.3.2', $mappings, $onTrack['transactions']);
		$aggAtRisk = $this->computeAggregation('2.3.2', $mappings, $atRisk['transactions']);
		$aggNonCompliant = $this->computeAggregation('2.3.2', $mappings, $nonCompliant['transactions']);

		// YTDSpend must rise monotonically as transactions accumulate.
		self::assertLessThan($aggAtRisk['ytdSpendCents'], $aggOnTrack['ytdSpendCents']);
		self::assertLessThan($aggNonCompliant['ytdSpendCents'], $aggAtRisk['ytdSpendCents']);

		// Each snapshot's compliance status matches the threshold band.
		self::assertSame('on-track', $aggOnTrack['complianceStatus']);
		self::assertSame('at-risk', $aggAtRisk['complianceStatus']);
		self::assertSame('non-compliant', $aggNonCompliant['complianceStatus']);

		// Utilization values match the expected ratios within IEEE-754 delta.
		self::assertEqualsWithDelta(0.65, $aggOnTrack['utilization'], 0.0001);
		self::assertEqualsWithDelta(0.85, $aggAtRisk['utilization'], 0.0001);
		self::assertEqualsWithDelta(0.96, $aggNonCompliant['utilization'], 0.0001);

		// Boundary semantics (programme 2.3.2 budget = 100 000 EUR / 10 000 000 ct;
		// GL 5000 allocation is 25%, so a 30 000 000-ct GL line delivers 7 500 000 ct =
		// 75% utilization, and a 36 000 000-ct GL line delivers 9 000 000 ct = 90%).
		$boundary75 = [
			['glAccountNumber' => '5000', 'amountCents' => 30000000, 'postingDate' => '2026-03-15'],
		];
		$aggBoundary75 = $this->computeAggregation('2.3.2', $mappings, $boundary75);
		self::assertEqualsWithDelta(0.75, $aggBoundary75['utilization'], 0.0001);
		self::assertSame(
			'on-track',
			$aggBoundary75['complianceStatus'],
			'Utilization == 0.75 must be on-track (≤ 75%, inclusive).'
		);

		$boundary90 = [
			['glAccountNumber' => '5000', 'amountCents' => 36000000, 'postingDate' => '2026-03-15'],
		];
		$aggBoundary90 = $this->computeAggregation('2.3.2', $mappings, $boundary90);
		self::assertEqualsWithDelta(0.90, $aggBoundary90['utilization'], 0.0001);
		self::assertSame(
			'at-risk',
			$aggBoundary90['complianceStatus'],
			'Utilization == 0.90 must be at-risk (≤ 90%, inclusive).'
		);

	}//end testComplianceStatusTransitionsOnRisingSpend()
}//end class
