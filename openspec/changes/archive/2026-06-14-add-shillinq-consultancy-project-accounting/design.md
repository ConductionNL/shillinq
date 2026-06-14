# Design — Consultancy Project Accounting

**status: pr-created**

## Context

Consultancy operators (Conduction's own primary customer profile)
need multi-project WIP, billable hours, rate cards, utilisation,
project P&L, and RJ 270 / IFRS 15 percentage-of-completion revenue
recognition. Every metric is a derivation from existing records
(GL, AR, hour entries) — no parallel ledger, no service-class
orchestration.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change is **spec-only**.

## Goals

- Declare `Project`, `ProjectAssignment`, `RateCard`, `WipBalance`
  as registers with lifecycles per ADR-031.
- Express RJ 270 / IFRS 15 percentage-of-completion as
  `x-openregister-calculations` — NOT a `RevenueRecognitionService`.
- Express utilisation + project P&L as derived fields /
  aggregations.
- Snapshot WIP at period-end via OR `ScheduledWorkflow` triggered
  by T2 period close.
- Snapshot rate-at-write on `BillableHour.recognisedRate` per RJ
  270 §3.2.4.

## Non-Goals

- No app-local `RevenueRecognitionService` (ADR-031 anti-pattern).
- No app-local `ProjectPlService` (ADR-031 anti-pattern).
- No fixed-price vs T&M mode-specific reporting in T3 (roadmap).
- No multi-currency translation (T5).

## Decisions

### D1 — `Project` lifecycle declarative

`offerte → active → on-hold → closed → archived` declared via
`x-openregister-lifecycle`. The `closed` transition triggers a
final WIP snapshot + recognition adjustment.

### D2 — Recognition formula as `x-openregister-calculations`

`Project.recognisedRevenue = totalContractValue ×
(costsIncurredToDate / totalEstimatedCosts)` (cost-to-cost method,
RJ 270 §3 / IFRS 15 §B14-B19, the most common).

- `costsIncurredToDate` is a derived field via
  `x-openregister-aggregations` summing `GLLine` postings on cost
  accounts tagged to the project.
- `totalEstimatedCosts` is operator-supplied on `Project`.

The recognition posting itself is materialised as a `JournalEntry`
by an OR scheduled workflow at month-end / period-close per
`REQ-CPA-007`.

**Alternative considered**: `RevenueRecognitionService::recogniseMonthEnd()`.
Rejected — every method maps cleanly to an OR extension.

### D3 — Rate-at-write snapshot per RJ 270 §3.2.4

When a project spans rate-card revisions (e.g. Q1 partner rate
€180, Q2 €195), RJ 270 mandates hours are recognised at the rate-
as-of-performance-date. `RateCard` carries `effectiveFrom` /
`effectiveTo`; `BillableHour.recognisedRate` is snapshotted at
write time. Subsequent rate-card edits do NOT retroactively change
already-logged hours' rates.

### D4 — WIP snapshot via period-close `ScheduledWorkflow`

Per `REQ-CPA-008`, a `WipBalance` snapshot is generated for every
active project at the moment T2 declares the period closed. The
workflow is event-triggered (not fixed cron) — listens to T2
`PeriodClosed` event.

### D5 — Project P&L as aggregation

`Project.profitAndLoss` is a derived field via
`x-openregister-aggregations` filtering `GLLine` by project FK
(revenue minus costs over the project's lifetime).

### D6 — Utilisation as calculation

`utilization = billableHoursThisPeriod / capacityHoursThisPeriod`
per person per period via `x-openregister-calculations`. Capacity
is operator-supplied per assignment per period; defaults to 36-
hour workweek per Wet IB.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| `Project` lifecycle | `x-openregister-lifecycle` (ADR-031) | Declared on schema |
| Cost-to-cost completion derivation | `x-openregister-aggregations` (ADR-031) | Filter GL by project FK; cost-account projection |
| Recognised revenue formula | `x-openregister-calculations` (ADR-031) | Pure formula on existing fields |
| Recognition journal posting | OR `ScheduledWorkflow` at month-end (ADR-031) | Cron-driven; replaces `RevenueRecognitionService` |
| Utilisation derivation | `x-openregister-calculations` (ADR-031) | Pure derived field |
| Project P&L | `x-openregister-aggregations` (ADR-031) | Filter GL by project FK |
| WIP snapshot | OR `ScheduledWorkflow` triggered by T2 period close | Event-driven |
| Rate-at-write snapshot | Schema constraint on `BillableHour` | Writeable once |
| Billable hours storage | T3 `UrenRegistratie` extension | Add `recognisedRate`, `projectAssignmentId` fields |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| RBAC (project-administrator) | OR authorization (ADR-022) | Per-project role |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 3 menu entries under `Projecten` with visibility predicate |
| Seed import | `ConfigurationService::importFromApp()` | 2 seed files |

**Net new code in implementation**: 4 schema declarations + 2
field extensions on `UrenRegistratie` + 3 manifest entries + 2
seed JSONs + 1 event-triggered `ScheduledWorkflow`. No new PHP
service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| `Project` lifecycle | Declarative (`x-openregister-lifecycle`) | Textbook fit |
| Cost-to-cost completion | Declarative (`x-openregister-aggregations`) | Standard projection-filter |
| Recognised revenue formula | Declarative (`x-openregister-calculations`) | Pure derivation |
| Recognition journal entry | OR `ScheduledWorkflow` at month-end | ADR-031 §"orchestrate scheduled work" |
| WIP snapshot | OR `ScheduledWorkflow` event-triggered | ADR-031 §"orchestrate event-driven work" |
| Utilisation | Declarative (`x-openregister-calculations`) | Pure derived field |
| Project P&L | Declarative (`x-openregister-aggregations`) | Standard projection-filter |
| Rate-at-write snapshot | Schema constraint | Standard immutable field |

No service class authored.

## Seed Data

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `lib/Settings/seeds/rj-270-stages.json` | Percentage-of-completion stage definitions (initiation, execution, closeout, complete) | 4 | RJ 270 §3 + IFRS 15 §B14-B19 |
| `lib/Settings/seeds/rate-card-templates.json` | Default rate-card structure (junior / medior / senior / partner) | 4 | Conduction internal default; operator overrides |

Both with SPDX header + `_meta` block.

`rj-270-stages.json` is statutory baseline; operator may add
intermediate stages. `rate-card-templates.json` is internal
default; operator overrides per administration.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Multi-rate boundary | `BillableHour.recognisedRate` snapshot at write per RJ 270 §3.2.4 |
| Estimated-cost revision | Audit-trail records every change; recognised revenue recalculates declaratively |
| WIP snapshot cadence | Event-triggered by T2 period-close, not fixed cron |
| Utilisation capacity | Operator-supplied per assignment per period; default 36-hour |
| Fixed-price vs T&M | Same `Project` schema; mode-specific reporting on roadmap |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` adds 4 schemas (additive)
   + 2 field extensions on `UrenRegistratie`.
2. `src/manifest.json` adds 3 navigation entries.
3. The repair step imports the 2 seeds (idempotent) and registers
   the period-end WIP `ScheduledWorkflow`.

Down-direction: revert the implementing PR, run the repair step in
down-direction. Existing projects + WIP snapshots remain queryable.

## Open Questions

1. **Multi-rate snapshot edge case** — confirmed RJ 270 §3.2.4
   compliant; confirm with project-administrator persona.
2. **Utilisation capacity defaults** — 36-hour workweek per Wet IB;
   confirm with HR/finance persona.
3. **Fixed-price vs T&M mode reporting** — roadmap.
