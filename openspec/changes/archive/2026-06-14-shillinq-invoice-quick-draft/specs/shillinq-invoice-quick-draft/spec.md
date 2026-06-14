# shillinq-invoice-quick-draft Specification

## ADDED Requirements

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
