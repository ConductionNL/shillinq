<?php

/**
 * Contract of VATReturnService
 *
 * The public surface of VATReturnService that other components call, so they depend
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
 * Contract of VATReturnService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VATReturnServiceInterface {

	/**
	 * Create a new VAT return for a period + regime and derive its lines (REQ-VAT-001).
	 *
	 * @param string $administrationId Server-resolved administration scope.
	 * @param string $period One of quarter | month | year.
	 * @param int $periodYear Fiscal year (e.g. 2026).
	 * @param int $periodNumber Period within year (Q: 1-4, M: 1-12, Y: 1).
	 * @param string $regime One of standard | kor | reverse-charge.
	 *
	 * @return array<string,mixed> The created VATReturn record (incl. id).
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function createReturn(
		string $administrationId,
		string $period,
		int $periodYear,
		int $periodNumber,
		string $regime,
	): array;

	/**
	 * Look a VATReturn up by id, returning null when it genuinely does not exist.
	 *
	 * This is the ONLY correct way to resolve a BtwAangifte by id. OpenRegister's
	 * `ObjectService::find()` is declared `: ?ObjectEntity` — null when the row
	 * genuinely does not exist, an ObjectEntity when it does, and NEVER an array.
	 * Every caller therefore has to normalise the entity before reading fields
	 * off it; callers that tested `is_array()` on the return value reported "not
	 * found" for every row that WAS found (see VATReturnController::show()).
	 *
	 * Exposed so HTTP callers can distinguish "absent" (404) from "present"
	 * without catching an exception, while `fetchReturn()` keeps the
	 * throw-on-missing contract the internal pipeline relies on.
	 *
	 * @param string $returnId VATReturn id.
	 *
	 * @return array<string,mixed>|null The VATReturn record, or null when absent.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function findReturn(string $returnId): ?array;

	/**
	 * Rebase a submitted return back to draft and re-derive its VAT lines (REQ-VAT-008).
	 *
	 * @param string $returnId The VATReturn id.
	 * @param string $userId Actor for the audit-trail log line.
	 *
	 * @return array<string,mixed> The refreshed VATReturn record.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function rebaseReturn(string $returnId, string $userId): array;

	/**
	 * Submit a VAT return (REQ-VAT-005) — draft → submitted with non-negative totals.
	 *
	 * @param string $returnId The VATReturn id.
	 * @param string $userId The actor's user id (for the audit-trail log line).
	 *
	 * @return array<string,mixed> The updated VATReturn record.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function submitReturn(string $returnId, string $userId): array;
}//end interface
