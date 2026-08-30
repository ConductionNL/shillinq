# Spec: bookkeeping-fixed-assets-depreciation

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`../add-shillinq-bookkeeping-foundation/specs/bookkeeping-chart-of-accounts/spec.md` (asset accounts)

**ADRs:** Per [ADR-022](../../architecture/adr-022.md) (no app-local
depreciation service — calculations sourced from OR's declarative
business-logic extension) and [ADR-031](../../architecture/adr-031.md)
(asset analytics expressed as `x-openregister-aggregations`, not PHP
report services; a single-method `DepreciationCalculator` is permitted
under the ADR-031 §"PHP guards remain a legitimate seam" exception
while OR's calculation extension stabilises).

## ADDED Requirements

### Requirement: REQ-FA-001: Fixed assets SHALL be declared as `FixedAsset` + `DepreciationSchedule` registers, not duplicates of GL

Fixed assets MUST be expressed as two new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `FixedAsset` — tangible business asset (name, type, acquisition
  cost, location, depreciation method, useful life, residual value,
  GL account mapping).
- `DepreciationSchedule` — per-asset yearly depreciation tracking
  (method, annual rate, period start/end, total depreciation amount,
  accumulated depreciation).

This capability **carries forward asset management as a core
bookkeeping function** — tangible assets are essential to any
business's balance sheet, and precise depreciation tracking is
required for Dutch tax compliance (Vennootschapsbelasting) and
financial reporting.

Posting a `FixedAsset` acquisition MUST materialise exactly one
balanced `GLTransaction` per the T1 REQ-JE-007 pattern. `GLLine.subLedgerType:
"fa"` + `subLedgerRef: <FixedAsset UUID>` resolves to the
capitalized asset line (T1 REQ-GL-009 stub now backed by a real FK).

Yearly depreciation-expense postings MUST materialise balanced
`GLTransaction` records (debit depreciation expense, credit
accumulated depreciation) per the same T1 pattern.

#### Scenario: Reviewer confirms no parallel asset table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `fixed_asset`, `asset_*`, `depreciation_*`, or
  `asset_register_*`
- **THEN** no such classes SHALL exist.

#### Scenario: GLLine asset-ledger ref resolves to a real FixedAsset

- **GIVEN** T2 is live and `FixedAsset ASSET-2026-0001` is acquired
- **WHEN** the materialised `GLLine` is inspected
- **THEN** the line MUST carry `subLedgerType: "fa"`,
  `subLedgerRef: "<UUID of ASSET-2026-0001>"`, **AND** the FK
  MUST resolve via OR's relation engine.

#### Scenario: Depreciation-expense GL posting is balanced

- **GIVEN** a `FixedAsset` in year 1 of its depreciation schedule
- **WHEN** the yearly depreciation-expense posting is materialised
- **THEN** a balanced `GLTransaction` MUST be created (debit
  depreciation-expense account, credit accumulated-depreciation
  account) with matching amounts.

### Requirement: REQ-FA-002: The `FixedAsset` schema SHALL declare a fixed minimum field set

| Field | Type | Required | Purpose |
|---|---|---|---|
| `assetNumber` | string | Yes | Unique identifier per administration (auto-generated or operator-assigned) |
| `name` | string | Yes | Human-readable asset name (e.g. "Company Vehicle – License XYZ") |
| `assetType` | enum | Yes | One of: `equipment`, `vehicle`, `property`, `building`, `leasehold`, `other` |
| `description` | string | No | Additional asset details or serial number |
| `purchaseDate` | date | Yes | Date of acquisition |
| `purchaseCost` | number ≥ 0 | Yes | Original acquisition cost in base currency |
| `residualValue` | number ≥ 0 | No | Estimated residual value at end of useful life; default 0 |
| `usefulLifeYears` | integer ≥ 1 | Yes | Estimated useful life in years (e.g. 5 for vehicles, 20 for buildings) |
| `depreciationMethod` | enum | Yes | One of: `linear`, `declining-balance`, `units-of-production` |
| `declineRate` | number (0–1) | No | For declining-balance: annual decline percentage (e.g. 0.20 for 20%) |
| `productionUnits` | integer | No | For units-of-production: estimated total units; actual units tracked per year |
| `capitalizationAccountNumber` | string | No | FK to `Account.accountNumber` for asset capitalization GL account |
| `depreciationExpenseAccountNumber` | string | No | FK to `Account.accountNumber` for depreciation-expense GL account |
| `accumulatedDepreciationAccountNumber` | string | No | FK to `Account.accountNumber` for accumulated-depreciation GL account (contra-asset) |
| `location` | string | No | Physical location or cost center of the asset |
| `costCenterCode` | string | No | Cost center for depreciation allocation |
| `status` | enum | Yes | One of: `active`, `inactive`, `retired` |
| `retirementDate` | date | No | Date asset was retired (if status = retired) |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Thing` (tangible asset).

#### Scenario: Schema validator accepts a minimal asset

- **GIVEN** the schema
- **WHEN** `{assetNumber:"ASSET-2026-0001", name:"Company Car", assetType:"vehicle", purchaseDate:"2026-01-15", purchaseCost:25000, usefulLifeYears:5, depreciationMethod:"linear", administrationId:"adm-1", status:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Residual value defaults to zero

- **GIVEN** an asset without `residualValue` set
- **WHEN** the depreciation schedule is calculated
- **THEN** the schedule MUST treat residual value as 0 (fully depreciate the acquisition cost).

#### Scenario: Depreciation method enum is validated

- **GIVEN** the asset schema
- **WHEN** an invalid depreciation method (e.g. `"accelerated"`) is submitted
- **THEN** validation MUST fail with a schema-constraint error.

### Requirement: REQ-FA-003: The `DepreciationSchedule` schema SHALL declare a fixed minimum field set

| Field | Type | Required | Purpose |
|---|---|---|---|
| `scheduleNumber` | string | Yes | Unique identifier for the depreciation schedule |
| `assetRef` | string | Yes | FK to `FixedAsset` UUID |
| `depreciationMethod` | enum | Yes | One of: `linear`, `declining-balance`, `units-of-production` |
| `annualRate` | number ≥ 0 | Yes | Annual depreciation rate as percentage (e.g. 0.20 for 20%) or fixed amount |
| `rateType` | enum | Yes | One of: `percentage`, `fixed-amount`, `units-per-year` |
| `periodStartDate` | date | Yes | Start date of the depreciation period (typically fiscal year start) |
| `periodEndDate` | date | Yes | End date of the depreciation period (typically fiscal year end) |
| `depreciationAmount` | number | Yes | Depreciation amount for this period (calculated) |
| `accumulatedDepreciation` | number | Yes | Total depreciation accumulated across all periods to date |
| `bookValue` | number | Yes | Net book value = `FixedAsset.purchaseCost - accumulatedDepreciation` |
| `fiscalYear` | integer | Yes | Fiscal year this schedule covers (e.g. 2026) |
| `status` | enum | Yes | One of: `planned`, `active`, `completed` |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Thing`.

#### Scenario: Depreciation amount respects Float Precision setting

- **GIVEN** Nextcloud System Settings Float Precision = 2 decimal places
- **WHEN** a depreciation calculation yields €1234.567
- **THEN** the `depreciationAmount` MUST be stored and displayed as €1234.57 (rounded).

#### Scenario: Book value is automatically calculated

- **GIVEN** a `FixedAsset` with purchaseCost €25,000 and a `DepreciationSchedule` with accumulatedDepreciation €5,000
- **WHEN** the schedule is queried
- **THEN** `bookValue` MUST be €20,000 (calculated, not stored as a separate field).

#### Scenario: Annual rate is applied per depreciation method

- **GIVEN** a linear-depreciation asset with `annualRate: 0.20` (20% per year)
- **WHEN** the schedule is for a full fiscal year
- **THEN** `depreciationAmount` MUST equal `(purchaseCost - residualValue) * 0.20`.

### Requirement: REQ-FA-004: `FixedAsset` SHALL declare a declarative lifecycle with automatic depreciation-schedule generation

`FixedAsset` MUST declare an `x-openregister-lifecycle` block with:

| From | To | Trigger | Guard |
|---|---|---|---|
| `active` | `inactive` | operator action | none |
| `inactive` | `active` | operator action | none |
| `active` | `retired` | operator action | none — bad-asset write-off or salvage journal may materialise a balanced GL posting |

On acquisition (`active` status), OR MUST automatically generate the
first-year `DepreciationSchedule` record using the asset's `depreciationMethod`,
`usefulLifeYears`, `purchaseDate`, and current `administrationId`'s fiscal year.

Yearly depreciation-expense postings MUST fire via OR's
`ScheduledWorkflow` primitive (per ADR-031 §"Background jobs that
walk an object queue" path 2 — not a shillinq `*Job` PHP class).

#### Scenario: Asset acquisition initialises depreciation schedule

- **GIVEN** a `FixedAsset` transitioning to `active` on 2026-01-15
- **WHEN** the transition fires
- **THEN** a `DepreciationSchedule` record MUST be created
  automatically with `periodStartDate: 2026-01-01` (fiscal year start),
  `periodEndDate: 2026-12-31`, and `depreciationAmount` calculated per
  the asset's depreciation method.

#### Scenario: Yearly depreciation fires automatically

- **GIVEN** an `active` asset on 2025-12-31 with annual
  depreciation-expense due
- **WHEN** the OR scheduled-workflow ticks on 2026-01-01
- **THEN** a new `DepreciationSchedule` record MUST be created for
  fiscal year 2026; **AND** a balanced `GLTransaction` MUST be
  materialised (debit depreciation-expense account, credit
  accumulated-depreciation account).

#### Scenario: Retirement captures residual value or salvage

- **GIVEN** an `active` asset transitioning to `retired` on 2030-12-31
- **WHEN** the transition fires
- **THEN** the asset status MUST become `retired`; **AND** if salvage
  proceeds exist, a GL posting MUST offset the accumulated depreciation.

### Requirement: REQ-FA-005: Depreciation rates SHALL respect Nextcloud System Settings Float Precision

Depreciation rate calculations (for all methods) MUST query the Float
Precision setting from Nextcloud System Settings at calculation time.
Rates MUST be rounded to the configured decimal places.

| Setting | Behaviour |
|---|---|
| Float Precision = 2 | €1234.567 → €1234.57 |
| Float Precision = 3 | €1234.567 → €1234.567 |
| Float Precision = 4 | €1234.5678 → €1234.5678 |

The fallback precision (if System Settings is unavailable) is **2
decimal places** per Dutch accounting standards.

#### Scenario: Float Precision is applied at calculation time

- **GIVEN** Nextcloud System Settings Float Precision = 3 decimal places
- **WHEN** a yearly depreciation calculation yields €5000.00 / 12 months = €416.6666...
- **THEN** the monthly allocation MUST be €416.667 (rounded to 3 places).

#### Scenario: Fallback precision applies if configuration unavailable

- **GIVEN** System Settings Float Precision is not accessible
- **WHEN** depreciation is calculated
- **THEN** the calculation MUST fall back to 2 decimal places per Dutch
  accounting standard.

### Requirement: REQ-FA-006: Internal asset transfers SHALL adjust depreciation schedules proportionally

When a `FixedAsset` is transferred between cost centers or departments
(via internal invoice or manual transfer action), the depreciation
schedule MUST adjust proportionally. No GL posting is required for
internal transfers (the asset remains on the same GL account);
the cost-center allocation is reflected in the `DepreciationSchedule`
cost-center field.

If the asset is split (e.g. 50% transferred), the original
`DepreciationSchedule` is updated to reflect the proportional
allocation, and a new schedule record is created for the receiving
cost center.

#### Scenario: Full asset transfer updates cost center

- **GIVEN** `FixedAsset ASSET-001` with cost-center "HQ" and a 2026
  depreciation schedule
- **WHEN** the asset is transferred to cost-center "Branch-1" via
  internal transfer
- **THEN** the asset's `costCenterCode` MUST update to "Branch-1";
  **AND** the depreciation schedule MUST reflect the new cost center
  for all future calculations; **AND** no GL posting is required.

#### Scenario: Proportional split creates separate schedule

- **GIVEN** `FixedAsset ASSET-002` worth €10,000 transferred 30% to a
  new department
- **WHEN** the split transfer fires
- **THEN** the original asset's depreciation MUST adjust to 70%;
  **AND** a new `FixedAsset` record MUST be created for the 30%
  portion with its own depreciation schedule.

### Requirement: REQ-FA-007: Depreciation-expense GL postings SHALL be declarative, not PHP service-driven

Depreciation-expense postings MUST be materialised via OR's
declarative business logic extension (specifically, the lifecycle
action on `FixedAsset` or a scheduled workflow that walks active assets).

If OR's declarative extension is NOT yet stable at T2 implementation
time, a single-method `OCA\Shillinq\Lifecycle\DepreciationCalculator`
(~30 LOC, ADR-031 exception) MAY be shipped to compute the yearly
amount and invoke the GL materialisation. This calculator is removed
when OR's extension lands. The spec is shape-neutral.

No `DepreciationService`, no `DepreciationReportService`,
no `AssetManagementService`. Calculations are declarative metadata.

#### Scenario: Reviewer confirms no depreciation service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*Depreciation*.php`,
  `lib/Service/*Asset*.php` (excluding the conditional
  `DepreciationCalculator` per ADR-031 exception)
- **THEN** no such files SHALL exist (other than the documented
  exception).

#### Scenario: Depreciation posting balances perfectly

- **GIVEN** a `FixedAsset` with €25,000 acquisition cost and 20%
  annual linear depreciation
- **WHEN** the yearly depreciation posting fires
- **THEN** a balanced `GLTransaction` MUST be created with debit
  depreciation-expense €5,000 and credit accumulated-depreciation
  €5,000.

### Requirement: REQ-FA-008: Asset acquisition and retirement SHALL materialise balanced GL postings

Asset acquisition MUST materialise a balanced GL posting:
- Debit: Fixed Asset (capitalisation account)
- Credit: Cash / Accounts Payable

Asset retirement or salvage MUST materialise a compensating posting:
- Debit/Credit: Gain/Loss on Disposal account
- Credit/Debit: Accumulated Depreciation and Fixed Asset (to remove from books)

These postings MUST follow the same T1 materialisation pattern as
`JournalEntry`.

#### Scenario: Asset acquisition creates balanced posting

- **GIVEN** a `FixedAsset` acquired for €25,000 on 2026-01-15 via cash
- **WHEN** the asset transitions to `active`
- **THEN** a balanced `GLTransaction` MUST be materialised:
  **Debit** Fixed Asset account €25,000,
  **Credit** Cash account €25,000.

#### Scenario: Retirement with salvage creates gain/loss posting

- **GIVEN** a `FixedAsset` with original cost €25,000, accumulated
  depreciation €20,000 (book value €5,000), sold for €6,000
- **WHEN** the asset is retired
- **THEN** a GL posting MUST be materialised:
  **Debit** Cash €6,000,
  **Credit** Accumulated Depreciation €20,000,
  **Credit** Fixed Asset account €25,000,
  **Debit** Gain on Disposal account €1,000 (the €6,000 proceeds minus
  €5,000 book value).

### Requirement: REQ-FA-009: Depreciation aggregations SHALL enable cost-center and method-based reporting

Depreciation tracking MUST be expressed as `x-openregister-aggregations`
queries:

1. **Depreciation by method** — GROUP BY `depreciationMethod`,
   SUM `annualRate` for current fiscal year.
2. **Depreciation by cost center** — GROUP BY `costCenterCode`,
   SUM `depreciationAmount` for current fiscal year.
3. **Accumulated depreciation by asset** — for each `FixedAsset`,
   the cumulative depreciation across all periods.
4. **Asset book values by status** — GROUP BY `status`,
   SUM `bookValue` for balance-sheet reporting.

NO `DepreciationReportService.php`, no `AssetAnalyticsService.php`.

#### Scenario: Reviewer confirms no depreciation report service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*Report*.php`,
  `lib/Service/*Analytics*.php` (related to assets/depreciation)
- **THEN** no such files SHALL exist.

#### Scenario: Cost-center depreciation aggregation

- **GIVEN** three assets allocated to cost centers "HQ" (€3000/year),
  "Branch-1" (€2000/year), "Branch-2" (€1500/year)
- **WHEN** the depreciation-by-cost-center aggregation runs
- **THEN** the result MUST show:
  - HQ: €3000
  - Branch-1: €2000
  - Branch-2: €1500

### Requirement: REQ-FA-010: Fixed Assets SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare:

- `Bookkeeping > Fixed Assets` — `type: index` + `type: detail` on
  `FixedAsset`; detail page MUST surface asset details, depreciation
  schedule history, GL links, and current book value.
- `Bookkeeping > Depreciation Schedules` — `type: index` +
  `type: detail` on `DepreciationSchedule`; detail page MUST surface
  the annual calculation, method details, and GL transaction links.
- `Bookkeeping > Depreciation Expense` — `type: report` (or
  `type: index` fallback) bound to the depreciation-by-cost-center
  aggregation and method-based summary.

Rendering MUST use `@conduction/nextcloud-vue` generic components
per ADR-024 Tier-4 — no bespoke Vue files.

#### Scenario: Asset index lists fixed assets

- **GIVEN** the manifest declares the Fixed Assets pages
- **WHEN** an operator opens `/index.php/apps/shillinq/fixed-assets`
- **THEN** `CnIndexPage` MUST render columns including
  `assetNumber`, `name`, `assetType`, `purchaseCost`, `status`,
  `bookValue`.

#### Scenario: Asset detail shows depreciation history

- **GIVEN** a `FixedAsset` with three years of depreciation schedules
- **WHEN** an operator opens the detail page
- **THEN** the page MUST surface:
  - Asset details (name, type, purchase date, cost)
  - Depreciation method and useful life
  - Table of annual depreciation schedules (year-by-year)
  - Current accumulated depreciation and book value
  - GL transaction links for acquisition and yearly postings
  - Cost-center allocation if applicable.
