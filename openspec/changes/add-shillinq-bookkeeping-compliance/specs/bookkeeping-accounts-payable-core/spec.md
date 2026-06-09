# Spec: bookkeeping-accounts-payable-core

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** T1 `bookkeeping-general-ledger`, T2 `bookkeeping-document-attachment-integration`

This capability carries forward the AP half of the legacy shillinq
invoicing scope (see Motivation in `proposal.md`) and addresses the
top-3 customer-asked capability in the intelligence-db
`competitor_features` cluster with `app_slug=shillinq`.

## ADDED Requirements

### Requirement: REQ-AP-001 — The system SHALL store vendor masters, AP invoices, and payment runs as OpenRegister-managed registers

Three registers MUST be declared in `lib/Settings/shillinq_register.json`:
`VendorMaster`, `APInvoice`, `PaymentRun`. No parallel PHP Mapper
classes, no custom DB tables, no app-local approval tables (per ADR-022
anti-pattern list). OR's generic CRUD HTTP surface exposes all three;
no additional shillinq controllers are required.

#### Scenario: Reviewer confirms no parallel AP storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `ap_invoice`,
  `vendor_master`, or `payment_run`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-AP-002 — The `VendorMaster` schema SHALL declare a fixed minimum field set

The `VendorMaster` schema MUST declare the following fields with the listed types and required flags.

| Field | Type | Required | Description |
|---|---|---|---|
| `vendorId` | string | Yes | Internal vendor code (e.g. `CRD-0001`) |
| `legalName` | string | Yes | Official registered business name |
| `tradeName` | string | No | Trade name if different from legal name |
| `kvkNumber` | string | No | Dutch KvK registration number |
| `vatID` | string | No | Dutch VAT number (BTW-nummer) |
| `iban` | string | Yes | Vendor's SEPA bank account IBAN |
| `bic` | string | No | BIC/SWIFT code |
| `email` | string | Yes | Contact email for remittance advice |
| `paymentTermsDays` | integer | No | Default payment terms (days net, e.g. `30`) |
| `defaultGlAccount` | string | No | FK to `Account.accountNumber` for default AP posting |
| `administrationId` | string | Yes | FK to the owning Administration |
| `lifecycleState` | enum | Yes | One of `active`, `blocked`, `archived` |
| `taxRegistrationCountry` | string | No | ISO 3166-1 alpha-2 country code for cross-border VAT |

#### Scenario: Schema validator accepts a minimal VendorMaster

- **GIVEN** the `VendorMaster` schema is loaded
- **WHEN** an object `{vendorId: "CRD-0001", legalName: "Leverancier B.V.", iban: "NL91ABNA0417164300", email: "facturen@leverancier.nl", administrationId: "adm-1", lifecycleState: "active"}` is validated
- **THEN** validation MUST pass.

### Requirement: REQ-AP-003 — The `APInvoice` schema SHALL declare a fixed minimum field set

The `APInvoice` schema MUST declare the following fields with the listed types and required flags.

| Field | Type | Required | Description |
|---|---|---|---|
| `vendorInvoiceRef` | string | Yes | Vendor's own invoice number |
| `vendorId` | string | Yes | FK to `VendorMaster.vendorId` |
| `administrationId` | string | Yes | FK to the owning Administration |
| `invoiceDate` | date | Yes | Date the vendor invoice was issued |
| `dueDate` | date | Yes | Payment due date per vendor's payment terms |
| `grossAmount` | number | Yes | Total amount including VAT in EUR |
| `vatAmount` | number | No | VAT portion |
| `netAmount` | number | Yes | Net amount excluding VAT |
| `currency` | string | Yes | ISO 4217 currency code (default `EUR`) |
| `periodId` | string | Yes | FK to `FiscalPeriod.periodId` (posting period) |
| `lifecycleState` | enum | Yes | One of `draft`, `received`, `matched`, `approved`, `posted`, `paid`, `voided` |
| `poRef` | string | No | FK to future PO register (declared for T4 3-way match) |
| `grRef` | string | No | FK to future GR register (declared for T4 3-way match) |
| `glTransactionId` | string | No | UUID of the materialised `GLTransaction` on posting |
| `paymentRunId` | string | No | FK to `PaymentRun.runId` once included in a payment run |
| `sourceDocumentUri` | string | No | Docudesk FK URI per `bookkeeping-document-attachment-integration` |

#### Scenario: Schema validator accepts a minimal APInvoice

- **GIVEN** the `APInvoice` schema
- **WHEN** an object with required fields and `lifecycleState: "draft"` is validated
- **THEN** validation MUST pass.

### Requirement: REQ-AP-004 — The `APInvoice` schema SHALL declare an AP invoice lifecycle via `x-openregister-lifecycle`

Per ADR-031, the AP invoice lifecycle MUST be declared as an
`x-openregister-lifecycle` block with the following transitions:

| From | To | Trigger | Guard / Action |
|---|---|---|---|
| `draft` | `received` | operator records receipt | none |
| `received` | `matched` | 2-way or 3-way match passes (REQ-AP-006) | match precondition guard |
| `matched` | `approved` | approval-workflow completes | OR approval-workflow consumed (REQ-AP-005) |
| `approved` | `posted` | operator posts | materialises balanced `GLTransaction`; writes `glTransactionId` |
| `posted` | `paid` | payment run settles; writes `paymentRunId` | none |
| `posted` | `voided` | operator voids; posts reversing `GLTransaction` | compensating GL entry |
| `draft` | `voided` | operator discards draft | no GL impact |
| `paid` | `voided` | — | **FORBIDDEN** — paid invoices cannot be voided; correction via credit note |

Materialising a balanced `GLTransaction` on the `approved → posted`
transition MUST follow the same pattern as T1's `JournalEntry`
lifecycle (REQ-JE-007).

#### Scenario: Posting an approved AP invoice materialises a GL transaction

- **GIVEN** `APInvoice` `INK-2026-0001` in state `approved`,
  `netAmount: 1000`, `vatAmount: 210`, `grossAmount: 1210`
- **WHEN** the operator triggers the `posted` transition
- **THEN** a balanced `GLTransaction` MUST be materialised debiting
  the appropriate expense account EUR 1.210 and crediting the AP
  control account EUR 1.210; **AND** `APInvoice.glTransactionId`
  MUST be set to the new transaction's UUID.

#### Scenario: Voiding a paid invoice is rejected

- **GIVEN** `APInvoice` in state `paid`
- **WHEN** the operator attempts to void it
- **THEN** the lifecycle engine MUST reject the transition with a
  "paid invoices cannot be voided" error.

### Requirement: REQ-AP-005 — AP approval routing SHALL be consumed from OR's approval-workflow abstraction

Per ADR-022, the `matched → approved` transition MUST consume OR's
approval-workflow abstraction via `x-openregister-lifecycle.requires`.
No app-local approval table, no shillinq `ApprovalService`, no
custom approval-chain PHP code SHALL be created. The approval policy
(single approver vs multi-step chain) is operator-configurable through
OR's approval-workflow configuration; shillinq declares only that the
transition requires an approved workflow step.

#### Scenario: AP invoice approval is routed through OR's approval-workflow

- **GIVEN** `APInvoice` in state `matched`
- **WHEN** the `approved` transition is requested
- **THEN** OR's approval-workflow MUST be invoked to manage the
  approval routing; the transition MUST NOT complete until the
  required approval(s) are granted.

#### Scenario: Reviewer confirms no app-local approval table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or `lib/Service/`
  classes naming `Approval*`
- **THEN** no such classes SHALL exist (other than any thin lifecycle
  guard permitted by ADR-031 exception, if explicitly documented).

### Requirement: REQ-AP-006 — The `APInvoice` lifecycle SHALL implement conditional 3-way match

The `APInvoice` lifecycle MUST implement a conditional 3-way match (PO + GR + invoice when both PO and GR are present; 2-way fallback otherwise). When the `received → matched` transition is triggered:

- **If PO and GR registers are available** (future procurement T4):
  the precondition MUST verify that invoice quantities and amounts
  match the referenced PO and GR within the configured tolerance.
  FK fields `poRef` and `grRef` on `APInvoice` carry the references.
- **If PO and GR registers are absent** (T2 baseline): the precondition
  reduces to a 2-way match — the invoice total is confirmed against
  the vendor master and the operator explicitly approves the amount.

The conditional precondition MUST be declared via
`x-openregister-lifecycle.requires` with conditional clauses per the
OR engine's documented support. If the engine cannot express the
conditionality declaratively, a single-method PHP guard
(`OCA\Shillinq\Lifecycle\ThreeWayMatchGuard`) MAY be referenced per
ADR-031 §"PHP guards remain a legitimate seam". The guard has exactly
one method: `matches(string $invoiceId, ?string $poRef, ?string $grRef): bool`.

#### Scenario: 2-way match (no PO/GR) passes for an operator-approved invoice

- **GIVEN** `APInvoice` with `poRef: null`, `grRef: null` in state
  `received`
- **AND** the operator confirms the amount matches the vendor's paper
  invoice
- **WHEN** the `matched` transition is triggered
- **THEN** the transition MUST succeed without a PO/GR match check.

#### Scenario: 3-way match fails when quantities do not match

- **GIVEN** `APInvoice` with `poRef: "PO-2026-0042"`,
  `grRef: "GR-2026-0011"` in state `received`
- **AND** the invoice quantity (100 units) exceeds the GR quantity
  (80 units)
- **WHEN** the `matched` transition is triggered
- **THEN** the guard MUST return `false` and the transition MUST be
  rejected with a "quantity mismatch" error naming the discrepancy.

### Requirement: REQ-AP-007 — The `PaymentRun` schema SHALL declare SEPA pain.001 XML as an `x-openregister-calculations` field

Per ADR-031, the SEPA payment-run XML MUST NOT be generated by a
PHP `SepaPaymentService`. It MUST be declared as an
`x-openregister-calculations` field named `sepaXml` on `PaymentRun`,
computed from the selected invoices' IBAN, amount, and remittance
data. The operator downloads the generated XML and uploads it to
their bank portal. iDEAL payment-link generation per invoice MUST
also be declared as a calculation on `APInvoice`.

The `PaymentRun` schema MUST declare at minimum:

| Field | Type | Required | Description |
|---|---|---|---|
| `runId` | string | Yes | Unique payment run code (e.g. `PAY-2026-001`) |
| `administrationId` | string | Yes | FK to the owning Administration |
| `runDate` | date | Yes | Intended value date for bank processing |
| `totalAmount` | number | Yes | Sum of selected invoice amounts |
| `currency` | string | Yes | ISO 4217 (default `EUR`) |
| `selectedInvoiceIds` | array | Yes | Array of `APInvoice` UUIDs included in this run |
| `sepaXml` | string | No | `x-openregister-calculations` output: SEPA pain.001 XML |
| `lifecycleState` | enum | Yes | One of `draft`, `approved`, `exported`, `settled` |

#### Scenario: SEPA XML is generated as a calculated field

- **GIVEN** a `PaymentRun` with 3 selected `APInvoice` UUIDs, each
  with a valid vendor IBAN and amount
- **WHEN** the `PaymentRun` object is read from OR
- **THEN** the `sepaXml` field MUST contain valid SEPA pain.001.001.03
  XML composing the 3 credit transfers.

#### Scenario: Reviewer confirms no PHP SepaPaymentService

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/` classes naming `Sepa*`,
  `Payment*Service`, or `Ideal*`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-AP-008 — The `VendorMaster` schema SHALL carry a declarative active/blocked/archived lifecycle

The `VendorMaster` schema MUST declare an `x-openregister-lifecycle`
block (per ADR-031) with:
- `active` — vendor is available for new AP invoices.
- `blocked` — vendor exists but new invoices are rejected at
  `APInvoice` creation (lifecycle precondition checks vendor state).
- `archived` — historical reference only; no new invoices.

Transitions: `active → blocked`, `blocked → active`, `active → archived`,
`blocked → archived`. No guard required on vendor blocking; an open-balance
guard SHOULD be declared for archiving (similar to REQ-CoA-005 on `Account`).

#### Scenario: Blocked vendor cannot receive new AP invoices

- **GIVEN** `VendorMaster` `CRD-0001` in state `blocked`
- **WHEN** an operator attempts to create a new `APInvoice` referencing
  `vendorId: "CRD-0001"`
- **THEN** the `APInvoice` creation MUST be rejected with a "vendor
  blocked" lifecycle precondition error.

### Requirement: REQ-AP-009 — AP aging SHALL be declared as an `x-openregister-aggregations` query

Per ADR-031, the AP aging report MUST be declared as an
`x-openregister-aggregations` query grouping `APInvoice` records by
`(vendorId, agingBucket)` where `agingBucket` is computed from
`dueDate` relative to the report date (0–30 days, 31–60 days, 61–90
days, 90+ days). Only invoices with `lifecycleState` NOT IN
(`paid`, `voided`) are included.

#### Scenario: AP aging groups outstanding invoices by aging bucket

- **GIVEN** 3 outstanding AP invoices for vendor `CRD-0001` with
  due dates 10 days ago, 45 days ago, and 95 days ago
- **WHEN** the AP aging aggregation is queried
- **THEN** vendor `CRD-0001` MUST appear in buckets `0-30`, `31-60`,
  and `90+` with the respective invoice amounts.

### Requirement: REQ-AP-010 — Accounts payable SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare the following navigation entries:
- `Bookkeeping > Vendors` — `type: index` + `type: detail` for
  `VendorMaster`.
- `Bookkeeping > Accounts Payable` — `type: index` + `type: detail`
  for `APInvoice`.
- `Bookkeeping > AP Aging` — `type: report` (or `type: index`
  fallback) for the AP aging aggregation.
- `Bookkeeping > Payment Runs` — `type: index` + `type: detail` for
  `PaymentRun`; detail page includes a SEPA XML download action.

No bespoke Vue components are authored (per ADR-024).

#### Scenario: AP manifest entries exist and validate

- **GIVEN** `src/manifest.json` is loaded
- **WHEN** `node tests/validate-manifest.js` is run
- **THEN** the script MUST exit 0 and all four AP navigation entries
  MUST be present.
