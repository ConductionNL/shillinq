<?php

/**
 * Contract of WbsoDocumentService
 *
 * The public surface of WbsoDocumentService that other components call, so they depend
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
 * Contract of WbsoDocumentService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WbsoDocumentServiceInterface {

	/**
	 * Transition filed → archived (REQ-WBSO-009).
	 *
	 * Requires the document to have been in `filed` for at least seven years,
	 * OR an explicit `$allowEarly=true` (admin override).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $documentId Document to archive.
	 * @param string $reason Free-text reason from the auditor / admin.
	 * @param bool $allowEarly Admin override; when true the seven-year gate is skipped.
	 *
	 * @return array<string,mixed> Updated record.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function archiveDocument(
		string $administrationId,
		string $documentId,
		string $reason,
		bool $allowEarly = false,
	): array;

	/**
	 * Create a draft document (REQ-WBSO-003).
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $payload Document fields.
	 *
	 * @return array<string,mixed> Persisted record.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function createDocument(string $administrationId, array $payload): array;

	/**
	 * Transition draft → filed (REQ-WBSO-007).
	 *
	 * Requires the document to carry a non-empty `fileReference` (the
	 * docudesk URI pointing at the uploaded file). The caller is responsible
	 * for the upload itself; this method finalises the bookkeeping side.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $documentId Document to file.
	 * @param string $approver Nextcloud user id of the approving user.
	 *
	 * @return array<string,mixed> Updated record.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function fileDocument(string $administrationId, string $documentId, string $approver): array;

	/**
	 * Fetch one document by id within the administration.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $documentId Document id or documentNumber.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function getDocument(string $administrationId, string $documentId): ?array;

	/**
	 * Return every document for an administration.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function getDocumentsByAdministration(string $administrationId): array;

	/**
	 * Filter documents by type.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $type documentType to filter on.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function getDocumentsByType(string $administrationId, string $type): array;

	/**
	 * Pure helper: has the seven-year retention boundary elapsed?
	 *
	 * @param string $filedAt ISO-8601 timestamp the document was filed.
	 *
	 * @return bool
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function isRetentionElapsed(string $filedAt): bool;
}//end interface
