---
sidebar_position: 1
title: Match a bank line by hand
description: Pair a bank line with the invoices it pays, or book it to a ledger account, when no matching rule found it.
---

# Match a bank line by hand

A matching rule finds most bank lines on its own. The rest wait on **Banking > Unmatched bank lines** until someone says what they are.

## Pair a line with its invoices

1. Open **Unmatched bank lines** and choose **Match by hand** on the line.
2. The **Invoices** tab lists the open invoices of the line's administration: sales invoices for money in, supplier invoices for money out. Invoices with exactly the line's amount come first. Search by number, counterparty or amount.
3. Tick the invoices the line pays and choose **Confirm**.

Several invoices must add up to the line. If they add up to more, or to less, Shillinq refuses and tells you the difference. One invoice larger than the line is a part payment: the match is recorded as partial and the dialog tells you what remains open.

When you confirm, the invoices move to paid. A supplier invoice paid in part moves to partially paid. A sales invoice paid in part stays issued, because a sales invoice has no partially paid state; its open amount follows from its matches.

## Book a line to a ledger account

For bank costs, interest and other lines without an invoice:

1. Choose **Match by hand** on the line and open the **Ledger account** tab.
2. Pick the account, for example 4910 Bankkosten. For a line that includes VAT, pick the VAT rate and the VAT account.
3. Choose **Confirm**.

Shillinq posts a balanced journal entry between the bank account's ledger account and the account you picked, with the VAT on its own line. The bank account needs a ledger account for this; set it on the bank account first if Shillinq asks for it.

## Classify what stays open

On **Banking > Unmatched items**, select items and choose **Classify as timing**, **Classify as pending** or **Classify as adjustment**. Shillinq asks for one reason for the whole selection and saves it on every item.

Next: close the statement from its detail page once every line is matched or classified.
