<?php

/**
 * XAF Generic Import Profile
 *
 * The standard XAF 3.2 pass-through baseline (REQ-AIM-003). Used when the
 * source emits a clean, standards-compliant auditfile (including Shillinq's
 * own export — the round-trip case in REQ-AIM-003). No dialect quirks; the
 * standard auditfile already carries opening balances and relation details,
 * so the CSV column maps are empty.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Import\ImportProfile
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Import\ImportProfile;

use OCA\Shillinq\Service\Import\ImportProfileInterface;

/**
 * Standard XAF 3.2 pass-through profile (REQ-AIM-003).
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
 */
class XafGenericProfile implements ImportProfileInterface {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function sourceSystem(): string {
		return 'xaf-generic';
	}//end sourceSystem()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $parsed Parser output.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function normalizeLedgerAccounts(array $parsed): array {
		return ($parsed['ledgerAccounts'] ?? []);
	}//end normalizeLedgerAccounts()

	/**
	 * {@inheritDoc}
	 *
	 * Standard XAF carries opening balances and relation details inline, so no
	 * companion CSV is needed — the map is intentionally empty.
	 *
	 * @param string $artifact Artifact kind.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function mapCsvColumns(string $artifact): array {
		unset($artifact);
		return [];
	}//end mapCsvColumns()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $parsed Parser output.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function applyDialectQuirks(array $parsed): array {
		return $parsed;
	}//end applyDialectQuirks()
}//end class
