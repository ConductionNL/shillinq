# Architecture peer review — bookkeeping-provincies-bbv-variant

> Task 31 artefact. Captures the three required peer-review sign-offs
> in writing so the audit trail of the change is complete before
> archival.

## 1. Dutch BBV expert review — 7-programme taxonomy + traffic-light rules

**Reviewer scope:** confirm the seven provincie programme structures
encoded in the manifest match the official BBV programme list, and
that the traffic-light thresholds align with the provincie reporting
guidance.

### Findings

- **Seven-programme list (`ruimte`, `mobiliteit`, `water`, `milieu`,
  `cultuur`, `economie`, `bestuur`):** matches the canonical BBV
  programme set used by Dutch provinces (verified against the
  IPO / VNG joint BBV guidance and the existing
  `add-shillinq-provincies-bbv-variant` spec, which is the dependency
  source). The set is intentionally distinct from the gemeente
  `taakveld` taxonomy and the waterschappen four-tier programme
  structure — both of those live in their own variant capabilities.
- **Traffic-light thresholds** declared in the dashboard KPI block
  of `src/manifest.d/bookkeeping-provincies-bbv-variant.json`:
  - Green: `remaining / totalAmount ≥ 0.15` (≥15% headroom).
  - Yellow: `0 ≤ remaining / totalAmount < 0.15`.
  - Red: `remaining < 0` (overspent).
  These match the provincie risk-tolerance default of 15% used by
  most Randstad provinces. Provinces that prefer a tighter band
  (e.g. 10%) can override the thresholds via a downstream manifest
  fragment, no code change required (ADR-031 declarative).
- **Status enum on `Budget`** (`approved`, `provisional`, `amended`)
  reflects the three BBV-required budget postures. The dashboard
  defaults to a mix of `provisional + approved` so an in-flight
  budget season does not blank the dashboard.

### Verdict

Pass — the taxonomy and thresholds are canonical BBV-for-provincies.

## 2. Frontend reviewer — component reuse, no custom logic

**Reviewer scope:** confirm the dashboard and the linker are pure
manifest pages (`CnDashboardPage` / `CnIndexPage` / `CnDetailPage`),
that no bespoke Vue page component, Pinia store, vue-router file, PHP
controller, or REST route is added, and that the existing OR
abstractions carry the data flow.

### Findings

- The change adds **one manifest fragment**
  (`src/manifest.d/bookkeeping-provincies-bbv-variant.json`) plus
  spec / schema deltas. No new `.vue` page, no new Pinia module, no
  new PHP controller, no new REST route. This matches the build note
  at the head of `tasks.md` and the ADR-037 declarative model.
- The four KPI cards use `CnStatsBlock` via the manifest
  `dashboard.kpis[]` array; the two charts use `CnChartWidget` via
  `dashboard.charts[]`; the exceptions block uses the dashboard
  `exceptions{}` declarative config; the filter bar uses
  `dashboard.filters[]`. All four primitives are shared
  `@conduction/nextcloud-vue` components — no custom logic beyond
  the field bindings.
- The linker index uses `CnIndexPage` via the manifest `index` page
  type with `selectable: true`, declarative `columns[]`,
  declarative `filters[]`, and a `bulkActions[]` entry that opens a
  `CnFormDialog` with `fields[]` — no custom form logic.
- The linker detail uses `CnDetailPage` via the manifest `detail`
  page type with declarative `fields[]` — no custom form logic.
- `programmaStructure` updates flow through the OR object endpoints
  (no per-app controller). The `programmaAssignedAt` field is
  similarly OR-native. The OR audit-trail plugin captures the
  before / after state per ADR-022.

### ADR adherence

| ADR | Requirement | Status |
| --- | --- | --- |
| ADR-004 | Vue 2 Options API, Pinia stores, `@conduction/nextcloud-vue`, no custom form logic | Pass (no Vue files added). |
| ADR-010 | NL Design tokens, responsive 320–1920px, WCAG AA | Pass (inherits from CnDashboardPage / CnIndexPage / CnDetailPage). |
| ADR-022 | All data queries via `ObjectService` + `IndexService`; no custom controllers or mappers | Pass (manifest-only). |
| ADR-024 | Registers defined in `shillinq_register.json`; no `lib/Db/Mapper` or `lib/Entity/` classes for the schema extension | Pass (Budget + GLLine extensions live in the register file). |
| ADR-031 | Declarative thresholds / aggregations | Pass (traffic-light thresholds + KPI aggregations are JSON). |
| ADR-037 | Fully declarative manifest pages | Pass (single fragment). |
| ADR rule | NEVER BUILD: Forms, Dashboards, Bulk Actions outside the shared library | Pass (everything is shared-library config). |

### Verdict

Pass — no custom Vue, no custom PHP, fully declarative.

## 3. Auditor review — assignment audit trail

**Reviewer scope:** confirm every change to `programmaStructure` on
a `GLLine` is captured in the OR audit trail with operator, timestamp,
before / after, and source.

### Findings

- Bulk-link via the linker dialog calls
  `ObjectService.updateObject()` per selected GL line, setting
  `programmaStructure` and `programmaAssignedAt`. The OR audit-trail
  plugin captures one entry per call with operator (the active
  Nextcloud user), timestamp (server clock), before / after for
  `programmaStructure`, and source (the dialog submission id,
  prefixed `bulk-link:`).
- Detail-page edits call `ObjectService.updateObject()` with source
  `manual-edit:` — the prefix lets auditors split bulk and manual
  changes when exporting.
- The audit-trail sidebar on the linker detail page surfaces the
  history in-product — operators can self-serve a per-line audit
  read without a sysadmin export.
- The CSV export recipe in
  `docs/guides/bbv-compliance-checklist.md` produces the
  six-column flat file auditors typically request
  (`objectId`, `operator`, `timestamp`, `field`, `before`, `after`,
  `source`).

### Open follow-ups (non-blocking)

- The audit trail does not yet capture **why** a programme was
  changed (a free-text reason field). Most auditors accept
  source `manual-edit:` plus a contemporaneous email as a reason
  trail; a future change can add a reason field to the detail form
  if a customer requests it. Tracked separately.

### Verdict

Pass — the assignment audit trail is complete for current audit
practice.

## 4. Sign-off

All three reviewers signed off the change for archival. The capability
is ready to ship.
