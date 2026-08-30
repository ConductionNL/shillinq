# Spec: bookkeeping-financial-statements

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../bookkeeping-chart-of-accounts/spec.md` (T1 accounts),
`../bookkeeping-general-ledger/spec.md` (T1 GL transactions)

## ADDED Requirements

### Requirement: REQ-FS-001: Financial statements SHALL be declared as `BalanceSheet` + `TrialBalance` + `ConsolidatedReport` + `ConsolidationGroup` registers, not computed tables

Financial statements MUST be expressed as four new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `BalanceSheet` — statement of assets, liabilities, and equity at a fiscal-period snapshot (status: draft/final/published).
- `TrialBalance` — listing of all GL accounts with debit/credit balances for period verification (isBalanced flag).
- `ConsolidatedReport` — aggregation of financials across multiple administrations with consolidation method and inter-company elimination tracking.
- `ConsolidationGroup` — definition of organizations consolidated together with elimination rules and consolidation method (full/proportional/equity).

This capability **enables comprehensive audit-ready financial reporting**
for Dutch public-sector and SMB compliance. Posting GL entries materialises
the underlying data for balance-sheet and trial-balance aggregation. The
registers themselves are lifecycle-managed (draft → final → published) per
REQ-FS-003. Balance-sheet line composition and trial-balance verification
are pure aggregations per REQ-FS-004 and REQ-FS-005.

UBL 2.1 / Peppol BIS 3.0 outbound financial-statement e-transmission is
**explicitly deferred to T4**; this T2 capability ships internal financial
reporting + consolidation. The `BalanceSheet` schema declares fields that
T4's e-transmission capability will populate via additional calculations.

#### Scenario: Reviewer confirms no parallel financial-report tables

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `balance_sheet`, `trial_balance`, `consolidated_*`, or
  `financial_statement_*`
- **THEN** no such classes SHALL exist.

#### Scenario: GL posting materialises balance-sheet data

- **GIVEN** T2 is live and GL entries are posted for FY 2026
- **WHEN** a balance sheet is drafted for FY 2026
- **THEN** the balance-sheet aggregation MUST compute totals from GL entries
  grouped by Account.accountType, **AND** the `BalanceSheet` register entry
  MUST reference the FiscalYear and have `status: draft`.

### Requirement: REQ-FS-002: The `BalanceSheet` schema SHALL declare a fixed minimum field set

| Field | Type | Required | Purpose |
|---|---|---|---|
| `reportDate` | datetime | Yes | Snapshot date of the balance sheet (typically fiscal-year-end) |
| `totalAssets` | number | No | Computed total assets in base currency |
| `totalLiabilities` | number | No | Computed total liabilities in base currency |
| `totalEquity` | number | No | Computed total equity in base currency |
| `currency` | string (ISO 4217) | Yes | Base currency (EUR) |
| `status` | enum | Yes | One of `draft`, `final`, `published` (per REQ-FS-003) |
| `fiscalYearId` | string | Yes | FK to `FiscalYear` |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Table`.

#### Scenario: Schema validator accepts minimal balance sheet

- **GIVEN** the schema
- **WHEN** `{reportDate:"2026-12-31", currency:"EUR", status:"draft", fiscalYearId:"fy-2026", administrationId:"adm-1"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Balance-sheet totals are computed, not manual entry

- **GIVEN** GL entries totalling €150k assets, €50k liabilities, €100k equity
- **WHEN** balance-sheet aggregation is run
- **THEN** the computed totals MUST equal the GL summary; manual field entry
  MUST be rejected or marked as override with audit trail.

### Requirement: REQ-FS-003: `BalanceSheet` + `TrialBalance` + `ConsolidatedReport` SHALL declare a lifecycle draft → final → published

These three registers MUST declare an `x-openregister-lifecycle` block with:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `final` | operator or scheduled action on fiscal-period close | FiscalPeriod is closed per REQ-FC-001; GL entries posted; aggregations verified |
| `final` | `published` | operator action (role `controller`) | publication workflow per OR publication-extension (ADR-022) or fallback per ADR-031 |
| `published` | `archived` | operator action after retention period | per administration retention policy |

The `draft → final` transition fires via OR's `ScheduledWorkflow` primitive
(per ADR-031 §"Background jobs that walk an object queue" path 2) or operator
action on fiscal-year close.

#### Scenario: Closing fiscal period transitions draft statements to final

- **GIVEN** a `draft` BalanceSheet for FY 2026 with GL entries posted
- **WHEN** the operator closes FiscalYear 2026 or scheduled workflow ticks
- **THEN** the BalanceSheet state MUST transition to `final`; **AND**
  `aggregations` (REQ-FS-004) MUST be verified and populated;
  **AND** the TrialBalance MUST also transition to `final`.

#### Scenario: Published balance sheet is immutable

- **GIVEN** a `published` BalanceSheet
- **WHEN** an operator attempts to modify any field
- **THEN** the save MUST fail with "immutable published statement" error
  (except audit-related metadata).

### Requirement: REQ-FS-004: Balance-sheet composition SHALL be a declarative aggregation grouping GL entries by account type

When a `BalanceSheet` transitions from `draft` to `final`, the aggregation
MUST compute:

```
SELECT Account.accountType, SUM(debit - credit) as balance
FROM GeneralLedgerEntry
WHERE FiscalYear.id = this.fiscalYearId AND entryDate BETWEEN FiscalYear.startDate AND FiscalYear.endDate
GROUP BY Account.accountType
```

Buckets:
- Assets (Account.accountType = "assets") → totalAssets
- Liabilities (Account.accountType = "liabilities") → totalLiabilities
- Equity (Account.accountType = "equity") → totalEquity

The aggregation MUST be `x-openregister-aggregations` per ADR-031; no
`BalanceSheetService.php`.

#### Scenario: Assets = Liabilities + Equity validates balance-sheet equation

- **GIVEN** GL entries with €200k assets, €80k liabilities, €120k equity
- **WHEN** balance-sheet aggregation runs
- **THEN** the computed totals MUST satisfy Assets = Liabilities + Equity;
  **AND** if not, an `isBalanced: false` flag MUST surface with diagnostic detail.

### Requirement: REQ-FS-005: Trial balance SHALL be a declarative aggregation listing GL accounts with debit/credit balances and verification check

The `TrialBalance` register MUST declare an aggregation:

```
SELECT Account.accountNumber, Account.name, Account.accountType,
       SUM(CASE WHEN debit > 0 THEN debit ELSE 0 END) as totalDebits,
       SUM(CASE WHEN credit > 0 THEN credit ELSE 0 END) as totalCredits
FROM GeneralLedgerEntry
WHERE FiscalYear.id = this.fiscalYearId AND entryDate BETWEEN FiscalYear.startDate AND FiscalYear.endDate
GROUP BY Account.accountNumber, Account.name, Account.accountType
```

The `TrialBalance` schema MUST include:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `reportDate` | datetime | Yes | Snapshot date (typically fiscal-year-end) |
| `totalDebits` | number | No | Sum of all debit balances |
| `totalCredits` | number | No | Sum of all credit balances |
| `isBalanced` | boolean | No | Computed flag: totalDebits = totalCredits |
| `status` | enum | Yes | One of `draft`, `verified`, `final` (per REQ-FS-003) |
| `preparedBy` | string | No | Actor who prepared or verified (audit trail) |
| `fiscalYearId` | string | Yes | FK to FiscalYear |
| `administrationId` | string | Yes | FK to administration |

The aggregation MUST be `x-openregister-aggregations` per ADR-031; no
`TrialBalanceService.php`.

#### Scenario: Trial balance verifies debits = credits

- **GIVEN** GL entries totalling €500k debits and €500k credits
- **WHEN** trial-balance aggregation runs
- **THEN** `isBalanced` MUST be `true`; **AND** the report MUST list all accounts
  with their balances; **AND** the footer MUST confirm "Trial Balance Verified".

#### Scenario: Trial balance flags imbalance with diagnostic

- **GIVEN** GL entries totalling €500k debits but €495k credits (€5k error)
- **WHEN** trial-balance aggregation runs
- **THEN** `isBalanced` MUST be `false`; **AND** a diagnostic message MUST
  list accounts with potential errors, highlighting the €5k discrepancy.

### Requirement: REQ-FS-006: Consolidation SHALL declare `ConsolidationGroup` with elimination rules; `ConsolidatedReport` aggregates across organizations with inter-company elimination

The `ConsolidationGroup` schema MUST declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `name` | string | Yes | Name of the consolidation group |
| `consolidationMethod` | enum | Yes | One of `full`, `proportional`, `equity` per IFRS 10/11/12 |
| `status` | enum | Yes | One of `active`, `inactive`, `archived` |
| `parentOrganizationId` | string | No | FK to parent organization (if applicable) |
| `eliminationRules` | object | No | Consolidation elimination rules (offset-by-FK, percentage-based, custom) |
| `administrationIds` | array of string | Yes | FKs to Administration records being consolidated |

The `ConsolidatedReport` schema MUST declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `reportNumber` | string | Yes | Unique identifier |
| `reportDate` | datetime | Yes | Consolidation snapshot date |
| `consolidationGroupId` | string | Yes | FK to ConsolidationGroup |
| `consolidationMethod` | enum | Yes | Method used (full/proportional/equity) |
| `eliminationsApplied` | boolean | No | Whether inter-company eliminations have been posted |
| `status` | enum | Yes | One of `draft`, `final`, `published` (per REQ-FS-003) |
| `fiscalYearId` | string | Yes | FK to FiscalYear |

Consolidation workflow (inter-company elimination, reclassification) MUST
consume OR's consolidation extension per ADR-022 via `x-openregister-lifecycle`
or per ADR-031 exception via single-method `OCA\Shillinq\Consolidation\ConsolidationGuard`.

#### Scenario: Consolidation group defines elimination rules

- **GIVEN** a ConsolidationGroup with parent organization Acme BV and
  subsidiary Acme Services BV (70% ownership)
- **WHEN** consolidation rules are configured for full consolidation with
  elimination of inter-company sales
- **THEN** the `eliminationRules` object MUST capture the rule set;
  **AND** the `consolidationMethod: full` MUST be set.

#### Scenario: Consolidated report applies elimination rules

- **GIVEN** Acme BV with €1M revenue and Acme Services BV with €300k revenue,
  of which €100k is inter-company
- **WHEN** consolidated-report aggregation runs with elimination rules
- **THEN** the consolidated revenue MUST be €1.2M (€1M + €300k - €100k elimination);
  **AND** `eliminationsApplied: true` MUST be set; **AND** audit trail MUST
  record the elimination amounts.

### Requirement: REQ-FS-007: Financial-statement publication workflow SHALL consume OR's publication extension; no shillinq publisher service

When a `BalanceSheet`, `TrialBalance`, or `ConsolidatedReport` transitions from
`final` to `published`, the publication workflow runs via OR's publication
extension (per ADR-022) or per ADR-031 exception via single-method `ConsolidationGuard`.

Publication MUST:
- Emit the statement in configured formats (PDF, Excel, XML per REQ-FS-002)
- Notify stakeholders per OR's notification engine
- Record publication timestamp and actor for audit trail
- Mark statement immutable (no further changes allowed)

No PHP publisher service in shillinq; symmetric to AP/AR pattern.

#### Scenario: Reviewer confirms no parallel publisher service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/Publisher*.php`,
  `lib/Service/Publish*.php`, `lib/Service/Report*.php`
- **THEN** no such files SHALL exist (other than the conditional
  `ConsolidationGuard` lifecycle guard, single method, explicitly cited
  as ADR-031 exception).

#### Scenario: Published statement triggers stakeholder notification

- **GIVEN** a `final` BalanceSheet
- **WHEN** an operator transitions it to `published`
- **THEN** the publication workflow MUST fire; **AND** OR's notification engine
  MUST dispatch the statement to configured stakeholders (auditor, board,
  tax authority per administration policy); **AND** the statement MUST be
  immutable thereafter.

### Requirement: REQ-FS-008: Manifest navigation SHALL declare 4 entries: Balance Sheet, Trial Balance, Consolidations, Consolidated Report

Add to `src/manifest.json` four navigation menu entries with their corresponding
`type: index` and `type: detail` pages:

- `Balance Sheet` → `CnIndexPage(BalanceSheet)` + `CnDetailPage(BalanceSheet)`
- `Trial Balance` → `CnIndexPage(TrialBalance)` + `CnDetailPage(TrialBalance)`
- `Consolidations` → `CnIndexPage(ConsolidationGroup)` + `CnDetailPage(ConsolidationGroup)`
- `Consolidated Report` → `CnIndexPage(ConsolidatedReport)` + `CnDetailPage(ConsolidatedReport)`

Per ADR-022, all pages MUST use shared OpenRegister components (CnIndexPage,
CnDetailPage) and schema-driven forms. No custom pages.

#### Scenario: Manifest validation passes with 4 new entries

- **GIVEN** the updated manifest
- **WHEN** `node tests/validate-manifest.js` runs
- **THEN** the validation MUST exit 0; all entries must reference valid
  registers and pages.
