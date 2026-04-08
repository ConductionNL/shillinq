<?php

/**
 * Shillinq Automation Rule Background Job
 *
 * Runs every 15 minutes, evaluating all active AutomationRule objects
 * against their trigger schemas.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/general/tasks.md#task-6.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\Service\AutomationRuleEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Background job that evaluates automation rules on a 15-minute interval.
 *
 * @spec openspec/changes/general/tasks.md#task-6.3
 */
class AutomationRuleJob extends TimedJob
{
    /**
     * Constructor for AutomationRuleJob.
     *
     * @param ITimeFactory            $time      The time factory
     * @param AutomationRuleEvaluator $evaluator The rule evaluator service
     * @param ContainerInterface      $container The DI container
     * @param LoggerInterface         $logger    The logger
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private AutomationRuleEvaluator $evaluator,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run every 15 minutes (900 seconds).
        $this->setInterval(interval: 900);
    }//end __construct()

    /**
     * Execute the background job.
     *
     * Fetches all active automation rules, evaluates each, and updates
     * matchCount and lastEvaluatedAt.
     *
     * @param mixed $argument Job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-6.3
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('AutomationRuleJob: starting evaluation cycle');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $rules = $objectService->getObjects(
                schema: 'AutomationRule',
                filters: ['isActive' => true],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'AutomationRuleJob: failed to fetch rules: {msg}',
                ['msg' => $e->getMessage()]
            );
            return;
        }//end try

        $now = (new \DateTimeImmutable())->format('c');

        foreach ($rules as $rule) {
            try {
                $matches    = $this->evaluator->evaluate($rule);
                $matchCount = (int) ($rule['matchCount'] ?? 0) + count($matches);

                $objectService->updateObject(
                    id: $rule['id'],
                    data: [
                        'matchCount'      => $matchCount,
                        'lastEvaluatedAt' => $now,
                    ],
                );

                $this->logger->info(
                    'AutomationRuleJob: rule "{name}" matched {count} objects',
                    ['name' => $rule['name'], 'count' => count($matches)]
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'AutomationRuleJob: error evaluating rule "{name}": {msg}',
                    ['name' => ($rule['name'] ?? 'unknown'), 'msg' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        $this->logger->info('AutomationRuleJob: evaluation cycle complete');
    }//end run()
}//end class
