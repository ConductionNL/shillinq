<?php

/**
 * WBSO Document Lifecycle Guard
 *
 * ADR-031 exception-path guards for the Document lifecycle transitions
 * declared by the bookkeeping-financial-administration spec
 * (REQ-WBSO-003 / REQ-WBSO-007 / REQ-WBSO-009). Referenced from the
 * Document schema's x-openregister-lifecycle.transitions[*].requires.
 *
 * Exception reasons:
 *  - canFile():    fileReference must be set (mirrored in
 *                  WbsoDocumentService::fileDocument(); defence-in-depth).
 *  - canArchive(): the seven-year Archiefwet retention boundary requires
 *                  date arithmetic the declarative engine cannot yet
 *                  express; the guard mirrors
 *                  WbsoDocumentService::isRetentionElapsed().
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs/bookkeeping-financial-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Service\WbsoDocumentService;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guards for Document.file and Document.archive.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs/bookkeeping-financial-administration/spec.md
 */
class WbsoDocumentGuard {
	/**
	 * Construct the guard.
	 *
	 * @param WbsoDocumentService $documents Shared retention math.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly WbsoDocumentService $documents,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for the `file` transition (REQ-WBSO-007).
	 *
	 * @param array<string,mixed> $record The Document record about to transition.
	 *
	 * @return bool
	 */
	public function canFile(array $record): bool {
		if ((string)($record['status'] ?? '') !== 'draft') {
			$this->logger->debug('WbsoDocumentGuard: canFile rejected — not in draft');
			return false;
		}

		if (trim((string)($record['fileReference'] ?? '')) === '') {
			$this->logger->debug('WbsoDocumentGuard: canFile rejected — missing fileReference');
			return false;
		}

		return true;
	}//end canFile()

	/**
	 * Precondition for the `archive` transition (REQ-WBSO-009).
	 *
	 * @param array<string,mixed> $record The Document record about to transition.
	 *
	 * @return bool
	 */
	public function canArchive(array $record): bool {
		if ((string)($record['status'] ?? '') !== 'filed') {
			$this->logger->debug('WbsoDocumentGuard: canArchive rejected — not in filed');
			return false;
		}

		$filedAt = (string)($record['filedAt'] ?? ($record['documentDate'] ?? ''));

		return $this->documents->isRetentionElapsed(filedAt: $filedAt);
	}//end canArchive()
}//end class
