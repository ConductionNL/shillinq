<?php

/**
 * Shillinq Field Security Service
 *
 * Enforces field-level read/write permissions based on the user's role.
 *
 * @category  Service
 * @package   OCA\Shillinq\Service
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for filtering object fields based on role permissions.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.3
 */
class FieldSecurityService
{

    /**
     * Per-request permission cache.
     *
     * @var list<array<string,mixed>>|null
     */
    private ?array $permissionCache = null;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     * @param string             $appId     The Nextcloud app ID
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private string $appId='shillinq',
    ) {
    }//end __construct()

    /**
     * Filter response fields based on the user's role read permissions.
     *
     * @param array  $object     The object data to filter
     * @param string $schemaName The schema name
     * @param string $userId     The user ID to check permissions for
     *
     * @return array The filtered object data
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.3
     */
    public function filterResponse(array $object, string $schemaName, string $userId): array
    {
        $permissions = $this->loadPermissions(userId: $userId);

        foreach ($permissions as $permission) {
            if ($permission['schemaName'] !== $schemaName) {
                continue;
            }

            if (isset($permission['canRead']) === true
                && $permission['canRead'] === false
                && array_key_exists($permission['fieldName'], $object) === true
            ) {
                unset($object[$permission['fieldName']]);
            }
        }

        return $object;
    }//end filterResponse()

    /**
     * Check whether a user may write to a specific field.
     *
     * @param string $schemaName The schema name
     * @param string $fieldName  The field name
     * @param string $userId     The user ID
     *
     * @return bool True if the write is allowed
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.3
     */
    public function checkWritePermission(string $schemaName, string $fieldName, string $userId): bool
    {
        $permissions = $this->loadPermissions(userId: $userId);

        foreach ($permissions as $permission) {
            if ($permission['schemaName'] === $schemaName
                && $permission['fieldName'] === $fieldName
                && isset($permission['canWrite']) === true
                && $permission['canWrite'] === false
            ) {
                return false;
            }
        }

        return true;
    }//end checkWritePermission()

    /**
     * Load the permission matrix for a user, cached per request.
     *
     * @param string $userId The user ID to load permissions for
     *
     * @return array The list of permission objects
     */
    private function loadPermissions(string $userId): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            // Load the user's roles (base + delegated).
            $roleIds = $this->resolveUserRoleIds(
                objectService: $objectService,
                userId: $userId,
            );

            $allPermissions = [];
            foreach ($roleIds as $roleId) {
                $results = $objectService->findObjects(
                    $this->appId,
                    'permission',
                    ['roleId' => $roleId],
                );
                foreach ($results as $perm) {
                    $allPermissions[] = $perm;
                }
            }

            $this->permissionCache = $allPermissions;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to load permissions',
                ['exception' => $e->getMessage()]
            );
            $this->permissionCache = [];
        }//end try

        return $this->permissionCache;
    }//end loadPermissions()

    /**
     * Resolve all active role IDs for a user (base + active delegations).
     *
     * @param object $objectService The OpenRegister object service
     * @param string $userId        The user ID
     *
     * @return array<string> The role IDs
     */
    private function resolveUserRoleIds(object $objectService, string $userId): array
    {
        $roleIds = [];

        // Find the user's base role assignments.
        $accessRights = $objectService->findObjects(
            $this->appId,
            'accessRight',
            [
                'userId'   => $userId,
                'isActive' => true,
            ],
        );

        foreach ($accessRights as $right) {
            if (empty($right['roleId']) === false) {
                $roleIds[] = $right['roleId'];
            }
        }

        return array_unique($roleIds);
    }//end resolveUserRoleIds()

    /**
     * Reset the per-request permission cache.
     *
     * @return void
     */
    public function resetCache(): void
    {
        $this->permissionCache = null;
    }//end resetCache()
}//end class
