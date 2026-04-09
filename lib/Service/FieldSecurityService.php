<?php

/**
 * Shillinq Field Security Service
 *
 * Enforces field-level read/write permissions based on role-permission matrix.
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
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that strips restricted fields from responses and blocks restricted writes.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */
class FieldSecurityService
{

    /**
     * Cached permission matrix keyed by roleId.
     *
     * @var array<string, array>|null
     */
    private ?array $permissionCache = null;

    /**
     * Constructor for FieldSecurityService.
     *
     * @param ContainerInterface $container The DI container
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
     * Filter response fields based on the user's role permissions.
     *
     * Strips fields where canRead is false for any of the user's roles.
     *
     * @param array  $object     The object data to filter
     * @param string $schemaName The schema name
     * @param string $userId     The user object ID
     *
     * @return array The filtered object data
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
     */
    public function filterResponse(array $object, string $schemaName, string $userId): array
    {
        $permissions = $this->loadPermissions(userId: $userId);

        foreach ($permissions as $permission) {
            if ($permission['schemaName'] !== $schemaName) {
                continue;
            }

            if (isset($permission['canRead']) === true && $permission['canRead'] === false) {
                unset($object[$permission['fieldName']]);
            }
        }

        return $object;

    }//end filterResponse()

    /**
     * Check if a field write is permitted for the user's role.
     *
     * @param string $schemaName The schema name
     * @param string $fieldName  The field name
     * @param string $userId     The user object ID
     *
     * @return bool True if write is allowed
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
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
     * Load the permission matrix for a user, cached per request lifecycle.
     *
     * @param string $userId The user object ID
     *
     * @return array The permission entries
     */
    private function loadPermissions(string $userId): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

            // Resolve user's role IDs.
            $users = $objectService->findObjects(
                filters: ['id' => $userId],
                register: Application::APP_ID,
                schema: 'user',
            );

            if (empty($users) === true) {
                $this->permissionCache = [];
                return [];
            }

            // Load all active access rights (delegations) for this user.
            $accessRights = $objectService->findObjects(
                filters: [
                    'userId'   => $userId,
                    'isActive' => true,
                ],
                register: Application::APP_ID,
                schema: 'accessRight',
            );

            $roleIds = [];
            foreach ($accessRights as $right) {
                $roleIds[] = $right['roleId'];
            }

            // Load all permissions for these roles.
            $allPermissions = [];
            foreach ($roleIds as $roleId) {
                $perms          = $objectService->findObjects(
                    filters: ['roleId' => $roleId],
                    register: Application::APP_ID,
                    schema: 'permission',
                );
                $allPermissions = array_merge($allPermissions, $perms);
            }

            $this->permissionCache = $allPermissions;
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: failed to load permission matrix', ['exception' => $e->getMessage()]);
            $this->permissionCache = [];
        }//end try

        return $this->permissionCache;

    }//end loadPermissions()
}//end class
