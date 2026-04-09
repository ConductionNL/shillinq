<?php

/**
 * Shillinq Presence Controller
 *
 * Controller for managing real-time presence and editing indicators.
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
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\PresenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing real-time presence and editing indicators.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-8.3
 */
class PresenceController extends Controller
{
    /**
     * Constructor for the PresenceController.
     *
     * @param IRequest        $request         The request object
     * @param PresenceService $presenceService The presence service
     * @param IUserSession    $userSession     The user session
     * @param LoggerInterface $logger          The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private PresenceService $presenceService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Send a presence heartbeat for the current user.
     *
     * Records that the user is actively viewing or editing a target object.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.3
     *
     * @return JSONResponse
     */
    public function ping(): JSONResponse
    {
        try {
            $targetType = $this->request->getParam('targetType');
            $targetId   = $this->request->getParam('targetId');
            $isEditing  = (bool) $this->request->getParam('isEditing', false);

            $user = $this->userSession->getUser();

            $this->presenceService->ping(
                userId: $user->getUID(),
                targetType: $targetType,
                targetId: $targetId,
                isEditing: $isEditing
            );

            return new JSONResponse(data: ['success' => true], statusCode: 200);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to record presence heartbeat',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end ping()

    /**
     * Get active presence records for a target object.
     *
     * Returns all users currently viewing or editing the specified target.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.3
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        try {
            $targetType = $this->request->getParam('targetType');
            $targetId   = $this->request->getParam('targetId');

            $records = $this->presenceService->getActivePresence(
                targetType: $targetType,
                targetId: $targetId
            );

            return new JSONResponse(data: $records);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to retrieve presence records',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end index()
}//end class
