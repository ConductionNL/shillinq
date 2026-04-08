<?php

/**
 * Shillinq Create Default Configuration
 *
 * Repair step that seeds default KpiWidget, AnalyticsReport, AutomationRule,
 * and ExpenseClaim objects on fresh installation.
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
 * @spec openspec/changes/general/tasks.md#task-2.1
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
 * Repair step that creates default seed data for Shillinq entities.
 *
 * @spec openspec/changes/general/tasks.md#task-2.1
 */
class CreateDefaultConfiguration implements IRepairStep
{

    /**
     * Constructor for CreateDefaultConfiguration.
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
        return 'Create Shillinq default configuration and seed data';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.1
     */
    public function run(IOutput $output): void
    {
        $output->info('Creating Shillinq default seed data...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning('OpenRegister not available. Skipping seed data.');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $output->warning('Could not get ObjectService: '.$e->getMessage());
            return;
        }

        $this->seedKpiWidgets($objectService, $output);
        $this->seedAutomationRule($objectService, $output);
        $this->seedExpenseClaim($objectService, $output);
        $this->seedAnalyticsReport($objectService, $output);

        $output->info('Shillinq seed data created successfully.');
    }//end run()

    /**
     * Seed a single object with idempotency check.
     *
     * @param object  $objectService The object service.
     * @param string  $schema        The schema name.
     * @param string  $uniqueField   The field used for idempotency check.
     * @param string  $uniqueValue   The value to check for uniqueness.
     * @param array   $data          The object data.
     * @param IOutput $output        The output interface.
     *
     * @return void
     */
    private function seedObject(
        object $objectService,
        string $schema,
        string $uniqueField,
        string $uniqueValue,
        array $data,
        IOutput $output,
    ): void {
        try {
            $existing = $objectService->getObjects(
                schema: $schema,
                filters: [$uniqueField => $uniqueValue],
            );

            if (count($existing) > 0) {
                $output->info("Seed {$schema} ({$uniqueField}={$uniqueValue}) already exists, skipping.");
                return;
            }

            $objectService->createObject(schema: $schema, data: $data);
            $output->info("Seeded {$schema}: {$uniqueValue}");
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to seed {schema} ({field}={value}): {msg}',
                [
                    'schema' => $schema,
                    'field'  => $uniqueField,
                    'value'  => $uniqueValue,
                    'msg'    => $e->getMessage(),
                ]
            );
            $output->warning("Failed to seed {$schema} ({$uniqueValue}): ".$e->getMessage());
        }//end try
    }//end seedObject()

    /**
     * Seed default KPI widgets.
     *
     * @param object  $objectService The object service.
     * @param IOutput $output        The output interface.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.1
     */
    private function seedKpiWidgets(object $objectService, IOutput $output): void
    {
        $this->seedObject($objectService, 'KpiWidget', 'metricKey', 'total_receivables', [
            'title'       => 'Total Receivables',
            'metricKey'   => 'total_receivables',
            'chartType'   => 'number',
            'compareWith' => 'previous_period',
            'sortOrder'   => 1,
        ], $output);

        $this->seedObject($objectService, 'KpiWidget', 'metricKey', 'overdue_invoices', [
            'title'       => 'Overdue Invoices',
            'metricKey'   => 'overdue_invoices',
            'chartType'   => 'number',
            'compareWith' => 'previous_period',
            'sortOrder'   => 2,
        ], $output);

        $this->seedObject($objectService, 'KpiWidget', 'metricKey', 'cash_position', [
            'title'       => 'Cash Position',
            'metricKey'   => 'cash_position',
            'chartType'   => 'line',
            'compareWith' => 'previous_year',
            'sortOrder'   => 3,
        ], $output);
    }//end seedKpiWidgets()

    /**
     * Seed default automation rule.
     *
     * @param object  $objectService The object service.
     * @param IOutput $output        The output interface.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.2
     */
    private function seedAutomationRule(object $objectService, IOutput $output): void
    {
        $this->seedObject($objectService, 'AutomationRule', 'name', 'Invoice 30-day Reminder', [
            'name'            => 'Invoice 30-day Reminder',
            'triggerSchema'   => 'Invoice',
            'triggerField'    => 'ageInDays',
            'triggerOperator' => 'gte',
            'triggerValue'    => '30',
            'actionType'      => 'send_notification',
            'actionParams'    => '{"subject":"Invoice overdue","template":"invoice_reminder"}',
            'isActive'        => true,
            'matchCount'      => 0,
        ], $output);
    }//end seedAutomationRule()

    /**
     * Seed demo expense claim.
     *
     * @param object  $objectService The object service.
     * @param IOutput $output        The output interface.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.3
     */
    private function seedExpenseClaim(object $objectService, IOutput $output): void
    {
        $this->seedObject($objectService, 'ExpenseClaim', 'claimNumber', 'EXP-DEMO-0001', [
            'claimNumber' => 'EXP-DEMO-0001',
            'employeeId'  => 'admin',
            'description' => 'Demo conference travel expenses',
            'status'      => 'approved',
            'totalAmount' => 345.50,
            'currency'    => 'EUR',
        ], $output);
    }//end seedExpenseClaim()

    /**
     * Seed demo analytics report.
     *
     * @param object  $objectService The object service.
     * @param IOutput $output        The output interface.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.3
     */
    private function seedAnalyticsReport(object $objectService, IOutput $output): void
    {
        $this->seedObject($objectService, 'AnalyticsReport', 'title', 'Debtors Ageing Overview', [
            'title'       => 'Debtors Ageing Overview',
            'description' => 'Default debtors ageing report showing outstanding receivables by age bracket.',
            'reportType'  => 'debtors_ageing',
        ], $output);
    }//end seedAnalyticsReport()
}//end class
