---
sidebar_position: 1
title: Set up your chart of accounts
description: Pick (or import) a chart of accounts, map it to RGS / BBV, and configure VAT rates so every invoice and bill posts to the right place.
---

# Set up your chart of accounts

The chart of accounts is the spine of everything bookkeeping does in Shillinq, every invoice line, every bill line, every bank transaction has to land in an account. Shillinq ships starter charts (general SMB, ZZP, government, BBV) you can import and customise; or you can import your own.

## Goal

By the end the instance will have a chart of accounts with each account mapped to its RGS / BBV reference code, VAT rates configured against the taxable accounts, and starter records (cash account, bank account, debtors, creditors, VAT payable, VAT recoverable, retained earnings) live.

## Prerequisites

- Admin on the Nextcloud instance (or the Shillinq admin role).
- The **OpenRegister** app installed and enabled with the Shillinq register imported.
- A view on which starter chart fits the organisation, general SMB, ZZP, government (BBV), or a custom one.

## Steps

1. Open **Settings → Administration → Shillinq** and switch to the **Chart of accounts** section. Pick *Import starter* if you want one of the prebuilt charts, or *Add account* to build from scratch.

   ![Chart of accounts settings](/screenshots/user-guide/admin/01-chart-of-accounts-01.png)

2. Importing a starter (recommended): pick *General SMB*, *ZZP*, or *Government (BBV)*. Shillinq creates the account records, the account groupings (revenue / cost of sales / operating expenses / fixed assets / current assets / equity / liabilities), and the RGS code mapping in one go. Re-running the import on an existing chart adds missing accounts; it doesn't overwrite edits you made.

   ![Starter import](/screenshots/user-guide/admin/01-chart-of-accounts-02.png)

3. Review the chart. Each account has a **number** (e.g. 8000 for revenue), a **name**, a **type** (revenue / expense / asset / liability / equity), a **VAT-default** (the rate that applies when this account is picked on a line), and an **RGS code** (or **BBV taxonomy code** for government). Edit anything that doesn't match how the organisation books things.

   ![Chart of accounts table](/screenshots/user-guide/admin/01-chart-of-accounts-03.png)

4. Configure **VAT rates**. The Dutch defaults (21%, 9%, 0%, vrijgesteld, verlegd) come pre-configured. Verify each rate's GL account (VAT payable / VAT recoverable per rate), and toggle *Reverse-charge* on the *verlegd* rate so it flows into the right VAT-return box.

   ![VAT rate configuration](/screenshots/user-guide/admin/01-chart-of-accounts-04.png)

5. **Set the opening balances**. For each balance-sheet account, enter the opening balance for the start of the bookkeeping period. The sum of all opening balances must net to zero (debits = credits); Shillinq runs the assertion on save. Once that passes, the chart is ready and **Save** unlocks invoicing / bills / banking.

   ![Opening balances entered, balance assertion green](/screenshots/user-guide/admin/01-chart-of-accounts-05.png)

## Verification

The chart shows every account with its type, RGS code, and VAT default. The VAT rates list shows the configured rates mapped to their GL accounts. The opening-balances grid totals to zero. A test invoice can pick a revenue account; a test bill can pick an expense account; the resulting journal posts cleanly.

## Common issues

| Symptom | Fix |
|---|---|
| Starter import skipped some accounts | The chart already has accounts at those numbers, the import is non-destructive; either edit the existing accounts, or rename the conflicting ones and re-import. |
| RGS mapping field is empty for the public-sector chart | BBV taxonomy lookup is separate from RGS; on a government install, the BBV column is filled instead of RGS, both are visible on the account record. |
| Opening balance assertion fails | The balance sheet must net to zero, the debit column total must equal the credit column total. The retained-earnings (eigen vermogen) account usually picks up the rounding. |
| VAT-default on an account doesn't flow onto a new line | The account is a balance-sheet account (no VAT applies), only revenue/expense accounts carry a meaningful VAT default. |
| Account number reuse | Account numbers are unique per organisation; Shillinq rejects a duplicate on save. |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Send your first invoice](../user/02-send-invoice.md), the revenue accounts the chart provides.
- [Record a supplier bill](../user/03-record-bill.md), the expense accounts the chart provides.
- [Prepare a VAT return](../user/07-vat-return.md), the VAT-rate / box mapping flows from here.
- [Read your financial statements](../user/08-financial-statements.md), the chart's RGS / BBV codes drive the statement layout.
- [Shillinq architecture overview](../../Technical/architecture.md), RGS, BBV, IV3, Wet OB.
