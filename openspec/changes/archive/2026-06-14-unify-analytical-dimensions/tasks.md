# Tasks — Unify analytical dimensions

## Phase 0: Deduplication Check (ADR-012)

- [ ] Confirm the THREE overlapping registers exist as described and document
      their exact current shape:
  - [ ] `CostCenter` (`lib/Settings/shillinq_register.json` ~L4575) — full
        props incl. `budget`, `spentToDate`, `allocatedBudget`,
        `organizationId`, `responsibleUser`, `parentCode`, `status`,
        `ondernemingsActiviteit`, segment-P&L + budget aggregations/calcs.
  - [ ] `KostenDrager` (`shillinq_register.json` ~L4794) — under-built subset
        (`code`, `name`, `parentCode`, `responsibleUser`, `lifecycleState`,
        `administrationId`), stub `segmentPnl` only. Description self-admits
        *"Same shape as CostCenter; the distinction is semantic"*.
  - [ ] `AnalyticalDimension` (`lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json`)
        — generic operator-defined dimension with `dataType`, `isHierarchical`,
        `referenceRegister`, `referenceSchema`, `sortOrder`.
- [ ] Confirm NO unify/dimension-merge change already exists in
      `openspec/changes/` (only `bookkeeping-cost-centers-dimensions`, which
      *created* `AnalyticalDimension` — this change *extends + folds into* it,
      no overlap).
- [ ] Enumerate every FK consumer that references a dimension **by code** so the
      migration can guarantee stability:
  - [ ] `GLLine.costCenterCode`, `GLLine.kostenDragerCode`, `GLLine.dimensions`
        (`shillinq_register.json` ~L815–844) + their `x-openregister-relations`.
  - [ ] `ActivityCostAllocation.kostenplaatsCode` / `.kostendragerCode` /
        `splits[].kostendrager` (`bookkeeping-market-government-separation.json`).
  - [ ] `VpbBalansLink.costCenterId` (`shillinq_register.json` ~L23671) — the
        `ondernemingsActiviteit` Vpb cluster.
- [ ] Confirm CRUD stays on OpenRegister's generic object surface (ADR-022) — no
      app-local controller/service/Mapper is added for dimensions.

## Phase 1: Spec

- [ ] Author the delta spec under
      `specs/bookkeeping-cost-centers-dimensions/spec.md` (REQ prefix `ADIM`):
      ADDED (unified model + `dimensionType` + migration), MODIFIED (FK
      consumers re-targeted to the unified register), REMOVED (`KostenDrager`
      register; `CostCenter` folded).
- [ ] Validate `openspec validate unify-analytical-dimensions --strict`.

## Phase 2: Schema fragment — unify the register (ADR-031/ADR-037)

Edit `lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json`:

- [ ] Add `dimensionType` enum (`cost-center` / `cost-object` / `custom`,
      required) to `AnalyticalDimension`.
- [ ] Add the cost-center-only properties to `AnalyticalDimension`: `status`
      (alias), `budget`, `spentToDate` (read-only), `allocatedBudget`
      (read-only), `organizationId`, `responsibleUser`, `ondernemingsActiviteit`
      (boolean, default false).
- [ ] Make `dataType` required only when `dimensionType = custom` (conditional
      requirement / validation note); constrain `budget` /
      `ondernemingsActiviteit` to `dimensionType = cost-center`.
- [ ] Move the `x-openregister-lifecycle` (active/blocked/archived + block /
      unblock / archive transitions) and the `parentCode` self-relation onto
      `AnalyticalDimension` (drawn from `CostCenter`).
- [ ] Re-home the aggregations onto the unified register:
  - [ ] `segmentPnl` + `spentToDate` + `allocatedBudget` calc onto
        `AnalyticalDimension`, scoped `dimensionType = cost-center`.
  - [ ] `GLLine.byCostCenter` / `byCostCenterHierarchy` join filter
        `dimensionType = cost-center`.
  - [ ] Add `GLLine.byCostObject` (join `GLLine.kostenDragerCode →
        AnalyticalDimension.code` filtered `dimensionType = cost-object`) —
        replaces `KostenDrager.segmentPnl`.
  - [ ] `GLLine.byAnalyticalDimension` filter `dimensionType = custom`.
- [ ] Rewrite the fragment's seed objects to carry `dimensionType`
      (`CostCenter` seeds → `cost-center`; `AnalyticalDimension` seeds →
      `custom`) so greenfield installs land the unified shape with no migration.

## Phase 3: Re-target FK consumers (MODIFIED)

- [ ] `GLLine.costCenterRelation` → `relatedSchema: AnalyticalDimension`,
      `relatedField: code`, with `dimensionType = cost-center` condition.
- [ ] `GLLine.kostenDragerRelation` → `relatedSchema: AnalyticalDimension`,
      `dimensionType = cost-object`. Field name `kostenDragerCode` + stored
      values UNCHANGED.
- [ ] `GLLine.dimensions` validating relation → `AnalyticalDimension` filtered
      `dimensionType = custom` (already its intent).
- [ ] `VpbBalansLink.costCenter` relation → `relatedSchema: AnalyticalDimension`
      filtered `dimensionType = cost-center`; keep the
      `ondernemingsActiviteit = true` invariant in the description + scenario.
- [ ] `ActivityCostAllocation` — no field rename; note that
      `kostenplaatsCode` / `kostendragerCode` resolve to the unified register by
      `code`.

## Phase 4: Migration (ADR-037 — never silently drop)

- [ ] Add `lib/Repair/UnifyAnalyticalDimensions.php` (idempotent, fail-soft):
  - [ ] Upsert every `CostCenter` → `AnalyticalDimension`
        (`dimensionType=cost-center`), copying all props; key on
        `(administrationId, code, cost-center)`.
  - [ ] Upsert every `KostenDrager` → `AnalyticalDimension`
        (`dimensionType=cost-object`); key on `(administrationId, code,
        cost-object)`.
  - [ ] Idempotent re-run: skip already-migrated records; never duplicate;
        never mutate migrated state.
  - [ ] Do NOT delete source objects in this step.
- [ ] Register the step in `lib/AppInfo/Application.php`
      (`registerRepairStep` / `repair` event).

## Phase 5: Remove the redundant registers (REMOVED) — AFTER migration

- [ ] Remove the `KostenDrager` schema declaration from
      `shillinq_register.json` (register retired; values live on as
      `dimensionType=cost-object` rows keyed by `code`).
- [ ] Remove the standalone `CostCenter` schema declaration from
      `shillinq_register.json` (folded into `AnalyticalDimension`
      `dimensionType=cost-center`).
- [ ] Verify no remaining `relatedSchema: CostCenter` / `relatedSchema:
      KostenDrager` references survive (re-targeted in Phase 3); `add-shillinq-audit-trail.json`,
      `inventory-valuation-fifo-avg.json` references swept.

## Phase 6: Nav — filtered views + label unification (menu-layout.json + manifest.d)

- [ ] In `src/manifest.d/bookkeeping-cost-centers-dimensions.json`, point the
      `CostCenters` / `KostenDragers` / `AnalyticalDimensions` index pages at
      `AnalyticalDimension` with a `dimensionType` filter
      (`cost-center` / `cost-object` / `custom` respectively).
- [ ] Unify labels via l10n keys (EN primary): `Cost centers`,
      `Cost objects`, `Analytical dimensions`; NL: `Kostenplaatsen`,
      `Kostendragers`, `Analytische dimensies`.
- [ ] Keep all three leaves routable (deep links / e2e) per the established
      `menu-layout.json` `removals` pattern — no leaf is removed.

## Phase 7: Verify

- [ ] `openspec validate unify-analytical-dimensions --strict` passes.
- [ ] Migration runs idempotently on `occ maintenance:repair` (re-run = no-op).
- [ ] Golden-number check: post-migration `segmentPnl` / `spentToDate` for any
      cost-center `code` equals the pre-migration value.
- [ ] `VpbBalansLink` for an `ondernemingsActiviteit` cluster still resolves.
- [ ] `composer check:strict` green (PHPCS, PHPMD, Psalm, PHPStan) on the new
      Repair step.
