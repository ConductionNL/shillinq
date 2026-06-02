<?php

/**
 * Shillinq Initialize Settings Repair Step
 *
 * Repair step that initializes Shillinq register and schemas on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-iv3-reporting/tasks.md#task-10
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
 * @spec openspec/changes/add-shillinq-iv3-reporting/tasks.md#task-10
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
     * @spec openspec/changes/add-shillinq-iv3-reporting/tasks.md#task-10
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
     * Phase 3: registers the IV3 quarterly CBS ScheduledWorkflow if not yet present.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-iv3-reporting/tasks.md#task-10
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
            $this->registerIv3ScheduledWorkflow(output: $output);
            $this->seedXBRLData(output: $output);
        } catch (\Throwable $e) {
            $output->warning('Could not auto-configure Shillinq: '.$e->getMessage());
            $this->logger->error(
                'Shillinq initialization failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end run()

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
        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            return;
        }

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

    }//end seedChartOfAccounts()

    /**
     * Seed XBRL taxonomy, SBR document type, and XBRL mapping reference records.
     *
     * Idempotent — existing records are skipped on re-run. Loads from
     * lib/Settings/seeds/xbrl-seed.json via SettingsService::seedXBRLData().
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-sbr-xbrl-reporting/tasks.md#task-13
     */
    private function seedXBRLData(IOutput $output): void
    {
        $output->info('Shillinq: seeding XBRL taxonomy and SBR reference data...');

        $seedResult = $this->settingsService->seedXBRLData();

        if ($seedResult['success'] === true) {
            $seeded  = ($seedResult['seeded'] ?? 0);
            $skipped = ($seedResult['skipped'] ?? 0);
            $output->info(
                'XBRL seed completed: '.$seeded.' created, '.$skipped.' skipped (already exist).'
            );
        }

        if ($seedResult['success'] !== true) {
            $message = ($seedResult['message'] ?? 'unknown error');
            $output->warning('Shillinq: XBRL seed issue: '.$message);
        }

    }//end seedXBRLData()
}//end class
