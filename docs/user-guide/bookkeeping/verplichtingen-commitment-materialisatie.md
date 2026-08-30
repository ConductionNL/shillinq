---
sidebar_position: 8
title: Commitment Auto-Materialisation & Budget-Line Report
description: How approving a purchase order or activating a contract automatically raises a Verplichting, and how to read the committed-vs-realised report per budget line.
---

# Commitment Auto-Materialisation & Budget-Line Report

Dutch `verplichtingenadministratie` (BBV / Comptabiliteitswet) requires
that budget is consumed **when the organisation becomes legally
bound** — a purchase order is approved, a contract is signed — not
when the invoice later arrives. This guide covers the two additions
that close that loop: automatic commitment creation from approvals,
and a per-budget-line committed-vs-realised report.

## Goal

By the end of this guide you will understand when a `Verplichting` is
created automatically, what happens when budget is insufficient, and
how to read the committed-vs-realised report for a budget line.

## Prerequisites

- A Nextcloud account with **Shillinq** installed and enabled.
- The `bookkeeping-verplichtingenadministratie` capability configured
  for your administration (Mandates and Budgets set up under
  **Commitments**).

## Section 1 — Automatic commitment creation

When a purchase order reaches **Goedgekeurd** (approved), or a
contract reaches **Active**, shillinq automatically raises a matching
`Verplichting` (commitment) — you no longer need to open one by hand.
The commitment carries a `Source reference` back to the originating
purchase order or contract, and one budget line (`Verplichtingsregel`)
per budget coding combination (programme + cost centre + fiscal year +
GL account).

- **Multi-year orders.** When a purchase order's lines are dated
  across several fiscal years, one budget line is created per year,
  each reserving budget independently.
- **Repeated approvals.** Re-approving the same order never creates a
  duplicate commitment — the source reference is the idempotency key.
- **Insufficient budget.** If there is not enough free budget room and
  no override-mandate applies, the purchase-order approval itself is
  denied with the existing "insufficient budget" message — the
  commitment is never created unfunded.
- **Override-mandate.** When an override-mandate holder (e.g. the CFO)
  covers the amount, the commitment is created anyway, the override
  reason is recorded on the commitment (`Override reason` field), and
  it is also raised as an afwijking (deviation) visible to the
  rechtmatigheid (legitimacy) reporting for that fiscal year.
- **No sufficient mandate.** When no mandate at all covers the
  amount, the commitment is created in **In goedkeuring** (pending
  approval) status, exactly as if it had been raised manually.

## Section 2 — Committed vs. realised per budget line

Navigate to **Commitments → Committed vs. realised**. The table shows,
for every budget coding combination, four columns:

| Column | Meaning |
|---|---|
| Authorized | The authorised budget for this line (from the matching Budget record). |
| Committed | Outstanding committed amount across open commitments on this line. |
| Realised | Amount already invoiced against this line. |
| Available | Authorized − Committed − Realised. |

Click a row to drill down into the underlying commitments contributing
to that budget line. This extends the existing per-programme BBV
columns to per-line granularity, so a controller can immediately see
*which* budget line is over-committed, not just which programme.

## Troubleshooting

- **A purchase-order approval fails with an "insufficient budget"
  error.** This is expected fail-closed behaviour — either request an
  override-mandate, or reduce the order amount / wait for budget room
  to free up.
- **No rows appear in the committed-vs-realised report.** No
  commitments have been raised yet for this administration — approve
  a purchase order or activate a contract first.
