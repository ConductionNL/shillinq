<?php

/**
 * Shillinq Default Configuration Repair Step
 *
 * Seeds default KpiWidget, AutomationRule, ExpenseClaim, and AnalyticsReport objects.
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

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds default configuration objects for Shillinq.
 *
 * @spec openspec/changes/general/tasks.md#task-2.1
 */
class CreateDefaultConfiguration implements IRepairStep
{

    /**
     * Constructor for CreateDefaultConfiguration.
     *
     * @param ContainerInterface $container The service container
     * @param LoggerInterface    $logger    The logger
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
        return 'Create default Shillinq configuration objects';
    }//end getName()

    /**
     * Run the repair step to seed default objects.
     *
     * @param IOutput $output The output interface
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.1
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding default Shillinq configuration objects...');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $output->warning('OpenRegister not available, skipping seed data: '.$e->getMessage());
            return;
        }

        $this->seedKpiWidgets($objectService, $output);
        $this->seedAutomationRules($objectService, $output);
        $this->seedExpenseClaims($objectService, $output);
        $this->seedAnalyticsReports($objectService, $output);

        $output->info('Shillinq seed data creation complete.');
    }//end run()

    /**
     * Seed KPI widget objects.
     *
     * @param mixed   $objectService The OpenRegister ObjectService
     * @param IOutput $output        The output interface
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.1
     */
    private function seedKpiWidgets(mixed $objectService, IOutput $output): void
    {
        $widgets = [
            [
                'title'       => 'Total Receivables',
                'metricKey'   => 'total_receivables',
                'chartType'   => 'number',
                'compareWith' => 'previous_period',
                'sortOrder'   => 1,
            ],
            [
                'title'       => 'Overdue Invoices',
                'metricKey'   => 'overdue_invoices',
                'chartType'   => 'number',
                'compareWith' => 'previous_period',
                'sortOrder'   => 2,
            ],
            [
                'title'       => 'Cash Position',
                'metricKey'   => 'cash_position',
                'chartType'   => 'line',
                'compareWith' => 'previous_year',
                'sortOrder'   => 3,
            ],
        ];

        foreach ($widgets as $widget) {
            $this->seedObject($objectService, 'KpiWidget', 'metricKey', $widget['metricKey'], $widget, $output);
        }
    }//end seedKpiWidgets()

    /**
     * Seed automation rule objects.
     *
     * @param mixed   $objectService The OpenRegister ObjectService
     * @param IOutput $output        The output interface
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.2
     */
    private function seedAutomationRules(mixed $objectService, IOutput $output): void
    {
        $this->seedObject(
            $objectService,
            'AutomationRule',
            'name',
            'Invoice 30-day Reminder',
            [
                'name'            => 'Invoice 30-day Reminder',
                'triggerSchema'   => 'Invoice',
                'triggerField'    => 'ageInDays',
                'triggerOperator' => 'gte',
                'triggerValue'    => '30',
                'actionType'      => 'send_notification',
                'actionParams'    => '{"subject":"Invoice overdue","template":"invoice_reminder"}',
                'isActive'        => true,
                'matchCount'      => 0,
            ],
            $output,
        );
    }//end seedAutomationRules()

    /**
     * Seed expense claim objects.
     *
     * @param mixed   $objectService The OpenRegister ObjectService
     * @param IOutput $output        The output interface
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.3
     */
    private function seedExpenseClaims(mixed $objectService, IOutput $output): void
    {
        $this->seedObject(
            $objectService,
            'ExpenseClaim',
            'claimNumber',
            'EXP-DEMO-0001',
            [
                'claimNumber' => 'EXP-DEMO-0001',
                'employeeId'  => 'admin',
                'description' => 'Demo conference travel expenses',
                'status'      => 'approved',
                'totalAmount' => 345.50,
                'currency'    => 'EUR',
            ],
            $output,
        );
    }//end seedExpenseClaims()

    /**
     * Seed analytics report objects.
     *
     * @param mixed   $objectService The OpenRegister ObjectService
     * @param IOutput $output        The output interface
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-2.3
     */
    private function seedAnalyticsReports(mixed $objectService, IOutput $output): void
    {
        $this->seedObject(
            $objectService,
            'AnalyticsReport',
            'title',
            'Debtors Ageing Report',
            [
                'title'       => 'Debtors Ageing Report',
                'description' => 'Monthly debtors ageing analysis',
                'reportType'  => 'debtors_ageing',
            ],
            $output,
        );
    }//end seedAnalyticsReports()

    /**
     * Create an object if it does not already exist (idempotent).
     *
     * @param mixed   $objectService The OpenRegister ObjectService
     * @param string  $schema        The schema name
     * @param string  $uniqueField   The field used for uniqueness check
     * @param string  $uniqueValue   The value to check for
     * @param array   $data          The object data
     * @param IOutput $output        The output interface
     *
     * @return void
     */
    private function seedObject(
        mixed $objectService,
        string $schema,
        string $uniqueField,
        string $uniqueValue,
        array $data,
        IOutput $output,
    ): void {
        try {
            $existing = $objectService->getObjects(
                register: 'shillinq',
                schema: $schema,
                filters: [$uniqueField => $uniqueValue],
            );

            if (empty($existing) === false) {
                $output->info("  Skipping {$schema} '{$uniqueValue}' — already exists");
                return;
            }

            $objectService->createObject(
                register: 'shillinq',
                schema: $schema,
                object: $data,
            );

            $output->info("  Created {$schema} '{$uniqueValue}'");
        } catch (\Throwable $e) {
            $output->warning(
                "  Failed to seed {$schema} '{$uniqueValue}': ".$e->getMessage()
            );
        }//end try
    }//end seedObject()
}//end class
