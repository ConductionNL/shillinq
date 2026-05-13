---
sidebar_position: 8
title: Read your financial statements
description: Generate the P&L, balance sheet, and cash-flow statement for a period — drill into a line, export to SBR / XBRL or PDF.
---

# Read your financial statements

Generate the three financial statements — profit & loss, balance sheet, cash-flow — for any period from the live ledger. Drill from any line into the underlying postings, and when the period closes, export to SBR / XBRL (for the KvK / Belastingdienst filings) or to PDF for the annual report.

## Goal

By the end you will have looked at the P&L, balance sheet, and cash-flow statement for a period, drilled into at least one line to see the underlying postings, and exported one of them — either to SBR / XBRL for filing, or to PDF for sharing.

## Prerequisites

- Shillinq open and the OpenRegister back end connected (see [Open Shillinq for the first time](01-first-launch.md)).
- The right to view reports — bookkeeping or read-only finance role on the Shillinq instance.
- The period's invoices, bills, payroll (if any), and bank reconciliation are all complete (see [Reconcile a bank statement](05-bank-reconciliation.md)).
- The **chart of accounts** is mapped to RGS / BBV codes so the statement layout slots accounts into the right groups (see [Set up your chart of accounts](../admin/01-chart-of-accounts.md)).

## Steps

1. Open **Reports → Financial statements**. Pick the period and the reporting basis (statutory / management). Shillinq renders the three statements live off the ledger.

   ![Financial statements landing](/screenshots/tutorials/user/08-financial-statements-01.png)

2. Read the **Profit & Loss**. Revenue at the top, cost of sales, gross profit, operating expenses, operating profit, financial items, profit before tax, tax, net profit. Each line is a group from the chart of accounts; click into a group to see its accounts; click an account to see the postings.

   ![Profit and loss statement](/screenshots/tutorials/user/08-financial-statements-02.png)

3. Read the **Balance sheet**. Assets (fixed, current) and the offset (equity, long-term liabilities, current liabilities). Total assets equals total liabilities + equity to the cent — the running self-test on the page asserts this; a mismatch flags as red.

   ![Balance sheet](/screenshots/tutorials/user/08-financial-statements-03.png)

4. Read the **Cash flow statement**. Operating activities, investing activities, financing activities. Shillinq derives this indirectly from the P&L and the balance-sheet movements. Drill into any line to see the underlying entries.

   ![Cash flow statement](/screenshots/tutorials/user/08-financial-statements-04.png)

5. **Export**. Pick *PDF* for the annual report layout (BW Boek 2 Titel 9 disclosure structure for the statutory version), or *SBR / XBRL* for the filing channel — the SBR taxonomy slots the line values into the right XBRL elements automatically, ready to file with the KvK or the Belastingdienst.

   ![Export dialog — PDF or SBR/XBRL](/screenshots/tutorials/user/08-financial-statements-05.png)

## Verification

The three statements render for the period you picked. The balance sheet balances (assets = equity + liabilities). The cash-flow statement reconciles to the bank movements (opening + net flow = closing). The PDF export carries the right legal layout; the SBR / XBRL export passes the KvK's online validator.

## Common issues

| Symptom | Fix |
|---|---|
| Balance sheet doesn't balance | Usually an unposted opening balance for a new account, or a half-posted journal — Shillinq's *Trial balance* report under **Reports** shows where the imbalance sits. |
| Statement layout looks wrong (one line groups two unrelated things) | The chart-of-accounts → RGS mapping is wrong on the offending account; fix the mapping (see [Set up your chart of accounts](../admin/01-chart-of-accounts.md)) and reload. |
| Cash flow doesn't reconcile | The opening cash balance for the period is wrong, or the bank reconciliation isn't complete — close the reconciliation first. |
| SBR / XBRL export fails KvK validation | A required disclosure note is missing (typical: notes-to-the-balance-sheet table that the statutory layout demands); add it on the annual-report screen and re-export. |
| PDF export missing a section | The statement layout template doesn't include it for the chosen reporting basis (statutory vs management); pick the right basis. |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Prepare a VAT return](07-vat-return.md) — VAT settlement entries flow through the P&L and balance sheet.
- [Reconcile a bank statement](05-bank-reconciliation.md) — bank movements drive the cash-flow statement.
- [Set up your chart of accounts](../admin/01-chart-of-accounts.md) — the RGS / BBV mapping that drives statement layout.
- [Shillinq architecture overview](../../ARCHITECTURE.md) — BW Boek 2 Titel 9, RGS, SBR, XBRL, BBV, IV3.
