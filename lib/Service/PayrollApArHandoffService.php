<?php

/**
 * Payroll AP-AR Handoff Service
 *
 * Converts a VOORBEREID LHAfdracht into APTransaction payloads ready for the
 * bookkeeping-ap-ar app to schedule a payment run to the Belastingdienst (for
 * loonheffing + ZVW + eindheffingen WKR) and UWV (for premies SV). This
 * service does NOT create AP transactions itself — that is the AP-AR app's
 * responsibility; this service produces the canonical payload contract.
 *
 * Splitting the LHAfdracht into two AP transactions (Belastingdienst, UWV)
 * mirrors the actual cash-out: loonheffing+ZVW flow to Belastingdienst,
 * premies SV to UWV. The dueDate carried by both is the LHAfdracht's
 * vervaldagAfdracht (last day of the next month).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure converter: LHAfdracht to AP-AR payment payloads.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
final class PayrollApArHandoffService {

	/**
	 * Payee: Belastingdienst (BLD) — loonheffing + ZVW + WKR-eindheffingen.
	 *
	 * @var string
	 */
	public const PAYEE_BELASTINGDIENST = 'BELASTINGDIENST';

	/**
	 * Payee: UWV — werknemersverzekeringen-premies (AWF, AOF, WHK, WKO).
	 *
	 * @var string
	 */
	public const PAYEE_UWV = 'UWV';

	/**
	 * Build AP transaction payloads from an LHAfdracht (REQ-PAY-011).
	 *
	 * Returns up to two AP transactions: one to the Belastingdienst (LH + ZVW
	 * + WKR-eindheffingen) and one to UWV (premies SV werkgever). A payload is
	 * omitted when its amount is zero.
	 *
	 * @param array<string,mixed> $lhRemittance The LHAfdracht in VOORBEREID status.
	 *
	 * @return array<int,array<string,mixed>> AP transaction payloads.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function toApTransactionPayloads(array $lhRemittance): array {
		$employerId = (string)($lhRemittance['employerId'] ?? '');
		$periodId = (string)($lhRemittance['periodId'] ?? '');
		$dueDate = ($lhRemittance['dueDateRemittance'] ?? null);
		$adminId = ($lhRemittance['administrationId'] ?? null);

		$payrollTax = (float)($lhRemittance['totalPayrollTax'] ?? 0.0);
		$zvw = (float)($lhRemittance['totalHealthInsurance'] ?? 0.0);
		$wkr = (float)($lhRemittance['totalFinalLeviesWorkRelatedCosts'] ?? 0.0);
		$premiesSV = (float)($lhRemittance['totalSocialInsuranceContributions'] ?? 0.0);

		$taxAuthorityAmount = ($payrollTax + $zvw + $wkr);

		$payloads = [];
		if ($taxAuthorityAmount > 0.0) {
			$payloads[] = [
				'payee' => self::PAYEE_BELASTINGDIENST,
				'amount' => round($taxAuthorityAmount, 2),
				'currency' => 'EUR',
				'dueDate' => $dueDate,
				'employerId' => $employerId,
				'periodId' => $periodId,
				'administrationId' => $adminId,
				'breakdown' => [
					'payrollTax' => $payrollTax,
					'zvw' => $zvw,
					'eindheffingenWKR' => $wkr,
				],
				'description' => sprintf('Loonheffing + ZVW + WKR afdracht periode %s', $periodId),
				'source' => 'LHAfdracht',
				'sourceRef' => sprintf('%s/%s', $employerId, $periodId),
			];
		}

		if ($premiesSV > 0.0) {
			$payloads[] = [
				'payee' => self::PAYEE_UWV,
				'amount' => round($premiesSV, 2),
				'currency' => 'EUR',
				'dueDate' => $dueDate,
				'employerId' => $employerId,
				'periodId' => $periodId,
				'administrationId' => $adminId,
				'breakdown' => ['premiesSV' => $premiesSV],
				'description' => sprintf('Premies werknemersverzekeringen periode %s', $periodId),
				'source' => 'LHAfdracht',
				'sourceRef' => sprintf('%s/%s', $employerId, $periodId),
			];
		}

		return $payloads;
	}//end toApTransactionPayloads()
}//end class
