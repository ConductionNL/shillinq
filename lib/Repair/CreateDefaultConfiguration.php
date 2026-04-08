<?php

/**
 * Shillinq Create Default Configuration Repair Step
 *
 * Seeds initial data for all entity types on first install.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/core/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\AppInfo\Application;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds default objects for all Shillinq schemas.
 *
 * Creates demo organizations, app settings, a default dashboard, and a sample
 * data job on first install. Uses idempotency checks to prevent duplicate creation.
 *
 * @spec openspec/changes/core/tasks.md#task-2
 */
class CreateDefaultConfiguration implements IRepairStep
{
    /**
     * Constructor for CreateDefaultConfiguration.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger interface
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-2
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
     *
     * @spec openspec/changes/core/tasks.md#task-2
     */
    public function getName(): string
    {
        return 'Seed default Shillinq configuration data';
    }//end getName()

    /**
     * Run the repair step to seed default data.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-2
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding Shillinq default configuration...');

        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
        } catch (\Throwable $e) {
            $output->warning(
                'OpenRegister ObjectService not available, skipping seed data: '
                . $e->getMessage()
            );
            return;
        }

        $this->seedOrganizations(output: $output, objectService: $objectService);
        $this->seedAppSettings(output: $output, objectService: $objectService);
        $this->seedDashboards(output: $output, objectService: $objectService);
        $this->seedDataJobs(output: $output, objectService: $objectService);

        $output->info('Shillinq default configuration seeded successfully.');
    }//end run()

    /**
     * Seed Organization objects.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister object service
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-2
     */
    private function seedOrganizations(IOutput $output, object $objectService): void
    {
        $organizations = [
            [
                'name'               => 'Acme BV',
                'registrationNumber' => '87654321',
                'email'              => 'info@acme.nl',
                'city'               => 'Amsterdam',
                'country'            => 'NL',
            ],
            [
                'name'               => 'Beta Corp',
                'registrationNumber' => '12348765',
                'email'              => 'info@betacorp.nl',
                'city'               => 'Rotterdam',
                'country'            => 'NL',
            ],
        ];

        $this->seedObjects(
            output: $output,
            objectService: $objectService,
            schema: 'organization',
            uniqueField: 'name',
            objects: $organizations,
            label: 'Organization'
        );
    }//end seedOrganizations()

    /**
     * Seed AppSettings objects.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister object service
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-2
     */
    private function seedAppSettings(IOutput $output, object $objectService): void
    {
        $settings = [
            [
                'key'      => 'language',
                'value'    => 'en',
                'dataType' => 'string',
                'category' => 'general',
                'editable' => true,
            ],
            [
                'key'      => 'dateFormat',
                'value'    => 'DD-MM-YYYY',
                'dataType' => 'string',
                'category' => 'appearance',
                'editable' => true,
            ],
            [
                'key'      => 'notificationEmail',
                'value'    => 'true',
                'dataType' => 'boolean',
                'category' => 'notifications',
                'editable' => true,
            ],
            [
                'key'      => 'notificationInApp',
                'value'    => 'true',
                'dataType' => 'boolean',
                'category' => 'notifications',
                'editable' => true,
            ],
        ];

        $this->seedObjects(
            output: $output,
            objectService: $objectService,
            schema: 'appSettings',
            uniqueField: 'key',
            objects: $settings,
            label: 'AppSettings'
        );
    }//end seedAppSettings()

    /**
     * Seed Dashboard objects.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister object service
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-2
     */
    private function seedDashboards(IOutput $output, object $objectService): void
    {
        $dashboards = [
            [
                'title'      => 'Default Dashboard',
                'layoutType' => 'grid',
                'isDefault'  => true,
            ],
        ];

        $this->seedObjects(
            output: $output,
            objectService: $objectService,
            schema: 'dashboard',
            uniqueField: 'title',
            objects: $dashboards,
            label: 'Dashboard'
        );
    }//end seedDashboards()

    /**
     * Seed DataJob objects.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister object service
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-2
     */
    private function seedDataJobs(IOutput $output, object $objectService): void
    {
        $dataJobs = [
            [
                'fileName'         => 'demo-import.csv',
                'entityType'       => 'organization',
                'status'           => 'completed',
                'totalRecords'     => 5,
                'processedRecords' => 5,
                'failedRecords'    => 0,
                'errorLog'         => '',
            ],
        ];

        $this->seedObjects(
            output: $output,
            objectService: $objectService,
            schema: 'dataJob',
            uniqueField: 'fileName',
            objects: $dataJobs,
            label: 'DataJob'
        );
    }//end seedDataJobs()

    /**
     * Seed a list of objects for a given schema if none exist yet.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister object service
     * @param string  $schema        The schema slug
     * @param string  $uniqueField   The field to check for idempotency
     * @param array   $objects       The objects to seed
     * @param string  $label         Human-readable label for logging
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-2
     */
    private function seedObjects(
        IOutput $output,
        object $objectService,
        string $schema,
        string $uniqueField,
        array $objects,
        string $label,
    ): void {
        try {
            $existing = $objectService->getObjects(
                register: Application::APP_ID,
                schema: $schema
            );

            if (empty($existing) === false) {
                $output->info(
                    $label . ' objects already exist, skipping seed data.'
                );
                return;
            }

            foreach ($objects as $data) {
                $objectService->saveObject(
                    register: Application::APP_ID,
                    schema: $schema,
                    object: $data
                );
                $output->info(
                    'Seeded ' . $label . ': '
                    . ($data[$uniqueField] ?? 'unknown')
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Shillinq: failed to seed ' . $label . ' data',
                ['exception' => $e->getMessage()]
            );
            $output->warning(
                'Failed to seed ' . $label . ' data: '
                . $e->getMessage()
            );
        }//end try
    }//end seedObjects()
}//end class
