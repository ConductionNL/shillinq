# Proposal: unify-analytical-dimensions

`kind: config + migration` per ADR-031/ADR-037 — collapses three overlapping
analytical-tagging registers into ONE canonical `AnalyticalDimension` register
distinguished by a `dimensionType` enum, all declared in the existing ADR-037
fragment `lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json`
(NOT the monolith `shillinq_register.json`). One ADR-031 exception-path PHP
unit only: an idempotent `lib/Repair/*` migration that folds existing
`CostCenter` + `KostenDrager` objects into the unified register. No app-local
CRUD controller/service is added — CRUD stays on OpenRegister's generic object
surface (ADR-022).

## Summary

Shillinq today has **three overlapping mechanisms** to tag a GL line with an
analytical dimension, and they are partially redundant:

1. **`CostCenter`** (kostenplaats) — the fully-built dimension: `code`, `name`,
   `description`, `status`, `budget`, `spentToDate`, `allocatedBudget`,
   `organizationId`, `parentCode`, `responsibleUser`, `lifecycleState`,
   `administrationId`, `ondernemingsActiviteit`. Hierarchy + segment-P&L
   aggregations + budget roll-up. Referenced by `GLLine.costCenterCode` and by
   `VpbBalansLink.costCenterId` (the `ondernemingsActiviteit` Vpb cluster).
2. **`KostenDrager`** (kostendrager / cost object) — the schema description
   literally says *"Same shape as CostCenter; the distinction is semantic"*,
   yet it is **under-built** (only `code`, `name`, `parentCode`,
   `responsibleUser`, `lifecycleState`, `administrationId` — no budget, no
   `ondernemingsActiviteit`). Referenced by `GLLine.kostenDragerCode`.
3. **`AnalyticalDimension`** (operator-defined: Region, Product Line,
   Department) — the *meta* register declared in
   `bookkeeping-cost-centers-dimensions`; its declared codes become keys in the
   free-form `GLLine.dimensions` map. Props: `dataType`, `isHierarchical`,
   `referenceRegister`, `referenceSchema`, `sortOrder`.

This is exactly the kind of accidental triplication ADR-012 exists to prevent:
**kostenplaats and kostendrager are not separate registers — they are two
semantic *values* of "analytical dimension"** in Dutch cost accounting (the
kostenplaats is *where* a cost lands, the kostendrager is *what bears* it), and
`AnalyticalDimension` already models "an operator-extensible analytical tag."

**Goal:** collapse to ONE canonical analytical-dimension model — the existing
`AnalyticalDimension` register, extended with a `dimensionType` enum
(`cost-center` / `cost-object` / `custom`) plus the cost-center-specific
properties (`budget`, `spentToDate`, `allocatedBudget`, `organizationId`,
`responsibleUser`, `ondernemingsActiviteit`) that only apply when
`dimensionType = cost-center`. The Dutch cost-accounting semantics survive as
*values* of `dimensionType`, not as parallel registers. Hierarchy
(`parentCode`), budgets (cost-center keeps them; cost-object/custom may omit),
and the `ondernemingsActiviteit` flag (still addressable by `VpbBalansLink`) are
all preserved.

The nav stays readable: the `CostCenters`, `KostenDragers`, and
`AnalyticalDimensions` leaves become **filtered views of the one register** by
`dimensionType`, and the English/Dutch label inconsistency (`Cost Center`
vs `Kostendrager` vs `Analytical Dimension`) is unified under one bilingual
labelling convention.

This change does **not** silently drop data: an idempotent
`lib/Repair/UnifyAnalyticalDimensions` step (per the ADR-037
"migrations of existing objects" rule) converts every existing `CostCenter` and
`KostenDrager` object into an `AnalyticalDimension` with the correct
`dimensionType`, preserving `code` (so all FK references stay stable). The
redundant `CostCenter` and `KostenDrager` **registers** are removed only AFTER
the migration runs; `GLLine.costCenterCode` / `GLLine.kostenDragerCode`,
`ActivityCostAllocation.kostenplaatsCode` / `.kostendragerCode`, and
`VpbBalansLink.costCenterId` keep referencing dimensions **by `code`**, which is
preserved byte-for-byte.

**Depends on:**
- `bookkeeping-cost-centers-dimensions` (owns the `AnalyticalDimension` schema
  + the `GLLine.byCostCenter` / `byProject` / `byAnalyticalDimension`
  aggregations this change re-homes onto `dimensionType`)
- `bookkeeping-vpb-corporate-tax-balans` (`VpbBalansLink` references the
  `ondernemingsActiviteit` cost-center by `code` — the flag MUST stay
  addressable)
- `bookkeeping-market-government-separation` (`ActivityCostAllocation` matches
  `CommercialActivity` on `kostenplaats` / `kostendrager` codes)
- `bookkeeping-general-ledger` (`GLLine.costCenterCode` / `kostenDragerCode` /
  `projectCode` / `dimensions` — the consuming FK surface)
- `shillinq-notifications` (x-openregister-notifications rule conventions)
- ADR-037 modular config fragments + `src/menu-layout.json` (nav relocations /
  filtered views)
