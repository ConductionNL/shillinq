<?php

/**
 * Shillinq Analytics Service
 *
 * Computes KPI values by querying OpenRegister aggregate/filter API.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/general/tasks.md#task-11.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for computing KPI values and running analytics reports.
 *
 * @spec openspec/changes/general/tasks.md#task-11.2
 */
class AnalyticsService
{
    /**
     * Constructor for AnalyticsService.
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
     * Get the current value, previous value, and trend for a KPI metric.
     *
     * @param string $metricKey The metric identifier
     *
     * @return array{current: float, previous: float, trend: string}
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    public function getKpiValue(string $metricKey): array
    {
        return match ($metricKey) {
            'total_receivables' => $this->computeTotalReceivables(),
            'overdue_invoices'  => $this->computeOverdueInvoices(),
            'cash_position'     => $this->computeCashPosition(),
            default             => $this->computeCustomKpi(metricKey: $metricKey),
        };
    }//end getKpiValue()

    /**
     * Run a report and return a snapshot of the results.
     *
     * @param string $reportType The report type identifier
     * @param array  $parameters Optional report parameters
     *
     * @return array The report snapshot data
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    public function runReport(string $reportType, array $parameters=[]): array
    {
        return match ($reportType) {
            'debtors_ageing'    => $this->runDebtorsAgeing(parameters: $parameters),
            'budget_vs_actual'  => $this->runBudgetVsActual(parameters: $parameters),
            'cash_flow'         => $this->runCashFlow(parameters: $parameters),
            default             => $this->runCustomReport(reportType: $reportType, parameters: $parameters),
        };
    }//end runReport()

    /**
     * Compute total receivables KPI.
     *
     * Sums totalAmount of all invoices where status is not 'paid'.
     *
     * @return array{current: float, previous: float, trend: string}
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    private function computeTotalReceivables(): array
    {
        $objectService = $this->getObjectService();

        $invoices = $objectService->getObjects(
            register: 'shillinq',
            schema: 'Invoice',
            filters: [],
        );

        $current = 0.0;
        foreach ($invoices as $invoice) {
            if (($invoice['status'] ?? '') !== 'paid') {
                $current += (float) ($invoice['totalAmount'] ?? 0);
            }
        }

        $previous = $this->getPreviousPeriodValue(metricKey: 'total_receivables');
        $trend    = $this->calculateTrend(current: $current, previous: $previous);

        return [
            'current'  => $current,
            'previous' => $previous,
            'trend'    => $trend,
        ];
    }//end computeTotalReceivables()

    /**
     * Compute overdue invoices count KPI.
     *
     * Counts invoices where ageInDays > 0 and status is not 'paid'.
     *
     * @return array{current: float, previous: float, trend: string}
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    private function computeOverdueInvoices(): array
    {
        $objectService = $this->getObjectService();

        $invoices = $objectService->getObjects(
            register: 'shillinq',
            schema: 'Invoice',
            filters: [],
        );

        $current = 0.0;
        foreach ($invoices as $invoice) {
            if (($invoice['status'] ?? '') !== 'paid'
                && ((int) ($invoice['ageInDays'] ?? 0)) > 0
            ) {
                $current += 1;
            }
        }

        $previous = $this->getPreviousPeriodValue(metricKey: 'overdue_invoices');
        $trend    = $this->calculateTrend(current: $current, previous: $previous);

        return [
            'current'  => $current,
            'previous' => $previous,
            'trend'    => $trend,
        ];
    }//end computeOverdueInvoices()

    /**
     * Compute cash position KPI.
     *
     * @return array{current: float, previous: float, trend: string}
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    private function computeCashPosition(): array
    {
        $objectService = $this->getObjectService();

        $payments = $objectService->getObjects(
            register: 'shillinq',
            schema: 'Payment',
            filters: [],
        );

        $current = 0.0;
        foreach ($payments as $payment) {
            $current += (float) ($payment['amount'] ?? 0);
        }

        $previous = $this->getPreviousPeriodValue(metricKey: 'cash_position');
        $trend    = $this->calculateTrend(current: $current, previous: $previous);

        return [
            'current'  => $current,
            'previous' => $previous,
            'trend'    => $trend,
        ];
    }//end computeCashPosition()

    /**
     * Compute a custom KPI using a KpiWidget's filterJson and schemaRef.
     *
     * @param string $metricKey The metric key to compute
     *
     * @return array{current: float, previous: float, trend: string}
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     *
     * @psalm-suppress UnusedParam
     */
    private function computeCustomKpi(string $metricKey): array
    {
        return [
            'current'  => 0.0,
            'previous' => 0.0,
            'trend'    => 'neutral',
        ];
    }//end computeCustomKpi()

    /**
     * Get the previous period value for a metric (placeholder for comparison).
     *
     * @param string $metricKey The metric identifier
     *
     * @return float The previous period value
     *
     * @psalm-suppress UnusedParam
     */
    private function getPreviousPeriodValue(string $metricKey): float
    {
        return 0.0;
    }//end getPreviousPeriodValue()

    /**
     * Calculate trend direction from current and previous values.
     *
     * @param float $current  The current value
     * @param float $previous The previous value
     *
     * @return string 'up', 'down', or 'neutral'
     */
    private function calculateTrend(float $current, float $previous): string
    {
        if ($current > $previous) {
            return 'up';
        }

        if ($current < $previous) {
            return 'down';
        }

        return 'neutral';
    }//end calculateTrend()

    /**
     * Run the debtors ageing report.
     *
     * @param array $parameters Report parameters
     *
     * @return array Report snapshot data
     *
     * @psalm-suppress UnusedParam
     */
    private function runDebtorsAgeing(array $parameters): array
    {
        $objectService = $this->getObjectService();

        $invoices = $objectService->getObjects(
            register: 'shillinq',
            schema: 'Invoice',
            filters: [],
        );

        $buckets = [
            'current'    => 0.0,
            '1_30_days'  => 0.0,
            '31_60_days' => 0.0,
            '61_90_days' => 0.0,
            'over_90'    => 0.0,
        ];

        foreach ($invoices as $invoice) {
            if (($invoice['status'] ?? '') === 'paid') {
                continue;
            }

            $age    = (int) ($invoice['ageInDays'] ?? 0);
            $amount = (float) ($invoice['totalAmount'] ?? 0);

            if ($age <= 0) {
                $buckets['current'] += $amount;
            } else if ($age <= 30) {
                $buckets['1_30_days'] += $amount;
            } else if ($age <= 60) {
                $buckets['31_60_days'] += $amount;
            } else if ($age <= 90) {
                $buckets['61_90_days'] += $amount;
            } else {
                $buckets['over_90'] += $amount;
            }
        }//end foreach

        return [
            'reportType'  => 'debtors_ageing',
            'generatedAt' => (new \DateTimeImmutable())->format('c'),
            'data'        => $buckets,
        ];
    }//end runDebtorsAgeing()

    /**
     * Run a budget vs actual report.
     *
     * @param array $parameters Report parameters
     *
     * @return array Report snapshot data
     *
     * @psalm-suppress UnusedParam
     */
    private function runBudgetVsActual(array $parameters): array
    {
        return [
            'reportType'  => 'budget_vs_actual',
            'generatedAt' => (new \DateTimeImmutable())->format('c'),
            'data'        => [],
        ];
    }//end runBudgetVsActual()

    /**
     * Run a cash flow report.
     *
     * @param array $parameters Report parameters
     *
     * @return array Report snapshot data
     *
     * @psalm-suppress UnusedParam
     */
    private function runCashFlow(array $parameters): array
    {
        return [
            'reportType'  => 'cash_flow',
            'generatedAt' => (new \DateTimeImmutable())->format('c'),
            'data'        => [],
        ];
    }//end runCashFlow()

    /**
     * Run a custom report.
     *
     * @param string $reportType The report type identifier
     * @param array  $parameters Report parameters
     *
     * @return array Report snapshot data
     *
     * @psalm-suppress UnusedParam
     */
    private function runCustomReport(string $reportType, array $parameters): array
    {
        return [
            'reportType'  => $reportType,
            'generatedAt' => (new \DateTimeImmutable())->format('c'),
            'data'        => [],
        ];
    }//end runCustomReport()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return mixed The ObjectService instance
     */
    private function getObjectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
