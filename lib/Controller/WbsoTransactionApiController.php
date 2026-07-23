<?php

/**
 * WBSO Transaction API Controller
 *
 * REST surface for the Transaction register declared by the
 * bookkeeping-financial-administration spec (REQ-WBSO-002 / REQ-WBSO-005 /
 * REQ-WBSO-008). Endpoints:
 *  - GET  /api/v1/transactions                — list (filters: date, status, type).
 *  - GET  /api/v1/transactions/{id}           — fetch one.
 *  - POST /api/v1/transactions                — create draft (bookkeeper / admin).
 *  - POST /api/v1/transactions/{id}/post      — post a draft (bookkeeper / admin).
 *  - POST /api/v1/transactions/{id}/reverse   — reverse a posted (admin only).
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-27
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCA\Shillinq\Service\WbsoTransactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Transaction REST API (REQ-WBSO-002 / REQ-WBSO-005 / REQ-WBSO-008).
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-27
 */
class WbsoTransactionApiController extends Controller
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
     * @param IRequest               $request      Request.
     * @param WbsoTransactionService $transactions Transaction service.
     * @param WbsoRbacResolver       $rbac         Role resolver.
     * @param IUserSession           $userSession  Session.
     * @param LoggerInterface        $logger       Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly WbsoTransactionService $transactions,
        private readonly WbsoRbacResolver $rbac,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/v1/transactions (bookkeeper / auditor / admin).
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

        $filters = [
            'status'   => (string) $this->request->getParam('status', ''),
            'type'     => (string) $this->request->getParam('type', ''),
            'dateFrom' => (string) $this->request->getParam('dateFrom', ''),
            'dateTo'   => (string) $this->request->getParam('dateTo', ''),
        ];

        try {
            $rows = $this->transactions->listTransactions(administrationId: $administrationId, filters: $filters);
        } catch (\Throwable $e) {
            return $this->fail(message: 'Failed to load transactions', context: ['exception' => $e->getMessage()]);
        }

        return new JSONResponse(
            [
                'transactions' => $rows,
                'canCreate'    => $this->rbac->hasAny(['bookkeeper', 'administrator']),
            ],
            Http::STATUS_OK
        );

    }//end index()

    /**
     * GET /api/v1/transactions/{id}.
     *
     * @param string $id Transaction id or transactionNumber.
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
            return new JSONResponse(['error' => 'Invalid transaction id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $row = $this->transactions->getTransaction(administrationId: $administrationId, transactionId: $id);
        } catch (\Throwable $e) {
            return $this->fail(message: 'Failed to load transaction', context: ['exception' => $e->getMessage()]);
        }

        if ($row === null) {
            return new JSONResponse(['error' => 'Transaction not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($row, Http::STATUS_OK);

    }//end show()

    /**
     * POST /api/v1/transactions (bookkeeper or admin).
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
            'transactionNumber' => (string) $this->request->getParam('transactionNumber', ''),
            'transactionType'   => (string) $this->request->getParam('transactionType', ''),
            'transactionDate'   => (string) $this->request->getParam('transactionDate', ''),
            'amount'            => $this->request->getParam('amount', 0),
            'description'       => (string) $this->request->getParam('description', ''),
        ];

        try {
            $row = $this->transactions->createTransaction(administrationId: $administrationId, payload: $payload);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->fail(message: 'Failed to create transaction', context: ['exception' => $e->getMessage()]);
        }

        return new JSONResponse($row, Http::STATUS_CREATED);

    }//end create()

    /**
     * POST /api/v1/transactions/{id}/post (bookkeeper or admin).
     *
     * @param string $id Transaction id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function post(string $id): JSONResponse
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
            return new JSONResponse(['error' => 'Invalid transaction id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $row = $this->transactions->postTransaction(administrationId: $administrationId, transactionId: $id);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            return $this->fail(message: 'Failed to post transaction', context: ['exception' => $e->getMessage()]);
        }

        return new JSONResponse($row, Http::STATUS_OK);

    }//end post()

    /**
     * POST /api/v1/transactions/{id}/reverse (admin only).
     *
     * @param string $id Transaction id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function reverse(string $id): JSONResponse
    {
        $auth = $this->requireAuthenticated();
        if ($auth !== null) {
            return $auth;
        }

        if ($this->rbac->hasAny(['administrator']) === false) {
            return new JSONResponse(['error' => 'Administrator role required'], Http::STATUS_FORBIDDEN);
        }

        $administrationId = $this->resolveAdministration();
        if ($administrationId === null) {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return new JSONResponse(['error' => 'Invalid transaction id'], Http::STATUS_BAD_REQUEST);
        }

        $reason = trim((string) $this->request->getParam('reason', ''));

        try {
            $row = $this->transactions->reverseTransaction(
                administrationId: $administrationId,
                transactionId: $id,
                reason: $reason,
            );
        } catch (InvalidArgumentException $e) {
            $status = Http::STATUS_BAD_REQUEST;
            if ($e->getMessage() === 'Transaction not found') {
                $status = Http::STATUS_NOT_FOUND;
            }

            return new JSONResponse(['error' => $e->getMessage()], $status);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            return $this->fail(message: 'Failed to reverse transaction', context: ['exception' => $e->getMessage()]);
        }

        return new JSONResponse($row, Http::STATUS_CREATED);

    }//end reverse()

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
     * Resolve administration scope, default to the demo administration.
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
     * Logger + 500 response without stack-traces.
     *
     * @param string              $message Client-facing message.
     * @param array<string,mixed> $context Structured log context.
     *
     * @return JSONResponse
     */
    private function fail(string $message, array $context): JSONResponse
    {
        $this->logger->error('WbsoTransactionApiController: '.$message, $context);

        return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);

    }//end fail()
}//end class
