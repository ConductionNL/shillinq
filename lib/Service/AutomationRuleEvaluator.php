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

use OCP\IGroupManager;
use OCP\IUserManager;
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
     * Schemas allowed as triggerSchema to prevent cross-schema data access.
     *
     * @var array<string>
     */
    private const ALLOWED_TRIGGER_SCHEMAS = ['Invoice', 'Payment', 'ExpenseClaim', 'Debtor'];

    /**
     * Allowed status values for change_status action.
     *
     * @var array<string>
     */
    private const ALLOWED_STATUSES = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'paid', 'overdue', 'open', 'closed'];

    /**
     * Constructor for AutomationRuleEvaluator.
     *
     * @param ContainerInterface $container    The service container
     * @param LoggerInterface    $logger       The logger
     * @param IGroupManager      $groupManager Nextcloud group manager for admin lookup
     * @param IUserManager       $userManager  Nextcloud user manager for userId validation
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
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
        // Validate triggerSchema against allowlist (OWASP A01 / injection guard).
        if (in_array($rule['triggerSchema'], self::ALLOWED_TRIGGER_SCHEMAS, true) === false) {
            $this->logger->warning(
                'Rejected unknown triggerSchema: {schema}',
                ['schema' => ($rule['triggerSchema'] ?? '')]
            );
            return ['matches' => [], 'matchCount' => 0];
        }

        // Validate triggerOperator against allowlist.
        if (in_array($rule['triggerOperator'], self::OPERATORS, true) === false) {
            $this->logger->warning(
                'Rejected invalid triggerOperator: {op}',
                ['op' => ($rule['triggerOperator'] ?? '')]
            );
            return ['matches' => [], 'matchCount' => 0];
        }

        // Validate actionType against allowlist.
        if (in_array($rule['actionType'], self::ACTION_TYPES, true) === false) {
            $this->logger->warning(
                'Unknown action type: {type}',
                ['type' => ($rule['actionType'] ?? '')]
            );
            return ['matches' => [], 'matchCount' => 0];
        }

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

        // For `eq`, prefer string comparison when either value is non-numeric
        // to avoid 'open' == 'paid' via float(0.0) === float(0.0).
        if ($rule['triggerOperator'] === 'eq') {
            if (is_numeric($fieldValue) === true && is_numeric($triggerValue) === true) {
                return abs((float) $fieldValue - (float) $triggerValue) < PHP_FLOAT_EPSILON;
            }

            return (string) $fieldValue === (string) $triggerValue;
        }

        $fieldNumeric   = (float) $fieldValue;
        $triggerNumeric = (float) $triggerValue;

        return match ($rule['triggerOperator']) {
            'gt'  => $fieldNumeric > $triggerNumeric,
            'lt'  => $fieldNumeric < $triggerNumeric,
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
            default             => null,
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
                $userId = ($object['userId'] ?? null);

                // Validate that the userId corresponds to an existing Nextcloud account
                // before sending to prevent notifications to arbitrary user IDs.
                if (empty($userId) === true || $this->userManager->userExists($userId) === false) {
                    $this->logger->warning(
                        'Skipping notification for rule {name}: userId {uid} does not exist',
                        [
                            'name' => $rule['name'],
                            'uid'  => ($userId ?? ''),
                        ]
                    );
                    continue;
                }

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
                    ->setDateTime(new \DateTime())
                    ->setUser($userId);

                $notificationManager->notify($notification);
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

        // Validate newStatus against allowed values to prevent injection.
        if (in_array($newStatus, self::ALLOWED_STATUSES, true) === false) {
            $this->logger->warning(
                'change_status action has invalid newStatus {status} for rule {name}',
                [
                    'status' => $newStatus,
                    'name'   => $rule['name'],
                ]
            );
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
     * Escalate matching objects by notifying the configured escalation recipient(s).
     *
     * Reads escalateTo from actionParams first; falls back to all members of
     * the Nextcloud 'admin' group (per ADR-005: admin identity via IGroupManager,
     * never a hardcoded username string).
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
        // Resolve recipient(s): prefer explicit escalateTo, fall back to admin group.
        $recipients = [];

        if (empty($actionParams['escalateTo']) === false) {
            $userId = $actionParams['escalateTo'];
            if ($this->userManager->userExists($userId) === true) {
                $recipients[] = $userId;
            } else {
                $this->logger->warning(
                    'escalate action: escalateTo user {uid} does not exist for rule {name}',
                    [
                        'uid'  => $userId,
                        'name' => $rule['name'],
                    ]
                );
            }
        }

        if (empty($recipients) === true) {
            // Fall back to all members of the admin group (ADR-005 compliant).
            $adminGroup = $this->groupManager->get('admin');
            if ($adminGroup !== null) {
                foreach ($adminGroup->getUsers() as $adminUser) {
                    $recipients[] = $adminUser->getUID();
                }
            }
        }

        if (empty($recipients) === true) {
            $this->logger->warning(
                'escalate action: no valid recipient found for rule {name}; notification not sent',
                ['name' => $rule['name']]
            );
            return;
        }

        try {
            $notificationManager = $this->container->get('OCP\Notification\IManager');

            foreach ($matches as $object) {
                foreach ($recipients as $recipientUid) {
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
                        ->setDateTime(new \DateTime())
                        ->setUser($recipientUid);

                    $notificationManager->notify($notification);
                }//end foreach
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
