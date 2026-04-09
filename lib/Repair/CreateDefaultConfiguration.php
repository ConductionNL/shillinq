<?php

/**
 * Shillinq Default Configuration Repair Step
 *
 * Seeds default data for Organization, AppSettings, Dashboard, and DataJob schemas.
 *
 * @spec openspec/changes/core/tasks.md#task-2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Seeds default objects for all Shillinq schemas on first install.
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
        return 'Seed Shillinq default configuration data';
    }//end getName()

    /**
     * Run the repair step to seed default objects.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @spec openspec/changes/core/tasks.md#task-2
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding Shillinq default configuration...');

        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ConfigurationService'
            );
        } catch (\Throwable $e) {
            $output->warning(
                'OpenRegister ObjectService not available, skipping seed data.'
            );
            $this->logger->warning(
                'Shillinq: cannot seed data — OpenRegister not available',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $this->seedOrganizations(output: $output, objectService: $objectService);
        $this->seedAppSettings(output: $output, objectService: $objectService);
        $this->seedDashboards(output: $output, objectService: $objectService);
        $this->seedDataJobs(output: $output, objectService: $objectService);

        $output->info('Shillinq seed data complete.');
    }//end run()

    /**
     * Seed Organization objects.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister ObjectService
     *
     * @spec openspec/changes/core/tasks.md#task-2.1
     *
     * @return void
     */
    private function seedOrganizations(IOutput $output, object $objectService): void
    {
        $seeds = [
            [
                'name'               => 'Acme BV',
                'registrationNumber' => '12345678',
                'email'              => 'info@acme.nl',
                'city'               => 'Amsterdam',
                'country'            => 'NL',
            ],
            [
                'name'               => 'Beta Corp',
                'registrationNumber' => '87654321',
                'email'              => 'info@betacorp.nl',
                'city'               => 'Rotterdam',
                'country'            => 'NL',
            ],
        ];

        foreach ($seeds as $seed) {
            $this->seedObject(
                objectService: $objectService,
                output: $output,
                schema: 'organization',
                uniqueField: 'name',
                uniqueValue: $seed['name'],
                data: $seed
            );
        }
    }//end seedOrganizations()

    /**
     * Seed AppSettings objects.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister ObjectService
     *
     * @spec openspec/changes/core/tasks.md#task-2.2
     *
     * @return void
     */
    private function seedAppSettings(IOutput $output, object $objectService): void
    {
        $seeds = [
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

        foreach ($seeds as $seed) {
            $this->seedObject(
                objectService: $objectService,
                output: $output,
                schema: 'appSettings',
                uniqueField: 'key',
                uniqueValue: $seed['key'],
                data: $seed
            );
        }
    }//end seedAppSettings()

    /**
     * Seed Dashboard objects.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister ObjectService
     *
     * @spec openspec/changes/core/tasks.md#task-2.3
     *
     * @return void
     */
    private function seedDashboards(IOutput $output, object $objectService): void
    {
        $this->seedObject(
            objectService: $objectService,
            output: $output,
            schema: 'dashboard',
            uniqueField: 'title',
            uniqueValue: 'Default Dashboard',
            data: [
                'title'      => 'Default Dashboard',
                'layoutType' => 'grid',
                'isDefault'  => true,
            ]
        );
    }//end seedDashboards()

    /**
     * Seed DataJob objects.
     *
     * @param IOutput $output        The output interface
     * @param object  $objectService The OpenRegister ObjectService
     *
     * @spec openspec/changes/core/tasks.md#task-2.3
     *
     * @return void
     */
    private function seedDataJobs(IOutput $output, object $objectService): void
    {
        $this->seedObject(
            objectService: $objectService,
            output: $output,
            schema: 'dataJob',
            uniqueField: 'fileName',
            uniqueValue: 'demo-import.csv',
            data: [
                'fileName'         => 'demo-import.csv',
                'entityType'       => 'organization',
                'status'           => 'completed',
                'totalRecords'     => 5,
                'processedRecords' => 5,
                'failedRecords'    => 0,
                'errorLog'         => '',
            ]
        );
    }//end seedDataJobs()

    /**
     * Seed a single object if it does not already exist.
     *
     * @param object  $objectService The OpenRegister ObjectService
     * @param IOutput $output        The output interface
     * @param string  $schema        The schema slug
     * @param string  $uniqueField   Field used for idempotency check
     * @param string  $uniqueValue   Value to check for existence
     * @param array   $data          The object data to create
     *
     * @return void
     */
    private function seedObject(
        object $objectService,
        IOutput $output,
        string $schema,
        string $uniqueField,
        string $uniqueValue,
        array $data,
    ): void {
        try {
            $existing = $objectService->searchObjects(
                schema: $schema,
                register: 'shillinq',
                filters: [$uniqueField => $uniqueValue],
                limit: 1
            );

            if (empty($existing) === false) {
                $output->info(
                    'Shillinq: '.$schema.' "'.$uniqueValue.'" already exists, skipping.'
                );
                return;
            }

            $objectService->createObject(
                schema: $schema,
                register: 'shillinq',
                data: $data
            );
            $output->info(
                'Shillinq: seeded '.$schema.' "'.$uniqueValue.'".'
            );
        } catch (\Throwable $e) {
            $output->warning(
                'Shillinq: failed to seed '.$schema.' "'.$uniqueValue.'": '.$e->getMessage()
            );
            $this->logger->error(
                'Shillinq: seed failed for '.$schema,
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end seedObject()
}//end class
