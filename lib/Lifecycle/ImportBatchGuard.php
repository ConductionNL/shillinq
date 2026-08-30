<?php

/**
 * Import Batch Lifecycle Guard
 *
 * Fail-closed PHP guards referenced from the ImportBatch
 * x-openregister-lifecycle transitions (REQ-AIM-002/009). The declarative
 * lifecycle DSL cannot yet express the cross-schema preconditions these
 * guards enforce (a populated scope/source set for parse; an OPEN target
 * FiscalPeriod for reversal), so they live here as the documented ADR-031
 * PHP-guard seam — mirroring APGuard / StatementParser. Every guard denies
 * on uncertainty (CWE-863).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * Fail-closed guards for the ImportBatch lifecycle (REQ-AIM-002/009).
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
 */
class ImportBatchGuard {
	/**
	 * Whether a batch may leave draft for parsing (REQ-AIM-002).
	 *
	 * Requires sourceFiles, administrationId, migrationDate, and at least one
	 * scope flag set true. Fail-closed: any missing precondition denies.
	 *
	 * @param array<string,mixed> $batch The ImportBatch object data.
	 *
	 * @return bool True only when every parse precondition is satisfied.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
	 */
	public function canParse(array $batch): bool {
		$sourceFiles = ($batch['sourceFiles'] ?? []);
		if (is_array($sourceFiles) === false || $sourceFiles === []) {
			return false;
		}

		if (empty($batch['administrationId']) === true) {
			return false;
		}

		if (empty($batch['migrationDate']) === true) {
			return false;
		}

		$scope = ($batch['scope'] ?? []);
		if (is_array($scope) === false) {
			return false;
		}

		foreach (['chartOfAccounts', 'openingBalance', 'openItems', 'relations'] as $flag) {
			if (($scope[$flag] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end canParse()

	/**
	 * Whether a posted batch may be reversed (REQ-AIM-009).
	 *
	 * True only when the batch is in the posted state AND the target period is
	 * still open. Fail-closed: a closed period or any non-posted state denies,
	 * so reversal can never unwind books in a closed period.
	 *
	 * @param array<string,mixed> $batch The ImportBatch object data.
	 * @param bool $periodOpen Whether the target FiscalPeriod is open
	 *                         (resolved by the caller via the
	 *                         bookkeeping-period-close surface).
	 *
	 * @return bool True only for a posted batch in an open period.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
	 */
	public function canReverse(array $batch, bool $periodOpen): bool {
		if (($batch['status'] ?? '') !== 'posted') {
			return false;
		}

		return $periodOpen === true;
	}//end canReverse()
}//end class
