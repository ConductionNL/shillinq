<?php

/**
 * Subsidie Verantwoording Service
 *
 * ADR-031 exception-path service for the bookkeeping-subsidie-verantwoording
 * capability (T3 governance). Holds the two auto-generation rules that OR's
 * declarative lifecycle-action engine cannot yet express because they require
 * cross-schema reads plus date arithmetic:
 *
 *  - buildVerantwoordingForGrant(): on a Subsidie grant transition to
 *    `verleend` (awarded) or `uitbetaald` (disbursed), produce the draft
 *    SubsidieVerantwoording payload with an auto-calculated reportingPeriod
 *    (REQ-SUBV-009 / REQ-SUBV-002).
 *  - buildAuditorStatementForVerantwoording(): when a SubsidieVerantwoording is
 *    created for a grant at or above the auditor threshold, produce the pending
 *    AuditorStatement payload (REQ-SUBV-006).
 *
 * Both builders are pure (no I/O) so they are unit-testable; persistChange()
 * is the thin idempotent persistence wrapper invoked from the lifecycle action
 * / repair context. ADR-022: only the real ObjectService API is used
 * (setRegister/setSchema/findAll/saveObject).
 *
 * ADR-031 exception reason: cross-schema reads + reporting-period date
 * arithmetic are not yet expressible in the declarative lifecycle DSL. When the
 * engine gains those capabilities, fold these rules into declarative actions and
 * delete this file.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Throwable;

/**
 * Auto-generation rules for SubsidieVerantwoording and AuditorStatement records.
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */
class SubsidieVerantwoordingService {
	/**
	 * Default auditor threshold in the administration's base currency (EUR).
	 *
	 * REQ-SUBV-006 / design D3 — grants at or above this awarded amount require
	 * an auditor statement. Operators override via the `auditor_threshold` app
	 * config key.
	 */
	public const DEFAULT_AUDIT_THRESHOLD = 25000.0;

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the auditor threshold.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Build the draft SubsidieVerantwoording payload for a grant award/disbursement.
	 *
	 * REQ-SUBV-009 / REQ-SUBV-002: reportDate is the supplied reference date
	 * (today by default), reportingPeriod spans from the grant award date to the
	 * report date, and status starts at `draft`. awardedAmount is copied onto the
	 * verantwoording so the approval guard (REQ-SUBV-003) and the auditor-trigger
	 * (REQ-SUBV-006) can evaluate the threshold without a cross-schema join.
	 *
	 * Pure: no I/O. Returns the payload; the caller persists it via persistChange().
	 *
	 * @param array<string,mixed> $grant The Subsidie grant object.
	 * @param string|null $reportDate The report reference date (Y-m-d); today when null.
	 *
	 * @return array<string,mixed> The draft SubsidieVerantwoording payload.
	 *
	 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
	 */
	public function buildVerantwoordingForGrant(array $grant, ?string $reportDate = null): array {
		$report = $reportDate;
		if ($report === null || $report === '') {
			$report = (new DateTimeImmutable())->format('Y-m-d');
		}

		$grantId = (string)($grant['subsidyNumber'] ?? $grant['grantId'] ?? '');
		$awardDate = (string)($grant['awardDate'] ?? '');
		$administrationId = (string)($grant['administrationId'] ?? '');
		$awardedAmount = (float)($grant['awardAmount'] ?? $grant['awardedAmount'] ?? 0.0);

		return [
			'accountabilityId' => 'SV-' . $grantId,
			'grantId' => $grantId,
			'reportDate' => $report,
			'reportingPeriod' => $this->calculateReportingPeriod(awardDate: $awardDate, reportDate: $report),
			'status' => 'draft',
			'awardedAmount' => $awardedAmount,
			'administrationId' => $administrationId,
		];
	}//end buildVerantwoordingForGrant()

	/**
	 * Build the pending AuditorStatement payload when a verantwoording crosses the threshold.
	 *
	 * REQ-SUBV-006: returns null (no auditor statement required) when the grant's
	 * awarded amount is below the auditor threshold; otherwise returns the pending
	 * AuditorStatement payload with auditThresholdApplied true.
	 *
	 * Pure: no I/O. Returns the payload or null; the caller persists it via persistChange().
	 *
	 * @param array<string,mixed> $accountability The SubsidieVerantwoording object.
	 * @param string $auditorUserId The auditor user assigned (may be a placeholder).
	 * @param string|null $auditDate The audit reference date (Y-m-d); today when null.
	 *
	 * @return array<string,mixed>|null The pending AuditorStatement payload, or null when below threshold.
	 *
	 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
	 */
	public function buildAuditorStatementForVerantwoording(
		array $accountability,
		string $auditorUserId,
		?string $auditDate = null,
	): ?array {
		$awardedAmount = (float)($accountability['awardedAmount'] ?? 0.0);
		if ($this->requiresAuditorStatement(awardedAmount: $awardedAmount) === false) {
			return null;
		}

		$audit = $auditDate;
		if ($audit === null || $audit === '') {
			$audit = (new DateTimeImmutable())->format('Y-m-d');
		}

		$grantId = (string)($accountability['grantId'] ?? '');

		return [
			'statementId' => 'AS-' . $grantId,
			'grantId' => $grantId,
			'auditThresholdApplied' => true,
			'auditDate' => $audit,
			'auditorUserId' => $auditorUserId,
			'status' => 'pending',
			'findings' => [],
			'administrationId' => (string)($accountability['administrationId'] ?? ''),
		];
	}//end buildAuditorStatementForVerantwoording()

	/**
	 * Determine whether a grant's awarded amount requires an auditor statement.
	 *
	 * @param float $awardedAmount The grant's awarded amount.
	 *
	 * @return bool True when the amount is at or above the auditor threshold.
	 *
	 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
	 */
	public function requiresAuditorStatement(float $awardedAmount): bool {
		return $awardedAmount >= $this->resolveThreshold();
	}//end requiresAuditorStatement()

	/*
	 * NO persistChange() HERE.
	 *
	 * It took an ObjectService, a register, a schema, a dedupe field and a
	 * payload, and saved the payload unless a record with the same business
	 * key already existed. It had no caller: the two builders above are pure
	 * and return their payloads, and nothing in this app took a payload back
	 * for a save. The generic "save anything into any register/schema"
	 * signature is also the widest possible write surface for the narrowest
	 * possible need, so it is removed rather than wired — a caller that
	 * genuinely needs to persist a verantwoording should call OpenRegister's
	 * ObjectService for its own schema (ADR-022), not through a pass-through
	 * that accepts an arbitrary target.
	 */

	/**
	 * Compute the reporting period string from a grant award date to the report date.
	 *
	 * Returns "<awardDate> to <reportDate>" when the award date is present and
	 * parseable, otherwise falls back to "<reportDate> to <reportDate>".
	 *
	 * @param string $awardDate The grant award date (Y-m-d), may be empty.
	 * @param string $reportDate The report date (Y-m-d).
	 *
	 * @return string The reporting period.
	 */
	private function calculateReportingPeriod(string $awardDate, string $reportDate): string {
		if ($awardDate === '') {
			return $reportDate . ' to ' . $reportDate;
		}

		try {
			$start = (new DateTimeImmutable($awardDate))->format('Y-m-d');
		} catch (Throwable) {
			$start = $reportDate;
		}

		return $start . ' to ' . $reportDate;
	}//end calculateReportingPeriod()

	/**
	 * Resolve the configured auditor threshold, defaulting to EUR 25,000.
	 *
	 * @return float The auditor threshold in the base currency.
	 */
	private function resolveThreshold(): float {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'auditor_threshold', '');
		if ($raw === '' || is_numeric($raw) === false) {
			return self::DEFAULT_AUDIT_THRESHOLD;
		}

		return (float)$raw;
	}//end resolveThreshold()
}//end class
