# Design — Fixed Assets & Depreciation

**status: pr-created**

## Decisions

### D1 — Asset state machine is declarative

Per ADR-031, the `proposed → active → disposed → archived` state
machine MUST live as `x-openregister-lifecycle` metadata on
`FixedAsset`, not in a PHP service. Disposal triggers a lifecycle
action that emits a closing `JournalEntry` (T1 primitive) without any
shillinq-side `AssetDisposalService` orchestrating.

**Alternative considered**: Author a `FixedAssetService` mirroring
Exact / AFAS style. Rejected per ADR-031.

### D2 — Depreciation is a derived field, not a materialised schedule

Per ADR-031's `isOverdue`-on-decidesk-ActionItem pattern, depreciation
values are derivable on demand from the asset's fields
(`acquisitionCost`, `residualValue`, `usefulLifeMonths`,
`depreciationMethod`, etc.) plus the current date. No persisted
schedule table; no `DepreciationScheduleService` materialising
per-month rows. The monthly posting workflow reads the derived field
for the current period and emits the GL posting.

**Alternative considered**: Materialise a `DepreciationSchedule`
table per asset with one row per month. Rejected — that's the ADR-031
anti-pattern (storing what can be calculated), wastes space, and
creates a synchronisation surface (asset edited → schedule must be
regenerated). Derived fields stay fresh by definition.

### D3 — Parallel commercial / fiscal streams as two calculated fields

Parallel commercial vs fiscal streams are two
`x-openregister-calculations` fields (`commercialBookValue`,
`fiscalBookValue`) computed from the same source fields with
different rates (`commercialRate`, `fiscalRate`). Each posts to a
dedicated sub-account or `bookSet` dimension so the trial balance can
filter.

**Alternative considered**: Two separate asset records per asset (one
commercial, one fiscal). Rejected — doubles the storage and the
operator's mental model; the divergent rates are a property of the
single asset, not two distinct entities.

### D4 — Monthly run is an OR `ScheduledWorkflow`, not a TimedJob

Per ADR-031 §"Background jobs" path 2, the monthly depreciation run
MUST be an OR `ScheduledWorkflow` + n8n adapter, not a shillinq-side
`DepreciationJob extends TimedJob`. The workflow reads each active
asset's derived `monthlyDepreciation`, emits a balanced
`GLTransaction` per asset, and writes an audit row.

**Alternative considered**: Author a per-app `TimedJob`. Rejected per
ADR-031.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Asset state machine | `x-openregister-lifecycle` (ADR-031) | Declarative — no PHP state machine |
| Depreciation values | `x-openregister-calculations` (ADR-031) | Derived fields, no schedule table |
| Monthly depreciation posting run | OR `ScheduledWorkflow` + n8n adapter (ADR-031 path 2) | Operator-configurable cadence; no per-app TimedJob |
| Disposal closing journal | `x-openregister-lifecycle` action on `FixedAsset.active → disposed` | Declarative — emits `JournalEntry` via CloudEvent |
| Audit trail | OR audit-trail-immutable | Consumed automatically |
| RBAC | OR authorization | Per-schema role definitions |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4) | Adds 1 menu entry + 1 index/detail page pair |
| Acquisition document storage | docudesk attachment URI | Referenced from `FixedAsset` record |

**Net new code in implementation cycle**: 1 schema declaration + 1
manifest entry pair + 1 scheduled-workflow record. No new PHP service.

## Seed Data

None. `FixedAsset` records are administration-specific and accumulate
through operation; no template ships in this change.
