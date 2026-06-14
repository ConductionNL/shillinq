# Spec: bookkeeping-accounts-payable-core

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`./bookkeeping-document-attachment-integration/spec.md` (docudesk FK contract)

## ADDED Requirements

### Requirement: REQ-AP-001: Accounts payable SHALL be declared as `VendorMaster` + `APInvoice` + `PaymentRun` registers, not duplicates of GL

Accounts payable MUST be expressed as three new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `VendorMaster` — vendor party (name, IBAN, BIC, KvK, BTW,
  payment terms, default expense account).
- `APInvoice` — sub-ledger invoice (vendor reference, due date,
  amount, line breakdown, attachment FK to docudesk).
- `PaymentRun` — operator-curated batch of selected `APInvoice`
  UUIDs with a pain.001 / iDEAL output.

Posting an `APInvoice` MUST materialise exactly one balanced
`GLTransaction` per the T1 REQ-JE-007 pattern (the same
lifecycle-driven materialisation T1 used for `JournalEntry`).
`GLLine.subLedgerType: "ap"` + `subLedgerRef: <APInvoice UUID>`
resolves to the materialised AP line (T1 REQ-GL-009 stub now
backed by a real FK).

No custom database tables, no parallel storage. Per ADR-022, every
register consumes OR's audit-trail-immutable and RBAC
abstractions.

#### Scenario: Reviewer confirms no parallel AP table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or
  `appinfo/info.xml` table declarations naming `ap_invoice`,
  `vendor_master`, `payment_run`, or `accounts_payable_*`
- **THEN** no such classes or declarations SHALL exist.

#### Scenario: GLLine sub-ledger ref resolves to a real APInvoice

- **GIVEN** T2 is live and an `APInvoice` `INV-V-2026-0001` is
  posted
- **WHEN** its materialised `GLLine` is inspected
- **THEN** the line MUST carry `subLedgerType: "ap"`,
  `subLedgerRef: "<UUID of INV-V-2026-0001>"`, **AND** the FK MUST
  resolve via OR's relation engine.

### Requirement: REQ-AP-002: The `VendorMaster` schema SHALL declare a fixed minimum field set

| Field | Type | Required | Purpose |
|---|---|---|---|
| `vendorNumber` | string | Yes | Stable identifier (per administration) |
| `name` | string | Yes | Legal name |
| `tradingName` | string | No | Alternate / DBA name |
| `kvkNumber` | string | No | Dutch KvK number (8 digits) |
| `btwNumber` | string | No | Dutch BTW / EU VAT number |
| `iban` | string | No | Default IBAN for payments |
| `bic` | string | No | BIC / SWIFT |
| `paymentTermDays` | integer | Yes (default 30) | Default payment term in days |
| `defaultExpenseAccountNumber` | string | No | FK to `Account.accountNumber` for default expense coding |
| `address` | object | No | Street/number/postcode/city/country |
| `email` | string | No | Primary contact email |
| `phone` | string | No | Primary contact phone |
| `administrationId` | string | Yes | FK to the administration owning the vendor |
| `lifecycleState` | enum | Yes | One of `active`, `blocked`, `archived` |
| `contactRef` | string | No | If OR's `contact` abstraction is available per ADR-022, the FK to the shared contact record; else null. Resolution lives in `opsx-ff` design discovery. |

Schema.org annotation: `schema:Organization` (per shillinq config.yaml
`rules.specs`).

#### Scenario: Schema validator accepts a minimal vendor

- **GIVEN** the schema
- **WHEN** `{vendorNumber:"V-001", name:"Acme BV", paymentTermDays:30, administrationId:"adm-1", lifecycleState:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Reviewer flags contact duplication if OR contact is available

- **GIVEN** OR's `contact` abstraction is stable per ADR-022
- **WHEN** the `VendorMaster` schema is reviewed
- **THEN** the reviewer MUST confirm `contactRef` is populated
  rather than the address/email/phone fields being treated as
  authoritative; the design.md MUST note the consume-vs-duplicate
  decision per ADR-022.

### Requirement: REQ-AP-003: The `APInvoice` schema SHALL declare a fixed minimum field set with line breakdown

| Field | Type | Required | Purpose |
|---|---|---|---|
| `invoiceNumber` | string | Yes | Shillinq-side reference (auto-generated per administration) |
| `vendorInvoiceRef` | string | Yes | The vendor's own invoice number |
| `vendorId` | string | Yes | FK to `VendorMaster` UUID |
| `invoiceDate` | date | Yes | Date on the vendor's invoice |
| `dueDate` | date | Yes | Auto-calculated from `invoiceDate + vendor.paymentTermDays`; overrideable |
| `currency` | string (ISO 4217) | Yes | T2: base currency only; T5 adds multi-currency |
| `totalAmount` | number ≥ 0 | Yes | Total amount including tax |
| `taxAmount` | number | No | Tax/VAT amount (T3 adds VAT posting automation; T2 carries the field) |
| `lines` | array of object | Yes | `{description, accountNumber, amount, taxCode}` rows |
| `sourceDocumentUri` | string | No | docudesk FK URI per `bookkeeping-document-attachment-integration` |
| `purchaseOrderRef` | string | No | FK to PO register (future T4 procurement; nullable in T2) |
| `goodsReceiptRef` | string | No | FK to Goods Receipt register (future T4); nullable in T2 |
| `approvalState` | enum | Yes | One of `not-required`, `pending`, `approved`, `rejected` (per REQ-AP-005) |
| `state` | enum | Yes | One of `draft`, `pending`, `approved`, `posted`, `paid`, `disputed`, `voided` (per REQ-AP-004) |
| `glTransactionId` | string | No | Back-reference to materialised `GLTransaction` once posted |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Invoice` (per shillinq config.yaml
`rules.specs`).

#### Scenario: Schema validator accepts a 1-line invoice

- **GIVEN** the schema
- **WHEN** an invoice with one line referencing an existing
  `Account` is saved as `draft`
- **THEN** validation MUST pass; `dueDate` MUST be auto-set from
  vendor payment terms if absent.

#### Scenario: Schema rejects negative line amount

- **GIVEN** the schema
- **WHEN** a line with `amount: -50` is saved
- **THEN** validation MUST fail with a "non-negative amount" error
  (credit-notes are a separate journal type, not a negative line).

### Requirement: REQ-AP-004: `APInvoice` SHALL declare a declarative draft → pending → approved → posted → paid lifecycle

`APInvoice` MUST declare an `x-openregister-lifecycle` block with
the following states + transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `pending` | operator submit | none |
| `pending` | `approved` | approver action | REQ-AP-005 approval-workflow guard |
| `pending` | `rejected` | approver action | none — `approvalState` becomes `rejected`, returns to `draft` for revision |
| `approved` | `posted` | operator post (or auto on approval — administration policy) | 3-way match per REQ-AP-006; balanced materialisation per T1 REQ-JE-007 pattern; `FiscalPeriod` is `open` per REQ-PC-004 |
| `draft` | `posted` | operator post (when approval policy = `not-required`) | same guards as above |
| `posted` | `paid` | payment-match event from `PaymentRun` or bank reconciliation | matched payment amount equals `totalAmount` (per REQ-AP-008) |
| `posted` | `disputed` | operator action | none — payment held; investigation note recorded |
| `disputed` | `posted` | operator action (resolution) | none |
| `posted` | `voided` | operator action | materialised `GLTransaction` MUST already be reversed per T1 REQ-GL-004 |

No PHP service implements transitions. Per ADR-031 and T1's
REQ-JE-007 pattern, the lifecycle is declared in the schema.

#### Scenario: Posting a balanced approved AP invoice materialises GL

- **GIVEN** an `APInvoice` in state `approved` with valid lines
- **WHEN** the operator posts it
- **THEN** a balanced `GLTransaction` MUST be materialised (debit
  expense accounts per the lines, credit AP control account per
  `Account` flagged `isAPControlAccount`); **AND** the invoice
  state MUST become `posted`; **AND** `glTransactionId` MUST
  reference the new transaction.

#### Scenario: Voiding without GL reversal fails

- **GIVEN** a posted `APInvoice` whose `GLTransaction` is not
  reversed
- **WHEN** an operator attempts to void
- **THEN** the transition MUST fail with a "reverse the GL
  transaction first" error.

### Requirement: REQ-AP-005: AP approval routing SHALL consume OR's approval-workflow abstraction; no app-local approval table

`APInvoice` MUST consume OR's approval-workflow extension via
`x-openregister-lifecycle.requires` on the `pending → approved`
transition. Approval policy (threshold amounts, dual control for
amounts above €10 000, role of eligible approvers, escalation on
SLA breach) MUST be configured through OR's approval-workflow
configuration — NOT through an app-local approver table or
per-shillinq approval service. Per ADR-022 anti-pattern list.

The `approvalState` field tracks the OR workflow's state (mirroring
the T1 `JournalEntry.approvalState` shape).

#### Scenario: Below-threshold AP invoice auto-approves

- **GIVEN** an administration policy "AP invoices ≤ €1 000 auto-
  approve"
- **WHEN** an operator submits an €800 invoice
- **THEN** the invoice MUST transition `draft → approved`
  directly with `approvalState: not-required`.

#### Scenario: Above-threshold AP invoice requires approver

- **GIVEN** an administration policy "AP invoices > €1 000
  require a CFO approver"
- **WHEN** an operator submits a €5 000 invoice
- **THEN** the invoice MUST transition `draft → pending` with
  `approvalState: pending`; **AND** the configured approver MUST
  receive an OR notification.

#### Scenario: Reviewer confirms no parallel approval table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `ap_approval_*`, `approver_*`, `approval_route_*`
- **THEN** no such classes SHALL exist; approval flow comes from
  OR.

### Requirement: REQ-AP-006: AP posting SHALL enforce a 3-way match when PO + Goods Receipt registers are present; 2-way match otherwise

When the future procurement capability (T4) ships, `APInvoice`'s
`approved → posted` transition MUST require quantity and amount
parity between the linked Purchase Order, the linked Goods
Receipt, and the invoice lines. The precondition MUST be
declared in `x-openregister-lifecycle.requires` on `APInvoice.post`.

When PO/GR registers are NOT yet present (T2 reality), the
precondition reduces to a 2-way match: invoice + approval per
REQ-AP-005. The implementing engine MUST detect the absence of
the PO/GR registers at runtime (per OR's register discovery) and
apply the appropriate match shape.

If conditional preconditions cannot be expressed declaratively,
the shape-neutral fallback per ADR-031 exception is a
single-method `OCA\Shillinq\Lifecycle\ThreeWayMatchGuard` called
*by* the lifecycle engine.

#### Scenario: 2-way match passes in T2 reality

- **GIVEN** PO + GR registers are NOT installed (T2 baseline)
- **WHEN** an approved AP invoice is posted
- **THEN** the post MUST succeed without requiring a PO or GR
  reference.

#### Scenario: 3-way match enforces parity when PO + GR present

- **GIVEN** PO + GR registers are installed (future T4)
- **AND** an approved AP invoice references PO `PO-0001` (10
  units @ €100) and GR `GR-0001` (10 units received)
- **WHEN** the operator posts an invoice for 10 units @ €100
- **THEN** the post MUST succeed.
- **WHEN** the operator posts an invoice for 12 units @ €100
- **THEN** the post MUST fail with a "3-way match quantity
  mismatch" error.

### Requirement: REQ-AP-007: `PaymentRun` SHALL declare a register that produces SEPA pain.001 XML + iDEAL payment links as declarative calculations

`PaymentRun` MUST be a register with the following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `runNumber` | string | Yes | Sequential identifier per administration |
| `runDate` | date | Yes | Scheduled execution date |
| `invoiceRefs` | array of string | Yes | List of `APInvoice` UUIDs to include |
| `totalAmount` | number | Yes (calculated) | Sum of selected invoices' `totalAmount` |
| `paymentMethod` | enum | Yes | One of `sepa-pain001`, `ideal` |
| `sepaXml` | string | Yes (calculated) | When `paymentMethod=sepa-pain001`, the composed pain.001 XML (calculation per ADR-031) |
| `idealLinks` | array of object | Yes (calculated) | When `paymentMethod=ideal`, per-invoice iDEAL payment links (calculation per ADR-031) |
| `state` | enum | Yes | One of `draft`, `ready`, `submitted`, `executed`, `failed` |
| `administrationId` | string | Yes | FK to administration |

The `sepaXml` and `idealLinks` fields MUST be
`x-openregister-calculations` outputs (per ADR-031 — pure data →
string/object transformations). NO `PaymentRunService.php`,
`SepaXmlBuilder.php`, or `IdealLinkBuilder.php` PHP classes — XML
composition is a calculation, not a service.

Live PSD2 bank submission (auto-debit, real-time bank confirmation)
is explicitly T4; T2 produces the XML / link artefacts and the
operator submits them via their bank portal (or pays via the iDEAL
URL).

#### Scenario: Reviewer confirms no PHP payment-run service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/Payment*.php`, `lib/Service/Sepa*.php`, `lib/Service/Ideal*.php`
- **THEN** no such files SHALL exist; XML/link generation is in
  the calculation extension.

#### Scenario: Composing a 3-invoice SEPA payment run

- **GIVEN** three approved + posted `APInvoice` records (€100,
  €200, €300, all in EUR, all with vendor IBAN populated)
- **WHEN** a `PaymentRun` selects them and `paymentMethod=sepa-pain001`
- **THEN** the `sepaXml` calculation MUST emit valid pain.001
  XML totalling €600 with the three vendor IBANs as
  `CdtrAcct/Id/IBAN`; **AND** schema-XSD validation against
  pain.001.001.03 MUST pass.

#### Scenario: iDEAL link generation produces one URL per invoice

- **GIVEN** three approved + posted EUR invoices and
  `paymentMethod=ideal`
- **WHEN** the `PaymentRun` is saved
- **THEN** `idealLinks` MUST contain three records, each with
  `{invoiceRef, url, amount, expiresAt}`.

### Requirement: REQ-AP-008: Payment matching SHALL transition `posted → paid` via bank-reconciliation events; no shillinq matcher service

When the `bookkeeping-bank-reconciliation` capability matches a
bank statement line to an `APInvoice` (per REQ-BR-006), the
matching engine MUST emit a CloudEvent (or OR-native equivalent)
that the `APInvoice` lifecycle consumes to transition `posted →
paid`. The matched amount MUST equal the invoice `totalAmount`;
partial payments are handled by the
`bookkeeping-bank-reconciliation` capability's suspense workflow
and do NOT transition the invoice to `paid`.

No PHP matcher service in shillinq; the match is OR's, the
transition is declarative.

#### Scenario: Exact-amount bank line marks invoice paid

- **GIVEN** a `posted` AP invoice for €500 and a bank statement
  line of €500 with a matching vendor reference
- **WHEN** the bank-reconciliation engine matches them per
  REQ-BR-006
- **THEN** the AP invoice state MUST transition to `paid`; **AND**
  the audit trail MUST record the match event with the bank
  statement reference.

#### Scenario: Partial payment leaves invoice posted

- **GIVEN** a `posted` AP invoice for €1 000 and a bank line of
  €600
- **WHEN** the bank-reconciliation engine matches them (partial)
- **THEN** the AP invoice MUST remain in state `posted`; **AND** a
  partial-payment audit event MUST be recorded; **AND** the
  remaining €400 MUST be tracked per the bank-reconciliation
  suspense workflow.

### Requirement: REQ-AP-009: AP aging SHALL be declared as an `x-openregister-aggregations` query, not a PHP report builder

AP aging MUST be expressed as an `x-openregister-aggregations`
query grouping `APInvoice` by `(vendorId, agingBucket)` where
`agingBucket` is one of `current`, `1-30 days`, `31-60 days`,
`61-90 days`, `>90 days` computed as
`(today - invoice.dueDate)`. The aggregation MUST exclude
invoices in state `paid` or `voided`.

NO `APAgingReportService.php`. The same ADR-031 anti-pattern
prohibition as REQ-TB-001.

#### Scenario: Reviewer confirms no aging service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*Aging*.php`
- **THEN** no such files SHALL exist.

#### Scenario: Aging buckets count correctly

- **GIVEN** four posted unpaid AP invoices for vendor `V-001`
  with `dueDate` 5 days ago, 20 days ago, 45 days ago, 100 days
  ago
- **WHEN** the aging aggregation is requested for that vendor
- **THEN** the result MUST report `current: 0`, `1-30 days: 2`
  (the 5- and 20-day invoices), `31-60 days: 1`, `61-90 days: 0`,
  `>90 days: 1` (cardinality counts; amount totals are produced
  symmetrically).

### Requirement: REQ-AP-010: Accounts Payable SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare:

- `Bookkeeping > Vendors` — `type: index` + `type: detail` on
  `VendorMaster`.
- `Bookkeeping > Accounts Payable` — `type: index` + `type: detail`
  on `APInvoice`; detail page MUST surface lifecycle action
  buttons + link to the materialised `GLTransaction`.
- `Bookkeeping > AP Aging` — `type: report` (or `type: index`
  fallback per REQ-TB-005's pattern) bound to the aging
  aggregation.
- `Bookkeeping > Payment Runs` — `type: index` + `type: detail`
  on `PaymentRun`; detail page MUST surface the `sepaXml` /
  `idealLinks` outputs as downloadable artefacts.

Rendering MUST use `@conduction/nextcloud-vue` generic components
per ADR-024 Tier-4 — no bespoke Vue files.

#### Scenario: Vendor index lists vendor masters

- **GIVEN** the manifest declares the Vendors pages
- **WHEN** an operator opens `/index.php/apps/shillinq/vendors`
- **THEN** `CnIndexPage` MUST render columns including
  `vendorNumber`, `name`, `iban`, `paymentTermDays`,
  `lifecycleState`.

#### Scenario: Payment run detail offers SEPA XML download

- **GIVEN** a `PaymentRun` with `paymentMethod=sepa-pain001` and
  `state=ready`
- **WHEN** an operator opens the detail page
- **THEN** a "Download SEPA pain.001" action MUST be visible
  surfacing the `sepaXml` calculation output as a `.xml` file.
