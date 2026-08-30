# Design — GR Consolidation

**status: pr-created**

## Context

A gemeenschappelijke regeling (GR) is a separate juridical entity
funded by deelnemers (gemeenten / provincies / waterschappen) via
a quotum-verdeling. The GR has two bookkeeping surfaces: its own
jaarrekening, and a per-deelnemer doorbelasting that is posted in
each deelnemer's own administratie. Without dedicated consolidation
primitives, inter-GR boekingen leak into the consolidated rollup
and per-deelnemer doorbelastingen require either spreadsheets or
a separate product.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Eliminations as a declarative aggregation filter, not a materialised consolidated register

`GLLine` gains an optional `eliminationFlag: boolean` (default
`false`). Lines flagged true are excluded from the consolidated
trial-balance and jaarrekening aggregations via an
`x-openregister-aggregations.filter` clause (`WHERE eliminationFlag
= false`). The alternative (a separate `ConsolidatedTrialBalance`
register populated by a service) was rejected per the parent
envelope's design D2: the elimination view is trivially expressible
as an aggregation filter; materialising a consolidated table
introduces a sync problem and an extra source-of-truth.

### D2 — `GRDeelnemer` is a relational record, NOT a separate user-management primitive

`GRDeelnemer` is a simple shillinq register with `deelnemerType`,
`deelnemerNaam`, optional `administrationId` FK, `aandeel` (0 ≤ x ≤
1), and `actief` boolean. The optional `administrationId` is the
hook that lets cross-administration doorbelasting materialise — it
is NOT a hard FK because many deelnemers will not themselves run
shillinq.

### D3 — `GRVerdeelsleutel` separates "what cost cluster" from "how it is split"

`GRVerdeelsleutel` carries `costClusterAccountNumbers` (the array
of accountNumbers the sleutel applies to) and `verdelingsType` +
`parameters` (the per-deelnemer split rule). Multiple sleutels MAY
apply to the same cost cluster, sequenced by `lineNumber`. Common
verdelingstypes (vast-percentage, inwoner-aantal,
gewogen-oppervlak) ship as declarative shapes; `custom-formula`
is a free-form parameters JSON validated against the schema.

### D4 — Cross-administration doorbelasting materialisation rides the GR period-close trigger

When the GR period closes (per T3 `bookkeeping-period-close`), the
per-deelnemer doorbelasting aggregation runs; for each deelnemer
with `administrationId` set, a balanced `GLTransaction` materialises
in that administration with `sourceReference` pointing at the GR's
doorbelasting-rapport (a docudesk attachment URI). No app-local
cron, no app-local scheduler — the lifecycle hook is OR-native.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| GR own jaarrekening | T2 `bookkeeping-financial-statements` | Standard rollup filtered to `eliminationFlag = false`. No new aggregation primitive. |
| Per-deelnemer doorbelasting | `x-openregister-aggregations` | Grouped by deelnemer per applicable `GRVerdeelsleutel`. No PHP service. |
| GL transaction materialisation in deelnemer-administratie | T1 `bookkeeping-general-ledger` REQ-GL-001 | Balanced 2-line GLTransaction with sourceReference to the GR doorbelasting-rapport. |
| Period-close lifecycle trigger | T3 `bookkeeping-period-close` | Hook into the GR's period-close transition to fire the doorbelasting materialisation. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Both the GR-side and the deelnemer-side write events for the materialisation. |
| Lifecycle engine | `x-openregister-lifecycle` (ADR-031) | Active/archived state on `GRDeelnemer`. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.gov-gr` with 3 sub-pages. |

**Net new code in implementation cycle**: 2 schema declarations +
1 line field (`eliminationFlag`) + 1 manifest entry + 0 seed JSON
files (no GR-specific seed data; GR composition is operationally
authored). No new PHP service.

## Seed Data

None. `GRDeelnemer` and `GRVerdeelsleutel` are operationally
authored per administration; there is no canonical "every GR uses
these deelnemers" seed.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Cross-admin write requires careful authorization | Materialisation only on GR period-close approval; per ADR-022 audited in both administrations. |
| Quotum-aandeel sum drift across `actief` deelnemers | Aggregation invariant warns when sum ≠ 1.0; no hard refusal (custom-formulas may legitimately allocate partial aandelen). |
| `eliminationFlag` is operator-set on every inter-GR line | Sibling `add-shillinq-waterschappen-bbv-variant` and the GR's own posting screens default the flag based on the counterparty deelnemer; aggregation surfaces unflagged inter-GR lines as warnings. |
| Material doorbelasting bug duplicates posting on re-run of GR period close | Idempotency key: `(grAdministrationId, deelnemerId, periodId)`; re-running the close does not re-post. |
