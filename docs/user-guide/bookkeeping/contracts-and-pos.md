---
sidebar_position: 2
title: Contracts and performance obligations
description: Add a contract, define its performance obligations, allocate the transaction price, and record modifications with full audit trail.
---

# Contracts and performance obligations

A revenue contract (`RevenueContract`) in Shillinq is the IFRS 15.9 legal
instrument that grants the customer enforceable rights to goods or services —
distinct from the generic `Contract` record under **Procurement Contracts**
used for contract-lifecycle management (purchase, service, lease, …). Every
revenue contract carries one or more **performance obligations** — distinct
goods or services satisfied at a point in time or over time.

## Add a contract

1. Open **Bookkeeping → Revenue Recognition (IFRS 15) → Revenue Contracts**.
2. Click *Add contract* and fill in:
   - `contractNumber` — a unique identifier (e.g. C-2026-001).
   - `customerId` — pick from the Nextcloud contact list. Customer is **not**
     a bespoke Shillinq schema; Shillinq reuses the NC contact entity per
     ADR-022.
   - `signedAt`, `startDate`, `endDate` — ISO dates.
   - `fixedConsideration` — guaranteed cash/near-cash component.
   - `variableConsideration` — optional estimate; constrained per IFRS 15.56
     in the next step.
   - `currency` — defaults to EUR; multi-currency translation is T5.
   - `quoteOrderReference` — FK to the originating quote / sales order in
     the quote-to-cash module.
   - `contractGroupId` — optional, used to flag contracts that should be
     combined for revenue recognition per IFRS 15.17.
   - `lifecycleState` — `draft` on creation.

## Add performance obligations

In the contract detail panel switch to *Performance Obligations* and click
*Add PO*. Each PO carries:

- `description`,
- `distinctFlag` — IFRS 15.27 distinct test result,
- `satisfactionPattern` — `point-in-time` or `over-time`,
- `outputMethod` — when satisfaction is over-time and tracked by output:
  `units-delivered`, `milestones`, `time-elapsed`, `percentage-of-completion`,
- `inputMethod` — when satisfaction is over-time and tracked by input:
  `cost-to-cost`, `labour-hours`, `machine-hours`, `units-produced`,
- `sspAmount` — stand-alone selling price,
- `allocatedPrice` — derived by the relative-SSP allocation.

For a cost-to-cost PO point the `costBasis` field at the project-accounting
module so the % complete updates automatically on fresh timesheet entries.

## Allocate the transaction price

Open *Price Allocation*. Shillinq computes the relative-SSP allocation per
IFRS 15.74 by default. For a PO whose SSP is highly variable, switch the
allocation method on its row to `residual` per IFRS 15.79.

The allocation re-runs on contract modification or variable-consideration
re-estimation; the prior allocation snapshot is preserved on the
`PriceAllocation` row for the audit trail.

## Approve a contract modification

Open the contract → *Add Modification*. Pick the classification:

- **new-contract** — adds distinct scope priced at SSP (IFRS 15.20(a));
  Shillinq creates a fresh `RevenueContract` row leaving the original untouched.
- **prospective** — price-only change; the allocation re-runs forward.
- **not-distinct-cumulative** — scope change not distinct from existing POs;
  the prior+new cumulative is recomputed and a catch-up posted.

Every modification persists a `ContractModification` row with before/after
snapshots, classification reason, operator, and timestamp. The audit trail
is queryable from the contract detail view.

## Sign and move to delivery

Use the *Sign* lifecycle action on the contract. The state moves
`draft → signed`. *Begin delivery* moves to `in-delivery`. *Complete*
(or *Cancel*) closes the lifecycle. Each transition fires the materialisation
on `RevenueRecognitionEvent` so the GL captures the recognition event.

## Where to read next

- [Revenue waterfall](revenue-waterfall.md)
- [Contract balances](contract-balances.md)
- [Revenue Recognition API](../../api/revenue-recognition.md)
