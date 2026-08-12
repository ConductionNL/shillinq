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
 * @spec openspec/specs/bookkeeping-wbso-sno-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\WbsoDocumentService;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Document REST API (REQ-WBSO-003 / REQ-WBSO-005 / REQ-WBSO-007 / REQ-WBSO-009).
 *
 * @spec openspec/specs/bookkeeping-wbso-sno-administratie/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class WbsoDocumentApiController extends Controller
{

    /**
     * Identifier-safe slug pattern.
     *
     * @var string
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

    /**
     * Construct the controller.
     *
     * @param IRequest            $request     Request.
     * @param WbsoDocumentService $documents   Document service.
     * @param WbsoRbacResolver    $rbac        Role resolver.
     * @param IUserSession        $userSession Session.
     * @param LoggerInterface     $logger      Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly WbsoDocumentService $documents,
        private readonly WbsoRbacResolver $rbac,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/v1/documents.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $auth = $this->requireAuthenticated();
        if ($auth !== null) {
            return $auth;
        }

        $administrationId = $this->resolveAdministration();
        if ($administrationId === null) {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        $type   = (string) $this->request->getParam('type', '');
        $status = (string) $this->request->getParam('status', '');

        try {
            if ($type !== '') {
                $rows = $this->documents->getDocumentsByType(administrationId: $administrationId, type: $type);
            } else {
                $rows = $this->documents->getDocumentsByAdministration(administrationId: $administrationId);
            }
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->fail(message: 'Failed to load documents', context: ['exception' => $e->getMessage()]);
        }

        if ($status !== '') {
            $rows = array_values(
                    array_filter(
                $rows,
                static fn (array $row): bool => ((string) ($row['status'] ?? '') === $status)
            )
                    );
        }

        $filedFrom = (string) $this->request->getParam('filedFrom', '');
        if ($filedFrom !== '') {
            $rows = array_values(
                    array_filter(
                $rows,
                static fn (array $row): bool => ((string) ($row['filedAt'] ?? $row['documentDate'] ?? '') >= $filedFrom)
            )
                    );
        }

        return new JSONResponse(
            [
                'documents' => $rows,
                'canCreate' => $this->rbac->hasAny(['bookkeeper', 'administrator']),
            ],
            Http::STATUS_OK
        );

    }//end index()

    /**
     * GET /api/v1/documents/{id}.
     *
     * @param string $id Document id or documentNumber.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $auth = $this->requireAuthenticated();
        if ($auth !== null) {
            return $auth;
        }

        $administrationId = $this->resolveAdministration();
        if ($administrationId === null) {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return new JSONResponse(['error' => 'Invalid document id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $row = $this->documents->getDocument(administrationId: $administrationId, documentId: $id);
        } catch (\Throwable $e) {
            return $this->fail(message: 'Failed to load document', context: ['exception' => $e->getMessage()]);
        }

        if ($row === null) {
            return new JSONResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($row, Http::STATUS_OK);

    }//end show()

    /**
     * POST /api/v1/documents (bookkeeper or admin).
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $auth = $this->requireAuthenticated();
        if ($auth !== null) {
            return $auth;
        }

        if ($this->rbac->hasAny(['bookkeeper', 'administrator']) === false) {
            return new JSONResponse(['error' => 'Bookkeeper or administrator role required'], Http::STATUS_FORBIDDEN);
        }

        $administrationId = $this->resolveAdministration();
        if ($administrationId === null) {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        $payload = [
            'documentType'   => (string) $this->request->getParam('documentType', ''),
            'documentNumber' => (string) $this->request->getParam('documentNumber', ''),
            'documentDate'   => (string) $this->request->getParam('documentDate', ''),
            'fileReference'  => (string) $this->request->getParam('fileReference', ''),
        ];

        if ($payload['fileReference'] === '') {
            unset($payload['fileReference']);
        }

        try {
            $row = $this->documents->createDocument(administrationId: $administrationId, payload: $payload);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
     */
    #[NoAdminRequired]
    public function file(string $id): JSONResponse
    {
        $auth = $this->requireAuthenticated();
        if ($auth !== null) {
            return $auth;
        }

        if ($this->rbac->hasAny(['bookkeeper', 'administrator']) === false) {
            return new JSONResponse(['error' => 'Bookkeeper or administrator role required'], Http::STATUS_FORBIDDEN);
        }

        $administrationId = $this->resolveAdministration();
        if ($administrationId === null) {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return new JSONResponse(['error' => 'Invalid document id'], Http::STATUS_BAD_REQUEST);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            $approver = '';
        } else {
            $approver = $user->getUID();
        }

        try {
            $row = $this->documents->fileDocument(
                administrationId: $administrationId,
                documentId: $id,
                approver: $approver,
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            return $this->fail(message: 'Failed to file document', context: ['exception' => $e->getMessage()]);
        }

        return new JSONResponse($row, Http::STATUS_OK);

    }//end file()

    /**
     * POST /api/v1/documents/{id}/archive (auditor / admin).
     *
     * @param string $id Document id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function archive(string $id): JSONResponse
    {
        $auth = $this->requireAuthenticated();
        if ($auth !== null) {
            return $auth;
        }

        if ($this->rbac->hasAny(['auditor', 'administrator']) === false) {
            return new JSONResponse(['error' => 'Auditor or administrator role required'], Http::STATUS_FORBIDDEN);
        }

        $administrationId = $this->resolveAdministration();
        if ($administrationId === null) {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return new JSONResponse(['error' => 'Invalid document id'], Http::STATUS_BAD_REQUEST);
        }

        $reason     = (string) $this->request->getParam('reason', '');
        $allowEarly = (bool) $this->request->getParam('allowEarly', false);

        // Only administrators may override the seven-year retention boundary.
        if ($allowEarly === true && $this->rbac->hasAny(['administrator']) === false) {
            return new JSONResponse(['error' => 'Only administrators can override the 7-year retention boundary'], Http::STATUS_FORBIDDEN);
        }

        try {
            $row = $this->documents->archiveDocument(
                administrationId: $administrationId,
                documentId: $id,
                reason: $reason,
                allowEarly: $allowEarly,
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            return $this->fail(message: 'Failed to archive document', context: ['exception' => $e->getMessage()]);
        }

        return new JSONResponse($row, Http::STATUS_OK);

    }//end archive()

    /**
     * Authentication precondition.
     *
     * @return JSONResponse|null
     */
    private function requireAuthenticated(): ?JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        return null;

    }//end requireAuthenticated()

    /**
     * Resolve administration scope.
     *
     * @return string|null
     */
    private function resolveAdministration(): ?string
    {
        $value = trim((string) $this->request->getParam('administration_id', 'adm-consultancy-nl'));
        if ($value === '') {
            return null;
        }

        if (preg_match(self::ID_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;

    }//end resolveAdministration()

    /**
     * Log + return a 500 without stack-traces.
     *
     * @param string              $message Client-facing message.
     * @param array<string,mixed> $context Structured log context.
     *
     * @return JSONResponse
     */
    private function fail(string $message, array $context): JSONResponse
    {
        $this->logger->error('WbsoDocumentApiController: '.$message, $context);

        return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);

    }//end fail()
}//end class
