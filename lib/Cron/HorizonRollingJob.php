<?php

/**
 * Shillinq Horizon Rolling Job
 *
 * Weekly Monday 02:00 UTC orchestration: shifts every active CashflowForecastHorizon
 * one week forward (archive week-1, append week-13) by triggering the OpenRegister
 * lifecycle transitions and aggregation recomputes declared on the schema.
 *
 * Per ADR-031 this job contains zero business logic — it only orchestrates the
 * declarative lifecycle and aggregation engines on CashflowForecastHorizon /
 * CashflowWeek schemas in lib/Settings/shillinq_register.json. REQ-CF-000.
 *
 * @category Cron
 * @package  OCA\Shillinq\Cron
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\Shillinq\Cron;

use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Weekly rolling-horizon orchestrator.
 *
 * The job runs once per week (interval 604800s) and is the operational
 * equivalent of cron `0 2 * * 1`. Nextcloud's TimedJob scheduler honours the
 * interval and the operator's chosen window; this implementation is interval-based
 * because Nextcloud does not support cron-string scheduling directly.
 *
 * @psalm-api
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-19
 */
class HorizonRollingJob extends TimedJob
{

    /**
     * Weekly interval in seconds (7 days). REQ-CF-000.
     *
     * @var int
     */
    private const INTERVAL_SECONDS = 604800;

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time            Time factory for job scheduling.
     * @param SettingsService    $settingsService Resolves the active OR register slug.
     * @param ContainerInterface $container       DI container for OR ObjectService lookup.
     * @param LoggerInterface    $logger          Logger for error handling.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly SettingsService $settingsService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        $this->setInterval(seconds: self::INTERVAL_SECONDS);

        // Horizon roll is not millisecond-critical; let the scheduler pick the window.
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

        // Single instance only — avoid duplicate week-13 inserts on overlap.
        $this->setAllowParallelRuns(allow: false);

    }//end __construct()

    /**
     * Roll every active horizon by one week. Orchestration only — all business
     * logic is in the schema's x-openregister-lifecycle and x-openregister-aggregations.
     *
     * @param mixed $argument Job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-19
     */
    protected function run($argument): void
    {
        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $this->logger->info(
                'HorizonRollingJob: OpenRegister not available, skipping.'
            );
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settingsService->getRegisterSlug();

            $activeHorizons = $objectService
                ->setRegister($registerSlug)
                ->setSchema('CashflowForecastHorizon')
                ->findAll(
                    [
                        'filters' => ['lifecycleState' => 'active'],
                    ]
                );

            $rolled = 0;
            foreach ($activeHorizons as $horizon) {
                $this->rollOne(horizon: $horizon, objectService: $objectService, registerSlug: $registerSlug);
                $rolled++;
            }

            $this->logger->info(
                'HorizonRollingJob: rolled '.$rolled.' active horizon(s) by one week.'
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'HorizonRollingJob failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

    }//end run()

    /**
     * Roll one horizon: trigger lifecycle transition active -> rolling -> active
     * and let OR's aggregation engine recompute week-13.
     *
     * @param mixed  $horizon       The CashflowForecastHorizon entity (OR object).
     * @param mixed  $objectService OR ObjectService (loose typed to avoid hard FK at compile time).
     * @param string $registerSlug  Register slug.
     *
     * @return void
     */
    private function rollOne(mixed $horizon, mixed $objectService, string $registerSlug): void
    {
        // Defensive: the OR object accessor varies between entity and array.
        $horizonId = is_array($horizon) === true ? ($horizon['horizonId'] ?? null) : (method_exists($horizon, 'getHorizonId') === true ? $horizon->getHorizonId() : null);

        if ($horizonId === null) {
            $this->logger->warning('HorizonRollingJob: horizon missing horizonId, skipping.');
            return;
        }

        // Bump rolledOp; the lifecycle transitions are declared on the schema.
        $update = [
            'horizonId'      => $horizonId,
            'rolledOp'       => date('c'),
            'lifecycleState' => 'active',
        ];

        $objectService
            ->setRegister($registerSlug)
            ->setSchema('CashflowForecastHorizon')
            ->saveObject(object: $update);

    }//end rollOne()
}//end class
