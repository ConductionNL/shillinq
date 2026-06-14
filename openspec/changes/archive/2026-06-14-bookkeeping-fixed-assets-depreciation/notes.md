# Build Notes — bookkeeping-fixed-assets-depreciation

## Task 1: ADR-031 anti-pattern enumeration (carry-forward confirmation)

This capability **carries forward asset management as a core
bookkeeping function**. The sibling change `add-shillinq-fixed-assets-depreciation`
already shipped the T2 `FixedAsset` schema and a single-method
`DepreciationCalculator` per the ADR-031 exception clause. This T3
build extends that surface with:

- A new `DepreciationSchedule` register (per-asset, per-fiscal-year)
- REQ-FA-002 field-set alignment (`assetType`/`usefulLifeYears`/
  `status` aliases on top of the existing `assetCategory`/
  `usefulLifeMonths`/`lifecycleState` shape — additive via the
  ADR-037 fragment)
- REQ-FA-005 Float Precision integration on the existing calculator
- REQ-FA-006 internal-transfer handling
- REQ-FA-008 acquisition + retirement GL postings via lifecycle
- REQ-FA-009 cost-centre / method aggregations
- Three manifest navigation entries (Fixed Assets exists; add
  Depreciation Schedules + Depreciation Expense)
- Seed data for four realistic assets + schedules

### Anti-pattern enumeration (current snapshot — `git ls-tree origin/development`)

| Check | Pattern | Result |
|---|---|---|
| Spec | `openspec/specs/bookkeeping-fixed-assets-depreciation/` | Absent — change folder only ships under `openspec/changes/`, no main-spec yet. |
| Schema (FixedAsset) | `lib/Settings/shillinq_register.json` declares `FixedAsset` | **Present** — carry-forward from `add-shillinq-fixed-assets-depreciation`. This change extends additively via fragment. |
| Schema (DepreciationSchedule) | `lib/Settings/register.d/*.json` | Absent. Declared by this change in `register.d/bookkeeping-fixed-assets-depreciation.json`. |
| DB Mapper | `lib/Db/{FixedAsset,DepreciationSchedule}Mapper.php` | Absent (confirmed: `find lib/Db -iname '*asset*' -o -iname '*depreciation*'` empty). |
| PHP service | `lib/Service/AssetManagementService.php`, `lib/Service/DepreciationReportService.php`, `lib/Service/AssetAnalyticsService.php` | Absent. Only `lib/Service/DepreciationCalculator.php` (ADR-031 §"single-method exception"). |
| Lifecycle | `x-openregister-lifecycle` on `FixedAsset` | **Present** — extended additively (no field rename). |

### Architecture wedge

- Schema fragments under `lib/Settings/register.d/` are deep-merged
  into `shillinq_register.json` by `SettingsService::loadRegisterConfigData()`
  (ADR-037). The `DepreciationSchedule` schema lands as a brand-new
  schema entry. `FixedAsset` extensions land as additive `properties`
  + `x-openregister-aggregations` keys — disjoint keys union cleanly.
- The existing `FixedAsset` retains its `assetCategory` /
  `usefulLifeMonths` / `lifecycleState` names. REQ-FA-002 field aliases
  (`assetType` / `usefulLifeYears` / `status`) are added as additional
  optional properties, mapping operator-facing terminology to the
  canonical field set; the existing required-fields list is unchanged
  to preserve backward compatibility with seeded records.
- Calculations from REQ-FA-005 (Float Precision) and REQ-FA-007
  (depreciation-expense GL posting) hook into the existing
  `DepreciationCalculator` per the ADR-031 exception.
