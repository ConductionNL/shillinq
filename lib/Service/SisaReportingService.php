<?php

/**
 * SiSa Reporting Service
 *
 * ADR-031 exception: single-method read-only service implementing the SiSa
 * audit opinion calculation rule per REQ-SISA-009. Invoked by the SisaReport
 * lifecycle finalize transition when OR's x-openregister-calculations
 * conditional expression engine is not yet stable. Remove when OR lands
 * stable conditional aggregation support.
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
 * @spec openspec/changes/bookkeeping-sisa-reporting/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Read-only ADR-031 exception: derives audit opinion from SisaReport finding counts.
 *
 * The calculateAuditOpinion() rule mirrors the x-openregister-calculations
 * expression declared in lib/Settings/shillinq_register.json on SisaReport.
 * Both must stay in sync — the schema is the primary spec, the service is
 * the fallback. canBeFinalized() is the lifecycle precondition guard
 * referenced in the SisaReport finalize transition.
 *
 * @spec openspec/changes/bookkeeping-sisa-reporting/tasks.md#task-12
 */
class SisaReportingService {
	/**
	 * Derive the audit opinion from a SisaReport's aggregated finding counts.
	 *
	 * Rule per REQ-SISA-009 (priority order — first match wins):
	 *  1. Any overdue remediation                         → disclaimer
	 *  2. Any critical finding OR ≥3 major findings       → adverse
	 *  3. 1-2 major findings, 0 critical, 0 overdue       → qualified
	 *  4. No findings, no overdue                         → unqualified
	 *
	 * @param array<string,mixed> $sisaReport SisaReport object array from OpenRegister.
	 *
	 * @return string One of: unqualified, qualified, adverse, disclaimer.
	 *
	 * @spec openspec/changes/bookkeeping-sisa-reporting/tasks.md#task-12
	 */
	public function calculateAuditOpinion(array $sisaReport): string {
		$overdueCount = (int)($sisaReport['remediationOverdueCount'] ?? 0);
		$criticalCount = (int)($sisaReport['criticalFindingsCount'] ?? 0);
		$majorCount = (int)($sisaReport['majorFindingsCount'] ?? 0);

		if ($overdueCount > 0) {
			return 'disclaimer';
		}

		if ($criticalCount > 0 || $majorCount >= 3) {
			return 'adverse';
		}

		if ($majorCount >= 1) {
			return 'qualified';
		}

		return 'unqualified';
	}//end calculateAuditOpinion()

	/**
	 * Lifecycle precondition guard for the SisaReport `finalize` transition.
	 *
	 * Returns true (permit finalization) when the SisaReport has a reportDate,
	 * fiscalYear, and administrationId — the minimum required before the audit
	 * opinion can be meaningfully computed. Returns false if any are missing.
	 *
	 * Referenced from lib/Settings/shillinq_register.json SisaReport lifecycle
	 * finalize.requires as "OCA\\Shillinq\\Service\\SisaReportingService::canBeFinalized".
	 *
	 * @param array<string,mixed> $sisaReport SisaReport object array from OpenRegister.
	 *
	 * @return bool True when finalization is permitted.
	 *
	 * @spec openspec/changes/bookkeeping-sisa-reporting/tasks.md#task-12
	 */
	public function canBeFinalized(array $sisaReport): bool {
		return empty($sisaReport['reportDate']) === false
			&& empty($sisaReport['fiscalYear']) === false
			&& empty($sisaReport['administrationId']) === false;

	}//end canBeFinalized()
}//end class
