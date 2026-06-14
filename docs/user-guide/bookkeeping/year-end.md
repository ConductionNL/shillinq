---
sidebar_position: 12
title: Year-end close & period management
description: Close accounting periods, run year-end entries, manage fiscal years, and reconcile subsidiary ledgers in Shillinq.
---

# Year-end close & period management

Shillinq supports a structured **period-close process** — from daily reconciliation through to annual account finalisation. This page covers closing entries, fiscal year configuration, reconciliation schedules, and the year-end checklist.

## Fiscal years

Go to **Bookkeeping → Fiscal years** to see all configured fiscal years and their close status.

| Status | Meaning |
|--------|---------|
| Open | Transactions can still be posted |
| Soft-closed | No new transactions; adjustments require override |
| Hard-closed | Fully locked; no changes allowed |

To **open a new fiscal year**: click **+ New fiscal year**, set the start date (01-01 for most Dutch companies) and end date (31-12), and save.

To **close a fiscal year**: use the **Year-end close checklist** (see below). Shillinq will only allow hard-close once all checklist items are completed.

## Year-end close checklist

The checklist guides you through the standard close steps in order:

1. **Reconcile bank** — confirm bank statement is imported and all items matched.
2. **Reconcile debtors** — AR balance = sum of open invoices.
3. **Reconcile creditors** — AP balance = sum of open bills.
4. **Post depreciation** — all fixed asset depreciation entries for the year are posted.
5. **Post accruals** — year-end accruals and prepayments are journalised.
6. **Run trial balance** — no unexplained differences.
7. **Tax provisions** — VPB and deferred tax are calculated and posted.
8. **Closing entries** — P&L balances transferred to retained earnings.
9. **Final trial balance** — re-run after closing entries.
10. **Hard-close** — lock the fiscal year.

Each step has a status indicator. You can mark steps complete manually or let Shillinq verify them automatically.

## Closing entries

**Closing entries** (*sluitboekingen*) transfer the year's profit or loss from the income statement (P&L) accounts to equity. After closing:
- All revenue and expense accounts reset to zero for the new year.
- The net result posts to **Retained earnings** (eigen vermogen) on the balance sheet.

Shillinq generates the closing entries automatically from the trial balance. Review them, then post.

## Reconciliations

The **Reconciliations** page tracks subsidiary-ledger reconciliations — comparing sub-ledger totals (open invoices, fixed asset register, payroll) against the corresponding GL account balance.

Each reconciliation record shows:
- The GL account being reconciled
- The sub-ledger total
- The GL balance
- The difference (should be zero)
- The last reconciliation date and who performed it

## Unmatched items

The **Unmatched items** page lists bank transactions that could not be matched during reconciliation. Review and manually assign each item before closing the period.

## Variance report

The **Variance report** compares actual amounts to budget or to the same period last year. Use it for management review before closing the period.

## Related

- [General ledger](general-ledger.md) — view all posted transactions
- [Trial balance](trial-balance.md) — verify the books balance
- [Balance sheet](balance-sheet.md) — the financial position after close
