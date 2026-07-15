<?php

/**
 * Spend Analytics Service
 *
 * Single-dimension spend analysis over the Accounts-Payable sub-ledger,
 * built by CONSUMING OpenRegister's aggregation-api primitive (ADR-022) —
 * it does NOT re-implement grouping or summing in the leaf. Every view is
 * one server-side `SELECT sum(<amount>) ... GROUP BY <dimension>` executed
 * by OR's `AggregationRunner::runAdhocByRef()`, which enforces list-RBAC and
 * the active-organisation multi-tenant predicate before any SQL runs.
 *
 * Four dimensions, each a SINGLE top-level scalar field that OR's aggregation
 * engine honours today (`AggregationQuery.groupBy = {field: <name>}`):
 *
 *  - spend-by-supplier    → APTransaction, sum(totalAmount) GROUP BY vendorId,
 *                           over the open/posted invoice states.
 *  - spend-by-category    → GLLine, sum(amount) GROUP BY accountNumber, over
 *                           the debit AP expense postings.
 *  - spend-by-cost-centre → GLLine, sum(amount) GROUP BY costCenterCode, same
 *                           posting slice.
 *  - spend-by-period      → GLLine, sum(amount) GROUP BY periodId, same slice.
 *
 * CROSS-TAB (supplier × period, category × cost-centre) is intentionally NOT
 * offered here: OR's `AggregationQuery::getGroupByField(): ?string` reads a
 * single scalar groupBy field, so multi-field groupBy is inert engine-side
 * (see design.md verify-first findings + openregister multi-field-groupBy
 * issue). Cross-tab belongs in OpenRegister, not in this leaf.
 *
 * Money note: the supplier view sums gross invoice totals (APTransaction);
 * the category / cost-centre / period views sum posted expense debit amounts
 * (GLLine). The two need not reconcile to the cent (tax + the AP-payable
 * credit leg are excluded from the GL debit slice) — they answer different
 * questions and are labelled distinctly.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Single-dimension spend analytics that consume OR's aggregation-api.
 *
 * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
 */
class SpendAnalyticsService
{
    /**
     * FQCN of OpenRegister's aggregation runner (fetched lazily from the DI
     * container so the leaf does not hard-link the class at boot).
     *
     * @var string
     */
    private const OR_AGGREGATION_RUNNER = 'OCA\OpenRegister\Service\Aggregation\AggregationRunner';

    /**
     * APTransaction schema slug — source of the supplier view.
     *
     * @var string
     */
    private const SCHEMA_AP_TRANSACTION = 'APTransaction';

    /**
     * GLLine schema slug — source of the category / cost-centre / period views.
     *
     * @var string
     */
    private const SCHEMA_GL_LINE = 'GLLine';

    /**
     * Open / posted AP invoice states that represent committed spend. Excludes
     * draft (not yet committed), voided and written-off (reversed).
     *
     * @var string[]
     */
    private const AP_SPEND_STATES = [
        'issued',
        'partially-paid',
        'overdue',
        'disputed',
        'paid',
    ];

    /**
     * Constructor with lazy DI of OR's aggregation runner.
     *
     * @param ContainerInterface $container DI container — OR's AggregationRunner is fetched lazily.
     * @param IAppConfig         $appConfig App config for the register slug.
     * @param LoggerInterface    $logger    Nextcloud logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Spend grouped by supplier (vendorId) over the committed AP states.
     *
     * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
     *
     * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
     */
    public function spendBySupplier(): array
    {
        return $this->aggregate(
            dimension: 'supplier',
            schema: self::SCHEMA_AP_TRANSACTION,
            metricField: 'totalAmount',
            filter: ['state' => ['in' => self::AP_SPEND_STATES]],
            groupField: 'vendorId'
        );

    }//end spendBySupplier()

    /**
     * Spend grouped by expense category (GL accountNumber) over the debit AP
     * expense postings.
     *
     * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
     *
     * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
     */
    public function spendByCategory(): array
    {
        return $this->aggregate(
            dimension: 'category',
            schema: self::SCHEMA_GL_LINE,
            metricField: 'amount',
            filter: $this->apExpenseDebitFilter(),
            groupField: 'accountNumber'
        );

    }//end spendByCategory()

    /**
     * Spend grouped by analytical cost centre (GL costCenterCode) over the
     * debit AP expense postings.
     *
     * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
     *
     * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
     */
    public function spendByCostCentre(): array
    {
        return $this->aggregate(
            dimension: 'costCentre',
            schema: self::SCHEMA_GL_LINE,
            metricField: 'amount',
            filter: $this->apExpenseDebitFilter(),
            groupField: 'costCenterCode'
        );

    }//end spendByCostCentre()

    /**
     * Spend grouped by fiscal period (GL periodId) over the debit AP expense
     * postings.
     *
     * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
     *
     * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
     */
    public function spendByPeriod(): array
    {
        return $this->aggregate(
            dimension: 'period',
            schema: self::SCHEMA_GL_LINE,
            metricField: 'amount',
            filter: $this->apExpenseDebitFilter(),
            groupField: 'periodId'
        );

    }//end spendByPeriod()

    /**
     * The posting slice that constitutes AP expense spend: debit lines whose
     * sub-ledger is accounts-payable.
     *
     * @return array<string,mixed> The aggregation filter map.
     */
    private function apExpenseDebitFilter(): array
    {
        return [
            'side'         => 'debit',
            'subLedgerType' => 'ap',
        ];

    }//end apExpenseDebitFilter()

    /**
     * Build and dispatch one single-field aggregation through OR's
     * aggregation-api, then shape the envelope for the client.
     *
     * @param string               $dimension   Stable dimension identifier (supplier/category/costCentre/period).
     * @param string               $schema      Source schema slug.
     * @param string               $metricField Numeric field to sum.
     * @param array<string,mixed>  $filter      OR aggregation filter map (operator-aware).
     * @param string               $groupField  Single top-level scalar field to GROUP BY.
     *
     * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
     *
     * @throws RuntimeException When OR's aggregation runner is unavailable.
     *
     * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
     */
    private function aggregate(
        string $dimension,
        string $schema,
        string $metricField,
        array $filter,
        string $groupField
    ): array {
        $runner = $this->getAggregationRunner();
        if ($runner === null) {
            throw new RuntimeException('OpenRegister aggregation runner is unavailable');
        }

        // Single-field groupBy — the ONLY grouping shape OR honours today.
        $query = AggregationQuery::create(
            metric: 'sum',
            field: $metricField,
            filter: $filter,
            groupBy: ['field' => $groupField]
        );

        $envelope = $runner->runAdhocByRef(
            registerRef: $this->getRegisterSlug(),
            schemaRef: $schema,
            query: $query
        );

        return $this->shape(dimension: $dimension, envelope: $envelope);

    }//end aggregate()

    /**
     * Normalise OR's grouped envelope `{groups:[{key,value}], backend}` to the
     * client shape `{dimension, groups:[{key,amount}], total, backend}`.
     *
     * @param string              $dimension The dimension identifier.
     * @param array<string,mixed> $envelope  OR's aggregation result envelope.
     *
     * @return array<string,mixed> The shaped client payload.
     */
    private function shape(string $dimension, array $envelope): array
    {
        $groups = [];
        $total  = 0.0;

        $rawGroups = [];
        if (isset($envelope['groups']) === true && is_array($envelope['groups']) === true) {
            $rawGroups = $envelope['groups'];
        }

        foreach ($rawGroups as $group) {
            if (is_array($group) === false) {
                continue;
            }

            $key    = ($group['key'] ?? null);
            $amount = (float) ($group['value'] ?? 0);
            $total += $amount;

            $groups[] = [
                'key'    => $key,
                'amount' => $amount,
            ];
        }

        $backend = 'unknown';
        if (isset($envelope['backend']) === true && is_string($envelope['backend']) === true) {
            $backend = $envelope['backend'];
        }

        return [
            'dimension' => $dimension,
            'groups'    => $groups,
            'total'     => $total,
            'backend'   => $backend,
        ];

    }//end shape()

    /**
     * Resolve OR's AggregationRunner from the container, or null when
     * OpenRegister is unavailable (logged, never silently swallowed — the
     * caller re-raises so the controller can surface a real error status).
     *
     * @return object|null The AggregationRunner, or null.
     */
    private function getAggregationRunner(): ?object
    {
        try {
            $runner = $this->container->get(self::OR_AGGREGATION_RUNNER);
        } catch (\Throwable $e) {
            $this->logger->error(
                'SpendAnalyticsService: OpenRegister AggregationRunner unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        if (is_object($runner) === false) {
            return null;
        }

        return $runner;

    }//end getAggregationRunner()

    /**
     * Return the configured register slug, falling back to 'shillinq'.
     *
     * @return string The register slug.
     */
    private function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;

    }//end getRegisterSlug()
}//end class
