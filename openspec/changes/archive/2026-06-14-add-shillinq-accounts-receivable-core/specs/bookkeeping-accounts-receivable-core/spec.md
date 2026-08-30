# Spec: bookkeeping-accounts-receivable-core

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`./bookkeeping-document-attachment-integration/spec.md` (docudesk FK contract),
`./bookkeeping-bank-reconciliation/spec.md` (payment matching)

## ADDED Requirements

### Requirement: REQ-AR-001: Accounts receivable SHALL be declared as `CustomerMaster` + `ARInvoice` + `DunningRecord` registers, not duplicates of GL

Accounts receivable MUST be expressed as three new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `CustomerMaster` — customer party (name, address, KvK, BTW,
  default revenue account, dunning policy reference, credit
  limit).
- `ARInvoice` — sub-ledger invoice (customer reference, due
  date, amount, line breakdown, attachment FK to docudesk).
- `DunningRecord` — per-invoice dunning timeline record
  (reminder level + dispatched-at + acknowledged-at).

This capability **carries forward the original Shillinq invoicing
scope** — customer invoicing was Shillinq's pre-bookkeeping
mission, and this spec formalises it as the AR half of the
bookkeeping engine. Posting an `ARInvoice` MUST materialise
exactly one balanced `GLTransaction` per the T1 REQ-JE-007
pattern. `GLLine.subLedgerType: "ar"` + `subLedgerRef:
<ARInvoice UUID>` resolves to the materialised AR line (T1
REQ-GL-009 stub now backed by a real FK).

UBL 2.1 / Peppol BIS 3.0 outbound e-invoicing is **explicitly
deferred to T4**; this T2 capability ships internal AR posting +
dunning + aging. The `ARInvoice` schema declares fields
(`peppolEndpoint`, `ublXml`) that T4's e-invoicing capability
will populate via additional calculations.

#### Scenario: Reviewer confirms no parallel AR table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `ar_invoice`, `customer_master`, `dunning_*`, or
  `accounts_receivable_*`
- **THEN** no such classes SHALL exist.

#### Scenario: GLLine sub-ledger ref resolves to a real ARInvoice

- **GIVEN** T2 is live and `ARInvoice INV-C-2026-0001` is posted
- **WHEN** the materialised `GLLine` is inspected
- **THEN** the line MUST carry `subLedgerType: "ar"`,
  `subLedgerRef: "<UUID of INV-C-2026-0001>"`, **AND** the FK
  MUST resolve via OR's relation engine.

### Requirement: REQ-AR-002: The `CustomerMaster` schema SHALL declare a fixed minimum field set

| Field | Type | Required | Purpose |
|---|---|---|---|
| `customerNumber` | string | Yes | Stable identifier per administration |
| `name` | string | Yes | Legal name |
| `tradingName` | string | No | Alternate / DBA name |
| `kvkNumber` | string | No | Dutch KvK number (8 digits) |
| `btwNumber` | string | No | Dutch BTW / EU VAT number |
| `paymentTermDays` | integer | Yes (default 30) | Default payment term in days |
| `defaultRevenueAccountNumber` | string | No | FK to `Account.accountNumber` for default revenue coding |
| `creditLimit` | number ≥ 0 | No | Credit limit; if set, REQ-AR-006 evaluates against open AR balance |
| `dunningPolicyRef` | string | No | FK to OR's dunning-workflow policy record (per ADR-022 — if OR's dunning-workflow extension is stable); else null. Resolution lives in `opsx-ff` discovery |
| `peppolEndpoint` | string | No | Peppol BIS endpoint identifier (used by T4 e-invoicing) |
| `address` | object | No | Street/number/postcode/city/country |
| `email` | string | No | Primary billing email |
| `phone` | string | No | Primary contact phone |
| `administrationId` | string | Yes | FK to administration |
| `lifecycleState` | enum | Yes | One of `active`, `blocked`, `archived` |
| `contactRef` | string | No | If OR's `contact` abstraction is available per ADR-022, the FK to the shared contact record; else null. Same resolution as AP REQ-AP-002 |

Schema.org annotation: `schema:Organization` or `schema:Person`
(per shillinq config.yaml `rules.specs`).

#### Scenario: Schema validator accepts a minimal customer

- **GIVEN** the schema
- **WHEN** `{customerNumber:"C-001", name:"Klant BV", paymentTermDays:30, administrationId:"adm-1", lifecycleState:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Credit limit field declared but evaluated by REQ-AR-006

- **GIVEN** a customer with `creditLimit: 5000`
- **WHEN** an AR invoice is drafted bringing the customer's open
  AR balance to €6 000
- **THEN** the draft MUST succeed but a warning MUST be surfaced
  per REQ-AR-006's policy check.

### Requirement: REQ-AR-003: The `ARInvoice` schema SHALL declare a fixed minimum field set with line breakdown

| Field | Type | Required | Purpose |
|---|---|---|---|
| `invoiceNumber` | string | Yes | Shillinq-side invoice number (auto-generated per administration) |
| `customerId` | string | Yes | FK to `CustomerMaster` UUID |
| `invoiceDate` | date | Yes | Date of invoice issuance |
| `dueDate` | date | Yes | Auto-calculated from `invoiceDate + customer.paymentTermDays`; overrideable |
| `currency` | string (ISO 4217) | Yes | T2: base currency only; T5 adds multi-currency |
| `totalAmount` | number ≥ 0 | Yes | Total amount including tax |
| `taxAmount` | number | No | Tax/VAT amount |
| `lines` | array of object | Yes | `{description, accountNumber, amount, taxCode, quantity, unitPrice}` rows |
| `sourceDocumentUri` | string | No | docudesk FK URI per `bookkeeping-document-attachment-integration` (e.g. the issued PDF) |
| `ublXml` | string | No (calculated by T4) | UBL 2.1 / Peppol BIS 3.0 XML representation (T4) |
| `state` | enum | Yes | One of `draft`, `issued`, `partially-paid`, `paid`, `overdue`, `disputed`, `written-off`, `voided` (per REQ-AR-004) |
| `glTransactionId` | string | No | Back-reference to materialised `GLTransaction` once posted |
| `peppolDispatchedAt` | datetime | No (set by T4) | Timestamp of Peppol dispatch |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Invoice`.

#### Scenario: Schema validator accepts a 1-line invoice

- **GIVEN** the schema
- **WHEN** a draft invoice with one line referencing an existing
  `Account` is saved
- **THEN** validation MUST pass; `dueDate` MUST be auto-set from
  customer payment terms.

#### Scenario: Invoice number is unique per administration

- **GIVEN** an issued invoice `INV-C-2026-0001` exists in
  administration `adm-1`
- **WHEN** another invoice is issued with the same number in the
  same administration
- **THEN** the save MUST fail with a "duplicate invoice number"
  uniqueness error.

### Requirement: REQ-AR-004: `ARInvoice` SHALL declare a declarative draft → issued → paid lifecycle with dunning-driven overdue branch

`ARInvoice` MUST declare an `x-openregister-lifecycle` block with:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `issued` | operator issue | balanced materialisation per T1 REQ-JE-007; `FiscalPeriod` open per REQ-PC-004; credit-limit check per REQ-AR-006 (warning, not block, unless administration policy says block) |
| `issued` | `paid` | payment-match event from bank reconciliation per REQ-BR-006 | matched payment amount = `totalAmount` |
| `issued` | `partially-paid` | payment-match event from bank reconciliation per REQ-BR-006 | matched payment amount < `totalAmount` |
| `partially-paid` | `paid` | additional payment-match event | cumulative matched amount = `totalAmount` |
| `issued` | `overdue` | scheduled lifecycle action when `today > dueDate` and no payment match | none |
| `partially-paid` | `overdue` | same | none |
| `overdue` | `paid` | payment-match event | matched amount completes the total |
| `overdue` | `written-off` | operator action (role `controller`) | none — bad-debt write-off journal materialises a balanced compensating GL posting |
| `issued` | `disputed` | operator action | none — payment-match pursuit pauses |
| `disputed` | `issued` | resolution | none |
| `issued` | `voided` | operator action | materialised `GLTransaction` MUST already be reversed per T1 REQ-GL-004 |

The `issued → overdue` transition MUST fire via OR's
`ScheduledWorkflow` primitive (per ADR-031 §"Background jobs that
walk an object queue" path 2 — not a shillinq `*Job` PHP class).

#### Scenario: Issuing a balanced AR invoice materialises GL

- **GIVEN** a `draft` AR invoice with valid lines
- **WHEN** the operator issues it
- **THEN** a balanced `GLTransaction` MUST be materialised (debit
  AR control account, credit revenue accounts per the lines);
  **AND** the invoice state MUST become `issued`; **AND**
  `glTransactionId` MUST reference the new transaction.

#### Scenario: Overdue transition fires automatically

- **GIVEN** an `issued` AR invoice with `dueDate: 2026-04-30` and no
  payment match
- **WHEN** the OR scheduled-workflow ticks on `2026-05-01`
- **THEN** the invoice MUST transition to `overdue`; **AND**
  REQ-AR-005's dunning schedule MUST start.

#### Scenario: Write-off materialises a compensating GL posting

- **GIVEN** an `overdue` invoice for €1 000 declared uncollectible
- **WHEN** an actor in role `controller` transitions to `written-off`
- **THEN** a compensating `GLTransaction` MUST be materialised
  (credit AR control €1 000, debit bad-debt expense account €1 000);
  **AND** the invoice state MUST become `written-off`.

### Requirement: REQ-AR-005: AR dunning workflow SHALL consume OR's dunning-workflow abstraction; no app-local dunning service

`ARInvoice` MUST consume OR's dunning-workflow extension via
`x-openregister-lifecycle.requires` on transitions from `overdue`.
Dunning policy (reminder cadence, escalation thresholds, template
selection, debt-collection escalation) MUST be configured through
OR's dunning-workflow configuration — NOT through an app-local
dunning service. Per ADR-022 anti-pattern list.

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

If OR's dunning-workflow extension is NOT yet stable at T2
implementation time, the shape-neutral fallback per ADR-031
exception is a single-method `OCA\Shillinq\Lifecycle\DunningGuard`
called *by* the lifecycle engine to evaluate cadence + escalation;
`DunningRecord` writes remain declarative. The guard is removed
when OR's extension lands. The spec is shape-neutral.

#### Scenario: Reviewer confirms no parallel dunning service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/Dunning*.php`,
  `lib/Service/Collection*.php`, `lib/Service/Reminder*.php`
- **THEN** no such files SHALL exist (other than the conditional
  `DunningGuard` lifecycle guard, single method, explicitly cited
  as ADR-031 exception).

#### Scenario: First reminder fires 14 days after due date by default

- **GIVEN** an overdue invoice with `dueDate: 2026-04-30` and the
  default dunning policy (reminder 1 at +14 days)
- **WHEN** the OR dunning-workflow engine ticks on `2026-05-14`
- **THEN** a `DunningRecord` MUST be appended with
  `reminderLevel: reminder-1`; **AND** an OR notification MUST be
  dispatched to the customer email per OR's notification engine.

#### Scenario: Escalation to collection at +60 days

- **GIVEN** the default policy escalates to `collection` at +60
  days
- **WHEN** the dunning engine ticks at `+60` days with no payment
- **THEN** a `DunningRecord` with `reminderLevel: collection` MUST
  be appended; **AND** the configured collection-agency
  notification MUST fire per OR's notification engine.

### Requirement: REQ-AR-006: Credit-limit checks SHALL be a declarative aggregation surfaced on AR draft creation

When a `CustomerMaster` has `creditLimit > 0`, drafting a new
`ARInvoice` MUST aggregate the customer's open AR balance (sum of
`ARInvoice.totalAmount` where state ∈ `{issued, partially-paid,
overdue, disputed}` minus matched payments) and surface a
warning if (open balance + new invoice amount) > `creditLimit`.

By default the check is a **warning only** (the invoice may still
be issued). An administration policy MAY upgrade the check to a
**hard block** by configuring the appropriate OR
`x-openregister-lifecycle.requires` on the `draft → issued`
transition.

The aggregation MUST be `x-openregister-aggregations` per ADR-031;
no `CreditLimitService.php`.

#### Scenario: Within-limit draft issues without warning

- **GIVEN** a customer with `creditLimit: 10000` and €4 000 open
  AR
- **WHEN** an operator drafts a €5 000 invoice
- **THEN** the draft MUST succeed; no warning MUST surface;
  issue MUST succeed.

#### Scenario: Over-limit draft surfaces warning

- **GIVEN** a customer with `creditLimit: 10000` and €8 000 open
  AR
- **WHEN** an operator drafts a €5 000 invoice (would bring open
  to €13 000)
- **THEN** the draft MUST succeed; **AND** a credit-limit warning
  MUST be surfaced naming the projected open balance and the
  limit.

#### Scenario: Hard-block policy rejects over-limit issue

- **GIVEN** the administration policy upgrades credit-limit to
  hard block
- **WHEN** an operator attempts to issue an invoice that would
  exceed the customer's limit
- **THEN** the `draft → issued` transition MUST fail with a
  "credit limit exceeded" error.

### Requirement: REQ-AR-007: Payment matching SHALL transition `issued`/`partially-paid` → `paid` via bank-reconciliation events; no shillinq matcher service

When `bookkeeping-bank-reconciliation` matches a bank statement
line to an `ARInvoice` (per REQ-BR-006), the matching engine MUST
emit a CloudEvent (or OR-native equivalent) that the `ARInvoice`
lifecycle consumes to transition state per REQ-AR-004. Partial
matches transition to `partially-paid`; cumulative matches summing
to `totalAmount` transition to `paid`.

No PHP matcher service in shillinq; symmetric to AP REQ-AP-008.

#### Scenario: Exact-amount bank line marks invoice paid

- **GIVEN** an `issued` AR invoice for €500 and a bank statement
  line of €500 with a matching customer reference
- **WHEN** the bank-reconciliation engine matches them per
  REQ-BR-006
- **THEN** the AR invoice state MUST transition to `paid`; **AND**
  the audit trail MUST record the match event.

#### Scenario: Partial payment yields partially-paid state

- **GIVEN** an `issued` AR invoice for €1 000 and a bank line of
  €400
- **WHEN** the engine matches them (partial)
- **THEN** the AR invoice MUST transition to `partially-paid`;
  **AND** a partial-payment audit event MUST be recorded.

### Requirement: REQ-AR-008: AR aging SHALL be declared as an `x-openregister-aggregations` query, not a PHP report builder

AR aging MUST be expressed as an `x-openregister-aggregations`
query grouping `ARInvoice` by `(customerId, agingBucket)` where
`agingBucket` is one of `current`, `1-30 days`, `31-60 days`,
`61-90 days`, `>90 days` computed as
`(today - invoice.dueDate)`. The aggregation MUST exclude
invoices in state `paid`, `voided`, or `written-off`.

NO `ARAgingReportService.php`. The same ADR-031 anti-pattern
prohibition as AP REQ-AP-009.

#### Scenario: Reviewer confirms no AR aging service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*Aging*.php`
- **THEN** no such files SHALL exist.

#### Scenario: Aging excludes paid invoices

- **GIVEN** customer `C-001` has three issued invoices (one paid,
  two open both 45 days overdue)
- **WHEN** the AR aging aggregation runs for that customer
- **THEN** the result MUST report `31-60 days: 2`; the paid one
  MUST be excluded.

### Requirement: REQ-AR-009: AR invoice issuance SHALL declare the field shape T4 needs for UBL 2.1 / Peppol BIS 3.0 outbound, even though dispatch ships in T4

The `ARInvoice` schema (REQ-AR-003) declares `ublXml` (calculated
by T4) and `peppolDispatchedAt` (set by T4). The
`CustomerMaster` schema (REQ-AR-002) declares `peppolEndpoint`. T2
declares the fields so T4 attaches additively (no destructive
migration); T2 does NOT compute UBL XML or initiate Peppol
dispatch. The fields are no-op in T2.

The dependency is recorded in T2's proposal under "New
Dependencies" and in T4's roadmap under "Required predecessor:
T2 AR".

#### Scenario: T2 does not compute UBL XML

- **GIVEN** an issued AR invoice in T2
- **WHEN** the `ublXml` field is read
- **THEN** the value MUST be null (T4 populates it; T2 declares
  the field only).

#### Scenario: T4 attaches additively without schema migration

- **GIVEN** T2 is live and `ARInvoice` records exist with
  `ublXml: null`
- **WHEN** T4's e-invoicing capability ships
- **THEN** the `ublXml` field MUST be populated by T4's
  calculation for new invoices, and a T4 backfill MUST be possible
  via OR's standard object-update API; no schema migration MUST
  be required.

### Requirement: REQ-AR-010: Accounts Receivable SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare:

- `Bookkeeping > Customers` — `type: index` + `type: detail` on
  `CustomerMaster`.
- `Bookkeeping > Accounts Receivable` — `type: index` +
  `type: detail` on `ARInvoice`; detail page MUST surface
  lifecycle actions + link to materialised `GLTransaction` + the
  dunning timeline from `DunningRecord`.
- `Bookkeeping > AR Aging` — `type: report` (or `type: index`
  fallback) bound to the AR aging aggregation.
- `Bookkeeping > Dunning` — `type: index` + `type: detail` on
  `DunningRecord` for operator review of the dunning timeline.

Rendering MUST use `@conduction/nextcloud-vue` generic components
per ADR-024 Tier-4 — no bespoke Vue files.

#### Scenario: Customer index lists customer masters

- **GIVEN** the manifest declares the Customers pages
- **WHEN** an operator opens
  `/index.php/apps/shillinq/customers`
- **THEN** `CnIndexPage` MUST render columns including
  `customerNumber`, `name`, `paymentTermDays`, `creditLimit`,
  `lifecycleState`.

#### Scenario: AR invoice detail shows dunning timeline

- **GIVEN** an overdue AR invoice with two `DunningRecord`
  entries
- **WHEN** an operator opens the detail page
- **THEN** the page MUST surface the dunning timeline as a
  sub-list filtered to the invoice's UUID.
