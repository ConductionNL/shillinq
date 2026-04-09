<?php

/**
 * Shillinq Purchasing Limit Service
 *
 * Enforces per-buyer purchasing limits defined on Role objects.
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
 * Service that checks purchasing limits against a user's effective roles.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */
class PurchasingLimitService
{
    /**
     * Constructor for PurchasingLimitService.
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
     * Check whether a purchase amount is within the user's authorised limit.
     *
     * Resolves all active roles (base and delegated) and uses the highest
     * applicable limit across them.
     *
     * @param string $userId   The user object ID
     * @param float  $amount   The purchase amount to check
     * @param string $category The purchasing category
     *
     * @return bool True if the amount is within limits, false if blocked
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
     */
    public function checkLimit(string $userId, float $amount, string $category): bool
    {
        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

            // Get all active access rights (base + delegations) for the user.
            $accessRights = $objectService->findObjects(
                filters: [
                    'userId'   => $userId,
                    'isActive' => true,
                ],
                register: Application::APP_ID,
                schema: 'accessRight',
            );

            // Collect all role IDs.
            $roleIds = [];
            foreach ($accessRights as $right) {
                $roleIds[] = $right['roleId'];
            }

            if (empty($roleIds) === true) {
                return true;
            }

            // Load the roles and find the highest applicable limit.
            $highestLimit       = null;
            $hasApplicableLimit = false;

            foreach ($roleIds as $roleId) {
                $roles = $objectService->findObjects(
                    filters: ['id' => $roleId],
                    register: Application::APP_ID,
                    schema: 'role',
                );

                if (empty($roles) === true) {
                    continue;
                }

                $role = $roles[0];

                if (isset($role['purchasingLimitAmount']) === false) {
                    continue;
                }

                // Check if category matches (null category = applies to all).
                $roleCategory = ($role['purchasingLimitCategory'] ?? null);
                if ($roleCategory !== null && $roleCategory !== $category) {
                    continue;
                }

                $hasApplicableLimit = true;
                $limitAmount        = (float) $role['purchasingLimitAmount'];

                if ($highestLimit === null || $limitAmount > $highestLimit) {
                    $highestLimit = $limitAmount;
                }
            }//end foreach

            // If no applicable limit found, allow the purchase.
            if ($hasApplicableLimit === false) {
                return true;
            }

            return $amount <= $highestLimit;
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: failed to check purchasing limit', ['exception' => $e->getMessage()]);
            // Fail open: allow the purchase if the check fails.
            return true;
        }//end try

    }//end checkLimit()
}//end class
