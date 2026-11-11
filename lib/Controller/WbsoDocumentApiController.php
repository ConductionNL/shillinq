<?php

/**
 * WBSO Document API Controller
 *
 * REST surface for the Document register declared by the
 * bookkeeping-financial-administration spec (REQ-WBSO-003 / REQ-WBSO-005 /
 * REQ-WBSO-007 / REQ-WBSO-009). Endpoints:
 *  - GET  /api/v1/documents                — list (filters: type, status, filedFrom).
 *  - GET  /api/v1/documents/{id}            — fetch one.
 *  - POST /api/v1/documents                 — create draft (bookkeeper / admin).
 *  - POST /api/v1/documents/{id}/file       — draft → filed (bookkeeper / admin).
 *  - POST /api/v1/documents/{id}/archive    — filed → archived (auditor / admin).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-28
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\WbsoDocumentService;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Document REST API (REQ-WBSO-003 / REQ-WBSO-005 / REQ-WBSO-007 / REQ-WBSO-009).
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-28
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class WbsoDocumentApiController extends Controller {
	/**
	 * Identifier-safe slug pattern.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Roles that may create and file documents.
	 *
	 * @var array<int,string>
	 */
	private const BOOKKEEPER_ROLES = ['bookkeeper', 'administrator'];

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request Request.
	 * @param WbsoDocumentService $documents Document service.
	 * @param WbsoRbacResolver $rbac Role resolver.
	 * @param IUserSession $userSession Session.
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Localized strings for client-facing error messages (ADR-050).
	 * @param AdministrationContextService $administrationContext Membership check on the requested administration.
	 */
	public function __construct(
		IRequest $request,
		private readonly WbsoDocumentService $documents,
		private readonly WbsoRbacResolver $rbac,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly AdministrationContextService $administrationContext,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/v1/documents.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$administrationId = $this->guard()->read();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		$rows = $this->loadDocuments(administrationId: $administrationId);
		if ($rows instanceof JSONResponse) {
			return $rows;
		}

		return $this->listing(rows: $this->filedSince(rows: $this->withStatus(rows: $rows)));

	}//end index()

	/**
	 * GET /api/v1/documents/{id}.
	 *
	 * @param string $id Document id or documentNumber.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$administrationId = $this->guard()->read();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return $this->invalidId();
		}

		try {
			$row = $this->documents->getDocument(administrationId: $administrationId, documentId: $id);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load document', context: ['exception' => $e->getMessage()]);
		}

		return $this->rowOrNotFound(row: $row);
	}//end show()

	/**
	 * POST /api/v1/documents (bookkeeper or admin).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$administrationId = $this->guard()->write(roles: self::BOOKKEEPER_ROLES, refusal: 'Bookkeeper or administrator role required');
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$row = $this->documents->createDocument(administrationId: $administrationId, payload: $this->documentPayload());
		} catch (InvalidArgumentException $e) {
			return $this->invalidDocument(administrationId: $administrationId, failure: $e);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to create document', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse($row, Http::STATUS_CREATED);
	}//end create()

	/**
	 * POST /api/v1/documents/{id}/file (bookkeeper or admin).
	 *
	 * @param string $id Document id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function file(string $id): JSONResponse {
		$administrationId = $this->guard()->write(roles: self::BOOKKEEPER_ROLES, refusal: 'Bookkeeper or administrator role required');
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return $this->invalidId();
		}

		return $this->fileDocument(administrationId: $administrationId, documentId: $id);
	}//end file()

	/**
	 * POST /api/v1/documents/{id}/archive (auditor / admin).
	 *
	 * @param string $id Document id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function archive(string $id): JSONResponse {
		$administrationId = $this->guard()->write(roles: ['auditor', 'administrator'], refusal: 'Auditor or administrator role required');
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return $this->invalidId();
		}

		$archival = new WbsoDocumentArchival(request: $this->request, documentId: $id);

		// Only administrators may override the seven-year retention boundary.
		if ($archival->allowEarly === true && $this->rbac->hasAny(['administrator']) === false) {
			return new JSONResponse(['error' => 'Only administrators can override the 7-year retention boundary'], Http::STATUS_FORBIDDEN);
		}

		return $this->archiveDocument(administrationId: $administrationId, archival: $archival);
	}//end archive()

	/**
	 * Log + return a 500 without stack-traces.
	 *
	 * @param string $message Client-facing message.
	 * @param array<string,mixed> $context Structured log context.
	 *
	 * @return JSONResponse
	 */
	private function fail(string $message, array $context): JSONResponse {
		$this->logger->error('WbsoDocumentApiController: ' . $message, $context);

		return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end fail()

	/**
	 * The access steps of this API.
	 *
	 * @return WbsoActionGuard
	 */
	private function guard(): WbsoActionGuard {
		return new WbsoActionGuard(
			caller: new WbsoCallerCheck(userSession: $this->userSession, rbac: $this->rbac),
			scope: new WbsoAdministrationScope(request: $this->request, administrationContext: $this->administrationContext),
		);

	}//end guard()

	/**
	 * The documents of the administration, of the requested type when one is
	 * given; a rejected filter is a 400.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int,array<string,mixed>>|JSONResponse
	 */
	private function loadDocuments(string $administrationId): array|JSONResponse {
		$type = (string)$this->request->getParam('type', '');

		try {
			return $this->documentsOfType(administrationId: $administrationId, type: $type);
		} catch (InvalidArgumentException $e) {
			return $this->rejectedFilter(context: ['administrationId' => $administrationId, 'type' => $type, 'exception' => $e->getMessage()]);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to load documents', context: ['exception' => $e->getMessage()]);
		}

	}//end loadDocuments()

	/**
	 * The documents of one type, or all of them when no type is given.
	 *
	 * @param string $administrationId The administration.
	 * @param string $type The document type, '' for all.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function documentsOfType(string $administrationId, string $type): array {
		if ($type !== '') {
			return $this->documents->getDocumentsByType(administrationId: $administrationId, type: $type);
		}

		return $this->documents->getDocumentsByAdministration(administrationId: $administrationId);

	}//end documentsOfType()

	/**
	 * Answer a document filter the service rejected with a 400.
	 *
	 * @param array<string,string> $context The log context.
	 *
	 * @return JSONResponse
	 */
	private function rejectedFilter(array $context): JSONResponse {
		$this->logger->error('WbsoDocumentApiController: document list filter rejected', $context);
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Unable to load documents: the filter is invalid'),
				'error' => 'wbso-document-list-failed',
			],
			Http::STATUS_BAD_REQUEST,
		);

	}//end rejectedFilter()

	/**
	 * The listing response, with whether the caller may create.
	 *
	 * @param array<int,array<string,mixed>> $rows The documents.
	 *
	 * @return JSONResponse
	 */
	private function listing(array $rows): JSONResponse {
		return new JSONResponse(
			[
				'documents' => $rows,
				'canCreate' => $this->rbac->hasAny(self::BOOKKEEPER_ROLES),
			],
			Http::STATUS_OK
		);

	}//end listing()

	/**
	 * Keep the documents with the requested status, all when none is given.
	 *
	 * @param array<int,array<string,mixed>> $rows The documents.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function withStatus(array $rows): array {
		$status = (string)$this->request->getParam('status', '');
		if ($status === '') {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => ((string)($row['status'] ?? '') === $status)
			)
		);

	}//end withStatus()

	/**
	 * Keep the documents filed (or dated) on or after filedFrom, all when it
	 * is not given.
	 *
	 * @param array<int,array<string,mixed>> $rows The documents.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function filedSince(array $rows): array {
		$filedFrom = (string)$this->request->getParam('filedFrom', '');
		if ($filedFrom === '') {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => ((string)($row['filedAt'] ?? $row['documentDate'] ?? '') >= $filedFrom)
			)
		);

	}//end filedSince()

	/**
	 * The document to create, from the request; an empty fileReference is left out.
	 *
	 * @return array<string,string>
	 */
	private function documentPayload(): array {
		$payload = [
			'documentType' => (string)$this->request->getParam('documentType', ''),
			'documentNumber' => (string)$this->request->getParam('documentNumber', ''),
			'documentDate' => (string)$this->request->getParam('documentDate', ''),
			'fileReference' => (string)$this->request->getParam('fileReference', ''),
		];

		if ($payload['fileReference'] === '') {
			unset($payload['fileReference']);
		}

		return $payload;

	}//end documentPayload()

	/**
	 * Answer a document the service rejected as invalid with a 400.
	 *
	 * @param string $administrationId The administration.
	 * @param InvalidArgumentException $failure The rejection.
	 *
	 * @return JSONResponse
	 */
	private function invalidDocument(string $administrationId, InvalidArgumentException $failure): JSONResponse {
		$this->logger->error(
			'WbsoDocumentApiController: document creation payload rejected',
			['administrationId' => $administrationId, 'exception' => $failure->getMessage()]
		);
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Unable to create document: the submitted details are invalid'),
				'error' => 'wbso-document-create-failed',
			],
			Http::STATUS_BAD_REQUEST,
		);

	}//end invalidDocument()

	/**
	 * File the document with the caller as approver: 404 when the service does
	 * not know it, 409 when its state does not allow filing.
	 *
	 * @param string $administrationId The administration.
	 * @param string $documentId The document.
	 *
	 * @return JSONResponse
	 */
	private function fileDocument(string $administrationId, string $documentId): JSONResponse {
		$context = ['administrationId' => $administrationId, 'documentId' => $documentId];
		try {
			$row = $this->filed(administrationId: $administrationId, documentId: $documentId);
		} catch (InvalidArgumentException $e) {
			$this->logger->error('WbsoDocumentApiController: document to file not found', $context + ['exception' => $e->getMessage()]);
			return $this->documentNotFound();
		} catch (RuntimeException $e) {
			return $this->fileConflict(context: $context + ['exception' => $e->getMessage()]);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to file document', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse($row, Http::STATUS_OK);

	}//end fileDocument()

	/**
	 * Hand the filing, with the caller as approver, to the service.
	 *
	 * @param string $administrationId The administration.
	 * @param string $documentId The document.
	 *
	 * @return array<string,mixed>
	 */
	private function filed(string $administrationId, string $documentId): array {
		return $this->documents->fileDocument(
			administrationId: $administrationId,
			documentId: $documentId,
			approver: $this->approver(),
		);

	}//end filed()

	/**
	 * The uid of the caller, '' when nobody is logged in.
	 *
	 * @return string
	 */
	private function approver(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();

	}//end approver()

	/**
	 * Answer a filing the document's state does not allow with a 409.
	 *
	 * @param array<string,string> $context The log context.
	 *
	 * @return JSONResponse
	 */
	private function fileConflict(array $context): JSONResponse {
		$this->logger->error('WbsoDocumentApiController: document file transition rejected', $context);
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Unable to file this document in its current state'),
				'error' => 'wbso-document-file-conflict',
			],
			Http::STATUS_CONFLICT,
		);

	}//end fileConflict()

	/**
	 * Archive the document: 404 when the service does not know it, 409 when
	 * its state does not allow archiving.
	 *
	 * @param string $administrationId The administration.
	 * @param WbsoDocumentArchival $archival The document, reason and override.
	 *
	 * @return JSONResponse
	 */
	private function archiveDocument(string $administrationId, WbsoDocumentArchival $archival): JSONResponse {
		$context = ['administrationId' => $administrationId, 'documentId' => $archival->documentId];
		try {
			$row = $this->archived(administrationId: $administrationId, archival: $archival);
		} catch (InvalidArgumentException $e) {
			$this->logger->error('WbsoDocumentApiController: document to archive not found', $context + ['exception' => $e->getMessage()]);
			return $this->documentNotFound();
		} catch (RuntimeException $e) {
			return $this->archiveConflict(context: $context + ['exception' => $e->getMessage()]);
		} catch (\Throwable $e) {
			return $this->fail(message: 'Failed to archive document', context: ['exception' => $e->getMessage()]);
		}

		return new JSONResponse($row, Http::STATUS_OK);

	}//end archiveDocument()

	/**
	 * Hand the archival to the service.
	 *
	 * @param string $administrationId The administration.
	 * @param WbsoDocumentArchival $archival The document, reason and override.
	 *
	 * @return array<string,mixed>
	 */
	private function archived(string $administrationId, WbsoDocumentArchival $archival): array {
		return $this->documents->archiveDocument(
			administrationId: $administrationId,
			documentId: $archival->documentId,
			reason: $archival->reason,
			allowEarly: $archival->allowEarly,
		);

	}//end archived()

	/**
	 * Answer an archival the document's state does not allow with a 409.
	 *
	 * @param array<string,string> $context The log context.
	 *
	 * @return JSONResponse
	 */
	private function archiveConflict(array $context): JSONResponse {
		$this->logger->error('WbsoDocumentApiController: document archive transition rejected', $context);
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Unable to archive this document in its current state'),
				'error' => 'wbso-document-archive-conflict',
			],
			Http::STATUS_CONFLICT,
		);

	}//end archiveConflict()

	/**
	 * The 404 for a document the service does not know.
	 *
	 * @return JSONResponse
	 */
	private function documentNotFound(): JSONResponse {
		return new JSONResponse(
			['message' => $this->l10n->t('Document not found'), 'error' => 'wbso-document-not-found'],
			Http::STATUS_NOT_FOUND,
		);

	}//end documentNotFound()

	/**
	 * The 400 for a malformed document id.
	 *
	 * @return JSONResponse
	 */
	private function invalidId(): JSONResponse {
		return new JSONResponse(['error' => 'Invalid document id'], Http::STATUS_BAD_REQUEST);

	}//end invalidId()

	/**
	 * The document, or a 404 when there is none.
	 *
	 * @param array<string,mixed>|null $row The document.
	 *
	 * @return JSONResponse
	 */
	private function rowOrNotFound(?array $row): JSONResponse {
		if ($row === null) {
			return new JSONResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($row, Http::STATUS_OK);

	}//end rowOrNotFound()

}//end class
