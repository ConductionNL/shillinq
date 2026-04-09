<?php

/**
 * Shillinq Automation Rule Background Job
 *
 * Timed job that evaluates active automation rules every 15 minutes.
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
 * Background job that evaluates all active AutomationRule objects on schedule.
 *
 * @spec openspec/changes/general/tasks.md#task-6.3
 */
class AutomationRuleJob extends TimedJob
{

    /**
     * Interval in seconds (15 minutes).
     *
     * @var int
     */
    private const INTERVAL = 900;

    /**
     * Constructor for AutomationRuleJob.
     *
     * @param ITimeFactory            $time       The time factory
     * @param AutomationRuleEvaluator $evaluator  The rule evaluator
     * @param ContainerInterface      $container  The service container
     * @param LoggerInterface         $logger     The logger
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private AutomationRuleEvaluator $evaluator,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL);
    }//end __construct()

    /**
     * Run the automation rule evaluation.
     *
     * Fetches all active AutomationRule objects, evaluates each, updates
     * matchCount and lastEvaluatedAt after evaluation.
     *
     * @param mixed $argument Job argument (unused)
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
                register: 'shillinq',
                schema: 'AutomationRule',
                filters: ['isActive' => true],
            );

            $now = (new \DateTimeImmutable())->format('c');

            foreach ($rules as $rule) {
                try {
                    $result     = $this->evaluator->evaluate($rule);
                    $matchCount = (int) ($rule['matchCount'] ?? 0);

                    $objectService->updateObject(
                        register: 'shillinq',
                        schema: 'AutomationRule',
                        id: $rule['id'],
                        object: [
                            'matchCount'      => ($matchCount + $result['matchCount']),
                            'lastEvaluatedAt' => $now,
                        ],
                    );

                    $this->logger->info(
                        'AutomationRuleJob: rule "{name}" matched {count} objects',
                        [
                            'name'  => $rule['name'],
                            'count' => $result['matchCount'],
                        ]
                    );
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'AutomationRuleJob: failed to evaluate rule "{name}": {message}',
                        [
                            'name'    => ($rule['name'] ?? 'unknown'),
                            'message' => $e->getMessage(),
                        ]
                    );
                }//end try
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                'AutomationRuleJob: failed to fetch rules: {message}',
                ['message' => $e->getMessage()]
            );
        }//end try
    }//end run()
}//end class
