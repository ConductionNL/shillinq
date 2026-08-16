<?php

/**
 * Unit tests for KorThresholdCalculator.
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
 * @spec openspec/changes/bookkeeping-kor-kleine-ondernemersregeling/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\KorThresholdCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure KOR fiscal arithmetic: running omzet, benutting, prognose,
 * alert-schijf crossing, suppletie-bedrag (REQ-KOR-004), three-year blokkade,
 * and herzieningsregels recovery (REQ-KOR-006/011).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class KorThresholdCalculatorTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var KorThresholdCalculator
	 */
	private KorThresholdCalculator $calc;

	/**
	 * Set up the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new KorThresholdCalculator();

	}//end setUp()

	/**
	 * Only KOR-eligible, non-draft invoices in the year count (REQ-KOR-002).
	 *
	 * @return void
	 */
	public function testRunningOmzetOnlyCountsKorEligible(): void {
		$invoices = [
			['amount' => 200.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'status' => 'issued', 'leveringsDatum' => '2026-08-12'],
			['amount' => 999.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'status' => 'draft', 'leveringsDatum' => '2026-08-13'],
			['amount' => 500.0, 'vrijstellingsGrondslag' => 'REGULIER_21PCT_VAT', 'status' => 'issued', 'leveringsDatum' => '2026-08-14'],
			['amount' => 100.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'status' => 'issued', 'leveringsDatum' => '2025-12-31'],
			['amount' => 50.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'status' => 'issued', 'leveringsDatum' => '2026-01-05'],
		];

		// 200 + 50 = 250.00 => 25000 cents (draft excluded, regulier excluded, prior-year excluded).
		self::assertSame(25000, $this->calc->runningOmzetCents(invoices: $invoices, year: 2026));

	}//end testRunningOmzetOnlyCountsKorEligible()

	/**
	 * Benutting is omzet / drempel, with zero-threshold guard (REQ-KOR-002).
	 *
	 * @return void
	 */
	public function testBenutting(): void {
		// 16.620 / 20.000 = 0.831.
		self::assertEqualsWithDelta(0.831, $this->calc->benutting(revenueCents: 1662000, thresholdCents: 2000000), 0.0001);
		self::assertSame(0.0, $this->calc->benutting(revenueCents: 100, thresholdCents: 0));

	}//end testBenutting()

	/**
	 * Each schijf fires exactly once on crossing, never on a static benutting (REQ-KOR-003).
	 *
	 * @return void
	 */
	public function testCrossedSchijf(): void {
		// Crossing 80% from below.
		self::assertSame('THRESHOLD_80_PCT', $this->calc->crossedSchijf(previousUtilisation: 0.79, newUtilisation: 0.83)['trigger']);
		// Crossing 90% from below.
		self::assertSame('THRESHOLD_90_PCT', $this->calc->crossedSchijf(previousUtilisation: 0.85, newUtilisation: 0.906)['trigger']);
		// Crossing 100% from below => OVERSCHRIJDING.
		$hit = $this->calc->crossedSchijf(previousUtilisation: 0.95, newUtilisation: 1.018);
		self::assertSame('THRESHOLD_100_PCT', $hit['trigger']);
		self::assertSame('OVERRUN', $hit['severity']);
		// No new schijf when staying within the same band.
		self::assertNull($this->calc->crossedSchijf(previousUtilisation: 0.82, newUtilisation: 0.84));

	}//end testCrossedSchijf()

	/**
	 * The end-of-year prognose projects the monthly average forward (REQ-KOR-002).
	 *
	 * @return void
	 */
	public function testPrognoseEndOfYear(): void {
		// 16.420 over 8 months => avg 2052.50/mo => + 4 remaining months.
		// 1642000 cents, month 8 => avg = 205250, remaining 4 => 1642000 + 821000 = 2463000.
		self::assertSame(2463000, $this->calc->prognoseEndOfYearCents(lopendeCents: 1642000, currentMonth: 8));

	}//end testPrognoseEndOfYear()

	/**
	 * Prognose-status escalates with projected benutting (REQ-KOR-002).
	 *
	 * @return void
	 */
	public function testPrognoseStatus(): void {
		self::assertSame('UNDER_THRESHOLD', $this->calc->prognoseStatus(prognoseCents: 1500000, thresholdCents: 2000000));
		self::assertSame('WARNING', $this->calc->prognoseStatus(prognoseCents: 1700000, thresholdCents: 2000000));
		self::assertSame('OVERRUN_EXPECTED', $this->calc->prognoseStatus(prognoseCents: 2463000, thresholdCents: 2000000));

	}//end testPrognoseStatus()

	/**
	 * Suppletie sums the embedded VAT of KOR-facturen before the revocatie-datum (REQ-KOR-004).
	 *
	 * The revocatie-datum itself is exclusive (facturen on/after it are already re-marked).
	 *
	 * @return void
	 */
	public function testSuppletieBedrag(): void {
		$invoices = [
			// In window: 1210 gross => embedded VAT 1210 * 0.21 / 1.21 = 210.00 => 21000 cents.
			['amount' => 1210.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2026-03-01'],
			// In window: 605 gross => embedded VAT 105.00 => 10500 cents.
			['amount' => 605.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2026-06-15'],
			// On the revocatie-datum => excluded (already re-marked).
			['amount' => 1000.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2026-09-04'],
			// Before ingangsDatum => excluded.
			['amount' => 500.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2025-12-31'],
			// Not KOR => excluded.
			['amount' => 1210.0, 'vrijstellingsGrondslag' => 'REGULIER_21PCT_VAT', 'leveringsDatum' => '2026-04-01'],
		];

		$cents = $this->calc->suppletieBedragCents(
			invoices: $invoices,
			effectiveDate: '2026-01-01',
			revocationDate: '2026-09-04'
		);

		// 21000 + 10500 = 31500 cents = EUR 315.00.
		self::assertSame(31500, $cents);

	}//end testSuppletieBedrag()

	/**
	 * The heraanmelding-blokkade is exactly revocatieDatum + 3 years (REQ-KOR-004).
	 *
	 * @return void
	 */
	public function testPlusThreeYears(): void {
		self::assertSame('2029-09-04', $this->calc->plusThreeYears(date: '2026-09-04'));
		self::assertSame('', $this->calc->plusThreeYears(date: 'not-a-date'));

	}//end testPlusThreeYears()

	/**
	 * Herzieningsregels recover voorbelasting proportional to remaining life (REQ-KOR-006/011).
	 *
	 * @return void
	 */
	public function testHerzieningRecovery(): void {
		// EUR 139 VAT (13900 cents), 57 of 60 months remaining => 13900 * 57/60 = 13205.
		self::assertSame(13205, $this->calc->herzieningRecoveryCents(vatCents: 13900, remainingMonths: 57, totalMonths: 60));
		// Clamp: remaining > total => full recovery.
		self::assertSame(13900, $this->calc->herzieningRecoveryCents(vatCents: 13900, remainingMonths: 99, totalMonths: 60));
		// Zero total months => zero recovery (no divide-by-zero).
		self::assertSame(0, $this->calc->herzieningRecoveryCents(vatCents: 13900, remainingMonths: 10, totalMonths: 0));

	}//end testHerzieningRecovery()

	/**
	 * The canonical NL-KOR lock-in window is 3 calendar years, opt-out window opens 3 months prior (REQ-KOR-007).
	 *
	 * @return void
	 */
	public function testLockInWindowCanonical(): void {
		$window = $this->calc->lockInWindow(effectiveDate: '2026-01-01');
		self::assertSame('2028-12-31', $window['lockInEndDate']);
		self::assertSame('2028-10-01', $window['earliestTerminationDate']);

	}//end testLockInWindowCanonical()

	/**
	 * Mid-year ingangsdatum produces the day-before in year+3 for lockInEindDatum (REQ-KOR-007).
	 *
	 * @return void
	 */
	public function testLockInWindowMidYear(): void {
		$window = $this->calc->lockInWindow(effectiveDate: '2026-07-15');
		self::assertSame('2029-07-14', $window['lockInEndDate']);
		self::assertSame('2029-05-01', $window['earliestTerminationDate']);

	}//end testLockInWindowMidYear()

	/**
	 * Invalid lock-in inputs return null (REQ-KOR-007).
	 *
	 * @return void
	 */
	public function testLockInWindowInvalid(): void {
		self::assertNull($this->calc->lockInWindow(effectiveDate: 'nope'));
		self::assertNull($this->calc->lockInWindow(effectiveDate: '2026-13-01'));

	}//end testLockInWindowInvalid()

	/**
	 * Opt-out is blocked outside the [vroegsteOpzeg, lockInEinde] window (REQ-KOR-007).
	 *
	 * @return void
	 */
	public function testIsOptOutPermitted(): void {
		// Before vroegsteOpzeg => blocked.
		self::assertFalse($this->calc->isOptOutPermitted(today: '2028-09-30', earliestTerminationDate: '2028-10-01', lockInEndDate: '2028-12-31'));
		// Inside the window => permitted.
		self::assertTrue($this->calc->isOptOutPermitted(today: '2028-10-15', earliestTerminationDate: '2028-10-01', lockInEndDate: '2028-12-31'));
		// On the boundary => permitted.
		self::assertTrue($this->calc->isOptOutPermitted(today: '2028-10-01', earliestTerminationDate: '2028-10-01', lockInEndDate: '2028-12-31'));
		// After lockInEindDatum => blocked (different lifecycle path).
		self::assertFalse($this->calc->isOptOutPermitted(today: '2029-01-01', earliestTerminationDate: '2028-10-01', lockInEndDate: '2028-12-31'));
		// Empty windows are unsafe => blocked.
		self::assertFalse($this->calc->isOptOutPermitted(today: '2028-10-15', earliestTerminationDate: '', lockInEndDate: '2028-12-31'));

	}//end testIsOptOutPermitted()

	/**
	 * Per-lidstaat KOR-EU aggregate groups by lidstaat, applies the per-country drempel (REQ-KOR-008).
	 *
	 * @return void
	 */
	public function testPerLidstaatAggregate(): void {
		$invoices = [
			['amount' => 12000.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'BE', 'leveringsDatum' => '2026-03-01'],
			['amount' => 4000.0,  'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'BE', 'leveringsDatum' => '2026-05-15'],
			['amount' => 8000.0,  'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'DE', 'leveringsDatum' => '2026-04-20'],
			// Different year => excluded.
			['amount' => 9999.0,  'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'BE', 'leveringsDatum' => '2025-12-31'],
			// No lidstaat => excluded.
			['amount' => 100.0,   'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2026-06-01'],
			// Country without override => default 100k drempel.
			['amount' => 1000.0,  'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'FR', 'leveringsDatum' => '2026-07-01'],
		];

		$aggregate = $this->calc->perLidstaatAggregate(
			invoices: $invoices,
			thresholdsPerMemberState: ['BE' => 25000, 'DE' => 22000],
			year: 2026
		);

		// BE total 16.000 over drempel 25.000 => benutting 0.64.
		self::assertEqualsWithDelta(16000.0, $aggregate['BE']['revenue'], 0.001);
		self::assertEqualsWithDelta(25000.0, $aggregate['BE']['threshold'], 0.001);
		self::assertEqualsWithDelta(0.64, $aggregate['BE']['benutting'], 0.001);

		// DE 8000 / 22000 = 0.3636...
		self::assertEqualsWithDelta(0.3636, $aggregate['DE']['benutting'], 0.001);

		// FR uses default 100000 drempel.
		self::assertEqualsWithDelta(100000.0, $aggregate['FR']['threshold'], 0.001);
		self::assertEqualsWithDelta(0.01, $aggregate['FR']['benutting'], 0.001);

	}//end testPerLidstaatAggregate()

	/**
	 * Branche-compat check returns BLOCK on fiscale eenheid + art. 11 vrijstelling (REQ-KOR-010).
	 *
	 * @return void
	 */
	public function testBrancheCompatibilityBlocking(): void {
		$unit = $this->calc->brancheCompatibility(branche: ['fiscaleEenheid' => true]);
		self::assertSame('BLOCK', $unit['verdict']);

		$art11 = $this->calc->brancheCompatibility(branche: ['art11Vrijstelling' => true]);
		self::assertSame('BLOCK', $art11['verdict']);

	}//end testBrancheCompatibilityBlocking()

	/**
	 * Branche-compat check returns WARN on mixed-use and intracommunautair (REQ-KOR-010).
	 *
	 * @return void
	 */
	public function testBrancheCompatibilityWarning(): void {
		$mixed = $this->calc->brancheCompatibility(branche: ['vrijgesteldEnBelast' => true]);
		self::assertSame('WARN', $mixed['verdict']);

		$intra = $this->calc->brancheCompatibility(branche: ['intracommunautair' => true]);
		self::assertSame('WARN', $intra['verdict']);

	}//end testBrancheCompatibilityWarning()

	/**
	 * Branche-compat check returns OK for a clean ZZP / MKB without contra-indicaties (REQ-KOR-010).
	 *
	 * @return void
	 */
	public function testBrancheCompatibilityOk(): void {
		$ok = $this->calc->brancheCompatibility(branche: []);
		self::assertSame('OK', $ok['verdict']);

	}//end testBrancheCompatibilityOk()

	/**
	 * Voorraad-correctie sums per-asset herziening across all transitions assets (REQ-KOR-011a).
	 *
	 * @return void
	 */
	public function testVoorraadCorrectie(): void {
		$assets = [
			['vatCents' => 13900, 'remainingMonths' => 57, 'totalMonths' => 60],   // => 13205.
			['vatCents' => 21000, 'remainingMonths' => 24, 'totalMonths' => 120],  // => 4200.
			['vatCents' => 5000,  'remainingMonths' => 0,  'totalMonths' => 60],   // => 0 (fully depreciated).
		];

		// 13205 + 4200 + 0 = 17405.
		self::assertSame(17405, $this->calc->voorraadCorrectieCents(assets: $assets));

	}//end testVoorraadCorrectie()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
