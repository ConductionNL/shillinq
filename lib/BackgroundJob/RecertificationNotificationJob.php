<?php

/**
 * Shillinq Recertification Notification Job
 *
 * Hourly background job that dispatches recertification review notifications.
 *
 * @category  BackgroundJob
 * @package   OCA\Shillinq\BackgroundJob
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-4.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\RecertificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Timed job that evaluates recertification campaigns hourly.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-4.2
 */
class RecertificationNotificationJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory           $time                   The time factory
     * @param ContainerInterface     $container              The DI container
     * @param RecertificationService $recertificationService The recertification service
     * @param LoggerInterface        $logger                 The logger
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private ContainerInterface $container,
        private RecertificationService $recertificationService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every hour (3600 seconds).
        $this->setInterval(seconds: 3600);
    }//end __construct()

    /**
     * Evaluate active campaigns and dispatch notifications when due.
     *
     * @param mixed $argument Unused argument
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-4.2
     */
    protected function run($argument): void
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            $campaigns = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'accessRecertification',
                filters: ['isActive' => true],
            );

            $now = new \DateTime();

            foreach ($campaigns as $campaign) {
                $nextRun = ($campaign['nextRunAt'] ?? null);

                // If nextRunAt is not set or is in the past, this campaign is due.
                if ($nextRun !== null) {
                    $nextRunDate = new \DateTime($nextRun);
                    if ($nextRunDate > $now) {
                        continue;
                    }
                }

                $this->recertificationService->dispatchReviewNotifications(
                    campaign: $campaign,
                );

                // Update lastRunAt and compute nextRunAt.
                $campaign['lastRunAt'] = $now->format('c');
                $campaign['nextRunAt'] = $this->computeNextRun(
                    cronExpression: ($campaign['cronExpression'] ?? '0 9 1 * *'),
                    from: $now,
                );

                $objectService->saveObject(
                    register: Application::APP_ID,
                    schema: 'accessRecertification',
                    object: $campaign,
                );

                $this->logger->info(
                    'Shillinq: recertification notifications dispatched',
                    ['campaignId' => ($campaign['id'] ?? 'unknown')]
                );
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: recertification notification job failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Compute the next run time from a cron expression (simplified).
     *
     * @param string    $cronExpression The cron expression
     * @param \DateTime $from           The current time
     *
     * @return string ISO 8601 timestamp of the next run
     */
    private function computeNextRun(string $cronExpression, \DateTime $from): string
    {
        // Simplified: add 30 days for monthly, 7 days for weekly, 1 day for daily.
        $parts = explode(' ', $cronExpression);
        $day   = ($parts[2] ?? '*');

        if ($day !== '*') {
            // Monthly schedule.
            $next = clone $from;
            $next->modify('+1 month');
            return $next->format('c');
        }

        // Default: next day.
        $next = clone $from;
        $next->modify('+1 day');
        return $next->format('c');
    }//end computeNextRun()
}//end class
