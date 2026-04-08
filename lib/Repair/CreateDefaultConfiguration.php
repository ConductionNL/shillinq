<?php

/**
 * Shillinq Create Default Configuration Repair Step
 *
 * Seeds the default roles, teams, and sample audit log entries on install.
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
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-2.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that creates default roles, teams, and sample data.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-2.1
 */
class CreateDefaultConfiguration implements IRepairStep
{

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService The settings service
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger
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
     */
    public function getName(): string
    {
        return 'Create Shillinq default roles, teams, and seed data';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output The output interface
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-2.1
     */
    public function run(IOutput $output): void
    {
        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister not available, skipping seed data.');
            return;
        }

        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            $this->seedRoles(objectService: $objectService, output: $output);
            $this->seedTeams(objectService: $objectService, output: $output);
            $this->seedAccessControlEvents(objectService: $objectService, output: $output);

            $output->info('Shillinq seed data created successfully.');
        } catch (\Throwable $e) {
            $output->warning('Could not create seed data: ' . $e->getMessage());
            $this->logger->error(
                'Shillinq seed data creation failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Seed the five built-in roles.
     *
     * @param object  $objectService The OpenRegister object service
     * @param IOutput $output        The output interface
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-2.1
     */
    private function seedRoles(object $objectService, IOutput $output): void
    {
        $roles = [
            ['name' => 'Admin',        'level' => 100, 'description' => 'Full system access'],
            ['name' => 'Editor',       'level' => 80,  'description' => 'Create and edit all entities'],
            ['name' => 'Accountant',   'level' => 60,  'description' => 'Financial data read/write'],
            ['name' => 'Viewer',       'level' => 40,  'description' => 'Read-only access to all entities'],
            ['name' => 'Reports-only', 'level' => 20,  'description' => 'Export and view reports only'],
        ];

        foreach ($roles as $role) {
            $this->seedObject(
                objectService: $objectService,
                schema: 'role',
                uniqueKey: 'name',
                uniqueValue: $role['name'],
                data: array_merge($role, ['isActive' => true]),
            );
        }

        $output->info('Seeded 5 built-in roles.');
    }//end seedRoles()

    /**
     * Seed the default administrator team.
     *
     * @param object  $objectService The OpenRegister object service
     * @param IOutput $output        The output interface
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-2.2
     */
    private function seedTeams(object $objectService, IOutput $output): void
    {
        $this->seedObject(
            objectService: $objectService,
            schema: 'team',
            uniqueKey: 'name',
            uniqueValue: 'Administrators',
            data: [
                'name'        => 'Administrators',
                'description' => 'System administrators with full access',
                'createdAt'   => '2026-01-01T00:00:00Z',
            ],
        );

        $output->info('Seeded default Administrators team.');
    }//end seedTeams()

    /**
     * Seed sample access control events for the audit log demo.
     *
     * @param object  $objectService The OpenRegister object service
     * @param IOutput $output        The output interface
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-2.2
     */
    private function seedAccessControlEvents(object $objectService, IOutput $output): void
    {
        $events = [
            [
                'action'       => 'login',
                'resourceType' => 'session',
                'resourceId'   => 'seed-login-001',
                'timestamp'    => '2026-01-01T09:00:00Z',
                'result'       => 'success',
                'ipAddress'    => '127.0.0.1',
            ],
            [
                'action'       => 'read',
                'resourceType' => 'invoice',
                'resourceId'   => 'seed-read-001',
                'timestamp'    => '2026-01-01T09:05:00Z',
                'result'       => 'success',
                'ipAddress'    => '127.0.0.1',
            ],
            [
                'action'       => 'permission-denied',
                'resourceType' => 'settings',
                'resourceId'   => 'seed-denied-001',
                'timestamp'    => '2026-01-01T09:10:00Z',
                'result'       => 'denied',
                'ipAddress'    => '192.168.1.100',
            ],
        ];

        foreach ($events as $event) {
            $this->seedObject(
                objectService: $objectService,
                schema: 'accessControl',
                uniqueKey: 'resourceId',
                uniqueValue: $event['resourceId'],
                data: $event,
            );
        }

        $output->info('Seeded 3 sample access control events.');
    }//end seedAccessControlEvents()

    /**
     * Idempotently seed an object: create only if no match on the unique key.
     *
     * @param object $objectService The OpenRegister object service
     * @param string $schema        The schema name
     * @param string $uniqueKey     The property to check for duplicates
     * @param string $uniqueValue   The expected value of the unique property
     * @param array  $data          The full object data
     *
     * @return void
     */
    private function seedObject(
        object $objectService,
        string $schema,
        string $uniqueKey,
        string $uniqueValue,
        array $data,
    ): void {
        $existing = $objectService->findObjects(
            register: Application::APP_ID,
            schema: $schema,
            filters: [$uniqueKey => $uniqueValue],
        );

        if (empty($existing) === true) {
            $objectService->saveObject(
                register: Application::APP_ID,
                schema: $schema,
                object: $data,
            );
        }
    }//end seedObject()
}//end class
