# Spec: bookkeeping-accounts-receivable-core

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md` (T1 COA),
`../add-shillinq-general-ledger/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`./bookkeeping-document-attachment-integration/spec.md` (docudesk FK contract),
`./bookkeeping-bank-reconciliation/spec.md` (payment matching)

## ADDED Requirements

### Requirement: REQ-AR-001 — Accounts receivable SHALL be declared as `CustomerMaster` + `ARInvoice` + `DunningRecord` registers, not duplicates of GL

Accounts receivable MUST be expressed as three new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `CustomerMaster` — customer/debtor party (legalName, address, KvK, BTW,
  IBAN, default receivable account, dunning policy reference, credit limit
  in integer cents, credit terms).
- `ARInvoice` — sub-ledger invoice (customer reference, invoice number, due
  date, total amount in integer cents, line breakdown, attachment FK to
  docudesk).
- `DunningRecord` — per-invoice dunning timeline record (reminder level +
  dispatched-at + acknowledged-at).

This capability establishes the foundational AR data model for customer
invoicing and credit-control. Posting an `ARInvoice` MUST materialise
exactly one balanced `GLTransaction` per the T1 REQ-JE-007 pattern.
`GLLine.subLedgerType: "ar"` + `subLedgerRef: <ARInvoice UUID>` resolves
to the materialised AR line (T1 REQ-GL-009 stub now backed by a real FK).

UBL 2.1 / Peppol BIS 3.0 outbound e-invoicing is **explicitly deferred to
T4**; this T2 capability ships internal AR posting + dunning + aging.
Manual customer invoice authoring (form + PDF render) is the T2 issuance
path.

#### Scenario: Reviewer confirms no parallel AR table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `ar_invoice`,
  `customer_master`, `dunning_record`, or `accounts_receivable_*`
- **THEN** no such classes SHALL exist.

#### Scenario: GLLine sub-ledger ref resolves to a real ARInvoice

- **GIVEN** T2 is live and `ARInvoice INV-C-2026-0001` is posted
- **WHEN** the materialised `GLLine` is inspected
- **THEN** the line MUST carry `subLedgerType: "ar"`,
  `subLedgerRef: "<UUID of INV-C-2026-0001>"`, **AND** the FK
  MUST resolve via OR's relation engine.

### Requirement: REQ-AR-002 — The `CustomerMaster` schema SHALL declare a fixed minimum field set

The `CustomerMaster` schema MUST declare the following fields with the
listed types and required flags. Schema validation MUST reject
CustomerMaster records missing any required field.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `customerNumber` | string | Yes | Stable identifier per administration |
| `legalName` | string | Yes | Legal name |
| `tradingName` | string | No | Alternate / DBA name |
| `kvkNumber` | string | No | Dutch KvK number (8 digits) |
| `taxId` | string | No | Dutch BTW / EU VAT number |
| `paymentTerms` | string | Yes (default "Net 30") | Default payment term (free-text or `Net <n>` form) |
| `defaultReceivableAccountNumber` | string | No | FK to `Account.accountNumber` for default receivable coding |
| `iban` | string | No | Customer bank account IBAN |
| `creditLimitCents` | integer ≥ 0 | No | Credit limit in integer cents (Money: integer-cent) |
| `dunningPolicyRef` | string | No | FK to OR's dunning-workflow policy record (per ADR-022 — if OR's dunning-workflow extension is stable); else null |
| `billingAddress` | string \| object | No | Street/number/postcode/city/country |
| `email` | string | No | Primary contact email |
| `contactPerson` | string | No | Primary contact name |
| `administrationId` | string | Yes | FK to administration |
| `lifecycleState` | enum | Yes | One of `active`, `suspended`, `inactive` |
| `contactRef` | string | No | If OR's `contact` abstraction is available per ADR-022, the FK to the shared contact record; else null |

Schema.org annotation: `schema:Organization` (or `schema:Person` for natural
persons).

#### Scenario: Schema validator accepts a minimal customer

- **GIVEN** the schema
- **WHEN** `{customerNumber:"CUST-001", legalName:"Gemeente Amsterdam", paymentTerms:"Net 30", administrationId:"adm-1", lifecycleState:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Customer IBAN is validated for Dutch format

- **GIVEN** a customer with `iban: "NL91ABNA0417164300"`
- **WHEN** the record is saved
- **THEN** validation MUST pass (valid Dutch IBAN format).

### Requirement: REQ-AR-003 — The `ARInvoice` schema SHALL declare a fixed minimum field set with line breakdown

The `ARInvoice` schema MUST declare the following fields with the
listed types and required flags. Schema validation MUST reject
ARInvoice records missing any required field or whose `lines`
array is empty.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `invoiceNumber` | string | Yes | Customer invoice number (unique per administration) |
| `customerId` | string | Yes | FK to `CustomerMaster` UUID |
| `invoiceDate` | date | Yes | Date of invoice issuance |
| `dueDate` | date | Yes | Auto-calculated from `invoiceDate + customer.paymentTerms`; overrideable |
| `currency` | string (ISO 4217) | Yes | T2: base currency only (EUR); T5 adds multi-currency |
| `totalAmountCents` | integer ≥ 0 | Yes | Total amount including tax, in integer cents |
| `taxAmountCents` | integer | No | Tax/VAT amount in integer cents |
| `lines` | array of object | Yes | `{description, accountNumber, amountCents, taxCode, quantity, unitPriceCents}` rows |
| `sourceDocumentUri` | string | No | docudesk FK URI per `bookkeeping-document-attachment-integration` (e.g. the rendered/uploaded PDF) |
| `status` | enum | Yes | One of `draft`, `issued`, `partially-paid`, `paid`, `overdue`, `disputed`, `written-off`, `voided` (per REQ-AR-004) |
| `writeOffReason` | string | No | Free-text reason captured on `overdue → written-off` transition (REQ-AR-004) |
| `glTransactionId` | string | No | Back-reference to materialised `GLTransaction` once posted |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Invoice`.

#### Scenario: Schema validator accepts a 1-line invoice

- **GIVEN** the schema
- **WHEN** a draft invoice with one line referencing an existing `Account` is
  saved
- **THEN** validation MUST pass; `dueDate` MUST be auto-set from customer
  payment terms.

#### Scenario: Invoice number is unique per administration

- **GIVEN** invoice `INV-C-2026-0001` exists in administration `adm-1`
- **WHEN** another invoice is authored with the same number in the same
  administration
- **THEN** the save MUST fail with a "duplicate invoice number" uniqueness
  error.

### Requirement: REQ-AR-004 — `ARInvoice` SHALL declare a declarative draft → issued → paid lifecycle with dunning-driven overdue branch

`ARInvoice` MUST declare an `x-openregister-lifecycle` block with:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `issued` | operator issuance/posting | balanced materialisation per T1 REQ-JE-007; `FiscalPeriod` open per REQ-PC-004; `CreditLimitGuard` (ADR-031-exception, see REQ-AR-006) |
| `issued` | `paid` | payment-match event from bank reconciliation per REQ-BR-006 | matched payment amount = `totalAmountCents` |
| `issued` | `partially-paid` | payment-match event from bank reconciliation per REQ-BR-006 | matched payment amount < `totalAmountCents` |
| `partially-paid` | `paid` | additional payment-match event | cumulative matched amount = `totalAmountCents` |
| `issued` | `overdue` | scheduled lifecycle action when `today > dueDate` and no payment match | none |
| `partially-paid` | `overdue` | same | none |
| `overdue` | `paid` | payment-match event | matched amount completes the total |
| `overdue` | `written-off` | operator action (role `controller`) | `writeOffReason` MUST be set — bad-debt write-off materialises a balanced compensating GL posting |
| `issued` | `disputed` | operator action | none — dunning pauses per REQ-AR-005 |
| `overdue` | `disputed` | operator action | none — dunning pauses |
| `disputed` | `issued` | resolution | none |
| `disputed` | `written-off` | resolution → uncollectible | `writeOffReason` MUST be set |
| `issued` | `voided` | operator action | materialised `GLTransaction` MUST already be reversed per T1 REQ-GL-004 |

The `issued → overdue` transition MUST fire via OR's `ScheduledWorkflow`
primitive (per ADR-031 §"Background jobs that walk an object queue" path 2 —
not a shillinq `*Job` PHP class).

#### Scenario: Issuing a balanced AR invoice materialises GL

- **GIVEN** a `draft` AR invoice with valid lines
- **WHEN** the operator issues it
- **THEN** a balanced `GLTransaction` MUST be materialised (debit AR
  receivable account, credit revenue accounts per the lines); **AND** the
  invoice state MUST become `issued`; **AND** `glTransactionId` MUST
  reference the new transaction.

#### Scenario: Overdue transition fires automatically

- **GIVEN** an `issued` AR invoice with `dueDate: 2026-04-30` and no payment
  match
- **WHEN** the OR scheduled-workflow ticks on `2026-05-01`
- **THEN** the invoice MUST transition to `overdue`; **AND** REQ-AR-005's
  dunning schedule MUST start.

#### Scenario: Write-off materialises a compensating GL posting

- **GIVEN** an `overdue` invoice for €1 000 declared uncollectible
- **WHEN** an actor in role `controller` transitions to `written-off` with
  `writeOffReason: "uncollectible debt — customer insolvent"`
- **THEN** a compensating `GLTransaction` MUST be materialised (credit AR
  receivable €1 000, debit bad-debt expense account €1 000); **AND** the
  invoice state MUST become `written-off`; **AND** the reason MUST be
  persisted on the invoice and exposed on the audit trail per REQ-AR-008.

#### Scenario: Disputing an overdue invoice pauses dunning

- **GIVEN** an `overdue` AR invoice with `DunningRecord` reminder-1 dispatched
- **WHEN** the operator transitions it to `disputed`
- **THEN** no further `DunningRecord` rows MUST be created by the scheduled
  dunning escalation while the invoice remains `disputed`.

### Requirement: REQ-AR-005 — AR dunning workflow SHALL consume OR's dunning-workflow abstraction; no app-local dunning service

`ARInvoice` MUST consume OR's dunning-workflow extension via
`x-openregister-lifecycle.requires` on transitions from `overdue`. Dunning
policy (reminder cadence, escalation thresholds, template selection,
debt-collection escalation) MUST be configured through OR's dunning-workflow
configuration — NOT through an app-local dunning service. Per ADR-022
anti-pattern list.

The `DunningRecord` register captures the timeline:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `invoiceRef` | string | Yes | FK to `ARInvoice` UUID |
| `reminderLevel` | enum | Yes | One of `reminder-1`, `reminder-2`, `formal-notice`, `collection` |
| `dispatchedAt` | datetime | Yes | When the reminder was dispatched |
| `dispatchedBy` | string | Yes | Actor (system or operator) |
| `templateRef` | string | No | FK to OR's notification template |
| `acknowledgedAt` | datetime | No | When the customer responded |
| `administrationId` | string | Yes | FK to administration |

If OR's dunning-workflow extension is NOT yet stable at T2 implementation time,
ADR-031's exception path applies: a single-method
`OCA\Shillinq\Lifecycle\ARGuard` MAY ship, documented as a temporary
fallback pending OR's extension stabilisation.

#### Scenario: Dunning schedule follows OR policy configuration

- **GIVEN** an `overdue` AR invoice and an OR dunning-workflow policy
  configured for reminder-1 at +14 days
- **WHEN** the OR scheduled-workflow ticks 14 days post-overdue
- **THEN** a `DunningRecord` MUST be created with `reminderLevel: reminder-1`,
  `dispatchedAt` set to today, and a notification MUST be dispatched per the
  policy template.

#### Scenario: Cancel dunning on paid

- **GIVEN** an `overdue` invoice with reminder-1 dispatched
- **WHEN** the invoice transitions to `paid` (REQ-AR-004)
- **THEN** no further `DunningRecord` rows MUST be created; **AND** any
  pending dunning escalation runs MUST be cancelled by the OR
  dunning-workflow engine.

### Requirement: REQ-AR-006 — Credit-limit check SHALL be enforced as an ADR-031-exception precondition on `draft → issued`

The `draft → issued` transition MUST consult the cross-object precondition
`sum(open ARInvoice.totalAmountCents for customer) + this.totalAmountCents
≤ customer.creditLimitCents` (when `creditLimitCents` is set).

This is the ADR-031 Risk-3 cross-object precondition the aggregation engine
cannot enforce at transition time. It MUST be implemented as a single PHP
seam `lib/Guard/CreditLimitGuard.php` and wired via
`x-openregister-lifecycle.transitions[draft→issued].guard`. The guard MUST
NOT be reused as a generic billing service — it carries one method,
`check(arInvoice): GuardResult`.

When `creditLimitCents` is null or zero, the guard MUST pass through
unconditionally.

#### Scenario: Issuance is blocked when credit limit is breached

- **GIVEN** customer `CUST-001` with `creditLimitCents: 100000` (€1 000) and
  one outstanding `issued` invoice for €600
- **WHEN** the operator attempts to issue a draft invoice for €500 against
  `CUST-001`
- **THEN** the transition MUST fail with a "credit limit exceeded" guard
  error; **AND** the invoice MUST remain in `draft`.

#### Scenario: Issuance proceeds when within credit limit

- **GIVEN** the same customer and outstanding invoice
- **WHEN** the operator issues a draft for €300
- **THEN** the transition MUST succeed; **AND** the GL posting MUST be
  materialised per REQ-AR-004.

### Requirement: REQ-AR-007 — Aged Receivables report SHALL show customer-level outstanding amounts grouped by aging bucket

The aged receivables report MUST be declared as an
`x-openregister-aggregations` query returning:

```
GROUP BY (customerId, agingBucket)
WHERE status IN ('issued', 'partially-paid', 'overdue', 'disputed')
ORDER BY agingBucket DESC, totalOutstanding DESC
RETURN {
  customerName, customerNumber,
  bucket_0_30 (sum of totalAmountCents - paidAmountCents where daysUntilDue >= -30),
  bucket_31_60 (where daysUntilDue between -60 and -31),
  bucket_61_90 (where daysUntilDue between -90 and -61),
  bucket_90_plus (where daysUntilDue < -90),
  totalOutstanding
}
```

Bucket thresholds (0–30 days, 31–60, 61–90, 90+) are administration-configurable
via `IAppConfig['ar.aging.buckets']` with defaults `[30, 60, 90]`.

The report MUST be downloadable as CSV via the standard manifest CSV export
hook (`x-openregister-aggregations.export.csv = true`). No PHP
`ARAgingReportService` is permitted per ADR-031.

#### Scenario: Aging report aggregates by customer and bucket

- **GIVEN** shillinq with open AR invoices: 3 from `Gemeente Amsterdam` (1
  current, 2 overdue by 15 days), 2 from `VNG Realisatie` (1 overdue by 45
  days, 1 by 120 days)
- **WHEN** the aged receivables report is generated
- **THEN** the report MUST show:
  - Gemeente Amsterdam: bucket_0_30 (current invoice + 2 ×15-day invoices),
    bucket_31_60 €0, bucket_61_90 €0, bucket_90_plus €0
  - VNG Realisatie: bucket_0_30 €0, bucket_31_60 €0, bucket_61_90 with the
    45-day invoice, bucket_90_plus with the 120-day invoice
  - Rows sorted by aging bucket descending then by total outstanding
    descending

#### Scenario: Aged receivables exports as CSV

- **GIVEN** the aged receivables report
- **WHEN** the operator clicks "Download CSV"
- **THEN** a CSV MUST be served with the customer rows and bucket columns
  named per the spec, amounts rendered as EUR with two decimal places.

### Requirement: REQ-AR-008 — AR invoice lifecycle transitions and write-off SHALL be audit-trailed

All AR invoice state transitions MUST be recorded by OR's
`AuditTrailService` (per ADR-022) capturing actor, timestamp, from-state,
to-state, and any guard payload (e.g. `writeOffReason` on
`overdue → written-off`).

No app-local audit table is permitted. The audit trail MUST be queryable
from the ARInvoice detail page per the manifest `auditPanel` widget.

#### Scenario: Write-off reason persisted on audit trail

- **GIVEN** an operator transitions an overdue invoice to `written-off`
  with `writeOffReason: "uncollectible debt — customer insolvent"`
- **WHEN** the audit trail is queried for the invoice
- **THEN** the audit row MUST carry the actor, the timestamp, the from/to
  states, and the `writeOffReason` payload verbatim.

### Requirement: REQ-AR-009 — Payment matching SHALL transition AR invoices from issued → paid via bank-reconciliation confirmation

Payment matching MUST transition AR invoices from `issued` to `paid` when an
operator confirms a `ReconciliationMatch` emitted by
`bookkeeping-bank-reconciliation`. When the cumulative confirmed amount is less
than `totalAmountCents`, the invoice SHALL transition to `partially-paid` instead;
additional matches MUST advance `partially-paid → paid` once the total is
reached.

#### Scenario: Operator confirms bank-match; AR invoice transitions to paid

- **GIVEN** an `issued` AR invoice for €1 000 and a bank-reconciliation match
  candidate showing €1 000 received
- **WHEN** the operator confirms the match on the AR detail page
- **THEN** the AR invoice MUST transition to `paid`; **AND** a GL posting MUST
  be created per bank-reconciliation's materialization pattern.

### Requirement: REQ-AR-010 — Manifest navigation SHALL include four entries for AR core workflow

The `src/manifest.json` navigation MUST declare four entries:

1. **Customers** (`type: index`) — list all `CustomerMaster` records with
   add/search/detail
2. **Accounts Receivable** (`type: index`) — list all `ARInvoice` records with
   add/search/detail
3. **AR Aging** (`type: aggregate`) — display the aged receivables report with
   download options
4. **Dunning Log** (`type: index`) — list all `DunningRecord` records with
   detail view

Each entry MUST have a corresponding detail page for viewing/editing (list pages
auto-generated by `CnIndexPage`; detail pages authored per the manifest pattern).

#### Scenario: Customers and AR lists are accessible from main navigation

- **GIVEN** the app is installed with this spec
- **WHEN** the user opens the app
- **THEN** the left sidebar MUST show "Customers", "Accounts Receivable",
  "AR Aging", "Dunning Log" as clickable navigation entries.

### Requirement: REQ-AR-011 — Seed data SHALL include 3 customers and a multi-state AR invoice cohort with realistic Dutch details

Seed data MUST be authored in `lib/Settings/seeds/ar-demo.json` and loaded
idempotently via `SettingsService::seedArDemo()` plus the existing repair
step per the proposal `Implementation note`. The seed file MUST include
mock `CustomerMaster` and `ARInvoice` records for dev/test/demo:

**Customers (3):**
- Dutch government entity (e.g. Gemeente Amsterdam)
- Dutch market intermediary (e.g. VNG Realisatie)
- Private-sector counterparty

**Invoices spanning lifecycle states:**
- 1+ draft invoice
- 1+ issued (within payment term)
- 1+ overdue with at least one `DunningRecord` reminder dispatched
- 1+ paid (matched against test bank transactions when available)
- 1+ disputed

All seed objects MUST use:
- Dutch legal names, valid postcodes (`[1-9][0-9]{3}[A-Z]{2}`)
- Realistic Dutch KvK codes and BTW numbers
- Amounts in integer cents (Money convention)
- `@self` envelope: `{ "@self": { "register": ..., "schema": ..., "slug": ... }, ...properties }`

Seed data MUST be idempotent — re-running `SettingsService::seedArDemo()` MUST
skip existing objects matched by slug. Seeding MUST be gated by the
`ar_demo_seed` admin setting and MUST NOT seed under a contaminating default
administrationId.

#### Scenario: Seed data loads on app install

- **GIVEN** a fresh Nextcloud instance with shillinq installed and
  `ar_demo_seed` enabled
- **WHEN** the repair step runs `SettingsService::seedArDemo()`
- **THEN** 3 customers and the AR invoice cohort MUST be created with
  realistic Dutch details; **AND** re-running the same repair step MUST NOT
  create duplicates.
