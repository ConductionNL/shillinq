# Design — Unify analytical dimensions

## Problem restated

Three registers tag a GL line with an analytical dimension; two of them
(`CostCenter`, `KostenDrager`) are admittedly *"the same shape; the distinction
is semantic"*, and the third (`AnalyticalDimension`) already models "an
operator-extensible analytical tag" generically. The result:

- **Duplication** — `KostenDrager` is a strict under-built subset of
  `CostCenter` (no budget, no `ondernemingsActiviteit`, no segment-P&L budget
  roll-up). Two lifecycle blocks, two relation graphs, two nav homes for what
  is conceptually one concept split by purpose.
- **Inconsistent depth** — the cost-center surface has budgets + aggregations +
  calculations; the kostendrager surface has a stub `segmentPnl` and nothing
  else; the analytical-dimension surface has `dataType` / `referenceSchema`
  metadata neither of the other two has.
- **Label drift** — `Cost Center` (EN) / `Kostendrager` (NL) / `Analytical
  Dimension` (EN) live in three nav leaves with no shared labelling rule.

## Decision

**One canonical register: `AnalyticalDimension`, discriminated by
`dimensionType`.**

`dimensionType` enum: `cost-center` | `cost-object` | `custom`.

- `cost-center` — the kostenplaats. Carries the cost-center-only properties
  (`budget`, `spentToDate`, `allocatedBudget`, `organizationId`,
  `responsibleUser`, `ondernemingsActiviteit`) and the budget aggregations /
  calculations. `dataType` is fixed to `string` (the value is a code, not a
  typed scalar).
- `cost-object` — the kostendrager. Same hierarchy + lifecycle, **omits**
  budget/`ondernemingsActiviteit` (they were never built on `KostenDrager`
  anyway). `dataType = string`.
- `custom` — the operator-defined dimension (Region, Product Line, Department).
  Keeps the existing `dataType` / `isHierarchical` / `referenceRegister` /
  `referenceSchema` / `sortOrder` metadata; surfaces through the free-form
  `GLLine.dimensions` map exactly as today.

`code` remains the stable, operator-facing key and stays **unique per
(administration, dimensionType)** — a kostenplaats `KC-100` and a custom
dimension `KC-100` could legitimately coexist, so the uniqueness scope widens
from "per administration" to "per administration + dimensionType". In practice
the migration preserves every existing code unchanged.

### Why fold INTO `AnalyticalDimension` (not into `CostCenter`)?

`AnalyticalDimension` is already the generically-named, operator-extensible
register and already owns the `byAnalyticalDimension` aggregation plumbing. Its
name does not bake in one of the three purposes. Folding the two
purpose-specific registers into the generic one is the ADR-012-correct
direction; folding the generic one into `CostCenter` would mis-name the result.
The discriminator pattern mirrors the decidesk `Decision` supertype refactor
(`decisionType`) — one supertype register, behaviour adapted by an enum, not
parallel entities.

### Properties matrix (post-unification)

| Property | cost-center | cost-object | custom | Notes |
|---|---|---|---|---|
| `code`, `name`, `description` | ✓ | ✓ | ✓ | shared identity |
| `dimensionType` | ✓ | ✓ | ✓ | discriminator, required |
| `parentCode` | ✓ | ✓ | ✓ (when `isHierarchical`) | self-relation, hierarchy preserved |
| `lifecycleState` | ✓ | ✓ | ✓ | active/blocked/archived |
| `administrationId` | ✓ | ✓ | ✓ | scoping |
| `status` (alias) | ✓ | — | — | legacy convenience alias of lifecycleState |
| `budget`, `spentToDate`, `allocatedBudget` | ✓ | — | — | cost-center only |
| `organizationId`, `responsibleUser` | ✓ | ✓ | — | owner metadata |
| `ondernemingsActiviteit` | ✓ | — | — | **Vpb addressable — MUST survive** |
| `dataType`, `isHierarchical`, `referenceRegister`, `referenceSchema`, `sortOrder` | — | — | ✓ | custom-dimension metadata |

`x-openregister-conditional-required` (or the documented validation note) keeps
`dataType` required only when `dimensionType = custom`, and constrains
`ondernemingsActiviteit` / `budget` to `dimensionType = cost-center`.

## Aggregations

The aggregations already declared in
`bookkeeping-cost-centers-dimensions.json` on `GLLine`
(`byCostCenter`, `byCostCenterHierarchy`, `byAnalyticalDimension`) and the
`segmentPnl` / `spentToDate` aggregations on `CostCenter` are **re-homed** onto
the unified register:

- `GLLine.byCostCenter` keeps joining `GLLine.costCenterCode →
  AnalyticalDimension.code` but the join filters `dimensionType = cost-center`.
- A sibling `byCostObject` join on `GLLine.kostenDragerCode` filters
  `dimensionType = cost-object` (replaces `KostenDrager.segmentPnl`).
- `segmentPnl` / `spentToDate` / `allocatedBudget` move onto
  `AnalyticalDimension` and are scoped `dimensionType = cost-center` (budget
  arithmetic is meaningless for custom dimensions).

No PHP aggregation service is added — all declarative per ADR-031.

## Migration (the load-bearing part)

`lib/Repair/UnifyAnalyticalDimensions.php` (registered in `Application.php`
`registerRepairStep`, runs on `occ maintenance:repair` and on
`occ app:enable shillinq`). Idempotent, fail-soft, never destructive:

1. List every `CostCenter` object via OpenRegister `ObjectService::findAll`.
   For each, **upsert** an `AnalyticalDimension` keyed by
   `(administrationId, code, dimensionType=cost-center)`, copying every property
   verbatim (`budget`, `spentToDate`→recompute is fine, `ondernemingsActiviteit`,
   `organizationId`, `responsibleUser`, `parentCode`, `status`,
   `lifecycleState`, `description`). Preserve the original UUID where the OR
   surface allows, otherwise record a `migratedFrom` provenance pointer.
2. List every `KostenDrager` object. Upsert an `AnalyticalDimension` keyed by
   `(administrationId, code, dimensionType=cost-object)`, copying `code`,
   `name`, `parentCode`, `responsibleUser`, `lifecycleState`,
   `administrationId`.
3. **Collision guard:** if a `cost-center` and a `cost-object` share the same
   `code` in the same administration (legal today — different registers), the
   `dimensionType` discriminator keeps them distinct, so no merge/clobber
   occurs. Logged at info level.
4. Idempotency: re-running finds the unified records already present (matched on
   the `(administrationId, code, dimensionType)` natural key) and skips. No
   duplicates, no state mutation of already-migrated records.
5. The step **does not delete** the source `CostCenter` / `KostenDrager`
   objects — the schema-removal step (below) does that only after a verified
   migration, and only the *register declarations* are removed; FK consumers
   reference `code`, which is preserved.

The existing seed objects in `bookkeeping-cost-centers-dimensions.json` (3
`CostCenter` + 2 `AnalyticalDimension` seeds) are rewritten in-fragment to
carry `dimensionType` so a fresh install lands the unified shape directly
(no migration needed on greenfield).

## Compatibility / FK stability

Everything that references a dimension does so **by `code`**, which the
migration preserves:

- `GLLine.costCenterCode` → still resolves; relation retargeted to
  `AnalyticalDimension.code` (filtered `dimensionType = cost-center`).
- `GLLine.kostenDragerCode` → relation retargeted to `AnalyticalDimension.code`
  (filtered `dimensionType = cost-object`). The field name and stored values are
  untouched.
- `GLLine.dimensions[code]` (custom) → unchanged; the validating relation still
  resolves to `AnalyticalDimension.code` filtered `dimensionType = custom`.
- `ActivityCostAllocation.kostenplaatsCode` / `.kostendragerCode` and the nested
  `splits[].kostendrager` → unchanged values; resolve to the unified register by
  code.
- `VpbBalansLink.costCenterId` → unchanged values; the `ondernemingsActiviteit`
  flag survives on the `cost-center` rows, so the link's "MUST reference a
  cost-center with `ondernemingsActiviteit: true`" invariant still holds.

No FK field is renamed; no stored code changes. This is a register-shape change,
not a data-key change.

## Nav (menu-layout.json)

The three leaves stay readable but back onto the one register via filtered
index views:

- `CostCenters` → index over `AnalyticalDimension` filtered
  `dimensionType = cost-center`.
- `KostenDragers` → index filtered `dimensionType = cost-object`.
- `AnalyticalDimensions` → index filtered `dimensionType = custom`.

Per the established `menu-layout.json` pattern, no leaf is *removed* (their
pages stay routable for deep links + e2e); the index pages' `objectConfig` gains
a `dimensionType` filter and the labels are unified (EN primary + NL secondary
via the standard l10n keys: `Cost centers` / `Kostenplaatsen`,
`Cost objects` / `Kostendragers`, `Analytical dimensions` /
`Analytische dimensies`). The fragment continues to live in
`src/manifest.d/bookkeeping-cost-centers-dimensions.json`.

## Alternatives considered

1. **Keep three registers, just document the overlap.** Rejected — leaves the
   ADR-012 duplication in place and the under-built `KostenDrager` keeps
   drifting. The whole point is to collapse.
2. **Fold everything into `CostCenter` and rename.** Rejected — `CostCenter`
   bakes "kostenplaats" into its name; the generic `AnalyticalDimension` is the
   honest supertype name.
3. **Drop `KostenDrager` entirely (no cost-object concept).** Rejected — the
   kostendrager is a real, distinct Dutch cost-accounting concept and is
   referenced by `GLLine.kostenDragerCode` + `ActivityCostAllocation`. It must
   survive as a `dimensionType` value.
4. **Hard-migrate then delete source objects in the same step.** Rejected — the
   ADR-037 migration rule says never silently drop; we upsert, verify, and only
   then retire the register declarations (FK consumers key on `code`, which is
   never deleted).

## Risks

- **Aggregation rewrite drift.** Moving `segmentPnl` / `spentToDate` /
  `byCostCenter` onto the unified register with a `dimensionType` filter could
  silently change a sum if the filter is wrong. Mitigation: the spec requires
  the post-migration segment-P&L for any cost-center `code` to equal the
  pre-migration value (golden-number scenario REQ-ADIM-008).
- **Code collision across types.** Mitigated by widening uniqueness to
  `(administration, dimensionType)` and the discriminator-keyed upsert.
- **VpbBalansLink invariant.** The `ondernemingsActiviteit: true` constraint
  must keep resolving. Covered by REQ-ADIM-005 + a dedicated scenario.
- **Re-run safety.** The Repair step must be idempotent; covered by REQ-ADIM-007
  and a re-run scenario.
