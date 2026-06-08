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
     * Phase 4b: registers the FixedAssets monthly-depreciation ScheduledWorkflow per REQ-FA-005.
     * Phase 4c: registers the BCF quarterly DigiKoppeling ScheduledWorkflow per REQ-BCF-005.
     * Phase 5: seeds KOR thresholds from kor-thresholds-2026.json idempotently.
     * Phase 6: seeds AllocationRule example records from seeds/allocation-rules/ idempotently.
     * Phase 7: seeds RJ-270 stages and rate-card templates for consultancy project accounting.
     * Phase 8: seeds ProductAttribute templates (office, it_hardware, logistics, food_beverage, clothing) per REQ-IPC-007.
     * Phase 9: seeds ReimbursementPolicy + PassThroughMarkupRule master-data records per REQ-ERP-004 / REQ-ERP-005.
     * Phase 10: seeds demo Barcode records (EAN/GTIN/SSCC/UPC/internal) per REQ-SKU-011.
     * Phase 11: seeds demo InventoryStock records (Amsterdam / Rotterdam / Utrecht) per REQ-IST-009.
     * Phase 12: seeds BBVProgramme + BudgetBBVMapping demo records (waterschappen BBV chain member 01) per REQ-BBVW-001 / REQ-BBVW-002.
     * Phase 13: seeds the default InventoryGLConfig (RGS 3.5 MKB account
     * routing for COGS / Inventory Asset / GR-IR / Inventory Adjustment)
     * per REQ-CG-001 / Task 11.
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
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
     * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-11
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

            $this->seedDefaultAdministration(output: $output);
            $this->seedChartOfAccounts(output: $output);
            $this->seedProjectData(output: $output);
            $this->seedSelectielijstRules(output: $output);
            $this->registerIv3ScheduledWorkflow(output: $output);
            $this->registerFixedAssetsMonthlyDepreciationWorkflow(output: $output);
            $this->registerBcfQuarterlyDigikoppelingWorkflow(output: $output);
            $this->seedKorThresholds(output: $output);
            $this->seedComplianceReferenceData(output: $output);
            $this->seedProductAttributeTemplates(output: $output);
            $this->seedReimbursementPolicies(output: $output);
            $this->seedPassThroughMarkupRules(output: $output);
            $this->seedInventoryBarcodeDemo(output: $output);
            $this->seedInventoryLotsDemo(output: $output);
            $this->seedInventoryValuationExamples(output: $output);
            $this->seedInventoryStockExamples(output: $output);
            $this->seedInventoryGLConfig(output: $output);
            $this->seedBbvWaterschappenDemo(output: $output);
        } catch (\Throwable $e) {
            $output->warning('Could not auto-configure Shillinq: '.$e->getMessage());
            $this->logger->error(
                'Shillinq initialization failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end run()

    /**
     * Seed the default Administration on fresh install, idempotently (REQ-MA-001, REQ-MA-007).
     *
     * Foundational multi-administratie boundary: a single default Administration
     * (administrationCode ADM-001) is created so single-administratie installs have a
     * valid administrationId FK target. Deduplicated on administrationCode inside
     * SettingsService::seedDefaultAdministration(), so re-runs are safe.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
     */
    private function seedDefaultAdministration(IOutput $output): void
    {
        $output->info('Seeding default administration...');
        $result = $this->settingsService->seedDefaultAdministration();

        if (($result['success'] ?? false) !== true) {
            $output->warning('Default administration seeding issue: '.($result['message'] ?? 'unknown error'));
            return;
        }

        $output->info(
            'Default administration: '.($result['seeded'] ?? 0).' created, '.($result['skipped'] ?? 0).' skipped (already exists).'
        );

    }//end seedDefaultAdministration()

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
        if (($rj270Result['success'] ?? false) === true) {
            $output->info(
                'RJ-270 stages seeded: '.($rj270Result['seeded'] ?? 0).' created, '.($rj270Result['skipped'] ?? 0).' skipped.'
            );
        }

        if (($rj270Result['success'] ?? false) !== true) {
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
     * Register the monthly Fixed-Assets depreciation ScheduledWorkflow if not yet present.
     *
     * Idempotent: uses the slug 'shillinq-fixed-assets-monthly-depreciation'
     * for deduplication. Interval defaults to 2592000 seconds (30 days). The
     * workflow walks every `FixedAsset` with `lifecycleState=active` and,
     * for each asset, reads the `monthlyDepreciation` derived field and
     * materialises a balanced `GLTransaction` (debit depreciation expense,
     * credit accumulated depreciation). Per ADR-031 §"Background jobs that
     * walk an object queue" path 2 — no shillinq DepreciationJob extends
     * TimedJob ships. REQ-FA-005.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    private function registerFixedAssetsMonthlyDepreciationWorkflow(IOutput $output): void
    {
        try {
            $workflowMapper = $this->container->get(
                'OCA\OpenRegister\Db\ScheduledWorkflowMapper'
            );
        } catch (\Throwable $e) {
            $output->info('Shillinq: ScheduledWorkflowMapper not available, skipping FixedAssets workflow registration');
            return;
        }

        $slug = 'shillinq-fixed-assets-monthly-depreciation';

        $existing = $workflowMapper->findAll();
        foreach ($existing as $workflow) {
            if ($workflow->getName() === $slug) {
                $output->info('Shillinq: FixedAssets monthly-depreciation ScheduledWorkflow already registered, skipping');
                return;
            }
        }

        // 30 days in seconds — monthly cadence per REQ-FA-005. Operators
        // adjust the interval and target via the OpenRegister
        // ScheduledWorkflow admin UI if their close cadence differs.
        $workflowMapper->createFromArray(
            data: [
                'name'        => $slug,
                'engine'      => 'openconnector',
                'workflowId'  => 'fixed-assets-monthly-depreciation',
                'intervalSec' => 2592000,
                'enabled'     => true,
                'payload'     => json_encode(
                    [
                        'register'       => 'shillinq',
                        'schema'         => 'FixedAsset',
                        'lifecycleState' => 'active',
                        'derivedFields'  => [
                            'monthlyDepreciation',
                            'currentBookValue',
                            'commercialBookValue',
                            'fiscalBookValue',
                        ],
                    ]
                ),
            ]
        );

        $output->info('Shillinq: FixedAssets monthly-depreciation ScheduledWorkflow registered (interval: 30 days)');

    }//end registerFixedAssetsMonthlyDepreciationWorkflow()

    /**
     * Register the BCF quarterly DigiKoppeling ScheduledWorkflow if not already present.
     *
     * Idempotent: uses the slug 'shillinq-bcf-quarterly-digikoppeling-submission'
     * for deduplication. The interval defaults to 7776000 seconds (90 days /
     * quarterly cadence aligned with the BCF claim window; the cron equivalent
     * fires on the first day of each quarter). Per ADR-019 + ADR-022 the
     * submission consumes the `digikoppeling-bcf` OpenConnector source — no
     * app-local DigiKoppeling client ships. Operators adjust the interval and
     * target via the OpenRegister ScheduledWorkflow admin UI per REQ-BCF-005.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-bcf-vat-compensation/tasks.md#task-11
     */
    private function registerBcfQuarterlyDigikoppelingWorkflow(IOutput $output): void
    {
        try {
            $workflowMapper = $this->container->get(
                'OCA\OpenRegister\Db\ScheduledWorkflowMapper'
            );
        } catch (\Throwable $e) {
            $output->info('Shillinq: ScheduledWorkflowMapper not available, skipping BCF workflow registration');
            return;
        }

        $slug = 'shillinq-bcf-quarterly-digikoppeling-submission';

        $existing = $workflowMapper->findAll();
        foreach ($existing as $workflow) {
            if ($workflow->getName() === $slug) {
                $output->info('Shillinq: BCF quarterly DigiKoppeling ScheduledWorkflow already registered, skipping');
                return;
            }
        }

        // 90 days in seconds — quarterly cadence aligned with digikoppeling-bcf
        // cron `0 0 1 */3 *`. Operators reconfigure the interval and target via the
        // OpenRegister admin UI when Belastingdienst deadlines shift.
        // REQ-BCF-005 / ADR-019.
        $workflowMapper->createFromArray(
            data: [
                'name'        => $slug,
                'engine'      => 'openconnector',
                'workflowId'  => 'digikoppeling-bcf',
                'intervalSec' => 7776000,
                'enabled'     => true,
                'payload'     => json_encode(
                    [
                        'register'           => 'shillinq',
                        'schema'             => 'BcfClaim',
                        'administrationType' => ['gemeente', 'provincie', 'waterschap'],
                    ]
                ),
            ]
        );

        $output->info('Shillinq: BCF quarterly DigiKoppeling ScheduledWorkflow registered (interval: 90 days)');

    }//end registerBcfQuarterlyDigikoppelingWorkflow()

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
        if (($btwResult['success'] ?? false) === true) {
            $output->info(
                'BTW tariffs seeded: '.($btwResult['seeded'] ?? 0).' created, '.($btwResult['skipped'] ?? 0).' skipped.'
            );
        }

        if (($btwResult['success'] ?? false) !== true) {
            $output->warning('BTW tariffs seeding issue: '.($btwResult['message'] ?? 'unknown error'));
        }

        $output->info('Seeding BBV taakvelden...');
        $bbvResult = $this->settingsService->seedBbvTaakvelden();
        if (($bbvResult['success'] ?? false) === true) {
            $output->info(
                'BBV taakvelden seeded: '.($bbvResult['seeded'] ?? 0).' created, '.($bbvResult['skipped'] ?? 0).' skipped.'
            );
        }

        if (($bbvResult['success'] ?? false) !== true) {
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
     * Seed the demo Barcode records, idempotently.
     *
     * Calls `SettingsService::seedInventoryBarcodes()`. Idempotent: barcodes
     * matched by `(barcode, uomCode)` are skipped, preserving operator edits
     * per REQ-SKU-011. The demo barcodes reference inventory-product-catalog
     * demo SKUs (DV-KAT-SENIOR-2KG, VIT-C-1000MG-100CT).
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-15
     */
    private function seedInventoryBarcodeDemo(IOutput $output): void
    {
        $output->info('Seeding demo barcodes...');
        $result = $this->settingsService->seedInventoryBarcodes();

        if ($result['success'] === true) {
            $output->info(
                'Demo barcodes seeded: '.($result['seeded'] ?? 0).' created, '.($result['skipped'] ?? 0).' skipped.'
            );
            return;
        }

        $output->warning('Demo barcode seeding issue: '.($result['message'] ?? 'unknown error'));

    }//end seedInventoryBarcodeDemo()

    /**
     * Seed the demo InventoryLot records, idempotently.
     *
     * Calls `SettingsService::seedInventoryLots()`. Idempotent: lots
     * matched by `(administrationId, lotNumber)` are skipped, preserving
     * operator edits per REQ-LOT-002. The seed references inventory-product-
     * catalog demo SKUs (DV-KAT-SENIOR-2KG etc.); the lots still load when
     * those Products are absent and become discoverable once they land.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
     */
    private function seedInventoryLotsDemo(IOutput $output): void
    {
        $output->info('Seeding demo inventory lots...');
        $result = $this->settingsService->seedInventoryLots();

        if ($result['success'] === true) {
            $output->info(
                'Demo inventory lots seeded: '.($result['seeded'] ?? 0).' created, '.($result['skipped'] ?? 0).' skipped.'
            );
            return;
        }

        $output->warning('Demo inventory lot seeding issue: '.($result['message'] ?? 'unknown error'));

    }//end seedInventoryLotsDemo()

    /**
     * Seed example InventoryValuation snapshots from
     * `inventory-valuation-examples.json`, idempotently per administration.
     *
     * Calls SettingsService::seedInventoryValuationExamples(). Idempotent:
     * dedupes on (productId, warehouse, status=active, administrationId)
     * per REQ-INV-005 so re-runs are safe and operator edits persist.
     * Skipped when administration_id is not configured (C2).
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-12
     */
    private function seedInventoryValuationExamples(IOutput $output): void
    {
        $settings         = $this->settingsService->getSettings();
        $administrationId = ($settings['administration_id'] ?? '');

        if ($administrationId === '') {
            $output->info('Shillinq: InventoryValuation example seed skipped (no default administration configured)');
            return;
        }

        $output->info('Seeding inventory valuation examples...');
        $result = $this->settingsService->seedInventoryValuationExamples(administrationId: $administrationId);

        if (($result['success'] ?? false) === true) {
            $output->info(
                'Inventory valuation examples seeded: '
                .($result['seeded'] ?? 0).' created, '.($result['skipped'] ?? 0).' skipped.'
            );
            return;
        }

        $output->warning('Inventory valuation example seeding issue: '.($result['message'] ?? 'unknown error'));

    }//end seedInventoryValuationExamples()

    /**
     * Seed example InventoryStock records from the three location seed files
     * (stock-amsterdam.json, stock-rotterdam.json, stock-utrecht.json),
     * idempotently per administration.
     *
     * Calls SettingsService::seedInventoryStockExamples() which deduplicates
     * on (administrationId, productSku, locationCode) per REQ-IST-002 +
     * REQ-IST-009 so re-runs are safe and operator edits to quantities
     * persist across upgrades. Skipped when administration_id is not
     * configured (C2 — prevents "default" contamination of real tenant data).
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
     */
    private function seedInventoryStockExamples(IOutput $output): void
    {
        $settings         = $this->settingsService->getSettings();
        $administrationId = ($settings['administration_id'] ?? '');

        if ($administrationId === '') {
            $output->info('Shillinq: InventoryStock seed skipped (no default administration configured)');
            return;
        }

        $output->info('Seeding InventoryStock examples...');
        $result = $this->settingsService->seedInventoryStockExamples(administrationId: $administrationId);

        if (($result['success'] ?? false) === true) {
            $output->info(
                'InventoryStock examples seeded: '
                .($result['seeded'] ?? 0).' created, '.($result['skipped'] ?? 0).' skipped.'
            );
            return;
        }

        $output->warning('InventoryStock example seeding issue: '.($result['message'] ?? 'unknown error'));

    }//end seedInventoryStockExamples()

    /**
     * Seed the default `InventoryGLConfig` record per administration,
     * idempotently (inventory-cogs-posting REQ-CG-001 + Task 11).
     *
     * Calls SettingsService::seedInventoryGLConfig() which dedupes on
     * administrationId per REQ-CG-001 (one active config per tenant)
     * so re-runs preserve operator overrides. Skipped when
     * administration_id is not configured (C2 — prevents "default"
     * contamination of real tenant data).
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-11
     */
    private function seedInventoryGLConfig(IOutput $output): void
    {
        $settings         = $this->settingsService->getSettings();
        $administrationId = ($settings['administration_id'] ?? '');

        if ($administrationId === '') {
            $output->info('Shillinq: InventoryGLConfig seed skipped (no default administration configured)');
            return;
        }

        $output->info('Seeding InventoryGLConfig defaults (RGS 3.5 MKB)...');
        $result = $this->settingsService->seedInventoryGLConfig(administrationId: $administrationId);

        if (($result['success'] ?? false) === true) {
            $output->info(
                'InventoryGLConfig seeded: '
                .($result['seeded'] ?? 0).' created, '.($result['skipped'] ?? 0).' skipped.'
            );
            return;
        }

        $output->warning('InventoryGLConfig seeding issue: '.($result['message'] ?? 'unknown error'));

    }//end seedInventoryGLConfig()

    /**
     * Seed the bookkeeping-waterschappen-bbv-variant slice-01 demo data
     * (BBVProgramme + BudgetBBVMapping) idempotently per administration.
     *
     * Calls SettingsService::seedBbvProgrammes() and seedBudgetBbvMappings().
     * Both seeders dedupe on natural keys per REQ-BBVW-001 / REQ-BBVW-002 so
     * re-runs never create duplicates and operator edits persist across
     * upgrades. Skipped when administration_id is not configured (C2 —
     * prevents "default" contamination of real tenant data).
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
     */
    private function seedBbvWaterschappenDemo(IOutput $output): void
    {
        $settings         = $this->settingsService->getSettings();
        $administrationId = ($settings['administration_id'] ?? '');

        if ($administrationId === '') {
            $output->info('Shillinq: BBV waterschappen demo seed skipped (no default administration configured)');
            return;
        }

        $output->info('Seeding BBVProgramme demo records...');
        $progResult = $this->settingsService->seedBbvProgrammes(administrationId: $administrationId);
        if (($progResult['success'] ?? false) === true) {
            $output->info(
                'BBVProgramme demo records seeded: '
                .($progResult['seeded'] ?? 0).' created, '.($progResult['skipped'] ?? 0).' skipped.'
            );
        }

        if (($progResult['success'] ?? false) !== true) {
            $output->warning('BBVProgramme demo seeding issue: '.($progResult['message'] ?? 'unknown error'));
            return;
        }

        $output->info('Seeding BudgetBBVMapping demo records...');
        $mapResult = $this->settingsService->seedBudgetBbvMappings(administrationId: $administrationId);
        if (($mapResult['success'] ?? false) === true) {
            $output->info(
                'BudgetBBVMapping demo records seeded: '
                .($mapResult['seeded'] ?? 0).' created, '.($mapResult['skipped'] ?? 0).' skipped.'
            );
            return;
        }

        $output->warning('BudgetBBVMapping demo seeding issue: '.($mapResult['message'] ?? 'unknown error'));

    }//end seedBbvWaterschappenDemo()

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

    /**
     * Seed the ReimbursementPolicy master-data records from
     * reimbursement-policies-2026.json, idempotently per administration.
     *
     * Deduplication key is (policyId, administrationId): if a ReimbursementPolicy
     * record with the same policyId already exists for the seeded administration
     * it is skipped. Re-running the repair step is safe and preserves operator
     * edits per REQ-ERP-004 (one active policy per administration).
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-18
     */
    private function seedReimbursementPolicies(IOutput $output): void
    {
        $seedPath = __DIR__.'/../Settings/seeds/reimbursement-policies-2026.json';
        if (file_exists($seedPath) === false) {
            $output->warning('Shillinq: ReimbursementPolicy seed file not found, skipping');
            return;
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            $output->warning('Shillinq: failed to read ReimbursementPolicy seed file, skipping');
            return;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $output->warning('Shillinq: failed to parse ReimbursementPolicy seed file: '.json_last_error_msg());
            return;
        }

        $policies = ($data['policies'] ?? []);
        if (empty($policies) === true) {
            $output->info('Shillinq: ReimbursementPolicy seed file contains no policies, skipping');
            return;
        }

        try {
            $objectService    = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug     = $this->settingsService->getRegisterSlug();
            $settings         = $this->settingsService->getSettings();
            $administrationId = ($settings['administration_id'] ?? '');
            $seeded           = 0;
            $skipped          = 0;

            if ($administrationId === '') {
                $output->info('Shillinq: ReimbursementPolicy seed skipped (no default administration configured)');
                return;
            }

            foreach ($policies as $policy) {
                $policyId = ($policy['policyId'] ?? null);
                if ($policyId === null) {
                    continue;
                }

                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('ReimbursementPolicy')
                    ->findAll(
                            [
                                'filters' => [
                                    'policyId'         => $policyId,
                                    'administrationId' => $administrationId,
                                ],
                                'limit'   => 1,
                            ]
                            );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $policy['administrationId'] = $administrationId;
                $objectService->saveObject(
                    object: $policy,
                    register: $registerSlug,
                    schema: 'ReimbursementPolicy',
                );
                $seeded++;
            }//end foreach

            $output->info(
                'Shillinq: ReimbursementPolicy seeded: '.$seeded.' created, '.$skipped.' skipped (already exist).'
            );
        } catch (\Throwable $e) {
            $output->warning('Shillinq: ReimbursementPolicy seeding failed: '.$e->getMessage());
            $this->logger->warning(
                'Shillinq: ReimbursementPolicy seeding failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end seedReimbursementPolicies()

    /**
     * Seed the PassThroughMarkupRule master-data records from
     * passthrough-markup-rules-2026.json, idempotently per administration.
     *
     * Deduplication key is (ruleId, administrationId): re-running the repair
     * step preserves operator edits per REQ-ERP-005. Lookup priority — customer
     * + category > customer-only > global default — is enforced declaratively
     * by Receipt/MileageEntry/PerDiem x-openregister-calculations.markupLookup;
     * this seeder only loads the canonical RULE-001..RULE-004 fixtures.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md#task-19
     */
    private function seedPassThroughMarkupRules(IOutput $output): void
    {
        $seedPath = __DIR__.'/../Settings/seeds/passthrough-markup-rules-2026.json';
        if (file_exists($seedPath) === false) {
            $output->warning('Shillinq: PassThroughMarkupRule seed file not found, skipping');
            return;
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            $output->warning('Shillinq: failed to read PassThroughMarkupRule seed file, skipping');
            return;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $output->warning('Shillinq: failed to parse PassThroughMarkupRule seed file: '.json_last_error_msg());
            return;
        }

        $rules = ($data['rules'] ?? []);
        if (empty($rules) === true) {
            $output->info('Shillinq: PassThroughMarkupRule seed file contains no rules, skipping');
            return;
        }

        try {
            $objectService    = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug     = $this->settingsService->getRegisterSlug();
            $settings         = $this->settingsService->getSettings();
            $administrationId = ($settings['administration_id'] ?? '');
            $seeded           = 0;
            $skipped          = 0;

            if ($administrationId === '') {
                $output->info('Shillinq: PassThroughMarkupRule seed skipped (no default administration configured)');
                return;
            }

            foreach ($rules as $rule) {
                $ruleId = ($rule['ruleId'] ?? null);
                if ($ruleId === null) {
                    continue;
                }

                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('PassThroughMarkupRule')
                    ->findAll(
                            [
                                'filters' => [
                                    'ruleId'           => $ruleId,
                                    'administrationId' => $administrationId,
                                ],
                                'limit'   => 1,
                            ]
                            );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $rule['administrationId'] = $administrationId;
                $objectService->saveObject(
                    object: $rule,
                    register: $registerSlug,
                    schema: 'PassThroughMarkupRule',
                );
                $seeded++;
            }//end foreach

            $output->info(
                'Shillinq: PassThroughMarkupRule seeded: '.$seeded.' created, '.$skipped.' skipped (already exist).'
            );
        } catch (\Throwable $e) {
            $output->warning('Shillinq: PassThroughMarkupRule seeding failed: '.$e->getMessage());
            $this->logger->warning(
                'Shillinq: PassThroughMarkupRule seeding failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end seedPassThroughMarkupRules()
}//end class
