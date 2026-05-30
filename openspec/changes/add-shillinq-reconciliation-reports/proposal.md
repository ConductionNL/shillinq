# Proposal: add-shillinq-reconciliation-reports

`kind: config` per ADR-032 — the centre of mass is declarative
saved-query objects + manifest entries. No PHP service classes are
authored (modulo conditional thin guards if engine-dependency risks
confirm).

## Summary

Introduce the **reconciliation / exception report catalogue**
capability for Shillinq as part of the Tier 4 advanced bookkeeping
engine (per `adr-001-bookkeeping-tier-roadmap.md`). This change
declares saved-query objects (`SavedQuery` / `Budget` registers) as
`x-openregister-aggregations` (per ADR-031) covering sub-ledger ↔ GL
match, intercompany match, variance analysis vs `Budget`, and the
controller exception report. Per `feedback_launchpad-no-or-dependency.md`,
launchpad consumes these reports via runtime GraphQL — shillinq publishes
the saved queries against OR registers, launchpad discovers them through
the GraphQL schema, neither app imports the other. No PHP
`ReportingService.generateReconciliation()`; severity classification
on exception rows (`critical` / `warning` / `info`) is encoded as a
calculated field.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

Controllers need exception reports across sub-ledger ↔ GL,
intercompany, and budget vs actual — the operational visibility layer.
Without these, a complete bookkeeping system has a blind spot:
operators discover reconciliation gaps too late. The saved-query
shape lets launchpad and the manifest pages share a single canonical
data path; the controller exception report rolls up severity from the
underlying queries so the controller sees the urgent items at the
top of the list.

This proposal is one of seven sibling Tier 4 capability changes
extracted from the bundled `add-shillinq-bookkeeping-advanced` proposal
to satisfy ADR-032 spec-sizing (cap: 20 unchecked tasks per change).

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`Budget`) to
  `lib/Settings/shillinq_register.json`, declares saved-query records
  for the four reports, adds manifest navigation entries.
- [ ] Project: openregister — no source changes; this change consumes
  `x-openregister-aggregations`, `SavedQuery`, audit-trail-immutable,
  RBAC.
- [ ] Project: launchpad — no source changes; launchpad consumes
  reconciliation reports via runtime GraphQL only (per
  `feedback_launchpad-no-or-dependency.md`); no `shillinq` dep is added.

## Scope

### In Scope

- One new capability spec (`bookkeeping-reconciliation-reports`) —
  see the `specs/` folder.
- `Budget` register declaration (`accountNumber`, `periodId`,
  `budgetAmount`, `currency`, `administrationId`, `lifecycleState`).
- Four saved-query records as `x-openregister-aggregations`:
  sub-ledger ↔ GL match (REQ-RR-002), intercompany match across
  administrations (REQ-RR-003), variance analysis vs `Budget`
  (REQ-RR-004), controller exception report consolidating the three
  (REQ-RR-005).
- Severity classification (`critical` / `warning` / `info`) encoded
  as a calculated field on each exception row, sortable in the
  controller report.
- launchpad consumption via runtime GraphQL only; no install-time dep
  per `feedback_launchpad-no-or-dependency.md`.
- Manifest navigation entries (Bookkeeping > Reconciliation Reports
  + Bookkeeping > Budgets) using `type: index` / `type: detail`
  renderers; the reports page lists the saved-query catalogue and
  renders each report via `type: detail` pages bound to the
  saved-query metadata.

### Out of Scope

- **Implementation code** — this is a spec-only change.
- **A PHP report engine** — REQ-RR-001 hard-forbids
  `lib/Service/*Report*.php` / `*Reconciliation*.php` /
  `*Variance*.php` class names; the reviewer gates on grep.
- **Frontend Vue components** beyond `CnIndexPage` / `CnDetailPage`
  generic rendering.
- **launchpad widget authoring** — launchpad chooses how to render the
  saved queries; shillinq just publishes them.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-reconciliation-reports`** — declares the four
saved-query objects, the `Budget` register, severity classification as
a calculated field, and launchpad consumption via runtime GraphQL.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-RR-*` for
traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema (`Budget`) and
  declares `x-openregister-aggregations` on `SavedQuery` records owning
  the four reports.
- `src/manifest.json` — adds Bookkeeping > Reconciliation Reports
  (saved-query catalog) + Bookkeeping > Budgets (index/detail).
- No new PHP services. No new Vue components. No new controllers. No
  new TimedJobs.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-aggregations`,
  `SavedQuery`, audit-trail-immutable, RBAC (`controller` role for
  exception report access).
- **T1 `bookkeeping-general-ledger`** — the saved queries aggregate
  `GLLine`.
- **T2 `bookkeeping-accounts-payable-core` /
  `bookkeeping-accounts-receivable-core`** — sub-ledger ↔ GL match
  joins T2 sub-ledger objects with `GLLine`.
- **launchpad** — discovers the saved queries via OR's GraphQL schema;
  launchpad MUST NOT list shillinq as a dependency (per
  `feedback_launchpad-no-or-dependency.md`).

## Risks

### Risk 1: OR aggregation engine cannot express cross-administration intercompany match or cross-schema variance join declaratively

**Severity**: Medium
**Mitigation**: Per ADR-031 exception path. Where a single-schema
aggregation suffices (e.g. variance grouping `GLLine` by
`accountNumber + periodId`), declarative is the default. Where the
aggregation crosses schemas or administrations (intercompany match,
budget-vs-actual variance join), the spec keeps the requirement
shape-neutral (e.g. REQ-RR-003 says "a saved query MUST match each
intercompany posting…" without prescribing engine internals); the
resolution lives in the implementing cycle. If the engine cannot
express it, a thin single-method PHP guard called *by* the aggregation
engine is authored. The guard is single-method, ~20 LOC, and
explicitly cited as an ADR-031 exception in the implementing cycle's
design doc.

### Risk 2: Reports become a parallel reporting engine via creep

**Severity**: Medium
**Mitigation**: REQ-RR-001 hard-forbids report-engine services;
reports MUST be `SavedQuery` / `x-openregister-aggregations`;
reviewer gate; launchpad is the canonical visualisation surface. Any
proposal to author `*Report*` / `*Reconciliation*` / `*Variance*`
PHP service files is rejected at code review.

### Risk 3: launchpad inadvertently grows a `shillinq` install-time dep

**Severity**: Low–Medium (architectural drift)
**Mitigation**: Per `feedback_launchpad-no-or-dependency.md`, launchpad
consumes saved queries via runtime GraphQL on OR; the GraphQL schema
is the discovery surface. The implementing cycle includes an explicit
end-to-end test confirming launchpad widget rendering works with no
`require: shillinq` in launchpad's `appinfo/info.xml`.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder. After implementation (separate cycle), rollback follows the
standard pattern: revert the implementing PR. Saved queries are
non-destructive; launchpad widgets degrade gracefully when the queries
disappear (no results shown).

## Open Questions

1. **Cross-administration intercompany aggregation engine support** —
   see Risk 1. The `opsx-ff` design phase resolves whether the
   aggregation engine can express the intercompany match across two
   administrations, or whether a thin saved-query guard is needed.
   The spec is shape-neutral.
2. **Default budget-variance threshold** — 5% / 10% / operator-
   configurable? REQ-RR-004 makes the threshold a saved-query
   parameter; default value confirmed during implementing cycle UX
   review.
