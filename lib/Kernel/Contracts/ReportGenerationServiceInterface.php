<?php

/**
 * Contract of ReportGenerationService
 *
 * The public surface of ReportGenerationService that other components call, so they depend
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

use OCP\Files\File;

/**
 * Contract of ReportGenerationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportGenerationServiceInterface {

	/**
	 * Look up a single GeneratedReport record by id.
	 *
	 * @param string $id The GeneratedReport id.
	 *
	 * @return array<string, mixed>|null The record, or null when missing.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function findRecord(string $id): ?array;

	/**
	 * Generate a report: render it, store + tag the file, record a GeneratedReport.
	 *
	 * @param string $reportType ReportCatalogue report-type id (e.g. 'vat-return').
	 * @param string $period Reporting period (e.g. '2026', '2026-Q1', '2026-03').
	 * @param string $administrationId Administration the report is generated for.
	 * @param string $format One of the report's catalogue formats.
	 * @param string|null $asUserId The user the report is produced for when there
	 *                              is no session (a scheduled run); null = the session user.
	 *
	 * @return array<string, mixed> The recorded GeneratedReport (incl. fileId + downloadPath),
	 *                              or an `{ error: ... }` envelope when generation cannot proceed.
	 *
	 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function generate(string $reportType, string $period, string $administrationId, string $format, ?string $asUserId=null): array;

	/**
	 * List recorded GeneratedReport records, optionally filtered.
	 *
	 * @param array<string, mixed> $filters Optional filters: reportType, period,
	 *                                      administrationId, category.
	 *
	 * @return array<int, mixed> The matching GeneratedReport records (empty on failure).
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function listGenerated(array $filters): array;

	/**
	 * Resolve the stored Nextcloud File for a GeneratedReport id.
	 *
	 * @param string $id The GeneratedReport id.
	 *
	 * @return File|null The stored file, or null when the record/file is not resolvable.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function resolveFile(string $id): ?File;

	/**
	 * Resolve the stored Nextcloud File for an ALREADY-AUTHORISED GeneratedReport record.
	 *
	 * ⚠️ This method performs NO authorisation. The caller must have established
	 * that the current user may read `$record` — in practice
	 * `AdministrationContextService::canAccess($record['administrationId'])` —
	 * BEFORE calling it. It is split out from resolveFile() precisely so the
	 * caller can interpose that check between "load the record" and "read the
	 * bytes" (ADR-005; see ReportingController::download()).
	 *
	 * Resolution order, tightest first:
	 *  1. the CURRENT user's Files home — the same home storeFile() writes into
	 *     (`:410 getUserFolder($userId)`), so this is the path a report's own
	 *     author always takes;
	 *  2. the instance-wide file cache, but only for a node whose path is exactly
	 *     the `filePath` recorded on the report. Previously this branch was an
	 *     unconstrained `IRootFolder::getById()` followed by an unconstrained
	 *     `IRootFolder::get($path)`, which resolves across EVERY user's storage.
	 *     It is retained (narrowed) so a colleague in the same administration can
	 *     still download a report a co-worker generated.
	 *
	 * @param array<string,mixed> $record The GeneratedReport record.
	 *
	 * @return File|null The stored file, or null when the record/file is not resolvable.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function resolveRecordFile(array $record): ?File;
}//end interface
