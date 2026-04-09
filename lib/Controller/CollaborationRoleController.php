<?php

/**
 * Shillinq Collaboration Role Controller
 *
 * Controller for managing collaboration roles on target objects.
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
use OCA\Shillinq\Service\CollaborationRoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing collaboration roles on target objects.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-8.2
 */
class CollaborationRoleController extends Controller
{
    /**
     * Constructor for the CollaborationRoleController.
     *
     * @param IRequest                 $request                  The request object
     * @param ContainerInterface       $container                The DI container for ObjectService access
     * @param CollaborationRoleService $collaborationRoleService The collaboration role service
     * @param IUserSession             $userSession              The user session
     * @param INotificationManager     $notificationManager      The notification manager
     * @param LoggerInterface          $logger                   The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private CollaborationRoleService $collaborationRoleService,
        private IUserSession $userSession,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List roles for a target type and target ID.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.2
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        try {
            $targetType = $this->request->getParam('targetType');
            $targetId   = $this->request->getParam('targetId');

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $roles = $objectService->findObjects(
                filters: [
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ]
            );

            return new JSONResponse(data: $roles);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to list collaboration roles',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end index()

    /**
     * Assign a collaboration role to a user on a target object.
     *
     * Requires the 'approver' role on the target. Sends a notification
     * to the grantee after the role is assigned.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.2
     *
     * @return JSONResponse
     */
    public function create(): JSONResponse
    {
        try {
            $data       = $this->request->getParams();
            $targetType = ($data['targetType'] ?? null);
            $targetId   = ($data['targetId'] ?? null);

            $user = $this->userSession->getUser();

            $hasRole = $this->collaborationRoleService->checkRole(
                userId: $user->getUID(),
                targetType: $targetType,
                targetId: $targetId,
                minimumRole: 'approver'
            );
            if ($hasRole === false) {
                return new JSONResponse(data: ['error' => 'Forbidden'], statusCode: 403);
            }

            $data['grantedBy'] = $user->getUID();
            $data['grantedAt'] = date('c');

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $role = $objectService->saveObject(object: $data);

            // Notify the grantee about the new role assignment.
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($data['userId'] ?? '')
                ->setDateTime(new \DateTime())
                ->setObject($targetType ?? '', $targetId ?? '')
                ->setSubject(
                    'role_assigned',
                    [
                        'role'      => ($data['role'] ?? ''),
                        'grantedBy' => $user->getUID(),
                    ]
                );
            $this->notificationManager->notify(notification: $notification);

            return new JSONResponse(data: $role);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to assign collaboration role',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end create()

    /**
     * Revoke a collaboration role.
     *
     * Requires the 'approver' role on the target object.
     *
     * @param string $id The role assignment ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.2
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $role = $objectService->getObject(id: $id);
            $user = $this->userSession->getUser();

            $hasRole = $this->collaborationRoleService->checkRole(
                userId: $user->getUID(),
                targetType: ($role['targetType'] ?? ''),
                targetId: ($role['targetId'] ?? ''),
                minimumRole: 'approver'
            );
            if ($hasRole === false) {
                return new JSONResponse(data: ['error' => 'Forbidden'], statusCode: 403);
            }

            $objectService->deleteObject(id: $id);

            return new JSONResponse(data: ['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to revoke collaboration role',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end destroy()
}//end class
