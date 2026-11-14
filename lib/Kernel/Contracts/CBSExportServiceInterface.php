<?php

/**
 * Contract of CBSExportService
 *
 * The public surface of CBSExportService that other components call, so they depend
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

use DateTimeInterface;

/**
 * Contract of CBSExportService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CBSExportServiceInterface {

	/**
	 * Generate a CBSSubmission for an administration + reporting period.
	 *
	 * Pipeline (REQ-CBS-004):
	 *   1. Query active Account records for the administration.
	 *   2. Load GL transactions + GL lines for the period.
	 *   3. Load account → CBS mapping (settings, fallback default).
	 *   4. Aggregate GL line amounts by CBS classification.
	 *   5. Persist a CBSLine record for each non-zero classification.
	 *   6. Generate IV3 JSON for the submission + lines.
	 *   7. Store JSON reference + checksum on the CBSSubmission record.
	 *   8. Return submission array in `draft` state.
	 *
	 * @param string $administrationId Administration scope.
	 * @param DateTimeInterface $periodStart Reporting period start (inclusive).
	 * @param DateTimeInterface $periodEnd Reporting period end (inclusive).
	 * @param array $organization Required organization metadata: legalName, kvkNumber, taxIdentificationNumber.
	 *
	 * @return array<string,mixed> The persisted CBSSubmission (id + fields).
	 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
	 */
	public function generateSubmission(
		string $administrationId,
		DateTimeInterface $periodStart,
		DateTimeInterface $periodEnd,
		array $organization,
	): array;

	/**
	 * Validate a CBSSubmission against the four CBS rule families (REQ-CBS-008).
	 *
	 * Structural — all required CBSLine records exist; no missing
	 * classifications. Balancing — sum of CBSLine.aggregatedAmount equals the
	 * total absolute GL posted amount for the period. Accounting — no GL
	 * account appears under multiple classifications (mapping conflict).
	 * Completeness — organization KvK and tax id are correctly formatted.
	 *
	 * Critical errors block state transition; warnings are recorded but allow
	 * transition.
	 *
	 * @param array $submission The CBSSubmission record (with id).
	 *
	 * @return array{valid:bool,errors:array<int,string>,warnings:array<int,string>}
	 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
	 */
	public function validateSubmission(array $submission): array;
}//end interface
