<?php

/**
 * Shillinq Purchasing Limit Service
 *
 * Enforces per-buyer purchasing limits defined on Role objects.
 *
 * @category  Service
 * @package   OCA\Shillinq\Service
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.4
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for checking purchasing limits against user roles and delegations.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.4
 */
class PurchasingLimitService
{

    /**
     * Constructor.
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
     * Returns true if the purchase is allowed, false if it exceeds the limit.
     *
     * @param string $userId   The user ID
     * @param float  $amount   The purchase amount
     * @param string $category The purchase category
     *
     * @return bool True if the purchase is within limits
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.4
     */
    public function checkLimit(string $userId, float $amount, string $category): bool
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            $roles = $this->resolveEffectiveRoles(
                objectService: $objectService,
                userId: $userId,
            );

            if (empty($roles) === true) {
                return false;
            }

            // Find the highest applicable limit across all active roles.
            $highestLimit = null;

            foreach ($roles as $role) {
                $limitAmount   = ($role['purchasingLimitAmount'] ?? null);
                $limitCategory = ($role['purchasingLimitCategory'] ?? null);

                if ($limitAmount === null) {
                    continue;
                }

                // Category must match, or the role limit applies to all categories.
                if ($limitCategory !== null && $limitCategory !== $category) {
                    continue;
                }

                if ($highestLimit === null || (float) $limitAmount > $highestLimit) {
                    $highestLimit = (float) $limitAmount;
                }
            }

            // No applicable limit found means no restriction.
            if ($highestLimit === null) {
                return true;
            }

            return $amount <= $highestLimit;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: purchasing limit check failed',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end checkLimit()

    /**
     * Resolve all effective roles for a user (base + active delegations).
     *
     * @param object $objectService The OpenRegister object service
     * @param string $userId        The user ID
     *
     * @return array The list of role objects
     */
    private function resolveEffectiveRoles(object $objectService, string $userId): array
    {
        $roles = [];

        $accessRights = $objectService->findObjects(
            register: Application::APP_ID,
            schema: 'accessRight',
            filters: [
                'userId'   => $userId,
                'isActive' => true,
            ],
        );

        foreach ($accessRights as $right) {
            if (empty($right['roleId']) === true) {
                continue;
            }

            try {
                $role = $objectService->getObject(
                    register: Application::APP_ID,
                    schema: 'role',
                    id: $right['roleId'],
                );
                if (empty($role) === false && ($role['isActive'] ?? false) === true) {
                    $roles[] = $role;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Shillinq: could not load role for limit check',
                    ['roleId' => $right['roleId'], 'exception' => $e->getMessage()]
                );
            }//end try
        }

        return $roles;
    }//end resolveEffectiveRoles()
}//end class
