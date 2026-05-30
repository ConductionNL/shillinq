---
status: draft
---
# Sales Funnel: Quote → Order → Invoice

## Purpose

Provide the full sales funnel from a customer quote (verkoopofferte) through a confirmed sales order to one or more invoices, with status workflow, partial deliveries, partial invoicing, backorders, volume discounts, promotions, and per-customer pricing tiers. This module is the operational counterpart to the IFRS 15 revenue module: the quote is the offer, the order is the signed contract, and the invoice is the billing event. Together they form the quote-to-cash (Q2C) backbone that every SME with a real sales process needs, but that most Dutch SME bookkeeping packages (Exact, Snelstart, Moneybird, Reeleezee) treat as separate islands with weak workflow.

The module is opinionated about the difference between a quote (a non-binding offer that can be revised) and an order (a binding commitment that drives revenue recognition and inventory reservation). It supports common B2B patterns: quote with multiple versions, customer-side acceptance workflow (e-mail link, e-signature via DocuSign/Signhost, or paper return), conversion of an accepted quote into a sales order with one click, partial shipments triggering partial invoicing, backorder handling for stocked items, blanket orders (a master commitment drawn down by call-off releases over months), credit-hold based on AR ageing, and volume discounts that recompute as a customer crosses thresholds.

It also handles the Dutch market specifics: BTW correctly applied per line based on customer location (binnenland 21/9/0%, intracommunautair 0% with VIES validation, derde landen 0% export), reverse-charge constructions (verleggingsregeling), small-business scheme (Kleineondernemersregeling, KOR), Peppol e-invoicing for public-sector customers (mandated for Dutch government suppliers), and UBL 2.1 invoice format. Output integrates with the IFRS 15 module (each sales order becomes a Contract with one or more POs), accounts receivable (each invoice creates an AR open item), inventory (stock reservation and shipment), and the general ledger (revenue posting on delivery, deferred-revenue posting on advance invoicing).

## Data Model

- **Quote (Verkoopofferte)**: quote number, customer reference, version, validity period, currency, language (nl/en/de/fr), header text, footer text, payment terms, delivery terms (Incoterms), lines, subtotal, BTW per rate, total, status (draft | sent | accepted | declined | expired | converted), responsible salesperson, related opportunity reference.
- **QuoteLine**: product or service reference, description, quantity, unit price, discount (absolute or percentage), BTW rate, GL account, project reference (optional), cost-of-goods estimate.
- **SalesOrder (Verkooporder)**: order number, customer reference, source quote reference, order date, requested delivery date, confirmed delivery date, currency, status (draft | confirmed | partial | shipped | invoiced | closed | cancelled), payment terms, delivery terms, shipping address, billing address, lines, totals, credit-check result, blanket-order flag, master order reference.
- **SalesOrderLine**: product reference, ordered quantity, delivered quantity, invoiced quantity, backorder quantity, unit price, discount applied, BTW rate, requested delivery date per line, project reference, performance obligation reference (forward link to IFRS 15).
- **Delivery (Levering)**: delivery number, source sales order reference, shipped-at, carrier, tracking number, lines with quantity shipped, signed proof of delivery (POD) reference.
- **Invoice (Factuur)**: invoice number (Dutch sequential), source sales order reference(s), source delivery reference(s), invoice date, due date, currency, payment terms, lines, subtotal, BTW per rate, total, status (draft | sent | partially-paid | paid | overdue | cancelled | credited), Peppol message ID (if dispatched via Peppol), UBL XML reference.
- **InvoiceLine**: product reference, quantity, unit price, discount, BTW rate, GL account, period start/end (for service invoicing), performance-obligation reference.
- **CreditNote (Creditfactuur)**: source invoice reference, reason code, lines, total, status, automatic AR offset.
- **PricingTier**: customer or customer-group reference, product or product-group reference, tier definition (quantity breakpoint → price), validity period.
- **VolumeDiscount / Promotion**: rule (buy X get Y, percentage off above threshold, free shipping above amount), validity period, customer eligibility, automatic application flag.
- **BlanketOrder**: master commitment quantity, validity period, call-off releases as child sales orders, remaining quantity, expiry handling.
- **CreditHold**: customer reference, reason, applied-at, released-at, blocking severity (warning | block-order | block-delivery).

## Requirements

- **REQ-QOI-001** A quote MUST progress through the lifecycle draft → sent → (accepted | declined | expired), with version control allowing revisions while preserving the originally-sent version as evidence.
- **REQ-QOI-002** Quote acceptance MUST be capturable via three channels: in-app customer link (no login, signed URL token), e-signature provider integration (DocuSign, Signhost, Adobe Sign), or manual back-office mark-as-accepted with uploaded evidence.
- **REQ-QOI-003** An accepted quote MUST be convertible to a sales order in one click, copying all line data, applying the customer's current pricing tier and active volume discounts, performing a credit check, and creating the IFRS 15 Contract record.
- **REQ-QOI-004** Sales orders MUST support partial deliveries: a single order line of quantity N may be split across multiple deliveries with quantities n1, n2, ..., until the cumulative delivered quantity equals N or the remainder is moved to backorder or cancelled.
- **REQ-QOI-005** Invoicing MUST support multiple modes: on order confirmation (advance invoicing → deferred revenue), on delivery (most common), on milestone (project-based), on schedule (subscription), and one or many invoices per order including consolidated monthly billing per customer.
- **REQ-QOI-006** BTW MUST be calculated per invoice line based on customer location, product BTW classification, and reverse-charge rules, with VIES validation for intracommunautaire EU customers and automatic 0% application for validated VAT numbers; failure of VIES validation MUST raise a warning before invoice dispatch.
- **REQ-QOI-007** The system MUST produce a Dutch-compliant invoice numbering sequence per administratie, with no gaps, and reject any deletion of issued invoices (only credit notes are allowed to reverse, per Belastingdienst guidance).
- **REQ-QOI-008** E-invoicing MUST produce UBL 2.1 / Peppol BIS Billing 3.0 compliant invoice XML and dispatch via a Peppol Access Point for customers with a registered Peppol identifier; for Dutch public-sector customers Peppol dispatch MUST be the default.
- **REQ-QOI-009** Customer-specific pricing tiers MUST resolve in priority order (customer-specific > customer-group > product-group default > list price), with the resolved price and applied discount shown line-by-line for transparency.
- **REQ-QOI-010** Credit-hold MUST be triggered automatically when configurable AR ageing thresholds are breached (e.g. open balance > EUR 5K and > 60 days overdue) and MUST block order confirmation or delivery release depending on severity, with override permission auditable.

### GIVEN/WHEN/THEN scenarios

**GIVEN** a customer with a tiered pricing rule (1-9 units at EUR 100, 10-49 units at EUR 90, 50+ units at EUR 80) and an active "free shipping above EUR 1,000" promotion, **WHEN** the salesperson creates a quote for 25 units, **THEN** the system MUST resolve the unit price to EUR 90 (10-49 tier), calculate line total EUR 2,250, apply the free-shipping promotion (excluding the shipping line that would otherwise be EUR 25), show the applied tier and promotion as line annotations, and produce a quote document with the discount breakdown visible to the customer.

**GIVEN** a sales order for 100 units to be delivered in two tranches of 60 and 40, **WHEN** the warehouse ships 60 units on day 10 and creates the delivery note, **THEN** the system MUST automatically generate an invoice for 60 units (delivery-based invoicing), update the order line to delivered=60 / invoiced=60 / backorder=40, leave the order in status "partial", and trigger the IFRS 15 revenue recognition for the satisfied portion of the related performance obligation.

**GIVEN** an intracommunautaire EU customer in Germany with VAT number DE123456789, **WHEN** an invoice is issued, **THEN** the system MUST validate the VAT number against VIES in real-time, apply 0% BTW with reverse-charge legend on the invoice ("BTW verlegd / VAT reverse-charged per Article 196 VAT Directive"), populate the intracommunautaire-leveringen ICP listing for the period, and dispatch the invoice via Peppol if a Peppol identifier is registered for the customer.

## Standards & Sources

- **EU VAT Directive 2006/112/EC** (intracommunautaire, reverse-charge)
- **Wet op de Omzetbelasting 1968** (Dutch BTW law)
- **Belastingdienst** guidance on factuurvereisten (invoice requirements: 13 mandatory fields)
- **Peppol BIS Billing 3.0** and **UBL 2.1** invoice schemas
- **NL CIUS** (Core Invoice Usage Specification for Netherlands)
- **EN 16931** European e-invoicing semantic data model
- **Wet elektronische facturatie overheid** (Dutch e-invoicing for public sector)
- **Incoterms 2020** for delivery terms
- **ISO 20022** for payment references in invoices
- Competitor reference models: Exact Online (quote/order/invoice), Snelstart, Moneybird, Reeleezee, e-Boekhouden, Sage 50, QuickBooks Online (Estimate → Invoice), Xero (Quote → Invoice), Zoho Books, FreshBooks, Tally Prime, Odoo Sales, Salesforce CPQ, HubSpot Quotes, Pipedrive

## Cross-app integration

- **bookkeeping-accounts-receivable-core**: each invoice creates an AR open item; payments received clear it; ageing feeds credit-hold.
- **bookkeeping-ifrs15-revenue**: each sales order becomes a Contract; each order line maps to one or more performance obligations; recognition timing follows IFRS 15 rules independent of invoicing timing.
- **bookkeeping-general-ledger**: invoice posting (debit AR, credit revenue, credit BTW) and advance-invoice posting (debit AR, credit deferred-revenue, credit BTW).
- **bookkeeping-inventory** (future): order confirmation reserves stock; delivery decrements stock; backorder triggers replenishment.
- **bookkeeping-consultancy-project-accounting**: project-based orders link order lines to project + WBS for revenue and cost tracking.
- **bookkeeping-treasury-ihb**: open AR is a key input to the 13-week cashflow forecast.
- **openconnector**: integrations to CRM (Salesforce, HubSpot, Pipedrive) for quote sync, to Peppol Access Points (Storecove, Tradeshift) for e-invoicing, to e-signature providers, to VIES for VAT validation, to shipping providers (DHL, PostNL, UPS) for tracking.
- **docudesk**: PDF quote and invoice generation with branded templates, customer-portal hosting of documents.
- **opencatalogi** / **softwarecatalog**: product catalogue source for the QuoteLine/SalesOrderLine product references.
- **launchpad**: sales-funnel tile (quotes outstanding, conversion rate, average deal size, days-to-close, billed-vs-recognised gap).

## Target users

- **Salesperson / Account Manager** creating quotes, following up on acceptance, and converting to orders.
- **Sales Operations / Deal Desk** approving non-standard pricing, configuring volume discounts and promotions, maintaining customer pricing tiers.
- **Order Management / Customer Service** confirming orders, managing partial deliveries, handling backorders and customer enquiries.
- **Billing / AR Clerk** running periodic invoice batches, managing recurring invoices, dispatching via Peppol, handling credit notes.
- **Warehouse / Logistics** creating delivery notes, capturing proof of delivery, triggering invoices on shipment.
- **Credit Controller** monitoring credit-hold queue, releasing or escalating blocked orders, working with AR ageing.
- **CFO / Controller** consuming the billed-vs-recognised reconciliation and the sales-funnel KPIs.
- **External auditor** validating invoice sequence integrity, BTW correctness, and the cut-off between delivery and invoicing.
- **Dutch SMEs** (5-250 FTE) currently using Exact / Snelstart / Moneybird who need a real sales-order workflow rather than the simplified "direct invoicing" most SME packages provide.
- **Public-sector suppliers** required to dispatch invoices via Peppol to Dutch government buyers.
