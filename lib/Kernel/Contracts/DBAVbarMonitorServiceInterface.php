<?php

/**
 * Contract of DBAVbarMonitorService
 *
 * The public surface of DBAVbarMonitorService that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of DBAVbarMonitorService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DBAVbarMonitorServiceInterface {

	/**
	 * Result codes for `assess()`.
	 */
	public const RESULT_OK = 'OK';

	/**
	 * Assess a factuur line against the VBAR grens (REQ-DBA-016).
	 *
	 * Returns one of:
	 *   - OK: rate >= threshold;
	 *   - WARN: rate < threshold and compliance-mode is soft/intermediair;
	 *   - BLOCK: rate < threshold and compliance-mode is hard.
	 *
	 * @param int $amountCents Factuurbedrag (eurocenten).
	 * @param float $hours Aantal gefactureerde uren.
	 * @param string $administrationId Per-administration scoping.
	 *
	 * @return array<string,mixed> { result, uurtariefCents, vbarGrensCents, message }
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function assess(int $amountCents, float $hours, string $administrationId): array;

	/**
	 * Emit a VBAR_GRENS_ONDERSCHREDEN flag against a DBAOpdracht (REQ-DBA-016).
	 *
	 * Idempotent: a single OPEN flag per (opdrachtId, factuurId) is enforced via
	 * a details-payload check.
	 *
	 * @param string $assignmentId FK to DBAOpdracht.
	 * @param string $administrationId Administration scoping.
	 * @param string $invoiceId The factuur that triggered the check.
	 * @param int $hourlyRateCents The computed effective rate.
	 * @param int $vbarGrensCents The threshold applied.
	 *
	 * @return bool True when a flag record was written.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function emitFlag(
		string $assignmentId,
		string $administrationId,
		string $invoiceId,
		int $hourlyRateCents,
		int $vbarGrensCents,
	): bool;
}//end interface
