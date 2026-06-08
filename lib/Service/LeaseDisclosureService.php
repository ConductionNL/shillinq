<?php

/**
 * Lease Disclosure Service
 *
 * Aggregates the IFRS 16.51–60 quantitative disclosure table for a fiscal period
 * (REQ-LD-001..REQ-LD-005). Reads the period's active LeaseContract records (and
 * their amortization schedules) via the real OpenRegister ObjectService API
 * (findAll) and computes: closing RoU asset by asset class, current vs non-current
 * lease liability, the undiscounted maturity analysis (REQ-LD-002), the
 * liability-weighted average IBR per asset class (REQ-LD-003), and the expense
 * breakdown including straight-line short-term / low-value exemption expense
 * (REQ-LE-003). The result is the materialised LeaseDisclosureTable payload
 * (design.md D5); no parallel GL table is written.
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
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-disclosures/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Computes the period-end IFRS 16 disclosure table for one administration.
 *
 * Reads are scoped to a single administration (ADR-005 IDOR safety): the
 * administrationId is server-resolved from the authenticated user's context. The
 * aggregation is deterministic and side-effect-free given the lease set, so the
 * arithmetic core (aggregateFromLeases) is unit-testable without OpenRegister.
 *
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-disclosures/spec.md
 */
class LeaseDisclosureService
{
    /**
     * Asset classes disclosed, in stable order (REQ-LD-001).
     *
     * @var array<int,string>
     */
    private const ASSET_CLASSES = ['vehicle', 'real-estate', 'IT-hardware', 'machinery', 'other'];

    /**
     * Construct the service.
     *
     * @param ContainerInterface          $container  DI container — OR's ObjectService is fetched lazily.
     * @param IAppConfig                  $appConfig  App config for the register slug.
     * @param LeaseAmortizationCalculator $calculator Pure-logic IFRS 16 arithmetic helper.
     * @param LoggerInterface             $logger     Logger (no stack traces to client).
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LeaseAmortizationCalculator $calculator,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate the disclosure table for a fiscal period (REQ-LD-001).
     *
     * @param string $administrationId Administration scope (server-resolved, ADR-005).
     * @param string $fiscalPeriod     Fiscal period label (e.g. "2026").
     *
     * @return array<string,mixed> The LeaseDisclosureTable payload.
     *
     * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-disclosures/spec.md
     */
    public function generateForPeriod(string $administrationId, string $fiscalPeriod): array
    {
        try {
            $leases = $this->objectService()
                ->setRegister($this->register())
                ->setSchema('LeaseContract')
                ->findAll(['filters' => ['administrationId' => $administrationId]]);
        } catch (\Throwable $e) {
            // Fail soft: an OpenRegister read failure yields an empty disclosure
            // rather than a stack trace to the client (ADR-005). The administration
            // id is logged for diagnostics but never special-category data.
            $this->logger->warning(
                'LeaseDisclosureService: failed to read leases for disclosure',
                ['administrationId' => $administrationId, 'exception' => $e->getMessage()]
            );
            $leases = [];
        }

        $leases = array_values(
            array_filter(
                $leases,
                static function ($lease): bool {
                    return is_array($lease) === true
                        && in_array(($lease['status'] ?? ''), ['active', 'modified'], true) === true;
                }
            )
        );

        $table = $this->aggregateFromLeases(leases: $leases);
        $table['fiscalPeriod']     = $fiscalPeriod;
        $table['administrationId'] = $administrationId;
        $table['materializedAtPeriodClose'] = true;
        $table['qualitativeNarrative']      = $this->narrativeSeed(leaseCount: count($leases));

        return $table;

    }//end generateForPeriod()

    /**
     * Aggregate the quantitative disclosure figures from a set of leases (REQ-LD-001..005).
     *
     * Side-effect-free: given the lease set it computes RoU-by-class, the
     * current/non-current liability split, the undiscounted maturity analysis,
     * the liability-weighted IBR per class, and the expense breakdown. This is the
     * unit-testable core (no OpenRegister dependency).
     *
     * @param array<int,array<string,mixed>> $leases Active / modified LeaseContract arrays.
     *
     * @return array<string,mixed> Quantitative disclosure figures.
     *
     * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-disclosures/spec.md
     */
    public function aggregateFromLeases(array $leases): array
    {
        $acc = [
            'rouByClass'     => array_fill_keys(self::ASSET_CLASSES, 0),
            'liabilityCur'   => 0,
            'liabilityNon'   => 0,
            'maturity'       => ['lt1y' => 0, 'y1to2' => 0, 'y2to3' => 0, 'y3to4' => 0, 'y4to5' => 0, 'gt5y' => 0],
            'ibrWeighted'    => array_fill_keys(self::ASSET_CLASSES, 0),
            'ibrWeightBase'  => array_fill_keys(self::ASSET_CLASSES, 0),
            'interestCents'  => 0,
            'depCents'       => 0,
            'shortTermCents' => 0,
            'lowValueCents'  => 0,
        ];

        foreach ($leases as $lease) {
            $acc = $this->accumulateLease(acc: $acc, lease: $lease);
        }

        return [
            'totalRouAssetByClass'          => $this->centsMapToFloat(map: $acc['rouByClass']),
            'closingRouByClass'             => $this->centsMapToFloat(map: $acc['rouByClass']),
            'totalRouAdditionsInPeriod'     => 0.0,
            'totalRouDepreciationInPeriod'  => $this->calculator->fromCents(cents: $acc['depCents']),
            'totalRouDisposalsInPeriod'     => 0.0,
            'totalLeaseLiabilityCurrent'    => $this->calculator->fromCents(cents: $acc['liabilityCur']),
            'totalLeaseLiabilityNoncurrent' => $this->calculator->fromCents(cents: $acc['liabilityNon']),
            'maturityAnalysis'              => $this->centsMapToFloat(map: $acc['maturity']),
            'weightedAverageIbrByClass'     => $this->weightedAverages(weighted: $acc['ibrWeighted'], base: $acc['ibrWeightBase']),
            'totalInterestExpense'          => $this->calculator->fromCents(cents: $acc['interestCents']),
            'totalShortTermLeaseExpense'    => $this->calculator->fromCents(cents: $acc['shortTermCents']),
            'totalLowValueLeaseExpense'     => $this->calculator->fromCents(cents: $acc['lowValueCents']),
            'totalVariableLeaseExpense'     => 0.0,
        ];

    }//end aggregateFromLeases()

    /**
     * Fold one lease's contribution into the running aggregate (REQ-LD-001).
     *
     * Exempt leases add only straight-line expense; capitalised leases add RoU,
     * liability split, maturity buckets, interest, depreciation and weighted IBR.
     *
     * @param array<string,mixed> $acc   The running aggregate.
     * @param array<string,mixed> $lease One LeaseContract array.
     *
     * @return array<string,mixed> The updated aggregate.
     *
     * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-disclosures/spec.md
     */
    private function accumulateLease(array $acc, array $lease): array
    {
        $class = (string) ($lease['assetClass'] ?? 'other');
        if (in_array($class, self::ASSET_CLASSES, true) === false) {
            $class = 'other';
        }

        $classification = (string) ($lease['classification'] ?? '');

        // Exempt leases carry straight-line expense, no RoU / liability (REQ-LE-003).
        if ($classification === 'short-term-exempt' || $classification === 'low-value-exempt') {
            $periods = $this->calculator->scheduleLength(lease: $lease);
            $expense = $this->calculator->toCents(amount: ($lease['basePaymentAmount'] ?? 0)) * $periods;
            if ($classification === 'short-term-exempt') {
                $acc['shortTermCents'] += $expense;
                return $acc;
            }

            $acc['lowValueCents'] += $expense;
            return $acc;
        }

        if ($classification !== 'IFRS16-capitalised') {
            return $acc;
        }

        $rows = $this->calculator->buildSchedule(lease: $lease);
        if ($rows === []) {
            return $acc;
        }

        return $this->accumulateCapitalised(acc: $acc, lease: $lease, class: $class, rows: $rows);

    }//end accumulateLease()

    /**
     * Fold a capitalised lease's amortization schedule into the aggregate.
     *
     * @param array<string,mixed>                $acc   The running aggregate.
     * @param array<string,mixed>                $lease One LeaseContract array.
     * @param string                             $class The resolved asset class.
     * @param array<int,array<string,float|int>> $rows  The amortization schedule.
     *
     * @return array<string,mixed> The updated aggregate.
     *
     * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-disclosures/spec.md
     */
    private function accumulateCapitalised(array $acc, array $lease, string $class, array $rows): array
    {
        $opening = $rows[0];
        // Closing RoU = closing of the final row (period-end snapshot).
        $closingRou = (float) $rows[(count($rows) - 1)]['closingRouAsset'];
        $acc['rouByClass'][$class] += $this->calculator->toCents(amount: $closingRou);

        // Liability split: principal due in the next periodsPerYear periods is
        // current, the remainder non-current (REQ-LD-001 51(d)).
        $perYear = $this->calculator->periodsPerYear((string) ($lease['paymentFrequency'] ?? 'monthly'));
        foreach ($rows as $index => $row) {
            $principal            = $this->calculator->toCents(amount: $row['paymentPrincipalPortion']);
            $acc['liabilityNon'] += $principal;
            if ($index < $perYear) {
                $acc['liabilityCur'] += $principal;
                $acc['liabilityNon'] -= $principal;
            }

            // Undiscounted maturity buckets on the contractual payment (REQ-LD-002).
            $payment   = $this->calculator->toCents(amount: $row['paymentAppliedTotal']);
            $yearIndex = intdiv($index, max(1, $perYear));
            $acc['maturity'][$this->maturityBucket(yearIndex: $yearIndex)] += $payment;
        }//end foreach

        $acc['interestCents'] += $this->sumColumnCents(rows: $rows, column: 'interestAccrued');
        $acc['depCents']      += $this->sumColumnCents(rows: $rows, column: 'depreciationCharge');

        // Liability-weighted IBR per class (REQ-LD-003).
        $openingLiabCents            = $this->calculator->toCents(amount: $opening['openingLeaseLiability']);
        $acc['ibrWeighted'][$class] += (int) round($openingLiabCents * (float) ($lease['ibrPercent'] ?? 0));
        $acc['ibrWeightBase'][$class] += $openingLiabCents;

        return $acc;

    }//end accumulateCapitalised()

    /**
     * Sum a numeric column across schedule rows, in cents.
     *
     * @param array<int,array<string,float|int>> $rows   Schedule rows.
     * @param string                             $column Column key.
     *
     * @return int Sum in cents.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called via named args from accumulateCapitalised().
     */
    private function sumColumnCents(array $rows, string $column): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += $this->calculator->toCents(amount: ($row[$column] ?? 0));
        }

        return $total;

    }//end sumColumnCents()

    /**
     * Map a zero-based year index onto a maturity bucket key (REQ-LD-002).
     *
     * @param int $yearIndex Years from period start (0 = within 12 months).
     *
     * @return string Bucket key.
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called via named args from accumulateCapitalised().
     */
    private function maturityBucket(int $yearIndex): string
    {
        return match (true) {
            $yearIndex <= 0 => 'lt1y',
            $yearIndex === 1 => 'y1to2',
            $yearIndex === 2 => 'y2to3',
            $yearIndex === 3 => 'y3to4',
            $yearIndex === 4 => 'y4to5',
            default => 'gt5y',
        };

    }//end maturityBucket()

    /**
     * Convert a cents-keyed map to two-decimal floats.
     *
     * @param array<string,int> $map Cents map.
     *
     * @return array<string,float> Float map.
     */
    private function centsMapToFloat(array $map): array
    {
        $out = [];
        foreach ($map as $key => $cents) {
            $out[$key] = $this->calculator->fromCents(cents: (int) $cents);
        }

        return $out;

    }//end centsMapToFloat()

    /**
     * Compute the liability-weighted IBR per class (REQ-LD-003).
     *
     * @param array<string,int> $weighted Sum of (opening-liability × ibr) per class, in cents·percent.
     * @param array<string,int> $base     Sum of opening-liability per class, in cents.
     *
     * @return array<string,float> Weighted-average IBR per class (percent, two decimals).
     */
    private function weightedAverages(array $weighted, array $base): array
    {
        $out = [];
        foreach ($weighted as $class => $sum) {
            $denominator = (int) ($base[$class] ?? 0);
            if ($denominator === 0) {
                $out[$class] = 0.0;
                continue;
            }

            $out[$class] = round($sum / $denominator, 2);
        }

        return $out;

    }//end weightedAverages()

    /**
     * Qualitative narrative seed for the disclosure note (IFRS 16.59, REQ-LD-001).
     *
     * @param int $leaseCount Number of leases in the period.
     *
     * @return string Seed text for the operator to refine.
     */
    private function narrativeSeed(int $leaseCount): string
    {
        return 'The entity leases assets recognised under IFRS 16. '.$leaseCount
            .' lease(s) were active or modified in the period. Extension and termination options '
            .'are reassessed each period close; refer to the maturity analysis for undiscounted future '
            .'commitments and to the weighted-average incremental borrowing rate for the discounting basis.';

    }//end narrativeSeed()

    /**
     * Resolve OpenRegister's ObjectService lazily.
     *
     * @return object The ObjectService instance.
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
     *
     * @return string The register slug.
     */
    private function register(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;

    }//end register()
}//end class
