<?php

/**
 * Shillinq Create Default Configuration Repair Step
 *
 * Repair step that seeds default collaboration data on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/collaboration/tasks.md#task-2.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds default Comment and CollaborationRole objects.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-2.1
 */
class CreateDefaultConfiguration implements IRepairStep
{

    /**
     * Constructor for CreateDefaultConfiguration.
     *
     * @param SettingsService    $settingsService The settings service
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/collaboration/tasks.md#task-2.1
     */
    public function getName(): string
    {
        return 'Seed default collaboration data for Shillinq';
    }//end getName()

    /**
     * Run the repair step to seed default collaboration objects.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-2.1
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding default collaboration data...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning(
                'OpenRegister is not installed. Skipping collaboration seed data.'
            );
            return;
        }

        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
        } catch (\Throwable $e) {
            $output->warning('Could not get ObjectService: '.$e->getMessage());
            return;
        }

        $this->seedComment(output: $output, objectService: $objectService);
        $this->seedCollaborationRole(output: $output, objectService: $objectService);

        $output->info('Collaboration seed data completed.');
    }//end run()

    /**
     * Seed a demo Comment object.
     *
     * Uses content + targetId for idempotency check.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister object service
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-2.1
     */
    private function seedComment(IOutput $output, object $objectService): void
    {
        try {
            $existing = $objectService->findObjects(
                filters: [
                    'content'  => 'Please review the line items before approval.',
                    'targetId' => 'demo-invoice-001',
                ],
                schemaName: 'Comment'
            );

            if (empty($existing) === false) {
                $output->info('Comment seed already exists, skipping.');
                return;
            }

            $objectService->saveObject(
                schemaName: 'Comment',
                object: [
                    'content'    => 'Please review the line items before approval.',
                    'author'     => 'admin',
                    'targetType' => 'Invoice',
                    'targetId'   => 'demo-invoice-001',
                    'timestamp'  => '2026-01-15T09:00:00Z',
                    'mentions'   => [],
                    'resolved'   => false,
                ]
            );

            $output->info('Comment seed created successfully.');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to seed Comment: '.$e->getMessage()
            );
            $output->warning('Failed to seed Comment: '.$e->getMessage());
        }//end try
    }//end seedComment()

    /**
     * Seed a demo CollaborationRole object.
     *
     * Uses principalId + targetId + role for idempotency check.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister object service
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-2.2
     */
    private function seedCollaborationRole(
        IOutput $output,
        object $objectService,
    ): void {
        try {
            $existing = $objectService->findObjects(
                filters: [
                    'principalId' => 'admin',
                    'targetId'    => 'demo-invoice-001',
                    'role'        => 'approver',
                ],
                schemaName: 'CollaborationRole'
            );

            if (empty($existing) === false) {
                $output->info('CollaborationRole seed already exists, skipping.');
                return;
            }

            $objectService->saveObject(
                schemaName: 'CollaborationRole',
                object: [
                    'targetType'    => 'Invoice',
                    'targetId'      => 'demo-invoice-001',
                    'principalType' => 'user',
                    'principalId'   => 'admin',
                    'role'          => 'approver',
                    'grantedBy'     => 'admin',
                    'grantedAt'     => '2026-01-01T00:00:00Z',
                ]
            );

            $output->info('CollaborationRole seed created successfully.');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to seed CollaborationRole: '.$e->getMessage()
            );
            $output->warning(
                'Failed to seed CollaborationRole: '.$e->getMessage()
            );
        }//end try
    }//end seedCollaborationRole()
}//end class
