<?php

/**
 * Contract of VatSuppletieDetectionService
 *
 * The public surface of VatSuppletieDetectionService that other components call, so they depend
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
 * Contract of VatSuppletieDetectionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VatSuppletieDetectionServiceInterface {

	/**
	 * Detect drift between a filed VATReturn and its underlying GL ledger
	 * (REQ-VBTW-013). Creates a `draft` VatCorrection with the filed +
	 * current snapshots when drift exists; returns null when the ledger
	 * still matches what was filed. Never mutates the original VATReturn,
	 * its VATDeclarations, or its VATLines.
	 *
	 * @param string $vatReturnId The filed VATReturn id.
	 *
	 * @return array<string,mixed>|null The created VatCorrection record, or null if no drift.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function detect(string $vatReturnId): ?array;

	/**
	 * A VatCorrection by id, or null when there is none, for a caller that
	 * has to check the correction's administration before acting on it.
	 *
	 * @param string $vatCorrectionId The VatCorrection id.
	 *
	 * @return array<string,mixed>|null The correction, or null when it does not exist.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
	 */
	public function findCorrection(string $vatCorrectionId): ?array;

	/**
	 * Compile a `detected` VatCorrection into a `prepared` one: per-rubriek
	 * deltas, the net correction amount, the €1.000-grens decision, the
	 * 8-week filing deadline, and a draft GL correction posting
	 * (REQ-VBTW-014). The operator still decides whether/when to file —
	 * this method never transitions the VatCorrection past `draft`.
	 *
	 * @param string $vatCorrectionId The `detected` VatCorrection id.
	 *
	 * @return array<string,mixed> The updated VatCorrection record.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function prepare(string $vatCorrectionId): array;
}//end interface
