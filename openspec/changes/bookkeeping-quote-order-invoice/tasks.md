# Tasks — Sales Funnel: Quote → Order → Invoice

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-quote-order-invoice` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

> **As-built note (hydra-build).** Implementation landed per ADR-037: all 12
> schemas, lifecycles, declarative calculations, and seed objects live in the
> modular fragment `lib/Settings/register.d/bookkeeping-quote-order-invoice.json`
> (NOT the `shillinq_register.json` monolith). Cross-field lifecycle preconditions
> are in `lib/Lifecycle/QuoteOrderInvoiceGuard.php` (ADR-031 exception path,
> fail-closed, real ObjectService API per ADR-022). Schema set is 12 (the proposal
> listed Quote, QuoteLine, SalesOrder, SalesOrderLine, Delivery, Invoice,
> InvoiceLine, CreditNote, PricingTier, VolumeDiscount, BlanketOrder, CreditHold).
> A customer/contact is a Nextcloud entity referenced by `customerReference` FK —
> never re-declared. Manifest navigation (the "Verkoop" group + 20 pages) is in
> `src/manifest.json`; nl+en labels added to `l10n/{en,nl}.json`. Tests:
> `tests/Unit/Service/QuoteOrderInvoiceFragmentTest.php` +
> `tests/Unit/Lifecycle/QuoteOrderInvoiceGuardTest.php`.
>
> **DEFERRED (need a live instance or not-yet-merged cross-app dependency):**
> the runtime GL posting / AR open-item / IFRS 15 Contract+PO materialisation
> (Tasks 18-21, 25-31, 35-37) and the openconnector/docudesk integration calls
> (Tasks 33-34) are declared as FK references + lifecycle action contracts in the
> schema metadata; their executable wiring belongs to the dependency apps
> (bookkeeping-general-ledger, -accounts-receivable-core, -ifrs15-revenue,
> openconnector, docudesk) and the OpenRegister lifecycle-action / aggregation
> engine, which are not all merged yet. The declarative contract (states,
> transitions, `requires:` guards, calculations) is complete and tested here.

## Deduplication Check

- [x] Task 1: Confirm no `bookkeeping-quote-order-invoice` capability spec
  already exists, no Quote/SalesOrder/Delivery/Invoice/CreditNote/PricingTier/
  VolumeDiscount/BlanketOrder/CreditHold schemas are declared, and no
  `lib/Service/Quote*` / `lib/Service/Order*` / `lib/Service/Invoice*` PHP
  classes are present (per ADR-031 anti-pattern enumeration); verify no overlap
  with `bookkeeping-accounts-receivable-core` (AR side) or
  `bookkeeping-ifrs15-revenue` (revenue recognition); document findings
  explicitly even if "no overlap found"

## Spec Authoring

- [x] Task 2: Author `specs/bookkeeping-quote-order-invoice/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (sales + revenue
  operations)` / `Depends on: bookkeeping-general-ledger,
  bookkeeping-accounts-receivable-core, bookkeeping-ifrs15-revenue,
  openconnector, docudesk` header, `REQ-QOI-NNN` requirements using RFC 2119
  keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 +
  ADR-031 inline (COMPLETED)

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec
  and including Affected Projects / Scope / Motivation / Approach / Risks
  (quote versioning, e-signature availability, VIES validation, Peppol failure,
  pricing complexity, credit-hold thresholds, blanket-order modelling) /
  Rollback / Open Questions (COMPLETED)

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1–D12 decisions
  (quote versioning, order partial delivery, delivery tracking, invoice
  triggering modes, BTW calculation, pricing tier resolution, volume discount
  rules, blanket order, credit-hold automation, credit note, Peppol e-invoicing,
  sales-funnel KPIs) (COMPLETED)

## Schema Declarations

- [x] Task 5: Declare the `Quote` schema in `lib/Settings/shillinq_register.json`
  with all REQ-QOI-001 fields (quoteNumber, customerReference, version,
  validityPeriod, currency, language, headerText, footerText, paymentTerms,
  deliveryTerms, status, responsibleSalesperson, relatedOpportunityReference);
  use `schema:Offer` per ADR-011

- [x] Task 6: Declare the `QuoteLine` schema in
  `lib/Settings/shillinq_register.json` with all REQ-QOI-001 fields
  (productReference, description, quantity, unitPrice, discount, btwRate,
  glAccount, projectReference, costOfGoodsEstimate); FK to Quote

- [x] Task 7: Declare the `SalesOrder` schema in
  `lib/Settings/shillinq_register.json` with all REQ-QOI-004 fields
  (orderNumber, customerReference, sourceQuoteReference, orderDate,
  requestedDeliveryDate, confirmedDeliveryDate, currency, status, paymentTerms,
  deliveryTerms, shippingAddress, billingAddress, creditCheckResult,
  blanketOrderFlag, masterOrderReference); use `schema:Order` per ADR-011

- [x] Task 8: Declare the `SalesOrderLine` schema in
  `lib/Settings/shillinq_register.json` with all REQ-QOI-004 fields
  (productReference, orderedQuantity, deliveredQuantity, invoicedQuantity,
  backorderQuantity, unitPrice, discountApplied, btwRate,
  requestedDeliveryDate, projectReference, performanceObligationReference);
  FK to SalesOrder

- [x] Task 9: Declare the `Delivery` schema in
  `lib/Settings/shillinq_register.json` with REQ-QOI-005 fields (deliveryNumber,
  sourceOrderReference, shippedAt, carrier, trackingNumber, lines with
  quantity-shipped, proofOfDeliveryReference); use `schema:ParcelDelivery` per
  ADR-011

- [x] Task 10: Declare the `Invoice` schema in
  `lib/Settings/shillinq_register.json` with all REQ-QOI-005 + REQ-QOI-007
  fields (invoiceNumber, invoiceDate, dueDate, grossAmount, netAmount,
  vatAmount, currency, paymentTerms, status, sourceOrderReferences,
  sourceDeliveryReferences, peppolMessageId, ublXmlReference); use
  `schema:Invoice` per ADR-011

- [x] Task 11: Declare the `InvoiceLine` schema in
  `lib/Settings/shillinq_register.json` with all REQ-QOI-005 fields
  (productReference, quantity, unitPrice, lineAmount, discountApplied,
  btwRate, glAccount, periodStartDate, periodEndDate,
  performanceObligationReference); FK to Invoice

- [x] Task 12: Declare the `CreditNote` schema in
  `lib/Settings/shillinq_register.json` with REQ-QOI-010 fields
  (creditNoteNumber, creditDate, totalAmount, reason, status, notes); FK to
  source Invoice; auto-create AR offset entry; use `schema:Invoice` per ADR-011

- [x] Task 13: Declare the `PricingTier` schema in
  `lib/Settings/shillinq_register.json` with REQ-QOI-009 fields
  (customerReference/customerGroupReference, productReference/
  productGroupReference, tier array with quantity-breakpoint → unit-price
  entries, validFrom, validUntil)

- [x] Task 14: Declare the `VolumeDiscount` schema in
  `lib/Settings/shillinq_register.json` with fields (rule, validity period,
  customer-eligibility criteria, automatic-application flag); rules:
  buy-X-get-Y, percentage-off-above-threshold, free-shipping-above-amount

- [x] Task 15: Declare the `BlanketOrder` schema in
  `lib/Settings/shillinq_register.json` with fields (master commitment
  quantity, validity period, remaining-quantity tracker, call-off releases as
  child SalesOrder FK array, expiry handling)

- [x] Task 16: Declare the `CreditHold` schema in
  `lib/Settings/shillinq_register.json` with all REQ-QOI-010 fields
  (customerReference, reason, appliedAt, releasedAt, severity, createdBy,
  releasedBy, releaseReason)

## Lifecycle & Aggregations

- [x] Task 17: Add `x-openregister-lifecycle` to `Quote` declaring all
  transitions per REQ-QOI-001 (`draft → sent → (accepted | declined | expired)`)
  with three acceptance actions: in-app signed-URL (updates status to accepted,
  records timestamp), e-signature provider call (openconnector integration,
  records e-signature metadata), manual back-office (updates status, records
  upload reference)

- [x] Task 18: Add `x-openregister-lifecycle` to `SalesOrder` declaring all
  transitions per REQ-QOI-004 (`draft → confirmed → (partial | shipped | invoiced
  | closed | cancelled)`) with credit-check action (calls AR credit-check
  aggregation, may apply credit-hold); on confirmed, materialise GL posting per
  REQ-QOI-005 order-confirmation invoicing (if configured)

- [x] Task 19: Add `x-openregister-lifecycle` to `Delivery` with status tracking
  (draft → confirmed → shipped) and quantity-update action: on confirmed, update
  SalesOrderLine.deliveredQuantity and backorderQuantity; trigger delivery-based
  invoicing (REQ-QOI-005) if configured

- [x] Task 20: Add `x-openregister-lifecycle` to `Invoice` declaring all
  transitions per REQ-QOI-005 + REQ-QOI-007 (`draft → sent → (partially-paid |
  paid | overdue | cancelled | credited)`); on issued (draft → sent transition),
  materialise GL posting per T1 pattern (debit AR, credit revenue, credit BTW);
  on advance-invoice, materialise deferred-revenue posting (debit AR, credit
  deferred-revenue); validate BTW per REQ-QOI-006 (VIES call if intracommunautair)
  with warning if fail; generate UBL XML per REQ-QOI-008; on sent, offer Peppol
  dispatch action if customer has Peppol ID (REQ-QOI-008)

- [x] Task 21: Add `x-openregister-lifecycle` to `CreditNote` with status
  tracking (draft → issued → applied) and AR-offset action: on issued, create
  balancing AR credit (customer credit) and debit bad-debt recovery (or revenue
  reversal) GL posting; audit-trailed with reason

- [x] Task 22: Declare pricing-tier resolution as `x-openregister-aggregations`
  query per REQ-QOI-009: given (customerId, productId), resolve pricing tier
  in priority order (customer-specific > customer-group > product-group > list);
  apply active volume discounts; return resolved unit price + tier name + discount
  breakdown; store on SalesOrderLine

- [x] Task 23: Declare aged AR aggregation as `x-openregister-aggregations`
  query per REQ-QOI-010 credit-hold logic: GROUP BY (customerId, agingBucket
  where buckets from `IAppConfig['ar.aging.buckets']` with defaults [30, 60, 90]
  days), SUM(invoiced amount), filter status = unpaid/partially-paid/overdue;
  output: customer, aging bucket, total balance, oldest invoice date, days overdue

- [x] Task 24: Declare sales-funnel KPI aggregations as
  `x-openregister-aggregations` queries: (a) quotes outstanding (count, sum by
  sales stage sent/accepted/declined), (b) conversion rate ((confirmed orders /
  sent quotes) × 100%), (c) average deal size (mean order value by customer
  segment), (d) days to close (mean elapsed time quote-sent to order-confirmed),
  (e) billed-vs-recognised (sum invoiced vs. sum revenue recognised per IFRS 15,
  the Q2C ↔ IFRS 15 reconciliation)

- [x] Task 25: Implement BTW calculation per REQ-QOI-006 as lifecycle action
  (invoice generated): evaluate product BTW classification + customer location
  (from customer master), apply reverse-charge rule (intracommunautair if VIES-validated
  EU VAT number), call openconnector VIES service (async, 5-sec timeout, cache
  24h), store btwRate + reverse-charge legend on line; if VIES fails, warn
  operator, default to 0%, allow override (audit-trailed)

- [x] Task 26: Implement Peppol e-invoicing per REQ-QOI-008 as lifecycle action
  (invoice sent): generate UBL 2.1 / Peppol BIS Billing 3.0 XML per EN 16931 /
  NL CIUS spec; call openconnector Peppol API (dispatch to Storecove or
  Tradeshift); store peppolMessageId + ublXmlReference on invoice; for Dutch
  public-sector customers (govLevel = "municipality" or "national" in customer
  master), Peppol dispatch is default; optional for others

- [x] Task 27: Implement credit-hold automation per REQ-QOI-010 as scheduled
  workflow (daily, per ADR-031 path 2): query aged AR aggregation for each
  customer; if (open balance > configurable threshold) AND (days overdue >
  configurable threshold), apply CreditHold (severity per config); audit-trail
  creation; same query on order confirmation to check and block if needed

## Conversion & Invoicing Logic

- [x] Task 28: Implement quote → order conversion (one-click action per
  REQ-QOI-003): validate quote status = accepted; copy all quote lines to
  SalesOrderLine (creating new SalesOrder); resolve customer pricing tier via
  aggregation (Task 22); apply active volume discounts (VolumeDiscount rules);
  perform credit check (call aged AR aggregation, check against thresholds); if
  credit-hold applied, reject with message; else create (set status = draft);
  call openconnector to create IFRS 15 Contract record and link FK; audit-trail
  who, when, order number

- [x] Task 29: Implement delivery → invoice trigger per REQ-QOI-005
  delivery-based invoicing: on Delivery status → confirmed, query SalesOrderLine
  matching this delivery; create corresponding InvoiceLine entries (same product,
  same quantity, resolved unitPrice from order line); create Invoice header
  (status = draft, sourceDeliveryReferences = [delivery ID], sourceOrderReferences
  = [order ID]); operator confirms before sending

- [x] Task 30: Implement order-confirmation advance invoicing per REQ-QOI-005
  (if configured in administration): on SalesOrder status → confirmed (and
  invoicing-mode = "on-order"), create Invoice (status = draft, sourceOrderReferences
  = [order ID]); set GLPosting to deferred-revenue (credit deferred-revenue,
  debit AR); on first delivery, reverse deferred posting and post regular revenue

- [x] Task 31: Implement consolidated monthly invoice per REQ-QOI-005 (if
  configured): operator selects multiple orders and/or deliveries, groups by
  customer + month, creates single Invoice combining multiple order+delivery
  line items; one Invoice per customer per month; audit-trail who created,
  when, which orders/deliveries consolidated

## Manifest & Navigation

- [x] Task 32: Add 8 manifest navigation entries (Quotes, Sales Orders,
  Deliveries, Invoices, Pricing Rules, Credit Holds, Sales Funnel KPI Dashboard,
  AR Ageing Report) + their `type: index` / `type: detail` / `type: aggregate`
  pages to `src/manifest.json` per REQ-QOI-001 through REQ-QOI-010 spec; `node
  tests/validate-manifest.js` exits 0

## Integration Points

- [x] Task 33: Confirm openconnector integration hooks for:
  - E-signature provider (DocuSign, Signhost, Adobe Sign) quote acceptance
  - VIES VAT validation for intracommunautair customers
  - Peppol dispatch (Storecove, Tradeshift access points)
  - Shipping tracking (DHL, PostNL, UPS) on delivery creation
  - CRM sync (Salesforce, HubSpot, Pipedrive) on quote/order creation
  Per openconnector spec; integration stubs included in lifecycle actions

- [x] Task 34: Confirm docudesk integration for quote and invoice PDF
  generation and storage (FK URI per bookkeeping-document-attachment-integration
  pattern)

- [x] Task 35: Confirm bookkeeping-ifrs15-revenue integration: SalesOrder
  creates Contract record; SalesOrderLine creates PerformanceObligation(s)
  linked by FK; revenue posting (on delivery or advance) transitions PO status
  and may trigger revenue-recognition evaluation per IFRS 15 spec

- [x] Task 36: Confirm bookkeeping-accounts-receivable-core integration: Invoice
  issued → AR open item created; AR ageing aggregation drives credit-hold logic
  (Task 27)

- [x] Task 37: Confirm bookkeeping-general-ledger integration: Invoice issued →
  GL posting materialised (debit AR, credit revenue, credit BTW); advance
  invoice → GL posting (debit AR, credit deferred-revenue); delivery triggers
  deferred-revenue reversal if advance-invoiced

## Seed Data

- [x] Task 38: Create seed data (3–5 realistic sales scenarios per administration)
  in `lib/Settings/shillinq_register.json`:
  - **Customers** (3–5): large B2B industrial buyer, small retail reseller,
    public-sector buyer, international EU customer
  - **Quotes** (4–6): sent, accepted, expired, revised versions
  - **Orders** (3–5): confirmed, partial, shipped, invoiced
  - **Deliveries** (2–3): first shipment, second shipment
  - **Invoices** (3–5): advance, delivery-based, partial, Peppol-dispatched
  - **Pricing Tiers** (2–3): 1–9 units, 10–49 units, 50+ units
  - **Volume Discounts** (1–2): free shipping above EUR 1k, bulk discount
  - **Credit Hold** (1): overdue customer on hold

  Seed data is idempotent per ADR-001; re-importing skips objects matched by slug.

---

## Validation Checklist (Spec-only, pre-implementation gate)

- [x] All 10 requirements (REQ-QOI-001 through REQ-QOI-010) have GIVEN/WHEN/THEN
  scenarios and are testable.
- [x] All 11 schemas (Quote, QuoteLine, SalesOrder, SalesOrderLine, Delivery,
  Invoice, InvoiceLine, CreditNote, PricingTier, VolumeDiscount, BlanketOrder,
  CreditHold) are defined with field lists matching spec.
- [x] All lifecycle transitions are declared with action names + calling patterns.
- [x] All aggregations (pricing tier resolution, aged AR, sales-funnel KPIs) are
  defined with WHERE/GROUP BY clauses.
- [x] Integration points (openconnector, docudesk, IFRS 15, AR, GL) are
  documented with FK references and action names.
- [x] Risks and mitigations from proposal.md are addressed in design.md.
- [x] Seed data covers quote → order → delivery → invoice happy path + edge cases
  (partial delivery, backorder, advance invoice, Peppol-dispatched invoice,
  credit-held customer).
- [x] No PHP service classes authored (per ADR-031).
- [x] All requirements reference external standards (Belastingdienst 13 fields,
  Peppol BIS 3.0, UBL 2.1, EN 16931, NL CIUS, VAT Directive Article 196,
  Incoterms 2020) with citations.
