# Spec: Vpb Corporate Tax

**Status:** proposed
**Scope:** shillinq
**Tier:** T2-specialization (Vpb)
**Depends on:** `bookkeeping-chart-of-accounts`, `bookkeeping-general-ledger`

## Preamble

This specification defines the vennootschapsbelasting (Vpb) corporate-tax
administration for Dutch government enterprises: a `TaxDeadline` register, a
`TaxPaymentTracking` register, a read-only computed `QuarterlyTaxStatement`, and
an additive `taxTreatment` classification on the existing `GLLine` schema. All
requirements use RFC 2119 language (MUST, SHOULD, MAY).

Per ADR-037 the schemas, the additive `GLLine.taxTreatment` property, and the
seed objects are declared in the register fragment
`lib/Settings/register.d/bookkeeping-vpb-corporate-tax.json` — the monolith
`shillinq_register.json` is never edited; the fragment loader's additive
key-union merge (`SettingsService::deepMergeConfig`) folds the new property onto
the existing `GLLine` schema. Per ADR-022 the quarterly statement is computed on
demand by `TaxReportService` from existing `GLTransaction` + `GLLine` data via
the real OpenRegister `ObjectService` API (`find` / `findAll`). Deadline and
payment CRUD reuse OpenRegister's generic object API; only the computed
aggregation, the reconcile endpoint, and the deadline-reminder dispatch carry
bespoke PHP.

---

## ADDED Requirements

### Requirement: Tax Deadline Register

The system MUST declare a `TaxDeadline` register with fields `deadlineDate`
(date), `deadlineType` (enum: provisional-payment, final-return,
extension-request), `description` (string), `fiscalYear` (integer), `quarter`
(integer 1-4, nullable), `status` (enum: pending, submitted, filed, archived),
`relatedPeriodId` (string), and `administrationId` (string).

#### Scenario: Declare TaxDeadline schema

- **GIVEN** the shillinq register fragment is loaded
- **WHEN** OpenRegister imports the configuration
- **THEN** a `TaxDeadline` schema exists with the required fields and enum
  constraints, scoped to an administration via `administrationId`.

### Requirement: Tax Payment Tracking Overlay

The system MUST declare a `TaxPaymentTracking` register with fields
`paymentDate` (datetime), `paymentType` (enum: provisional, final, adjustment),
`amount` (number), `currency` (string), `status` (enum: pending, paid,
reconciled), `linkedGLAccount` (string FK to Account.accountNumber),
`relatedDeadlineId` (string), `description` (string), and `administrationId`
(string).

#### Scenario: Declare TaxPaymentTracking schema

- **GIVEN** the shillinq register fragment is loaded
- **WHEN** OpenRegister imports the configuration
- **THEN** a `TaxPaymentTracking` schema exists with the required fields,
  amount/currency, and an administration scope.

### Requirement: Quarterly Tax Report Aggregation

The system MUST compute a `QuarterlyTaxStatement` for an administration +
fiscal year + quarter by filtering `GLLine` rows by fiscal period and grouping
by account type and `taxTreatment`, producing revenue, operating expenses,
non-operating items, special deductions, and net taxable income.

#### Scenario: Compute quarterly statement

- **GIVEN** an administration with `GLTransaction`/`GLLine` rows in period
  `2025-Q1`
- **WHEN** `GET /api/tax-reports/{year}/{quarter}` is called for that
  administration
- **THEN** the response carries summed revenue, deductible/non-deductible
  expense totals, net taxable income, the per-account breakdown, and a count of
  untagged tax-relevant postings.

### Requirement: GLLine Tax Treatment Tag

The system MUST extend the existing `GLLine` schema additively with a
`taxTreatment` property (enum: normal, deductible, nonDeductible, special;
default normal) classifying the tax treatment of a posting, without editing the
monolith register file.

#### Scenario: Tag a posting

- **GIVEN** the register fragment is merged onto the base `GLLine` schema
- **WHEN** a `GLLine` is created without a `taxTreatment`
- **THEN** it defaults to `normal`; a posting MAY be tagged `deductible`,
  `nonDeductible`, or `special`.

### Requirement: Deadline Management UI

The system MUST provide an index page for tax deadlines with search, filter
(by deadline type, status, fiscal year, quarter) and bulk status actions.

#### Scenario: List deadlines

- **GIVEN** the operator opens the Vpb deadlines page
- **WHEN** the page loads
- **THEN** deadlines render in a sortable table with columns deadline date,
  type, status, and related period; search and facet filters narrow the list.

### Requirement: Deadline Detail

The system MUST provide a deadline detail page showing the deadline summary,
related period, linked payment tracking, audit trail, and files/notes.

#### Scenario: Open a deadline

- **GIVEN** a deadline exists
- **WHEN** the operator opens its detail page
- **THEN** the deadline fields, related payments and the audit trail are shown.

### Requirement: Payment Tracking UI

The system MUST provide an index page for tax payment tracking with search and
filter (by payment type, status, fiscal year).

#### Scenario: List payments

- **GIVEN** the operator opens the Vpb payments page
- **WHEN** the page loads
- **THEN** payments render with columns payment date, type, amount, status, and
  linked account.

### Requirement: Payment Reconciliation

The system MUST provide payment detail and reconciliation that matches a
payment record to GL postings by account + amount + date and flags divergence.

#### Scenario: Reconcile a payment

- **GIVEN** a payment with `linkedGLAccount` and `amount`
- **WHEN** the reconcile endpoint is called
- **THEN** the system reports whether a matching GL posting exists and the
  variance between the GL amount and the payment amount.

### Requirement: Quarterly Report View

The system MUST provide a quarterly tax statement view showing aggregated
revenue, operating expenses, non-operating items, special deductions, and net
taxable income, with a per-account breakdown.

#### Scenario: View quarterly statement

- **GIVEN** GL data for a fiscal period
- **WHEN** the operator opens the quarterly statement
- **THEN** the aggregated totals and per-account rows are displayed.

### Requirement: Untagged Posting Warning

The quarterly statement MUST report the count of tax-relevant GL postings that
lack a `taxTreatment` tag.

#### Scenario: Warn on untagged postings

- **GIVEN** tax-relevant accounts with some postings missing `taxTreatment`
- **WHEN** the quarterly statement is computed
- **THEN** the response includes an `untaggedCount` greater than zero.

### Requirement: Report Export

The system MUST expose the per-account rows of the quarterly statement so an
export (Excel/PDF, via OpenRegister's generic facilities) can produce the
columns account code, account name, amount, and tax treatment.

#### Scenario: Export rows present

- **GIVEN** a computed quarterly statement
- **WHEN** the response is returned
- **THEN** the per-account rows carry account code, account name, amount and
  tax treatment so an export can be produced.

### Requirement: Annual Summary

The system MUST provide an annual summary aggregating Q1–Q4 statements with the
estimated tax liability.

#### Scenario: Annual roll-up

- **GIVEN** quarterly statements for a fiscal year
- **WHEN** the annual report endpoint is called
- **THEN** the four quarters are summed into an annual net taxable income and an
  estimated tax liability.

### Requirement: Deadline Reminders

The system MUST dispatch deadline reminder notifications 7 days and 1 day
before a deadline's `deadlineDate`, via a daily background job using
OpenRegister's notification facility, linking to the deadline detail page.

#### Scenario: Reminder dispatched

- **GIVEN** a `pending` deadline due in 7 days
- **WHEN** the daily reminder job runs
- **THEN** a notification is dispatched for that deadline; the same deadline is
  not re-notified for the same window on a subsequent run.

### Requirement: Vpb Settings

The system MUST provide a Vpb settings page for the operator (reminder windows,
deadline-type visibility).

#### Scenario: Open settings

- **GIVEN** the operator opens the Vpb settings page
- **WHEN** the page loads
- **THEN** the settings page renders.

### Requirement: Tax Treatment Configuration

The settings MUST surface the `taxTreatment` categories (normal, deductible,
nonDeductible, special) used to classify postings.

#### Scenario: View tax treatment categories

- **GIVEN** the settings page
- **WHEN** it loads
- **THEN** the four tax-treatment categories are shown.

### Requirement: Navigation Entry

The system MUST register Vpb navigation entries (Deadlines, Payments, Reports,
Settings) via an ADR-037 manifest fragment.

#### Scenario: Navigation present

- **GIVEN** the manifest fragments are merged
- **WHEN** the app loads
- **THEN** the Vpb sub-entries appear in the menu and resolve to existing pages.
