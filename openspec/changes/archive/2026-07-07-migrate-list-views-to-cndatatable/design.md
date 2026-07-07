# Design: migrate-list-views-to-cndatatable

## Context

Shillinq already treats `CnDataTable` as its list widget
(`GeneratedReportsIndex.vue`, `BBVProgrammeTable.vue`). Five record-list views
predate that convention and roll their own `<table>`.

## Decisions

### D1 — Migrate list views, exempt detail tables

The distinguishing test (per the CnDataTable universal-list-widget reference): is
the table a *list of records* (paginated, filterable, row actions) or a
*fixed-shape single-record* table (line items, comparison, pivot)? The five named
views are the former → migrate. The nine detail/line-item tables subagent review
identified are the latter → exempt.

### D2 — Preserve behaviour, change only the renderer

For each view: map the existing `<th>` set to CnDataTable column defs, feed the
existing rows via `:rows`, move filters to the component's filter/header surface,
and move per-row action buttons to the row-action slot. The backing object stores
are untouched; the same rows appear. NcSelect filters gain the component's
built-in `inputLabel` accessibility (which the fleet gates).

### D3 — One change, five files

Grouping the five into a single change keeps them consistent (same column/utility
conventions) and gives one capability requirement to gate against. They are
independent files with no shared state, so the migration is mechanical per file.

## Verification

- Vitest component tests: each migrated view renders `CnDataTable` as the list
  renderer with the expected columns.
- One Playwright test on the browser-observable invoice list (rows render + status
  filter narrows) — the others are covered by component tests.

## Non-goals

- No data, store, route, or PHP change.
- No change to the exempt detail/line-item tables.
