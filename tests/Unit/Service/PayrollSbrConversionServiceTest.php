<?php

/**
 * Unit tests for the PayrollSbrConversionService.
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

use OCA\Shillinq\Service\PayrollSbrConversionService;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the SBR/XBRL hand-off contract is deterministic and stable.
 *
 * REQ-PAY-011: converted instance ref must be deterministic per (werkgever,
 * periode) so retries cannot create duplicate Digipoort submissions; status
 * starts at READY_FOR_SBR; totals are echoed verbatim from the LHAfdracht.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollSbrConversionServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var PayrollSbrConversionService
	 */
	private PayrollSbrConversionService $svc;

	/**
	 * Build a fresh service before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new PayrollSbrConversionService();

	}//end setUp()

	/**
	 * Converts an LHAfdracht into a complete SBR instance hand-off payload.
	 *
	 * @return void
	 */
	public function testToSbrInstancePayloadEchoesTotalsAndDeterministicRef(): void {
		$lh = [
			'employerId' => 'wg-conduction-bv',
			'periodId' => 'lp-2026-05',
			'totalPayrollTax' => 18620.10,
			'totalSocialInsuranceContributions' => 7559.40,
			'totalHealthInsurance' => 3654.00,
			'totalFinalLeviesWorkRelatedCosts' => 0.0,
			'totalRemittance' => 29833.50,
			'dueDateRemittance' => '2026-06-30',
			'status' => 'VOORBEREID',
		];

		$payload = $this->svc->toSbrInstancePayload(lhRemittance: $lh);

		$this->assertSame(PayrollSbrConversionService::SBR_TAXONOMY_VERSION, $payload['taxonomyVersion']);
		$this->assertSame('LA-XX-2026-wg-conduction-bv-lp-2026-05', $payload['instanceRef']);
		$this->assertSame('Loonaangifte', $payload['collectie']);
		$this->assertSame('READY_FOR_SBR', $payload['status']);
		$this->assertSame(18620.10, $payload['loonheffingTotaal']);
		$this->assertSame(7559.40, $payload['premiesSVTotaal']);
		$this->assertSame(3654.00, $payload['zvwTotaal']);
		$this->assertSame(0.0, $payload['eindheffingenWKR']);
		$this->assertSame(29833.50, $payload['totalRemittance']);
		$this->assertSame('2026-06-30', $payload['dueDateRemittance']);

	}//end testToSbrInstancePayloadEchoesTotalsAndDeterministicRef()

	/**
	 * Stamps a stable, idempotent sbrInstanceRef on the LHAfdracht copy.
	 *
	 * @return void
	 */
	public function testStampInstanceRefIsIdempotent(): void {
		$lh = [
			'employerId' => 'wg-1',
			'periodId' => 'lp-2026-04',
		];

		$first = $this->svc->stampInstanceRef(lhRemittance: $lh);
		$second = $this->svc->stampInstanceRef(lhRemittance: $first);

		$this->assertSame('LA-XX-2026-wg-1-lp-2026-04', $first['sbrInstanceRef']);
		$this->assertSame($first['sbrInstanceRef'], $second['sbrInstanceRef']);

	}//end testStampInstanceRefIsIdempotent()

	/**
	 * Sanitises unsafe characters in identifiers before composing the ref.
	 *
	 * @return void
	 */
	public function testInstanceRefSanitisesIdentifierCharacters(): void {
		$payload = $this->svc->toSbrInstancePayload(
			lhRemittance: [
				'employerId' => 'wg-/?\\!1',
				'periodId' => 'lp 2026/05',
			]
		);

		$this->assertSame('LA-XX-2026-wg-1-lp202605', $payload['instanceRef']);

	}//end testInstanceRefSanitisesIdentifierCharacters()
}//end class
