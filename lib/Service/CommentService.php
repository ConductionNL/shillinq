<?php

/**
 * Shillinq Comment Service
 *
 * Business logic layer for comment CRUD operations.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns all ObjectService interactions for Comment objects.
 *
 * Provides findAll, find, create, update, anonymise, and delete operations.
 * Controllers must not call ObjectService directly for comments.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-8.1
 */
class CommentService
{
    /**
     * Constructor for CommentService.
     *
     * @param ContainerInterface $container The DI container for OpenRegister access
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List comments for a target document.
     *
     * When targetId is empty, all comments for the given targetType are returned
     * (admin listing). Results are sorted by timestamp ascending.
     *
     * @param string $targetType The entity type
     * @param string $targetId   The target object ID (empty = all for type)
     *
     * @return array<int,array<string,mixed>> Sorted comment array
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     */
    public function findAll(string $targetType, string $targetId=''): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $filters       = ['targetType' => $targetType];

            if (empty($targetId) === false) {
                $filters['targetId'] = $targetId;
            }

            $result   = $objectService->findAll(schema: 'Comment', filters: $filters);
            $comments = ($result['results'] ?? $result ?? []);

            usort(
                $comments,
                static function ($a, $b) {
                    return ($a['timestamp'] ?? '') <=> ($b['timestamp'] ?? '');
                }
            );

            return $comments;
        } catch (\Throwable $e) {
            $this->logger->error(
                'CommentService: findAll failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end findAll()

    /**
     * Find a single comment by ID.
     *
     * @param string $id The comment object ID
     *
     * @return array<string,mixed>|null The comment or null if not found
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     */
    public function find(string $id): ?array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $objectService->find(schema: 'Comment', id: $id);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CommentService: find failed',
                ['id' => $id, 'exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end find()

    /**
     * Create a new comment.
     *
     * @param array<string,mixed> $data The comment data
     *
     * @return array<string,mixed> The created comment
     *
     * @throws \Throwable When the creation fails
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     */
    public function create(array $data): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        return $objectService->create(schema: 'Comment', data: $data);
    }//end create()

    /**
     * Update an existing comment.
     *
     * @param string              $id   The comment object ID
     * @param array<string,mixed> $data The fields to update
     *
     * @return array<string,mixed> The updated comment
     *
     * @throws \Throwable When the update fails
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     */
    public function update(string $id, array $data): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        return $objectService->update(id: $id, data: $data);
    }//end update()

    /**
     * Anonymise a comment — zeroes out content, author, and mentions.
     *
     * This is the only correct path for DPO/admin GDPR erasure of comment data.
     * The author field is explicitly overwritten here, which the generic update()
     * action does not allow in order to prevent accidental author spoofing.
     *
     * @param string $id The comment object ID
     *
     * @return array<string,mixed> The anonymised comment
     *
     * @throws \Throwable When the operation fails
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     */
    public function anonymise(string $id): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        return $objectService->update(
            id: $id,
            data: [
                'content'  => '[anonymised]',
                'author'   => '[anonymised]',
                'mentions' => [],
                'editedAt' => (new \DateTime())->format('c'),
            ],
        );
    }//end anonymise()

    /**
     * Delete a comment.
     *
     * @param string $id The comment object ID
     *
     * @return void
     *
     * @throws \Throwable When the deletion fails
     *
     * @spec openspec/changes/collaboration/tasks.md#task-8.1
     */
    public function delete(string $id): void
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $objectService->delete(id: $id);
    }//end delete()
}//end class
