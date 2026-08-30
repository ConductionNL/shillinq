# Spec: bookkeeping-accounts-payable-core

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md` (T1 COA),
`../add-shillinq-general-ledger/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`./bookkeeping-document-attachment-integration/spec.md` (docudesk FK contract),
`./bookkeeping-bank-reconciliation/spec.md` (payment matching)

## Purpose

This specification defines the requirements for bookkeeping accounts payable core in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.
## Requirements

@e2e exclude pure backend/schema: AP register declarations, schema constraints, SEPA XML generation — not browser-testable

### REQ-AP-001: Accounts payable SHALL be declared as `Payee` + `APTransaction` + `DunningNotice` registers, not duplicates of GL

Accounts payable MUST be expressed as three new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `Payee` — vendor/supplier party (name, address, KvK, BTW, bank account
  IBAN, default expense account, dunning policy reference, credit terms).
- `APTransaction` — sub-ledger invoice (payee reference, invoice number, due
  date, amount, line breakdown, attachment FK to docudesk).
- `DunningNotice` — per-invoice dunning timeline record (reminder level +
  dispatched-at + acknowledged-at).

This capability establishes the foundational AP data model for vendor payment
tracking and cash-flow management. Posting an `APTransaction` MUST materialise
exactly one balanced `GLTransaction` per the T1 REQ-JE-007 pattern.
`GLLine.subLedgerType: "ap"` + `subLedgerRef: <APTransaction UUID>` resolves
to the materialised AP line (T1 REQ-GL-009 stub now backed by a real FK).

Peppol BIS 3.0 inbound e-invoicing is **explicitly deferred to T4**; this T2
capability ships internal AP posting + dunning + aging. Manual vendor invoice
upload (PDF/attachment) is the T2 intake path.

#### Scenario: Reviewer confirms no parallel AP table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `ap_transaction`,
  `payee`, `dunning_*`, or `accounts_payable_*`
- **THEN** no such classes SHALL exist.

#### Scenario: GLLine sub-ledger ref resolves to a real APTransaction

- **GIVEN** T2 is live and `APTransaction INV-V-2026-0001` is posted
- **WHEN** the materialised `GLLine` is inspected
- **THEN** the line MUST carry `subLedgerType: "ap"`,
  `subLedgerRef: "<UUID of INV-V-2026-0001>"`, **AND** the FK
  MUST resolve via OR's relation engine.

### REQ-AP-002: The `Payee` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `Payee` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `vendorNumber` | string | Yes | Stable identifier per administration |
| `name` | string | Yes | Legal name |
| `tradingName` | string | No | Alternate / DBA name |
| `kvkNumber` | string | No | Dutch KvK number (8 digits) |
| `btwNumber` | string | No | Dutch BTW / EU VAT number |
| `paymentTermDays` | integer | Yes (default 30) | Default payment term in days |
| `defaultExpenseAccountNumber` | string | No | FK to `Account.accountNumber` for default expense coding |
| `bankAccount` | string | No | Payee bank account IBAN for payments |
| `creditTerms` | string | No | Free-text payment terms or reference to OR terms record |
| `dunningPolicyRef` | string | No | FK to OR's dunning-workflow policy record (per ADR-022 — if OR's dunning-workflow extension is stable); else null |
| `address` | object | No | Street/number/postcode/city/country |
| `email` | string | No | Primary contact email |
| `phone` | string | No | Primary contact phone |
| `administrationId` | string | Yes | FK to administration |
| `lifecycleState` | enum | Yes | One of `active`, `blocked`, `archived` |
| `contactRef` | string | No | If OR's `contact` abstraction is available per ADR-022, the FK to the shared contact record; else null |

Schema.org annotation: `schema:Organization`.

#### Scenario: Schema validator accepts a minimal vendor

- **GIVEN** the schema
- **WHEN** `{vendorNumber:"V-001", name:"Leverancier BV", paymentTermDays:30, administrationId:"adm-1", lifecycleState:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Vendor IBAN is validated for Dutch format

- **GIVEN** a vendor with `bankAccount: "NL91ABNA0417164300"`
- **WHEN** the record is saved
- **THEN** validation MUST pass (valid Dutch IBAN format).

### REQ-AP-003: The `APTransaction` schema SHALL declare a fixed minimum field set with line breakdown

The system SHALL satisfy this requirement: The `APTransaction` schema SHALL declare a fixed minimum field set with line breakdown.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `invoiceNumber` | string | Yes | Vendor invoice number (unique per administration + payee) |
| `vendorId` | string | Yes | FK to `Payee` UUID |
| `invoiceDate` | date | Yes | Date of invoice issuance |
| `dueDate` | date | Yes | Auto-calculated from `invoiceDate + payee.paymentTermDays`; overrideable |
| `currency` | string (ISO 4217) | Yes | T2: base currency only (EUR); T5 adds multi-currency |
| `totalAmount` | number ≥ 0 | Yes | Total amount including tax |
| `taxAmount` | number | No | Tax/VAT amount |
| `lines` | array of object | Yes | `{description, accountNumber, amount, taxCode, quantity, unitPrice}` rows |
| `sourceDocumentUri` | string | No | docudesk FK URI per `bookkeeping-document-attachment-integration` (e.g. the uploaded PDF) |
| `state` | enum | Yes | One of `draft`, `received`, `issued`, `partially-paid`, `paid`, `overdue`, `disputed`, `written-off`, `voided` (per REQ-AP-004) |
| `glTransactionId` | string | No | Back-reference to materialised `GLTransaction` once posted |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Invoice`.

#### Scenario: Schema validator accepts a 1-line invoice

- **GIVEN** the schema
- **WHEN** a received invoice with one line referencing an existing `Account` is
  saved
- **THEN** validation MUST pass; `dueDate` MUST be auto-set from payee payment
  terms.

#### Scenario: Invoice number is unique per administration + payee

- **GIVEN** a received invoice `INV-V-2026-0001` from vendor `V-001` exists in
  administration `adm-1`
- **WHEN** another invoice from the same vendor is received with the same number
  in the same administration
- **THEN** the save MUST fail with a "duplicate invoice number" uniqueness error.

### REQ-AP-004: `APTransaction` SHALL declare a declarative draft → issued → paid lifecycle with dunning-driven overdue branch

`APTransaction` MUST declare an `x-openregister-lifecycle` block with:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `received` | operator receipt | vendor invoice number unique per administration + payee |
| `received` | `issued` | operator approval/posting | balanced materialisation per T1 REQ-JE-007; `FiscalPeriod` open per REQ-PC-004 |
| `issued` | `paid` | payment-match event from bank reconciliation per REQ-BR-006 | matched payment amount = `totalAmount` |
| `issued` | `partially-paid` | payment-match event from bank reconciliation per REQ-BR-006 | matched payment amount < `totalAmount` |
| `partially-paid` | `paid` | additional payment-match event | cumulative matched amount = `totalAmount` |
| `issued` | `overdue` | scheduled lifecycle action when `today > dueDate` and no payment match | none |
| `partially-paid` | `overdue` | same | none |
| `overdue` | `paid` | payment-match event | matched amount completes the total |
| `overdue` | `written-off` | operator action (role `controller`) | none — vendor write-off journal materialises a balanced compensating GL posting |
| `issued` | `disputed` | operator action | none — payment-match pursuit pauses |
| `disputed` | `issued` | resolution | none |
| `issued` | `voided` | operator action | materialised `GLTransaction` MUST already be reversed per T1 REQ-GL-004 |

The `issued → overdue` transition MUST fire via OR's `ScheduledWorkflow`
primitive (per ADR-031 §"Background jobs that walk an object queue" path 2 —
not a shillinq `*Job` PHP class).

#### Scenario: Issuing a balanced AP invoice materialises GL

- **GIVEN** a `received` AP invoice with valid lines
- **WHEN** the operator issues it
- **THEN** a balanced `GLTransaction` MUST be materialised (credit AP payable
  account, debit expense accounts per the lines); **AND** the invoice state
  MUST become `issued`; **AND** `glTransactionId` MUST reference the new
  transaction.

#### Scenario: Overdue transition fires automatically

- **GIVEN** an `issued` AP invoice with `dueDate: 2026-04-30` and no payment
  match
- **WHEN** the OR scheduled-workflow ticks on `2026-05-01`
- **THEN** the invoice MUST transition to `overdue`; **AND** REQ-AP-005's
  dunning schedule MUST start.

#### Scenario: Write-off materialises a compensating GL posting

- **GIVEN** an `overdue` invoice for €1 000 declared uncollectible/abated
- **WHEN** an actor in role `controller` transitions to `written-off`
- **THEN** a compensating `GLTransaction` MUST be materialised (debit AP
  payable €1 000, credit bad-debt recovery/write-off account €1 000); **AND**
  the invoice state MUST become `written-off`.

### REQ-AP-005: AP dunning workflow SHALL consume OR's dunning-workflow abstraction; no app-local dunning service

`APTransaction` MUST consume OR's dunning-workflow extension via
`x-openregister-lifecycle.requires` on transitions from `overdue`. Dunning
policy (reminder cadence, escalation thresholds, template selection,
debt-collection escalation) MUST be configured through OR's dunning-workflow
configuration — NOT through an app-local dunning service. Per ADR-022
anti-pattern list.

The `DunningNotice` register captures the timeline:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `invoiceRef` | string | Yes | FK to `APTransaction` UUID |
| `reminderLevel` | enum | Yes | One of `reminder-1`, `reminder-2`, `formal-notice`, `collection` |
| `dispatchedAt` | datetime | Yes | When the reminder was dispatched |
| `dispatchedBy` | string | Yes | Actor (system or operator) |
| `templateRef` | string | No | FK to OR's notification template |
| `acknowledgedAt` | datetime | No | When the vendor responded |
| `administrationId` | string | Yes | FK to administration |

If OR's dunning-workflow extension is NOT yet stable at T2 implementation time,
ADR-031's exception path applies: a single-method `OCA\Shillinq\Lifecycle\APGuard`
MAY ship, documented as a temporary fallback pending OR's extension stabilisation.

#### Scenario: Dunning schedule follows OR policy configuration

- **GIVEN** an `overdue` AP invoice and an OR dunning-workflow policy configured
  for reminder-1 at +14 days
- **WHEN** the OR scheduled-workflow ticks 14 days post-overdue
- **THEN** a `DunningNotice` record MUST be created with `reminderLevel: reminder-1`,
  `dispatchedAt` set to today, and a notification MUST be dispatched per the
  policy template.

### REQ-AP-006: Aged Payables Detail report SHALL show per-invoice breakdown grouped by vendor and due-date bucket

The aged payables detail report MUST be declared as an
`x-openregister-aggregations` query returning:

```
GROUP BY (vendorId, dueDateBucket)
WHERE state IN ('issued', 'partially-paid', 'overdue', 'disputed')
ORDER BY vendorName ASC, dueDate ASC
RETURN {
  vendorName, vendorNumber,
  invoiceNumber, invoiceDate, dueDate,
  amount, paidAmount,
  dueDateBucket (current, 30days, 60days, 90days),
  daysOverdue
}
```

Bucket thresholds (0–30 days, 30–60, 60–90, 90+) are administration-configurable
via `IAppConfig['ap.aging.buckets']` with defaults `[30, 60, 90]`.

#### Scenario: Detail report shows all open invoices grouped by vendor and bucket

- **GIVEN** shillinq with 5 open AP invoices: 3 from `Vendor-A` (1 current, 2
  overdue by 15 days), 2 from `Vendor-B` (1 overdue by 45 days, 1 by 120 days)
- **WHEN** the aged payables detail report is generated
- **THEN** the report MUST show:
  - Vendor-A: 1 row in "current" bucket, 2 rows in "30–60 days" bucket
  - Vendor-B: 1 row in "60–90 days" bucket, 1 row in "90+ days" bucket
  - Total count: 5 rows, ordered by vendor then by due date

### REQ-AP-007: Aged Payables Summary report SHALL show vendor totals grouped by aging bucket

The aged payables summary report MUST be declared as an
`x-openregister-aggregations` query returning:

```
GROUP BY (vendorId, agingBucket)
WHERE state IN ('issued', 'partially-paid', 'overdue', 'disputed')
ORDER BY agingBucket DESC, totalAmount DESC
RETURN {
  vendorName, vendorNumber,
  agingBucket,
  count (number of invoices in bucket),
  totalAmount,
  percentageOfTotal
}
```

#### Scenario: Summary report aggregates by vendor and aging severity

- **GIVEN** the same 5 invoices as REQ-AP-006
- **WHEN** the aged payables summary report is generated
- **THEN** the report MUST show:
  - Row 1: "90+ days", 2 vendors (Vendor-A: €2000, Vendor-B: €3000 total)
  - Row 2: "60–90 days", Vendor-B: €2500
  - Row 3: "30–60 days", Vendor-A: €1000
  - Row 4: "Current", Vendor-A: €500
  - Percentages calculated relative to total open payables

### REQ-AP-008: Aged Payables Timeline report SHALL show payment due dates with amounts and vendor summary

The aged payables timeline report MUST be declared as an
`x-openregister-aggregations` query returning:

```
GROUP BY dueDate
WHERE state IN ('issued', 'partially-paid', 'overdue', 'disputed')
ORDER BY dueDate ASC
RETURN {
  dueDate,
  daysUntilDue (negative = overdue),
  count (number of invoices due on this date),
  totalAmount,
  vendors (list of vendor names and their amounts)
}
```

#### Scenario: Timeline report shows upcoming payment schedule

- **GIVEN** AP invoices with due dates: 2026-05-25 (€500, Vendor-A), 2026-05-30
  (€1500, Vendor-B), 2026-06-05 (€1000, Vendor-A), 2026-04-20 (€2000 overdue,
  Vendor-B)
- **WHEN** the aged payables timeline report is generated (as of 2026-05-21)
- **THEN** the report MUST show (in order):
  - Row 1: 2026-04-20, -31 days, €2000, Vendor-B (overdue)
  - Row 2: 2026-05-25, +4 days, €500, Vendor-A (upcoming)
  - Row 3: 2026-05-30, +9 days, €1500, Vendor-B (upcoming)
  - Row 4: 2026-06-05, +15 days, €1000, Vendor-A (upcoming)

### REQ-AP-009: Payment matching SHALL transition AP invoices from issued → paid via bank-reconciliation confirmation

The system SHALL satisfy this requirement: Payment matching SHALL transition AP invoices from issued → paid via bank-reconciliation confirmation.

When `bookkeeping-bank-reconciliation` emits a `ReconciliationMatch` candidate
matching an `APTransaction`, the operator MUST be able to confirm the match via
the AP detail page. Confirmation triggers an `issued → paid` lifecycle
transition (or `partially-paid → paid` if this completes a multi-part payment).

#### Scenario: Operator confirms bank-match; AP invoice transitions to paid

- **GIVEN** an `issued` AP invoice for €1000 and a bank-reconciliation match
  candidate showing €1000 received
- **WHEN** the operator confirms the match on the AP detail page
- **THEN** the AP invoice MUST transition to `paid`; **AND** a GL posting MUST
  be created per bank-reconciliation's materialization pattern.

### REQ-AP-010: Manifest navigation SHALL include four entries for AP core workflow

The `src/manifest.json` navigation MUST declare four entries:

1. **Vendors** (`type: index`) — list all `Payee` records with add/search/detail
2. **Accounts Payable** (`type: index`) — list all `APTransaction` records with
   add/search/detail
3. **AP Aging** (`type: aggregate`) — display the three aged payables reports
   (detail / summary / timeline) with download options
4. **Dunning** (`type: index`) — list all `DunningNotice` records with detail
   view

Each entry MUST have a corresponding detail page for viewing/editing (list pages
auto-generated by `CnIndexPage`; detail pages authored per the manifest pattern).

#### Scenario: Vendors and AP lists are accessible from main navigation

- **GIVEN** the app is installed with this spec
- **WHEN** the user opens the app
- **THEN** the left sidebar MUST show "Vendors", "Accounts Payable", "AP Aging",
  "Dunning" as clickable navigation entries.

### REQ-AP-011: Seed data SHALL include 3 vendors and 5–8 AP invoices with realistic Dutch details

The `lib/Settings/shillinq_register.json` `components.objects[]` MUST include
mock `Payee` and `APTransaction` records for dev/test/demo:

**Vendors (3):**
- Utilities provider (elektriciteit/gas)
- Office supplies distributor
- Professional services firm

**Invoices (5–8) spanning all lifecycle states:**
- 2 current (due within 7 days)
- 2 overdue by 15–30 days
- 1 overdue by 45+ days (dunning reminder staged)
- 1–2 paid (matched against test bank transactions)
- 1 disputed (marked as disputed, payment on hold)

All seed objects MUST use:
- Dutch street names and valid postcodes (`[1-9][0-9]{3}[A-Z]{2}`)
- Realistic Dutch KvK codes and BTW numbers
- Amounts €500–€5000 with 19% VAT where applicable
- `@self` envelope: `{ "@self": { "register": ..., "schema": ..., "slug": ... }, ...properties }`

Seed data is idempotent — re-importing via `ConfigurationService::importFromApp()`
with `force: false` MUST skip existing objects matched by slug.

#### Scenario: Seed data loads on app install

- **GIVEN** a fresh Nextcloud instance with shillinq installed
- **WHEN** the repair step runs `ConfigurationService::importFromApp('shillinq', ...)`
- **THEN** 3 vendors and 5–8 AP invoices MUST be created with realistic Dutch
  details; **AND** re-running the same repair step MUST NOT create duplicates.

### Requirement: REQ-AP-012: Payee SHALL be the canonical payee master with `payeeType`, a `bankAccount` object, and folded financial fields

The `Payee` schema SHALL be the single canonical master for any party the
administration pays. It SHALL carry a `payeeType` enum
`[supplier, vendor, freelancer, contractor, government, other]`, and the
financial fields folded in from the retired `VendorFinancialProfile` as
top-level fields: `creditLimit` (number) and `apBalance` (number). Its
`bankAccount` field SHALL be a structured object
`{ iban (string), bic (string), accountHolderName (string) }` into which the
retired profile's `iban`/`bic` fold — these SHALL NOT be added as separate
top-level `Payee` fields. The `bic` field is the bank's ISO 9362 identifier;
because a "SWIFT code" IS a BIC, no separate `swift` field SHALL be added.
Schema.org annotation: `schema:Organization`.

True non-SEPA / cross-border international payments (non-IBAN account numbers,
intermediary/correspondent banks) are out of scope — a future capability; the
`bankAccount` object models SEPA IBAN/BIC only.

#### Scenario: Payee validates a freelancer

- **GIVEN** the `Payee` schema
- **WHEN** `{vendorNumber:"V-010", name:"Jan de Vries", payeeType:"freelancer", paymentTermDays:14, administrationId:"adm-1", lifecycleState:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: bankAccount object and folded financial fields validate

- **GIVEN** a `Payee` with `bankAccount:{iban:"NL00BANK0123456789", bic:"<BANKNL2A>", accountHolderName:"Jan de Vries"}`, top-level `creditLimit:5000` and `apBalance:1250`
- **WHEN** the record is saved
- **THEN** validation MUST pass and the `bankAccount` object plus both top-level financial fields persist
- **AND** no separate top-level `iban`, `bic`, or `swift` field is required or present.

### Requirement: REQ-AP-013: A `PaymentRun` schema SHALL declare a batch of approved Payee payments with a declarative lifecycle

The system SHALL define a `PaymentRun` schema under the core AP fragment. It
SHALL declare its state machine as `x-openregister-lifecycle` with states
`draft → approved → exported → reconciled` (no PHP state-machine service per
ADR-031). It SHALL carry `runNumber`, `administrationId`, `executionDate`,
`debtorAccountIban`, `status`, `totalAmount`, `currency`, `paymentLines[]`,
`exportedFileRef`, `exportedAt`, `reconciledAt`, and `lifecycleState`. Each
`paymentLines[]` entry SHALL carry `payeeId`, `payeeName`, `creditorIban`,
`amount`, `remittanceInfo`, and `apTransactionRef`. Schema.org annotation:
`schema:PaymentService`.

#### Scenario: PaymentRun lifecycle enforces approval before export

- **GIVEN** a `PaymentRun` in state `draft`
- **WHEN** a `draft → exported` transition is attempted
- **THEN** the lifecycle SHALL reject it; the run MUST first be `approved`.

#### Scenario: PaymentRun lines reference payees and AP invoices

- **GIVEN** a `PaymentRun` with two `paymentLines[]`
- **WHEN** the object is saved
- **THEN** each line carries `payeeId`, `creditorIban`, `amount`, and `apTransactionRef`
- **AND** `totalAmount` equals the sum of the line `amount`s.

### Requirement: REQ-AP-014: The AP menu SHALL collapse to a single group with clean leaf labels

The Accounts-Payable navigation SHALL present exactly one group, with the
"(T2)" suffix removed, and leaf labels: Payees, AP Invoices, AP Aging,
Dunning Notices, Payment Runs. The legacy base `src/manifest.json` AP leaves
(`Vendors`, `AccountsPayable`, `APAging`, `PaymentRuns`) SHALL be removed, AND
the now-orphaned legacy base-AP **page definitions** of the same names SHALL
also be deleted from `src/manifest.json` in this change (the base
`AccountsPayable`/`APAging` page defs used the legacy `APTransaction` schema);
the consolidated module's own pages replace them. This uses the
`IInitialState`-provided manifest consumed by `CnAppRoot` per ADR-024; no
per-leaf route registration is added.

#### Scenario: Single AP group with clean labels

- **WHEN** an operator opens the Bookkeeping menu
- **THEN** exactly one "Accounts Payable" group is shown (no "(T2)" suffix)
- **AND** its leaves are Payees, AP Invoices, AP Aging, Dunning Notices, Payment Runs
- **AND** no legacy `Vendors` / `AccountsPayable` / `APAging` / `PaymentRuns` base leaves appear.

#### Scenario: Orphaned legacy AP page definitions removed

- **WHEN** `src/manifest.json` is inspected after this change
- **THEN** the legacy `Vendors`, `AccountsPayable`, `APAging`, and `PaymentRuns` page definitions are absent
- **AND** no orphaned legacy base-AP page definition remains for a follow-up cleanup.

### Requirement: REQ-AP-012 — `APTransaction` SHALL declare web-push notification rules using the title-vs-body content model

The `APTransaction` schema SHALL declare an `x-openregister-notifications` block (ADR-031
dialect) with two rules — `approvalNeeded` and `overdue` — each delivered on the
`nc-notification` AND `web-push` channels, each carrying `originApp: shillinq`, each declaring
a bilingual `subject` (resolved as the notification TITLE) and a bilingual `message` (resolved
as the notification BODY, distinct from the title), and each declaring exactly one primary
`actions[]` entry whose `target` is `{ kind: object-detail }` so the action deeplinks to the
triggering AP invoice.

shillinq SHALL register a deeplink for the `APTransaction` schema with OpenRegister's
deeplink registry (`DeepLinkRegistrationListener`) targeting the existing `APInvoiceDetail`
manifest route (`/bookkeeping/accounts-payable/:id`), using the admin-configured register slug
(L3) rather than a hardcoded register, so the `object-detail` action target resolves to a real
shillinq URL.

The `approvalNeeded` rule SHALL fire when an AP invoice enters the approval queue. Because
`APTransaction` declares no approver, assignee, or `approvalState` field, the rule keys on the
lifecycle `state` field (`updated` trigger, condition `state == received`, the documented
"awaiting approval/posting" state) and recipients fall back to the `shillinq-finance` group —
mirroring the AR/PO notification rules — rather than a per-invoice approver. The `overdue` rule
SHALL be a daily `scheduled` rule (`intervalSec 86400`) filtering `state notIn [paid,
written-off, voided]` AND `dueDate before now`, mirroring the AR overdue filter shape.

No existing notification rule SHALL be clobbered (the schema declared none before this change).

#### Scenario: AP invoice enters the approval queue raises an approvalNeeded push

- **WHEN** an `APTransaction` is updated so its `state` becomes `received`
- **THEN** the engine dispatches a notification on `nc-notification` and `web-push` with
  `originApp: shillinq`, TITLE "Approval needed: {{invoiceNumber}}" / "Goedkeuring nodig:
  {{invoiceNumber}}" and BODY "This supplier invoice is in Shillinq. Open it?" / "Deze
  inkoopfactuur staat in Shillinq. Openen?", to the `shillinq-finance` group

#### Scenario: Title and body are distinct (title-vs-body content model)

- **WHEN** the `approvalNeeded` or `overdue` rule fires
- **THEN** the notification title is the resolved `subject` and the notification body is the
  resolved `message`, and the two are not forced to be identical

#### Scenario: Open invoice action deeplinks to the AP invoice detail

- **WHEN** a recipient clicks the primary "Open invoice" / "Factuur openen" action on either
  rule's notification
- **THEN** the engine resolves the `object-detail` target to the triggering `APTransaction`
  via shillinq's registered deeplink and opens `/apps/shillinq/bookkeeping/accounts-payable/{uuid}`

#### Scenario: Background web-push delivery when all tabs are closed

- **WHEN** the `approvalNeeded` or `overdue` rule fires for a finance-group recipient who has
  an active push subscription but no open Nextcloud tab
- **THEN** the encrypted web-push is delivered and the Service Worker shows the rich
  notification in the background, and a click opens the resolved AP invoice deeplink

#### Scenario: Overdue sweep notifies on unpaid past-due invoices

- **WHEN** the daily `overdue` scheduled rule (`intervalSec 86400`) runs and an `APTransaction`
  has `dueDate` before now and `state` not in `[paid, written-off, voided]`
- **THEN** the engine dispatches a notification to the `shillinq-finance` group with TITLE
  "Invoice overdue: {{invoiceNumber}}" / "Factuur over de vervaldatum: {{invoiceNumber}}" and
  BODY "This invoice is overdue in Shillinq. Open it?" / "Deze factuur is vervallen in
  Shillinq. Openen?"

