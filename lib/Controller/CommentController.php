<?php

/**
 * Shillinq Comment Controller
 *
 * OCS API controller for comment CRUD operations.
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
 * @spec openspec/changes/collaboration/tasks.md#task-8.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\CollaborationRoleService;
use OCA\Shillinq\Service\DocumentEventNotifier;
use OCA\Shillinq\Service\MentionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OCS API controller for comment CRUD: create, list, edit, resolve, delete.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-8.1
 */
class CommentController extends Controller
{

    /**
     * Maximum minutes within which an author can delete their own comment.
     *
     * @var int
     */
    private const DELETE_WINDOW_MINUTES = 5;

    /**
     * Constructor for CommentController.
     *
     * @param IRequest                 $request      The request object
     * @param ContainerInterface       $container    The DI container
     * @param IUserSession             $userSession  The user session
     * @param IGroupManager            $groupManager The group manager
     * @param CollaborationRoleService $roleService  The role service
     * @param MentionService           $mentionSvc   The mention service
     * @param DocumentEventNotifier    $eventNotify  The event notifier
     * @param LoggerInterface          $logger       The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private CollaborationRoleService $roleService,
        private MentionService $mentionSvc,
        private DocumentEventNotifier $eventNotify,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List comments for a target document.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Comments sorted by timestamp ascending
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
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

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $result        = $objectService->findAll(
                schema: 'Comment',
                filters: [
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ],
            );

            $comments = ($result['results'] ?? $result ?? []);

            // Sort by timestamp ascending.
            usort($comments, static function ($a, $b) {
                return ($a['timestamp'] ?? '') <=> ($b['timestamp'] ?? '');
            });

            return new JSONResponse($comments);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CommentController: index failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to fetch comments'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end index()

    /**
     * Create a new comment.
     *
     * Requires at least contributor role on the target. Triggers MentionService
     * and DocumentEventNotifier after creation.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse The created comment or error
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
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

        $userId     = $user->getUID();
        $content    = $this->request->getParam('content', '');
        $targetType = $this->request->getParam('targetType', '');
        $targetId   = $this->request->getParam('targetId', '');

        if (empty($content) === true || empty($targetType) === true || empty($targetId) === true) {
            return new JSONResponse(
                ['error' => 'content, targetType, and targetId are required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        // Check contributor role.
        $hasRole = $this->roleService->checkRole(
            userId: $userId,
            targetType: $targetType,
            targetId: $targetId,
            minimumRole: 'contributor',
        );

        if ($hasRole === false) {
            return new JSONResponse(
                ['error' => 'Insufficient permissions — requires at least contributor role'],
                Http::STATUS_FORBIDDEN,
            );
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $mentions      = $this->mentionSvc->extractMentions(content: $content);

            $comment = $objectService->create(
                schema: 'Comment',
                data: [
                    'content'    => $content,
                    'author'     => $userId,
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                    'timestamp'  => (new \DateTime())->format('c'),
                    'mentions'   => $mentions,
                    'resolved'   => false,
                ],
            );

            // Process mentions (send notifications).
            $this->mentionSvc->processMentions(
                content: $content,
                targetType: $targetType,
                targetId: $targetId,
            );

            // Notify reviewers/approvers.
            $this->eventNotify->notify(
                eventType: 'comment.added',
                targetType: $targetType,
                targetId: $targetId,
                context: ['author' => $userId],
            );

            return new JSONResponse($comment, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CommentController: create failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to create comment'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end create()

    /**
     * Edit an existing comment (author or admin only).
     *
     * @NoAdminRequired
     *
     * @param string $id The comment object ID
     *
     * @return JSONResponse The updated comment or error
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     */
    public function update(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $userId  = $user->getUID();
        $content = $this->request->getParam('content', '');

        if (empty($content) === true) {
            return new JSONResponse(
                ['error' => 'content is required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $comment       = $objectService->find(schema: 'Comment', id: $id);

            if ($comment === null) {
                return new JSONResponse(
                    ['error' => 'Comment not found'],
                    Http::STATUS_NOT_FOUND,
                );
            }

            $isAdmin = $this->groupManager->isAdmin($userId);
            if (($comment['author'] ?? '') !== $userId && $isAdmin === false) {
                return new JSONResponse(
                    ['error' => 'Only the author or an admin can edit this comment'],
                    Http::STATUS_FORBIDDEN,
                );
            }

            $mentions = $this->mentionSvc->extractMentions(content: $content);
            $updated  = $objectService->update(
                id: $id,
                data: [
                    'content'  => $content,
                    'mentions' => $mentions,
                    'editedAt' => (new \DateTime())->format('c'),
                ],
            );

            return new JSONResponse($updated);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CommentController: update failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to update comment'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end update()

    /**
     * Mark a comment as resolved.
     *
     * Requires at least reviewer role on the target document.
     *
     * @NoAdminRequired
     *
     * @param string $id The comment object ID
     *
     * @return JSONResponse The resolved comment or error
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     */
    public function resolve(string $id): JSONResponse
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
            $comment       = $objectService->find(schema: 'Comment', id: $id);

            if ($comment === null) {
                return new JSONResponse(
                    ['error' => 'Comment not found'],
                    Http::STATUS_NOT_FOUND,
                );
            }

            // Author can also resolve their own comment.
            $isAuthor = (($comment['author'] ?? '') === $userId);
            if ($isAuthor === false) {
                $hasRole = $this->roleService->checkRole(
                    userId: $userId,
                    targetType: ($comment['targetType'] ?? ''),
                    targetId: ($comment['targetId'] ?? ''),
                    minimumRole: 'reviewer',
                );

                if ($hasRole === false) {
                    return new JSONResponse(
                        ['error' => 'Insufficient permissions — requires at least reviewer role'],
                        Http::STATUS_FORBIDDEN,
                    );
                }
            }

            $updated = $objectService->update(
                id: $id,
                data: [
                    'resolved'   => true,
                    'resolvedBy' => $userId,
                    'resolvedAt' => (new \DateTime())->format('c'),
                ],
            );

            // Notify about resolution.
            $this->eventNotify->notify(
                eventType: 'comment.resolved',
                targetType: ($comment['targetType'] ?? ''),
                targetId: ($comment['targetId'] ?? ''),
                context: ['resolvedBy' => $userId],
            );

            return new JSONResponse($updated);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CommentController: resolve failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to resolve comment'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end resolve()

    /**
     * Delete a comment.
     *
     * Author can delete within 5 minutes; DPO or admin can delete at any time.
     *
     * @NoAdminRequired
     *
     * @param string $id The comment object ID
     *
     * @return JSONResponse Success or error
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
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
            $comment       = $objectService->find(schema: 'Comment', id: $id);

            if ($comment === null) {
                return new JSONResponse(
                    ['error' => 'Comment not found'],
                    Http::STATUS_NOT_FOUND,
                );
            }

            $isAdmin  = $this->groupManager->isAdmin($userId);
            $isAuthor = (($comment['author'] ?? '') === $userId);

            if ($isAdmin === true) {
                // Admin/DPO can always delete.
                $objectService->delete(id: $id);
                return new JSONResponse(['success' => true]);
            }

            if ($isAuthor === true) {
                // Author can delete within 5 minutes.
                $createdAt = new \DateTime($comment['timestamp'] ?? 'now');
                $now       = new \DateTime();
                $diffMins  = (int) (($now->getTimestamp() - $createdAt->getTimestamp()) / 60);

                if ($diffMins <= self::DELETE_WINDOW_MINUTES) {
                    $objectService->delete(id: $id);
                    return new JSONResponse(['success' => true]);
                }

                return new JSONResponse(
                    ['error' => 'Deletion window expired — only DPO or admin can delete after 5 minutes'],
                    Http::STATUS_FORBIDDEN,
                );
            }

            return new JSONResponse(
                ['error' => 'Only the author (within 5 min) or DPO/admin can delete comments'],
                Http::STATUS_FORBIDDEN,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'CommentController: destroy failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to delete comment'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end destroy()
}//end class
