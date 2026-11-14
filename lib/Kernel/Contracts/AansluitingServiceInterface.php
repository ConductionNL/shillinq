<?php

/**
 * Contract of AansluitingService
 *
 * The public surface of AansluitingService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of AansluitingService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AansluitingServiceInterface {

	/**
	 * Compute (or recompute) the AansluitingResult for one Aansluiting
	 * definition + fiscal period (REQ-AANS-004).
	 *
	 * Resolves source A / source B per the definition's aansluitingType,
	 * computes the signed difference and tolerance decision, and persists an
	 * AansluitingResult. Idempotent per (aansluitingId, periodId): a result
	 * already `explained` or `resolved` is left untouched by recompute — the
	 * caller must `reopen()` it first (REQ-AANS-006, avoids silently
	 * clobbering an operator's explanation).
	 *
	 * @param string $reconciliationId The Aansluiting definition id.
	 * @param string $periodId The fiscal period to compute (e.g. '2026-Q2').
	 *
	 * @return array<string,mixed> The (created or updated) AansluitingResult record.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function compute(string $reconciliationId, string $periodId): array;

	/**
	 * Record an operator's explanation for an open AansluitingResult and
	 * transition it to `explained` (REQ-AANS-006).
	 *
	 * @param string $resultId The AansluitingResult id.
	 * @param string $reasonCode One of timing/error/adjustment/other.
	 * @param string $reasonText Free-text operator explanation (required, non-empty).
	 * @param string $actor The acting user id (audit-trailed).
	 *
	 * @return array<string,mixed> The updated AansluitingResult record.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function explain(string $resultId, string $reasonCode, string $reasonText, string $actor): array;

	/**
	 * Reopen an explained or resolved AansluitingResult (REQ-AANS-006), e.g.
	 * because a later recompute would find fresh drift.
	 *
	 * @param string $resultId The AansluitingResult id.
	 * @param string $actor The acting user id (audit-trailed).
	 * @param string $reason Free-text reason for reopening (audit-trailed).
	 *
	 * @return array<string,mixed> The updated AansluitingResult record.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function reopen(string $resultId, string $actor, string $reason): array;

	/**
	 * Confirm an explained AansluitingResult is settled and transition it to
	 * `resolved` (REQ-AANS-006). Gated by AansluitingResolutionGuard::canResolve
	 * (ADR-031 exception — requires a non-empty explanationReasonText).
	 *
	 * @param string $resultId The AansluitingResult id.
	 * @param string $actor The acting user id (audit-trailed).
	 *
	 * @return array<string,mixed> The updated AansluitingResult record.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function resolve(string $resultId, string $actor): array;
}//end interface
