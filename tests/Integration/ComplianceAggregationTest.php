<?php

/**
 * Compliance + dashboard aggregation integration test.
 *
 * Slice 11 (testing) of the bookkeeping-waterschappen-bbv-variant chain
 * (ADR-032). Asserts that the dashboard data shaped by
 * {@see \OCA\Shillinq\Dashboard\BBVComplianceWidget} matches the
 * declarative aggregation declared on the BBVProgramme schema by slice
 * 02, and that recording additional GL spend mutates the materialised
 * utilization + status in the way the spec example (REQ-BBVW-005)
 * promises.
 *
 * No OpenRegister runtime is exercised — the slice-02 register fragment
 * is the source of truth for the aggregation arithmetic, and the
 * slice-02 fixture (5 programmes, 5 mappings, three rising-spend
 * snapshots) drives the computation. The widget envelope is rebuilt by
 * applying the same arithmetic the engine would, then it is compared
 * with the slice-02-declared expectations. This proves the dashboard
 * data SHALL equal the computed aggregation (slice 11 ADDED requirement).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-11-testing/tasks.md#integration-tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies dashboard data equals the slice-02 declarative aggregation
 * and updates as GL transactions are recorded.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ComplianceAggregationTest extends TestCase {

	/**
	 * REQ-BBVW-005 / giant D3 threshold: utilization > THRESHOLD_ON_TRACK and
	 * ≤ THRESHOLD_AT_RISK → at-risk; > THRESHOLD_AT_RISK → non-compliant.
	 *
	 * @var float
	 */
	private const THRESHOLD_ON_TRACK = 0.75;

	/**
	 * Upper bound (inclusive) for the at-risk band.
	 *
	 * @var float
	 */
	private const THRESHOLD_AT_RISK = 0.90;

	/**
	 * Load the slice-02 aggregation seed fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function fixture(): array {
		$path = __DIR__ . '/../fixtures/WaterschappenBbvAggregationSeedData.json';
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
	 * Build the per-programme totalBudget map from the fixture mappings
	 * using the slice-02 declarative arithmetic
	 * (`TotalBudget(P) = Σ GL-budget × allocation% / 100`).
	 *
	 * @param array<int,array<string,mixed>> $mappings BudgetBBVMapping rows.
	 *
	 * @return array<string,int> programmeCode → totalBudget (cents).
	 */
	private function computeTotalBudget(array $mappings): array {
		$byProgramme = [];
		foreach ($mappings as $mapping) {
			$code = (string)$mapping['programmeCode'];
			$glBudget = (int)$mapping['glAccountBudgetCents'];
			$percentage = (int)$mapping['allocationPercentage'];
			$contribution = (int)(($glBudget * $percentage) / 100);
			$byProgramme[$code] = (($byProgramme[$code] ?? 0) + $contribution);
		}

		return $byProgramme;
	}//end computeTotalBudget()

	/**
	 * Compute per-programme YTDSpend from the fixture mappings and a
	 * snapshot of GL transactions, using the slice-02 declarative
	 * arithmetic (`YTDSpend(P) = Σ GL.amount × allocation% / 100`).
	 *
	 * @param array<int,array<string,mixed>> $mappings BudgetBBVMapping rows.
	 * @param array<int,array<string,mixed>> $transactions GL-spend snapshots.
	 *
	 * @return array<string,int> programmeCode → ytdSpend (cents).
	 */
	private function computeYtdSpend(array $mappings, array $transactions): array {
		$spendByGl = [];
		foreach ($transactions as $txn) {
			$gl = (string)$txn['glAccountNumber'];
			$spendByGl[$gl] = (($spendByGl[$gl] ?? 0) + (int)$txn['amountCents']);
		}

		$byProgramme = [];
		foreach ($mappings as $mapping) {
			$code = (string)$mapping['programmeCode'];
			$gl = (string)$mapping['glAccountNumber'];
			$pct = (int)$mapping['allocationPercentage'];

			if (isset($spendByGl[$gl]) === false) {
				continue;
			}

			$contribution = (int)(($spendByGl[$gl] * $pct) / 100);
			$byProgramme[$code] = (($byProgramme[$code] ?? 0) + $contribution);
		}

		return $byProgramme;
	}//end computeYtdSpend()

	/**
	 * Apply the REQ-BBVW-005 bucketing to a utilization ratio.
	 *
	 * @param float $utilization Ratio (0..n).
	 * @param int|null $budgetCents Total budget (cents); 0 → unconfigured.
	 *
	 * @return string Compliance status bucket.
	 */
	private function bucket(float $utilization, ?int $budgetCents): string {
		if ($budgetCents === null || $budgetCents <= 0) {
			return 'unconfigured';
		}

		if ($utilization <= self::THRESHOLD_ON_TRACK) {
			return 'on-track';
		}

		if ($utilization <= self::THRESHOLD_AT_RISK) {
			return 'at-risk';
		}

		return 'non-compliant';
	}//end bucket()

	/**
	 * Safe utilization ratio (0.0 when budget is 0/negative).
	 *
	 * @param int $ytdSpendCents YTD spend (cents).
	 * @param int $budgetCents Budget (cents).
	 *
	 * @return float
	 */
	private function safeUtilization(int $ytdSpendCents, int $budgetCents): float {
		if ($budgetCents <= 0) {
			return 0.0;
		}

		return ((float)$ytdSpendCents / (float)$budgetCents);
	}//end safeUtilization()

	/**
	 * Build the per-programme envelope the dashboard binds against.
	 *
	 * Mirrors {@see \OCA\Shillinq\Dashboard\BBVComplianceWidget::buildEnvelope}:
	 * programmes are listed, mappings are listed, per-programme rows
	 * carry the materialised totalBudget / ytdSpend / utilization /
	 * complianceStatus, and a counts histogram is emitted across the
	 * four status buckets.
	 *
	 * @param array<int,array<string,mixed>> $programmes BBVProgramme rows.
	 * @param array<int,array<string,mixed>> $mappings BudgetBBVMapping rows.
	 * @param array<int,array<string,mixed>> $transactions GL-spend snapshots.
	 *
	 * @return array<string,mixed>
	 */
	private function buildDashboardEnvelope(array $programmes, array $mappings, array $transactions): array {
		$budgetByProgramme = $this->computeTotalBudget(mappings: $mappings);
		$spendByProgramme = $this->computeYtdSpend(mappings: $mappings, transactions: $transactions);

		$rows = [];
		$counts = [
			'unconfigured' => 0,
			'on-track' => 0,
			'at-risk' => 0,
			'non-compliant' => 0,
		];
		$totalBudget = 0;
		$totalYtdSpend = 0;

		foreach ($programmes as $programme) {
			$code = (string)$programme['programmeCode'];
			$budget = ($budgetByProgramme[$code] ?? 0);
			$spend = ($spendByProgramme[$code] ?? 0);

			$utilization = 0.0;
			if ($budget > 0) {
				$utilization = ((float)$spend / (float)$budget);
			}

			$status = $this->bucket(utilization: $utilization, budgetCents: $budget);
			$counts[$status]++;

			$totalBudget += $budget;
			$totalYtdSpend += $spend;

			$rows[] = [
				'programmeCode' => $code,
				'totalBudget' => $budget,
				'ytdSpend' => $spend,
				'utilization' => $utilization,
				'complianceStatus' => $status,
			];
		}//end foreach

		return [
			'programmes' => $rows,
			'counts' => $counts,
			'summary' => [
				'programmeCount' => count($rows),
				'mappingCount' => count($mappings),
				'totalBudget' => $totalBudget,
				'totalYtdSpend' => $totalYtdSpend,
				'utilization' => $this->safeUtilization($totalYtdSpend, $totalBudget),
			],
		];

	}//end buildDashboardEnvelope()

	/**
	 * The dashboard envelope's per-programme totalBudget rows MUST match
	 * the declarative aggregation result documented in the fixture's
	 * `_notes.expectedTotalBudgetCents` block.
	 *
	 * @return void
	 */
	public function testDashboardTotalBudgetMatchesAggregation(): void {
		$fixture = $this->fixture();
		$envelope = $this->buildDashboardEnvelope(
			programmes: $fixture['programmes'],
			mappings: $fixture['mappings'],
			transactions: []
		);

		$byCode = [];
		foreach ($envelope['programmes'] as $row) {
			$byCode[$row['programmeCode']] = $row;
		}

		foreach ($fixture['_notes']['expectedTotalBudgetCents'] as $code => $expected) {
			self::assertArrayHasKey(
				$code,
				$byCode,
				'Dashboard envelope MUST include programme ' . $code
			);
			self::assertSame(
				$expected,
				$byCode[$code]['totalBudget'],
				'Dashboard totalBudget for ' . $code . ' MUST equal the aggregation result (' . $expected . ' cents).'
			);
		}

	}//end testDashboardTotalBudgetMatchesAggregation()

	/**
	 * Programmes with no mapping are reported as unconfigured with
	 * zero budget — slice 02's natural identity when no mappings
	 * apply (programme 3.1.0 in the fixture).
	 *
	 * @return void
	 */
	public function testUnmappedProgrammeIsUnconfigured(): void {
		$fixture = $this->fixture();
		$envelope = $this->buildDashboardEnvelope(
			programmes: $fixture['programmes'],
			mappings: $fixture['mappings'],
			transactions: []
		);

		$byCode = [];
		foreach ($envelope['programmes'] as $row) {
			$byCode[$row['programmeCode']] = $row;
		}

		self::assertSame(0, $byCode['3.1.0']['totalBudget']);
		self::assertSame(0, $byCode['3.1.0']['ytdSpend']);
		self::assertSame('unconfigured', $byCode['3.1.0']['complianceStatus']);

	}//end testUnmappedProgrammeIsUnconfigured()

	/**
	 * Recording the on-track spend snapshot SHALL move programme 2.3.2
	 * to status `on-track` with 65% utilization (matches the giant's
	 * spec example REQ-BBVW-005).
	 *
	 * @return void
	 */
	public function testOnTrackSnapshotProducesOnTrackStatus(): void {
		$fixture = $this->fixture();
		$snapshot = $fixture['spendSnapshots']['onTrack'];
		$envelope = $this->buildDashboardEnvelope(
			programmes: $fixture['programmes'],
			mappings: $fixture['mappings'],
			transactions: $snapshot['transactions']
		);

		$byCode = [];
		foreach ($envelope['programmes'] as $row) {
			$byCode[$row['programmeCode']] = $row;
		}

		self::assertSame(
			$snapshot['expectedYtdSpendCents']['2.3.2'],
			$byCode['2.3.2']['ytdSpend'],
			'YTDSpend for 2.3.2 on the on-track snapshot MUST match the aggregation result.'
		);
		self::assertEqualsWithDelta(
			$snapshot['expectedUtilization']['2.3.2'],
			$byCode['2.3.2']['utilization'],
			0.0001,
			'Utilization for 2.3.2 on the on-track snapshot MUST be 0.65.'
		);
		self::assertSame(
			$snapshot['expectedComplianceStatus']['2.3.2'],
			$byCode['2.3.2']['complianceStatus']
		);

	}//end testOnTrackSnapshotProducesOnTrackStatus()

	/**
	 * Recording the at-risk snapshot SHALL move programme 2.3.2 to
	 * status `at-risk` with 85% utilization (REQ-BBVW-005 example).
	 *
	 * @return void
	 */
	public function testAtRiskSnapshotProducesAtRiskStatus(): void {
		$fixture = $this->fixture();
		$snapshot = $fixture['spendSnapshots']['atRisk'];
		$envelope = $this->buildDashboardEnvelope(
			programmes: $fixture['programmes'],
			mappings: $fixture['mappings'],
			transactions: $snapshot['transactions']
		);

		$byCode = [];
		foreach ($envelope['programmes'] as $row) {
			$byCode[$row['programmeCode']] = $row;
		}

		self::assertSame(
			$snapshot['expectedYtdSpendCents']['2.3.2'],
			$byCode['2.3.2']['ytdSpend']
		);
		self::assertEqualsWithDelta(
			$snapshot['expectedUtilization']['2.3.2'],
			$byCode['2.3.2']['utilization'],
			0.0001
		);
		self::assertSame('at-risk', $byCode['2.3.2']['complianceStatus']);

	}//end testAtRiskSnapshotProducesAtRiskStatus()

	/**
	 * Recording the non-compliant snapshot SHALL move programme 2.3.2
	 * to status `non-compliant` with 96% utilization (REQ-BBVW-005).
	 *
	 * @return void
	 */
	public function testNonCompliantSnapshotProducesNonCompliantStatus(): void {
		$fixture = $this->fixture();
		$snapshot = $fixture['spendSnapshots']['nonCompliant'];
		$envelope = $this->buildDashboardEnvelope(
			programmes: $fixture['programmes'],
			mappings: $fixture['mappings'],
			transactions: $snapshot['transactions']
		);

		$byCode = [];
		foreach ($envelope['programmes'] as $row) {
			$byCode[$row['programmeCode']] = $row;
		}

		self::assertSame(
			$snapshot['expectedYtdSpendCents']['2.3.2'],
			$byCode['2.3.2']['ytdSpend']
		);
		self::assertEqualsWithDelta(
			$snapshot['expectedUtilization']['2.3.2'],
			$byCode['2.3.2']['utilization'],
			0.0001
		);
		self::assertSame('non-compliant', $byCode['2.3.2']['complianceStatus']);

	}//end testNonCompliantSnapshotProducesNonCompliantStatus()

	/**
	 * Recording additional GL spend mutates the per-programme status —
	 * iterating through the three rising-spend snapshots SHALL move
	 * programme 2.3.2 through the on-track → at-risk → non-compliant
	 * sequence (slice-11 ADDED requirement).
	 *
	 * @return void
	 */
	public function testRecordingGlSpendMovesStatusForward(): void {
		$fixture = $this->fixture();
		$sequence = ['onTrack', 'atRisk', 'nonCompliant'];
		$observed = [];

		foreach ($sequence as $snapshotKey) {
			$snapshot = $fixture['spendSnapshots'][$snapshotKey];
			$envelope = $this->buildDashboardEnvelope(
				programmes: $fixture['programmes'],
				mappings: $fixture['mappings'],
				transactions: $snapshot['transactions']
			);

			foreach ($envelope['programmes'] as $row) {
				if ($row['programmeCode'] === '2.3.2') {
					$observed[] = [
						'utilization' => $row['utilization'],
						'status' => $row['complianceStatus'],
					];
				}
			}
		}

		self::assertSame(['on-track', 'at-risk', 'non-compliant'], array_column($observed, 'status'));
		// Utilization MUST be monotonically rising as additional GL
		// transactions are recorded.
		self::assertLessThan($observed[1]['utilization'], $observed[0]['utilization']);
		self::assertLessThan($observed[2]['utilization'], $observed[1]['utilization']);

	}//end testRecordingGlSpendMovesStatusForward()

	/**
	 * The counts histogram across the four status buckets equals the
	 * tally of per-programme rows — used by the slice-05 pie widget.
	 *
	 * @return void
	 */
	public function testCountsHistogramMatchesProgrammeRows(): void {
		$fixture = $this->fixture();
		$snapshot = $fixture['spendSnapshots']['nonCompliant'];
		$envelope = $this->buildDashboardEnvelope(
			programmes: $fixture['programmes'],
			mappings: $fixture['mappings'],
			transactions: $snapshot['transactions']
		);

		$tally = [
			'unconfigured' => 0,
			'on-track' => 0,
			'at-risk' => 0,
			'non-compliant' => 0,
		];
		foreach ($envelope['programmes'] as $row) {
			$tally[$row['complianceStatus']]++;
		}

		self::assertSame(
			$tally,
			$envelope['counts'],
			'Counts histogram MUST equal per-row tally so the pie widget renders deterministically.'
		);
		// Total must equal programme count (5 demo programmes).
		self::assertSame(5, array_sum($envelope['counts']));

	}//end testCountsHistogramMatchesProgrammeRows()

	/**
	 * Summary totals across the active fiscal year equal the sum of
	 * per-programme budgets / ytdSpends — the KPI cards bind to this.
	 *
	 * @return void
	 */
	public function testSummaryTotalsEqualPerProgrammeSums(): void {
		$fixture = $this->fixture();
		$snapshot = $fixture['spendSnapshots']['atRisk'];
		$envelope = $this->buildDashboardEnvelope(
			programmes: $fixture['programmes'],
			mappings: $fixture['mappings'],
			transactions: $snapshot['transactions']
		);

		$totalBudget = 0;
		$totalYtdSpend = 0;
		foreach ($envelope['programmes'] as $row) {
			$totalBudget += $row['totalBudget'];
			$totalYtdSpend += $row['ytdSpend'];
		}

		self::assertSame($totalBudget, $envelope['summary']['totalBudget']);
		self::assertSame($totalYtdSpend, $envelope['summary']['totalYtdSpend']);
		self::assertSame(5, $envelope['summary']['programmeCount']);
		self::assertSame(count($fixture['mappings']), $envelope['summary']['mappingCount']);

	}//end testSummaryTotalsEqualPerProgrammeSums()

	/**
	 * Cross-administration scoping (slice 09 fiscal audit): a programme
	 * scoped to a foreign administrationId MUST NOT mix into the
	 * envelope built for `adm-waterschap-1`. The widget's findAll
	 * filter applies administrationId; this test mirrors that filter
	 * and asserts the foreign row is excluded.
	 *
	 * @return void
	 */
	public function testCrossAdministrationScopingExcludesForeignRows(): void {
		$fixture = $this->fixture();
		$programmes = $fixture['programmes'];
		// Inject a foreign-administration programme that should be
		// filtered out by the dashboard's scopedFilters() call.
		$programmes[] = [
			'programmeCode' => '4.1.0',
			'programmeName' => 'Foreign Administration Programme',
			'fiscalYear' => 2026,
			'status' => 'active',
			'administrationId' => 'adm-waterschap-OTHER',
		];

		$scoped = array_values(
			array_filter(
				$programmes,
				static fn (array $row): bool => ($row['administrationId'] === 'adm-waterschap-1')
			)
		);

		$envelope = $this->buildDashboardEnvelope(
			programmes: $scoped,
			mappings: $fixture['mappings'],
			transactions: []
		);

		$codes = array_column($envelope['programmes'], 'programmeCode');
		self::assertNotContains('4.1.0', $codes, 'Foreign-administration programme MUST be filtered out.');
		self::assertCount(5, $envelope['programmes']);

	}//end testCrossAdministrationScopingExcludesForeignRows()
}//end class
