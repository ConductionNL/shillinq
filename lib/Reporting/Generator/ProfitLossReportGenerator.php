<?php

/**
 * Profit and loss (winst- en verliesrekening) report generator
 *
 * Produces a resultatenrekening over the period, rendered as editable DOCX/ODT
 * or as a dompdf PDF from the default in-code layout. Revenue and expense totals
 * are aggregated from the real Account objects in the shillinq register
 * (accountType revenue / expenses); per-account amounts come from the
 * materialised account balance or, when absent, from the posted GLLine movements
 * (credit raises revenue, debit raises expenses). The result for the period is
 * revenue minus expenses.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/reporting/spec.md#req-rpt-004
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use PhpOffice\PhpWord\PhpWord;

/**
 * Renders a profit-and-loss account from revenue/expense account totals.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class ProfitLossReportGenerator extends AbstractDocumentReportGenerator
{
    /**
     * The catalogue report-type id this generator produces.
     *
     * @return string
     */
    public static function reportType(): string
    {
        return 'profit-loss';

    }//end reportType()

    /**
     * Cover title for the document.
     *
     * @return string
     */
    protected function documentTitle(): string
    {
        return 'Winst- en verliesrekening';

    }//end documentTitle()

    /**
     * Build the W&V body: revenue, expenses, result, from account totals.
     *
     * @param PhpWord              $phpWord The styled document.
     * @param array<string, mixed> $context `{ reportType, period, administrationId }`.
     *
     * @return void
     */
    protected function build(PhpWord $phpWord, array $context): void
    {
        $administrationId = $this->str($context, 'administrationId');
        $accounts         = $this->loadAccounts($administrationId);

        $currency = 'EUR';
        foreach ($accounts as $account) {
            $accountCurrency = $this->str($account, 'currency');
            if ($accountCurrency !== '') {
                $currency = $accountCurrency;
                break;
            }
        }

        [$revenueLines, $expenseLines, $movements] = $this->classify($accounts, $administrationId);

        $totalRevenue = $this->total($revenueLines);
        $totalExpense = $this->total($expenseLines);
        $result       = ($totalRevenue - $totalExpense);

        $section = $this->addSection($phpWord);
        $this->addCover($section, 'Winst- en verliesrekening', 'Resultatenrekening over de periode', $context);

        // --- Opbrengsten ---
        $this->addHeading($section, 'Opbrengsten');
        $this->addAmountTable(
            $section,
            'Rekening',
            'Bedrag',
            $revenueLines,
            ['label' => 'Totaal opbrengsten', 'amount' => $totalRevenue],
            $currency
        );

        // --- Kosten ---
        $section->addTextBreak(1);
        $this->addHeading($section, 'Kosten');
        $this->addAmountTable(
            $section,
            'Rekening',
            'Bedrag',
            $expenseLines,
            ['label' => 'Totaal kosten', 'amount' => $totalExpense],
            $currency
        );

        // --- Resultaat ---
        $section->addTextBreak(1);
        $this->addHeading($section, 'Resultaat');
        $this->addAmountTable(
            $section,
            'Resultaatbepaling',
            'Bedrag',
            [
                ['label' => 'Totaal opbrengsten', 'amount' => $totalRevenue],
                ['label' => 'Totaal kosten', 'amount' => (-1 * $totalExpense)],
            ],
            ['label' => ($result >= 0.0 ? 'Resultaat (winst)' : 'Resultaat (verlies)'), 'amount' => $result],
            $currency
        );

        $section->addTextBreak(1);
        $this->addNote(
            $section,
            'Het resultaat is bepaald als het saldo van opbrengsten en kosten over de periode. '
            .'Bedragen zijn afgeleid uit de grootboekrekeningen van het type opbrengst en kosten'
            .($movements === true ? ', aangevuld met de geboekte grootboekmutaties.' : '.')
        );

        if ($revenueLines === [] && $expenseLines === []) {
            $this->addNote($section, 'Er zijn geen resultaatrekeningen gevonden voor deze administratie.');
        }

    }//end build()

    /**
     * Load the Account objects for the administration (all accounts when no
     * administration is supplied).
     *
     * @param string $administrationId The administration scope (may be empty).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadAccounts(string $administrationId): array
    {
        $filters = [];
        if ($administrationId !== '') {
            $filters['administrationId'] = $administrationId;
        }

        $accounts = $this->loadObjects('Account', $filters);
        if ($accounts === [] && $filters !== []) {
            $accounts = $this->loadObjects('Account');
        }

        return $this->dedupeAccounts($accounts);

    }//end loadAccounts()

    /**
     * Collapse the chart of accounts to one row per (administration, account key)
     * so an unscoped read does not double-count the same general-ledger account.
     * The first occurrence wins.
     *
     * @param array<int, array<string, mixed>> $accounts The Account objects.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dedupeAccounts(array $accounts): array
    {
        $seen = [];
        $out  = [];
        foreach ($accounts as $account) {
            $key = $this->str($account, 'administrationId').'|'.$this->accountKey($account);
            if (isset($seen[$key]) === true) {
                continue;
            }

            $seen[$key] = true;
            $out[]      = $account;
        }

        return $out;

    }//end dedupeAccounts()

    /**
     * Classify accounts into revenue and expense lines with their amounts.
     *
     * @param array<int, array<string, mixed>> $accounts         The Account objects.
     * @param string                           $administrationId The administration scope.
     *
     * @return array{0: array<int, array{label: string, amount: float}>, 1: array<int, array{label: string, amount: float}>, 2: bool}
     */
    private function classify(array $accounts, string $administrationId): array
    {
        $revenue   = [];
        $expense   = [];
        $usedMoves = false;
        $movements = null;

        foreach ($accounts as $account) {
            $type   = strtolower($this->str($account, 'accountType', 'type', 'category'));
            $bucket = match ($type) {
                'revenue', 'income', 'opbrengsten', 'baten' => 'revenue',
                'expenses', 'expense', 'kosten', 'lasten' => 'expense',
                default => null,
            };

            if ($bucket === null) {
                continue;
            }

            $amount = $this->accountAmount($account);
            if ($amount === null) {
                if ($movements === null) {
                    $movements = $this->deriveMovements($administrationId);
                }

                $usedMoves = true;
                $code      = $this->accountKey($account);
                $net       = ($movements[$code] ?? 0.0);
                // Net = debit - credit. Revenue is credit-natured, expense debit-natured.
                $amount = $bucket === 'revenue' ? (-1 * $net) : $net;
            }

            $label = $this->accountLabel($account);
            $line  = ['label' => $label, 'amount' => abs($amount)];
            if ($bucket === 'revenue') {
                $revenue[] = $line;
            } else {
                $expense[] = $line;
            }
        }//end foreach

        return [$revenue, $expense, $usedMoves];

    }//end classify()

    /**
     * Read a materialised amount/balance from a P&L Account, or null when absent.
     *
     * @param array<string, mixed> $account The Account object.
     *
     * @return float|null
     */
    private function accountAmount(array $account): ?float
    {
        foreach (['balance', 'currentBalance', 'closingBalance', 'periodTotal', 'amount', 'saldo'] as $key) {
            if (array_key_exists($key, $account) === true && is_numeric($account[$key]) === true) {
                return (float) $account[$key];
            }
        }

        return null;

    }//end accountAmount()

    /**
     * Derive net debit-minus-credit movements per account code from GLLine.
     *
     * @param string $administrationId The administration scope (may be empty).
     *
     * @return array<string, float> accountNumber => net (debit - credit).
     */
    private function deriveMovements(string $administrationId): array
    {
        $filters = [];
        if ($administrationId !== '') {
            $filters['administrationId'] = $administrationId;
        }

        $lines = $this->loadObjects('GLLine', $filters);
        if ($lines === [] && $filters !== []) {
            $lines = $this->loadObjects('GLLine');
        }

        $balances = [];
        foreach ($lines as $line) {
            $code = $this->str($line, 'accountNumber', 'accountId', 'account');
            if ($code === '') {
                continue;
            }

            $amount = $this->num($line, 'amount', 'baseCurrencyAmount', 'transactionAmount');
            $side   = strtolower($this->str($line, 'side', 'drcr'));
            $signed = $side === 'credit' ? (-1 * $amount) : $amount;

            $balances[$code] = (($balances[$code] ?? 0.0) + $signed);
        }

        return $balances;

    }//end deriveMovements()

    /**
     * The account's join key (accountNumber preferred).
     *
     * @param array<string, mixed> $account The Account object.
     *
     * @return string
     */
    private function accountKey(array $account): string
    {
        return $this->str($account, 'accountNumber', 'code', 'rgsCode', 'id');

    }//end accountKey()

    /**
     * A display label for an account row: "<number> <name>".
     *
     * @param array<string, mixed> $account The Account object.
     *
     * @return string
     */
    private function accountLabel(array $account): string
    {
        $number = $this->str($account, 'accountNumber', 'code', 'rgsCode');
        $name   = $this->str($account, 'name', 'accountName', 'title', 'description');
        $label  = trim($number.' '.$name);
        return $label !== '' ? $label : ('Rekening '.$this->str($account, 'id'));

    }//end accountLabel()

    /**
     * Sum the amounts in a set of lines.
     *
     * @param array<int, array{label: string, amount: float}> $lines The lines.
     *
     * @return float
     */
    private function total(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $row) {
            $sum += (float) ($row['amount'] ?? 0.0);
        }

        return $sum;

    }//end total()
}//end class
