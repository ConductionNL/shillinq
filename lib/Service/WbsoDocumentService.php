<?php

/**
 * WBSO Document Service
 *
 * Server-side helpers for the Document register declared by the
 * bookkeeping-financial-administration spec (REQ-WBSO-003 / REQ-WBSO-007 /
 * REQ-WBSO-009). Enforces:
 *  - draft → filed transitions (requires fileReference);
 *  - filed → archived transitions (gated by the seven-year retention
 *    boundary or manual approval);
 *  - audit-trail population (createdBy / filedAt / archivedAt).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-25
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Document-register helper service (REQ-WBSO-003 / REQ-WBSO-007 / REQ-WBSO-009).
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-25
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Pre-existing debt (issue
 *     #506): changing this signature would ripple to callers; deferred.
 */
class WbsoDocumentService {

	/**
	 * Seven calendar years in days (REQ-WBSO-009).
	 *
	 * @var int
	 */
	public const RETENTION_DAYS = 2557;

	/**
	 * Allowed document types.
	 *
	 * @var array<int,string>
	 */
	public const ALLOWED_TYPES = ['invoice', 'receipt', 'contract', 'tax-form', 'bank-statement', 'memo'];

	/**
	 * Allowed lifecycle states.
	 *
	 * @var array<int,string>
	 */
	public const ALLOWED_STATES = ['draft', 'filed', 'archived'];

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config (register slug).
	 * @param IUserSession $userSession Authenticated session (createdBy).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return every document for an administration.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getDocumentsByAdministration(string $administrationId): array {
		return $this->objectService
			->setRegister($this->register())
			->setSchema('Document')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

	}//end getDocumentsByAdministration()

	/**
	 * Filter documents by type.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $type documentType to filter on.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getDocumentsByType(string $administrationId, string $type): array {
		if (in_array($type, self::ALLOWED_TYPES, true) === false) {
			throw new InvalidArgumentException('Unknown documentType');
		}

		$documents = $this->getDocumentsByAdministration(administrationId: $administrationId);
		return array_values(
			array_filter(
				$documents,
				static fn (array $row): bool => ((string)($row['documentType'] ?? '') === $type)
			)
		);

	}//end getDocumentsByType()

	/**
	 * Fetch one document by id within the administration.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $documentId Document id or documentNumber.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getDocument(string $administrationId, string $documentId): ?array {
		$documents = $this->getDocumentsByAdministration(administrationId: $administrationId);
		foreach ($documents as $row) {
			if ((string)($row['id'] ?? '') === $documentId
				|| (string)($row['documentNumber'] ?? '') === $documentId
			) {
				return $row;
			}
		}

		return null;
	}//end getDocument()

	/**
	 * Create a draft document (REQ-WBSO-003).
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $payload Document fields.
	 *
	 * @return array<string,mixed> Persisted record.
	 */
	public function createDocument(string $administrationId, array $payload): array {
		$payload['administrationId'] = $administrationId;
		$payload['status'] = 'draft';
		$payload['createdAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$payload['createdBy'] = $this->currentUserId();

		$this->validateDocumentPayload(payload: $payload);


		// ADR-084: saveObject() returns an ObjectEntityInterface, not the array
		// this method declares — returning it raised a TypeError on every call.
		return (array)$this->objectService
			->setRegister($this->register())
			->setSchema('Document')
			->saveObject($payload)
			->jsonSerialize();

	}//end createDocument()

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
	 */
	public function fileDocument(string $administrationId, string $documentId, string $approver): array {
		$document = $this->getDocument(administrationId: $administrationId, documentId: $documentId);
		if ($document === null) {
			throw new InvalidArgumentException('Document not found');
		}

		if ((string)($document['status'] ?? '') !== 'draft') {
			throw new RuntimeException('Document must be in draft state to be filed');
		}

		if (trim((string)($document['fileReference'] ?? '')) === '') {
			throw new RuntimeException('fileReference is required to file a document');
		}

		if (trim($approver) === '') {
			throw new InvalidArgumentException('Approver user id is required');
		}

		$document['status'] = 'filed';
		$document['filedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$document['filedBy'] = $approver;


		// ADR-084: see createDocument() — the contract returns an entity.
		return (array)$this->objectService
			->setRegister($this->register())
			->setSchema('Document')
			->saveObject($document)
			->jsonSerialize();

	}//end fileDocument()

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
	 */
	public function archiveDocument(
		string $administrationId,
		string $documentId,
		string $reason,
		bool $allowEarly = false,
	): array {
		$document = $this->getDocument(administrationId: $administrationId, documentId: $documentId);
		if ($document === null) {
			throw new InvalidArgumentException('Document not found');
		}

		if ((string)($document['status'] ?? '') !== 'filed') {
			throw new RuntimeException('Only filed documents can be archived');
		}

		if ($allowEarly === false) {
			$filedAt = (string)($document['filedAt'] ?? $document['documentDate'] ?? '');
			if ($this->isRetentionElapsed(filedAt: $filedAt) === false) {
				throw new RuntimeException(
					'Documents must have been filed for at least seven years before archival'
				);
			}
		}

		$document['status'] = 'archived';
		$document['archivedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$document['archivalReason'] = $reason;


		// ADR-084: see createDocument() — the contract returns an entity.
		return (array)$this->objectService
			->setRegister($this->register())
			->setSchema('Document')
			->saveObject($document)
			->jsonSerialize();

	}//end archiveDocument()

	/**
	 * Pure helper: has the seven-year retention boundary elapsed?
	 *
	 * @param string $filedAt ISO-8601 timestamp the document was filed.
	 *
	 * @return bool
	 */
	public function isRetentionElapsed(string $filedAt): bool {
		if ($filedAt === '') {
			return false;
		}

		try {
			$filed = new DateTimeImmutable($filedAt);
		} catch (\Throwable) {
			return false;
		}

		$boundary = $filed->modify('+' . self::RETENTION_DAYS . ' days');

		return $boundary <= (new DateTimeImmutable());
	}//end isRetentionElapsed()

	/**
	 * Validate the create payload.
	 *
	 * @param array<string,mixed> $payload Document fields.
	 *
	 * @return void
	 */
	public function validateDocumentPayload(array $payload): void {
		$required = ['documentType', 'documentNumber', 'documentDate', 'administrationId'];
		foreach ($required as $field) {
			if (isset($payload[$field]) === false || $payload[$field] === '') {
				throw new InvalidArgumentException(sprintf('%s is required', $field));
			}
		}

		if (in_array((string)$payload['documentType'], self::ALLOWED_TYPES, true) === false) {
			throw new InvalidArgumentException('documentType must be one of: ' . implode(', ', self::ALLOWED_TYPES));
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$payload['documentDate']) !== 1) {
			throw new InvalidArgumentException('documentDate must be ISO-8601 (YYYY-MM-DD)');
		}

	}//end validateDocumentPayload()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Resolve the current user id, falling back to 'system'.
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end currentUserId()
}//end class
