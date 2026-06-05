<?php

/**
 * Shillinq Initialize Settings Repair Step
 *
 * Repair step that initializes Shillinq register and schemas on install/upgrade.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Shillinq configuration via SettingsService.
 *
 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
 * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-15
 */
class InitializeSettings implements IRepairStep
{
    /**
     * Constructor for InitializeSettings.
     *
     * @param SettingsService    $settingsService The settings service
     * @param LoggerInterface    $logger          The logger interface
     * @param ContainerInterface $container       The DI container
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
        private ContainerInterface $container,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
     */
    public function getName(): string
    {
        return 'Initialize Shillinq register and schemas via ConfigurationService';
    }//end getName()

    /**
     * Run the repair step to initialize Shillinq configuration.
     *
     * Phase 1: imports the register schema from shillinq_register.json (includes
     * Iv3Export schema, lifecycle, aggregations, and iv3-xml-transformation mapping).
     * Phase 2: seeds the chart of accounts from the RGS template selected
     * in app config key 'rgs_template' (default: 'mkb'). Idempotent —
     * existing accounts are skipped on re-run, preserving operator edits.
     * Seeding is skipped entirely when administration_id is not configured
     * (C2: prevents "default" contamination of real tenant data).
     * Phase 3: seeds the Archiefwet Selectielijst Gemeenten 2020 retention rules.
     * Phase 4: registers the IV3 quarterly CBS ScheduledWorkflow if not yet present.
     * Phase 5: seeds KOR thresholds from kor-thresholds-2026.json idempotently.
     * Phase 6: seeds AllocationRule example records from seeds/allocation-rules/ idempotently.
     * Phase 7: seeds RJ-270 stages and rate-card templates for consultancy project accounting.
     * Phase 8: seeds ProductAttribute templates (office, it_hardware, logistics, food_beverage, clothing) per REQ-IPC-007.
     * Phase 9: seeds Location records (Amsterdam, Rotterdam, Utrecht) per REQ-IST-009.
     * Phase 10: seeds InventoryStock records for all three warehouse locations per REQ-IST-009.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
     * @spec openspec/changes/add-shillinq-iv3-reporting/tasks.md#task-10
     * @spec openspec/changes/add-shillinq-kor-kleine-ondernemersregeling/tasks.md#task-12
     * @spec openspec/changes/add-shillinq-cost-centers-dimensions/tasks.md#task-11
     * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-15
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-13
     * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
     */
    public function run(IOutput $output): void
    {
        $output->info('Initializing Shillinq configuration...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning(
            'OpenRegister is not installed or enabled. Skipping auto-configuration.'
            );
            $this->logger->warning(
                'Shillinq: OpenRegister not available, skipping register initialization'
            );
            return;
        }

        try {
            // C8: use loadConfigurationForced() so OR's per-register/per-schema
            // version_compare provides the real idempotency — no silent no-ops on
            // routine upgrades when the shillinq_register.json version hasn't changed.
            $result = $this->settingsService->loadConfigurationForced();

            if ($result['success'] === true) {
                $skipped = (($result['skipped'] ?? false) === true);
                $version = ($result['version'] ?? 'unknown');
                if ($skipped === true) {
                    $output->info('Shillinq configuration already up-to-date (version-unchanged skip)');
                }

                if ($skipped !== true) {
                    $output->info(
                        'Shillinq configuration imported successfully (version: '.$version.')'
                    );
                }
            }

            if ($result['success'] !== true) {
                $message = ($result['message'] ?? 'unknown error');
                $output->warning('Shillinq configuration import issue: '.$message);
                // H2: skip account seed when schema import failed to avoid writing
                // accounts into an uninitialized register.
                $output->warning('Shillinq: schema import failed, skipping account seed');
                return;
            }

            $this->seedChartOfAccounts(output: $output);
            $this->seedProjectData(output: $output);
            $this->seedSelectielijstRules(output: $output);
            $this->registerIv3ScheduledWorkflow(output: $output);
            $this->seedKorThresholds(output: $output);
            $this->seedComplianceReferenceData(output: $output);
            $this->seedProductAttributeTemplates(output: $output);
            $this->seedWarehouseLocations(output: $output);
            $this->seedInventoryStockLevels(output: $output);
        } catch (\Throwable $e) {
            $output->warning('Could not auto-configure Shillinq: '.$e->getMessage());
            $this->logger->error(
                'Shillinq initialization failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end run()

    /**
     * Seed project accounting data (RJ-270 stages and rate-card templates), idempotently.
     *
     * RJ-270 stages are seeded unconditionally (not tenant-specific).
     * Rate-card templates require a configured administrationId (C2).
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-15
     */
    private function seedProjectData(IOutput $output): void
    {
        $output->info('Seeding RJ-270 stages...');
        $rj270Result = $this->settingsService->seedRj270Stages();
        if ($rj270Result['success'] === true) {
            $output->info(
                'RJ-270 stages seeded: '.($rj270Result['seeded'] ?? 0).' created, '.($rj270Result['skipped'] ?? 0).' skipped.'
            );
        }

        if ($rj270Result['success'] !== true) {
            $output->warning('RJ-270 stages seeding issue: '.($rj270Result['message'] ?? 'unknown error'));
        }

        $settings         = $this->settingsService->getSettings();
        $administrationId = ($settings['administration_id'] ?? '');

        if ($administrationId === '') {
            $output->warning(
                'Shillinq: administration_id not configured — skipping rate-card template seed.'
            );
            return;
        }

        $output->info('Seeding rate-card templates...');
        $rcResult = $this->settingsService->seedRateCardTemplates(administrationId: $administrationId);
        if ($rcResult['success'] === true) {
            $output->info(
                'Rate-card templates seeded: '.($rcResult['seeded'] ?? 0).' created, '.($rcResult['skipped'] ?? 0).' skipped.'
            );
        }

        if ($rcResult['success'] !== true) {
            $output->warning('Rate-card templates seeding issue: '.($rcResult['message'] ?? 'unknown error'));
        }

    }//end seedProjectData()

    /**
     * Import the Selectielijst Gemeenten 2020 retention rules, idempotently.
     *
     * Calls SettingsService::seedSelectielijst() which skips already-existing
     * seeded rules and preserves operator-authored overrides per REQ-ARC-002.
     * Safe to call on every install/upgrade — the seed is idempotent.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
     */
    private function seedSelectielijstRules(IOutput $output): void
    {
        $output->info('Seeding Archiefwet Selectielijst Gemeenten 2020 retention rules...');

        $result = $this->settingsService->seedSelectielijst();

        if ($result['success'] === true) {
            $seeded  = ($result['seeded'] ?? 0);
            $skipped = ($result['skipped'] ?? 0);
            $output->info(
                'Selectielijst retention rules seeded: '.$seeded.' created, '.$skipped.' skipped (already exist).'
            );
        }

        if ($result['success'] !== true) {
            $message = ($result['message'] ?? 'unknown error');
            $output->warning('Selectielijst seeding issue: '.$message);
        }

    }//end seedSelectielijstRules()

    /**
     * Register the IV3 quarterly CBS ScheduledWorkflow if not already present.
     *
     * Idempotent: uses the slug 'shillinq-iv3-quarterly-cbs-submission' for deduplication.
     * The interval defaults to 7776000 seconds (90 days / quarterly). Operators adjust
     * via the OpenRegister ScheduledWorkflow admin UI. Per ADR-031 §"Background jobs that
     * orchestrate external systems" + REQ-IV3-006.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-iv3-reporting/tasks.md#task-9
     */
    private function registerIv3ScheduledWorkflow(IOutput $output): void
    {
        try {
            $workflowMapper = $this->container->get(
                'OCA\OpenRegister\Db\ScheduledWorkflowMapper'
            );
        } catch (\Throwable $e) {
            $output->info('Shillinq: ScheduledWorkflowMapper not available, skipping IV3 workflow registration');
            return;
        }

        $slug = 'shillinq-iv3-quarterly-cbs-submission';

        $existing = $workflowMapper->findAll();
        foreach ($existing as $workflow) {
            if ($workflow->getName() === $slug) {
                $output->info('Shillinq: IV3 quarterly CBS ScheduledWorkflow already registered, skipping');
                return;
            }
        }

        // 90 days in seconds — quarterly cadence aligned with cbs-iv3 cron 0 0 1 */3 *.
        // Operators reconfigure the interval and target via the OpenRegister admin UI
        // if CBS deadlines shift. REQ-IV3-006 / ADR-019.
        $workflowMapper->createFromArray(
            data: [
                'name'        => $slug,
                'engine'      => 'openconnector',
                'workflowId'  => 'cbs-iv3',
                'intervalSec' => 7776000,
                'enabled'     => true,
                'payload'     => json_encode(
                    [
                        'register'           => 'shillinq',
                        'schema'             => 'Iv3Export',
                        'administrationType' => ['gemeente', 'provincie', 'waterschap'],
                    ]
                ),
            ]
        );

        $output->info('Shillinq: IV3 quarterly CBS ScheduledWorkflow registered (interval: 90 days)');

    }//end registerIv3ScheduledWorkflow()

    /**
     * Seed T3 NL-compliance reference data (BTW tariffs + BBV taakvelden), idempotently.
     *
     * Both seeds are statutory reference catalogues that are not tenant-specific,
     * so they are seeded unconditionally. Deduplication is handled inside the
     * SettingsService seed helpers (by code), keeping re-runs safe.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-operations/tasks.md#task-311
     */
    private function seedComplianceReferenceData(IOutput $output): void
    {
        $output->info('Seeding BTW tariffs...');
        $btwResult = $this->settingsService->seedBtwTariffs();
        if ($btwResult['success'] === true) {
            $output->info(
                'BTW tariffs seeded: '.($btwResult['seeded'] ?? 0).' created, '.($btwResult['skipped'] ?? 0).' skipped.'
            );
        }

        if ($btwResult['success'] !== true) {
            $output->warning('BTW tariffs seeding issue: '.($btwResult['message'] ?? 'unknown error'));
        }

        $output->info('Seeding BBV taakvelden...');
        $bbvResult = $this->settingsService->seedBbvTaakvelden();
        if ($bbvResult['success'] === true) {
            $output->info(
                'BBV taakvelden seeded: '.($bbvResult['seeded'] ?? 0).' created, '.($bbvResult['skipped'] ?? 0).' skipped.'
            );
        }

        if ($bbvResult['success'] !== true) {
            $output->warning('BBV taakvelden seeding issue: '.($bbvResult['message'] ?? 'unknown error'));
        }

    }//end seedComplianceReferenceData()

    /**
     * Seed the KOR thresholds from kor-thresholds-2026.json, idempotently.
     *
     * Deduplication key is fiscalYear: if a KorThreshold record with the same
     * fiscalYear already exists in OpenRegister, the seed entry is skipped.
     * This means re-running the repair step is safe and preserves operator edits.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-kor-kleine-ondernemersregeling/tasks.md#task-12
     */
    private function seedKorThresholds(IOutput $output): void
    {
        $seedPath = __DIR__.'/../Settings/seeds/kor-thresholds-2026.json';
        if (file_exists($seedPath) === false) {
            $output->warning('Shillinq: KOR threshold seed file not found, skipping');
            return;
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            $output->warning('Shillinq: failed to read KOR threshold seed file, skipping');
            return;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $output->warning('Shillinq: failed to parse KOR threshold seed file: '.json_last_error_msg());
            return;
        }

        $thresholds = ($data['thresholds'] ?? []);
        if (empty($thresholds) === true) {
            $output->info('Shillinq: KOR threshold seed file contains no thresholds, skipping');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settingsService->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($thresholds as $threshold) {
                $fiscalYear = ($threshold['fiscalYear'] ?? null);
                if ($fiscalYear === null) {
                    continue;
                }

                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('KorThreshold')
                    ->findAll(
                            [
                                'filters' => ['fiscalYear' => $fiscalYear],
                                'limit'   => 1,
                            ]
                            );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $objectService->saveObject(
                    object: $threshold,
                    register: $registerSlug,
                    schema: 'KorThreshold',
                );
                $seeded++;
            }//end foreach

            $output->info(
                'Shillinq: KOR thresholds seeded: '.$seeded.' created, '.$skipped.' skipped (already exist).'
            );
        } catch (\Throwable $e) {
            $output->warning('Shillinq: KOR threshold seeding failed: '.$e->getMessage());
            $this->logger->warning(
                'Shillinq: KOR threshold seeding failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end seedKorThresholds()

    /**
     * Seed ProductAttribute templates for all five standard categories, idempotently.
     *
     * Calls SettingsService::seedProductAttributes() per category. Idempotent:
     * attributes matched by name + applicableToCategories are skipped, preserving
     * operator edits per REQ-IPC-007.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-13
     */
    private function seedProductAttributeTemplates(IOutput $output): void
    {
        $categories = ['office', 'it_hardware', 'logistics', 'food_beverage', 'clothing'];

        foreach ($categories as $category) {
            $output->info('Seeding ProductAttribute template: '.$category.'...');
            $result = $this->settingsService->seedProductAttributes(category: $category);

            if ($result['success'] === true) {
                $output->info(
                    'ProductAttribute ('.$category.'): '.($result['seeded'] ?? 0).' created, '.($result['skipped'] ?? 0).' skipped.'
                );
            }

            if ($result['success'] !== true) {
                $output->warning(
                    'ProductAttribute ('.$category.') seeding issue: '.($result['message'] ?? 'unknown error')
                );
            }
        }//end foreach

    }//end seedProductAttributeTemplates()

    /**
     * Seed Location records (Amsterdam, Rotterdam, Utrecht warehouses), idempotently.
     *
     * Location seed is unconditional (not tenant-specific) because location master data
     * is shared infrastructure. Deduplication is by (code, organizationId) inside
     * SettingsService::seedLocations() per REQ-IST-009.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-19
     */
    private function seedWarehouseLocations(IOutput $output): void
    {
        $output->info('Seeding warehouse locations...');
        $result = $this->settingsService->seedLocations();
        if ($result['success'] === true) {
            $output->info(
                'Locations seeded: '.($result['seeded'] ?? 0).' created, '.($result['skipped'] ?? 0).' skipped.'
            );
        }

        if ($result['success'] !== true) {
            $output->warning('Locations seeding issue: '.($result['message'] ?? 'unknown error'));
        }

    }//end seedWarehouseLocations()

    /**
     * Seed InventoryStock records for all three warehouse locations, idempotently.
     *
     * Seeding is unconditional (demo data). Deduplication key is (product, location, organizationId)
     * inside SettingsService::seedInventoryStock() per REQ-IST-009.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
     */
    private function seedInventoryStockLevels(IOutput $output): void
    {
        $locationFiles = [
            'stock-amsterdam.json',
            'stock-rotterdam.json',
            'stock-utrecht.json',
        ];

        foreach ($locationFiles as $file) {
            $output->info('Seeding InventoryStock from '.$file.'...');
            $result = $this->settingsService->seedInventoryStock(locationFile: $file);
            if ($result['success'] === true) {
                $output->info(
                    'InventoryStock ('.$file.'): '.($result['seeded'] ?? 0).' created, '.($result['skipped'] ?? 0).' skipped.'
                );
            }

            if ($result['success'] !== true) {
                $output->warning('InventoryStock ('.$file.') seeding issue: '.($result['message'] ?? 'unknown error'));
            }
        }//end foreach

    }//end seedInventoryStockLevels()

    /**
     * Seed the chart of accounts from the configured RGS template, idempotently.
     *
     * Seeding is skipped when administration_id is not configured (C2) to prevent
     * orphan accounts under a "default" id that contaminates real tenant data later.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    private function seedChartOfAccounts(IOutput $output): void
    {
        $settings    = $this->settingsService->getSettings();
        $templateRaw = ($settings['rgs_template'] ?? '');
        $template    = 'mkb';
        if ($templateRaw !== '') {
            $template = $templateRaw;
        }

        $administrationId = ($settings['administration_id'] ?? '');

        if ($administrationId === '') {
            // C2: seeding under a hardcoded "default" administrationId contaminates
            // real tenants when the admin later configures a proper id. Skip the seed
            // entirely and surface a clear admin notice instead.
            $output->warning(
                'Shillinq: administration_id not configured — skipping chart of accounts seed. '
                .'Go to Shillinq admin settings, set administration_id, then click '
                .'"Seed Chart of Accounts" to initialise.'
            );
            $this->logger->warning(
                'Shillinq: administration_id not configured, skipping chart of accounts seed'
            );
            return;
        }

        $output->info('Seeding chart of accounts (template: '.$template.')...');

        $seedResult = $this->settingsService->seedRgsTemplate(
            templateVariant: $template,
            administrationId: $administrationId
        );

        if ($seedResult['success'] === true) {
            $seeded  = ($seedResult['seeded'] ?? 0);
            $skipped = ($seedResult['skipped'] ?? 0);
            $output->info(
                'Chart of accounts seeded: '.$seeded.' created, '.$skipped.' skipped (already exist).'
            );
        }

        if ($seedResult['success'] !== true) {
            $message = ($seedResult['message'] ?? 'unknown error');
            $output->warning('Chart of accounts seeding issue: '.$message);
        }

        $allocationResult = $this->settingsService->seedAllocationRules(administrationId: $administrationId);

        if ($allocationResult['success'] === true) {
            $seeded  = ($allocationResult['seeded'] ?? 0);
            $skipped = ($allocationResult['skipped'] ?? 0);
            $output->info(
                'AllocationRule examples seeded: '.$seeded.' created, '.$skipped.' skipped (already exist).'
            );
        }

        if ($allocationResult['success'] !== true) {
            $message = ($allocationResult['message'] ?? 'unknown error');
            $output->warning('AllocationRule seeding issue: '.$message);
        }

    }//end seedChartOfAccounts()
}//end class
