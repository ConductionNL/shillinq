<?php

/**
 * Shillinq Comment Controller
 *
 * Controller for managing collaboration comments on objects.
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
use OCA\Shillinq\Service\MentionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing collaboration comments on objects.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-8.1
 */
class CommentController extends Controller
{
    /**
     * Constructor for the CommentController.
     *
     * @param IRequest                 $request                  The request object
     * @param ContainerInterface       $container                The DI container for ObjectService access
     * @param MentionService           $mentionService           The mention processing service
     * @param CollaborationRoleService $collaborationRoleService The collaboration role service
     * @param IUserSession             $userSession              The user session
     * @param LoggerInterface          $logger                   The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private MentionService $mentionService,
        private CollaborationRoleService $collaborationRoleService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List comments filtered by target type and target ID.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        try {
            $targetType = $this->request->getParam('targetType');
            $targetId   = $this->request->getParam('targetId');

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $comments = $objectService->findObjects(
                filters: [
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ]
            );

            usort($comments, function ($a, $b) {
                return ($a['created'] ?? '') <=> ($b['created'] ?? '');
            });

            return new JSONResponse(data: $comments);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to list comments',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end index()

    /**
     * Create a new comment on a target object.
     *
     * Requires the 'contributor' role on the target. After creation,
     * processes any @mentions in the comment body.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     *
     * @return JSONResponse
     */
    public function create(): JSONResponse
    {
        try {
            $data       = $this->request->getParams();
            $targetType = ($data['targetType'] ?? null);
            $targetId   = ($data['targetId'] ?? null);

            $hasRole = $this->collaborationRoleService->checkRole(
                targetType: $targetType,
                targetId: $targetId,
                role: 'contributor'
            );
            if ($hasRole === false) {
                return new JSONResponse(data: ['error' => 'Forbidden'], statusCode: 403);
            }

            $user = $this->userSession->getUser();

            $data['author']  = $user->getUID();
            $data['created'] = date('c');

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $comment = $objectService->saveObject(object: $data);

            $this->mentionService->processMentions(
                comment: $comment
            );

            return new JSONResponse(data: $comment);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to create comment',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end create()

    /**
     * Update an existing comment.
     *
     * Only the original author or an admin may edit a comment.
     * Sets the editedAt timestamp on update.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     *
     * @param string $id The comment ID
     *
     * @return JSONResponse
     */
    public function update(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $comment = $objectService->getObject(id: $id);

            $user    = $this->userSession->getUser();
            $userId  = $user->getUID();
            $isAdmin = $this->collaborationRoleService->checkRole(
                targetType: ($comment['targetType'] ?? ''),
                targetId: ($comment['targetId'] ?? ''),
                role: 'admin'
            );

            if ($comment['author'] !== $userId && $isAdmin === false) {
                return new JSONResponse(data: ['error' => 'Forbidden'], statusCode: 403);
            }

            $data             = $this->request->getParams();
            $data['editedAt'] = date('c');

            $updated = $objectService->saveObject(object: array_merge($comment, $data));

            return new JSONResponse(data: $updated);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to update comment',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end update()

    /**
     * Resolve a comment.
     *
     * Requires the 'reviewer' role on the target object. Sets the
     * resolved flag, resolvedBy user, and resolvedAt timestamp.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     *
     * @param string $id The comment ID
     *
     * @return JSONResponse
     */
    public function resolve(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $comment = $objectService->getObject(id: $id);

            $hasRole = $this->collaborationRoleService->checkRole(
                targetType: ($comment['targetType'] ?? ''),
                targetId: ($comment['targetId'] ?? ''),
                role: 'reviewer'
            );
            if ($hasRole === false) {
                return new JSONResponse(data: ['error' => 'Forbidden'], statusCode: 403);
            }

            $user = $this->userSession->getUser();

            $comment['resolved']   = true;
            $comment['resolvedBy'] = $user->getUID();
            $comment['resolvedAt'] = date('c');

            $updated = $objectService->saveObject(object: $comment);

            return new JSONResponse(data: $updated);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to resolve comment',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end resolve()

    /**
     * Delete a comment.
     *
     * The original author may delete within 5 minutes of creation.
     * Admins and DPOs may delete at any time.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     *
     * @param string $id The comment ID
     *
     * @return JSONResponse
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $comment = $objectService->getObject(id: $id);

            $user    = $this->userSession->getUser();
            $userId  = $user->getUID();
            $isAdmin = $this->collaborationRoleService->checkRole(
                targetType: ($comment['targetType'] ?? ''),
                targetId: ($comment['targetId'] ?? ''),
                role: 'admin'
            );
            $isDpo = $this->collaborationRoleService->checkRole(
                targetType: ($comment['targetType'] ?? ''),
                targetId: ($comment['targetId'] ?? ''),
                role: 'dpo'
            );

            if ($isAdmin === true || $isDpo === true) {
                $objectService->deleteObject(id: $id);
                return new JSONResponse(data: ['success' => true]);
            }

            if ($comment['author'] === $userId) {
                $createdAt    = strtotime($comment['created'] ?? 'now');
                $fiveMinLimit = ($createdAt + 300);

                if (time() > $fiveMinLimit) {
                    return new JSONResponse(data: ['error' => 'Forbidden'], statusCode: 403);
                }

                $objectService->deleteObject(id: $id);
                return new JSONResponse(data: ['success' => true]);
            }

            return new JSONResponse(data: ['error' => 'Forbidden'], statusCode: 403);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to delete comment',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end destroy()
}//end class
