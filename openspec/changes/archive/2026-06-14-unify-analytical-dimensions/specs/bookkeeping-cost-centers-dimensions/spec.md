# Spec: unify-analytical-dimensions

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (operations / analytical model)
**Depends on:**
- `bookkeeping-cost-centers-dimensions` (owns the `AnalyticalDimension` schema + the `byCostCenter` / `byProject` / `byAnalyticalDimension` GL aggregations re-homed here)
- `bookkeeping-vpb-corporate-tax-balans` (`VpbBalansLink.costCenterId` → the `ondernemingsActiviteit` cost-center cluster, by code)
- `bookkeeping-market-government-separation` (`ActivityCostAllocation` matches `CommercialActivity` on `kostenplaats` / `kostendrager` codes)
- `bookkeeping-general-ledger` (`GLLine.costCenterCode` / `kostenDragerCode` / `projectCode` / `dimensions` — the consuming FK surface)
- `shillinq-notifications` (x-openregister-notifications rule conventions)
- ADR-031 (declarative business logic), ADR-037 (modular config fragments + `src/menu-layout.json`)

## ADDED Requirements

### Requirement: REQ-ADIM-001 — The system SHALL provide ONE canonical analytical-dimension register discriminated by a `dimensionType` enum

There MUST be a single OpenRegister-managed register, `AnalyticalDimension`,
declared in the ADR-037 fragment
`lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json` (NOT the
monolith `shillinq_register.json`), that models every analytical tagging
dimension in shillinq. The register MUST carry a required `dimensionType`
property with enum values `cost-center`, `cost-object`, `custom`. CRUD MUST go
through OpenRegister's generic object surface (ADR-022); no app-local
controller, service, or Mapper is added for dimensions.

`code` remains the stable operator-facing key and MUST be unique per
`(administrationId, dimensionType)`. The shared identity properties (`code`,
`name`, `description`, `parentCode`, `lifecycleState`, `administrationId`) apply
to every `dimensionType`.

#### Scenario: One register replaces three

- **GIVEN** the shillinq register declarations after this change
- **WHEN** the schemas are listed
- **THEN** exactly ONE analytical-dimension register (`AnalyticalDimension`)
  MUST exist, carrying a required `dimensionType` enum
  (`cost-center` / `cost-object` / `custom`)
- **AND** no separate `CostCenter` or `KostenDrager` register declaration MUST
  remain (see REQ-ADIM-006)

#### Scenario: Reviewer confirms no parallel CRUD

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for an app-local controller / service / Mapper wrapping
  dimension CRUD
- **THEN** none MUST exist; dimension state lives only in the
  `AnalyticalDimension` register reached via OpenRegister's object API

### Requirement: REQ-ADIM-002 — The unified register SHALL preserve Dutch cost-accounting semantics as values of `dimensionType`, not as separate registers

The kostenplaats (`cost-center`) and the kostendrager (`cost-object`) MUST
survive as distinct, meaningful **values** of `dimensionType`, retaining their
Dutch cost-accounting distinction (a kostenplaats is *where* a cost lands; a
kostendrager is *what bears* it). The cost-center-specific properties MUST be
present on the unified register and apply ONLY when `dimensionType = cost-center`:
`status` (alias of `lifecycleState`), `budget`, `spentToDate` (read-only),
`allocatedBudget` (read-only), `organizationId`, `responsibleUser`, and
`ondernemingsActiviteit` (boolean, default false). The custom-dimension metadata
(`dataType`, `isHierarchical`, `referenceRegister`, `referenceSchema`,
`sortOrder`) MUST be present and apply when `dimensionType = custom`;
`dataType` MUST be required only when `dimensionType = custom`.

#### Scenario: Cost-center keeps its budget surface

- **GIVEN** an `AnalyticalDimension` with `dimensionType = cost-center`
- **WHEN** it is created with a `budget` and `ondernemingsActiviteit = true`
- **THEN** both properties MUST be accepted and persisted, and `spentToDate` /
  `allocatedBudget` MUST be derivable via the aggregations of REQ-ADIM-004

#### Scenario: Cost-object omits budget but keeps hierarchy

- **GIVEN** an `AnalyticalDimension` with `dimensionType = cost-object`
- **WHEN** it is created with `code`, `name`, `parentCode`, `responsibleUser`
- **THEN** it MUST persist, the `parentCode` self-relation MUST resolve for
  hierarchy navigation, and no `budget` / `ondernemingsActiviteit` is required

#### Scenario: Custom dimension requires a dataType

- **GIVEN** an `AnalyticalDimension` with `dimensionType = custom`
- **WHEN** it is created without `dataType`
- **THEN** validation MUST reject it; a `cost-center` or `cost-object` created
  without `dataType` MUST be accepted

### Requirement: REQ-ADIM-003 — Hierarchy SHALL be preserved across all dimension types via the `parentCode` self-relation

The unified register MUST keep the `parentCode` self-relation
(`localField: parentCode → AnalyticalDimension.code`, many-to-one) and the
`active` / `blocked` / `archived` lifecycle (with `block` / `unblock` /
`archive` transitions) carried over from `CostCenter`. Hierarchy MUST work
within a `dimensionType` (a `cost-center` parents a `cost-center`; a
`cost-object` parents a `cost-object`; a hierarchical `custom` dimension parents
its own values).

#### Scenario: Parent roll-up within a dimension type

- **GIVEN** two `cost-center` dimensions where child `KC-110.parentCode = KC-100`
- **WHEN** the segment-P&L hierarchy aggregation runs
- **THEN** the child's posted GL amounts MUST roll up to the parent via
  `parentCode`, exactly as before unification

### Requirement: REQ-ADIM-004 — Segment-P&L and budget aggregations SHALL be re-homed onto the unified register, scoped by `dimensionType`

The aggregations previously split across `CostCenter` (`segmentPnl`, `spentToDate`, `allocatedBudget` calc) and `KostenDrager` (`segmentPnl`) and the GL-side aggregations (`byCostCenter`, `byCostCenterHierarchy`, `byAnalyticalDimension`) MUST be declared on the unified register (and on `GLLine`) per ADR-031 — no PHP aggregation service runs:

- `segmentPnl`, `spentToDate`, and the `allocatedBudget` calculation MUST be on
  `AnalyticalDimension` and scoped `dimensionType = cost-center`.
- `GLLine.byCostCenter` / `byCostCenterHierarchy` MUST join
  `GLLine.costCenterCode → AnalyticalDimension.code` filtered
  `dimensionType = cost-center`.
- A `GLLine.byCostObject` aggregation MUST join `GLLine.kostenDragerCode →
  AnalyticalDimension.code` filtered `dimensionType = cost-object` (replacing
  the retired `KostenDrager.segmentPnl`).
- `GLLine.byAnalyticalDimension` MUST be scoped `dimensionType = custom`.

#### Scenario: Per-cost-center P&L unchanged after unification

- **GIVEN** GL lines tagged `costCenterCode = KC-100`
- **WHEN** the `byCostCenter` / `segmentPnl` aggregation runs on the unified
  register
- **THEN** the resulting per-cost-center totals MUST equal the pre-unification
  totals for `KC-100` (golden-number invariant, see REQ-ADIM-008)

#### Scenario: Cost-object P&L replaces the KostenDrager aggregation

- **GIVEN** GL lines tagged `kostenDragerCode = KD-001`
- **WHEN** the `byCostObject` aggregation runs
- **THEN** it MUST produce the same per-kostendrager P&L the retired
  `KostenDrager.segmentPnl` would have produced

### Requirement: REQ-ADIM-005 — The `ondernemingsActiviteit` flag SHALL stay addressable by `VpbBalansLink`

The `ondernemingsActiviteit` boolean MUST survive on `cost-center` rows of the
unified register so that `VpbBalansLink.costCenterId` continues to reference an
ondernemingsactiviteit cost-center **by code**, and the invariant "the
referenced cost-center MUST have `ondernemingsActiviteit: true`
(REQ-VPB-002)" MUST still hold. No `VpbBalansLink` field is renamed; its stored
`costCenterId` values are unchanged.

#### Scenario: Vpb-balans link resolves post-unification

- **GIVEN** a `VpbBalansLink` whose `costCenterId` is the code of a former
  `CostCenter` with `ondernemingsActiviteit: true`
- **WHEN** the migration has run and the `CostCenter` register is retired
- **THEN** the link MUST resolve to the matching `AnalyticalDimension`
  (`dimensionType = cost-center`, `ondernemingsActiviteit = true`) by `code`
- **AND** the `vpbBalansFiltered` aggregation MUST still produce its
  Activa / Passiva / Resultaat columns for that cluster

### Requirement: REQ-ADIM-006 — An idempotent migration SHALL fold existing `CostCenter` and `KostenDrager` objects into the unified register before any register is removed

`lib/Repair/UnifyAnalyticalDimensions.php` MUST be registered as a repair step
(`occ maintenance:repair` / `occ app:enable shillinq`) and MUST:

- Upsert every existing `CostCenter` object into an `AnalyticalDimension` with
  `dimensionType = cost-center`, copying every property verbatim
  (`code`, `name`, `description`, `status`, `budget`, `organizationId`,
  `parentCode`, `responsibleUser`, `lifecycleState`, `administrationId`,
  `ondernemingsActiviteit`).
- Upsert every existing `KostenDrager` object into an `AnalyticalDimension` with
  `dimensionType = cost-object`, copying `code`, `name`, `parentCode`,
  `responsibleUser`, `lifecycleState`, `administrationId`.
- Match on the natural key `(administrationId, code, dimensionType)` so a
  cost-center and a cost-object sharing a `code` remain distinct.
- Be idempotent (re-runs skip already-migrated records; no duplicates; no
  mutation of migrated state) and fail-soft (per-object failures are logged +
  warned, never fatal).
- NOT delete the source objects; only the register *declarations* are removed,
  and only after the migration (REQ-ADIM-007).

#### Scenario: CostCenter folds into cost-center dimension

- **GIVEN** a `CostCenter` `{ code: KC-100, budget: 250000, ondernemingsActiviteit: true }`
- **WHEN** the repair step runs
- **THEN** an `AnalyticalDimension` `{ code: KC-100, dimensionType: cost-center,
  budget: 250000, ondernemingsActiviteit: true }` MUST exist with the same
  `administrationId` and `code`

#### Scenario: KostenDrager folds into cost-object dimension

- **GIVEN** a `KostenDrager` `{ code: KD-001, name: "Product A" }`
- **WHEN** the repair step runs
- **THEN** an `AnalyticalDimension` `{ code: KD-001, dimensionType: cost-object,
  name: "Product A" }` MUST exist

#### Scenario: Re-run is a no-op

- **GIVEN** the repair step has already run once
- **WHEN** `occ maintenance:repair` runs it again
- **THEN** no duplicate `AnalyticalDimension` records MUST be created and no
  already-migrated record MUST be mutated

### Requirement: REQ-ADIM-007 — FK references to dimensions by `code` SHALL remain stable across the migration

Because the migration preserves `code`, every consumer that references a dimension by code MUST keep resolving without a data rewrite. No FK field is renamed and no stored code value changes:

- `GLLine.costCenterCode`, `GLLine.kostenDragerCode`, `GLLine.dimensions[code]`
- `ActivityCostAllocation.kostenplaatsCode`, `.kostendragerCode`,
  `splits[].kostendrager`
- `VpbBalansLink.costCenterId`

#### Scenario: GL line dimension tags resolve after migration

- **GIVEN** a posted `GLLine` with `costCenterCode = KC-100` and
  `kostenDragerCode = KD-001`
- **WHEN** the migration has run and the legacy registers are retired
- **THEN** both codes MUST resolve to the corresponding unified
  `AnalyticalDimension` records (filtered `cost-center` / `cost-object`
  respectively), and the stored field values MUST be unchanged

#### Scenario: ActivityCostAllocation matching unaffected

- **GIVEN** an `ActivityCostAllocation` matched on `kostenplaatsCode` /
  `kostendragerCode`
- **WHEN** the migration has run
- **THEN** the match MUST still resolve to the unified register by `code`; no
  allocation record is altered

### Requirement: REQ-ADIM-008 — Post-migration segment-P&L SHALL equal pre-migration values (golden-number invariant)

For any dimension `code`, the post-unification segment-P&L / `spentToDate` / budget roll-up produced by the re-homed aggregations MUST equal the value the pre-unification aggregations produced for the same `code`. A non-equal result is a migration defect, not an accepted drift.

#### Scenario: Golden number holds for a cost-center

- **GIVEN** the pre-migration `segmentPnl` total for `costCenterCode = KC-100`
- **WHEN** the unified `byCostCenter` / `segmentPnl` aggregation is evaluated
  after migration
- **THEN** the total MUST be identical (to two-decimal cents)

## MODIFIED Requirements

@e2e exclude unbuilt UI: the unified filtered index/detail pages and the segment-P&L drill-down are not yet rebuilt against `dimensionType`

### Requirement: REQ-ADIM-101 — GL-line dimension relations SHALL target the unified register filtered by `dimensionType`

The `GLLine` `x-openregister-relations` previously pointing at `CostCenter` and `KostenDrager` MUST be re-targeted to `AnalyticalDimension`:

- `costCenterRelation`: `localField: costCenterCode →
  AnalyticalDimension.code`, condition `dimensionType = cost-center`.
- `kostenDragerRelation`: `localField: kostenDragerCode →
  AnalyticalDimension.code`, condition `dimensionType = cost-object`.
- the `dimensions` map validation relation: `→ AnalyticalDimension.code`
  filtered `dimensionType = custom`.

Field names (`costCenterCode`, `kostenDragerCode`, `dimensions`) and stored
values MUST NOT change.

#### Scenario: GL relation re-targeted, field name preserved

- **GIVEN** the post-change `GLLine` schema
- **WHEN** its relations are inspected
- **THEN** `costCenterRelation` / `kostenDragerRelation` MUST point at
  `AnalyticalDimension` with the matching `dimensionType` condition, and the
  source field names MUST be unchanged

### Requirement: REQ-ADIM-102 — `VpbBalansLink.costCenter` relation SHALL target the unified register, retaining the ondernemingsactiviteit invariant

The `VpbBalansLink.costCenter` relation MUST point at `AnalyticalDimension`
(filtered `dimensionType = cost-center`), and the description MUST retain "the
referenced dimension MUST have `ondernemingsActiviteit: true` per REQ-VPB-002."
`costCenterId` is unchanged.

#### Scenario: Vpb relation re-targeted

- **GIVEN** the post-change `VpbBalansLink` schema
- **WHEN** its `costCenter` relation is inspected
- **THEN** it MUST resolve `costCenterId → AnalyticalDimension.code` filtered
  `dimensionType = cost-center, ondernemingsActiviteit = true`

### Requirement: REQ-ADIM-103 — The nav SHALL present cost-centers / cost-objects / custom dimensions as filtered views of the one register with unified labels

In `src/manifest.d/bookkeeping-cost-centers-dimensions.json`, the `CostCenters`, `KostenDragers`, and `AnalyticalDimensions` index pages MUST bind to `AnalyticalDimension` with a `dimensionType` filter
(`cost-center` / `cost-object` / `custom` respectively). Labels MUST be unified
via l10n keys (EN primary: `Cost centers`, `Cost objects`,
`Analytical dimensions`; NL: `Kostenplaatsen`, `Kostendragers`,
`Analytische dimensies`). Per the established `src/menu-layout.json` convention,
no leaf is removed — the pages stay routable for deep links and e2e specs; only
their backing query + labels change.

#### Scenario: Cost-centers nav shows only cost-center dimensions

- **GIVEN** the unified register holds `cost-center`, `cost-object`, and
  `custom` rows
- **WHEN** the operator opens the `CostCenters` nav leaf
- **THEN** the index MUST list only rows with `dimensionType = cost-center`,
  under the unified `Cost centers` / `Kostenplaatsen` label

#### Scenario: All three leaves stay routable

- **GIVEN** the post-change manifest + `menu-layout.json`
- **WHEN** `CostCenters`, `KostenDragers`, `AnalyticalDimensions` routes are
  visited directly
- **THEN** each MUST resolve to its filtered index page (no 404), consistent
  with the `menu-layout.json` deep-link-preservation pattern

## REMOVED Requirements

### Requirement: REQ-ADIM-201 — The standalone `KostenDrager` register SHALL be removed (folded into `dimensionType = cost-object`)

After the migration (REQ-ADIM-006), the `KostenDrager` schema declaration MUST
be removed from `lib/Settings/shillinq_register.json`. The concept survives as
`AnalyticalDimension` rows with `dimensionType = cost-object`, keyed by the same
`code`. `GLLine.kostenDragerCode` and `ActivityCostAllocation.kostendragerCode`
keep their field names and values (they resolve to the unified register).

**Reason:** the schema self-admitted *"Same shape as CostCenter; the distinction
is semantic"* and was an under-built subset — a textbook ADR-012 duplication.
The semantics are preserved as a `dimensionType` value.

#### Scenario: KostenDrager register no longer declared

- **GIVEN** the post-change register declarations
- **WHEN** schemas are listed
- **THEN** no `KostenDrager` schema MUST exist, and no relation MUST carry
  `relatedSchema: KostenDrager`

### Requirement: REQ-ADIM-202 — The standalone `CostCenter` register SHALL be removed (folded into `dimensionType = cost-center`)

After the migration, the standalone `CostCenter` schema declaration MUST be
removed from `lib/Settings/shillinq_register.json`. The kostenplaats concept,
its budget surface, hierarchy, lifecycle, and `ondernemingsActiviteit` flag
survive as `AnalyticalDimension` rows with `dimensionType = cost-center`, keyed
by the same `code`. `GLLine.costCenterCode`,
`ActivityCostAllocation.kostenplaatsCode`, and `VpbBalansLink.costCenterId` keep
their field names and values.

**Reason:** `CostCenter` is one of three values of a single analytical-dimension
concept; folding it into the generically-named supertype register (rather than
keeping a purpose-named register) is the ADR-012-correct collapse. The generic
register, not `CostCenter`, is the honest home (parallels the decidesk
`Decision`-supertype refactor).

#### Scenario: CostCenter register folded, no purpose-named relation survives

- **GIVEN** the post-change register declarations
- **WHEN** schemas + relations are listed
- **THEN** no standalone `CostCenter` schema MUST exist, and no relation MUST
  carry `relatedSchema: CostCenter` — every former reference points at
  `AnalyticalDimension` filtered `dimensionType = cost-center`
