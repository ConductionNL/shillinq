<?php

/**
 * Shillinq Recertification Notification Background Job
 *
 * Dispatches recertification review notifications on schedule.
 *
 * @category  BackgroundJob
 * @package   OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\RecertificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Background job that evaluates recertification campaigns hourly.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-4
 */
class RecertificationNotificationJob extends TimedJob
{


    /**
     * Constructor for RecertificationNotificationJob.
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
        parent::__construct($time);
        // Run every hour.
        $this->setInterval(3600);

    }//end __construct()


    /**
     * Evaluate active recertification campaigns and dispatch notifications if due.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-4
     */
    protected function run($argument): void
    {
        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

            $campaigns = $objectService->findObjects(
                filters: ['isActive' => true],
                register: Application::APP_ID,
                schema: 'accessRecertification',
            );

            $now = new \DateTime();

            foreach ($campaigns as $campaign) {
                if ($this->isDue($campaign, $now) === false) {
                    continue;
                }

                $this->recertificationService->dispatchReviewNotifications($campaign);

                // Update lastRunAt and nextRunAt.
                $campaign['lastRunAt'] = $now->format('c');
                $campaign['nextRunAt'] = $this->computeNextRun($campaign['cronExpression'], $now);

                $objectService->saveObject(
                    register: Application::APP_ID,
                    schema: 'accessRecertification',
                    object: $campaign,
                );

                $this->logger->info(
                    'Shillinq: recertification notifications dispatched',
                    ['campaign' => ($campaign['name'] ?? 'unknown')]
                );
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: RecertificationNotificationJob failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end run()


    /**
     * Check if a campaign is due based on its cron expression and nextRunAt.
     *
     * @param array     $campaign The campaign object
     * @param \DateTime $now      The current time
     *
     * @return bool True if the campaign is due
     */
    private function isDue(array $campaign, \DateTime $now): bool
    {
        if (isset($campaign['nextRunAt']) === true && empty($campaign['nextRunAt']) === false) {
            $nextRun = new \DateTime($campaign['nextRunAt']);
            return $now >= $nextRun;
        }

        // If no nextRunAt, always run.
        return true;

    }//end isDue()


    /**
     * Compute the next run time from a cron expression.
     *
     * Simple implementation that adds the shortest interval implied by the cron.
     *
     * @param string    $cronExpression The cron expression
     * @param \DateTime $from           The reference time
     *
     * @return string ISO 8601 datetime string
     */
    private function computeNextRun(string $cronExpression, \DateTime $from): string
    {
        // Simple heuristic: add 1 month for monthly crons, 1 day for daily, 1 hour for hourly.
        $parts = explode(' ', trim($cronExpression));

        $next = clone $from;

        if (count($parts) >= 5) {
            if ($parts[2] !== '*') {
                // Day of month specified — monthly.
                $next->modify('+1 month');
            } elseif ($parts[1] !== '*') {
                // Hour specified — daily.
                $next->modify('+1 day');
            } else {
                // Default to hourly.
                $next->modify('+1 hour');
            }
        } else {
            $next->modify('+1 day');
        }

        return $next->format('c');

    }//end computeNextRun()


}//end class
