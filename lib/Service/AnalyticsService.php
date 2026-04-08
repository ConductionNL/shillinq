<?php

/**
 * Shillinq Analytics Service
 *
 * Computes KPI values by querying OpenRegister and handles period
 * comparison calculations.
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
 * Service for computing KPI values and analytics report data.
 *
 * @spec openspec/changes/general/tasks.md#task-11.2
 */
class AnalyticsService
{
    /**
     * Constructor for AnalyticsService.
     *
     * @param ContainerInterface $container The DI container
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
     * Get the current value, previous value, and trend for a KPI metric key.
     *
     * @param string $metricKey The metric identifier (e.g. total_receivables).
     *
     * @return array{current: float, previous: float, trend: string}
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    public function getKpiValue(string $metricKey): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        switch ($metricKey) {
            case 'total_receivables':
                $current  = $this->computeTotalReceivables(objectService: $objectService);
                $previous = $this->computeTotalReceivables(objectService: $objectService, previousPeriod: true);
                break;
            case 'overdue_invoices':
                $current  = $this->computeOverdueInvoices(objectService: $objectService);
                $previous = $this->computeOverdueInvoices(objectService: $objectService, previousPeriod: true);
                break;
            case 'cash_position':
                $current  = $this->computeCashPosition(objectService: $objectService);
                $previous = $this->computeCashPosition(objectService: $objectService, previousPeriod: true);
                break;
            default:
                $this->logger->warning('Unknown metric key: {key}', ['key' => $metricKey]);
                return ['current' => 0, 'previous' => 0, 'trend' => 'neutral'];
        }//end switch

        $trend = 'neutral';
        if ($current > $previous) {
            $trend = 'up';
        } else if ($current < $previous) {
            $trend = 'down';
        }

        return [
            'current'  => $current,
            'previous' => $previous,
            'trend'    => $trend,
        ];
    }//end getKpiValue()

    /**
     * Run a report by type and return its snapshot data.
     *
     * @param string $reportType The report type identifier.
     * @param array  $parameters Optional report parameters.
     *
     * @return array The report snapshot data.
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    public function runReport(string $reportType, array $parameters=[]): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $snapshot = [];

        switch ($reportType) {
            case 'debtors_ageing':
                $snapshot = $this->buildDebtorsAgeingReport(objectService: $objectService, parameters: $parameters);
                break;
            case 'budget_vs_actual':
                $snapshot = $this->buildBudgetVsActualReport(objectService: $objectService, parameters: $parameters);
                break;
            case 'cash_flow':
                $snapshot = $this->buildCashFlowReport(objectService: $objectService, parameters: $parameters);
                break;
            default:
                $snapshot = ['type' => 'custom', 'data' => []];
                break;
        }//end switch

        return $snapshot;
    }//end runReport()

    /**
     * Compute total receivables from unpaid invoices.
     *
     * @param object $objectService  The OpenRegister object service.
     * @param bool   $previousPeriod Whether to compute for the previous period.
     *
     * @return float The total receivables amount.
     */
    private function computeTotalReceivables(object $objectService, bool $previousPeriod=false): float
    {
        $filters = ['status' => ['ne' => 'paid']];

        if ($previousPeriod === true) {
            $filters['_period'] = 'previous';
        }

        try {
            $invoices = $objectService->getObjects(schema: 'Invoice', filters: $filters);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not fetch invoices for KPI: '.$e->getMessage());
            return 0;
        }

        $total = 0;
        foreach ($invoices as $invoice) {
            $total += (float) ($invoice['totalAmount'] ?? 0);
        }

        return $total;
    }//end computeTotalReceivables()

    /**
     * Compute the number of overdue invoices.
     *
     * @param object $objectService  The OpenRegister object service.
     * @param bool   $previousPeriod Whether to compute for the previous period.
     *
     * @return float The count of overdue invoices.
     */
    private function computeOverdueInvoices(object $objectService, bool $previousPeriod=false): float
    {
        $filters = [
            'status'    => ['ne' => 'paid'],
            'ageInDays' => ['gt' => 0],
        ];

        if ($previousPeriod === true) {
            $filters['_period'] = 'previous';
        }

        try {
            $invoices = $objectService->getObjects(schema: 'Invoice', filters: $filters);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not fetch overdue invoices for KPI: '.$e->getMessage());
            return 0;
        }

        return (float) count($invoices);
    }//end computeOverdueInvoices()

    /**
     * Compute the current cash position.
     *
     * @param object $objectService  The OpenRegister object service.
     * @param bool   $previousPeriod Whether to compute for the previous period.
     *
     * @return float The cash position value.
     */
    private function computeCashPosition(object $objectService, bool $previousPeriod=false): float
    {
        $filters = [];

        if ($previousPeriod === true) {
            $filters['_period'] = 'previous';
        }

        try {
            $payments = $objectService->getObjects(schema: 'Payment', filters: $filters);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not fetch payments for KPI: '.$e->getMessage());
            return 0;
        }

        $total = 0;
        foreach ($payments as $payment) {
            $total += (float) ($payment['amount'] ?? 0);
        }

        return $total;
    }//end computeCashPosition()

    /**
     * Build a debtors ageing report.
     *
     * @param object $objectService The OpenRegister object service.
     * @param array  $parameters    Report parameters.
     *
     * @return array The report data.
     */
    private function buildDebtorsAgeingReport(object $objectService, array $parameters): array
    {
        try {
            $invoices = $objectService->getObjects(
                schema: 'Invoice',
                filters: ['status' => ['ne' => 'paid']],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Could not build debtors ageing report: '.$e->getMessage());
            return ['type' => 'debtors_ageing', 'buckets' => []];
        }

        $buckets = [
            'current'    => 0,
            '1_30_days'  => 0,
            '31_60_days' => 0,
            '61_90_days' => 0,
            'over_90'    => 0,
        ];

        foreach ($invoices as $invoice) {
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
            'type'    => 'debtors_ageing',
            'buckets' => $buckets,
            'total'   => array_sum($buckets),
        ];
    }//end buildDebtorsAgeingReport()

    /**
     * Build a budget vs actual report.
     *
     * @param object $objectService The OpenRegister object service.
     * @param array  $parameters    Report parameters.
     *
     * @return array The report data.
     */
    private function buildBudgetVsActualReport(object $objectService, array $parameters): array
    {
        return [
            'type' => 'budget_vs_actual',
            'data' => [],
        ];
    }//end buildBudgetVsActualReport()

    /**
     * Build a cash flow report.
     *
     * @param object $objectService The OpenRegister object service.
     * @param array  $parameters    Report parameters.
     *
     * @return array The report data.
     */
    private function buildCashFlowReport(object $objectService, array $parameters): array
    {
        return [
            'type' => 'cash_flow',
            'data' => [],
        ];
    }//end buildCashFlowReport()
}//end class
