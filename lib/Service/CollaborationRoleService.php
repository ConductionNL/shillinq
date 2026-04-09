<?php

/**
 * Shillinq Collaboration Role Service
 *
 * Service for checking role-based access on collaboration targets.
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

use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for checking role-based access on collaboration targets.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-7.2
 */
class CollaborationRoleService
{

    /**
     * Role hierarchy mapping from role name to numeric level.
     *
     * @var array<string,int>
     */
    private const ROLE_HIERARCHY = [
        'viewer'      => 1,
        'contributor' => 2,
        'reviewer'    => 3,
        'approver'    => 4,
    ];

    /**
     * Constructor for the CollaborationRoleService.
     *
     * @param ContainerInterface $container    The service container
     * @param IGroupManager      $groupManager The group manager
     * @param LoggerInterface    $logger       The logger
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.2
     */
    public function __construct(
        private ContainerInterface $container,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether a user meets the minimum role requirement on a target.
     *
     * Resolves both direct user assignments and group-based assignments.
     * Expired roles are ignored. Returns false when no matching role is found
     * (the caller is responsible for any fallback logic).
     *
     * @param string $userId      The user ID to check
     * @param string $targetType  The type of the target object
     * @param string $targetId    The unique identifier of the target object
     * @param string $minimumRole The minimum role required (viewer|contributor|reviewer|approver)
     *
     * @return bool True if the user meets the minimum role, false otherwise
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.2
     */
    public function checkRole(string $userId, string $targetType, string $targetId, string $minimumRole): bool
    {
        $minimumLevel = (self::ROLE_HIERARCHY[$minimumRole] ?? 0);
        if ($minimumLevel === 0) {
            $this->logger->warning(
                'Shillinq: unknown minimum role requested',
                ['minimumRole' => $minimumRole]
            );
            return false;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: could not resolve ObjectService',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try

        try {
            $roles = $objectService->findObjects(
                filters: [
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to fetch collaboration roles',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try

        $now = new \DateTime();

        foreach ($roles as $role) {
            // Check expiry.
            if (empty($role['expiresAt']) === false) {
                $expiresAt = new \DateTime($role['expiresAt']);
                if ($expiresAt < $now) {
                    continue;
                }
            }

            // Check principal match (direct user or group membership).
            $principalId  = ($role['principalId'] ?? '');
            $principalHit = false;

            if ($principalId === $userId) {
                $principalHit = true;
            } elseif ($this->groupManager->isInGroup(userId: $userId, group: $principalId) === true) {
                $principalHit = true;
            }

            if ($principalHit === false) {
                continue;
            }

            // Check hierarchy level.
            $roleLevel = (self::ROLE_HIERARCHY[$role['role'] ?? ''] ?? 0);
            if ($roleLevel >= $minimumLevel) {
                return true;
            }
        }//end foreach

        return false;
    }//end checkRole()
}//end class
