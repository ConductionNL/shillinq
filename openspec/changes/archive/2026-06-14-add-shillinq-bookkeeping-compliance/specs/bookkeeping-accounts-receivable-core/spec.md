# Spec: bookkeeping-accounts-receivable-core

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** T1 `bookkeeping-general-ledger`, T2 `bookkeeping-document-attachment-integration`, T2 `bookkeeping-bank-reconciliation`

This capability **carries forward the original Shillinq invoicing
scope** — the customer invoicing surface that was Shillinq's founding
use-case — and expands it with dunning, credit-limit checks, and
bank-reconciliation-based payment matching. It is the AR half that
completes the AP half delivered by `bookkeeping-accounts-payable-core`.

## ADDED Requirements

### Requirement: REQ-AR-001 — The system SHALL store customer masters, AR invoices, and dunning records as OpenRegister-managed registers

Three registers MUST be declared in `lib/Settings/shillinq_register.json`:
`CustomerMaster`, `ARInvoice`, `DunningRecord`. No parallel PHP Mapper
classes, no custom DB tables, no app-local dunning tables (per ADR-022
anti-pattern list). OR's generic CRUD HTTP surface exposes all three.

#### Scenario: Reviewer confirms no parallel AR storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `ar_invoice`,
  `customer_master`, or `dunning_record`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-AR-002 — The `CustomerMaster` schema SHALL declare a fixed minimum field set

The `CustomerMaster` schema MUST declare the following fields with the listed types and required flags.

| Field | Type | Required | Description |
|---|---|---|---|
| `customerId` | string | Yes | Internal customer code (e.g. `DEB-0001`) |
| `legalName` | string | Yes | Official registered business name |
| `tradeName` | string | No | Trade name if different |
| `kvkNumber` | string | No | Dutch KvK registration number |
| `vatID` | string | No | Dutch VAT number (BTW-nummer) |
| `email` | string | Yes | Contact email for invoice delivery |
| `telephone` | string | No | Contact telephone |
| `iban` | string | No | Customer's IBAN for SEPA direct debit |
| `creditLimit` | number | No | Maximum outstanding AR balance allowed (EUR) |
| `dunningPolicyRef` | string | No | FK to OR dunning-policy record |
| `defaultGlAccount` | string | No | FK to `Account.accountNumber` for default AR posting |
| `administrationId` | string | Yes | FK to the owning Administration |
| `lifecycleState` | enum | Yes | One of `active`, `blocked`, `archived` |
| `ublDebtorRef` | string | No | UBL 2.1 AccountingCustomerParty ID (declared for T4 Peppol outbound) |

#### Scenario: Schema validator accepts a minimal CustomerMaster

- **GIVEN** the `CustomerMaster` schema is loaded
- **WHEN** an object `{customerId: "DEB-0001", legalName: "Klant B.V.", email: "facturen@klant.nl", administrationId: "adm-1", lifecycleState: "active"}` is validated
- **THEN** validation MUST pass.

### Requirement: REQ-AR-003 — The `ARInvoice` schema SHALL declare a fixed minimum field set

The `ARInvoice` schema MUST declare the following fields with the listed types and required flags.

| Field | Type | Required | Description |
|---|---|---|---|
| `invoiceNumber` | string | Yes | Sequential invoice number (e.g. `2026-0042`) |
| `customerId` | string | Yes | FK to `CustomerMaster.customerId` |
| `administrationId` | string | Yes | FK to the owning Administration |
| `invoiceDate` | date | Yes | Date the invoice was issued |
| `dueDate` | date | Yes | Payment due date |
| `grossAmount` | number | Yes | Total amount including VAT |
| `vatAmount` | number | No | VAT portion |
| `netAmount` | number | Yes | Net amount excluding VAT |
| `currency` | string | Yes | ISO 4217 currency code (default `EUR`) |
| `periodId` | string | Yes | FK to `FiscalPeriod.periodId` |
| `lifecycleState` | enum | Yes | One of `draft`, `issued`, `paid`, `overdue`, `disputed`, `written-off` |
| `glTransactionId` | string | No | UUID of the materialised `GLTransaction` on issue |
| `sourceDocumentUri` | string | No | Docudesk FK URI (PDF invoice) |
| `matchedBankLineId` | string | No | FK to `BankStatementLine.lineId` once payment is matched |
| `ublRef` | string | No | UBL 2.1 document reference (declared for T4 Peppol BIS 3.0 outbound) |

#### Scenario: Schema validator accepts a minimal ARInvoice

- **GIVEN** the `ARInvoice` schema
- **WHEN** an object with required fields and `lifecycleState: "draft"` is validated
- **THEN** validation MUST pass.

### Requirement: REQ-AR-004 — The `ARInvoice` schema SHALL declare an AR invoice lifecycle via `x-openregister-lifecycle`

Per ADR-031, the AR invoice lifecycle MUST be declared with the
following transitions:

| From | To | Trigger | Guard / Action |
|---|---|---|---|
| `draft` | `issued` | operator issues | credit-limit check (REQ-AR-006); materialises balanced `GLTransaction`; writes `glTransactionId` |
| `issued` | `paid` | payment matched from bank-rec | sets `matchedBankLineId`; posts payment `GLTransaction` |
| `issued` | `overdue` | automated (due date passed) | OR calculated field `isOverdue`; OR scheduled workflow triggers |
| `overdue` | `paid` | payment matched | same as `issued → paid` |
| `issued` | `disputed` | customer raises dispute | operator action |
| `overdue` | `disputed` | customer raises dispute | operator action |
| `disputed` | `issued` | dispute resolved | operator action |
| `issued` | `written-off` | operator writes off | posts compensating `GLTransaction` (debit bad-debt expense, credit AR control); requires `period-closer` or `ar-controller` role |
| `overdue` | `written-off` | operator writes off | same as above |

The `issued → overdue` auto-transition SHOULD be implemented using
OR's `x-openregister-calculations` `isOverdue` boolean (derived from
`dueDate < now`), consumed by OR's `ScheduledWorkflow` + n8n adapter
per ADR-031. If the scheduled-workflow path is unavailable, the
auto-transition MAY be deferred to manual operator action with a
visual indicator only.

#### Scenario: Issuing an AR invoice materialises a GL transaction

- **GIVEN** `ARInvoice` `2026-0042` in state `draft`, `grossAmount: 1210`,
  `customerId: "DEB-0001"`, `periodId: "2026-01"`
- **WHEN** the `issued` transition is triggered
- **THEN** a balanced `GLTransaction` MUST be materialised debiting
  the AR control account EUR 1.210 and crediting the revenue account
  EUR 1.210; **AND** `ARInvoice.glTransactionId` MUST be set.

#### Scenario: Written-off invoice posts a compensating GL entry

- **GIVEN** `ARInvoice` in state `overdue` with outstanding balance
  EUR 500
- **WHEN** the operator triggers `written-off`
- **THEN** a compensating `GLTransaction` MUST be posted debiting
  the bad-debt expense account EUR 500 and crediting the AR control
  account EUR 500; the write-off actor and timestamp MUST be recorded
  in the audit trail.

### Requirement: REQ-AR-005 — AR dunning SHALL be consumed from OR's dunning-workflow abstraction

AR dunning MUST be consumed from OpenRegister's dunning-workflow abstraction per ADR-022. Per ADR-022, the dunning workflow (reminder sequences, escalation
cadence, debt-collection hand-off) MUST be consumed from OR's
dunning-workflow abstraction. The `CustomerMaster.dunningPolicyRef`
carries the FK to the OR-managed dunning policy. Shillinq carries
no app-local dunning service and no app-local dunning-schedule table.

The dunning cadence (default: reminder 1 at +14 days past due,
reminder 2 at +30 days, formal notice at +45 days, debt-collection
escalation at +60 days) is operator-configurable per administration
through OR's dunning-policy configuration.

If OR's dunning-workflow extension is NOT yet stable at T2
implementation time, the spec annotates the gap, an OR issue is
filed, and the implementing cycle MAY carry a single-method PHP guard
(`OCA\Shillinq\Lifecycle\DunningGuard`) per ADR-031 exception. The
guard has exactly one method that evaluates dunning cadence for a
given invoice and writes `DunningRecord` objects declaratively. The
guard is removed when OR's extension lands; the spec is shape-neutral.

#### Scenario: Overdue invoice triggers dunning at configured cadence

- **GIVEN** `ARInvoice` `2026-0042` in state `overdue` with due date
  14 days ago and `CustomerMaster.dunningPolicyRef` pointing to a
  policy with reminder-1 threshold of 14 days
- **WHEN** the dunning workflow evaluates the invoice
- **THEN** a `DunningRecord` MUST be created at `dunningLevel: "reminder1"`,
  and the reminder communication MUST be triggered via OR's
  notification abstraction.

#### Scenario: Reviewer confirms no app-local dunning table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `dunning_*`
  or `lib/Service/` classes naming `Dunning*`
- **THEN** no such classes SHALL exist (other than the conditional
  `DunningGuard` per ADR-031 exception, if applicable and explicitly
  documented).

### Requirement: REQ-AR-006 — The credit-limit check on `ARInvoice` issuance SHALL be declared as an `x-openregister-aggregations` query

Before the `draft → issued` transition, the lifecycle MUST verify
that issuing the invoice would not push the customer's outstanding
AR balance past `CustomerMaster.creditLimit`. This check MUST be
declared as an `x-openregister-aggregations` query summing the
`grossAmount` of all non-`paid`, non-`written-off` `ARInvoice`
records for the customer — NOT as a PHP service. If `creditLimit`
is null or 0, the check is skipped.

#### Scenario: Invoice issuance blocked when credit limit would be exceeded

- **GIVEN** `CustomerMaster` `DEB-0001` with `creditLimit: 5000`
- **AND** outstanding AR balance (sum of `issued` + `overdue` invoices)
  is EUR 4.800
- **WHEN** an operator attempts to issue a new `ARInvoice` for EUR 500
- **THEN** the `issued` transition MUST be rejected with a "credit
  limit exceeded" error; outstanding balance plus new invoice (5.300)
  exceeds the limit (5.000).

### Requirement: REQ-AR-007 — The `DunningRecord` schema SHALL declare a fixed minimum field set

The `DunningRecord` schema MUST declare the following fields with the listed types and required flags.

| Field | Type | Required | Description |
|---|---|---|---|
| `dunningId` | string | Yes | Unique dunning record identifier |
| `arInvoiceId` | string | Yes | FK to `ARInvoice.invoiceNumber` |
| `dunningLevel` | enum | Yes | One of `reminder1`, `reminder2`, `formal-notice`, `collection` |
| `issuedDate` | date | Yes | Date the dunning communication was issued |
| `dueDate` | date | Yes | New payment deadline in the dunning notice |
| `amount` | number | Yes | Outstanding amount stated in the notice |
| `administrationId` | string | Yes | FK to the owning Administration |
| `status` | enum | Yes | One of `pending`, `sent`, `responded`, `escalated`, `withdrawn` |

#### Scenario: Schema validator accepts a minimal DunningRecord

- **GIVEN** the `DunningRecord` schema
- **WHEN** an object with required fields is validated
- **THEN** validation MUST pass.

### Requirement: REQ-AR-008 — AR aging SHALL be declared as an `x-openregister-aggregations` query

Per ADR-031, the AR aging report MUST be declared as an
`x-openregister-aggregations` query grouping `ARInvoice` by
`(customerId, agingBucket)` where `agingBucket` is derived from
`dueDate` relative to the report date (0–30 days, 31–60 days,
61–90 days, 90+ days). Only invoices with `lifecycleState` NOT IN
(`paid`, `written-off`) are included.

#### Scenario: AR aging shows outstanding invoices per customer per bucket

- **GIVEN** 2 outstanding invoices for customer `DEB-0001`: one 15
  days overdue (EUR 1.000) and one 50 days overdue (EUR 2.000)
- **WHEN** the AR aging aggregation is queried
- **THEN** `DEB-0001` MUST appear in the `0-30` bucket (EUR 1.000)
  and the `31-60` bucket (EUR 2.000).

### Requirement: REQ-AR-009 — UBL 2.1 / Peppol BIS 3.0 field shapes SHALL be declared for T4 attachment

The `ARInvoice` schema MUST declare the `ublRef` field (string, optional)
so that a future T4 UBL/Peppol e-invoicing capability can attach
without a destructive migration. The actual UBL 2.1 XML generation
MUST NOT be implemented in T2 — it is explicitly deferred to T4.
The spec is shape-neutral for which format T4 selects.

#### Scenario: UBL ref field exists but is unused in T2

- **GIVEN** an `ARInvoice` in state `issued`
- **WHEN** the object is inspected
- **THEN** the `ublRef` field MUST exist on the schema (may be null).

### Requirement: REQ-AR-010 — Accounts receivable SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare the following navigation entries:
- `Bookkeeping > Customers` — `type: index` + `type: detail` for
  `CustomerMaster`.
- `Bookkeeping > Accounts Receivable` — `type: index` + `type: detail`
  for `ARInvoice`.
- `Bookkeeping > AR Aging` — `type: report` (or `type: index`
  fallback) for the AR aging aggregation.
- `Bookkeeping > Dunning` — `type: index` for `DunningRecord`,
  filterable by `status` and `dunningLevel`.

No bespoke Vue components are authored (per ADR-024).

#### Scenario: AR manifest entries exist and validate

- **GIVEN** `src/manifest.json` is loaded
- **WHEN** `node tests/validate-manifest.js` is run
- **THEN** the script MUST exit 0 and all four AR navigation entries
  MUST be present.
