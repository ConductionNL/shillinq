<?php

/**
 * Shillinq Automation Rule Evaluator
 *
 * Evaluates a single AutomationRule against a set of OpenRegister objects
 * and executes the configured action on matches.
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

use DateTime;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Evaluates automation rules against OpenRegister objects.
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
     * Constructor for AutomationRuleEvaluator.
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
     * Evaluate a rule against all objects of its trigger schema.
     *
     * Returns the list of matching objects and executes the configured action.
     *
     * @param array $rule The AutomationRule object data.
     *
     * @return array List of matching objects.
     *
     * @spec openspec/changes/general/tasks.md#task-6.4
     */
    public function evaluate(array $rule): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $objects = $objectService->getObjects(
            schema: $rule['triggerSchema'],
            filters: [],
        );

        $matches = [];
        foreach ($objects as $object) {
            if ($this->matchesCondition(object: $object, rule: $rule) === true) {
                $matches[] = $object;
            }
        }

        // Execute action for each match.
        foreach ($matches as $match) {
            $this->executeAction(rule: $rule, object: $match);
        }

        return $matches;
    }//end evaluate()

    /**
     * Check if a single object matches the rule condition.
     *
     * @param array $object The OpenRegister object.
     * @param array $rule   The AutomationRule data.
     *
     * @return bool True if the object matches the condition.
     *
     * @spec openspec/changes/general/tasks.md#task-6.4
     */
    public function matchesCondition(array $object, array $rule): bool
    {
        $field    = $rule['triggerField'];
        $operator = $rule['triggerOperator'];
        $value    = (float) $rule['triggerValue'];

        if (isset($object[$field]) === false) {
            return false;
        }

        $objectValue = (float) $object[$field];

        if (in_array($operator, self::OPERATORS, true) === false) {
            $this->logger->warning('Unknown operator: {op}', ['op' => $operator]);
            return false;
        }

        switch ($operator) {
            case 'gt':
                return $objectValue > $value;
            case 'lt':
                return $objectValue < $value;
            case 'eq':
                return abs($objectValue - $value) < PHP_FLOAT_EPSILON;
            case 'gte':
                return $objectValue >= $value;
            case 'lte':
                return $objectValue <= $value;
            default:
                $this->logger->warning('Unknown operator: {op}', ['op' => $operator]);
                return false;
        }//end switch
    }//end matchesCondition()

    /**
     * Preview which objects would match a rule without executing actions.
     *
     * @param array $rule The AutomationRule object data.
     *
     * @return array List of matching objects.
     *
     * @spec openspec/changes/general/tasks.md#task-6.4
     */
    public function preview(array $rule): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $objects = $objectService->getObjects(
            schema: $rule['triggerSchema'],
            filters: [],
        );

        $matches = [];
        foreach ($objects as $object) {
            if ($this->matchesCondition(object: $object, rule: $rule) === true) {
                $matches[] = $object;
            }
        }

        return $matches;
    }//end preview()

    /**
     * Execute the configured action for a matched object.
     *
     * @param array $rule   The AutomationRule data.
     * @param array $object The matched object.
     *
     * @return void
     */
    private function executeAction(array $rule, array $object): void
    {
        $actionParams = [];
        if (empty($rule['actionParams']) === false) {
            $actionParams = json_decode($rule['actionParams'], true) ?? [];
        }

        switch ($rule['actionType']) {
            case 'send_notification':
                $this->executeSendNotification(rule: $rule, object: $object, actionParams: $actionParams);
                break;
            case 'change_status':
                $this->executeChangeStatus(object: $object, actionParams: $actionParams);
                break;
            case 'escalate':
                $this->executeEscalate(rule: $rule, object: $object, actionParams: $actionParams);
                break;
            default:
                $this->logger->warning(
                    'Unknown action type: {type}',
                    ['type' => $rule['actionType']]
                );
                break;
        }//end switch
    }//end executeAction()

    /**
     * Send a notification for a matched object.
     *
     * @param array $rule         The rule data.
     * @param array $object       The matched object.
     * @param array $actionParams The action parameters.
     *
     * @return void
     */
    private function executeSendNotification(array $rule, array $object, array $actionParams): void
    {
        try {
            $notificationManager = $this->container->get('OCP\Notification\IManager');
            $notification        = $notificationManager->createNotification();

            $subject = ($actionParams['subject'] ?? 'Automation Rule: '.$rule['name']);
            $notification->setApp('shillinq')
                ->setUser($object['userId'] ?? 'admin')
                ->setDateTime(new DateTime())
                ->setObject('automation_rule', (string) $rule['id'])
                ->setSubject('automation_match', ['subject' => $subject, 'objectId' => $object['id'] ?? '']);

            $notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to send notification for rule {name}: {msg}',
                ['name' => $rule['name'], 'msg' => $e->getMessage()]
            );
        }//end try
    }//end executeSendNotification()

    /**
     * Change the status of a matched object.
     *
     * @param array $object       The matched object.
     * @param array $actionParams The action parameters (must include newStatus).
     *
     * @return void
     */
    private function executeChangeStatus(array $object, array $actionParams): void
    {
        if (empty($actionParams['newStatus']) === true) {
            $this->logger->warning('change_status action missing newStatus parameter');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->updateObject(
                id: $object['id'],
                data: ['status' => $actionParams['newStatus']],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to change status for object {id}: {msg}',
                ['id' => ($object['id'] ?? 'unknown'), 'msg' => $e->getMessage()]
            );
        }//end try
    }//end executeChangeStatus()

    /**
     * Escalate a matched object by creating an escalation record and notifying the CFO.
     *
     * @param array $rule         The rule data.
     * @param array $object       The matched object.
     * @param array $actionParams The action parameters.
     *
     * @return void
     */
    private function executeEscalate(array $rule, array $object, array $actionParams): void
    {
        try {
            $notificationManager = $this->container->get('OCP\Notification\IManager');
            $notification        = $notificationManager->createNotification();

            $notification->setApp('shillinq')
                ->setUser($actionParams['cfoUserId'] ?? 'admin')
                ->setDateTime(new DateTime())
                ->setObject('escalation', (string) ($object['id'] ?? ''))
                ->setSubject(
                    'escalation',
                    ['rule' => $rule['name'], 'objectId' => $object['id'] ?? '']
                );

            $notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to escalate for rule {name}: {msg}',
                ['name' => $rule['name'], 'msg' => $e->getMessage()]
            );
        }//end try
    }//end executeEscalate()
}//end class
