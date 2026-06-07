<?php

/**
 * Administration Controller
 *
 * Administratie-aware RBAC + multi-tenant context API (REQ-MA-001, REQ-MA-003,
 * REQ-MA-007). Exposes:
 *  - GET  /api/administrations/context   the authenticated user's accessible
 *                                        administrations + active administration;
 *  - POST /api/administrations/switch    validate access and switch the active
 *                                        administration in-session (no re-login);
 *  - GET  /api/administrations/{id}/export-scope  validated per-administration
 *                                        export scope metadata for the Auditfile
 *                                        XAF export (the export itself streams
 *                                        only that administration's data).
 *
 * Every endpoint is available to any authenticated user (#[NoAdminRequired]); the
 * administration scope is validated against the user's AdministrationMembership
 * records by AdministrationContextService. A request for an administration the
 * user has no membership for is masked as a 404 (never 403) so the existence of
 * other tenants' data is not disclosed (REQ-MA-001). No stack traces reach the
 * client; identifiers are validated before the data layer is touched.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Administratie context + switcher + export-scope API.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
 */
class AdministrationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                     $request The request object.
     * @param AdministrationContextService $context The administratie-aware RBAC context service.
     * @param LoggerInterface              $logger  Logger (no stack traces to client).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly AdministrationContextService $context,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the authenticated user's administration context (REQ-MA-003).
     *
     * @return JSONResponse 200 with { userId, administrations[], activeAdministrationId };
     *                      401 when no user is authenticated.
     *
     * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
     */
    #[NoAdminRequired]
    public function context(): JSONResponse
    {
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $context = $this->context->buildContext();
        } catch (\Throwable $e) {
            $this->logger->error(
                'AdministrationController: failed to build context',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Failed to resolve administration context'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($context, Http::STATUS_OK);

    }//end context()

    /**
     * Switch the active administration for the session (REQ-MA-003).
     *
     * Validates the user has a valid membership for the requested administration.
     * On success returns the new active administration id; when the user has no
     * membership the response is a masked 404 (REQ-MA-001).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
     */
    #[NoAdminRequired]
    public function switch(): JSONResponse
    {
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim((string) $this->request->getParam('administrationId', ''));
        if ($this->isValidIdentifier(identifier: $administrationId) === false) {
            return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $target = $this->context->resolveSwitchTarget(targetId: $administrationId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'AdministrationController: failed to resolve switch target',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Failed to switch administration'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($target === null) {
            // Mask non-membership as 404 — do not confirm the administration exists.
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(['activeAdministrationId' => $target], Http::STATUS_OK);

    }//end switch()

    /**
     * Return the validated export scope for an administration (REQ-MA-007).
     *
     * The endpoint confirms the user may access the administration before
     * returning the scope descriptor used by the Auditfile XAF exporter; a
     * non-member receives a masked 404. The export itself is bound to this single
     * administrationId so no cross-administration data leaks.
     *
     * @param string $id The administration id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-16
     */
    #[NoAdminRequired]
    public function exportScope(string $id): JSONResponse
    {
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim($id);
        if ($this->isValidIdentifier(identifier: $administrationId) === false) {
            return new JSONResponse(['error' => 'Invalid administration id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $allowed = $this->context->canAccess(administrationId: $administrationId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'AdministrationController: failed to check export access',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Failed to resolve export scope'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($allowed === false) {
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(
            [
                'administrationId' => $administrationId,
                'format'           => 'xaf-3.2',
                'scope'            => 'single-administration',
                'includes'         => [
                    'GLTransaction',
                    'GLLine',
                    'Account',
                    'BalanceSheet',
                    'AnnualReport',
                ],
            ],
            Http::STATUS_OK
        );

    }//end exportScope()

    /**
     * Validate an administration identifier slug before touching the data layer.
     *
     * @param string $identifier The identifier to validate.
     *
     * @return bool True when the identifier is a safe short slug.
     */
    private function isValidIdentifier(string $identifier): bool
    {
        return ($identifier !== '' && preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $identifier) === 1);

    }//end isValidIdentifier()
}//end class
