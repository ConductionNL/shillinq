<?php

/**
 * Shillinq Create Default Configuration Repair Step
 *
 * Seeds demo objects for Comment and CollaborationRole schemas.
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
 * Repair step that seeds demo Comment and CollaborationRole objects.
 *
 * Idempotent: checks for existing objects before creating.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-2.1
 */
class CreateDefaultConfiguration implements IRepairStep
{

    /**
     * Constructor for CreateDefaultConfiguration.
     *
     * @param ContainerInterface $container       The DI container
     * @param SettingsService    $settingsService The settings service
     * @param LoggerInterface    $logger          The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private SettingsService $settingsService,
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
        return 'Create default collaboration seed data for Shillinq';
    }//end getName()

    /**
     * Run the repair step to seed demo objects.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-2.1
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding Shillinq collaboration demo data...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister is not available. Skipping seed data.');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $this->seedComment(objectService: $objectService, output: $output);
            $this->seedCollaborationRole(objectService: $objectService, output: $output);

            $output->info('Collaboration seed data created successfully.');
        } catch (\Throwable $e) {
            $output->warning('Could not seed collaboration data: '.$e->getMessage());
            $this->logger->error(
                'Shillinq seed data failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Seed a demo Comment object.
     *
     * Idempotency check uses content + targetId combination.
     *
     * @param object  $objectService The OpenRegister ObjectService
     * @param IOutput $output        The output interface
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-2.1
     */
    private function seedComment(object $objectService, IOutput $output): void
    {
        $existing = $objectService->findAll(
            schema: 'Comment',
            filters: [
                'content'  => 'Please review the line items before approval.',
                'targetId' => 'demo-invoice-001',
            ],
        );

        $records = ($existing['results'] ?? $existing ?? []);
        if (empty($records) === false) {
            $output->info('Comment seed already exists, skipping.');
            return;
        }

        $objectService->create(
            schema: 'Comment',
            data: [
                'content'    => 'Please review the line items before approval.',
                'author'     => 'admin',
                'targetType' => 'Invoice',
                'targetId'   => 'demo-invoice-001',
                'timestamp'  => '2026-01-15T09:00:00Z',
                'mentions'   => [],
                'resolved'   => false,
            ],
        );

        $output->info('Comment seed created.');
    }//end seedComment()

    /**
     * Seed a demo CollaborationRole object.
     *
     * Idempotency check uses principalId + targetId + role combination.
     *
     * @param object  $objectService The OpenRegister ObjectService
     * @param IOutput $output        The output interface
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-2.2
     */
    private function seedCollaborationRole(object $objectService, IOutput $output): void
    {
        $existing = $objectService->findAll(
            schema: 'CollaborationRole',
            filters: [
                'principalId' => 'admin',
                'targetId'    => 'demo-invoice-001',
                'role'        => 'approver',
            ],
        );

        $records = ($existing['results'] ?? $existing ?? []);
        if (empty($records) === false) {
            $output->info('CollaborationRole seed already exists, skipping.');
            return;
        }

        $objectService->create(
            schema: 'CollaborationRole',
            data: [
                'targetType'    => 'Invoice',
                'targetId'      => 'demo-invoice-001',
                'principalType' => 'user',
                'principalId'   => 'admin',
                'role'          => 'approver',
                'grantedBy'     => 'admin',
                'grantedAt'     => '2026-01-01T00:00:00Z',
            ],
        );

        $output->info('CollaborationRole seed created.');
    }//end seedCollaborationRole()
}//end class
