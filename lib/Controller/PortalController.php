<?php

/**
 * Shillinq Portal Controller
 *
 * OCS API controller with token-based authentication for external portal access.
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
 * @spec openspec/changes/general/tasks.md#task-11.4
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
 * @spec openspec/changes/general/tasks.md#task-11.4
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
     * Authenticate with a portal token and return a scoped session.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function auth(): JSONResponse
    {
        $token = $this->request->getParam('token');

        if (empty($token) === true) {
            return new JSONResponse(
                ['error' => 'Token is required'],
                401
            );
        }

        $portalToken = $this->portalService->validateToken($token);

        if ($portalToken === null) {
            return new JSONResponse(
                ['error' => 'Invalid or expired token'],
                401
            );
        }

        return new JSONResponse([
            'organizationId' => $portalToken['organizationId'],
            'permissions'    => ($portalToken['permissions'] ?? []),
        ]);
    }//end auth()

    /**
     * List invoices scoped to the authenticated portal token's organisation.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function invoices(): JSONResponse
    {
        $token = $this->request->getHeader('X-Portal-Token');

        if (empty($token) === true) {
            return new JSONResponse(
                ['error' => 'Portal token is required'],
                401
            );
        }

        $portalToken = $this->portalService->validateToken($token);

        if ($portalToken === null) {
            return new JSONResponse(
                ['error' => 'Invalid or expired token'],
                401
            );
        }

        $invoices = $this->portalService->getScopedInvoices($portalToken);

        return new JSONResponse(['results' => $invoices]);
    }//end invoices()

    /**
     * List payments scoped to the authenticated portal token's organisation.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function payments(): JSONResponse
    {
        $token = $this->request->getHeader('X-Portal-Token');

        if (empty($token) === true) {
            return new JSONResponse(
                ['error' => 'Portal token is required'],
                401
            );
        }

        $portalToken = $this->portalService->validateToken($token);

        if ($portalToken === null) {
            return new JSONResponse(
                ['error' => 'Invalid or expired token'],
                401
            );
        }

        $payments = $this->portalService->getScopedPayments($portalToken);

        return new JSONResponse(['results' => $payments]);
    }//end payments()

    /**
     * Generate a new portal token (admin only).
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function generate(): JSONResponse
    {
        $organizationId = $this->request->getParam('organizationId');
        $description    = $this->request->getParam('description');
        $expiresAt      = $this->request->getParam('expiresAt');
        $permissions    = $this->request->getParam('permissions', []);

        if (empty($organizationId) === true) {
            return new JSONResponse(
                ['error' => 'organizationId is required'],
                400
            );
        }

        $result = $this->portalService->generateToken(
            organizationId: $organizationId,
            description: $description,
            expiresAt: $expiresAt,
            permissions: $permissions,
        );

        return new JSONResponse($result);
    }//end generate()
}//end class
