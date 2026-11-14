---
sidebar_position: 2
title: Expense categories
description: Map expense categories to ledger accounts so an approved expense claim posts itself, and set spending limits per category.
---

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

# Expense categories (kostensoorten)

An **expense category** names the ledger account its receipts post to. A
consultant picks a category on a receipt; when the bookkeeper posts the
approved expense claim, every line finds its account by itself. Nobody types
an account number.

## Where to find them

Open the **settings gear** at the bottom of the navigation and choose
**Expense categories**. The list shows each category's code, its Dutch and
English name, the expense account (number, name and type side by side), the
VAT deduction rule and whether it is active. Click a row to open and edit it.

Each administration has its own list.

## The seeded list

An administration on the RGS 3.5 MKB chart of accounts receives these
categories, but only where its chart has the account as an expense account:

| Code | Name | Account | VAT deduction |
|---|---|---|---|
| travel-public | Openbaar vervoer | 4510 OV-reiskosten | full |
| travel-abroad | Buitenlandse reis | 4520 Buitenlandse reizen | full |
| fuel | Brandstof | 4210 Brandstof | full |
| meals | Maaltijden en representatie | 4440 Representatiekosten | none (food and drink in horeca) |
| office | Kantoorartikelen | 4310 Kantoorartikelen | full |
| phone | Telefoon en internet | 4330 Telefoon en internet | full |
| software | Software en abonnementen | 4340 Software en SaaS-abonnementen | full |
| training | Opleiding en cursus | 4050 Personeelsopleidingen | full |
| other | Overige personeelskosten | 4060 Overige personeelskosten | full |

Change the account of a category, add your own, or switch one off with
**Active**. Only active categories are offered on a receipt.

### Only expense accounts

Saving a category is refused when its account does not exist in the
administration's chart or is not an expense account. Setting a category to
**1100 Debiteuren**, for example, is refused with the message that account 1100
is not an expense account. A change of account applies to claims posted after
the change; the **Audit Trail** tab records who changed it and when.

## Mileage, per diem and the claim total

Three accounts are not tied to a category. Set them on the administration
(**Settings gear → Administraties**, open the administration):

| Setting | Default on RGS | What posts there |
|---|---|---|
| Mileage account | 4530 Kilometervergoeding | every mileage line |
| Per diem account | 4060 Overige personeelskosten | every per diem line |
| Expense payable account | 2200 Personeelsschulden | the claim total owed to the employee |

## Choosing a category on a receipt

On the receipt form the **Expense category** field is a list of the
administration's active categories, by name. Type part of the name or the code
to narrow it down. A lunch with a client of EUR 37.50 goes under
**Maaltijden en representatie**.

A receipt can also carry an account an operator confirmed by hand (for
example after accepting the docudesk suggestion). That confirmed account wins
over the category's account. A docudesk suggestion is never used until a
person confirms it.

## How a claim posts

When the bookkeeper posts an approved claim, each line takes its account in a
fixed order:

1. a receipt: the account confirmed on the receipt, else its category's account;
2. a mileage line: the administration's mileage account;
3. a per diem line: the administration's per diem account;
4. the claim total: the administration's expense payable account (credit).

When the category deducts VAT (**full**), the receipt's VAT amount goes to
input VAT and the rest to the expense account. When it deducts none, the whole
amount goes to the expense account.

**Example.** S. de Vries claims a train ticket of EUR 42.80 including EUR 3.53
VAT (Openbaar vervoer), a client lunch of EUR 37.50 (Maaltijden en
representatie) and 86 km at EUR 0.23. The posting debits 4510 EUR 39.27, input
VAT EUR 3.53, 4440 EUR 37.50 and 4530 EUR 19.78, and credits 2200 EUR 100.08.

**A line without an account stops the post.** A receipt left
**uncategorised** without a confirmed account refuses the post with a message
naming that receipt, and the claim stays approved. Pick a category (or confirm
an account) and post again.

## Spending limits per category

A **reimbursement policy** (**Settings gear → Reimbursement Policies**) can
carry a limit per category, per receipt line or per night. When an employee
submits a claim, every receipt is checked against the limit of its category:

- a line over its limit holds the claim for approval, and the claim lists the
  line with the limit it passed (for example a EUR 210 hotel night against a
  limit of EUR 150 per night);
- a claim within every limit goes on without that step.

## Receipts from before categories

On the update that introduced categories, the free text already in the
receipts' category field was mapped once: an exact code, then a Dutch or
English category name, then a known word (reiskosten, trein, lunch, maaltijd,
parkeren and others). A receipt whose text matched nothing is set to
**uncategorised**; the update lists those receipts with their original text in
the upgrade log for a bookkeeper. Filter the **Receipts** list on category
`uncategorised` to pick a category for each.
