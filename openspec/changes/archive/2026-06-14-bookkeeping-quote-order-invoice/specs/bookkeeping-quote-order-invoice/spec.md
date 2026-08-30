# Specification: bookkeeping-quote-order-invoice

| Property | Value |
|----------|-------|
| **Status** | proposed |
| **Scope** | shillinq |
| **Tier** | T3 (sales + revenue operations) |
| **App** | shillinq |
| **Depends on** | bookkeeping-general-ledger, bookkeeping-accounts-receivable-core, bookkeeping-ifrs15-revenue |
| **Depends on (external)** | openconnector (e-signature, VIES, Peppol, shipping, CRM), docudesk |
| **Kind** | config (12 new schemas, lifecycle automation, declarative calculations/aggregations) |

## Overview

This specification defines the **Sales Funnel: Quote → Order → Invoice (Q2C)** capability
for Shillinq — the quote-to-cash workflow for Dutch SMEs. It covers a versioned,
non-binding quote (verkoopofferte) with multi-channel acceptance, conversion to a binding
sales order (verkooporder) with partial-delivery / backorder tracking, delivery (Levering)
shipment records, delivery / advance / consolidated invoicing (factuur) with Dutch
sequential numbering, per-line BTW with VIES reverse-charge, Peppol BIS Billing 3.0 / UBL 2.1
outbound e-invoicing, customer pricing tiers + volume discounts, blanket-order call-off,
automated AR-ageing credit holds, and credit notes (creditfactuur) with AR offset.

Per **ADR-031**, the entire surface is declarative metadata: schemas
(`lib/Settings/register.d/bookkeeping-quote-order-invoice.json`), lifecycles
(`x-openregister-lifecycle`), line/quantity arithmetic (`x-openregister-calculations`),
and reporting roll-ups (`x-openregister-aggregations`) — there are **no**
`QuoteService` / `OrderService` / `InvoiceService` PHP classes. The small set of
cross-field / cross-schema lifecycle preconditions that the declarative `requires:`
clause cannot yet express are referenced from the schema transitions and implemented,
fail-closed, in `OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard` (ADR-031 exception path).
Per **ADR-022**, every object read uses the real OpenRegister ObjectService API
(`setRegister` / `setSchema` / `findAll`). A **customer / contact is a Nextcloud entity**
(NC addressbook contact / AR customer master) referenced by `customerReference` — never a
re-declared Customer schema.

## Requirements

### Requirement: REQ-QOI-001: Quote lifecycle with version control

A `Quote` MUST progress through `draft → sent → (accepted | declined | expired)` and
support version control: revising a sent quote retains the originally-sent version as
an immutable snapshot (`supersedesQuote` FK + incremented `version`). A quote MUST NOT
leave `draft` unless it carries a `customerReference` and at least one `QuoteLine`
(enforced by `QuoteOrderInvoiceGuard::canSendQuote`).

#### Scenario: Quote revision retains prior version

**GIVEN** a quote v1 has been sent to the customer (status: sent) at 2026-01-15,
**WHEN** the salesperson revises the unit price and re-sends as v2 at 2026-01-18,
**THEN** the system MUST retain v1 as an immutable snapshot (visible in quote history),
create v2 as the current active quote (status: sent) referencing v1 via `supersedesQuote`,
and keep the original v1 PDF accessible as evidence of the original offer.

### Requirement: REQ-QOI-002: Quote acceptance via three channels

Quote acceptance MUST be capturable via three channels recorded in `acceptanceChannel`:
(a) `in-app-link` (no login, signed-URL token), (b) `e-signature` (DocuSign / Signhost /
Adobe Sign via openconnector), (c) `manual-backoffice` (mark-as-accepted with uploaded
evidence). Each channel MUST record `acceptedAt` and an `acceptanceEvidenceReference`.
A quote MUST NOT transition `sent → accepted` without a recorded acceptance channel
(`QuoteOrderInvoiceGuard::canAcceptQuote`).

#### Scenario: Customer accepts quote via in-app link

**GIVEN** a quote (status: sent) with a unique acceptance token,
**WHEN** the customer opens the acceptance link (no login required), enters their name,
and confirms,
**THEN** the system MUST set `acceptanceChannel = in-app-link`, record `acceptedAt`,
transition the quote to `accepted`, and make the acceptance auditable.

### Requirement: REQ-QOI-003: Accepted quote converts to sales order with pricing tier and credit check

An accepted quote MUST be convertible to a `SalesOrder` in one action, copying line data
to `SalesOrderLine`, resolving the customer pricing tier (REQ-QOI-009), applying active
volume discounts, performing the AR credit check (REQ-QOI-010), and recording the IFRS 15
`contractReference`. If a block-severity credit hold applies, the conversion MUST be rejected.

#### Scenario: Quote conversion applies pricing tier and credit check

**GIVEN** a customer with a tiered pricing rule (1–9 @ €100, 10–49 @ €90, 50+ @ €80) and
no active credit hold,
**WHEN** the salesperson converts an accepted quote for 25 units,
**THEN** the system MUST resolve the unit price to €90 (10–49 tier), store the resolved
`unitPrice` and `appliedTierName` on the order line, pass the credit check, and create the
SalesOrder in `draft` with a linked IFRS 15 `contractReference`.

### Requirement: REQ-QOI-004: Sales orders support partial deliveries and backorders

A `SalesOrder` MUST progress through `draft → confirmed → (partial | shipped | invoiced |
closed | cancelled)`. Each `SalesOrderLine` tracks `orderedQuantity`, `deliveredQuantity`,
and `invoicedQuantity`; `backorderQuantity` and `uninvoicedQuantity` are declarative
calculations (`max(ordered − delivered, 0)` and `max(delivered − invoiced, 0)`). A single
order line of quantity N MAY be split across multiple deliveries until cumulative delivered
equals N or the remainder is backordered / cancelled. Confirmation is blocked while the
customer is under a `block-order` / `block-delivery` hold
(`QuoteOrderInvoiceGuard::canConfirmOrder`).

#### Scenario: Partial shipment and backorder handling

**GIVEN** a sales order for 100 units to be delivered in two tranches of 60 and 40,
**WHEN** the warehouse confirms a delivery of 60 units,
**THEN** the system MUST set the order-line `deliveredQuantity` to 60, expose
`backorderQuantity` = 40, leave the order in `partial`, and (under delivery-based invoicing)
generate a draft invoice for the 60 delivered units.

### Requirement: REQ-QOI-005: Invoicing supports multiple triggering modes

Invoicing MUST support the modes recorded in `SalesOrder.invoicingMode` and reflected in
`Invoice.invoiceType`: `on-delivery` (most common — `delivery-based`), `on-order`
(advance → deferred revenue), and `consolidated-monthly` (`consolidated`). An order MAY
generate one or many invoices, and a consolidated invoice MAY combine multiple
`sourceOrderReferences` / `sourceDeliveryReferences`. An `Invoice` progresses through
`draft → sent → (partially-paid | paid | overdue | cancelled | credited)`. On issue
(`draft → sent`) the GL posting is materialised (debit AR, credit revenue, credit BTW;
advance → deferred revenue), the AR open item is created, and dispatch (optionally Peppol)
occurs. Issue is blocked unless the invoice carries a sequential number, at least one
`InvoiceLine`, and a balanced `netAmount + vatAmount = grossAmount`
(`QuoteOrderInvoiceGuard::canIssueInvoice`).

#### Scenario: Delivery-based invoicing with partial shipment

**GIVEN** a sales order for 100 units with two planned deliveries (60 + 40),
**WHEN** the warehouse confirms the 60-unit delivery,
**THEN** the system MUST create the Delivery record and a draft Invoice for 60 units
(`invoiceType = delivery-based`, `sourceDeliveryReferences = [delivery]`), update the order
line to delivered=60 / invoiced=60, and await operator confirmation before sending.

### Requirement: REQ-QOI-006: BTW is calculated per line with VIES reverse-charge

BTW MUST be determined per line from customer location and product classification:
- **Binnenland (NL domestic)**: the product's standard rate (21 / 9 / 0%).
- **Intracommunautair (EU B2B)**: 0% reverse-charge when the customer VAT number is
  VIES-validated (async, cached 24h), recording `viesValidated = true` and a `btwLegend`
  ("Btw verlegd — intracommunautaire levering, art. 196 btw-richtlijn"). A VIES failure
  MUST raise a warning before dispatch; the operator MAY override (audit-trailed).
- **Derde landen (export)**: 0% on documented export (T4 future), else 21% conservative.

#### Scenario: Intracommunautaire customer with VIES validation

**GIVEN** an EU B2B customer in Germany with VAT number DE123456789,
**WHEN** an invoice is issued,
**THEN** the system MUST validate the VAT number against VIES, apply 0% BTW with the
reverse-charge `btwLegend`, set `viesValidated = true`, and dispatch via Peppol if the
customer has a registered Peppol identifier.

### Requirement: REQ-QOI-007: Dutch-compliant invoice numbering, no gaps, no deletion

The system MUST produce a Dutch-compliant sequential `invoiceNumber` per administration with
no gaps. A draft invoice MAY be cancelled (number released); an **issued** invoice MUST NOT
be deletable — reversal is only via a `CreditNote` (Belastingdienst guidance). The Invoice
lifecycle therefore offers `cancel` only from `draft` and `credit` only from `sent`.

#### Scenario: Issued invoice cannot be deleted

**GIVEN** an invoice in status `sent` with number 2026-0001,
**WHEN** an operator attempts to delete it,
**THEN** the system MUST reject deletion and require a `CreditNote` referencing the invoice
to reverse it, preserving the audit trail.

### Requirement: REQ-QOI-008: E-invoicing produces Peppol BIS 3.0 / UBL 2.1 XML

E-invoicing MUST generate UBL 2.1 / Peppol BIS Billing 3.0 (EN 16931 / NL CIUS) compliant
XML and dispatch it through a Peppol Access Point (openconnector) for customers with a
registered Peppol identifier, storing `peppolMessageId` and `ublXmlReference`. For Dutch
public-sector customers Peppol dispatch MUST be the default (Wet elektronische facturatie
overheid).

#### Scenario: Public-sector invoice dispatched via Peppol

**GIVEN** a Dutch municipality customer with Peppol identifier 0106:NL123456789B01,
**WHEN** the invoice transitions `draft → sent`,
**THEN** the system MUST generate the UBL XML, dispatch it via the Peppol Access Point, and
store the returned `peppolMessageId` and `ublXmlReference` on the invoice.

### Requirement: REQ-QOI-009: Pricing tiers resolved in priority order with line-level transparency

`PricingTier` resolution MUST follow priority order: customer-specific (priority 1) >
customer-group (2) > product-group (3) > list price (4), then apply active `VolumeDiscount`
rules. The resolved `unitPrice` and `appliedTierName` MUST be stored on the order line for
transparency. Pricing is resolved declaratively (`x-openregister-aggregations`) and stored
at order-confirmation time (not recomputed at invoice time).

#### Scenario: Customer-specific tier overrides product-group tier

**GIVEN** a product-group tier (€90 for 10–49 units) and a customer-specific agreed price
(€75 flat) for the same customer + product,
**WHEN** an order line for 20 units is resolved,
**THEN** the system MUST select the customer-specific tier (€75), store `appliedTierName`
on the line, and show the resolved price to the operator.

### Requirement: REQ-QOI-010: Automated AR-ageing credit holds and credit notes

A `CreditHold` MUST be triggered automatically when configurable AR-ageing thresholds are
breached (default: open balance > €5,000 AND oldest item > 60 days overdue), with severity
`warning` (blocks nothing), `block-order` (blocks order confirmation), or `block-delivery`
(blocks shipment). Thresholds live in administration config (ADR-022 `IAppConfig`). A hold
release MUST record `releasedBy` and `releaseReason`
(`QuoteOrderInvoiceGuard::canReleaseCreditHold`). A `CreditNote` MUST reference a source
invoice, carry a reason code and a positive amount, and on issue post a GL reversal plus an
AR offset (`QuoteOrderInvoiceGuard::canIssueCreditNote`).

#### Scenario: Credit-hold blocks order confirmation

**GIVEN** a customer with €7,000 outstanding AR and the oldest invoice 75 days overdue,
**WHEN** the salesperson attempts to confirm a new sales order,
**THEN** the system MUST find an active `block-order` / `block-delivery` CreditHold for the
customer and reject the confirmation; a Credit Controller MAY release the hold with a
recorded `releasedBy` and `releaseReason`.

## Cross-References

- **IFRS 15**: `SalesOrder.contractReference` / `SalesOrderLine.performanceObligationReference`
  → bookkeeping-ifrs15-revenue.
- **AR**: `Invoice.arOpenItemReference` / `CreditNote.arOffsetReference` →
  bookkeeping-accounts-receivable-core; AR ageing drives credit holds.
- **GL**: `Invoice.glTransactionReference` / `CreditNote.glTransactionReference` →
  bookkeeping-general-ledger (advance → deferred revenue, reversed on delivery).
- **openconnector**: VIES validation, e-signature, Peppol dispatch, shipping tracking, CRM sync.
- **docudesk**: quote / invoice / proof-of-delivery PDF (FK URI per
  bookkeeping-document-attachment-integration).

## Implementation Approach (per ADR-031)

- **Lifecycles** (Quote, SalesOrder, Delivery, Invoice, CreditNote, CreditHold): declarative
  `x-openregister-lifecycle`; cross-field preconditions → `QuoteOrderInvoiceGuard` (fail-closed).
- **Line / quantity arithmetic** (line net/vat, backorder, uninvoiced, remaining blanket qty):
  declarative `x-openregister-calculations`.
- **Pricing resolution, AR ageing, sales-funnel KPIs**: declarative `x-openregister-aggregations`.
- **Credit-hold automation**: scheduled workflow (ADR-031 path 2) evaluating AR ageing daily.
- **No PHP Q2C service classes.**
