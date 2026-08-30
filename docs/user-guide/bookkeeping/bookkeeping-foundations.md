---
sidebar_position: 1
title: Bookkeeping foundations
description: The core concepts behind Shillinq — accounts, ledgers, double-entry bookkeeping, and the difference between a bill and an invoice.
---

# Bookkeeping foundations

Shillinq is a full double-entry bookkeeping system. This page explains the
concepts you need to understand before you create your first account or record
your first transaction.

## The general ledger (grootboek)

The **general ledger** is the master record of every financial transaction in
your organisation. Every time money moves — from sales, purchases, payroll,
taxes — the ledger captures it. In Dutch bookkeeping this is called the
*grootboek*.

The ledger is divided into named **accounts**. Each account tracks one specific
type of financial activity (e.g. "Revenue from consulting", "Office supplies",
"Bank — main account"). When you open the **Chart of Accounts** in Shillinq
you are looking at the index of your ledger.

## Accounts

An **account** in Shillinq is a named slot in your ledger that collects all
the transactions of one type. Each account has:

| Field | Example | Meaning |
|-------|---------|---------|
| `accountNumber` | `8010` | The RGS code (see [RGS account numbering](rgs-account-numbering.md)) |
| `accountName` | Revenue from consulting | Human-readable label |
| `accountType` | `revenue` | Balance sheet vs. income statement classification |
| `normalBalance` | `credit` | Whether the account grows on debit or credit |

Shillinq uses the **RGS** (Referentie Grootboekschema) numbering scheme by
default — a Dutch national standard that makes your accounts comparable to
those of any other Dutch company. See [RGS account numbering](rgs-account-numbering.md)
for a full explanation.

## Double-entry bookkeeping

Every financial transaction in Shillinq is recorded with **at least two
entries** — a debit and a credit — and these must always balance to zero. This
is called *double-entry bookkeeping* (*dubbel boekhouden*).

A simple example: you pay a €100 supplier invoice:

| Account | Debit | Credit |
|---------|-------|--------|
| Accounts payable (crediteuren) | €100 | |
| Bank — main account | | €100 |

The supplier account decreases (you no longer owe that money) and the bank
account decreases (the money left your account). Both sides equal €100 so the
entry balances.

You never need to enter debits and credits manually in Shillinq. When you
record a bill, create an invoice, or import a bank statement, Shillinq
generates the correct journal entries automatically based on the account type
of each line. The double-entry layer is there for auditors, accountants, and
financial reports — as a daily user you work with bills, invoices, and
bank transactions instead.

## Bills vs. invoices — the most important distinction

Shillinq uses two terms that sound similar but are opposites:

| Term | Who sends it | Who owes money | Accounting name | Dutch |
|------|-------------|----------------|-----------------|-------|
| **Bill** | Your supplier | **You** owe them | Accounts payable (AP) | Crediteur / inkoopfactuur |
| **Invoice** | **You** | Your customer owes you | Accounts receivable (AR) | Debiteur / verkoopfactuur |

### Bills (inkoopfacturen / crediteuren)

A **bill** is a purchase invoice that a supplier sent **to you**. You owe them
money. Shillinq records bills under **Accounts Payable** (AP):

- Found in: **Inkoop → Supplier invoices**
- Creates a liability: you owe the supplier
- Closed by: a payment from your bank account to the supplier

### Invoices (verkoopfacturen / debiteuren)

An **invoice** is a document you send **to a customer**. They owe you money.
Shillinq records invoices under **Accounts Receivable** (AR):

- Found in: **Bookkeeping → Accounts receivable**
- Creates an asset: the customer owes you
- Closed by: a payment from the customer into your bank account

## Bank transactions and reconciliation

When your bank statement arrives (or you import a CAMT.053 file), Shillinq
**matches** each bank line to an open bill or invoice. This process is called
**bank reconciliation** (*bankverzoening*). Once a bill is matched to a bank
payment, both are marked as settled and removed from the open-items list.

## The financial cycle in one picture

```
Supplier → Bill (AP) → You pay → Bank reconciliation → Settled
You → Invoice (AR) → Customer pays → Bank reconciliation → Settled
```

The three daily actions that keep your books up to date:

1. **Import bills** — record what you owe suppliers.
2. **Create invoices** — record what customers owe you.
3. **Import bank** — match payments on both sides.

Each of these has a step-by-step guide in the [Guides](../../guides) section.
