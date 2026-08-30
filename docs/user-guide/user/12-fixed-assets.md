---
sidebar_position: 12
title: Manage fixed assets
description: Capitalise a fixed asset, read its derived depreciation values (straight-line, declining-balance), run the parallel commercial / fiscal streams (Wet IB / Wet VPB), and dispose of it at sale — Shillinq emits the closing journal entry declaratively, no PHP service required.
---

# Manage fixed assets

Shillinq's **fixed-assets** (*vaste activa*) register holds every
capitalised tangible or intangible business asset — buildings,
vehicles, machinery, IT-equipment, furniture, intangibles — together
with its **depreciation rules** and **lifecycle state**. Per ADR-031
the depreciation values (`monthlyDepreciation`, `currentBookValue`,
`commercialBookValue`, `fiscalBookValue`) are **derived** on demand
from the asset's `acquisitionDate`, `acquisitionCost`,
`usefulLifeMonths`, `residualValue`, `depreciationMethod`, and the
parallel `commercialRate` / `fiscalRate` — Shillinq does not
materialise a `DepreciationSchedule` table per asset. The monthly
posting run is an OpenRegister `ScheduledWorkflow`, not a Shillinq
`TimedJob`.

The capability spec lives at
[`bookkeeping-fixed-assets-depreciation`](../../../openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md);
this page walks an SMB or ZZP bookkeeper through capitalising a
laptop, reading its derived monthly depreciation, and disposing of it
at sale.

## Goal

By the end of this story one `FixedAsset` is *active* in Shillinq:
its derived monthly depreciation reads on the detail page, the
parallel commercial / fiscal book values diverge under their
respective rates (Wet IB / Wet VPB), and disposal emits a balanced
closing `JournalEntry` — credits the asset account for its gross
value, debits accumulated depreciation, posts any gain or loss to
the configured P&L account.

## Prerequisites

- Shillinq open and the OpenRegister back end connected
  (see [Open Shillinq for the first time](01-first-launch.md)).
- The **chart of accounts** set up so the asset (0220), accumulated
  depreciation (0225), and depreciation expense (4500) accounts exist
  (see [Set up your chart of accounts](../admin/01-chart-of-accounts.md)).
- The **bookkeeping role** on the Shillinq instance (a `read`-only
  *auditor* user can open the detail page but cannot capitalise or
  dispose).
- A configured administration (the default `ADM-001` ships with the
  install).

## Step 1 — Capitalise the asset

1. Open Shillinq.
2. Navigate to **Bookkeeping > Fixed Assets**.
3. Click **Capitalise asset** (proposed → active is a two-step
   lifecycle by default; the operator can also create the record in
   `proposed` and approve it later).
4. Fill in the required fields:
   - **Asset number** *(activanummer)* — e.g. `FA-0001`.
   - **Name** *(naam)* — `Dell laptop XPS-15`.
   - **Asset category** — `it-equipment`.
   - **Acquisition date** — `2026-01-01`.
   - **Acquisition cost** — `2400.00` EUR.
   - **Useful life (months)** — `48` (4-year straight-line).
   - **Residual value** — `0.00`.
   - **Depreciation method** — `linear`.
   - **Asset account** — `0220`.
   - **Accumulated depreciation account** — `0225`.
   - **Depreciation expense account** — `4500`.
5. Click **Save**.

Result: a new `FixedAsset` in `lifecycleState: proposed`, or — if you
clicked **Capitalise & activate** — directly in `active`.

## Step 2 — Read the derived book values

1. Open the detail page (**Bookkeeping > Fixed Assets > FA-0001**).
2. The detail page renders the derived fields alongside the operator
   fields. For the worked example on 2026-07-01 (six months elapsed):
   - **Monthly Depreciation** — `50.00` EUR per month
     (`(2400 − 0) / 48`).
   - **Current Book Value** — `2100.00` EUR (`2400 − 6 × 50`).
   - **Commercial Book Value** — falls back to *Current Book Value*
     when no `commercialRate` is set.
   - **Fiscal Book Value** — same fallback when no `fiscalRate` is
     set.

### Worked example — parallel Wet IB / Wet VPB streams

If the same asset is configured with `commercialRate: 0.20` (5-year
commercial life under Dutch GAAP) and `fiscalRate: 0.33` (3-year
fiscal life under Wet IB), the detail page renders **both** book
values, and they diverge:

- After 12 months **Commercial Book Value** reads
  `10000 × (1 − 0.20 × 1) = 8000.00`.
- After 12 months **Fiscal Book Value** reads
  `10000 × (1 − 0.33 × 1) = 6700.00`.

This is the parallel-stream behaviour every Dutch SMB needs when its
fiscal book runs ahead of (or behind) its commercial book — most
commonly under the *Wet IB* 20 % fiscal cap on certain asset classes
or under MIA/Vamil accelerated depreciation.

## Step 3 — Monthly depreciation runs by itself

A monthly `ScheduledWorkflow` (registered as
`shillinq-fixed-assets-monthly-depreciation`) walks every `FixedAsset`
with `lifecycleState: active` and posts a balanced `GLTransaction` per
asset — debit `4500 Depreciation expense`, credit
`0225 Accumulated depreciation` — using the derived
`monthlyDepreciation` field for the period. The schedule itself stays
implicit; Shillinq does **not** materialise a per-month
`DepreciationSchedule` table.

Operators reconfigure the cadence through the OpenRegister
`ScheduledWorkflow` admin UI (default interval: 30 days).

## Step 4 — Dispose of the asset

When the laptop is sold (e.g. on 2030-01-01 for `200.00` EUR):

1. Open the detail page.
2. Click **Dispose asset**.
3. Fill in:
   - **Disposal date** — `2030-01-01`.
   - **Disposal treatment** — `sale`.
   - **Disposal proceeds** — `200.00`.
4. Confirm.

The lifecycle transitions `active → disposed` and the declarative
`emit-journal-entry` action posts a balanced closing journal:

- Credit `0220 Asset` for `2400.00` (reverse the gross value).
- Debit `0225 Accumulated depreciation` for the accumulated
  depreciation balance.
- Debit the disposal clearing account for the `200.00` proceeds.
- Debit *boekverlies vaste activa* for the residual loss (or credit
  *boekwinst vaste activa* if proceeds exceed book value).

The journal lines always balance (sum of debits == sum of credits, in
integer cents) — verified by the unit-tested kernel
`DisposalJournalEmitter`.

## Verify

- The detail page renders `currentBookValue`, `monthlyDepreciation`,
  `commercialBookValue`, `fiscalBookValue`.
- The Audit Trail tab shows the lifecycle transitions (create →
  activate → dispose) with actor, timestamp, and before/after
  snapshot.
- The closing journal shows on the General Ledger page with
  `transactionType: disposal` and `sourceReference:
  fixed-asset:FA-0001`.

## Troubleshooting

- **Monthly Depreciation reads `0.00`** — `depreciationMethod` is set
  to `none` (intentional: no automatic posting happens). Switch to
  `linear` or `degressive` to activate.
- **The disposal action button is hidden** — the operator is in the
  `auditor` role (read-only) or the asset is already in
  `lifecycleState: disposed` / `archived`.
- **Closing journal lines don't balance** — should not happen; the
  emitter is unit-tested against the REQ-FA-006 worked examples. If
  it ever does, file a bug against
  `bookkeeping-fixed-assets-depreciation` with the asset's
  `acquisitionCost`, `acquisitionDate`, current book value, and the
  proceeds entered.

## What's next

- Configure the parallel **Wet IB / Wet VPB** rates per asset
  category via the operator UI.
- Wire the monthly `ScheduledWorkflow` to an n8n workflow that emails
  the bookkeeper a summary of the depreciation postings on each tick.
- For investment-deduction (KIA / EIA / MIA / Vamil) eligibility see
  [`bookkeeping-investeringsaftrek`](../../../openspec/changes/bookkeeping-investeringsaftrek)
  — the overlay register classifies the asset against the four
  schemes.
