---
sidebar_position: 1
title: BBV Compliance Dashboard
description: Monitor your province's BBV budget health in real-time — KPI cards, budget-vs-actuals charts, traffic-light status, and exception alerts for the seven canonical programme structures.
---

# BBV Compliance Dashboard

Monitor your province's BBV budget health in real-time.

The BBV Compliance Dashboard surfaces the four spending KPIs (total
budget, committed, spent, remaining), the budget-vs-actuals bar chart,
the cumulative spend trend, and an exceptions block highlighting
overspent programmes — all keyed to the seven canonical BBV programme
structures used by Dutch provinces (`ruimte`, `mobiliteit`, `water`,
`milieu`, `cultuur`, `economie`, `bestuur`).

## Goal

By the end of this guide you will be able to read the four KPI cards,
interpret the traffic-light status, drive the filters to scope the
dashboard to a single programme or fiscal year, and act on the
exceptions block when a programme runs over budget.

## Prerequisites

- A Nextcloud account with **Shillinq** installed and enabled.
- The active administration's `administrationType` is `provincie` —
  the dashboard navigation entry is hidden for non-provincie
  administrations (manifest `visibilityPredicate`).
- At least one `Budget` record with a `programmaStructure` and one or
  more posted or committed `GLLine` records. The
  `bbv-provincies-budgets-2026.json` seed (task 21) provides a sample
  set; replace with real budgets in production.

## Open the dashboard

1. Open **Shillinq → BBV Provincie → BBV Compliance Dashboard**.
2. The route is `/bbv-provincie/compliance-dashboard`.
3. If the navigation entry is not visible, confirm the active
   administration's `administrationType` is `provincie`
   (administrative settings).

## Section 1 — Dashboard components

The dashboard mounts three regions, in this order.

### KPI cards

Four cards at the top of the dashboard:

- **Total budget** — sum of `totalAmount` across `Budget` records
  matching the active fiscal year filter.
- **Committed** — `programmeBudgetVsActuals` credit-side aggregation
  on `GLLine` (orders placed but not yet paid).
- **Spent** — `programmeBudgetVsActuals` spend aggregation on `GLLine`
  (posted spend).
- **Remaining** — `totalAmount − committed − spent`, with a
  traffic-light badge applied (see Section 2).

All amounts render as EUR with the EU thousands / decimal separators.

### Charts

- **Budget vs. actuals** — a horizontal bar chart, one bar per
  programme, with budget and spent as the two series. The chart
  re-queries when a filter is applied.
- **Trend** — a cumulative monthly spend line. Months with zero
  postings render as zero rather than being omitted, so the line
  remains visually continuous across the fiscal year.

### Exceptions block

A list of every programme whose `remaining` is negative, sorted
ascending (most overspent first). Each row links to the
Budget-to-Programme Linker for remediation. When no programme is over
budget the block shows the empty state `No overspends`.

## Section 2 — Interpreting the traffic-light status

Each programme card and the Remaining KPI carry a traffic-light badge
driven by the declared thresholds in
`src/manifest.d/bookkeeping-provincies-bbv-variant.json`:

| Status | Rule | Operator action |
| ------ | ---- | --------------- |
| Green | `remaining / totalAmount ≥ 0.15` (more than 15% of the budget left) | None — programme is healthy. |
| Yellow | `0 ≤ remaining / totalAmount < 0.15` (less than 15% headroom) | Review committed orders; consider scaling new spend. |
| Red | `remaining < 0` (overspent) | Address via the Budget-to-Programme Linker — re-map a misallocated GL line, or amend the budget. The exceptions block surfaces the programme automatically. |

The thresholds are declarative — never hard-coded in Vue — so a future
policy change (e.g. yellow at 10% instead of 15%) is a manifest edit
plus an app version bump, not a code release.

## Section 3 — Using filters

The dashboard exposes three filter facets, declared in the manifest:

- **Programme** — multi-select across the seven BBV programme
  structures. An empty selection means "all programmes".
- **Fiscal year** — single-select; defaults to `@currentFiscalYear`.
  The list is sourced from the distinct `fiscalYear` values on
  `Budget`.
- **Budget status** — multi-select across `approved`, `provisional`,
  `amended`. Defaults to all-active (provisional + approved); amended
  is opt-in.

Filters cumulate (AND logic) — selecting `mobiliteit` plus fiscal year
`2026` plus status `approved` reduces the dashboard to one programme,
one year, one budget posture. The bar chart, trend chart, and
exceptions block all re-query when a filter changes.

## Section 4 — Troubleshooting

### Dashboard appears empty / KPI cards all read €0

- Confirm the active administration is a `provincie`. The
  `visibilityPredicate` hides the dashboard for non-provincie
  administrations.
- Confirm at least one `Budget` record exists with a `fiscalYear`
  matching the dashboard filter. The default filter is the current
  fiscal year — old budgets are excluded unless you change the
  filter.
- Confirm the GL lines you expect to see have a `programmaStructure`
  value. GL lines with a `null` programme are excluded from the
  programme aggregations. Use the
  [Budget-to-Programme Linker](./budget-to-programme-linker.md) to
  assign them.

### Data looks stale

- Default refresh cadence is **daily** (nightly batch at 02:00 UTC).
- Switch the cadence via **Admin settings → Dashboard refresh
  interval** (task 11). Options: real-time (deferred to T4), hourly,
  daily, weekly.
- Use the manual refresh button on the dashboard if the cadence is
  longer than your audit window.

### A programme is missing from the bar chart

- Check the **Programme** filter — an active multi-select might have
  excluded it.
- Confirm a `Budget` record exists for that programme in the active
  fiscal year. The chart renders one bar per `Budget` row, not one
  bar per programme; an unbudgeted programme draws no bar.

## What you have now

A read-only view of your province's BBV budget health, scoped to the
active fiscal year and filters, with traffic-light alerts on
overspent programmes. To re-map GL lines to the correct programme, use
the [Budget-to-Programme Linker](./budget-to-programme-linker.md).

## See also

- [Budget-to-Programme Linker](./budget-to-programme-linker.md) — bulk
  assignment of GL lines to BBV programme structures.
- [BBV compliance checklist](../../guides/bbv-compliance-checklist.md)
  — pre-audit walkthrough.
