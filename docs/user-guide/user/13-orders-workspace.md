---
sidebar_position: 13
title: Work with Orders
description: One Orders workspace for every kind of agreement — sales orders, purchase orders, bookings, grants/subsidies, quotes and engagements — with payments and lines tracked alongside.
---

# Work with Orders

Shillinq treats every commitment between you and another party as an **Order**. A
purchase order to a supplier, a sales order to a customer, a booking, a **grant /
subsidie**, a quote and a ZZP engagement are all the *same primitive* — they share a
counterparty, an amount, dates, a status and lines, and differ only in their **type**.

This replaces the separate Subsidies, Purchase Orders and Quote/Sales pages that used
to live in their own menus: there is now **one Orders workspace**, filtered by type.

## Goal

By the end you will know how to find, filter and open any order, read its payments
and lines, and understand how the former subsidie / purchase-order pages map onto it.

## Prerequisites

- Shillinq open with the OpenRegister back end connected (see [Open Shillinq for the first time](01-first-launch.md)).
- A counterparty (customer / supplier / grantee) reference available.

## The Orders list

Open **Orders** in the navigation. You get one list of every order in the
administration, with a **Type** filter:

| Type | What it is | Where it used to live |
| --- | --- | --- |
| `purchase` | A purchase order to a supplier | *Purchase Orders* |
| `sales` | A sales order to a customer | *Sales Orders* |
| `quote` | A quotation | *Quotes* |
| `booking` | A booked service (with its deposit-to-invoice flow) | *Bookings* |
| `grant` | A grant / subsidie (applied → granted → settled → paid → reclaimed) | *Subsidies* |
| `engagement` | A ZZP engagement (DBA model agreement) | *DBA* |
| `blanket` | A blanket/call-off framework order | *Blanket Orders* |

Pick a type from the filter to narrow the list; clear it to see everything. The
columns show the order number, type, counterparty, total amount, date and status.

## Opening an order

Click an order to open its detail. The page shows the order's own fields plus two
related sections:

- **Payments** — every payment against this order: a booking **deposit**, an
  installment, a grant **disbursement** (uitbetaald) or **reclaim** (teruggevorderd).
  Amounts are no longer fields *on* the order; they are Payment records, so the same
  payment widget works for every order type.
- **Lines** — the order lines (the unified successor of quote/sales-order lines).

## Grants (subsidies)

A grant is an order of type `grant`. Its grant-specific detail — scheme
(`regeling`), article, decision date (`beschikking`), settlement date
(`vaststelling`), performance report (`prestatieverantwoording`) and the
auditor-statement threshold — sits on the order alongside the shared fields. The
**awarded amount** is the order's total; the **paid out** and **reclaimed** amounts
are Payment records. The accountability report and auditor statement remain their own
records, linked to the order.

So the old *Aanvragen → Verleend → Vastgesteld → Uitbetaald → Teruggevorderd* pages
are now a single grant order moving through those states, with its payments listed
beneath it.

## Where did my old page go?

| Old page | Now |
| --- | --- |
| Subsidies → Aanvragen / Overzicht / Verleende / Terugvorderingen / R&D | Orders, filter **grant** |
| Purchase Orders | Orders, filter **purchase** |
| GR Deelnemers | the Customer / Vendor party (a contact) |

The underlying data was migrated automatically — nothing was lost; it is just shown
in one place now.

## Next steps

- [Create a purchase order](04-create-purchase-order.md) — raising a `purchase` order and three-way-matching it.
- [Manage a contract](06-manage-contract.md) — longer-running agreements.
