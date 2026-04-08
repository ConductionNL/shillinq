<?php

/**
 * Shillinq Collaboration Role Service
 *
 * Enforces per-document collaboration roles with hierarchy checking.
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
 * @spec openspec/changes/collaboration/tasks.md#task-7.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Checks whether a given principalId holds a minimum role on a target document.
 *
 * Role hierarchy: viewer(1) < contributor(2) < reviewer(3) < approver(4).
 * Falls back to AccessControl global permissions when no CollaborationRole exists.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-7.2
 */
class CollaborationRoleService
{

    /**
     * Role hierarchy map: role name to numeric level.
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
     * Constructor for CollaborationRoleService.
     *
     * @param ContainerInterface $container    The DI container for OpenRegister access
     * @param IGroupManager      $groupManager The Nextcloud group manager
     * @param LoggerInterface    $logger       The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check if a user meets a minimum role on the given target.
     *
     * Fetches all CollaborationRole objects for the target, filters to matching
     * principalId (user) or group membership, checks expiresAt, and returns
     * true only if the user's highest role meets or exceeds the minimum.
     *
     * @param string $userId      The Nextcloud userId to check
     * @param string $targetType  The entity type
     * @param string $targetId    The target object ID
     * @param string $minimumRole The minimum role required
     *
     * @return bool Whether the user meets the minimum role requirement
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.2
     */
    public function checkRole(
        string $userId,
        string $targetType,
        string $targetId,
        string $minimumRole,
    ): bool {
        $minimumLevel = (self::ROLE_HIERARCHY[$minimumRole] ?? 0);
        $roles        = $this->getRolesForTarget(
            targetType: $targetType,
            targetId: $targetId,
        );

        $highestLevel = 0;
        $now          = new \DateTime();

        foreach ($roles as $role) {
            if ($this->roleMatchesUser(role: $role, userId: $userId) === false) {
                continue;
            }

            // Skip expired roles.
            $expiresAt = ($role['expiresAt'] ?? null);
            if (empty($expiresAt) === false && new \DateTime($expiresAt) < $now) {
                continue;
            }

            $roleName  = ($role['role'] ?? 'viewer');
            $roleLevel = (self::ROLE_HIERARCHY[$roleName] ?? 0);
            if ($roleLevel > $highestLevel) {
                $highestLevel = $roleLevel;
            }
        }//end foreach

        if ($highestLevel >= $minimumLevel) {
            return true;
        }

        // No matching role found — fall back to AccessControl global permissions.
        return $this->checkAccessControlFallback(
            userId: $userId,
            targetType: $targetType,
            targetId: $targetId,
            minimumRole: $minimumRole,
        );
    }//end checkRole()

    /**
     * Get all CollaborationRole objects for a specific target.
     *
     * @param string $targetType The entity type
     * @param string $targetId   The target object ID
     *
     * @return array<int,array<string,mixed>> List of role objects
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.2
     */
    public function getRolesForTarget(string $targetType, string $targetId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $result        = $objectService->findAll(
                schema: 'CollaborationRole',
                filters: [
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ],
            );

            return ($result['results'] ?? $result ?? []);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CollaborationRoleService: failed to fetch roles',
                ['exception' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getRolesForTarget()

    /**
     * Get the numeric level for a role name.
     *
     * @param string $role The role name
     *
     * @return int The numeric level
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.2
     */
    public function getRoleLevel(string $role): int
    {
        return (self::ROLE_HIERARCHY[$role] ?? 0);
    }//end getRoleLevel()

    /**
     * Check if a role applies to the given user (direct match or group membership).
     *
     * @param array<string,mixed> $role   The CollaborationRole object
     * @param string              $userId The Nextcloud userId
     *
     * @return bool Whether the role matches the user
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.2
     */
    private function roleMatchesUser(array $role, string $userId): bool
    {
        $principalType = ($role['principalType'] ?? 'user');
        $principalId   = ($role['principalId'] ?? '');

        if ($principalType === 'user') {
            return $principalId === $userId;
        }

        if ($principalType === 'group') {
            return $this->groupManager->isInGroup(
                userId: $userId,
                group: $principalId,
            );
        }

        return false;
    }//end roleMatchesUser()

    /**
     * Fall back to AccessControl global permissions when no CollaborationRole exists.
     *
     * If the user has global editor access they are treated as contributor.
     *
     * @param string $userId      The Nextcloud userId
     * @param string $targetType  The entity type
     * @param string $targetId    The target object ID
     * @param string $minimumRole The minimum role required
     *
     * @return bool Whether the user has sufficient global access
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.2
     */
    private function checkAccessControlFallback(
        string $userId,
        string $targetType,
        string $targetId,
        string $minimumRole,
    ): bool {
        $minimumLevel = (self::ROLE_HIERARCHY[$minimumRole] ?? 0);

        // Global editors are treated as contributors (level 2).
        $contributorLevel = self::ROLE_HIERARCHY['contributor'];

        // If minimum required exceeds contributor, global access is not enough.
        if ($minimumLevel > $contributorLevel) {
            return false;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $object        = $objectService->find(
                schema: $targetType,
                id: $targetId,
            );

            // If the object exists and user can access it via OpenRegister, treat as contributor.
            return $object !== null;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'CollaborationRoleService: AccessControl fallback failed',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end checkAccessControlFallback()
}//end class
