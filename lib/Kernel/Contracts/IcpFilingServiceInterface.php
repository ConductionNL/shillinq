<?php

/**
 * Contract of IcpFilingService
 *
 * The public surface of IcpFilingService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of IcpFilingService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IcpFilingServiceInterface {

	/**
	 * Create a correction ICP-opgaaf for an already-submitted period (REQ-ICP-008).
	 *
	 * Materialises a new `IcpOpgaaf` with `type: correction`, `correctsPeriod` set to
	 * the period being corrected, and the supplied corrective lines (positive or
	 * negative amounts). For each corrective line the original, contemporaneous
	 * `ViesValidation` evidence is re-attached (looked up by buyer VAT-ID) rather than
	 * re-querying VIES, preserving the good-faith defence (Implementing Regulation
	 * 282/2011, Article 18). The new opgaaf starts in `draft` so the reconciliation
	 * gate (REQ-ICP-004) and lifecycle (REQ-ICP-005) still apply before submission.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $correctsPeriod The period being corrected (YYYY-Qn / YYYY-MM).
	 * @param array<int,array<string,mixed>> $correctiveLines Lines: {buyerVatId, supplyType, amountExclVat}.
	 * @param string $reason Free-text correction reason.
	 *
	 * @return array<string,mixed> The draft correction opgaaf plus `evidence` and a `saved` flag.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function createCorrection(
		string $administrationId,
		string $correctsPeriod,
		array $correctiveLines,
		string $reason,
	): array;

	/**
	 * Produce the Belastingdienst inspection bundle for a period (REQ-ICP-010).
	 *
	 * Assembles a ZIP containing the archived XBRL payload, a `kenmerk.txt` with the
	 * Belastingdienst reference, and a CSV of all underlying supplies with their VIES
	 * request IDs (the good-faith audit trail). Source-invoice PDFs are attached when
	 * the OpenRegister file-attachment surface is available; that fetch is left to the
	 * caller because it requires a live instance (documented deferral in tasks). The
	 * ZIP is written to a temp path and the path + manifest are returned.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-ICP-001).
	 * @param string $period Filing period (YYYY-Qn / YYYY-MM).
	 *
	 * @return array{period:string,zipPath:string,supplyCount:int,manifest:array<int,string>,reference:string}
	 *
	 * @throws RuntimeException When the ZIP cannot be created.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function exportForInspection(string $administrationId, string $period): array;

	/**
	 * List ViesValidation outages pending a definitive VIES answer (REQ-ICP-009).
	 *
	 * Drives the daily revalidation job: returns the buyer VAT-IDs that are pending a
	 * definitive VIES answer, together with the age (in days) of the pending outage
	 * evidence so the caller can escalate past the 14-day threshold.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $now ISO-8601 "now" override for deterministic tests.
	 *
	 * @return array<int,array{vatId:string,viesValidationId:string,ageDays:int,escalate:bool}>
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function pendingOutages(string $administrationId, string $now = ''): array;
}//end interface
