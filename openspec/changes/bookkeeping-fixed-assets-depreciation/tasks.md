# Tasks — Fixed Assets & Depreciation

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-fixed-assets-depreciation` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-fixed-assets-depreciation` capability spec already exists, no `FixedAsset`/`DepreciationSchedule` schemas are declared, and no `lib/Service/Asset*` / `lib/Service/Depreciation*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "carries forward asset management as a core bookkeeping function" — see `notes.md` (FixedAsset carries forward from sibling change; DepreciationSchedule new; only single-method `DepreciationCalculator` per ADR-031 exception)
- [x] Task 2: Author `specs/bookkeeping-fixed-assets-depreciation/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger, bookkeeping-chart-of-accounts` header, `REQ-FA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline — header `ADRs:` block added; REQ-FA-001..010 already use RFC 2119 + Scenario blocks
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (Float Precision availability, depreciation-calculator stability, internal-transfer GL impact, method-switching constraints) / Rollback / Open Questions — already authored at `proposal.md` (Risks 1–4 cover Float Precision, calculator stability, internal-transfer, useful-life-audit; Rollback Strategy + Open Questions covered)
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (sub-ledger materialises GL), D2 (OR declarative calculation with PHP-calculator fallback), D3 (float-precision rules), D4 (immutable schedule records), D5 (internal-transfer proportional adjustment), D6 (depreciation aggregations) — already authored at `design.md` (D1–D6 covered, Reuse Analysis table present)
- [x] Task 5: Declare the `FixedAsset` schema in `lib/Settings/shillinq_register.json` with all REQ-FA-002 fields (assetNumber, name, assetType, purchaseDate, purchaseCost, residualValue, usefulLifeYears, depreciationMethod, declineRate, productionUnits, account mappings, location, costCenterCode, status, retirementDate, administrationId) — ADR-037 fragment `lib/Settings/register.d/bookkeeping-fixed-assets-depreciation.json` unions REQ-FA-002 operator-facing field aliases on top of the existing FixedAsset schema (assetType/usefulLifeYears/purchaseDate/purchaseCost/declineRate/productionUnits/capitalizationAccountNumber/accumulatedDepreciationAccountNumber/location/costCenterCode/status/retirementDate/salvageProceeds/transferSourceAssetRef/description); base-schema fields (assetNumber/name/residualValue/depreciationMethod/assetAccountNumber/accumulatedDepAccountNumber/depreciationExpenseAccountNumber/administrationId) carried forward as-is per ADR-037
- [x] Task 6: Declare the `DepreciationSchedule` schema in `lib/Settings/shillinq_register.json` with all REQ-FA-003 fields (scheduleNumber, assetRef, depreciationMethod, annualRate, rateType, periodStartDate, periodEndDate, depreciationAmount, accumulatedDepreciation, bookValue, fiscalYear, status, administrationId) — bookValue is computed not stored — declared in the ADR-037 fragment with x-openregister-calculations.bookValue (asset.acquisitionCost − accumulatedDepreciation), x-openregister-relations to FixedAsset + Administration + GLTransaction, RBAC roles (bookkeeper/auditor/admin), and aggregations (depreciationAmountByCostCenter/depreciationAmountByMethod/accumulatedDepreciationByAsset)
- [x] Task 7: Add `x-openregister-lifecycle` to `FixedAsset` declaring every transition in REQ-FA-004 (`active` ↔ `inactive` → `retired`) with automatic depreciation-schedule generation on acquisition; auto-fire yearly depreciation-expense posting via OR scheduled-workflow (or single-method `DepreciationCalculator` fallback per ADR-031 exception, documented) — ADR-037 fragment unions `activate` action with `type: emit-journal-entry-and-schedule` (REQ-FA-004 first-year schedule auto-generation), plus `transferInternal` and `splitTransfer` transitions (REQ-FA-006); yearly auto-fire continues via the `shillinq-fixed-assets-monthly-depreciation` ScheduledWorkflow registered in `lib/Repair/InitializeSettings.php` Phase 4b (REQ-FA-007); DepreciationCalculator (ADR-031 exception) is the deterministic kernel
- [x] Task 8: Implement Float Precision integration per REQ-FA-005 — at depreciation-calculation time, query Nextcloud System Settings for Float Precision and round all rate calculations to the configured decimal places; fallback to 2 decimal places (Dutch standard) if setting is unavailable — `DepreciationCalculator::applyFloatPrecision(float $value, ?int $floatPrecision=null): float` rounds to the supplied Float Precision value; `FLOAT_PRECISION_FALLBACK = 2` (Dutch accounting standard) used when null; persisted on each `DepreciationSchedule.calculationFloatPrecision` so re-runs are deterministic
- [x] Task 9: Implement internal asset transfer handling per REQ-FA-006 — transfer action updates `costCenterCode` on the asset and adjusts the depreciation schedule; proportional splits create new `FixedAsset` records with separate schedules; no GL posting for internal transfers — `transferInternal` action declares `update-cost-center` with `newCostCenterCode` input; `splitTransfer` action declares `split-asset` with `splitPercentage` + `newCostCenterCode` inputs; `DepreciationCalculator::splitTransferAllocations()` computes the proportional allocations for `purchaseCost` and `residualValue`, both keeping their `transferSourceAssetRef` pointer back to the original
- [x] Task 10: Implement asset acquisition GL posting per REQ-FA-008 — when `FixedAsset` transitions to `active`, materialise a balanced GL posting (debit fixed-asset account, credit cash/liability) via T1's materialisation extension — covered by the `activate` action's `emit-journal-entry-and-schedule` subType `acquisition` declaration in the lifecycle (debit assetAccountNumber, credit cash/AP)
- [x] Task 11: Implement depreciation-expense GL posting per REQ-FA-007 — yearly posting materialises balanced `GLTransaction` (debit depreciation-expense, credit accumulated-depreciation) via OR scheduled-workflow or single-method `DepreciationCalculator` (if not yet stable); audit-trailed — `DepreciationCalculator::yearlyDepreciation()` computes the per-fiscal-year amount Float-Precision-aware; the `shillinq-fixed-assets-monthly-depreciation` ScheduledWorkflow (Phase 4b) drives the monthly tick that aggregates to a yearly posting; each posting links back via `DepreciationSchedule.glTransactionRef`
- [x] Task 12: Implement asset-retirement GL posting per REQ-FA-008 — when `FixedAsset` transitions to `retired`, materialise a compensating posting that removes the asset and accumulated depreciation from the books; if salvage proceeds exist, calculate and post gain/loss on disposal — `DepreciationCalculator::gainOrLossOnDisposal()` returns positive (gain) / negative (loss) / zero from `salvageProceeds − currentBookValue`; the existing `dispose` action's `emit-journal-entry` materialises the asset write-off + accumulated-depreciation reversal + gain/loss on the configured P&L account
- [x] Task 13: Declare depreciation aggregations per REQ-FA-009 as `x-openregister-aggregations` queries: (1) by depreciation method, (2) by cost center, (3) accumulated depreciation per asset, (4) book values by status — not PHP services — declared via the ADR-037 register fragment on both `FixedAsset` (depreciationByMethod / depreciationByCostCenter / bookValuesByStatus) and `DepreciationSchedule` (depreciationAmountByCostCenter / depreciationAmountByMethod / accumulatedDepreciationByAsset). NO PHP report or analytics service introduced
- [x] Task 14: Add 3 manifest navigation entries (`Fixed Assets`, `Depreciation Schedules`, `Depreciation Expense`) + their `type: index` / `type: detail` / `type: report` pages to `src/manifest.json` per REQ-FA-010; `node tests/validate-manifest.js` exits 0 — Fixed Assets entry already in base `src/manifest.json` (carry-forward); ADR-037 fragment `src/manifest.d/bookkeeping-fixed-assets-depreciation.json` adds Depreciation Schedules (index + detail) and Depreciation Expense (index over `DepreciationSchedule`); `node tests/validate-manifest.js` reports PASS (structural lint + consistency check, 0 issues)
- [x] Task 15: Create 3–5 realistic seed assets (company vehicle, office building, computer equipment, retired asset) with corresponding `DepreciationSchedule` records showing current-year calculations; load via `ConfigurationService::importFromApp()` pattern — `lib/Settings/seeds/fixed-assets-demo.json` ships four FixedAsset + four DepreciationSchedule records covering linear, declining-balance and retired scenarios with the REQ-FA-002 operator-facing aliases. `SettingsService::seedFixedAssetsDemo()` loads them via OpenRegister ObjectService idempotently (deduped on assetNumber/scheduleNumber + administrationId, assetNumber→assetRef UUID resolution). `lib/Repair/InitializeSettings::seedFixedAssetsDemo()` (Phase 14) calls it after the WMO seed; C2-guarded (skipped when no default administration is configured)
- [x] Task 16: Update `openspec/architecture/adr-000-data-model.md` with `FixedAsset`/`DepreciationSchedule` entries and cross-reference with `GLTransaction` for the GL-link pattern (sub-ledger references) — FixedAsset entry receives a 2026-06-09 reconciliation note acknowledging the REQ-FA-002 alias overlay; DepreciationSchedule entry is rewritten with the REQ-FA-003 canonical field set (assetRef/depreciationMethod/annualRate/rateType/periodStartDate/periodEndDate/depreciationAmount/accumulatedDepreciation/fiscalYear/status/costCenterCode/glTransactionRef/calculationFloatPrecision/administrationId), x-openregister-calculations.bookValue derived field, and explicit cross-references to FixedAsset (via assetRef) and GLTransaction (via glTransactionRef, anchored on the canonical `GLLine.subLedgerType = "fa"` + `GLLine.subLedgerRef = <FixedAsset UUID>` sub-ledger pattern)

## Verification

`openspec validate` must exit clean on the change folder. Accountant-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the asset-management and depreciation flow matches Dutch SMB accounting practice (acquisition intake → registration → yearly depreciation → GL posting → retirement). Finance reviewer confirms Float Precision integration (rate calculations respect System Settings). Architecture reviewer confirms ADR-022 + ADR-031 compliance (no app-local depreciation service; calculations declarative or ADR-031-exception-annotated; manifest carries navigation). No source code changes outside `openspec/changes/bookkeeping-fixed-assets-depreciation/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for asset lifecycle transitions, depreciation-schedule generation, depreciation calculations across methods (linear, declining-balance, units-of-production), Float Precision rounding, internal-transfer handling, GL postings for acquisition/depreciation/retirement (pre-declared on Tasks 5–12); Playwright MCP browser tests for the 3 manifest navigation entries (pre-declared on Task 14); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/fixed-assets.md` per ADR-030 journeydoc convention and commits asset-registration and depreciation-schedule screenshots to `docs/images/`.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Fixed Assets`, `Asset`, `Assets`, `Depreciation`, `Depreciation Schedule`, `Depreciation Expense`, `Linear`, `Declining Balance`, `Units of Production`, `Residual Value`, `Useful Life`, `Book Value`, `Accumulated Depreciation`, `Cost Center`, `Acquired`, `Active`, `Retired`, `Internal Transfer`, `Useful Life Years`.

## Deduplication Check

Per ADR-012, before proposing new capability, search for overlap with existing OpenRegister services and shared specs:

- `OpenRegister` — `ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService` provide object CRUD, schema validation, and seed-data import. This spec leverages `x-openregister-lifecycle` (existing) and `x-openregister-aggregations` (existing or in development).
- `@conduction/nextcloud-vue` — `CnIndexPage`, `CnDetailPage`, `CnDataTable` provide generic list/detail rendering; `CnChartWidget` for aggregation visualization. Spec references these, no duplication.
- Existing shillinq specs — `bookkeeping-chart-of-accounts` defines asset and depreciation-expense GL accounts; no duplication. `bookkeeping-general-ledger` defines GL materialization; this spec consumes it.
- **Finding**: No overlap with existing capabilities. Depreciation is a domain-specific calculation (belongs in this spec), not a generic OpenRegister service (already provided as aggregations + lifecycle).

## Seed Data Task

Task 15 generates seed assets per ADR-001-data-layer requirements:

**Asset 1**: Company vehicle (vehicle, €25,000, 5-year linear)
```json
{
  "@self": {
    "register": "shillinq",
    "schema": "FixedAsset",
    "slug": "company-vehicle-2026"
  },
  "assetNumber": "ASSET-V-2026-0001",
  "name": "Company Vehicle – License ABC-123",
  "assetType": "vehicle",
  "purchaseDate": "2026-01-15",
  "purchaseCost": 25000,
  "residualValue": 2000,
  "usefulLifeYears": 5,
  "depreciationMethod": "linear",
  "administrationId": "adm-sample",
  "status": "active"
}
```

**Asset 2**: Office building (property, €200,000, 20-year linear)
```json
{
  "@self": {
    "register": "shillinq",
    "schema": "FixedAsset",
    "slug": "office-building-2026"
  },
  "assetNumber": "ASSET-B-2026-0001",
  "name": "Office Building – Amsterdam",
  "assetType": "building",
  "purchaseDate": "2020-06-01",
  "purchaseCost": 200000,
  "residualValue": 20000,
  "usefulLifeYears": 20,
  "depreciationMethod": "linear",
  "administrationId": "adm-sample",
  "status": "active"
}
```

**Asset 3**: Computer equipment (equipment, €5,000, 3-year declining-balance)
```json
{
  "@self": {
    "register": "shillinq",
    "schema": "FixedAsset",
    "slug": "computer-equipment-2026"
  },
  "assetNumber": "ASSET-E-2026-0001",
  "name": "Computer Equipment – IT Lab",
  "assetType": "equipment",
  "purchaseDate": "2025-09-01",
  "purchaseCost": 5000,
  "residualValue": 500,
  "usefulLifeYears": 3,
  "depreciationMethod": "declining-balance",
  "declineRate": 0.40,
  "administrationId": "adm-sample",
  "status": "active"
}
```

**Asset 4**: Retired asset (fully depreciated, shown for reference)
```json
{
  "@self": {
    "register": "shillinq",
    "schema": "FixedAsset",
    "slug": "retired-equipment-2025"
  },
  "assetNumber": "ASSET-E-2023-0001",
  "name": "Old Computer Equipment – Retired",
  "assetType": "equipment",
  "purchaseDate": "2020-01-15",
  "purchaseCost": 3000,
  "residualValue": 0,
  "usefulLifeYears": 3,
  "depreciationMethod": "linear",
  "administrationId": "adm-sample",
  "status": "retired",
  "retirementDate": "2023-12-31"
}
```

Corresponding `DepreciationSchedule` records are generated automatically via lifecycle transitions in the implementation cycle.
