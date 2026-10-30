---
status: done
---

# shillinq-invoice-quick-draft Specification

## Purpose
Provides a dashboard-launched modal for quickly drafting an accounts-receivable invoice without leaving the Financial overview. Selecting a customer drives default GL account and due date, line items show live net, VAT, and gross totals, and saving creates a draft ARInvoice through the OpenRegister object API before refreshing the receivables widget; last-used line details are remembered per customer with a 90-day expiry.

## Requirements

### Requirement: Quick-draft launch from the Financial overview

The Financial overview dashboard's **Create invoice** action SHALL open
the InvoiceQuickDraftModal in place, instead of navigating to the AR
index page. The modal is hosted by the dashboard actions component and
isolated in its own `.vue` file under `src/modals/`.

#### Scenario: Create invoice opens the quick-draft modal

- **GIVEN** the user is on the Financial overview dashboard
- **WHEN** the user clicks the **Create invoice** action
- **THEN** the quick-draft modal opens without leaving the dashboard

### Requirement: Customer selection drives defaults

The modal SHALL resolve customers from the `CustomerMaster` register
schema and, on selection, default the line GL account from the
customer's `defaultGlAccount` and the due date from the customer's
payment terms (net 30 fallback).

#### Scenario: Selecting a customer pre-fills the due date

- **GIVEN** the quick-draft modal is open
- **WHEN** the user selects a customer
- **THEN** the due date is computed from the invoice date plus the
  customer's payment terms

### Requirement: Line items with live totals

The modal SHALL support one or more line items (description, quantity,
unit price, VAT rate) and display live net, VAT and gross totals
computed from those lines.

#### Scenario: Adding a line updates the totals

- **GIVEN** a draft with one line of 2 × €100 at 21% VAT
- **WHEN** the totals are computed
- **THEN** net is €200, VAT is €42 and gross is €242

### Requirement: Save as draft via OpenRegister

On save the modal SHALL create an `ARInvoice` object in lifecycle state
`draft` through the OpenRegister object API (ADR-022 — no app-local AR
CRUD controller), and SHALL not be saveable until a customer and at
least one priced line are present.

#### Scenario: Save creates a draft ARInvoice

- **GIVEN** a customer is selected and one priced line is entered
- **WHEN** the user clicks **Save draft**
- **THEN** an `ARInvoice` is created with `lifecycleState: "draft"` and
  a success toast naming the new invoice is shown

### Requirement: Dashboard refresh after save

After a successful save the modal SHALL emit the `cn:widget:refresh`
event for the receivables widget so the Financial overview reflects the
new draft without a full-page navigation.

#### Scenario: Receivables widget refreshes after save

- **GIVEN** a draft invoice has just been saved
- **WHEN** the modal closes
- **THEN** a `cn:widget:refresh` event is emitted for the receivables
  widget

### Requirement: Per-customer last-used persistence

The modal SHALL persist the last-used GL account, VAT code, description
and unit price per customer in `localStorage` (key
`shillinq:invoice-quick-draft:{customerId}`), pre-filling them on the
next draft for the same customer, and SHALL expire entries after 90
days.

#### Scenario: Stored preferences expire after 90 days

@e2e exclude localStorage TTL is a pure persistence rule asserted at unit level, not observable as UI behaviour

- **GIVEN** a stored preference older than 90 days
- **WHEN** the preferences are loaded for that customer
- **THEN** nothing is returned and the stale entry is discarded

### Requirement: The quick draft saves its lines as invoiceLines (REQ-IQD-006)

`buildInvoicePayload()` SHALL write the draft's lines to `invoiceLines`, each with
`lineId` (the position as a string), `itemName` (the trimmed description),
`quantity`, `unitCode` `C62`, `netPrice` (the unit price), `netAmount` (quantity
times unit price, rounded to cents), `vatRate` and `vatCategory` (`S` above zero,
`Z` at zero). It SHALL NOT write `lines`. Empty lines SHALL still be dropped.

#### Scenario: A consultancy drafts two hours of work

- GIVEN a draft line "Consulting", quantity 2, unit price 100, VAT 21, and an empty line
- WHEN the payload is built
- THEN `invoiceLines` holds one line with `lineId` "1", `itemName` "Consulting", `netPrice` 100, `netAmount` 200, `vatRate` 21, `vatCategory` S and `unitCode` C62
- AND the payload has no `lines` key, and the totals are net 200, VAT 42, gross 242
- @e2e exclude payload builder; covered by `tests/vitest/invoiceQuickDraft.spec.js` "builds a draft ARInvoice whose lines are the declared invoiceLines"

### Requirement: REQ-IQD-007: The quick draft SHALL write only declared fields, its reference and line account included

ARInvoice SHALL declare `customerReference` and a `glAccount` on each
`invoiceLines` item. The quick draft SHALL write the reference as
`customerReference` and the default GL account (or a line's own) as each line's
`glAccount`. Every payload key and line key SHALL be declared on ARInvoice.

#### Scenario: The reference and GL account survive the save

- GIVEN a draft with reference `PO-42` and GL account `8000`
- WHEN the payload is built
- THEN `customerReference` is `PO-42`, each line's `glAccount` is `8000`
- AND every key is declared on the effective ARInvoice register
- @e2e exclude payload builder; covered by `tests/vitest/invoiceQuickDraft.spec.js`

### Requirement: REQ-IQD-008: Quick drafts saved before ARInvoice 0.16.0 SHALL get their reference and line accounts from their audit trail

For an ARInvoice with a `DRAFT-` number the repair step of REQ-RIN-011 SHALL read
the invoice's create audit entry and fill a blank `customerReference` from it, and
each line without a `glAccount` from the account at the same position in the
entry's `invoiceLines`, or in the older `lines` shape. A value already there SHALL
NOT be written. An audit trail that cannot be read SHALL skip that draft only.

#### Scenario: A quick draft gets its reference and accounts from its create entry

- GIVEN a quick draft without a reference whose create audit entry holds reference `PO-12` and account 8020 on line 1
- WHEN the repair step runs
- THEN the draft carries reference `PO-12` and line 1 account 8020
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testAQuickDraftGetsItsReferenceAndAccountsFromItsAudit`

#### Scenario: The older lines shape is read and a reference is kept

- GIVEN a quick draft with reference `KEEP` whose create entry holds `lines` with account 8030
- WHEN the repair step runs
- THEN the reference stays `KEEP` and line 1 gets account 8030
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testTheOlderQuickDraftLinesShapeIsRead`

#### Scenario: An unreadable audit trail skips only that draft

- GIVEN a quick draft whose audit trail cannot be read and a generated invoice that fits its profile
- WHEN the repair step runs
- THEN only the generated invoice is saved and no warning is written
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testAnUnreadableAuditSkipsOnlyThatDraft`
