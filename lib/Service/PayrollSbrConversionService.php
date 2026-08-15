<?php

/**
 * Payroll SBR/XBRL Conversion Service
 *
 * Converts a VOORBEREID LHAfdracht aggregate into an SBR/XBRL instance payload
 * (REQ-PAY-011) ready for hand-off to the bookkeeping-loonaangifte-sbr app for
 * Digipoort submission. This service NEVER submits to Digipoort itself; it
 * only renders the instance metadata + line items + stamps a deterministic
 * sbrInstanceRef so the SBR app picks up where this service stops.
 *
 * Boundary: this app owns the loonheffing arithmetic and the Loonaangifte
 * taxonomy mapping (LA-XX-2026); the SBR app owns transport, signing and
 * Digipoort acknowledgements. The contract between them is the payload
 * returned by toSbrInstancePayload(): an array shaped like an XBRL instance
 * with collectie, identificerendePeriode, werkgever, loonheffingTotaal,
 * premiesSVTotaal, eindheffingenWKR, ZVW and the deterministic instanceRef.
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
 * Pure SBR/XBRL conversion for LHAfdracht payloads (REQ-PAY-011).
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class PayrollSbrConversionService {

	/**
	 * SBR Nederland Loonaangifte taxonomy version targeted by this converter.
	 *
	 * @var string
	 */
	public const SBR_TAXONOMY_VERSION = 'LA-XX-2026';

	/**
	 * Convert an LHAfdracht into an SBR/XBRL instance hand-off payload.
	 *
	 * The payload is deterministic for a (werkgever, periode) pair so retries
	 * by the SBR app cannot create duplicate Digipoort submissions; the SBR
	 * app stamps the Digipoort kenmerk back on the LHAfdracht once accepted.
	 *
	 * @param array<string,mixed> $lhRemittance The LHAfdracht in VOORBEREID status.
	 *
	 * @return array<string,mixed> The SBR/XBRL instance hand-off payload.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function toSbrInstancePayload(array $lhRemittance): array {
		$employerId = (string)($lhRemittance['employerId'] ?? '');
		$periodId = (string)($lhRemittance['periodId'] ?? '');

		return [
			'taxonomyVersion' => self::SBR_TAXONOMY_VERSION,
			'instanceRef' => $this->deriveInstanceRef(employerId: $employerId, periodId: $periodId),
			'collectie' => 'Loonaangifte',
			'identificerendePeriode' => $periodId,
			'werkgever' => $employerId,
			'loonheffingTotaal' => (float)($lhRemittance['totalPayrollTax'] ?? 0.0),
			'premiesSVTotaal' => (float)($lhRemittance['totalSocialInsuranceContributions'] ?? 0.0),
			'zvwTotaal' => (float)($lhRemittance['totalHealthInsurance'] ?? 0.0),
			'eindheffingenWKR' => (float)($lhRemittance['totalFinalLeviesWorkRelatedCosts'] ?? 0.0),
			'totalRemittance' => (float)($lhRemittance['totalRemittance'] ?? 0.0),
			'dueDateRemittance' => ($lhRemittance['dueDateRemittance'] ?? null),
			'status' => 'READY_FOR_SBR',
		];

	}//end toSbrInstancePayload()

	/**
	 * Stamp the deterministic sbrInstanceRef on an LHAfdracht record.
	 *
	 * Returns a new array with the sbrInstanceRef field populated; the caller
	 * is responsible for persisting via PayrollService::saveObject (this is a
	 * pure function).
	 *
	 * @param array<string,mixed> $lhRemittance The LHAfdracht record.
	 *
	 * @return array<string,mixed> A copy with sbrInstanceRef stamped.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function stampInstanceRef(array $lhRemittance): array {
		$employerId = (string)($lhRemittance['employerId'] ?? '');
		$periodId = (string)($lhRemittance['periodId'] ?? '');

		$stamped = $lhRemittance;
		$stamped['sbrInstanceRef'] = $this->deriveInstanceRef(employerId: $employerId, periodId: $periodId);

		return $stamped;
	}//end stampInstanceRef()

	/**
	 * Derive the deterministic SBR instance reference (idempotent).
	 *
	 * @param string $employerId Employer id.
	 * @param string $periodId Period id.
	 *
	 * @return string Instance reference (taxonomy-werkgever-periode).
	 */
	private function deriveInstanceRef(string $employerId, string $periodId): string {
		$safeWg = preg_replace('/[^A-Za-z0-9_.\-]/', '', $employerId) ?? '';
		$safePe = preg_replace('/[^A-Za-z0-9_.\-]/', '', $periodId) ?? '';

		return sprintf('%s-%s-%s', self::SBR_TAXONOMY_VERSION, $safeWg, $safePe);
	}//end deriveInstanceRef()
}//end class
