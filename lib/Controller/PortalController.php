<?php

/**
 * Shillinq Portal Controller
 *
 * OCS API controller for token-based portal authentication and scoped
 * invoice/payment data retrieval.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/general/tasks.md#task-11.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\PortalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for portal token authentication and scoped data access.
 *
 * @spec openspec/changes/general/tasks.md#task-11.1
 */
class PortalController extends Controller
{
    /**
     * Constructor for PortalController.
     *
     * @param IRequest      $request       The request object
     * @param PortalService $portalService The portal service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private PortalService $portalService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Authenticate using a portal token.
     *
     * Validates the raw token and returns a scoped session.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function auth(): JSONResponse
    {
        $rawToken = $this->request->getParam('token', '');

        if (empty($rawToken) === true) {
            return new JSONResponse(
                ['error' => 'Token is required'],
                401
            );
        }

        $tokenObject = $this->portalService->validateToken($rawToken);

        if ($tokenObject === null) {
            return new JSONResponse(
                ['error' => 'Invalid or expired token'],
                401
            );
        }

        return new JSONResponse(
                [
                    'organizationId' => $tokenObject['organizationId'],
                    'permissions'    => ($tokenObject['permissions'] ?? []),
                ]
                );
    }//end auth()

    /**
     * List invoices scoped to the authenticated portal token's organisation.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function invoices(): JSONResponse
    {
        $rawToken = $this->request->getHeader('X-Portal-Token');

        if (empty($rawToken) === true) {
            return new JSONResponse(['error' => 'Portal token required'], 401);
        }

        $tokenObject = $this->portalService->validateToken($rawToken);
        if ($tokenObject === null) {
            return new JSONResponse(['error' => 'Invalid or expired token'], 401);
        }

        $invoices = $this->portalService->getScopedInvoices($tokenObject['organizationId']);

        return new JSONResponse($invoices);
    }//end invoices()

    /**
     * List payments scoped to the authenticated portal token's organisation.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function payments(): JSONResponse
    {
        $rawToken = $this->request->getHeader('X-Portal-Token');

        if (empty($rawToken) === true) {
            return new JSONResponse(['error' => 'Portal token required'], 401);
        }

        $tokenObject = $this->portalService->validateToken($rawToken);
        if ($tokenObject === null) {
            return new JSONResponse(['error' => 'Invalid or expired token'], 401);
        }

        $payments = $this->portalService->getScopedPayments($tokenObject['organizationId']);

        return new JSONResponse($payments);
    }//end payments()

    /**
     * Generate a new portal token (admin only).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function generate(): JSONResponse
    {
        $organizationId = $this->request->getParam('organizationId', '');
        $description    = $this->request->getParam('description');
        $expiresAt      = $this->request->getParam('expiresAt');
        $permissions    = $this->request->getParam('permissions', []);

        if (empty($organizationId) === true) {
            return new JSONResponse(
                ['error' => 'organizationId is required'],
                400
            );
        }

        if (is_array($permissions) === false) {
            $permissions = [];
        }

        $result = $this->portalService->generateToken(
            organizationId: $organizationId,
            description: $description,
            expiresAt: $expiresAt,
            permissions: $permissions,
        );

        return new JSONResponse(
                [
                    'rawToken' => $result['rawToken'],
                    'token'    => $result['tokenObject'],
                ]
                );
    }//end generate()
}//end class
