<?php

/**
 * Import Profile Interface
 *
 * One implementation per source package (e-Boekhouden, Exact Online,
 * Moneybird, SnelStart, plus the xaf-generic baseline) encapsulating that
 * package's XAF dialect quirks and the CSV column maps for artifacts the
 * package omits from its XAF (notably open items and extended relation
 * details) per REQ-AIM-003. The AuditfileParser produces a package-neutral
 * normalised structure; the profile normalises the dialect on top of it and
 * supplies the column maps the pipeline uses to read the companion CSVs.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Import
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

namespace OCA\Shillinq\Service\Import;

/**
 * Contract every package import profile implements (REQ-AIM-003).
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
 */
interface ImportProfileInterface {
	/**
	 * The ImportBatch.sourceSystem enum value this profile handles.
	 *
	 * @return string One of xaf-generic / e-boekhouden / exact-online / moneybird / snelstart.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function sourceSystem(): string;

	/**
	 * Normalise the parsed ledger-account list for this dialect.
	 *
	 * Receives the full AuditfileParser output and returns the cleaned-up
	 * ['ledgerAccounts'=>[...]] list (e.g. trimming a package prefix off codes,
	 * promoting a dialect-specific RGS field). The xaf-generic baseline is a
	 * pass-through.
	 *
	 * @param array<string,mixed> $parsed The AuditfileParser normalised structure.
	 *
	 * @return array<int,array<string,mixed>> Normalised ledger-account field maps.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function normalizeLedgerAccounts(array $parsed): array;

	/**
	 * The CSV column map for the artifacts this package omits from its XAF.
	 *
	 * Returns a map keyed by artifact kind ('open-items', 'relations') whose
	 * value maps a normalised field name to the package's real CSV column
	 * header. The pipeline uses this to read the companion CSV referenced on
	 * the batch (REQ-AIM-003 scenario "package profile supplies the open items
	 * its XAF lacks").
	 *
	 * @param string $artifact The artifact kind ('open-items' | 'relations').
	 *
	 * @return array<string,string> normalisedField => csvColumnHeader.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function mapCsvColumns(string $artifact): array;

	/**
	 * Apply this dialect's quirks to the full parsed structure.
	 *
	 * The single seam where a package's known deviations from standard XAF 3.2
	 * are corrected (date formats, sign conventions, amount-type omissions).
	 * The xaf-generic baseline returns the input unchanged.
	 *
	 * @param array<string,mixed> $parsed The AuditfileParser normalised structure.
	 *
	 * @return array<string,mixed> The dialect-corrected structure.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function applyDialectQuirks(array $parsed): array;
}//end interface
