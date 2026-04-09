<?php

/**
 * Shillinq Create Default Configuration Repair Step
 *
 * Seeds default roles, teams, and sample audit log entries on install/upgrade.
 *
 * @category  Repair
 * @package   OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\AppInfo\Application;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds default access control configuration objects.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-2
 */
class CreateDefaultConfiguration implements IRepairStep
{

    /**
     * The ObjectService instance from OpenRegister (resolved at runtime).
     *
     * @var object|null
     */
    private ?object $objectService = null;


    /**
     * Constructor for CreateDefaultConfiguration.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger interface
     *
     * @return void
     */
    public function __construct(
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
        return 'Seed default access control configuration for Shillinq';

    }//end getName()


    /**
     * Run the repair step to seed default objects.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding default access control configuration...');

        try {
            $this->objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (\Throwable $e) {
            $output->warning('OpenRegister ObjectService not available, skipping seed data: '.$e->getMessage());
            $this->logger->warning('Shillinq: could not resolve ObjectService for seeding', ['exception' => $e->getMessage()]);
            return;
        }

        $this->seedRoles($output);
        $this->seedTeams($output);
        $this->seedAccessControlEvents($output);

        $output->info('Default access control configuration seeded successfully.');

    }//end run()


    /**
     * Seed the five built-in roles.
     *
     * @param IOutput $output The output interface
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-2
     */
    private function seedRoles(IOutput $output): void
    {
        $roles = [
            [
                'name'        => 'Admin',
                'level'       => 100,
                'description' => 'Full system access',
            ],
            [
                'name'        => 'Editor',
                'level'       => 80,
                'description' => 'Create and edit all entities',
            ],
            [
                'name'        => 'Accountant',
                'level'       => 60,
                'description' => 'Financial data read/write',
            ],
            [
                'name'        => 'Viewer',
                'level'       => 40,
                'description' => 'Read-only access to all entities',
            ],
            [
                'name'        => 'Reports-only',
                'level'       => 20,
                'description' => 'Export and view reports only',
            ],
        ];

        foreach ($roles as $role) {
            $this->seedObject('role', 'name', $role['name'], array_merge($role, ['isActive' => true]), $output);
        }

    }//end seedRoles()


    /**
     * Seed the default administrator team.
     *
     * @param IOutput $output The output interface
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-2
     */
    private function seedTeams(IOutput $output): void
    {
        $this->seedObject(
            'team',
            'name',
            'Administrators',
            [
                'name'        => 'Administrators',
                'description' => 'System administrators with full access',
                'createdAt'   => '2026-01-01T00:00:00Z',
            ],
            $output
        );

    }//end seedTeams()


    /**
     * Seed sample AccessControl audit log entries.
     *
     * @param IOutput $output The output interface
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-2
     */
    private function seedAccessControlEvents(IOutput $output): void
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
                'resourceType' => 'invoice',
                'resourceId'   => 'seed-denied-001',
                'timestamp'    => '2026-01-01T09:10:00Z',
                'result'       => 'denied',
                'ipAddress'    => '127.0.0.1',
            ],
        ];

        foreach ($events as $event) {
            $this->seedObject('accessControl', 'resourceId', $event['resourceId'], $event, $output);
        }

    }//end seedAccessControlEvents()


    /**
     * Create or update a seed object idempotently.
     *
     * @param string  $schemaName The schema slug
     * @param string  $uniqueKey  The property used for idempotency
     * @param string  $uniqueVal  The value of the unique key
     * @param array   $data       The object data
     * @param IOutput $output     The output interface
     *
     * @return void
     */
    private function seedObject(string $schemaName, string $uniqueKey, string $uniqueVal, array $data, IOutput $output): void
    {
        try {
            $existing = $this->objectService->findObjects(
                filters: [$uniqueKey => $uniqueVal],
                register: Application::APP_ID,
                schema: $schemaName,
            );

            if (empty($existing) === false) {
                $output->info("Seed object {$schemaName}/{$uniqueVal} already exists, skipping.");
                return;
            }

            $this->objectService->saveObject(
                register: Application::APP_ID,
                schema: $schemaName,
                object: $data,
            );

            $output->info("Seeded {$schemaName}/{$uniqueVal}.");
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Shillinq: failed to seed {$schemaName}/{$uniqueVal}",
                ['exception' => $e->getMessage()]
            );
            $output->warning("Could not seed {$schemaName}/{$uniqueVal}: ".$e->getMessage());
        }//end try

    }//end seedObject()


}//end class
