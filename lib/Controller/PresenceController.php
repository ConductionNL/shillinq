<?php

/**
 * Shillinq Presence Controller
 *
 * OCS API controller for presence tracking.
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
 * @spec openspec/changes/collaboration/tasks.md#task-8.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\CollaborationRoleService;
use OCA\Shillinq\Service\PresenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * OCS API controller for presence heartbeat pings and active viewer listing.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-8.3
 */
class PresenceController extends Controller
{
    /**
     * Constructor for PresenceController.
     *
     * @param IRequest                 $request         The request object
     * @param IUserSession             $userSession     The user session
     * @param PresenceService          $presenceService The presence service
     * @param CollaborationRoleService $roleService     The role service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private PresenceService $presenceService,
        private CollaborationRoleService $roleService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Send a presence heartbeat ping.
     *
     * Upserts PresenceRecord for the current user on the target document.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse The upserted presence record
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.3
     */
    public function ping(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $targetType = $this->request->getParam('targetType', '');
        $targetId   = $this->request->getParam('targetId', '');
        $isEditing  = (bool) $this->request->getParam('isEditing', false);

        if (empty($targetType) === true || empty($targetId) === true) {
            return new JSONResponse(
                ['error' => 'targetType and targetId are required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $record = $this->presenceService->ping(
            userId: $user->getUID(),
            targetType: $targetType,
            targetId: $targetId,
            isEditing: $isEditing,
        );

        return new JSONResponse($record);
    }//end ping()

    /**
     * List active presence records for a target.
     *
     * Returns only records within the 120-second activity window.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Active presence records
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.3
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $targetType = $this->request->getParam('targetType', '');
        $targetId   = $this->request->getParam('targetId', '');

        if (empty($targetType) === true || empty($targetId) === true) {
            return new JSONResponse(
                ['error' => 'targetType and targetId are required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        // Guard: caller must hold at least viewer role to see who is viewing the document.
        $hasRole = $this->roleService->checkRole(
            userId: $user->getUID(),
            targetType: $targetType,
            targetId: $targetId,
            minimumRole: 'viewer',
        );

        if ($hasRole === false) {
            return new JSONResponse(
                ['error' => 'Insufficient permissions — requires at least viewer role'],
                Http::STATUS_FORBIDDEN,
            );
        }

        $records = $this->presenceService->getActiveViewers(
            targetType: $targetType,
            targetId: $targetId,
        );

        return new JSONResponse($records);
    }//end index()
}//end class
