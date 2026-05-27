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
            $result = $this->settingsService->loadConfiguration();

            if ($result['success'] === true) {
                $version = ($result['version'] ?? 'unknown');
                $output->info(
                    'Shillinq configuration imported successfully (version: '.$version.')'
                );
            }

            if ($result['success'] !== true) {
                $message = ($result['message'] ?? 'unknown error');
                $output->warning('Shillinq configuration import issue: '.$message);
            }

            $this->seedChartOfAccounts(output: $output);
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
            $administrationId = 'default';
            $output->warning(
                'Shillinq: administration_id not configured — seeding chart of accounts under '
                .'administrationId="default". Set administration_id in Shillinq admin settings '
                .'before going to production to avoid cross-tenant contamination.'
            );
            $this->logger->warning(
                'Shillinq: administration_id not configured, using "default" for chart of accounts seed'
            );
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
}//end class
