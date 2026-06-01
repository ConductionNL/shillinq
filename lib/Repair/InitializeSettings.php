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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Shillinq configuration via SettingsService.
 *
 * @spec openspec/changes/spec/tasks.md#task-11
 */
class InitializeSettings implements IRepairStep
{
    /**
     * Constructor for InitializeSettings.
     *
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/spec/tasks.md#task-11
     */
    public function getName(): string
    {
        return 'Initialize Shillinq register and schemas via ConfigurationService';
    }//end getName()

    /**
     * Run the repair step to initialize Shillinq configuration.
     *
     * Phase 1: imports the register schema from shillinq_register.json.
     * Phase 2: seeds the chart of accounts from the RGS template selected
     * in app config key 'rgs_template' (default: 'mkb'). Idempotent —
     * existing accounts are skipped on re-run, preserving operator edits.
     * Seeding is skipped entirely when administration_id is not configured
     * (C2: prevents "default" contamination of real tenant data).
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/spec/tasks.md#task-11
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
            $this->seedBbvData(output: $output);
        } catch (\Throwable $e) {
            $output->warning('Could not auto-configure Shillinq: '.$e->getMessage());
            $this->logger->error(
                'Shillinq initialization failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end run()

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
     * Seed BBV taakveld catalogue and default RGS→BBV mapping for municipal administrations.
     *
     * Skipped entirely for non-municipal administration types (mkb, zzp, etc.).
     * Idempotent on re-run — existing records are skipped to preserve operator overrides.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-bbv-compliance/tasks.md#task-10
     */
    private function seedBbvData(IOutput $output): void
    {
        $settings           = $this->settingsService->getSettings();
        $administrationId   = ($settings['administration_id'] ?? '');
        $administrationType = ($settings['administration_type'] ?? '');

        if ($administrationId === '') {
            $output->info('Shillinq: administration_id not configured — skipping BBV seed.');
            return;
        }

        // BBV seeding is only relevant for municipal-type administrations.
        $municipalTypes = ['gemeente', 'provincie', 'waterschap'];
        if (in_array($administrationType, $municipalTypes, strict: true) === false) {
            $output->info(
                'Shillinq: administration_type is "'.$administrationType
                .'" — BBV seed applies only to gemeente/provincie/waterschap, skipping.'
            );
            return;
        }

        $output->info('Shillinq: seeding BBV taakveld catalogue...');
        $taakveldResult = $this->settingsService->seedBbvTaakvelden();
        if ($taakveldResult['success'] === true) {
            $seeded  = ($taakveldResult['seeded'] ?? 0);
            $skipped = ($taakveldResult['skipped'] ?? 0);
            $output->info('BBV taakveld catalogue: '.$seeded.' created, '.$skipped.' skipped.');
        } else {
            $output->warning('BBV taakveld seed issue: '.($taakveldResult['message'] ?? 'unknown error'));
        }

        $output->info('Shillinq: seeding RGS→BBV account mapping (administrationId: '.$administrationId.')...');
        $mappingResult = $this->settingsService->seedBbvAccountMappings(
            administrationId: $administrationId
        );
        if ($mappingResult['success'] === true) {
            $seeded  = ($mappingResult['seeded'] ?? 0);
            $skipped = ($mappingResult['skipped'] ?? 0);
            $output->info('BBV account mappings: '.$seeded.' created, '.$skipped.' skipped (operator overrides preserved).');
        } else {
            $output->warning('BBV mapping seed issue: '.($mappingResult['message'] ?? 'unknown error'));
        }

    }//end seedBbvData()
}//end class
