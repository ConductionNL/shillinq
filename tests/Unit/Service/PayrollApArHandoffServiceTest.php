<?php

/**
 * Unit tests for the PayrollApArHandoffService.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-011-lh-aangifte.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PayrollApArHandoffService;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the AP transaction hand-off splits loonafdracht into BLD + UWV.
 *
 * REQ-PAY-011: LHAfdracht is the source of two AP transactions — Belastingdienst
 * (loonheffing + ZVW + WKR-eindheffingen) and UWV (premies SV werkgever) —
 * both due on the LHAfdracht.vervaldagAfdracht (last day of the next month).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollApArHandoffServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var PayrollApArHandoffService
	 */
	private PayrollApArHandoffService $svc;

	/**
	 * Build a fresh service before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new PayrollApArHandoffService();

	}//end setUp()

	/**
	 * Builds a Belastingdienst + UWV AP transaction pair from a typical LHAfdracht.
	 *
	 * @return void
	 */
	public function testSplitsIntoBelastingdienstAndUwv(): void {
		$payloads = $this->svc->toApTransactionPayloads(
			lhRemittance: [
				'employerId' => 'wg-1',
				'periodId' => 'lp-2026-05',
				'totalPayrollTax' => 18620.10,
				'totalSocialInsuranceContributions' => 7559.40,
				'totalHealthInsurance' => 3654.00,
				'totalFinalLeviesWorkRelatedCosts' => 240.00,
				'dueDateRemittance' => '2026-06-30',
				'administrationId' => 'adm-1',
			]
		);

		$this->assertCount(2, $payloads);

		$bld = $payloads[0];
		$this->assertSame(PayrollApArHandoffService::PAYEE_BELASTINGDIENST, $bld['payee']);
		$this->assertEqualsWithDelta(22514.10, $bld['amount'], 0.005);
		$this->assertSame('EUR', $bld['currency']);
		$this->assertSame('2026-06-30', $bld['dueDate']);
		$this->assertSame('wg-1', $bld['employerId']);
		$this->assertSame('lp-2026-05', $bld['periodId']);
		$this->assertSame('LHAfdracht', $bld['source']);
		$this->assertSame('wg-1/lp-2026-05', $bld['sourceRef']);
		$this->assertSame(18620.10, $bld['breakdown']['payrollTax']);
		$this->assertSame(3654.00, $bld['breakdown']['zvw']);
		$this->assertSame(240.00, $bld['breakdown']['eindheffingenWKR']);

		$uwv = $payloads[1];
		$this->assertSame(PayrollApArHandoffService::PAYEE_UWV, $uwv['payee']);
		$this->assertEqualsWithDelta(7559.40, $uwv['amount'], 0.005);
		$this->assertSame('2026-06-30', $uwv['dueDate']);
		$this->assertSame(7559.40, $uwv['breakdown']['premiesSV']);

	}//end testSplitsIntoBelastingdienstAndUwv()

	/**
	 * Omits a payload whose amount is zero.
	 *
	 * @return void
	 */
	public function testOmitsZeroAmountPayloads(): void {
		$payloads = $this->svc->toApTransactionPayloads(
			lhRemittance: [
				'employerId' => 'wg-1',
				'periodId' => 'lp-2026-05',
				'totalPayrollTax' => 0.0,
				'totalSocialInsuranceContributions' => 1234.56,
				'totalHealthInsurance' => 0.0,
				'totalFinalLeviesWorkRelatedCosts' => 0.0,
				'dueDateRemittance' => '2026-06-30',
			]
		);

		$this->assertCount(1, $payloads);
		$this->assertSame(PayrollApArHandoffService::PAYEE_UWV, $payloads[0]['payee']);

	}//end testOmitsZeroAmountPayloads()

	/**
	 * Returns no payloads when the LHAfdracht has no amounts at all.
	 *
	 * @return void
	 */
	public function testReturnsEmptyWhenLhAfdrachtEmpty(): void {
		$payloads = $this->svc->toApTransactionPayloads(lhRemittance: []);
		$this->assertSame([], $payloads);

	}//end testReturnsEmptyWhenLhAfdrachtEmpty()
}//end class
