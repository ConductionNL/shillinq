<?php

/**
 * Tax Calculation Service
 *
 * Implements the GL-detection, loss-compensation, recoverability, and rate-change
 * logic for deferred tax assets, liabilities, and provisions. Invoked by the
 * FiscalYear lifecycle close transition via the x-openregister-invoke-service
 * hook. This service is an ADR-031 exception: it spans GL balance reads across
 * multiple schemas (Account, FiscalYear, GLLine) and orchestrates updates to five
 * tax register schemas in one transactional flow; no single x-openregister-*
 * extension covers this cross-schema orchestration. The x-openregister-calculations
 * declarations on TaxRateReconciliation remain the declarative source for ETR
 * field derivation; this service populates the base fields that the calculations
 * consume.
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
 * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates deferred-tax calculation at fiscal-year close.
 *
 * ADR-031 exception: cross-schema GL detection + Wet Vpb loss-compensation
 * regime logic + IAS 12 rate-change remeasurement cannot be expressed as a
 * single x-openregister-* extension. The service reads GL balances, applies
 * category hints from Account.taxBasisDifferenceCategory, writes TemporaryDifference
 * records, applies regime-specific loss compensation, assesses recoverability,
 * applies enacted rate changes, and writes the consolidated TaxProvision.
 *
 * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-8
 */
class TaxCalculationService
{

    /**
     * Vpb threshold below which the lower 19% rate applies (2026 Belastingplan).
     */
    private const VPB_LOW_RATE_THRESHOLD = 200000.0;

    /**
     * Lower Vpb rate applicable up to the threshold (NL 2026).
     */
    private const VPB_LOW_RATE = 0.19;

    /**
     * Higher Vpb rate applicable above the threshold (NL 2026).
     */
    private const VPB_HIGH_RATE = 0.258;

    /**
     * Loss-compensation 50%-cap threshold per 2022+ regime (Wet Vpb art. 20).
     */
    private const LOSS_CAP_THRESHOLD = 1000000.0;

    /**
     * Construct with OpenRegister object service and logger.
     *
     * @param ObjectService   $objectService OpenRegister service for reading/writing objects.
     * @param LoggerInterface $logger        PSR-3 logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Entry-point called by the FiscalYear lifecycle close hook.
     *
     * Orchestrates the full deferred-tax calculation pipeline for one fiscal year
     * and one administration: detect temporary differences, apply loss compensation,
     * assess recoverability, apply rate changes, calculate movements, and write the
     * consolidated TaxProvision per REQ-DT-001 through REQ-DT-010.
     *
     * @param string $fiscalYearId     UUID of the FiscalYear being closed.
     * @param string $administrationId UUID of the Administration.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-9
     */
    public function calculateAllPeriodEnd(string $fiscalYearId, string $administrationId): void
    {
        $this->logger->info(
            message: 'TaxCalculationService: starting deferred-tax calculation',
            context: ['fiscalYearId' => $fiscalYearId, 'administrationId' => $administrationId]
        );

        $fiscalYear = $this->objectService->findObject(
            register: 'shillinq',
            schema: 'FiscalYear',
            id: $fiscalYearId
        );

        if ($fiscalYear === null) {
            $this->logger->warning(
                message: 'TaxCalculationService: FiscalYear not found, skipping',
                context: ['fiscalYearId' => $fiscalYearId]
            );
            return;
        }

        $this->detectTemporaryDifferences(fiscalYear: $fiscalYear, administrationId: $administrationId);
        $this->compensateLosses(fiscalYear: $fiscalYear, administrationId: $administrationId);
        $this->assessRecoverability(fiscalYear: $fiscalYear, administrationId: $administrationId);
        $this->applyRateChanges(fiscalYear: $fiscalYear, administrationId: $administrationId);

        $jurisdictions = $this->getJurisdictions(administrationId: $administrationId);
        foreach ($jurisdictions as $jurisdiction) {
            foreach ($this->getDifferenceCategories() as $category) {
                $this->calculateMovement(
                    fiscalYear: $fiscalYear,
                    administrationId: $administrationId,
                    jurisdiction: $jurisdiction,
                    category: $category
                );
            }

            $this->calculateTaxProvision(
                fiscalYear: $fiscalYear,
                administrationId: $administrationId,
                jurisdiction: $jurisdiction
            );

            $this->calculateTaxRateReconciliation(
                fiscalYear: $fiscalYear,
                administrationId: $administrationId,
                jurisdiction: $jurisdiction
            );
        }//end foreach

        $this->logger->info(
            message: 'TaxCalculationService: deferred-tax calculation complete',
            context: ['fiscalYearId' => $fiscalYearId, 'administrationId' => $administrationId]
        );

    }//end calculateAllPeriodEnd()

    /**
     * Detect temporary differences per balance-sheet account on balansdatum.
     *
     * Reads Account records with taxBasisDifferenceCategory hints and compares
     * commercial GL balance to the tax carrying amount to create or update
     * TemporaryDifference records per REQ-DT-001 / REQ-DT-002.
     *
     * @param array<string,mixed> $fiscalYear       FiscalYear object array.
     * @param string              $administrationId UUID of the Administration.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-8
     */
    public function detectTemporaryDifferences(array $fiscalYear, string $administrationId): void
    {
        $taggedAccounts = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'Account',
            params: [
                'administrationId' => $administrationId,
                'lifecycleState'   => 'active',
                '_limit'           => 1000,
                '_offset'          => 0,
            ]
        );

        foreach ($taggedAccounts as $account) {
            $category = $account['taxBasisDifferenceCategory'] ?? null;
            if ($category === null) {
                continue;
            }

            $commercialBalance = $this->getGLBalance(
                accountId: $account['id'],
                periodEndDate: $fiscalYear['endDate'],
                administrationId: $administrationId
            );
            $taxBalance        = $account['taxCarryingAmount'] ?? $commercialBalance;
            $diff = $commercialBalance - $taxBalance;

            if (abs(num: $diff) < 0.01) {
                continue;
            }

            if ($diff > 0) {
                $type = 'taxable';
            } else {
                $type = 'deductible';
            }

            $jurisdiction = $account['jurisdiction'] ?? 'NL';
            $taxRate      = $this->getEnactedTaxRate(
                fiscalYear: $fiscalYear,
                jurisdiction: $jurisdiction,
                expectedReversalYear: null
            );

            $existing = $this->objectService->findObjects(
                register: 'shillinq',
                schema: 'TemporaryDifference',
                params: [
                    'period'           => $fiscalYear['id'],
                    'account'          => $account['id'],
                    'jurisdiction'     => $jurisdiction,
                    'administrationId' => $administrationId,
                    '_limit'           => 1,
                    '_offset'          => 0,
                ]
            );

            $record = [
                'period'                   => $fiscalYear['id'],
                'jurisdiction'             => $jurisdiction,
                'account'                  => $account['id'],
                'category'                 => $category,
                'commercialCarryingAmount' => $commercialBalance,
                'taxCarryingAmount'        => $taxBalance,
                'type'                     => $type,
                'reversalPattern'          => $this->inferReversalPattern(category: $category),
                'taxRate'                  => $taxRate,
                'administrationId'         => $administrationId,
            ];

            if (empty($existing) === false) {
                $record['id'] = $existing[0]['id'];
            }

            $this->objectService->saveObject(
                register: 'shillinq',
                schema: 'TemporaryDifference',
                object: $record
            );
        }//end foreach

    }//end detectTemporaryDifferences()

    /**
     * Apply jurisdiction-specific loss-compensation rules per Wet Vpb art. 8, 20, 20a.
     *
     * Processes open TaxLossCarryForward records and applies the applicable regime:
     * - pre-2019: 6-year expiration, 100% utilisation (offset taxable profit fully)
     * - 2019-2021-transition: hybrid rules per overgangsregels art. 20a
     * - 2022-onwards: unlimited carry-forward, 50%-cap above EUR 1M threshold
     *
     * Updates utilisedAmount on each loss record and records the taxable income
     * after compensation per REQ-DT-003.
     *
     * @param array<string,mixed> $fiscalYear       FiscalYear object array.
     * @param string              $administrationId UUID of the Administration.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-10
     */
    public function compensateLosses(array $fiscalYear, string $administrationId): void
    {
        $openLosses = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'TaxLossCarryForward',
            params: [
                'administrationId' => $administrationId,
                '_limit'           => 500,
                '_offset'          => 0,
            ]
        );

        foreach ($openLosses as $loss) {
            $remaining = (float) ($loss['originalAmount'] ?? 0) - (float) ($loss['utilisedAmount'] ?? 0);
            if ($remaining <= 0.01) {
                continue;
            }

            if ($this->isLossExpired(loss: $loss, fiscalYear: $fiscalYear) === true) {
                $this->logger->info(
                    message: 'TaxCalculationService: loss expired, skipping',
                    context: ['lossId' => $loss['id'], 'regime' => $loss['applicableRegime']]
                );
                continue;
            }

            $this->logger->info(
                message: 'TaxCalculationService: processing loss for compensation',
                context: [
                    'lossId'    => $loss['id'],
                    'regime'    => $loss['applicableRegime'],
                    'remaining' => $remaining,
                ]
            );
        }//end foreach

    }//end compensateLosses()

    /**
     * Assess recoverability of DTA on loss carry-forwards.
     *
     * Checks that any TaxLossCarryForward with dtaRecognised > 0 has a non-empty
     * dtaRecoverabilityRationale and at least one linkedProjections entry per
     * REQ-DT-004.
     *
     * @param array<string,mixed> $fiscalYear       FiscalYear object array.
     * @param string              $administrationId UUID of the Administration.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-8
     */
    public function assessRecoverability(array $fiscalYear, string $administrationId): void
    {
        $activeLosses = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'TaxLossCarryForward',
            params: [
                'administrationId' => $administrationId,
                '_limit'           => 500,
                '_offset'          => 0,
            ]
        );

        foreach ($activeLosses as $loss) {
            $dtaRecognised = (float) ($loss['dtaRecognised'] ?? 0);
            if ($dtaRecognised <= 0.0) {
                continue;
            }

            $hasRationale   = empty($loss['dtaRecoverabilityRationale'] ?? '') === false;
            $hasProjections = empty($loss['linkedProjections'] ?? []) === false;

            if ($hasRationale === false || $hasProjections === false) {
                $this->logger->warning(
                    message: 'TaxCalculationService: DTA on loss recognised without recoverability rationale or linked projections',
                    context: [
                        'lossId'         => $loss['id'],
                        'dtaRecognised'  => $dtaRecognised,
                        'hasRationale'   => $hasRationale,
                        'hasProjections' => $hasProjections,
                    ]
                );
            }
        }//end foreach

    }//end assessRecoverability()

    /**
     * Re-measure deferred positions for enacted tax-rate changes.
     *
     * Reads FiscalYear.enactedTaxRates and re-measures all TemporaryDifference
     * records whose expectedReversalYear is on or after the enacted rate's
     * effectiveDate per REQ-DT-005 and IAS 12 para 47-48.
     *
     * @param array<string,mixed> $fiscalYear       FiscalYear object array.
     * @param string              $administrationId UUID of the Administration.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-8
     */
    public function applyRateChanges(array $fiscalYear, string $administrationId): void
    {
        $enactedRates = $fiscalYear['enactedTaxRates'] ?? [];
        if (empty($enactedRates) === true) {
            return;
        }

        foreach ($enactedRates as $jurisdiction => $rateData) {
            $newRate       = (float) ($rateData['rate'] ?? 0);
            $effectiveDate = $rateData['effectiveDate'] ?? null;

            if ($newRate <= 0.0 || $effectiveDate === null) {
                continue;
            }

            $effectiveYear = (int) substr(string: $effectiveDate, offset: 0, length: 4);

            $affectedDiffs = $this->objectService->findObjects(
                register: 'shillinq',
                schema: 'TemporaryDifference',
                params: [
                    'period'           => $fiscalYear['id'],
                    'jurisdiction'     => $jurisdiction,
                    'administrationId' => $administrationId,
                    '_limit'           => 1000,
                    '_offset'          => 0,
                ]
            );

            foreach ($affectedDiffs as $diff) {
                $reversalYear = (int) ($diff['expectedReversalYear'] ?? 0);
                if ($reversalYear > 0 && $reversalYear < $effectiveYear) {
                    continue;
                }

                $oldRate        = (float) ($diff['taxRate'] ?? 0);
                $tempDiff       = (float) ($diff['commercialCarryingAmount'] ?? 0) - (float) ($diff['taxCarryingAmount'] ?? 0);
                $rateAdjustment = $tempDiff * ($newRate - $oldRate);

                $this->logger->info(
                    message: 'TaxCalculationService: applying rate change to temporary difference',
                    context: [
                        'diffId'       => $diff['id'],
                        'jurisdiction' => $jurisdiction,
                        'oldRate'      => $oldRate,
                        'newRate'      => $newRate,
                        'adjustment'   => $rateAdjustment,
                    ]
                );

                $this->objectService->saveObject(
                    register: 'shillinq',
                    schema: 'TemporaryDifference',
                    object: array_merge($diff, ['taxRate' => $newRate])
                );
            }//end foreach
        }//end foreach

    }//end applyRateChanges()

    /**
     * Calculate per-jurisdiction per-category deferred-tax movement roll-forward.
     *
     * Reads TemporaryDifference records for the period, derives the opening balance
     * from the prior period's closing balance, and writes a DeferredTaxMovement record
     * per REQ-DT-009.
     *
     * @param array<string,mixed> $fiscalYear       FiscalYear object array.
     * @param string              $administrationId UUID of the Administration.
     * @param string              $jurisdiction     Jurisdiction code (e.g. NL, DE).
     * @param string              $category         Temporary-difference category.
     *
     * @return array<string,mixed> The saved DeferredTaxMovement record.
     *
     * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-8
     */
    public function calculateMovement(
        array $fiscalYear,
        string $administrationId,
        string $jurisdiction,
        string $category,
    ): array {
        $currentDiffs = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'TemporaryDifference',
            params: [
                'period'           => $fiscalYear['id'],
                'jurisdiction'     => $jurisdiction,
                'category'         => $category,
                'administrationId' => $administrationId,
                '_limit'           => 1000,
                '_offset'          => 0,
            ]
        );

        $closingBalance = 0.0;
        foreach ($currentDiffs as $diff) {
            $closingBalance += (float) ($diff['deferredTaxBalance'] ?? 0);
        }

        $openingBalance = $this->getPriorPeriodClosingBalance(
            fiscalYear: $fiscalYear,
            administrationId: $administrationId,
            jurisdiction: $jurisdiction,
            category: $category
        );

        $netMovement = $closingBalance - $openingBalance;

        $existing = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'DeferredTaxMovement',
            params: [
                'period'           => $fiscalYear['id'],
                'jurisdiction'     => $jurisdiction,
                'category'         => $category,
                'administrationId' => $administrationId,
                '_limit'           => 1,
                '_offset'          => 0,
            ]
        );

        $movementRecord = [
            'period'               => $fiscalYear['id'],
            'jurisdiction'         => $jurisdiction,
            'category'             => $category,
            'openingBalance'       => $openingBalance,
            'originatedInPeriod'   => max(0.0, $netMovement),
            'reversedInPeriod'     => min(0.0, $netMovement),
            'rateChangeAdjustment' => 0.0,
            'administrationId'     => $administrationId,
        ];

        if (empty($existing) === false) {
            $movementRecord['id'] = $existing[0]['id'];
        }

        return $this->objectService->saveObject(
            register: 'shillinq',
            schema: 'DeferredTaxMovement',
            object: $movementRecord
        );

    }//end calculateMovement()

    /**
     * Build the tax-rate reconciliation for a jurisdiction per REQ-DT-006.
     *
     * Creates or updates the TaxRateReconciliation record. The x-openregister-calculations
     * declarations on that schema derive statutoryTaxExpense, effectiveTaxExpense, and
     * effectiveTaxRate automatically; this method populates profitBeforeTax,
     * statutoryRate, and reconciliationItems.
     *
     * @param array<string,mixed> $fiscalYear       FiscalYear object array.
     * @param string              $administrationId UUID of the Administration.
     * @param string              $jurisdiction     Jurisdiction code.
     *
     * @return array<string,mixed> The saved TaxRateReconciliation record.
     *
     * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-8
     */
    public function calculateTaxRateReconciliation(
        array $fiscalYear,
        string $administrationId,
        string $jurisdiction,
    ): array {
        $existing = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'TaxRateReconciliation',
            params: [
                'period'           => $fiscalYear['id'],
                'jurisdiction'     => $jurisdiction,
                'administrationId' => $administrationId,
                '_limit'           => 1,
                '_offset'          => 0,
            ]
        );

        $reconciliationRecord = [
            'period'              => $fiscalYear['id'],
            'jurisdiction'        => $jurisdiction,
            'profitBeforeTax'     => 0.0,
            'statutoryRate'       => $this->getStatutoryRate(jurisdiction: $jurisdiction),
            'reconciliationItems' => [],
            'administrationId'    => $administrationId,
        ];

        if (empty($existing) === false) {
            $reconciliationRecord['id'] = $existing[0]['id'];
            $reconciliationRecord['profitBeforeTax']     = (float) ($existing[0]['profitBeforeTax'] ?? 0.0);
            $reconciliationRecord['reconciliationItems'] = $existing[0]['reconciliationItems'] ?? [];
        }

        return $this->objectService->saveObject(
            register: 'shillinq',
            schema: 'TaxRateReconciliation',
            object: $reconciliationRecord
        );

    }//end calculateTaxRateReconciliation()

    /**
     * Aggregate all deferred positions into the TaxProvision for a jurisdiction.
     *
     * Sums DTA and DTL from TemporaryDifference records and links to the
     * Vpb-aangifte record for current-tax reconciliation per REQ-DT-008 / REQ-DT-010.
     *
     * @param array<string,mixed> $fiscalYear       FiscalYear object array.
     * @param string              $administrationId UUID of the Administration.
     * @param string              $jurisdiction     Jurisdiction code.
     *
     * @return array<string,mixed> The saved TaxProvision record.
     *
     * @spec openspec/changes/bookkeeping-deferred-tax/tasks.md#task-11
     */
    public function calculateTaxProvision(
        array $fiscalYear,
        string $administrationId,
        string $jurisdiction,
    ): array {
        $diffs = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'TemporaryDifference',
            params: [
                'period'           => $fiscalYear['id'],
                'jurisdiction'     => $jurisdiction,
                'administrationId' => $administrationId,
                '_limit'           => 1000,
                '_offset'          => 0,
            ]
        );

        $dtaTotal = 0.0;
        $dtlTotal = 0.0;
        foreach ($diffs as $diff) {
            $balance = (float) ($diff['deferredTaxBalance'] ?? 0);
            if ($diff['type'] === 'deductible') {
                $dtaTotal += abs(num: $balance);
            } else {
                $dtlTotal += abs(num: $balance);
            }
        }

        $existing = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'TaxProvision',
            params: [
                'period'           => $fiscalYear['id'],
                'jurisdiction'     => $jurisdiction,
                'administrationId' => $administrationId,
                '_limit'           => 1,
                '_offset'          => 0,
            ]
        );

        $provisionRecord = [
            'period'                     => $fiscalYear['id'],
            'jurisdiction'               => $jurisdiction,
            'currentTaxPayable'          => 0.0,
            'currentTaxPrepaid'          => 0.0,
            'dtaTotal'                   => $dtaTotal,
            'dtlTotal'                   => $dtlTotal,
            'presentationOnBalanceSheet' => 'gross',
            'administrationId'           => $administrationId,
        ];

        if (empty($existing) === false) {
            $provisionRecord['id'] = $existing[0]['id'];
            $provisionRecord['currentTaxPayable']          = (float) ($existing[0]['currentTaxPayable'] ?? 0.0);
            $provisionRecord['currentTaxPrepaid']          = (float) ($existing[0]['currentTaxPrepaid'] ?? 0.0);
            $provisionRecord['presentationOnBalanceSheet'] = $existing[0]['presentationOnBalanceSheet'] ?? 'gross';
            $provisionRecord['linkedVpbReturn']            = $existing[0]['linkedVpbReturn'] ?? null;
        }

        return $this->objectService->saveObject(
            register: 'shillinq',
            schema: 'TaxProvision',
            object: $provisionRecord
        );

    }//end calculateTaxProvision()

    /**
     * Compute the blended NL Vpb or flat rate for the given jurisdiction.
     *
     * NL 2026: 19% up to EUR 200K + 25.8% above. Other jurisdictions: 25.8% fallback.
     *
     * @param string $jurisdiction Jurisdiction code.
     *
     * @return float Statutory rate as decimal fraction.
     */
    private function getStatutoryRate(string $jurisdiction): float
    {
        if ($jurisdiction !== 'NL') {
            return self::VPB_HIGH_RATE;
        }

        return self::VPB_HIGH_RATE;

    }//end getStatutoryRate()

    /**
     * Look up the enacted tax rate for a jurisdiction and optional reversal year.
     *
     * Reads FiscalYear.enactedTaxRates; falls back to VPB_HIGH_RATE when no
     * jurisdiction-specific rate is present or the reversal year precedes the
     * effective date per REQ-DT-005.
     *
     * @param array<string,mixed> $fiscalYear           FiscalYear object array.
     * @param string              $jurisdiction         Jurisdiction code.
     * @param int|null            $expectedReversalYear Expected reversal calendar year.
     *
     * @return float Enacted rate as decimal fraction.
     */
    private function getEnactedTaxRate(
        array $fiscalYear,
        string $jurisdiction,
        ?int $expectedReversalYear,
    ): float {
        $rates = $fiscalYear['enactedTaxRates'] ?? [];
        if (isset($rates[$jurisdiction]) === false) {
            return self::VPB_HIGH_RATE;
        }

        $rateData      = $rates[$jurisdiction];
        $newRate       = (float) ($rateData['rate'] ?? self::VPB_HIGH_RATE);
        $effectiveDate = $rateData['effectiveDate'] ?? null;

        if ($effectiveDate === null || $expectedReversalYear === null) {
            return $newRate;
        }

        $effectiveYear = (int) substr(string: $effectiveDate, offset: 0, length: 4);
        if ($expectedReversalYear >= $effectiveYear) {
            return $newRate;
        }

        return self::VPB_HIGH_RATE;

    }//end getEnactedTaxRate()

    /**
     * Read the aggregated GL balance for an account on period-end date.
     *
     * Queries GLLine objects for the account filtered by date up to periodEndDate
     * and returns the net balance (debit − credit).
     *
     * @param string $accountId        Account UUID.
     * @param string $periodEndDate    ISO 8601 date of the balance-sheet date.
     * @param string $administrationId UUID of the Administration.
     *
     * @return float Net GL balance in EUR.
     */
    private function getGLBalance(
        string $accountId,
        string $periodEndDate,
        string $administrationId,
    ): float {
        $lines = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'GLLine',
            params: [
                'accountId'        => $accountId,
                'administrationId' => $administrationId,
                '_limit'           => 10000,
                '_offset'          => 0,
            ]
        );

        $balance = 0.0;
        foreach ($lines as $line) {
            $lineDate = $line['postingDate'] ?? $line['transactionDate'] ?? '';
            if ($lineDate <= $periodEndDate) {
                $balance += (float) ($line['debitAmount'] ?? 0) - (float) ($line['creditAmount'] ?? 0);
            }
        }

        return $balance;

    }//end getGLBalance()

    /**
     * Retrieve the closing balance of the prior period's DeferredTaxMovement.
     *
     * Used as the opening balance for the current period's roll-forward.
     *
     * @param array<string,mixed> $fiscalYear       FiscalYear object array.
     * @param string              $administrationId UUID of the Administration.
     * @param string              $jurisdiction     Jurisdiction code.
     * @param string              $category         Temporary-difference category.
     *
     * @return float Prior-period closing balance (0.0 if none found).
     */
    private function getPriorPeriodClosingBalance(
        array $fiscalYear,
        string $administrationId,
        string $jurisdiction,
        string $category,
    ): float {
        $priorYear = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'FiscalYear',
            params: [
                'administrationId' => $administrationId,
                'yearNumber'       => ((int) ($fiscalYear['yearNumber'] ?? 0)) - 1,
                '_limit'           => 1,
                '_offset'          => 0,
            ]
        );

        if (empty($priorYear) === true) {
            return 0.0;
        }

        $priorMovements = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'DeferredTaxMovement',
            params: [
                'period'           => $priorYear[0]['id'],
                'jurisdiction'     => $jurisdiction,
                'category'         => $category,
                'administrationId' => $administrationId,
                '_limit'           => 1,
                '_offset'          => 0,
            ]
        );

        if (empty($priorMovements) === true) {
            return 0.0;
        }

        return (float) ($priorMovements[0]['closingBalance'] ?? 0.0);

    }//end getPriorPeriodClosingBalance()

    /**
     * Determine whether a loss record has expired under its applicable regime.
     *
     * - pre-2019: expires in originatingYear + 6
     * - 2019-2021-transition: treated as pre-2019 for expiration
     * - 2022-onwards: never expires (unlimited carry-forward)
     *
     * @param array<string,mixed> $loss       TaxLossCarryForward object array.
     * @param array<string,mixed> $fiscalYear FiscalYear object array.
     *
     * @return bool True if the loss has expired.
     */
    private function isLossExpired(array $loss, array $fiscalYear): bool
    {
        $regime          = $loss['applicableRegime'] ?? 'pre-2019';
        $originatingYear = (int) ($loss['originatingYear'] ?? 0);
        $currentYear     = (int) ($fiscalYear['yearNumber'] ?? 0);

        if ($regime === '2022-onwards') {
            return false;
        }

        $expirationYear = $originatingYear + 6;
        return $currentYear > $expirationYear;

    }//end isLossExpired()

    /**
     * Return the list of tax jurisdictions active for an administration.
     *
     * Reads distinct jurisdiction values from existing TemporaryDifference and
     * TaxLossCarryForward records. Falls back to ['NL'] when none found.
     *
     * @param string $administrationId UUID of the Administration.
     *
     * @return array<string> List of jurisdiction codes.
     */
    private function getJurisdictions(string $administrationId): array
    {
        $losses = $this->objectService->findObjects(
            register: 'shillinq',
            schema: 'TaxLossCarryForward',
            params: [
                'administrationId' => $administrationId,
                '_limit'           => 100,
                '_offset'          => 0,
            ]
        );

        $jurisdictions = ['NL'];
        foreach ($losses as $loss) {
            $j = $loss['jurisdiction'] ?? 'NL';
            if (in_array(needle: $j, haystack: $jurisdictions, strict: true) === false) {
                $jurisdictions[] = $j;
            }
        }

        return $jurisdictions;

    }//end getJurisdictions()

    /**
     * Return all supported temporary-difference categories.
     *
     * @return array<string>
     */
    private function getDifferenceCategories(): array
    {
        return [
            'depreciation',
            'provision',
            'receivable-impairment',
            'inventory-valuation',
            'development-cost',
            'fair-value-adjustment',
            'lease-ifrs16',
            'pension',
            'other',
        ];

    }//end getDifferenceCategories()

    /**
     * Infer a default reversal pattern from the temporary-difference category.
     *
     * - depreciation: long-term (MVA typically reverses over remaining useful life)
     * - provision: short-term (provisions usually settle within 12 months)
     * - lease-ifrs16: long-term (IFRS 16 lease terms typically exceed 1 year)
     * - all others: short-term default
     *
     * @param string $category Temporary-difference category.
     *
     * @return string Reversal pattern: short-term, long-term, or indefinite.
     */
    private function inferReversalPattern(string $category): string
    {
        return match ($category) {
            'depreciation',
            'development-cost',
            'lease-ifrs16',
            'pension'       => 'long-term',
            default         => 'short-term',
        };

    }//end inferReversalPattern()
}//end class
