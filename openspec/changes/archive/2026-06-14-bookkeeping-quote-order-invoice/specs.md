# Specification: bookkeeping-quote-order-invoice

**Status**: proposed  
**Scope**: shillinq  
**Tier**: T3 (sales + revenue operations)  
**Depends on**: bookkeeping-general-ledger, bookkeeping-accounts-receivable-core,
bookkeeping-ifrs15-revenue, openconnector, docudesk

---

## REQ-QOI-001: Quote lifecycle with version control

A Quote MUST progress through the lifecycle `draft → sent → (accepted | declined | expired)`,
with version control allowing revisions while preserving the originally-sent version as evidence.

**Quote entity fields**:
- quoteNumber (string, unique per administration)
- customerReference (string, FK to customer)
- version (integer, increments on revision; v1 is the sent version)
- validityPeriod (date range)
- currency (ISO 4217)
- language (nl/en/de/fr, for quote document rendering)
- headerText, footerText (optional branded text)
- paymentTerms (string, e.g. "Net 30")
- deliveryTerms (Incoterms 2020, e.g. "FOB", "DDP")
- status (enum: draft, sent, accepted, declined, expired)
- responsibleSalesperson (string, FK to Person)
- relatedOpportunityReference (string, FK to external CRM Opportunity, if applicable)

**QuoteLine entity fields**:
- productReference (string, FK to Product)
- description (string, may differ from product description)
- quantity (number)
- unitPrice (decimal, EUR)
- discount (absolute EUR or percentage)
- btwRate (decimal, 0, 6, 9, or 21 percent)
- glAccount (string, FK to Account for revenue recognition)
- projectReference (string, optional FK to Project)
- costOfGoodsEstimate (decimal, for gross-margin calculation)

#### Scenario: Quote versioning preserves sent version

**GIVEN** a quote v1 has been sent to customer (status: sent) at 2026-01-15,
**WHEN** salesperson revises unit price and re-sends (creates v2) at 2026-01-18,
**THEN** the system MUST retain v1 as immutable (visible in quote history), create
v2 as the current active quote (status: sent), and the original v1 PDF (from
docudesk) remains accessible as evidence of the original offer.

---

## REQ-QOI-002: Quote acceptance via three channels

Quote acceptance MUST be capturable via three channels:
(a) in-app customer link (no login, signed URL token, 30-day expiry),
(b) e-signature provider integration (DocuSign, Signhost, Adobe Sign),
(c) manual back-office mark-as-accepted with uploaded evidence.

All three channels MUST record the acceptance timestamp, accepting party name,
and acceptance method, for audit trail.

#### Scenario: Customer accepts quote via in-app link

**GIVEN** a quote (status: sent) with a unique acceptance token (valid for 30 days),
**WHEN** customer clicks the acceptance link (no login required), enters their name,
and confirms acceptance,
**THEN** the system MUST record the acceptance (status: accepted), capture the
timestamp, and send the customer a confirmation email with a copy of their signed
acceptance.

---

## REQ-QOI-003: Accepted quote converts to sales order with pricing tier and credit check

An accepted quote MUST be convertible to a sales order in one click, copying all line data,
applying the customer's current pricing tier and active volume discounts, performing a credit
check, and creating the IFRS 15 Contract record.

#### Scenario: Quote conversion applies customer pricing tier and active promotions

**GIVEN** a customer with a tiered pricing rule (1–9 units at EUR 100, 10–49 units at EUR 90,
50+ units at EUR 80) and an active "free shipping above EUR 1,000" promotion,
**WHEN** salesperson creates a quote for 25 units,
**THEN** the system MUST resolve the unit price to EUR 90 (10–49 tier), calculate line total
EUR 2,250, apply the free-shipping promotion (excluding the shipping line that would otherwise
be EUR 25), show the applied tier and promotion as line annotations, and produce a quote
document with the discount breakdown visible to the customer.

---

## REQ-QOI-004: Sales orders support partial deliveries and backorders

Sales orders MUST support partial deliveries: a single order line of quantity N may be split
across multiple deliveries with quantities n1, n2, ..., until the cumulative delivered quantity
equals N or the remainder is moved to backorder or cancelled.

**SalesOrder entity fields**:
- orderNumber (string, unique sequential per administration)
- customerReference (string, FK to Customer)
- sourceQuoteReference (string, FK to Quote)
- orderDate (datetime)
- requestedDeliveryDate (date, per-order default)
- confirmedDeliveryDate (date, set by logistics after credit check passes)
- currency (ISO 4217, typically customer currency)
- status (enum: draft, confirmed, partial, shipped, invoiced, closed, cancelled)
- paymentTerms (string)
- deliveryTerms (Incoterms 2020)
- shippingAddress, billingAddress (address objects)
- creditCheckResult (string: pass, fail, warn; timestamp)
- blanketOrderFlag (boolean, if true, this is a framework-agreement call-off release)
- masterOrderReference (string, FK to BlanketOrder if blanketOrderFlag=true)

**SalesOrderLine entity fields**:
- productReference (string, FK to Product)
- orderedQuantity (number)
- deliveredQuantity (number, cumulative across all deliveries)
- invoicedQuantity (number, cumulative across all invoices)
- backorderQuantity (number, orderedQuantity - deliveredQuantity - cancelledQuantity)
- unitPrice (decimal, resolved at order confirmation time)
- discountApplied (absolute or percentage, resolved from tier + promotions)
- btwRate (decimal, 0, 6, 9, or 21 percent, per product + customer location)
- requestedDeliveryDate (date, may differ from order default)
- projectReference (string, optional FK to Project)
- performanceObligationReference (string, FK to PerformanceObligation in IFRS 15 spec)

#### Scenario: Partial shipment and backorder handling

**GIVEN** a sales order for 100 units to be delivered in two tranches of 60 and 40,
**WHEN** the warehouse ships 60 units on day 10 and creates the delivery note,
**THEN** the system MUST automatically generate an invoice for 60 units (delivery-based invoicing),
update the order line to delivered=60 / invoiced=60 / backorder=40, leave the order in status
"partial", and trigger the IFRS 15 revenue recognition for the satisfied portion of the
related performance obligation.

---

## REQ-QOI-005: Invoicing supports multiple modes: on-order, on-delivery, on-milestone, on-schedule

Invoicing MUST support multiple modes:
(a) on order confirmation (advance invoicing → deferred revenue),
(b) on delivery (most common),
(c) on milestone (project-based, future),
(d) on schedule (subscription, future),
and one or many invoices per order including consolidated monthly billing per customer.

**Invoice entity fields**:
- invoiceNumber (string, unique sequential per administatie per Dutch law, no gaps)
- invoiceDate (datetime, Dutch law requires)
- dueDate (datetime)
- grossAmount (decimal EUR, total including VAT)
- netAmount (decimal EUR, before VAT)
- vatAmount (decimal EUR, sum of line VATs)
- currency (ISO 4217, EUR)
- paymentTerms (string, e.g. "Net 30")
- status (enum: draft, sent, partially-paid, paid, overdue, cancelled, credited)
- sourceOrderReferences (array of FK to SalesOrder, may be multiple for consolidation)
- sourceDeliveryReferences (array of FK to Delivery, may be multiple)
- peppolMessageId (string, if dispatched via Peppol)
- ublXmlReference (string, FK to docudesk-stored UBL XML)

**InvoiceLine entity fields**:
- productReference (string, FK to Product)
- quantity (number)
- unitPrice (decimal)
- lineAmount (decimal, before tax)
- discountApplied (absolute or percentage)
- btwRate (decimal, 0, 6, 9, or 21 percent)
- glAccount (string, FK to Account for revenue posting)
- periodStartDate, periodEndDate (date, for service invoicing, optional)
- performanceObligationReference (string, FK to PerformanceObligation in IFRS 15 spec)

#### Scenario: Delivery-based invoicing with partial shipment

**GIVEN** a sales order for 100 units with two planned deliveries (60 + 40),
**WHEN** the warehouse ships 60 units on 2026-02-10,
**THEN** the system MUST create a delivery record (tracking Delivery), and auto-generate
an invoice for 60 units (based on the delivery, not the order). The order line updates
delivered=60, invoiced=60, backorder=40. The invoice is issued with status "draft" and
awaits salesperson confirmation before sending to customer.

---

## REQ-QOI-006: BTW is calculated per line based on customer location, product classification, and reverse-charge rules

BTW MUST be calculated per invoice line based on customer location, product BTW classification,
and reverse-charge rules, with VIES validation for intracommunautaire EU customers and automatic
0% application for validated VAT numbers; failure of VIES validation MUST raise a warning before
invoice dispatch.

**BTW calculation logic**:
- **Binnenland (NL domestic)**: apply product's standard rate (21%, 9%, 6%, 0%).
- **Intracommunautair (EU B2B)**: if customer has VAT number, validate via VIES (async, cached 24h);
  if valid, apply 0% reverse-charge; if VIES fails, raise warning, default to 0%, operator may override.
- **Derde landen (export outside EU)**: 0% if documented export; otherwise 21% (conservative).

Reverse-charge legend: "BTW verlegd / VAT reverse-charged per Article 196 VAT Directive" appears
on intracommunautair 0% lines.

#### Scenario: Intracommunautaire customer with VIES validation

**GIVEN** an intracommunautaire EU customer in Germany with VAT number DE123456789,
**WHEN** an invoice is issued,
**THEN** the system MUST validate the VAT number against VIES in real-time (async, 5-second timeout),
apply 0% BTW with reverse-charge legend on the invoice ("BTW verlegd / VAT reverse-charged per Article 196 VAT
Directive"), populate the intracommunautaire-leveringen ICP listing for the period, and dispatch the invoice
via Peppol if a Peppol identifier is registered for the customer.

---

## REQ-QOI-007: Dutch-compliant invoice numbering with no gaps, no deletion allowed

The system MUST produce a Dutch-compliant invoice numbering sequence per administatie, with no gaps,
and reject any deletion of issued invoices (only credit notes are allowed to reverse, per Belastingdienst guidance).

Invoice numbering: per administration, sequential integer starting at 1 (e.g., 2026-0001, 2026-0002, …).
If an invoice is cancelled before issue, the number is released; after issue, no deletion allowed
(audit trail remains; credit note is the reversal mechanism).

---

## REQ-QOI-008: E-invoicing produces Peppol BIS Billing 3.0 compliant UBL 2.1 XML

E-invoicing MUST produce UBL 2.1 / Peppol BIS Billing 3.0 compliant invoice XML and dispatch via
a Peppol Access Point for customers with a registered Peppol identifier; for Dutch public-sector
customers Peppol dispatch MUST be the default.

UBL XML fields per EN 16931 / NL CIUS:
- Invoice ID, Issue Date, Due Date
- Seller (issuer company), Buyer (customer)
- Invoice Lines (item identification, quantity, unit price, tax rate, tax total, line total)
- Tax breakdown (by rate)
- Payment terms, payment channel (SEPA, bank transfer, direct debit)
- Peppol identifier (scheme:ID, e.g. "0106:NL123456789B01")

---

## REQ-QOI-009: Customer-specific pricing tiers are resolved in priority order with line-level transparency

Customer-specific pricing tiers MUST resolve in priority order (customer-specific > customer-group
> product-group default > list price), with the resolved price and applied discount shown line-by-line
for transparency.

**PricingTier entity fields**:
- customerReference, customerGroupReference, productReference, productGroupReference (at least one must be set)
- tier (array: quantity breakpoint → unit price, e.g. [{quantity: 1, price: 100}, {quantity: 10, price: 90}, {quantity: 50, price: 80}])
- validFrom, validUntil (datetime)

**Resolution algorithm**:
1. Look for customer-specific tier for this product.
2. If not found, look for customer-group tier for this product.
3. If not found, look for customer-specific tier for this product-group.
4. If not found, look for customer-group tier for this product-group.
5. If not found, use product's list price.
6. Apply volume discount if active (if cumulative order quantity crosses discount threshold, recompute).
7. Store resolved unit price and tier name on order line.

---

## REQ-QOI-010: Credit-hold is triggered automatically on AR ageing thresholds and blocks orders/deliveries

Credit-hold MUST be triggered automatically when configurable AR ageing thresholds are breached
(e.g. open balance > EUR 5K and > 60 days overdue) and MUST block order confirmation or delivery
release depending on severity, with override permission auditable.

**CreditHold entity fields**:
- customerReference (string, FK to Customer)
- reason (string, e.g. "Overdue AR > 60 days")
- appliedAt (datetime)
- releasedAt (datetime, optional)
- severity (enum: warning, block-order, block-delivery)
- createdBy, releasedBy (string, FK to Person)
- releaseReason (string, optional, e.g. "Payment plan agreed")

**Credit-hold thresholds (administration-configurable)**:
- Open AR balance threshold (EUR, default 5,000)
- Days overdue threshold (integer, default 60)
- Severity mapping (e.g. balance > 5k + days > 60 → block-delivery)

#### Scenario: Credit-hold blocks order confirmation

**GIVEN** a customer with EUR 7,000 outstanding AR and the oldest invoice overdue 75 days,
**WHEN** salesperson attempts to confirm a new sales order,
**THEN** the system MUST check credit-hold thresholds, find that open balance > 5k AND days overdue > 60,
apply a credit-hold (severity: block-order), and reject the order confirmation with message "Order blocked:
customer on credit hold. Contact Credit Controller." A Credit Controller may override with audit trail
(who, when, reason, override amount).

---

## Cross-References

- **IFRS 15 integration**: SalesOrder → Contract (T3 bookkeeping-ifrs15-revenue spec)
- **AR integration**: Invoice → AR open item (T2 bookkeeping-accounts-receivable-core spec)
- **GL integration**: Invoice issued → GL posting (T1 bookkeeping-general-ledger spec); Advance invoice
  → deferred revenue posting
- **Peppol integration**: Invoice sent → Peppol dispatch (openconnector spec)
- **VIES integration**: Invoice preview → VIES validation (openconnector spec)
- **E-signature integration**: Quote acceptance → DocuSign/Signhost (openconnector spec)
- **Quote/Invoice PDF**: docudesk FK URI per bookkeeping-document-attachment-integration pattern
- **Shipping tracking**: Delivery created → openconnector shipping API (DHL, PostNL, UPS)
- **CRM sync**: Quote/Order created → openconnector CRM API (Salesforce, HubSpot, Pipedrive)

---

## Implementation Approach (per ADR-031)

- **Quote lifecycle**: Declarative `x-openregister-lifecycle` with acceptance action calling openconnector
  e-signature API.
- **SalesOrder lifecycle**: Declarative `x-openregister-lifecycle` with credit-check action.
- **Invoice lifecycle**: Declarative `x-openregister-lifecycle` with GL-posting materialisation action (T1 pattern)
  and Peppol-dispatch action.
- **Pricing resolution**: Declarative `x-openregister-aggregations` query resolving tier + discounts.
- **Credit-hold automation**: Scheduled workflow (OR ADR-031 path 2) checking AR ageing daily; state change
  to apply hold.
- **Sales-funnel KPIs**: Declarative `x-openregister-aggregations` queries.

Zero PHP service classes. All behaviour declarative per ADR-031.
