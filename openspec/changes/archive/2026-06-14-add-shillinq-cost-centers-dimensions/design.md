status: pr-created

# Design — Cost Centers & Dimensions

**status: pr-created**

## Decisions

### D1 — Dimensions are first-class registers, not free-form strings on `GLLine`

Per ADR-031, each dimension type (cost-center, kostendrager, project)
gets its own register so the values are governable (typo-resistant,
versioned, lifecycle-managed) and queryable via standard OR
abstractions. Custom dimensions follow the same pattern: operators
declare a new register and the `GLLine.dimensions` free-form map
validates against the registered custom dimension registers via the
relations engine.

**Alternative considered**: Store dimension values as free-form
strings on `GLLine`. Rejected — no typo prevention, no governance, no
hierarchy navigation, no audit trail.

### D2 — Allocation rules are schema metadata, not a service

The `AllocationRule` register declares the rule shape (source pattern,
driver, targets, target dimension, cadence). The cadence field routes
execution: `per-posting` rules fire as an `x-openregister-lifecycle`
action on `GLTransaction.post`; `monthly` / `period-close` rules fire
from an OR `ScheduledWorkflow`. Either way, no PHP `AllocationService`
orchestrates — the rule body lives in the schema and the execution
shape is declarative.

The constraint that fixed-percentage targets sum to 100 is expressible
as an `x-openregister-lifecycle` precondition on `AllocationRule.save`
(per ADR-031). The cross-line balance constraint emitted when the rule
splits a transaction is the same constraint T1 declared on
`GLTransaction.post` — declarative re-use, no duplication.

**Alternative considered**: Author `AllocationService` mirroring
Exact / AFAS style. Rejected per ADR-031.

### D3 — Segment P&L as single-schema aggregation, not a service

Segment P&L roll-up is a single-schema aggregation on `GLLine` keyed
by dimension; per ADR-031 single-schema aggregations are declarative
default. No PHP `SegmentReportService`. Consumed by launchpad widgets via
runtime GraphQL and by the manifest detail pages.

### D4 — Pre-position WBSO `time-per-project` via REQ-CC-007

The future WBSO capability needs time-per-project; this spec adds a
non-required `timeBookingEnabled` flag on `Project` (REQ-CC-007) so
the WBSO capability lands additively without reshaping the `Project`
schema. The WBSO capability itself ships separately.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Cost-center / project / dimension storage | New registers (`CostCenter`, `KostenDrager`, `Project`) | Declared the same way as T1 `Account`; same RBAC + audit + lifecycle |
| Hierarchy navigation | `x-openregister-relations` self-relation | Standard relation shape |
| Custom dimensions | OR register abstraction (ADR-022) | Operator declares a custom dimension register; `GLLine.dimensions` free-form map validates against it via relations engine |
| Cost-allocation rule storage | New `AllocationRule` register | Schema-declared per ADR-031; cadence routes execution to lifecycle action or scheduled workflow |
| Allocation rule execution (per-posting) | `x-openregister-lifecycle` action on `GLTransaction.post` | Composition of existing primitives |
| Allocation rule execution (monthly / period-close) | OR `ScheduledWorkflow` (ADR-031 path 2) | Periodic batch work |
| Fixed-percentage sum-to-100 precondition | `x-openregister-lifecycle.requires` (ADR-031) | Declarative; same path as T1 balance precondition |
| Segment P&L aggregation | `x-openregister-aggregations` on `GLLine` (ADR-031) | Keyed by dimension; consumed by launchpad + manifest pages |
| Audit trail | OR audit-trail-immutable | Consumed automatically |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4) | Adds 4 menu entries + matching index/detail page pairs |

**Net new code in implementation cycle**: 4 schema declarations +
additive patches on `GLLine` + 4 manifest entry pairs + 3 seed JSON
files. No new PHP service.

## Seed Data

Seeds live under `lib/Settings/seeds/allocation-rules/` — example
shapes, not active rules:

| File | Purpose | Approximate row count |
|---|---|---|
| `allocation-rules/overhead-by-headcount.json` | Default headcount-driver overhead spread example | 1 |
| `allocation-rules/it-by-volume.json` | Default volume-driver IT-spend allocation example | 1 |
| `allocation-rules/facility-by-fixed-percentage.json` | Default fixed-percentage facility-cost allocation example | 1 |

These ship in `lifecycleState: paused` so operators can review and
activate. The implementing cycle's UX includes a `Try it` action that
flips them to `active` after operator confirmation.

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "shillinq-default",
  "variant": "<driver-name>", "imported": "<iso-timestamp>" } }`).

No seed data for `CostCenter` / `KostenDrager` / `Project` —
administration-specific; accumulate through operation.
