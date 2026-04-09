<?php

/**
 * Shillinq Bulk Action Service
 *
 * Executes batch operations against OpenRegister ObjectService.
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
 * @spec openspec/changes/general/tasks.md#task-11.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for bulk operations on OpenRegister objects.
 *
 * @spec openspec/changes/general/tasks.md#task-11.3
 */
class BulkActionService
{
    /**
     * Constructor for BulkActionService.
     *
     * @param ContainerInterface $container The service container
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
     * Approve multiple objects by setting status to 'approved'.
     *
     * Processes each ID individually; failures are collected without stopping.
     *
     * @param string $schema The schema name
     * @param array  $ids    Array of object IDs to approve
     *
     * @return array{succeeded: int, failed: int, errors: array}
     *
     * @spec openspec/changes/general/tasks.md#task-11.3
     */
    public function bulkApprove(string $schema, array $ids): array
    {
        return $this->bulkUpdate(schema: $schema, ids: $ids, data: ['status' => 'approved']);
    }//end bulkApprove()

    /**
     * Delete multiple objects.
     *
     * Processes each ID individually; failures are collected without stopping.
     *
     * @param string $schema The schema name
     * @param array  $ids    Array of object IDs to delete
     *
     * @return array{succeeded: int, failed: int, errors: array}
     *
     * @spec openspec/changes/general/tasks.md#task-11.3
     */
    public function bulkDelete(string $schema, array $ids): array
    {
        $objectService = $this->getObjectService();
        $succeeded     = 0;
        $failed        = 0;
        $errors        = [];

        foreach ($ids as $id) {
            try {
                $objectService->deleteObject(
                    register: 'shillinq',
                    schema: $schema,
                    id: $id,
                );
                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'id'      => $id,
                    'message' => $e->getMessage(),
                ];
                $this->logger->warning(
                    'Bulk delete failed for object {id}: {message}',
                    [
                        'id'      => $id,
                        'message' => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        return [
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'errors'    => $errors,
        ];
    }//end bulkDelete()

    /**
     * Assign multiple objects to a new assignee.
     *
     * @param string $schema     The schema name
     * @param array  $ids        Array of object IDs
     * @param string $assigneeId The assignee user ID
     *
     * @return array{succeeded: int, failed: int, errors: array}
     *
     * @spec openspec/changes/general/tasks.md#task-11.3
     */
    public function bulkAssign(string $schema, array $ids, string $assigneeId): array
    {
        return $this->bulkUpdate(schema: $schema, ids: $ids, data: ['assigneeId' => $assigneeId]);
    }//end bulkAssign()

    /**
     * Update multiple objects with the given data.
     *
     * @param string $schema The schema name
     * @param array  $ids    Array of object IDs
     * @param array  $data   Data to update on each object
     *
     * @return array{succeeded: int, failed: int, errors: array}
     */
    private function bulkUpdate(string $schema, array $ids, array $data): array
    {
        $objectService = $this->getObjectService();
        $succeeded     = 0;
        $failed        = 0;
        $errors        = [];

        foreach ($ids as $id) {
            try {
                $objectService->updateObject(
                    register: 'shillinq',
                    schema: $schema,
                    id: $id,
                    object: $data,
                );
                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'id'      => $id,
                    'message' => $e->getMessage(),
                ];
                $this->logger->warning(
                    'Bulk update failed for object {id}: {message}',
                    [
                        'id'      => $id,
                        'message' => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        return [
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'errors'    => $errors,
        ];
    }//end bulkUpdate()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return mixed The ObjectService instance
     */
    private function getObjectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
