<?php

/**
 * Unit tests for the PayrollCalculator pure-logic arithmetic.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-001-bruto-netto-berekening.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PayrollCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Exercises every payroll arithmetic rule against the REQ-PAY acceptance
 * criteria: bruto->netto, wage-tax table lookup, employer SV premiums capped at
 * the pro-rata maximum premieloon, the ZVW employer contribution (low/high,
 * capped), holiday-allowance accrual, the DGA gebruikelijk-loon warning, the
 * tax-free kilometer / home-office / 30%-ruling allowances and pro-rata mutaties.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollCalculatorTest extends TestCase {

	/**
	 * The calculator under test.
	 *
	 * @var PayrollCalculator
	 */
	private PayrollCalculator $calc;

	/**
	 * Build a fresh calculator before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calc = new PayrollCalculator();

	}//end setUp()

	/**
	 * Cents conversion round-trips without float drift.
	 *
	 * @return void
	 */
	public function testCentsRoundTrip(): void {
		self::assertSame(494000, $this->calc->toCents(4940.0));
		self::assertSame(4940.0, $this->calc->fromCents(494000));
		self::assertSame(0, $this->calc->toCents(null));

	}//end testCentsRoundTrip()

	/**
	 * The totaalBruto method sums numeric line items and ignores the reserved key (REQ-PAY-001).
	 *
	 * @return void
	 */
	public function testTotaalBrutoSumsComponents(): void {
		$gross = [
			'basissalaris' => 4940.00,
			'thuiswerkvergoeding' => 19.20,
			'totaal_bruto' => 99999.0,
			'nested' => ['ignored' => true],
		];
		self::assertSame(4959.20, $this->calc->totaalBruto($gross));

	}//end testTotaalBrutoSumsComponents()

	/**
	 * Home-office allowance is days x €2,40 tax-free (REQ-PAY-008).
	 *
	 * @return void
	 */
	public function testThuiswerkvergoeding(): void {
		// 2 days x €2,40 = €4,80.
		self::assertSame(4.80, $this->calc->thuiswerkvergoeding(2.0));
		self::assertSame(0.0, $this->calc->thuiswerkvergoeding(0.0));

	}//end testThuiswerkvergoeding()

	/**
	 * Mileage allowance is capped at €0,23/km (REQ-PAY-007).
	 *
	 * @return void
	 */
	public function testKilometervergoedingCapped(): void {
		// 120 km x €0,23 = €27,60.
		self::assertSame(27.60, $this->calc->belastingvrijeKilometervergoeding(120.0, 0.23));
		// A higher employer rate is clamped to the €0,23 cap.
		self::assertSame(27.60, $this->calc->belastingvrijeKilometervergoeding(120.0, 0.30));

	}//end testKilometervergoedingCapped()

	/**
	 * The 30%-ruling exempts 30% of gross only when it applies (REQ-PAY-015).
	 *
	 * @return void
	 */
	public function testExpat30PctVrijstelling(): void {
		self::assertSame(1500.0, $this->calc->expat30PctVrijstelling(5000.0, true));
		self::assertSame(0.0, $this->calc->expat30PctVrijstelling(5000.0, false));

	}//end testExpat30PctVrijstelling()

	/**
	 * The fiscaalLoon method is gross minus tax-free parts, floored at zero (REQ-PAY-001).
	 *
	 * @return void
	 */
	public function testFiscaalLoon(): void {
		self::assertSame(4940.0, $this->calc->fiscaalLoon(4959.20, 19.20));
		self::assertSame(0.0, $this->calc->fiscaalLoon(100.0, 200.0));

	}//end testFiscaalLoon()

	/**
	 * Wage tax is looked up from the bracket table, floored at zero (REQ-PAY-002).
	 *
	 * @return void
	 */
	public function testLoonheffingUitTabel(): void {
		$rules = [
			['from' => 0, 'tot' => 1200, 'percentage' => 0.0935, 'vasteHeffing' => 0, 'korting' => 295.0],
			['from' => 1200, 'tot' => 3300, 'percentage' => 0.3697, 'vasteHeffing' => 112.2, 'korting' => 295.0],
			['from' => 3300, 'tot' => 6400, 'percentage' => 0.3697, 'vasteHeffing' => 888.6, 'korting' => 295.0],
			['from' => 6400, 'tot' => null, 'percentage' => 0.495, 'vasteHeffing' => 2034.7, 'korting' => 0],
		];

		// €4.940 falls in the 3300..6400 bracket:
		// 888.60 + 0.3697*(4940-3300) - 295 = 888.60 + 606.31 - 295 = 1199.91.
		self::assertSame(1199.91, $this->calc->loonheffingUitTabel(4940.0, $rules));

		// Below the table / empty table yields zero, never an exception.
		self::assertSame(0.0, $this->calc->loonheffingUitTabel(0.0, $rules));
		self::assertSame(0.0, $this->calc->loonheffingUitTabel(4940.0, []));

	}//end testLoonheffingUitTabel()

	/**
	 * The met-korting table yields a materially lower tax than zonder-korting (REQ-PAY-001).
	 *
	 * @return void
	 */
	public function testKortingLowersTax(): void {
		$withDiscount = [
			['from' => 3300, 'tot' => 6400, 'percentage' => 0.3697, 'vasteHeffing' => 888.6, 'korting' => 295.0],
		];
		$zonderDiscount = [
			['from' => 3300, 'tot' => 6400, 'percentage' => 0.3697, 'vasteHeffing' => 888.6, 'korting' => 0],
		];

		$with = $this->calc->loonheffingUitTabel(4940.0, $withDiscount);
		$zonder = $this->calc->loonheffingUitTabel(4940.0, $zonderDiscount);
		self::assertGreaterThan($with, $zonder);
		self::assertSame(295.0, round(($zonder - $with), 2));

	}//end testKortingLowersTax()

	/**
	 * Annual caps pro-rate per period type (REQ-PAY-003).
	 *
	 * @return void
	 */
	public function testPeriodeMaximum(): void {
		self::assertSame(6206.67, $this->calc->periodeMaximum(PayrollCalculator::MAX_PREMIELOON_SV_JAAR_2026, 'MONTH'));
		self::assertSame(5969.00, $this->calc->periodeMaximum(PayrollCalculator::MAX_ZVW_PREMIELOON_JAAR_2026, 'MONTH'));

	}//end testPeriodeMaximum()

	/**
	 * AWF-laag premium is 2,64% of the SV wage (REQ-PAY-003).
	 *
	 * @return void
	 */
	public function testPremiesSVWerkgeverAwfLaag(): void {
		$premies = $this->calc->employerSocialInsurancePremiums(4940.0, 'MONTH', 'LOW', true, 0.0013, 0.0);
		// AWF 2,64% x 4940 = 130,42.
		self::assertSame(130.42, $premies['awf']);
		// AOF-klein 5,38% x 4940 = 265,77.
		self::assertSame(265.77, $premies['aof_basis']);
		// WHK 0,13% x 4940 = 6,42.
		self::assertSame(6.42, $premies['whk']);
		self::assertSame(0.0, $premies['wko']);
		self::assertSame(
			round(($premies['awf'] + $premies['aof_basis'] + $premies['whk']), 2),
			$premies['totaal_werkgever']
		);

	}//end testPremiesSVWerkgeverAwfLaag()

	/**
	 * The SV premium wage is capped at the per-period maximum (REQ-PAY-003).
	 *
	 * @return void
	 */
	public function testPremiesSVCappedAtMaximum(): void {
		$premies = $this->calc->employerSocialInsurancePremiums(7000.0, 'MONTH', 'LOW', true, 0.0, 0.0);
		// Capped at €6.206,67 -> AWF 2,64% x 6206,67 = 163,86.
		self::assertSame(6206.67, $premies['premieloon_gemaximeerd']);
		self::assertSame(163.86, $premies['awf']);

	}//end testPremiesSVCappedAtMaximum()

	/**
	 * ZVW employer contribution: low 5,32%, high 6,57%, capped (REQ-PAY-004).
	 *
	 * @return void
	 */
	public function testZvwWerkgever(): void {
		$low = $this->calc->zvwWerkgever(4940.0, 'MONTH', 'LOW');
		self::assertSame(262.81, $low['afgedragen_wg']);

		$high = $this->calc->zvwWerkgever(4940.0, 'MONTH', 'HIGH');
		self::assertSame(324.56, $high['afgedragen_wg']);

		// €6.500/month exceeds the €5.969 cap -> 5,32% x 5969 = 317,55.
		$capped = $this->calc->zvwWerkgever(6500.0, 'MONTH', 'LOW');
		self::assertSame(5969.00, $capped['basis']);
		self::assertSame(317.55, $capped['afgedragen_wg']);

	}//end testZvwWerkgever()

	/**
	 * Pension splits into employer and employee shares (REQ-PAY-001).
	 *
	 * @return void
	 */
	public function testPensioen(): void {
		$p = $this->calc->pensioen(4940.0, 0.182, 0.072);
		self::assertSame(899.08, $p['premie_wg_aandeel']);
		self::assertSame(355.68, $p['premie_wn_aandeel']);

	}//end testPensioen()

	/**
	 * Holiday allowance accrues at the WML minimum 8% (REQ-PAY-005).
	 *
	 * @return void
	 */
	public function testVakantiegeldOpbouw(): void {
		self::assertSame(395.20, $this->calc->vakantiegeldOpbouw(4940.0, 0.08));
		// A lower percentage is floored at the WML minimum.
		self::assertSame(395.20, $this->calc->vakantiegeldOpbouw(4940.0, 0.05));

	}//end testVakantiegeldOpbouw()

	/**
	 * Net pay = taxable - tax - SV - employee pension + tax-free allowances (REQ-PAY-001).
	 *
	 * @return void
	 */
	public function testNettoBetaald(): void {
		// Fiscaal 4940 - LH 1083,40 - SV 0 - pensioen-wn 355,68 + vrij 19,20 = 3520,12.
		$net = $this->calc->nettoBetaald(4940.0, 1083.40, 0.0, 355.68, 19.20);
		self::assertSame(3520.12, $net);

	}//end testNettoBetaald()

	/**
	 * Pro-rata gross scales by worked vs total days (REQ-PAY-014).
	 *
	 * @return void
	 */
	public function testProRataBruto(): void {
		// Full month, started on day 11 -> 12 of 22 working days.
		self::assertSame(2694.55, $this->calc->proRataBruto(4940.0, 12, 22));
		// Full attendance returns the full gross.
		self::assertSame(4940.0, $this->calc->proRataBruto(4940.0, 22, 22));
		// Zero days returns zero.
		self::assertSame(0.0, $this->calc->proRataBruto(4940.0, 0, 22));

	}//end testProRataBruto()

	/**
	 * DGA below the norm without an exception triggers a warning, never a block (REQ-PAY-009).
	 *
	 * @return void
	 */
	public function testDgaWarningBelowNorm(): void {
		$check = $this->calc->dgaGebruikelijkLoonCheck(true, 48000.0, null);
		self::assertTrue($check['onderNorm']);
		self::assertNotNull($check['waarschuwing']);
		self::assertSame(56000.0, $check['norm']);

	}//end testDgaWarningBelowNorm()

	/**
	 * A recorded exception suppresses the DGA warning (REQ-PAY-009).
	 *
	 * @return void
	 */
	public function testDgaExceptionSuppressesWarning(): void {
		$check = $this->calc->dgaGebruikelijkLoonCheck(true, 48000.0, 'Startup, beperkte winstgevendheid aangetoond');
		self::assertTrue($check['onderNorm']);
		self::assertNull($check['waarschuwing']);

	}//end testDgaExceptionSuppressesWarning()

	/**
	 * A DGA at or above the norm gets no warning; a non-DGA is never flagged (REQ-PAY-009).
	 *
	 * @return void
	 */
	public function testDgaAboveNormAndNonDga(): void {
		self::assertNull($this->calc->dgaGebruikelijkLoonCheck(true, 60000.0, null)['waarschuwing']);
		self::assertNull($this->calc->dgaGebruikelijkLoonCheck(false, 1000.0, null)['waarschuwing']);

	}//end testDgaAboveNormAndNonDga()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
