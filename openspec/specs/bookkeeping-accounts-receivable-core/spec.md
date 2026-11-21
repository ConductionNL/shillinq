---
status: in-progress
---

# Spec: bookkeeping-accounts-receivable-core

**Status:** in-progress
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** T1 `bookkeeping-general-ledger`, T2 `bookkeeping-document-attachment-integration`, T2 `bookkeeping-bank-reconciliation`
**OpenSpec changes:**
- `add-invoice-pdf-export-with-ubl-peppol-support` — adds `ARInvoice.deliveryStatus` field + Peppol delivery sub-lifecycle (REQ-AR-011)

This capability **carries forward the original Shillinq invoicing
scope** — the customer invoicing surface that was Shillinq's founding
use-case — and expands it with dunning, credit-limit checks, and
bank-reconciliation-based payment matching. It is the AR half that
completes the AP half delivered by `bookkeeping-accounts-payable-core`.

## Purpose

This specification defines the requirements for bookkeeping accounts receivable core in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/schema: AR register declarations, schema constraints, dunning logic — not browser-testable

### REQ-AR-001: The system SHALL store customer masters, AR invoices, and dunning records as OpenRegister-managed registers

Three registers MUST be declared in `lib/Settings/shillinq_register.json`:
`CustomerMaster`, `ARInvoice`, `DunningRecord`. No parallel PHP Mapper
classes, no custom DB tables, no app-local dunning tables (per ADR-022
anti-pattern list). OR's generic CRUD HTTP surface exposes all three.

#### Scenario: Reviewer confirms no parallel AR storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `ar_invoice`,
  `customer_master`, or `dunning_record`
- **THEN** no such classes SHALL exist.

### REQ-AR-002: The `CustomerMaster` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `CustomerMaster` schema SHALL declare a fixed minimum field set.

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

### REQ-AR-003: The `ARInvoice` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `ARInvoice` schema SHALL declare a fixed minimum field set.

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

### REQ-AR-004: The `ARInvoice` schema SHALL declare an AR invoice lifecycle via `x-openregister-lifecycle`

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

### REQ-AR-005: AR dunning SHALL be consumed from OR's dunning-workflow abstraction

The system SHALL satisfy this requirement: AR dunning SHALL be consumed from OR's dunning-workflow abstraction.

Per ADR-022, the dunning workflow (reminder sequences, escalation
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

### REQ-AR-006: The credit-limit check on `ARInvoice` issuance SHALL be declared as an `x-openregister-aggregations` query

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

### REQ-AR-007: The `DunningRecord` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `DunningRecord` schema SHALL declare a fixed minimum field set.

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

### REQ-AR-008: AR aging SHALL be declared as an `x-openregister-aggregations` query

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

### REQ-AR-009: UBL 2.1 / Peppol BIS 3.0 field shapes SHALL be declared for T4 attachment

The `ARInvoice` schema MUST declare the `ublRef` field (string, optional)
so that a future T4 UBL/Peppol e-invoicing capability can attach
without a destructive migration. The actual UBL 2.1 XML generation
MUST NOT be implemented in T2 — it is explicitly deferred to T4.
The spec is shape-neutral for which format T4 selects.

#### Scenario: UBL ref field exists but is unused in T2

- **GIVEN** an `ARInvoice` in state `issued`
- **WHEN** the object is inspected
- **THEN** the `ublRef` field MUST exist on the schema (may be null).

### REQ-AR-010: Accounts receivable SHALL be reachable through the shillinq manifest navigation

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

### Requirement: REQ-AR-011 — The `ARInvoice` schema SHALL declare a Peppol delivery-status field and a declarative delivery sub-lifecycle

The `ARInvoice` schema MUST declare the following additive fields (all optional,
defaulting so existing invoices remain valid):

| Field | Type | Required | Description |
|---|---|---|---|
| `deliveryStatus` | enum | No | One of `not-sent`, `queued`, `sent`, `delivered`, `rejected`, `failed` — default `not-sent` |
| `transmissionId` | string | No | Peppol message/transmission id (URN) returned by the transmission port |
| `payloadFileUri` | string | No | Docudesk/Files FK URI of the stored UBL / PDF-A3 artefact |
| `deliveryDetail` | string | No | Last delivery-status `detail` message (rejection/failure reason) |

The delivery status transitions MUST be declared via
`x-openregister-lifecycle` (a delivery sub-lifecycle keyed on `deliveryStatus`,
distinct from the `lifecycleState` lifecycle) with the following transitions:

| From | To | Trigger |
|---|---|---|
| `not-sent` | `queued` | operator triggers Send e-invoice (after validation) |
| `queued` | `sent` | access point accepts (`nl.conduction.peppol.delivery.status` `status: sent`) |
| `sent` | `delivered` | recipient access point confirms (`status: delivered`) |
| `queued` | `rejected` | access point/recipient rejects (`status: rejected`) |
| `sent` | `rejected` | recipient rejects after acceptance (`status: rejected`) |
| `queued` | `failed` | transmission error (`status: failed`) |
| `rejected` | `queued` | operator re-sends after correction |
| `failed` | `queued` | operator re-sends |

The delivery sub-lifecycle MUST NOT alter the accounting `lifecycleState`
(REQ-AR-004): an invoice can be `issued`/`overdue`/`paid` independently of its
`deliveryStatus`.

#### Scenario: Schema validator accepts an ARInvoice with a delivery status

- GIVEN the `ARInvoice` schema after this delta
- WHEN an object with required AR fields plus `deliveryStatus: "queued"`,
  `transmissionId: "urn:uuid:00000000-0000-0000-0000-000000000000"` is validated
- THEN validation MUST pass

#### Scenario: Existing invoices remain valid without the new field

- GIVEN a pre-existing `ARInvoice` object with no `deliveryStatus`
- WHEN it is loaded and re-validated
- THEN validation MUST pass and `deliveryStatus` MUST be treated as `not-sent`

#### Scenario: Delivery sub-lifecycle is independent of accounting state

- GIVEN an `ARInvoice` in `lifecycleState: paid` and `deliveryStatus: sent`
- WHEN a delivery-status event advances `deliveryStatus` to `delivered`
- THEN `lifecycleState` MUST remain `paid` (unchanged)

### Requirement: A down-payment invoice is raised on an order (REQ-SDP-001)

A user SHALL be able to raise an `ARInvoice` of down-payment kind for a
customer and an order, for a percentage of the order's net total or a fixed
net amount. The order SHALL be referenced by semantic type
`https://schema.org/Order`; when the reference does not resolve to an order
whose totals shillinq can read, the user MUST enter the order's net total per
VAT rate. The down payment SHALL be split over the order's VAT rates in
proportion, SHALL carry invoice type code 386, and SHALL store the totals it
was computed from.

#### Scenario: A kitchen studio asks 30 percent up front

- GIVEN order Keuken Eiland 2026-117 for Familie De Boer with a net total of EUR 15,000 at 21 percent VAT
- WHEN a bookkeeper chooses New down-payment invoice on the Accounts Receivable page, picks the customer and the order and enters 30 percent
- THEN a draft invoice of EUR 4,500 plus EUR 945 VAT, total EUR 5,445, exists with type code 386 and the order named on it

### Requirement: A down payment is booked as an advance received (REQ-SDP-002)

Issuing a down-payment invoice SHALL post debit debtors, credit the
administration's advances account and credit VAT payable, and MUST NOT post to
a revenue account. The advances account SHALL default to 2310 Vooruitontvangen
bedragen in an administration seeded from the RGS template.

@e2e exclude posting is the lifecycle action's behaviour, asserted by MaterialiseGlTransactionActionTest::testADownPaymentIsBookedAsAnAdvanceNotAsRevenue

#### Scenario: The balance sheet shows the advance

- GIVEN the down-payment invoice of EUR 5,445 for Familie De Boer
- WHEN the bookkeeper issues it
- THEN the general ledger shows EUR 4,500 credited to 2310 and EUR 945 to VAT payable, and nothing on revenue

### Requirement: The final invoice deducts every open down payment of the order (REQ-SDP-003)

On a draft `ARInvoice` for a customer with issued down payments for an order
that no invoice has deducted, `ARInvoiceDetail` SHALL offer to make it the final
invoice of that order. Doing so SHALL add one negative line per down payment
and VAT rate at the down payment's own net and VAT, and SHALL reference each
down-payment invoice. Issuing it SHALL book the full revenue and debit the
advances account for the deductions.

#### Scenario: The kitchen is delivered and invoiced

- GIVEN the paid down payment of EUR 5,445 for order Keuken Eiland 2026-117
- WHEN the bookkeeper drafts the invoice for the kitchen at EUR 15,000 plus EUR 3,150 VAT and chooses Deduct down payments for that order
- THEN the invoice shows a deduction of minus EUR 4,500 and minus EUR 945 VAT referencing the down-payment invoice, and an amount due of EUR 12,705

### Requirement: A down payment is deducted once, and never beyond the invoice (REQ-SDP-004)

Issuing a final invoice MUST be refused when one of its deductions was already
deducted on another invoice, or when the deductions exceed the invoice's total.
On issue each deducted down payment SHALL record the final invoice that
deducted it.

@e2e exclude the refusal runs in the issue transition, asserted by DownPaymentServiceTest::testADownPaymentDeductedElsewhereIsRefusedOnIssue and DownPaymentGuardTest::testTheCheckRefusesASecondDeductionNamingTheFirstInvoice

#### Scenario: A second final invoice tries the same deduction

- GIVEN the down payment deducted on issued invoice 2026-0587
- WHEN a bookkeeper issues another draft that also deducts it
- THEN the issue is refused naming invoice 2026-0587

### Requirement: The order's down-payment position is visible (REQ-SDP-005)

`ARInvoiceDetail` of a down-payment or final invoice SHALL show, for its order,
every down payment with its amount, paid state and the invoice that deducted
it.

#### Scenario: The bookkeeper checks what is still to deduct

- GIVEN two down payments on order Keuken Eiland 2026-117, one deducted and one not
- WHEN the bookkeeper opens either down-payment invoice
- THEN the page lists both with their state and the invoice that deducted the first

### Requirement: The e-invoice carries the down payment and its deduction (REQ-SDP-006)

The UBL of a down-payment invoice SHALL carry invoice type code 386, and the UBL
of a final invoice SHALL carry the deduction lines as negative lines at their
VAT category and rate and a billing reference to each down-payment invoice.

@e2e exclude the UBL document is rendered server side, asserted by ArInvoiceUblMapperTest::testAFinalInvoiceCarriesTheDeductionAndTheBillingReference

#### Scenario: A municipality receives the final invoice over Peppol

- GIVEN a final invoice to Gemeente Voorbeeld deducting one down payment
- WHEN it is sent as an e-invoice
- THEN its UBL contains the negative deduction line and a billing reference to the down-payment invoice number

### Requirement: An issued invoice is sent by email with its PDF attached (REQ-SIS-001)

Shillinq SHALL let a user with access to the administration send an `ARInvoice`
in state `issued` or `overdue` to the customer by email from `ARInvoiceDetail`.
The mail MUST carry the invoice as a PDF attachment named
`Factuur-<invoiceNumber>.pdf`, MUST go to `CustomerMaster.invoiceEmail` when
set and to `CustomerMaster.email` otherwise, and the invoice SHALL record
`sending.sentVia`, `sending.sentTo` and `sending.sentAt` only after the mailer
accepted the message.

#### Scenario: A bookkeeper mails an issued invoice

- GIVEN a bookkeeper on the AR invoice page of issued invoice 2026-0412 for Bakkerij De Korenaar B.V., whose invoice email is facturen@dekorenaar.example
- WHEN they choose Send by email
- THEN a mail with the attachment Factuur-2026-0412.pdf goes to facturen@dekorenaar.example
- AND the invoice page shows it was sent by email to that address, with the date and time

#### Scenario: A draft invoice cannot be sent

- GIVEN invoice 2026-0413 in state draft
- WHEN the bookkeeper opens its AR invoice page
- THEN the Send by email action is disabled with the reason that only an issued invoice can be sent

#### Scenario: A mail failure is shown, not hidden

- GIVEN the instance's mail server refuses the message
- WHEN the bookkeeper sends invoice 2026-0412
- THEN the page shows that the invoice could not be sent and why
- AND the invoice carries no sentAt

### Requirement: A send follows the customer's delivery method (REQ-SIS-002)

The send action SHALL route by `CustomerMaster.deliveryMethod`: `email` mails
the PDF, `peppol` hands the invoice to the existing e-invoice send, `post` and
`none` MUST NOT mail anything and SHALL record the method. When the e-invoice
send answers that the customer has no Peppol participant, the action SHALL
offer to mail the PDF instead of telling the user to leave the app.

#### Scenario: A Peppol customer without a participant id gets the PDF by email

- GIVEN customer Gemeente Voorbeeld with delivery method peppol and no resolvable Peppol participant
- WHEN the bookkeeper sends issued invoice 2026-0420 from its AR invoice page
- THEN the page offers Send by email instead
- AND choosing it mails the PDF and records sentVia email

#### Scenario: A post customer is not mailed

- GIVEN customer Stichting Buurthuis De Linde with delivery method post
- WHEN the bookkeeper sends issued invoice 2026-0421
- THEN no mail is sent
- AND the invoice page shows the delivery method post and that the letter is printed by hand

### Requirement: Selected invoices are sent in one action (REQ-SIS-003)

`AccountsReceivable` SHALL let a user select up to 200 invoices and send them in
one action. The sends SHALL run in a queued background job, each invoice MUST
follow REQ-SIS-002, and the batch SHALL record an outcome per invoice: sent,
skipped with a reason, or failed with the error. An invoice that already
carries `sending.sentAt` SHALL be skipped unless the user asked to resend.

#### Scenario: A bookkeeper sends the month's invoices

- GIVEN twelve issued invoices of September 2026 selected on the Accounts Receivable page, one of them already sent
- WHEN the bookkeeper chooses Send selected
- THEN eleven invoices are sent according to each customer's delivery method
- AND the batch result lists the already-sent invoice as skipped

#### Scenario: The same batch is not sent twice

- GIVEN a send batch that finished
- WHEN the bookkeeper selects the same invoices and chooses Send selected without resend
- THEN every invoice is skipped as already sent

### Requirement: A batch creates one invoice per customer (REQ-SIS-004)

Shillinq SHALL let a user create one `ARInvoice` per selected customer from one
set of lines, for up to 200 customers per call, through
`POST /apps/shillinq/api/ar-invoices/batch`. The create MUST be idempotent per
batch and customer, each invoice's due date SHALL follow that customer's
`paymentTermDays` unless the batch names a due date, and the user MAY issue and
send the created invoices in the same action.

#### Scenario: A consultancy bills its maintenance contract to three customers

- GIVEN a bookkeeper who selects Bakkerij De Korenaar B.V. (14 days), Gemeente Voorbeeld (30 days) and Stichting Buurthuis De Linde (21 days) and one line Onderhoudscontract oktober 2026 of EUR 250 excl. 21 percent VAT dated 2026-10-01
- WHEN they create the batch with issue and send
- THEN three issued invoices of EUR 302.50 exist with due dates 2026-10-15, 2026-10-31 and 2026-10-22
- AND each is sent according to its customer's delivery method

#### Scenario: A retried batch writes nothing twice

- GIVEN a batch create that wrote invoices for two of three customers before the connection dropped
- WHEN the same batch is submitted again
- THEN only the third customer's invoice is written

### Requirement: A customer carries a payment term and a delivery method (REQ-SIS-005)

`CustomerMaster` SHALL declare `paymentTermDays` (integer, default 30),
`deliveryMethod` (`email`, `peppol`, `post`, `none`, default `email`) and an
optional `invoiceEmail`, editable on `CustomerDetail`. A new invoice created by
the quick draft, the time-and-expense generator or the batch create SHALL take
its due date as invoice date plus the customer's `paymentTermDays`, and a new
recurring profile SHALL default its payment term to it.

#### Scenario: The quick draft uses the customer's term

- GIVEN customer Bakkerij De Korenaar B.V. with a payment term of 14 days
- WHEN a bookkeeper starts a quick draft for that customer dated 2026-10-01
- THEN the due date field shows 2026-10-15

#### Scenario: The time-and-expense generator uses the customer's term

- GIVEN customer Gemeente Voorbeeld with a payment term of 30 days
- WHEN a bookkeeper generates an invoice on the Generate invoice page with period end 2026-10-31
- THEN the drafted invoice has due date 2026-11-30 and payment terms stating 30 days

### Requirement: The customer list shows fields the customer carries (REQ-SIS-006)

Every column of the `Customers` index SHALL name a property `CustomerMaster`
declares. The index MUST show the customer code, legal name, payment term,
delivery method, credit limit and status.

#### Scenario: A bookkeeper scans the customer list

- GIVEN three customers with codes, names and payment terms set
- WHEN a bookkeeper opens the Customers page
- THEN every column shows a value for every customer and none is empty for want of a field

### Requirement: A recurring profile with email delivery sends its auto-issued invoices (REQ-SIS-007)

When `RecurringInvoiceGenerator` issues an invoice for a profile in issue mode
`auto-issue` whose `deliveryChannel` is `email`, the invoice SHALL be sent
through REQ-SIS-001. A profile in `draft-for-review` mode MUST NOT send.

#### Scenario: A monthly subscription invoice goes out by itself

- GIVEN an active profile for Bakkerij De Korenaar B.V. in auto-issue mode with delivery channel email
- WHEN the scheduled run issues the October 2026 invoice
- THEN the invoice is mailed to facturen@dekorenaar.example with its PDF
- AND its AR invoice page shows it was sent by email

### Requirement: A received web shop order becomes one issued sales invoice (REQ-SWO-001)

When a `WebshopOrder` is written for a known `WebshopChannel`, shillinq SHALL
write one `ARInvoice` with a line per order line and one for shipping, issue it
when the channel's issue mode is `auto-issue`, record its id on the order and
move the order to `invoiced`. A second order record with the same channel and
shop order number MUST NOT write an invoice and SHALL be marked `duplicate`.

#### Scenario: A tea order becomes an invoice

- GIVEN web shop order 100231 from J. Bakker for 2 x Earl Grey los 250 g at EUR 8.95 and shipping EUR 4.95, received on channel theehandelvandijk.nl
- WHEN the order is written
- THEN the Accounts Receivable page lists an issued invoice for Webwinkel particulieren with buyer J. Bakker and a total of EUR 22.85
- AND the Web shop orders page shows order 100231 as invoiced with a link to that invoice

#### Scenario: The same order sent twice

- GIVEN order 100231 already invoiced
- WHEN integriq writes order 100231 again
- THEN no second invoice exists and the Web shop orders page shows the second record as a duplicate

### Requirement: The debtor is a matched business or the shop's collective consumer debtor (REQ-SWO-002)

For a business buyer the invoicer SHALL use the `CustomerMaster` of the
channel's administration with the same VAT id, else the same KvK number, else
the same invoice email, and SHALL create one marked as created by the channel
when none matches. A consumer order SHALL be booked on the channel's collective
debtor with the buyer's name and address on the invoice.

#### Scenario: A returning business buyer is recognised

- GIVEN customer Lunchroom De Theepot with VAT id NL000099997B57
- WHEN an order arrives from a business buyer with that VAT id
- THEN the invoice is booked on Lunchroom De Theepot and no new customer is created

### Requirement: Web shop VAT follows the destination rules (REQ-SWO-003)

Each order line SHALL be invoiced net of VAT at the rate the order routes to: a
domestic order at the line's own rate, a consumer in another EU member state at
that state's rate through OSS, a business buyer in another member state with a
validated VAT id under reverse charge with the mention "Btw verlegd". An order
whose route cannot be determined MUST be refused, not invoiced at the Dutch
rate.

#### Scenario: A consumer in Belgium

- GIVEN order 100232 from a consumer in Antwerpen with prices including VAT
- WHEN the order is invoiced
- THEN the invoice lines carry the Belgian VAT rate and the invoice is marked for the OSS return

### Requirement: A paid order is settled on the payment clearing account (REQ-SWO-004)

An order received with payment status paid SHALL leave its invoice `paid`,
settled against the channel's clearing account, with the provider's payment id
recorded on the invoice for the later payout match. An order with payment
status pending SHALL leave its invoice issued and open.

#### Scenario: An iDEAL order is paid on arrival

- GIVEN order 100231 paid by iDEAL with provider payment id tr_example0001
- WHEN it is invoiced
- THEN its AR invoice page shows state paid and payment reference tr_example0001

### Requirement: A refunded or cancelled order is credited (REQ-SWO-005)

When an invoiced `WebshopOrder` is updated to payment status refunded or
cancelled, shillinq SHALL write an `ARInvoice` of type credit-note for the
refunded lines, referencing the original invoice, and SHALL move the order to
`credited`.

#### Scenario: A customer returns the tea

- GIVEN invoiced order 100231
- WHEN the shop reports the order refunded
- THEN the Accounts Receivable page lists a credit note of EUR 22.85 referencing the invoice of order 100231

### Requirement: An order that cannot be invoiced is refused visibly (REQ-SWO-006)

An order for an unknown channel, without lines, or whose line totals differ
from the order total by more than one cent per line SHALL NOT be invoiced; it
SHALL move to `refused` with a reason naming the problem and both totals where
they differ, and the bookkeeper SHALL be notified.

#### Scenario: Totals that do not add up

- GIVEN order 100233 whose lines add up to EUR 45.20 while the order total is EUR 45.60
- WHEN the order is written
- THEN no invoice is written
- AND the Web shop orders page shows order 100233 as refused with both totals in the reason
