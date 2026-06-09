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
- [ ] Task 5: Declare the `FixedAsset` schema in `lib/Settings/shillinq_register.json` with all REQ-FA-002 fields (assetNumber, name, assetType, purchaseDate, purchaseCost, residualValue, usefulLifeYears, depreciationMethod, declineRate, productionUnits, account mappings, location, costCenterCode, status, retirementDate, administrationId)
- [ ] Task 6: Declare the `DepreciationSchedule` schema in `lib/Settings/shillinq_register.json` with all REQ-FA-003 fields (scheduleNumber, assetRef, depreciationMethod, annualRate, rateType, periodStartDate, periodEndDate, depreciationAmount, accumulatedDepreciation, bookValue, fiscalYear, status, administrationId) — bookValue is computed not stored
- [ ] Task 7: Add `x-openregister-lifecycle` to `FixedAsset` declaring every transition in REQ-FA-004 (`active` ↔ `inactive` → `retired`) with automatic depreciation-schedule generation on acquisition; auto-fire yearly depreciation-expense posting via OR scheduled-workflow (or single-method `DepreciationCalculator` fallback per ADR-031 exception, documented)
- [ ] Task 8: Implement Float Precision integration per REQ-FA-005 — at depreciation-calculation time, query Nextcloud System Settings for Float Precision and round all rate calculations to the configured decimal places; fallback to 2 decimal places (Dutch standard) if setting is unavailable
- [ ] Task 9: Implement internal asset transfer handling per REQ-FA-006 — transfer action updates `costCenterCode` on the asset and adjusts the depreciation schedule; proportional splits create new `FixedAsset` records with separate schedules; no GL posting for internal transfers
- [ ] Task 10: Implement asset acquisition GL posting per REQ-FA-008 — when `FixedAsset` transitions to `active`, materialise a balanced GL posting (debit fixed-asset account, credit cash/liability) via T1's materialisation extension
- [ ] Task 11: Implement depreciation-expense GL posting per REQ-FA-007 — yearly posting materialises balanced `GLTransaction` (debit depreciation-expense, credit accumulated-depreciation) via OR scheduled-workflow or single-method `DepreciationCalculator` (if not yet stable); audit-trailed
- [ ] Task 12: Implement asset-retirement GL posting per REQ-FA-008 — when `FixedAsset` transitions to `retired`, materialise a compensating posting that removes the asset and accumulated depreciation from the books; if salvage proceeds exist, calculate and post gain/loss on disposal
- [ ] Task 13: Declare depreciation aggregations per REQ-FA-009 as `x-openregister-aggregations` queries: (1) by depreciation method, (2) by cost center, (3) accumulated depreciation per asset, (4) book values by status — not PHP services
- [ ] Task 14: Add 3 manifest navigation entries (`Fixed Assets`, `Depreciation Schedules`, `Depreciation Expense`) + their `type: index` / `type: detail` / `type: report` pages to `src/manifest.json` per REQ-FA-010; `node tests/validate-manifest.js` exits 0
- [ ] Task 15: Create 3–5 realistic seed assets (company vehicle, office building, computer equipment, retired asset) with corresponding `DepreciationSchedule` records showing current-year calculations; load via `ConfigurationService::importFromApp()` pattern
- [ ] Task 16: Update `openspec/architecture/adr-000-data-model.md` with `FixedAsset`/`DepreciationSchedule` entries and cross-reference with `GLTransaction` for the GL-link pattern (sub-ledger references)

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
