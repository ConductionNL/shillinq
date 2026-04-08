<?php

/**
 * Shillinq Collaboration Role Controller
 *
 * OCS API controller for collaboration role management.
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
 * @spec openspec/changes/collaboration/tasks.md#task-8.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\CollaborationRoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OCS API controller for collaboration role assignment, listing, and revocation.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-8.2
 */
class CollaborationRoleController extends Controller
{
    /**
     * Constructor for CollaborationRoleController.
     *
     * @param IRequest                 $request             The request object
     * @param ContainerInterface       $container           The DI container
     * @param IUserSession             $userSession         The user session
     * @param CollaborationRoleService $roleService         The role service
     * @param INotificationManager     $notificationManager The notification manager
     * @param LoggerInterface          $logger              The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private IUserSession $userSession,
        private CollaborationRoleService $roleService,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List roles for a target document.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse List of CollaborationRole objects
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.2
     */
    public function index(): JSONResponse
    {
        $targetType = $this->request->getParam('targetType', '');
        $targetId   = $this->request->getParam('targetId', '');

        if (empty($targetType) === true || empty($targetId) === true) {
            return new JSONResponse(
                ['error' => 'targetType and targetId are required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $roles = $this->roleService->getRolesForTarget(
            targetType: $targetType,
            targetId: $targetId,
        );

        return new JSONResponse($roles);
    }//end index()

    /**
     * Assign a collaboration role.
     *
     * Requires approver role on the target document.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse The created role or error
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.2
     */
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $userId        = $user->getUID();
        $targetType    = $this->request->getParam('targetType', '');
        $targetId      = $this->request->getParam('targetId', '');
        $principalType = $this->request->getParam('principalType', 'user');
        $principalId   = $this->request->getParam('principalId', '');
        $role          = $this->request->getParam('role', '');
        $expiresAt     = $this->request->getParam('expiresAt');

        if (empty($targetType) === true || empty($targetId) === true
            || empty($principalId) === true || empty($role) === true
        ) {
            return new JSONResponse(
                ['error' => 'targetType, targetId, principalId, and role are required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $validRoles = ['viewer', 'contributor', 'reviewer', 'approver'];
        if (in_array($role, $validRoles, true) === false) {
            return new JSONResponse(
                ['error' => 'role must be one of: '.implode(', ', $validRoles)],
                Http::STATUS_BAD_REQUEST,
            );
        }

        // Check that requester holds approver role.
        $hasRole = $this->roleService->checkRole(
            userId: $userId,
            targetType: $targetType,
            targetId: $targetId,
            minimumRole: 'approver',
        );

        if ($hasRole === false) {
            return new JSONResponse(
                ['error' => 'Insufficient permissions — requires approver role'],
                Http::STATUS_FORBIDDEN,
            );
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $data = [
                'targetType'    => $targetType,
                'targetId'      => $targetId,
                'principalType' => $principalType,
                'principalId'   => $principalId,
                'role'          => $role,
                'grantedBy'     => $userId,
                'grantedAt'     => (new \DateTime())->format('c'),
            ];

            if (empty($expiresAt) === false) {
                $data['expiresAt'] = $expiresAt;
            }

            $created = $objectService->create(
                schema: 'CollaborationRole',
                data: $data,
            );

            // Notify the grantee.
            $this->notifyGrantee(
                principalId: $principalId,
                role: $role,
                targetType: $targetType,
                targetId: $targetId,
            );

            return new JSONResponse($created, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CollaborationRoleController: create failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to assign role'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end create()

    /**
     * Revoke a collaboration role.
     *
     * Requires approver role on the target document.
     *
     * @param string $id The CollaborationRole object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Success or error
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.2
     */
    public function destroy(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $userId = $user->getUID();

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $role          = $objectService->find(schema: 'CollaborationRole', id: $id);

            if ($role === null) {
                return new JSONResponse(
                    ['error' => 'Role not found'],
                    Http::STATUS_NOT_FOUND,
                );
            }

            $hasRole = $this->roleService->checkRole(
                userId: $userId,
                targetType: ($role['targetType'] ?? ''),
                targetId: ($role['targetId'] ?? ''),
                minimumRole: 'approver',
            );

            if ($hasRole === false) {
                return new JSONResponse(
                    ['error' => 'Insufficient permissions — requires approver role'],
                    Http::STATUS_FORBIDDEN,
                );
            }

            $objectService->delete(id: $id);

            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CollaborationRoleController: destroy failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to revoke role'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end destroy()

    /**
     * Send a notification to the grantee about their new role.
     *
     * @param string $principalId The userId of the grantee
     * @param string $role        The role name assigned
     * @param string $targetType  The entity type
     * @param string $targetId    The target object ID
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.2
     */
    private function notifyGrantee(
        string $principalId,
        string $role,
        string $targetType,
        string $targetId,
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();

            $notification->setApp(Application::APP_ID)
                ->setUser($principalId)
                ->setDateTime(new \DateTime())
                ->setObject(type: $targetType, id: $targetId)
                ->setSubject(
                    subject: 'role_assigned',
                    parameters: [
                        'role'       => $role,
                        'targetType' => $targetType,
                        'targetId'   => $targetId,
                    ],
                );

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CollaborationRoleController: grantee notification failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end notifyGrantee()
}//end class
