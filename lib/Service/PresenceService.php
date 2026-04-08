<?php

/**
 * Shillinq Presence Service
 *
 * Manages real-time presence records for document viewers.
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
 * @spec openspec/changes/collaboration/tasks.md#task-7.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Upserts PresenceRecord objects and prunes stale records from list responses.
 *
 * Records with lastSeenAt older than 120 seconds are pruned from active viewer lists.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-7.3
 */
class PresenceService
{

    /**
     * Maximum age in seconds for a presence record to be considered active.
     *
     * @var int
     */
    private const ACTIVITY_WINDOW_SECONDS = 120;

    /**
     * Constructor for PresenceService.
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
     * Upsert a presence ping for the given user and target.
     *
     * If a PresenceRecord already exists for the (userId, targetType, targetId)
     * combination, it is updated. Otherwise a new record is created.
     *
     * @param string $userId     The Nextcloud userId
     * @param string $targetType The entity type being viewed
     * @param string $targetId   The OpenRegister object ID
     * @param bool   $isEditing  Whether the user has the edit form open
     *
     * @return array<string,mixed> The upserted presence record
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.3
     */
    public function ping(string $userId, string $targetType, string $targetId, bool $isEditing = false): array
    {
        $now = (new \DateTime())->format('c');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Try to find an existing record.
            $existing = $objectService->findAll(
                schema: 'PresenceRecord',
                filters: [
                    'userId'     => $userId,
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ],
            );

            $records = ($existing['results'] ?? $existing ?? []);

            if (empty($records) === false) {
                // Update the existing record.
                $record = $records[0];
                return $objectService->update(
                    id: $record['id'],
                    data: [
                        'lastSeenAt' => $now,
                        'isEditing'  => $isEditing,
                    ],
                );
            }

            // Create a new record.
            return $objectService->create(
                schema: 'PresenceRecord',
                data: [
                    'userId'     => $userId,
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                    'lastSeenAt' => $now,
                    'isEditing'  => $isEditing,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'PresenceService: ping failed',
                [
                    'userId'    => $userId,
                    'exception' => $e->getMessage(),
                ]
            );
            return [];
        }//end try
    }//end ping()

    /**
     * Get active viewers for a specific target.
     *
     * Returns only records with lastSeenAt within the activity window (120 seconds).
     *
     * @param string $targetType The entity type
     * @param string $targetId   The OpenRegister object ID
     *
     * @return array<int,array<string,mixed>> List of active presence records
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.3
     */
    public function getActiveViewers(string $targetType, string $targetId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $result        = $objectService->findAll(
                schema: 'PresenceRecord',
                filters: [
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ],
            );

            $records  = ($result['results'] ?? $result ?? []);
            $cutoff   = new \DateTime();
            $cutoff->modify('-'.self::ACTIVITY_WINDOW_SECONDS.' seconds');
            $active   = [];

            foreach ($records as $record) {
                $lastSeen = new \DateTime($record['lastSeenAt'] ?? '1970-01-01');
                if ($lastSeen >= $cutoff) {
                    $active[] = $record;
                }
            }//end foreach

            return $active;
        } catch (\Throwable $e) {
            $this->logger->error(
                'PresenceService: getActiveViewers failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getActiveViewers()
}//end class
