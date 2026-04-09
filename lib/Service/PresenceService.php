<?php

/**
 * Shillinq Presence Service
 *
 * Service for tracking real-time user presence on collaboration targets.
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
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for tracking real-time user presence on collaboration targets.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-7.3
 */
class PresenceService
{

    /**
     * Number of seconds after which a presence record is considered stale.
     *
     * @var int
     */
    private const PRESENCE_TIMEOUT_SECONDS = 120;

    /**
     * Constructor for the PresenceService.
     *
     * @param ContainerInterface $container The service container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.3
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record or update a user's presence on a target object.
     *
     * Creates a new PresenceRecord if none exists for the given user and target,
     * or updates the existing record's lastSeenAt and isEditing fields.
     *
     * @param string $userId     The user whose presence is being recorded
     * @param string $targetType The type of the target object
     * @param string $targetId   The unique identifier of the target object
     * @param bool   $isEditing  Whether the user is currently editing
     *
     * @return array<string,mixed> The upserted presence record
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.3
     */
    public function ping(string $userId, string $targetType, string $targetId, bool $isEditing): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: could not resolve ObjectService for presence',
                ['exception' => $e->getMessage()]
            );
            return [];
        }//end try

        $now = (new \DateTime())->format('c');

        try {
            $existing = $objectService->findObjects(
                filters: [
                    'userId'     => $userId,
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ]
            );

            if (empty($existing) === false) {
                $record = $existing[0];
                $record['lastSeenAt'] = $now;
                $record['isEditing']  = $isEditing;

                $updated = $objectService->saveObject(object: $record);
                return $updated;
            }

            $newRecord = [
                'userId'     => $userId,
                'targetType' => $targetType,
                'targetId'   => $targetId,
                'lastSeenAt' => $now,
                'isEditing'  => $isEditing,
            ];

            $created = $objectService->saveObject(object: $newRecord);
            return $created;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to upsert presence record',
                [
                    'userId'    => $userId,
                    'exception' => $e->getMessage(),
                ]
            );
            return [];
        }//end try
    }//end ping()

    /**
     * Get all users currently active on a target object.
     *
     * Returns only records with a lastSeenAt within the configured timeout
     * (120 seconds).
     *
     * @param string $targetType The type of the target object
     * @param string $targetId   The unique identifier of the target object
     *
     * @return array<int,array<string,mixed>> List of active presence records
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.3
     */
    public function getActiveViewers(string $targetType, string $targetId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: could not resolve ObjectService for active viewers',
                ['exception' => $e->getMessage()]
            );
            return [];
        }//end try

        try {
            $records = $objectService->findObjects(
                filters: [
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to fetch presence records',
                ['exception' => $e->getMessage()]
            );
            return [];
        }//end try

        $cutoff = new \DateTime();
        $cutoff->modify('-'.self::PRESENCE_TIMEOUT_SECONDS.' seconds');

        $active = [];
        foreach ($records as $record) {
            if (empty($record['lastSeenAt']) === true) {
                continue;
            }

            $lastSeen = new \DateTime($record['lastSeenAt']);
            if ($lastSeen >= $cutoff) {
                $active[] = $record;
            }
        }//end foreach

        return $active;
    }//end getActiveViewers()
}//end class
