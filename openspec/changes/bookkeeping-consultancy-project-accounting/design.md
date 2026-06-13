# Design — Consultancy and Departmental Project Accounting

## Context

Consultancy and service organizations require integrated project and
departmental cost tracking. Multi-project time logging, hierarchical
cost center budgets, project P&L, and utilization metrics must be
derived from the general ledger and time entry records — not maintained
as a parallel ledger.

This change is a **T3 capability slice** per ADR-032 spec-sizing,
introducing project accounting and departmental budget management
alongside the existing T1 foundation (chart of accounts, general ledger).

**Status: pr-created.** Implementation landed via Hydra builder (issue #122).
Schemas, seed files, repair step extension, and manifest navigation
are all declared. No PHP service classes authored per ADR-031.

## Goals

- Express project and cost center structure as **declarative metadata**
  — schema + `x-openregister-lifecycle` rules + manifest entries — per
  ADR-031. No new PHP service classes.
- Consume every OpenRegister abstraction that already exists for
  lifecycle, calculations, aggregations, and relations — per ADR-022.
- Make the spec a **competent finance manager readable contract** —
  a Dutch accountant/controller should recognize the model as
  faithful project accounting with hierarchical cost centers.
- Keep the shape narrow enough that project-level GL posting and
  time-entry integration attach without reshaping core schemas.

## Non-Goals

- No GL postings (GL entry creation is T1's job).
- No multi-currency projects (T5).
- No advanced forecasting or scenario analysis (roadmap).
- No PHP code authored in this change.

## Decisions

### D1 — Project and cost center lifecycle declarative

`CostProject` and `CostCenter` lifecycles are declared via
`x-openregister-lifecycle`, not authored as service classes.

- **CostProject** lifecycle: `draft → active → on-hold → closed → archived`
- **CostCenter** status: `active → inactive` (simpler lifecycle)

**Alternative considered**: Author a `ProjectManagementService`
mirroring SAP / Deltek style. Rejected per ADR-031 — lifecycle is
pure state machine, not logic.

### D2 — Budget roll-up as aggregation

Hierarchical cost center budgets roll up to parent centers via
`x-openregister-aggregations`. Child budgets sum automatically;
operator can override parent totals for capacity planning.

- `CostCenter.allocatedBudget` rolls up from children.
- `CostCenter.spentToDate` aggregates actual GL postings.
- Variance = allocated − spent.

**Alternative considered**: `BudgetAggregationService::rollUp()`.
Rejected — pure aggregation fits OR's built-in projections.

### D3 — Project P&L via GL filtering

Project-level profit and loss derives from GL lines tagged with
project FK, filtered by account type (revenue vs cost accounts).

- Revenue = GL lines on revenue accounts tagged to project.
- Costs = GL lines on expense accounts tagged to project.
- P&L = revenue − costs.

### D4 — Time entry integration per project/task

`TimeEntry` is extended with optional `projectId` and `taskId` fields,
enabling hourly logging per project. No new entity — reuses the
existing time-tracking register.

### D5 — Utilization as calculation

`utilizationPercent = billableHoursThisPeriod / availableHoursThisPeriod`
per person per period, declared via `x-openregister-calculations`.
Capacity is operator-supplied per assignment per period.

### D6 — Seed data as templates, not enums

Standard project types (service engagement, product development,
internal) and cost center structures (sales, engineering, operations,
administration) are shipped as JSON seed objects. Operators may
customize: add projects, archive unused cost centers, rename
departments.

**Alternative considered**: Bake types into schema enums. Rejected —
every organization's structure is unique; seeds provide a starting
point, not a constraint.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Project lifecycle | `x-openregister-lifecycle` (ADR-031) | Declared on `CostProject` schema |
| Cost center hierarchy | `x-openregister-relations` (ADR-031) | Self-relation on `CostCenter.parentCode` |
| Budget aggregation | `x-openregister-aggregations` (ADR-031) | Filter GL by cost center code; sum allocated budget from children |
| Project P&L derivation | `x-openregister-aggregations` (ADR-031) | Filter GL by project FK; revenue vs cost account projection |
| Utilization metric | `x-openregister-calculations` (ADR-031) | Pure derived field on time entries |
| Time entry project tagging | Extension of T3 `UrenRegistratie` / TimeEntry | Add optional `projectId`, `taskId` fields |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| RBAC (project manager) | OR authorization (ADR-022) | Per-project and per-cost-center roles |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (ADR-024) | 2 menu entries with library renderers |
| Seed import | `ConfigurationService::importFromApp()` (ADR-022) | 2 seed files |

**Net new code in implementation**: 4 schema declarations + 2 field
extensions on TimeEntry + 2 manifest entries + 2 seed JSON files.
No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Project lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Cost center hierarchy | Declarative (`x-openregister-relations` self-relation) | Standard hierarchy pattern |
| Budget roll-up | Declarative (`x-openregister-aggregations`) | Standard projection-filter |
| Project P&L | Declarative (`x-openregister-aggregations`) | Standard GL filtering |
| Utilization calculation | Declarative (`x-openregister-calculations`) | Pure derived field |
| Audit trail | Consumed from OR's audit-trail-immutable (ADR-022) | No app config needed |

No service class authored in this envelope.

## Seed Data

This change ships two seed files, both under `lib/Settings/seeds/`:

| File | Purpose | Approximate row count |
|---|---|---|
| `project-templates.json` | Standard project types: service, product development, internal optimization | 3 |
| `cost-center-templates.json` | Organizational structure: Sales, Engineering, Operations, Administration, Finance | 5 main departments + common sub-departments |

Format: JSON arrays of `CostProject` and `CostCenter` records matching
the schemas declared in `bookkeeping-consultancy-project-accounting/proposal.md`.
Loaded via `ConfigurationService::importFromApp()` in the repair step.
After seeding, projects and cost centers are fully editable through
normal OR object operations.

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per ADR-005.
- A `_meta` block with source attribution and variant identifier so
  future extensions can identify template-sourced vs operator-authored
  records.

### project-templates.json (3 seeds)

```json
[
  {
    "@self": {
      "register": "shillinq",
      "schema": "CostProject",
      "slug": "template-service-engagement"
    },
    "projectNumber": "PROJ-SVC",
    "name": "Service Engagement Template",
    "description": "Standard template for service contract delivery",
    "lifecycleState": "active",
    "status": "active",
    "startDate": null,
    "endDate": null,
    "totalBudget": 50000.00,
    "estimatedCosts": 45000.00,
    "costsIncurredToDate": 0.00,
    "administrationId": null
  },
  { /* product-development */ },
  { /* internal-optimization */ }
]
```

### cost-center-templates.json (5+ main, hierarchical)

```json
[
  {
    "@self": {
      "register": "shillinq",
      "schema": "CostCenter",
      "slug": "cc-sales"
    },
    "code": "CC-001",
    "name": "Sales",
    "description": "Client acquisition and account management",
    "status": "active",
    "budget": 100000.00,
    "parentCode": null
  },
  {
    "@self": {
      "register": "shillinq",
      "schema": "CostCenter",
      "slug": "cc-sales-account-mgmt"
    },
    "code": "CC-001-01",
    "name": "Account Management",
    "description": "Existing customer relationship management",
    "status": "active",
    "budget": 50000.00,
    "parentCode": "CC-001"
  },
  /* ... Engineering, Operations, Finance, etc. */
]
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Budget hierarchy depth unbounded | Schema permits arbitrary depth; UI renders first 4 levels by default with collapse/expand. No T3 enforcement. |
| Project cost visibility delayed | GL postings reflect costs only when entered/approved. Real-time cost tracking requires post-GL integration. |
| Cost center rename impact | Cost center code is immutable (used as GL posting tag); name is mutable. Renaming does not affect historical GL postings. |
| Multiple project assignments | Person may work on multiple projects in same period; utilization is per-project, not aggregated. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the 4 schemas
   (additive — no existing schema changes).
2. `TimeEntry` (or equivalent) gains optional `projectId`, `taskId`
   fields (additive).
3. `src/manifest.json` is patched with two new menu entries +
   corresponding index/detail page pairs (additive).
4. A repair step (or extension of the existing one) imports the 2
   seed files idempotently.

Down-direction: registers are non-destructive — disabling the seed
import + reverting the manifest leaves stranded but queryable
records. No destructive rollback needed at the spec-acceptance gate.

## Open Questions

1. **Cost center structure customization** — templates provided are
   generic (Sales/Engineering/Operations/Finance). Confirm whether
   industry-specific variants (healthcare departments, government
   bureaus) belong on roadmap or should be user-customizable within
   the UI.

2. **Project budget vs estimated costs relationship** — the schema
   carries both `totalBudget` (authorized spend limit) and
   `totalEstimatedCosts` (project manager's estimate). Confirm
   whether these are separate (budget for approval control; estimate
   for P&L calculation) or aliases.

3. **Time entry billability distinction** — not in T3 scope, but
   `TimeEntry.projectId` tagging implies future billability (invoice
   generation). Confirm whether T4 invoicing depends on this field's
   presence.

4. **Allocation rule cascade direction** — cost allocations to parent
   cost centers cascade to children automatically? Or are allocations
   only bottom-up (children contribute to parent actual)? Confirm
   with controller persona.
