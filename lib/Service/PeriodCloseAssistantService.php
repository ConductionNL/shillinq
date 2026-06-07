<?php

/**
 * Period Close Assistant Service
 *
 * Tier-2 AI close-assistant task detection (REQ-PC-004). Scans the ledger for
 * the incomplete pre-close tasks a guided month-end / quarter-end close must
 * surface, and formats them as non-blocking warning flags (design D3):
 *
 *  - Open AP transactions: unposted (draft) GLTransactions in the period whose
 *    lines carry subLedgerType "ap".
 *  - Open AR transactions: unposted (draft) GLTransactions in the period whose
 *    lines carry subLedgerType "ar".
 *  - Unreconciled bank receipts: bank statements dated in the period that report
 *    movements but have no posted GLTransaction referencing them.
 *  - Outstanding expense claims: ExpenseClaimEntry rows whose approvalState is
 *    pending (awaiting approval / reimbursement).
 *
 * All queries go through the real OpenRegister ObjectService API (find / findAll)
 * and are scoped to a single administration + period. Detection is deterministic
 * and fully unit-testable; the resulting flags are *warnings*, never blockers —
 * operators review and resolve them manually. This service deliberately does not
 * call an LLM: Shillinq ships no ChatService dependency, so the narrative is
 * generated from the deterministic detection summary. When a fleet ChatService
 * lands, generateFlags() is the single seam to enrich the message text.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Detects incomplete pre-close tasks and formats them as close-assistant flags.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
 */
class PeriodCloseAssistantService
{
    /**
     * Construct the service.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for the register slug.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Run every detector and return the consolidated flag list (REQ-PC-004).
     *
     * @param string $administrationId The administration scope (server-resolved, REQ-PC-008).
     * @param string $periodId         The period to analyse.
     * @param string $endDate          The period end date (ISO date) for date filtering.
     *
     * @return array<int,array<string,mixed>> Flag objects {id, severity, message, category, detectedAt}.
     *
     * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
     */
    public function analyse(string $administrationId, string $periodId, string $endDate=''): array
    {
        $detections = [
            'ap'             => $this->detectOpenSubLedger(
                administrationId: $administrationId,
                periodId: $periodId,
                subLedgerType: 'ap'
            ),
            'ar'             => $this->detectOpenSubLedger(
                administrationId: $administrationId,
                periodId: $periodId,
                subLedgerType: 'ar'
            ),
            'bank'           => $this->detectUnreconciledBankReceipts(
                administrationId: $administrationId,
                periodId: $periodId,
                endDate: $endDate
            ),
            'expense-claims' => $this->detectOutstandingExpenseClaims(administrationId: $administrationId),
        ];

        return $this->generateFlags(detections: $detections);

    }//end analyse()

    /**
     * Count unposted (draft) transactions carrying the given sub-ledger type (REQ-PC-004).
     *
     * Open AP / AR work is represented by GLTransactions still in the draft state
     * for the period whose GLLines carry the matching subLedgerType. Returns the
     * distinct transaction count and the total of the matching debit/credit lines.
     *
     * @param string $administrationId The administration scope.
     * @param string $periodId         The period.
     * @param string $subLedgerType    'ap' or 'ar'.
     *
     * @return array{count:int, total:float} Detection summary.
     *
     * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
     */
    public function detectOpenSubLedger(string $administrationId, string $periodId, string $subLedgerType): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $register      = $this->register();

        $transactions = $objectService
            ->setRegister($register)
            ->setSchema('GLTransaction')
            ->findAll(
                [
                    'filters' => [
                        'administrationId' => $administrationId,
                        'periodId'         => $periodId,
                        'state'            => 'draft',
                    ],
                ]
            );

        $draftIds = [];
        foreach ($transactions as $transaction) {
            $id = $this->idOf(record: (array) $transaction);
            if ($id !== '') {
                $draftIds[$id] = true;
            }
        }

        if ($draftIds === []) {
            return ['count' => 0, 'total' => 0.0];
        }

        $lines = $objectService
            ->setRegister($register)
            ->setSchema('GLLine')
            ->findAll(
                [
                    'filters' => [
                        'periodId'      => $periodId,
                        'subLedgerType' => $subLedgerType,
                    ],
                ]
            );

        $matchedTransactions = [];
        $totalCents          = 0;
        foreach ($lines as $line) {
            $line          = (array) $line;
            $transactionId = (string) ($line['transactionId'] ?? '');
            if (isset($draftIds[$transactionId]) === false) {
                continue;
            }

            $matchedTransactions[$transactionId] = true;
            $totalCents += (int) round(((float) ($line['amount'] ?? 0)) * 100);
        }

        return [
            'count' => count($matchedTransactions),
            'total' => ((float) $totalCents / 100),
        ];

    }//end detectOpenSubLedger()

    /**
     * Count bank statements in the period that have no posted GL match (REQ-PC-004).
     *
     * A bank statement reporting movements (transactionCount > 0) dated on or
     * before the period end with no posted GLTransaction whose sourceReference
     * points at it is flagged as unreconciled.
     *
     * @param string $administrationId The administration scope.
     * @param string $periodId         The period.
     * @param string $endDate          The period end date (ISO date); '' to skip date filtering.
     *
     * @return array{count:int, total:float} Detection summary (total is the receipt count, no euro total).
     *
     * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
     */
    public function detectUnreconciledBankReceipts(string $administrationId, string $periodId, string $endDate=''): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $register      = $this->register();

        $statements = $objectService
            ->setRegister($register)
            ->setSchema('BankStatement')
            ->findAll(['filters' => ['administrationId' => $administrationId]]);

        // Posted GL transactions for the period — their sourceReference set lets
        // us recognise statements that have already been reconciled / posted.
        $posted = $objectService
            ->setRegister($register)
            ->setSchema('GLTransaction')
            ->findAll(
                [
                    'filters' => [
                        'administrationId' => $administrationId,
                        'periodId'         => $periodId,
                        'state'            => 'posted',
                    ],
                ]
            );

        $postedReferences = [];
        foreach ($posted as $transaction) {
            $reference = (string) (((array) $transaction)['sourceReference'] ?? '');
            if ($reference !== '') {
                $postedReferences[$reference] = true;
            }
        }

        $unreconciled = 0;
        foreach ($statements as $statement) {
            $statement = (array) $statement;
            if ((int) ($statement['transactionCount'] ?? 0) <= 0) {
                continue;
            }

            if ($endDate !== '' && (string) ($statement['statementDate'] ?? '') > $endDate) {
                continue;
            }

            $statementId = $this->idOf(record: $statement);
            if ($statementId !== '' && isset($postedReferences[$statementId]) === true) {
                continue;
            }

            $unreconciled++;
        }//end foreach

        return ['count' => $unreconciled, 'total' => (float) $unreconciled];

    }//end detectUnreconciledBankReceipts()

    /**
     * Count expense claims awaiting approval / reimbursement (REQ-PC-004).
     *
     * @param string $administrationId The administration scope.
     *
     * @return array{count:int, total:float} Detection summary (total is the claimed euro amount).
     *
     * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
     */
    public function detectOutstandingExpenseClaims(string $administrationId): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $claims = $objectService
            ->setRegister($this->register())
            ->setSchema('ExpenseClaimEntry')
            ->findAll(
                [
                    'filters' => [
                        'administrationId' => $administrationId,
                        'approvalState'    => 'pending',
                    ],
                ]
            );

        $count      = 0;
        $totalCents = 0;
        foreach ($claims as $claim) {
            $claim = (array) $claim;
            $count++;
            $totalCents += (int) round(((float) ($claim['totalAmount'] ?? 0)) * 100);
        }

        return ['count' => $count, 'total' => ((float) $totalCents / 100)];

    }//end detectOutstandingExpenseClaims()

    /**
     * Format the detection summary into close-assistant flags (REQ-PC-004).
     *
     * Categories with zero detections produce no flag (a clean period yields an
     * empty flag list). Each flag is a warning except unreconciled bank receipts,
     * which are surfaced as informational (design D3 — "should verify").
     *
     * @param array<string,array{count:int,total:float}> $detections Per-category detection summaries.
     *
     * @return array<int,array<string,mixed>> The flag objects.
     *
     * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
     */
    public function generateFlags(array $detections): array
    {
        $now   = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $flags = [];

        $payable = ($detections['ap'] ?? ['count' => 0, 'total' => 0.0]);
        if ($payable['count'] > 0) {
            $flags[] = [
                'id'         => 'flag-ap',
                'severity'   => 'warning',
                'category'   => 'ap',
                'message'    => $payable['count'].' outstanding AP transaction(s) totalling '
                    .$this->euro(amount: $payable['total']).' require payment or accrual before period close',
                'detectedAt' => $now,
            ];
        }

        $receivable = ($detections['ar'] ?? ['count' => 0, 'total' => 0.0]);
        if ($receivable['count'] > 0) {
            $flags[] = [
                'id'         => 'flag-ar',
                'severity'   => 'warning',
                'category'   => 'ar',
                'message'    => $receivable['count'].' outstanding AR transaction(s) totalling '
                    .$this->euro(amount: $receivable['total']).' are not yet collected or provisioned',
                'detectedAt' => $now,
            ];
        }

        $bank = ($detections['bank'] ?? ['count' => 0, 'total' => 0.0]);
        if ($bank['count'] > 0) {
            $flags[] = [
                'id'         => 'flag-bank',
                'severity'   => 'info',
                'category'   => 'bank',
                'message'    => $bank['count'].' bank statement(s) in this period have no matching GL posting',
                'detectedAt' => $now,
            ];
        }

        $claims = ($detections['expense-claims'] ?? ['count' => 0, 'total' => 0.0]);
        if ($claims['count'] > 0) {
            $flags[] = [
                'id'         => 'flag-expense-claims',
                'severity'   => 'warning',
                'category'   => 'expense-claims',
                'message'    => $claims['count'].' expense claim(s) totalling '
                    .$this->euro(amount: $claims['total']).' are awaiting approval or reimbursement',
                'detectedAt' => $now,
            ];
        }

        return $flags;

    }//end generateFlags()

    /**
     * Format a euro amount for a flag message.
     *
     * @param float $amount The amount.
     *
     * @return string The formatted amount, e.g. "€5,200.00".
     */
    private function euro(float $amount): string
    {
        return '€'.number_format($amount, 2, '.', ',');

    }//end euro()

    /**
     * Extract the record id from an OpenRegister object's shape (top-level or @self).
     *
     * @param array<string,mixed> $record The record.
     *
     * @return string The id, or '' when absent.
     */
    private function idOf(array $record): string
    {
        $id = ($record['id'] ?? ($record['@self']['id'] ?? null));
        if ($id === null) {
            return '';
        }

        return (string) $id;

    }//end idOf()

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
