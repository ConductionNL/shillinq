<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Shillinq Order Limit Service
 *
 * Checks per-user ordering limits against basket totals to determine
 * whether approval is required before a purchase order can be placed.
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
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for checking per-user ordering limits against basket totals.
 *
 * Reads per-user or default ordering limits from application configuration
 * and returns whether the given basket total requires approval.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.2
 */
class OrderLimitService
{

    /**
     * Constructor for the OrderLimitService.
     *
     * @param IAppConfig      $appConfig The app config interface
     * @param LoggerInterface $logger    The logger
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether a basket total exceeds the user's ordering limit.
     *
     * Reads the per-user limit from AppSettings key `ordering.limitEur.{userId}`,
     * falling back to `ordering.limitEur.default`. If no limit is configured,
     * approval is never required.
     *
     * @param string $userId      The ID of the user placing the order
     * @param float  $basketTotal The total value of the basket in EUR
     *
     * @return array{requiresApproval: bool, limit: float|null} Approval check result
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.2
     */
    public function check(string $userId, float $basketTotal): array
    {
        // Try per-user limit first.
        $userLimitKey = "ordering.limitEur.{$userId}";
        $limitValue   = $this->appConfig->getValueString(Application::APP_ID, $userLimitKey, '');

        // Fall back to default limit.
        if ($limitValue === '') {
            $defaultLimitKey = 'ordering.limitEur.default';
            $limitValue      = $this->appConfig->getValueString(Application::APP_ID, $defaultLimitKey, '');
        }

        // No limit configured — approval is never required.
        if ($limitValue === '') {
            $this->logger->debug('OrderLimitService: no limit configured for user', [
                'userId'      => $userId,
                'basketTotal' => $basketTotal,
            ]);

            return [
                'requiresApproval' => false,
                'limit'            => null,
            ];
        }

        $limit = (float) $limitValue;

        $requiresApproval = ($basketTotal > $limit);

        $this->logger->info('OrderLimitService: limit check performed', [
            'userId'           => $userId,
            'basketTotal'      => $basketTotal,
            'limit'            => $limit,
            'requiresApproval' => $requiresApproval,
        ]);

        return [
            'requiresApproval' => $requiresApproval,
            'limit'            => $limit,
        ];
    }//end check()
}//end class
