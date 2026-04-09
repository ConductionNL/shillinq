<?php

/**
 * Shillinq Automation Rule Evaluator
 *
 * Evaluates a single AutomationRule against a set of OpenRegister objects.
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
 * @spec openspec/changes/general/tasks.md#task-6.4
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Evaluates automation rules against OpenRegister objects and executes actions.
 *
 * @spec openspec/changes/general/tasks.md#task-6.4
 */
class AutomationRuleEvaluator
{

    /**
     * Supported comparison operators.
     *
     * @var array<string>
     */
    private const OPERATORS = ['gt', 'lt', 'eq', 'gte', 'lte'];

    /**
     * Supported action types.
     *
     * @var array<string>
     */
    private const ACTION_TYPES = ['send_notification', 'change_status', 'escalate'];

    /**
     * Constructor for AutomationRuleEvaluator.
     *
     * @param ContainerInterface $container The service container
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
     * Evaluate a rule against objects and execute actions on matches.
     *
     * @param array $rule   The AutomationRule object
     * @param bool  $dryRun If true, only return matches without executing actions
     *
     * @return array{matches: array, matchCount: int}
     *
     * @spec openspec/changes/general/tasks.md#task-6.4
     */
    public function evaluate(array $rule, bool $dryRun=false): array
    {
        $objectService = $this->getObjectService();

        $objects = $objectService->getObjects(
            register: 'shillinq',
            schema: $rule['triggerSchema'],
            filters: [],
        );

        $matches = [];
        foreach ($objects as $object) {
            if ($this->matchesCondition(object: $object, rule: $rule) === true) {
                $matches[] = $object;
            }
        }

        if ($dryRun === false && empty($matches) === false) {
            $this->executeAction(rule: $rule, matches: $matches);
        }

        return [
            'matches'    => $matches,
            'matchCount' => count($matches),
        ];
    }//end evaluate()

    /**
     * Check if an object matches the rule's trigger condition.
     *
     * @param array $object The object to check
     * @param array $rule   The automation rule
     *
     * @return bool Whether the object matches
     *
     * @spec openspec/changes/general/tasks.md#task-6.4
     */
    public function matchesCondition(array $object, array $rule): bool
    {
        $fieldValue   = $object[$rule['triggerField']] ?? null;
        $triggerValue = $rule['triggerValue'];

        if ($fieldValue === null) {
            return false;
        }

        $fieldNumeric   = (float) $fieldValue;
        $triggerNumeric = (float) $triggerValue;

        return match ($rule['triggerOperator']) {
            'gt'  => $fieldNumeric > $triggerNumeric,
            'lt'  => $fieldNumeric < $triggerNumeric,
            'eq'  => $fieldNumeric === $triggerNumeric,
            'gte' => $fieldNumeric >= $triggerNumeric,
            'lte' => $fieldNumeric <= $triggerNumeric,
            default => false,
        };
    }//end matchesCondition()

    /**
     * Execute the rule's action on matching objects.
     *
     * @param array $rule    The automation rule
     * @param array $matches Array of matching objects
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-6.4
     */
    private function executeAction(array $rule, array $matches): void
    {
        $actionParams = [];
        if (empty($rule['actionParams']) === false) {
            $actionParams = json_decode($rule['actionParams'], true) ?? [];
        }

        match ($rule['actionType']) {
            'send_notification' => $this->executeSendNotification(rule: $rule, matches: $matches, actionParams: $actionParams),
            'change_status'     => $this->executeChangeStatus(rule: $rule, matches: $matches, actionParams: $actionParams),
            'escalate'          => $this->executeEscalate(rule: $rule, matches: $matches, actionParams: $actionParams),
            default             => $this->logger->warning(
                'Unknown action type: {type}',
                ['type' => $rule['actionType']]
            ),
        };
    }//end executeAction()

    /**
     * Send notifications for matching objects.
     *
     * @param array $rule         The automation rule
     * @param array $matches      Matching objects
     * @param array $actionParams Action parameters
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-6.4
     */
    private function executeSendNotification(array $rule, array $matches, array $actionParams): void
    {
        try {
            $notificationManager = $this->container->get('OCP\Notification\IManager');

            foreach ($matches as $object) {
                $notification = $notificationManager->createNotification();
                $notification->setApp('shillinq')
                    ->setObject($rule['triggerSchema'], ($object['id'] ?? ''))
                    ->setSubject(
                        'automation_rule_match',
                        [
                            'ruleName' => $rule['name'],
                            'subject'  => ($actionParams['subject'] ?? 'Automation rule triggered'),
                        ]
                    )
                    ->setDateTime(new \DateTime());

                if (empty($object['userId']) === false) {
                    $notification->setUser($object['userId']);
                    $notificationManager->notify($notification);
                }
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to send notification for rule {name}: {message}',
                [
                    'name'    => $rule['name'],
                    'message' => $e->getMessage(),
                ]
            );
        }//end try
    }//end executeSendNotification()

    /**
     * Change status of matching objects.
     *
     * @param array $rule         The automation rule
     * @param array $matches      Matching objects
     * @param array $actionParams Action parameters
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-6.4
     */
    private function executeChangeStatus(array $rule, array $matches, array $actionParams): void
    {
        $newStatus = ($actionParams['newStatus'] ?? null);
        if ($newStatus === null) {
            $this->logger->warning('change_status action missing newStatus parameter for rule {name}', ['name' => $rule['name']]);
            return;
        }

        $objectService = $this->getObjectService();

        foreach ($matches as $object) {
            try {
                $objectService->updateObject(
                    register: 'shillinq',
                    schema: $rule['triggerSchema'],
                    id: $object['id'],
                    object: ['status' => $newStatus],
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to change status for object {id}: {message}',
                    [
                        'id'      => ($object['id'] ?? ''),
                        'message' => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach
    }//end executeChangeStatus()

    /**
     * Escalate matching objects by creating escalation records and notifying CFO.
     *
     * @param array $rule         The automation rule
     * @param array $matches      Matching objects
     * @param array $actionParams Action parameters
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-6.4
     */
    private function executeEscalate(array $rule, array $matches, array $actionParams): void
    {
        try {
            $notificationManager = $this->container->get('OCP\Notification\IManager');

            foreach ($matches as $object) {
                $notification = $notificationManager->createNotification();
                $notification->setApp('shillinq')
                    ->setObject('escalation', ($object['id'] ?? ''))
                    ->setSubject(
                        'escalation_created',
                        [
                            'ruleName'   => $rule['name'],
                            'objectId'   => ($object['id'] ?? ''),
                            'objectType' => $rule['triggerSchema'],
                        ]
                    )
                    ->setDateTime(new \DateTime());

                $notification->setUser('admin');
                $notificationManager->notify($notification);
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to escalate for rule {name}: {message}',
                [
                    'name'    => $rule['name'],
                    'message' => $e->getMessage(),
                ]
            );
        }//end try
    }//end executeEscalate()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return mixed The ObjectService instance
     */
    private function getObjectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
