---
sidebar_position: 2
title: Budget-to-Programme Linker
description: Map your general ledger entries to the BBV programme structure used by Dutch provinces — multi-select GL lines, bulk-link via a guided dialog, and let the OpenRegister audit trail capture every assignment.
---

# Budget-to-Programme Linker

Map your general ledger entries to the BBV programme structure.

The Budget-to-Programme Linker is the single canonical surface for
assigning `GLLine` records to one of the seven BBV programme
structures used by Dutch provinces (`ruimte`, `mobiliteit`, `water`,
`milieu`, `cultuur`, `economie`, `bestuur`). Operators can bulk-link
many lines at once via a guided dialog, or open a single line in the
detail view to amend its programme manually. All changes flow through
`ObjectService.updateObject()` so the OpenRegister audit trail
captures before / after state automatically (ADR-022).

## Goal

By the end of this guide you will be able to read the mapping-status
badge, find unmapped GL lines via the filters, bulk-link a selection
to a target programme via the guided dialog, and open a single GL
line in the detail view to amend its programme.

## Prerequisites

- A Nextcloud account with **Shillinq** installed and enabled.
- The active administration is a `provincie` administration — the
  Linker navigation entry is hidden for non-provincie administrations
  (manifest `visibilityPredicate`).
- At least one `GLLine` record. Lines in `posted` or `committed`
  status are bulk-link-eligible; `draft` lines are rejected with an
  inline validation error.
- An understanding of the seven BBV programme structures used by your
  province. See the [compliance checklist](../../guides/bbv-compliance-checklist.md)
  for the full taxonomy.

## Section 1 — Why mapping matters

The BBV reporting framework requires every euro of expenditure to be
assigned to a numbered programme. Unmapped GL lines:

- Are excluded from the
  [BBV Compliance Dashboard](./bbv-compliance-dashboard.md) totals —
  they appear nowhere in budget-vs-actuals.
- Are flagged by external auditors as a compliance gap.
- Break the closing process for the fiscal year — most provinces
  block close until the mapping rate exceeds the configured
  threshold.

The Linker is the canonical surface for keeping the mapping rate
high. The mapping-status badge at the top of the index makes the gap
visible at a glance.

## Section 2 — The bulk mapping workflow

### Step 1: open the Linker

1. Open **Shillinq → BBV Provincie → Budget Links**.
2. The route is `/bbv-provincie/budget-to-programme`.

### Step 2: scope the index

The Linker index renders the GL-line table with three filter facets,
declared in the manifest:

- **Account type** — single-select across `assets`, `liabilities`,
  `revenue`, `expenses`.
- **Programme** — single-select across the seven BBV programmes plus
  the `unmapped` synthetic value (which surfaces every GL line with
  no programme assignment).
- **Assignment status** — single-select across `mapped` /
  `unmapped`.

For a typical bulk-mapping session, set **Assignment status =
unmapped** and optionally **Account type = expenses** to scope to
expense lines that are missing a programme.

### Step 3: read the mapping-status badge

The badge at the top of the index reads `Unmapped GL lines: N of M
(P%)` and is coloured by the thresholds declared in the manifest:

| Status | Rule | Action |
| ------ | ---- | ------ |
| Green | Less than 10% of GL lines are unmapped | Healthy; spot-check before close. |
| Yellow | 10–30% of GL lines are unmapped | Schedule a bulk-mapping session before close. |
| Red | More than 30% of GL lines are unmapped | Audit risk — bulk-link the high-value accounts first. |

### Step 4: bulk-select and link

1. Tick the master checkbox (top of the table) to select every GL
   line in the current filter, or tick individual row checkboxes
   for a subset.
2. Click **Link to Programme**. The CTA is disabled while nothing is
   selected (manifest `requiresSelection: true`).
3. A guided dialog opens with two fields:
   - **Target programme** — single-select dropdown, required, with
     the seven BBV programme values.
   - **Effective date** — date picker, required, defaults to today;
     future dates are rejected (warning) but can be overridden via
     a checkbox.
4. Click **Link**.
5. While saving, the submit button is disabled to prevent
   double-submit.
6. On success: a toast confirms `Linked N GL lines to <Programme>`
   and the table refreshes.
7. On partial failure: a toast reads `Linked N of M; M errors` and a
   side panel shows per-row error details (most commonly a `draft`
   line in the selection — re-post the line or remove it from the
   selection and retry).

The audit trail captures every assignment automatically — no extra
logging step is required.

## Section 3 — Viewing assignment history

Every change to `programmaStructure` on a `GLLine` writes an audit
trail entry via OpenRegister's audit-trail plugin (ADR-022). To view
the history of a single GL line:

1. From the Linker index, click a row to open the detail view (route
   `/bbv-provincie/budget-to-programme/{id}`).
2. The detail form shows the GL line's read-only fields (account
   number, description, amount, side, period) plus the two editable
   fields: `programmaStructure` (enum) and `programmaAssignedAt`
   (datetime).
3. Open the OpenRegister audit-trail sidebar to see the assignment
   history — each entry records the operator, timestamp, before /
   after `programmaStructure`, and the source (`Manual Edit` for
   detail-page edits, the dialog submission id for bulk links).

You can also export the audit trail in bulk for an auditor — see the
[BBV compliance checklist](../../guides/bbv-compliance-checklist.md).

## Section 4 — Troubleshooting

### Validation errors in the dialog

- **Target programme is required** — the dropdown is blank. Select
  one of the seven BBV programmes.
- **Effective date is in the future** — the date picker is set to a
  later date than today. Either correct the date or tick the
  override checkbox to acknowledge the future assignment.
- **GL line is in draft state** — the selection includes a `draft`
  row. Either post / commit the line first, or deselect it.

### A GL line keeps showing as unmapped after I linked it

- The Linker index caches the mapping-status badge for the duration
  of the page session. Refresh the page to recompute the badge.
- Confirm the line's `programmaStructure` is the intended value via
  the detail view — a partial-success toast may have skipped this
  row.

### I linked the wrong programme

- Open the GL line's detail view and select the correct
  `programmaStructure`. The edit lands in the audit trail with
  source `Manual Edit`, and the dashboard recomputes automatically
  at the next refresh interval (or immediately on a manual refresh).

### Bulk select-all only selects the visible page

- The CnDataTable's master checkbox selects the current page. For
  larger sets, tighten the filters (Assignment status = unmapped is
  usually enough) and repeat the bulk-link in batches.

## What you have now

A GL ledger fully mapped to the BBV programme structure, with every
assignment audited by OpenRegister. Spending now flows correctly into
the [BBV Compliance Dashboard](./bbv-compliance-dashboard.md), and the
fiscal-year close is unblocked from a compliance perspective.

## See also

- [BBV Compliance Dashboard](./bbv-compliance-dashboard.md) — read-only
  view of the budget health you just mapped against.
- [BBV compliance checklist](../../guides/bbv-compliance-checklist.md)
  — pre-audit walkthrough.
