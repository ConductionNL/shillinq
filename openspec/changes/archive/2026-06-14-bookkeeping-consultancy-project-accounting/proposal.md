# Proposal: bookkeeping-consultancy-project-accounting

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + seed data. No PHP service
classes are authored.

## Summary

Introduce **consultancy and departmental project accounting** for
Shillinq as a T3 capability per `adr-001-bookkeeping-tier-roadmap.md`.
This change declares the `CostProject`, `CostCenter`, `TimeEntry`,
and `ProjectBudget` registers with `x-openregister-lifecycle` rules,
`x-openregister-calculations` for hierarchical budget roll-up, and
`x-openregister-aggregations` for project and department cost
tracking (per ADR-031), wires navigation into `src/manifest.json`
(per ADR-024), and ships seed data for default project templates and
cost center structures. No PHP service classes, no custom database
tables — the entire capability lands as register metadata + manifest
entries + seed JSON.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T1 `bookkeeping-chart-of-accounts` (project costs
post to GL accounts) and T1 `bookkeeping-general-ledger` (project P&L
aggregates GL postings).

## Why

Management accounting and project cost tracking are essential for
consultancies and service organizations. Multi-project time tracking,
hierarchical department budgets, project-level cost rollup, and
utilization reporting require a declarative project accounting
structure integrated with the general ledger.

Without departmental and project accounting, operators must maintain
separate time-tracking and budgeting tools — defeating the suite's
value as an integrated business administration platform.

## What Changes

- Adds a `CostProject` analytical register (budget vs estimated vs
  incurred costs) with declarative lifecycle `draft → active →
  on-hold → closed → archived`.
- Adds a `ProjectBudget` period-allocation register with
  `pending → approved → allocated → spent` lifecycle.
- Additively extends `CostCenter` (description, status alias,
  budget, spentToDate, allocatedBudget, organizationId) and
  declares `spentToDate` aggregation + `allocatedBudget` recursive
  budget-rollup calculation.
- Extends `UrenRegistratie` with optional `costProjectId` and
  `taskId` for project-level time tracking; adds a declarative
  `utilizationPercent` calculation grouped by (person, period).
- Adds `CostProject.costsIncurredToDate` + `CostProject.profitAndLoss`
  declarative GL aggregations.
- Ships two seed JSON files (project-templates, cost-center-templates)
  loaded idempotently by a new `InitializeSettings` repair-step phase.
- Adds Projects (CostProjects + CostProjectDetail) navigation to
  `src/manifest.json`; Cost Centers nav reused unchanged.
- Reconciles `adr-000-data-model.md` `CostProject` and `CostCenter`
  entries; adds `CostProjectBudget` ADR-000 entry.

## Motivation

(Retained for compatibility — see "Why" above.)

## Affected Projects

- [x] Project: shillinq — adds 4 new registers/schemas (`CostProject`,
  `CostCenter`, `TimeEntry` extension, `ProjectBudget`) to
  `lib/Settings/shillinq_register.json`, adds 2 manifest navigation
  entries (`Projects > Overview`, `Cost Centers`), ships seed data
  for project templates and cost center structures.
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (lifecycle, calculations, aggregations).

## Scope

### In Scope

- One new capability spec (`bookkeeping-consultancy-project-accounting`).
- `CostProject` register with project identification, budget, and
  cost tracking (projectNumber, name, description, startDate, endDate,
  totalBudget, totalEstimatedCosts, costsIncurredToDate,
  administrationId, lifecycleState).
- `CostCenter` register with hierarchical department structure
  (code, name, description, status, budget, parentCode, organizationId).
- `ProjectBudget` register for period-level budget allocation per
  project.
- `TimeEntry` extension enabling time registration per project/task
  with timer support.
- Hierarchical budget roll-up via `x-openregister-calculations` and
  `x-openregister-aggregations`.
- Project P&L via aggregations filtering `GLLine` by project FK.
- Utilization metrics via calculations on time entries.
- Manifest navigation under `Projects` and `Cost Centers`.
- Seed data for standard project and cost center templates.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal.
- **Multi-currency project costs** — owned by T5 `multi-currency`.
- **Advanced forecasting and scenario modeling** — roadmap.
- **Labor allocation optimization** — roadmap T3+.

## Approach

Multiple deltas with ADDED Requirements:

**`bookkeeping-consultancy-project-accounting`** — declares the
`CostProject`, `CostCenter`, `ProjectBudget` registers and `TimeEntry`
extension. Hierarchical cost centers via `parentCode`. Project
lifecycle `draft → active → on-hold → closed → archived` via
`x-openregister-lifecycle`. Budget roll-up and project P&L via
`x-openregister-aggregations`. Seed templates loaded via
`ConfigurationService::importFromApp()` during repair step.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-CPA-*` for
traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions and T1
bookkeeping foundation (chart of accounts, general ledger).

## Impact

- `lib/Settings/shillinq_register.json` — adds 4 schemas with lifecycle,
  calculations, and aggregations; declares relations between projects,
  cost centers, and GL accounts.
- `lib/Settings/seeds/project-templates.json` — new file with standard
  project types (service, product, internal).
- `lib/Settings/seeds/cost-center-templates.json` — new file with
  departmental structure templates.
- `src/manifest.json` — adds 2 navigation entries (Projects, Cost Centers).
- Repair step to import seed data idempotently.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on calculations, aggregations, and lifecycle
  extensions. Standard shape.

## Risks

### Risk 1: Hierarchical budget aggregation complexity

**Severity**: Medium
**Mitigation**: Budget roll-up is declarative via `x-openregister-aggregations`
filtering child cost centers. Per-center overrides allowed; the system
recalculates parent totals automatically.

### Risk 2: Time entry rate snapshot

**Severity**: Low
**Mitigation**: Hourly rates are snapshotted at entry creation time per
project assignment. Rate-card changes do not retroactively adjust
already-logged entries.

### Risk 3: GL account project filtering

**Severity**: Low
**Mitigation**: Project cost tracking requires GL postings tagged with
project FK. Operators must configure chart-of-accounts posting rules
to ensure project codes are set on every transaction.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation (separate
cycle), rollback follows the standard pattern: revert the implementing
PR, run the repair step in down-direction. Registers are non-destructive.

## Open Questions

1. **Cost center hierarchy depth** — unbounded nesting supported;
   confirm UI rendering depth limits (typically 4 levels) with the
   design team.
2. **Budget variance reporting** — budget vs actual analysis is
   roadmap; declare budget-tracking fields now for forward compatibility.
3. **Allocation rule cascade** — cost allocations to parent cost
   centers cascade to children; confirm calculation order with the
   finance persona.
