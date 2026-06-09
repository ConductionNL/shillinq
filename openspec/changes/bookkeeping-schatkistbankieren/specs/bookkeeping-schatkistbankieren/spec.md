# Spec: bookkeeping-schatkistbankieren

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-chart-of-accounts/spec.md` (T1 chart of accounts),
`./bookkeeping-audit-trail/spec.md` (T2 audit trail)

## ADDED Requirements

### Requirement: REQ-SCHATKIST-001 Treasury banking compliance SHALL be declared as `TreasuryAccount` + `BankingRule` + `ComplianceReport` registers

Treasury banking compliance MUST be expressed as three new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `TreasuryAccount` — treasury master-list account (IBAN, compliance classification,
  admin scoping, approval status).
- `BankingRule` — configurable compliance criteria (IBAN format, segregation,
  approval requirements, reporting obligations).
- `ComplianceReport` — periodic compliance snapshot (compliance score,
  criteria match status, regulatory export status).

Activating a `TreasuryAccount` MUST trigger evaluation of all applicable
`BankingRule` criteria. No custom database tables, no parallel storage. Per ADR-022,
every register consumes OR's audit-trail-immutable and RBAC abstractions.

#### Scenario: Reviewer confirms no parallel treasury account table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or `appinfo/info.xml` table
  declarations naming `treasury_account`, `banking_rule`, `compliance_report`,
  or `schatkist_*`
- **THEN** no such classes or declarations SHALL exist.

#### Scenario: Treasury account references chart-of-accounts entry

- **GIVEN** T2 is live and a `TreasuryAccount` with IBAN `NL91ABNA0417164300` is created
- **WHEN** it is linked to an `Account` marked as treasury-eligible
- **THEN** the account reference MUST resolve via OR's relation engine.

### Requirement: REQ-SCHATKIST-002 The `TreasuryAccount` schema SHALL declare a fixed minimum field set

The `TreasuryAccount` schema MUST declare every field listed below; all fields marked Required MUST be present on every persisted instance.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `accountNumber` | string | Yes | Stable identifier (per administration) |
| `iban` | string | Yes | Full Dutch IBAN (validated per NL format) |
| `bic` | string | No | BIC / SWIFT code |
| `bankName` | string | No | Bank name for display |
| `accountName` | string | Yes | Treasury account label (e.g., "Schatkistrekening 2026") |
| `description` | string | No | Business purpose and governance notes |
| `complianceClassification` | enum | Yes | One of `master-list`, `subsidiary`, `suspense`, `temporary` |
| `masterListStatus` | enum | Yes | One of `active`, `pending-review`, `blocked`, `archived` |
| `administrationId` | string | Yes | FK to the administration owning the account |
| `linkedAccountNumber` | string | No | FK to `Account.accountNumber` for GL classification |
| `requiresApproval` | boolean | Yes (default true) | Whether activation requires CFO/treasurer approval |
| `approvalStatus` | enum | Yes | One of `not-required`, `pending`, `approved`, `rejected` |
| `lifecycleState` | enum | Yes | One of `draft`, `configured`, `active`, `monitored`, `compliant`, `suspended`, `archived` |

Schema.org annotation: `schema:BankAccount` (per shillinq config.yaml `rules.specs`).

#### Scenario: Schema validator accepts a minimal treasury account

- **GIVEN** the schema
- **WHEN** `{accountNumber:"TR-NL-001", iban:"NL91ABNA0417164300", accountName:"Schatkistrekening 2026", complianceClassification:"master-list", masterListStatus:"active", administrationId:"adm-1", lifecycleState:"draft"}` is saved
- **THEN** validation MUST pass; IBAN format MUST be validated against Dutch IBAN regex.

#### Scenario: Schema rejects invalid IBAN

- **GIVEN** the schema
- **WHEN** `{iban:"NL91ABNA04171643"}` (incomplete) is saved
- **THEN** validation MUST fail with an "invalid IBAN format" error.

### Requirement: REQ-SCHATKIST-003 The `BankingRule` schema SHALL declare configurable compliance criteria

The `BankingRule` schema MUST declare every field listed below; the evaluationCriteria payload MUST conform to the ruleType-specific shape (iban-format → pattern, segregation → checkDuplicates, approval-required → requiresTreasurerApproval, transaction-limit → maxAmount, reporting-period → cadence).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `ruleNumber` | string | Yes | Stable identifier (e.g., "rule-iban-format") |
| `name` | string | Yes | Human-readable rule name (e.g., "Dutch IBAN Format") |
| `description` | string | No | Detailed rule description and rationale |
| `ruleType` | enum | Yes | One of `iban-format`, `segregation`, `approval-required`, `transaction-limit`, `reporting-period` |
| `evaluationCriteria` | object | Yes | Criteria payload (varies by ruleType; see scenarios) |
| `severity` | enum | Yes | One of `informational`, `warning`, `blocking` |
| `isActive` | boolean | Yes | Whether this rule is enforced in the administration |
| `administrationId` | string | Yes | FK to administration (enables per-org rule customization) |

Schema.org annotation: custom (`schatkist:BankingRule`).

#### Scenario: IBAN format rule validates Dutch IBAN pattern

- **GIVEN** a `BankingRule` with `ruleType=iban-format` and `evaluationCriteria: { pattern: "^NL[0-9]{2}[A-Z]{4}[0-9]{10}$" }`
- **WHEN** a `TreasuryAccount` with IBAN `NL91ABNA0417164300` is evaluated
- **THEN** the rule MUST match; **AND** a `ComplianceReport` MUST record a pass.

#### Scenario: Segregation rule prevents duplicate IBANs within administration

- **GIVEN** a `BankingRule` with `ruleType=segregation` and `evaluationCriteria: { checkDuplicates: true }`
- **AND** a `TreasuryAccount` with IBAN `NL91ABNA0417164300` already exists
- **WHEN** a second account with the same IBAN is configured
- **THEN** the rule MUST fail with a "duplicate IBAN in administration" error.

#### Scenario: Approval-required rule gates activation

- **GIVEN** a `BankingRule` with `ruleType=approval-required` and `evaluationCriteria: { requiresTreasurerApproval: true }`
- **AND** a `TreasuryAccount` marked `requiresApproval: true`
- **WHEN** an operator attempts to activate it without approval
- **THEN** the activation MUST fail with an "approval required" error.

### Requirement: REQ-SCHATKIST-004 `TreasuryAccount` SHALL declare a declarative draft → configured → active → monitored → compliant lifecycle

`TreasuryAccount` MUST declare an `x-openregister-lifecycle` block with
the following states + transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `configured` | operator configure | none |
| `configured` | `active` | operator activate (or auto if approval policy = `not-required`) | Multi-criteria compliance check per REQ-SCHATKIST-005 |
| `active` | `monitored` | scheduled monitoring job or operator trigger | all active `BankingRule` criteria pass |
| `monitored` | `compliant` | compliance officer sign-off | audit trail per T2 spec |
| `active` | `suspended` | compliance failure or policy change | rule failure recorded |
| `suspended` | `active` | remediation + re-activation | all rules pass again |
| `configured` | `archived` | operator action | none |
| `active` | `archived` | operator action | none |

No PHP service implements transitions. Per ADR-031 and T1 patterns, the lifecycle
is declared in the schema.

#### Scenario: Activating a treasury account with passing compliance rules

- **GIVEN** a `TreasuryAccount` in state `configured` with valid IBAN
- **AND** all active `BankingRule` criteria pass
- **WHEN** the operator activates it
- **THEN** the account state MUST become `active`; **AND** a `ComplianceReport` MUST be generated
  recording all rule-evaluation results; **AND** the audit trail MUST record the transition.

#### Scenario: Activation fails when compliance rules fail

- **GIVEN** a `TreasuryAccount` with a segregation-rule violation (duplicate IBAN)
- **WHEN** the operator attempts to activate
- **THEN** the transition MUST fail with a "segregation rule violation" error; **AND** the
  account MUST remain `configured`.

### Requirement: REQ-SCHATKIST-005 Treasury account activation SHALL enforce multi-criteria compliance precondition with declarative rule evaluation

The `TreasuryAccount.activate` transition SHALL be gated by a multi-criteria compliance precondition that evaluates every active `BankingRule` scoped to the account's administration; the precondition MUST be expressed declaratively where the engine permits, otherwise via the ADR-031 single-method exception path.

When a `TreasuryAccount` transitions from `configured → active`, the lifecycle
engine MUST evaluate ALL active `BankingRule` records applicable to that administration
per REQ-SCHATKIST-003. Evaluation is a declarative precondition check:

- If ALL applicable rules PASS, activation proceeds and a `ComplianceReport` is generated.
- If ANY rule FAILS, activation is blocked and the failure is recorded in a compliance audit event.

If conditional multi-criteria preconditions cannot be expressed declaratively,
the shape-neutral fallback per ADR-031 exception is a single-method
`OCA\Shillinq\Lifecycle\ComplianceValidator::isCompliant(...)` called *by*
the lifecycle engine.

#### Scenario: Multi-criteria check passes on IBAN format + segregation

- **GIVEN** two active `BankingRule` records (IBAN format, segregation check)
- **AND** a `TreasuryAccount` with valid Dutch IBAN not duplicated in the administration
- **WHEN** the operator activates it
- **THEN** BOTH rules MUST be evaluated; the account MUST transition to `active`.

#### Scenario: Multi-criteria check fails on segregation only

- **GIVEN** two active rules (IBAN format, segregation)
- **AND** a `TreasuryAccount` with valid IBAN but matching an existing account
- **WHEN** the operator activates
- **THEN** the segregation rule MUST fail; the IBAN-format rule MAY pass, but activation
  MUST be blocked; the compliance event MUST cite the segregation failure.

### Requirement: REQ-SCHATKIST-006 `ComplianceReport` SHALL declare compliance snapshots with automated scoring and regulatory export

`ComplianceReport` MUST be a register with the following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `reportNumber` | string | Yes | Sequential identifier per administration |
| `reportPeriod` | string | Yes | Period identifier (e.g., "2026-Q1", "2026-01") |
| `generatedAt` | datetime | Yes | Report generation timestamp |
| `treasuryAccountId` | string | No | FK to `TreasuryAccount` (if single-account report); null if aggregated |
| `complianceScore` | number (0-100) | Yes (calculated) | Automated score from rule-match criteria |
| `criteriaResults` | array of object | Yes (calculated) | Per-rule evaluation result (rule number, pass/fail, severity) |
| `status` | enum | Yes | One of `draft`, `reviewed`, `approved-for-export`, `exported` |
| `regulatoryExportFormat` | enum | No | One of `csv-master-list`, `xml-regulatory`, `json-audit` (future expansion) |
| `regulatoryExportUri` | string | No | Storage URI of exported compliance report (docudesk FK contract) |
| `administrationId` | string | Yes | FK to administration |

The `complianceScore` and `criteriaResults` fields MUST be `x-openregister-calculations`
outputs (per ADR-031 — weighted rule-match aggregation). NO `ComplianceReportService.php`
or `ComplianceScoringService.php` classes — scoring is a calculation, not a service.

#### Scenario: Reviewer confirms no PHP compliance-report service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/Compliance*.php`, `lib/Service/Report*.php`
- **THEN** no such files SHALL exist; report generation and scoring are calculation extensions.

#### Scenario: Compliance score is auto-calculated from passing rules

- **GIVEN** five active `BankingRule` records (each 20 points max), and four pass while one fails
- **WHEN** a `ComplianceReport` is generated
- **THEN** `complianceScore` MUST be 80 (4 passing rules × 20 points); `criteriaResults` MUST list
  all five rules with pass/fail status; `status` MUST be `draft`.

#### Scenario: Regulatory export captures all compliant accounts

- **GIVEN** a `ComplianceReport` with `reportPeriod="2026-Q1"` and `administrationId="adm-1"`
- **AND** three treasury accounts in state `compliant`
- **WHEN** the operator sets `regulatoryExportFormat="csv-master-list"` and triggers export
- **THEN** a CSV file MUST be generated with one row per account, columns including
  `accountNumber`, `iban`, `masterListStatus`, `complianceScore`, `lastCompliantDate`.

### Requirement: REQ-SCHATKIST-007 Compliance metrics SHALL be declared as `x-openregister-aggregations`, not a PHP reporting service

Compliance metrics MUST be expressed as `x-openregister-aggregations` queries:

1. **By Administration + Period**: GROUP BY `(administrationId, reportPeriod)`
   aggregating `ComplianceReport.complianceScore` (sum, average), counting
   total + passing accounts, and computing compliance percentage.

2. **By Rule Type**: GROUP BY `ruleType` across all applicable rules, counting
   pass/fail per rule, identifying systematically failing rules requiring
   operator attention.

3. **Aging by Last Compliance**: GROUP BY `lastCompliantDate` bucket (0-30 days,
   31-60 days, 60+ days) to surface accounts requiring revalidation.

NO `ComplianceAggregationService.php`. Per ADR-031, aggregation engine handles
GROUP BY + metric computation.

#### Scenario: Reviewer confirms no aggregation service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*Aggregation*.php`, `lib/Service/*Metrics*.php`
- **THEN** no such files SHALL exist.

#### Scenario: Period-based aggregation reports compliance percentage

- **GIVEN** a `ComplianceReport` for `reportPeriod="2026-Q1", administrationId="adm-1"`
  with 8 compliant and 2 non-compliant accounts
- **WHEN** the aggregation query `GROUP BY administrationId, reportPeriod` is executed
- **THEN** the result MUST include `{ administrationId: "adm-1", period: "2026-Q1", totalAccounts: 10, compliantCount: 8, compliancePercentage: 80 }`.

### Requirement: REQ-SCHATKIST-008 Treasury account transitions SHALL materialize audit trail events per T2 `bookkeeping-audit-trail`

Every `TreasuryAccount` lifecycle transition MUST generate an immutable audit-trail event per T2 `bookkeeping-audit-trail`; the event SHALL include the timestamp, the actor (from `IUserSession`), the before/after state, the compliance rule-evaluation results on transitions to `active`/`monitored`, and any blocking reason on transitions to `suspended`.

Every `TreasuryAccount` state transition (draft → configured → active → monitored
→ compliant) MUST generate an immutable audit trail event per the T2
`bookkeeping-audit-trail` specification. Events MUST include:

- Transition timestamp and actor (user ID from IUserSession)
- Before/after state
- Compliance rule-evaluation results (when transitioning to `active`/`monitored`)
- Any blocking reasons (when transitioning to `suspended`)

Audit trail is immutable per T2 spec; transitions are reversible (e.g., active → suspended),
but audit events are never deleted.

#### Scenario: Audit trail records compliance check on activation

- **GIVEN** a `TreasuryAccount` transitioning `configured → active`
- **WHEN** the transition succeeds with all rules passing
- **THEN** an audit event MUST be recorded with `{ timestamp, actor, beforeState: "configured", afterState: "active", complianceCheckResults: { rulesPassed: 5, rulesFailed: 0 } }`.

#### Scenario: Audit trail records blocking reason on suspension

- **GIVEN** a `TreasuryAccount` transitioning `active → suspended` due to rule violation
- **WHEN** the transition occurs
- **THEN** an audit event MUST be recorded with `{ ..., blockingReason: "segregation-rule violation: duplicate IBAN detected" }`.

### Requirement: REQ-SCHATKIST-009 Treasury banking compliance SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare:

- `Governance > Treasury Accounts` — `type: index` + `type: detail` on `TreasuryAccount`;
  detail page MUST surface lifecycle action buttons + link to linked `Account` +
  compliance status badge.
- `Governance > Banking Rules` — `type: index` + `type: detail` on `BankingRule`;
  detail page MUST surface rule evaluation criteria + severity level.
- `Governance > Compliance Reports` — `type: report` (or `type: index` fallback)
  bound to the compliance aggregations; display table MUST include `reportPeriod`,
  `administrationId`, `complianceScore`, `compliancePercentage`, `status`.

Rendering MUST use `@conduction/nextcloud-vue` generic components per ADR-024
Tier-4 — no bespoke Vue files.

#### Scenario: Treasury account index lists accounts with compliance status

- **GIVEN** the manifest declares the Treasury Accounts pages
- **WHEN** an operator opens `/index.php/apps/shillinq/treasury-accounts`
- **THEN** `CnIndexPage` MUST render columns including `accountNumber`, `iban`,
  `accountName`, `masterListStatus`, `lifecycleState`, `complianceScore` (if compliant).

#### Scenario: Compliance report detail displays aggregated metrics

- **GIVEN** a `ComplianceReport` for period "2026-Q1"
- **WHEN** an operator opens the detail page
- **THEN** the page MUST display summary stats (total accounts, compliant count,
  compliance percentage) and a table of per-rule pass/fail counts.

### Requirement: REQ-SCHATKIST-010 Compliance seed data SHALL include three baseline banking rules

On first install, the app MUST load three seed `BankingRule` records via
`ConfigurationService::importFromApp()`:

1. **`rule-iban-format`** — validates Dutch IBAN format
   - `ruleType: "iban-format"`
   - `severity: "blocking"`
   - `evaluationCriteria: { pattern: "^NL[0-9]{2}[A-Z]{4}[0-9]{10}$" }`

2. **`rule-segregation`** — enforces IBAN uniqueness per administration
   - `ruleType: "segregation"`
   - `severity: "blocking"`
   - `evaluationCriteria: { checkDuplicates: true }`

3. **`rule-approval-required`** — requires treasurer/CFO approval for activation
   - `ruleType: "approval-required"`
   - `severity: "blocking"`
   - `evaluationCriteria: { requiresTreasurerApproval: true }`

Seed rules are idempotent — re-importing MUST NOT create duplicates.

#### Scenario: Seed rules load on first install

- **GIVEN** a fresh shillinq installation
- **WHEN** `ConfigurationService::importFromApp("shillinq", register_data, force: false)` is called
- **THEN** three `BankingRule` records MUST exist with `{ ruleNumber: "rule-iban-format", ... }`,
  `{ ruleNumber: "rule-segregation", ... }`, `{ ruleNumber: "rule-approval-required", ... }`;
  **AND** re-importing with `force: false` MUST NOT create duplicates.
