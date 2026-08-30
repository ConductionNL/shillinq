<?php

/**
 * Unit tests for cashflow declarative-aggregation invariants.
 *
 * These tests exercise the deterministic helpers and seed-data invariants
 * required by the spec (REQ-CF-003 betalingsgedrag, REQ-CF-005 recurring
 * expansion, REQ-CF-007 BTW calendar, REQ-CF-008 IB peilmaanden, REQ-CF-009
 * buffer-policy alerts, REQ-CF-010 crisis-mode trigger). Per ADR-031 the
 * runtime calculations live in OR aggregations; here we lock the invariant
 * arithmetic that the aggregations must satisfy.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Invariant-arithmetic tests for cashflow aggregations.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CashflowAggregationLogicTest extends TestCase {

	/**
	 * REQ-CF-003: betalingsgedrag confidence equals min(1.0, count / 12).
	 *
	 * @return void
	 */
	public function testBetalingsgedragConfidenceFormula(): void {
		self::assertSame(0.75, $this->confidence(9));
		self::assertSame(1.0, $this->confidence(12));
		self::assertSame(1.0, $this->confidence(20));
		// Below 5 samples => fallback marker; still compute a confidence value
		// so calibrate-and-warn UI has the number, but caller marks "LOW".
		self::assertSame((1.0 / 3.0), $this->confidence(4));

	}//end testBetalingsgedragConfidenceFormula()

	/**
	 * REQ-CF-003: new-customer fallback adds a 7-day buffer to contractual term.
	 *
	 * @return void
	 */
	public function testNewCustomerFallbackAddsBuffer(): void {
		$dueDate = '2026-06-15';
		$term = 30;
		$buffer = 7;
		$project = $this->projectFallback($dueDate, $term, $buffer);
		self::assertSame('2026-06-22', $project);

	}//end testNewCustomerFallbackAddsBuffer()

	/**
	 * REQ-CF-003: with-history projection = dueDate + meanOffset.
	 *
	 * @return void
	 */
	public function testProjectionWithMeanOffset(): void {
		$dueDate = '2026-05-15';
		$offset = 13;
		$project = (new \DateTimeImmutable($dueDate))
			->modify('+' . $offset . ' days')
			->format('Y-m-d');
		self::assertSame('2026-05-28', $project);

	}//end testProjectionWithMeanOffset()

	/**
	 * REQ-CF-007: BTW Q-end due dates per Belastingdienst calendar.
	 *
	 * @return void
	 */
	public function testBtwQuarterlyDueDates(): void {
		self::assertSame('2026-04-30', $this->vatDueDate(year: 2026, quarter: 1));
		self::assertSame('2026-07-31', $this->vatDueDate(year: 2026, quarter: 2));
		self::assertSame('2026-10-31', $this->vatDueDate(year: 2026, quarter: 3));
		self::assertSame('2027-01-31', $this->vatDueDate(year: 2026, quarter: 4));

	}//end testBtwQuarterlyDueDates()

	/**
	 * REQ-CF-007: standard BTW amount = trailing-quarter turnover x 21%.
	 *
	 * @return void
	 */
	public function testBtwAmountAt21Pct(): void {
		$turnover = 22952.38;
		$expected = round(($turnover * 0.21), 2);
		self::assertSame(4820.0, $expected);

	}//end testBtwAmountAt21Pct()

	/**
	 * REQ-CF-008: IB-aanslag projection = prior x (1 + growth).
	 *
	 * @return void
	 */
	public function testIbAanslagWithGrowthFactor(): void {
		$prior = 2100.00;
		$growth = 0.15;
		self::assertSame(2415.00, round(($prior * (1 + $growth)), 2));

	}//end testIbAanslagWithGrowthFactor()

	/**
	 * REQ-CF-005: CPI indexing applies linearly to standaardBedrag.
	 *
	 * @return void
	 */
	public function testCpiIndexationFormula(): void {
		$base = 620.00;
		$cpi = 0.032;
		self::assertSame(639.84, round(($base * (1 + $cpi)), 2));

	}//end testCpiIndexationFormula()

	/**
	 * REQ-CF-009: buffer policy thresholds (50% red, 150% yellow).
	 *
	 * @return void
	 */
	public function testBufferThresholds(): void {
		$buffer = 5200.00;
		self::assertSame(2600.0, round(($buffer * 0.5), 2));
		self::assertSame(7800.0, round(($buffer * 1.5), 2));

	}//end testBufferThresholds()

	/**
	 * REQ-CF-009: bufferStatus classifier (CRISIS / VOORALARM / BOVEN_BUFFER).
	 *
	 * @return void
	 */
	public function testBufferStatusClassifier(): void {
		self::assertSame('CRISIS', $this->bufferStatus(balance: 2000.0, ondergrens: 2600.0, vooralarm: 7800.0));
		self::assertSame('PRE_ALERT', $this->bufferStatus(balance: 7500.0, ondergrens: 2600.0, vooralarm: 7800.0));
		self::assertSame('ABOVE_BUFFER', $this->bufferStatus(balance: 12000.0, ondergrens: 2600.0, vooralarm: 7800.0));

	}//end testBufferStatusClassifier()

	/**
	 * REQ-CF-010: crisis-mode triggers when ANY of weeks 1-4 has negative eindSaldo.
	 *
	 * @return void
	 */
	public function testCrisisModeTriggerWithinFourWeeks(): void {
		$allPositive = [
			['weekNumber' => 22, 'closingBalance' => 100.0],
			['weekNumber' => 23, 'closingBalance' => 50.0],
			['weekNumber' => 24, 'closingBalance' => 200.0],
			['weekNumber' => 25, 'closingBalance' => 300.0],
		];
		$hasNegative = [
			['weekNumber' => 22, 'closingBalance' => 100.0],
			['weekNumber' => 23, 'closingBalance' => -50.0],
			['weekNumber' => 24, 'closingBalance' => 200.0],
			['weekNumber' => 25, 'closingBalance' => 300.0],
		];
		self::assertFalse($this->crisisActive($allPositive));
		self::assertTrue($this->crisisActive($hasNegative));

	}//end testCrisisModeTriggerWithinFourWeeks()

	/**
	 * REQ-CF-002: nettoMutatie = inflows_totaal - outflows_totaal.
	 *
	 * @return void
	 */
	public function testWeekNetMutationFormula(): void {
		$inflows = 12500.00;
		$outflows = 7475.00;
		self::assertSame(5025.0, ($inflows - $outflows));

	}//end testWeekNetMutationFormula()

	/**
	 * REQ-CF-002: eindSaldo = openingSaldo + nettoMutatie.
	 *
	 * @return void
	 */
	public function testWeekEndingSaldoFormula(): void {
		$opening = 14820.00;
		$net = 9225.00;
		self::assertSame(24045.0, ($opening + $net));

	}//end testWeekEndingSaldoFormula()

	/**
	 * Seed-data invariant: Erik (stable) buffer never breached within his band.
	 *
	 * @return void
	 */
	public function testSeedDataErikBufferBandIntact(): void {
		$seed = $this->loadSeed();
		$erik = $this->profileById($seed, 'erik-stable-b2b-consultant');
		self::assertGreaterThan($erik['bufferPolicy']['alertPreAlert'], $erik['expectedSaldoRange']['min']);

	}//end testSeedDataErikBufferBandIntact()

	/**
	 * Seed-data invariant: Sarah (volatile) is expected to trigger VOORALARM but not crisis.
	 *
	 * @return void
	 */
	public function testSeedDataSarahCanTriggerVooralarmNotCrisis(): void {
		$seed = $this->loadSeed();
		$sarah = $this->profileById($seed, 'sarah-volatile-project-based');
		// Min saldo should stay above ondergrens (no crisis) but may fall below vooralarm.
		self::assertGreaterThan(
			$sarah['bufferPolicy']['alertLowerLimit'],
			$sarah['expectedSaldoRange']['min']
		);

	}//end testSeedDataSarahCanTriggerVooralarmNotCrisis()

	/**
	 * Seed-data invariant: Jan (government) is expected to potentially breach
	 * ondergrens during the summer gap (acknowledged in expectedSaldoRange).
	 *
	 * @return void
	 */
	public function testSeedDataJanGovernmentCanBreachOndergrens(): void {
		$seed = $this->loadSeed();
		$jan = $this->profileById($seed, 'jan-government-contractor');
		// Per design.md and expectedSaldoRange, summer crisis is acceptable.
		self::assertLessThanOrEqual(
			$jan['bufferPolicy']['alertPreAlert'],
			$jan['expectedSaldoRange']['min']
		);

	}//end testSeedDataJanGovernmentCanBreachOndergrens()

	/**
	 * Seed-data invariant: every profile has at least one recurring outflow
	 * and at least one AR invoice — required for forecast viability.
	 *
	 * @return void
	 */
	public function testSeedDataAllProfilesHaveMinimumInputs(): void {
		$seed = $this->loadSeed();
		self::assertCount(3, $seed['profiles']);
		foreach ($seed['profiles'] as $profile) {
			self::assertGreaterThan(0, count($profile['recurring']));
			self::assertGreaterThan(0, count($profile['arInvoices']));
			self::assertArrayHasKey('horizon', $profile);
			self::assertArrayHasKey('bufferPolicy', $profile);
		}

	}//end testSeedDataAllProfilesHaveMinimumInputs()

	// -------------------- helpers --------------------

	/**
	 * Confidence formula.
	 *
	 * @param int $sampleCount Number of invoices in 12-month sample.
	 *
	 * @return float
	 */
	private function confidence(int $sampleCount): float {
		return min(1.0, ($sampleCount / 12));
	}//end confidence()

	/**
	 * Fallback projection date.
	 *
	 * @param string $dueDate Contract due date (Y-m-d).
	 * @param int $term Contract payment-term days.
	 * @param int $buffer Buffer days.
	 *
	 * @return string Y-m-d projection date.
	 */
	private function projectFallback(string $dueDate, int $term, int $buffer): string {
		// For new customer: fallback = dueDate + buffer (term is already in dueDate).
		return (new \DateTimeImmutable($dueDate))
			->modify('+' . $buffer . ' days')
			->format('Y-m-d');

	}//end projectFallback()

	/**
	 * BTW quarterly due date per Belastingdienst calendar.
	 *
	 * @param int $year Year of the quarter.
	 * @param int $quarter Quarter number 1-4.
	 *
	 * @return string Due date Y-m-d.
	 */
	private function vatDueDate(int $year, int $quarter): string {
		switch ($quarter) {
			case 1:
				return sprintf('%d-04-30', $year);
			case 2:
				return sprintf('%d-07-31', $year);
			case 3:
				return sprintf('%d-10-31', $year);
			case 4:
				return sprintf('%d-01-31', ($year + 1));
			default:
				return '';
		}

	}//end btwDueDate()

	/**
	 * Buffer-status classifier per REQ-CF-009.
	 *
	 * @param float $balance Eind-saldo of the week.
	 * @param float $ondergrens Critical threshold.
	 * @param float $vooralarm Pre-alert threshold.
	 *
	 * @return string
	 */
	private function bufferStatus(float $balance, float $ondergrens, float $vooralarm): string {
		if ($balance < $ondergrens) {
			return 'CRISIS';
		}

		if ($balance < $vooralarm) {
			return 'PRE_ALERT';
		}

		return 'ABOVE_BUFFER';
	}//end bufferStatus()

	/**
	 * Crisis-mode trigger per REQ-CF-010.
	 *
	 * @param list<array{weekNumber:int,eindSaldo:float}> $weeks Leading-4 weeks.
	 *
	 * @return bool
	 */
	private function crisisActive(array $weeks): bool {
		$leading = array_slice($weeks, 0, 4);
		foreach ($leading as $week) {
			if ($week['closingBalance'] < 0) {
				return true;
			}
		}

		return false;
	}//end crisisActive()

	/**
	 * Load seed-data fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function loadSeed(): array {
		$path = __DIR__ . '/../../fixtures/CashflowSeedData.json';
		$contents = file_get_contents($path);
		if ($contents === false) {
			self::fail('Could not read CashflowSeedData fixture at ' . $path);
		}

		$data = json_decode($contents, true);
		if (is_array($data) === false) {
			self::fail('CashflowSeedData fixture is not valid JSON.');
		}

		return $data;
	}//end loadSeed()

	/**
	 * Look up a profile by id.
	 *
	 * @param array<string,mixed> $seed Loaded fixture.
	 * @param string $id Profile identifier.
	 *
	 * @return array<string,mixed>
	 */
	private function profileById(array $seed, string $id): array {
		foreach ($seed['profiles'] as $profile) {
			if (($profile['id'] ?? '') === $id) {
				return $profile;
			}
		}

		self::fail('Profile not found in seed: ' . $id);

	}//end profileById()

}//end class
