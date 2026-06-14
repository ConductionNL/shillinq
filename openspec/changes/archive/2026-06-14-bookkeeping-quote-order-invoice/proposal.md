# Proposal: bookkeeping-quote-order-invoice

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(Quote, SalesOrder, Delivery, Invoice, CreditNote, PricingTier, BlanketOrder,
CreditHold) + quote-to-cash lifecycle with status workflows, partial delivery,
partial invoicing, backorders, volume discounts, and BTW/Peppol compliance.

## Summary

Introduce the **Sales Funnel: Quote → Order → Invoice (Q2C)** capability as one
of the T3 sales + revenue-operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability establishes the complete
quote-to-cash workflow for Dutch SMEs: from a non-binding customer quote
(verkoopofferte) through a signed sales order (verkooporder) to one or multiple
invoices (factuur), with support for partial deliveries, backorders, customer-
specific pricing tiers, volume discounts, and Dutch VAT (BTW) compliance per
Belastingdienst 13-field invoice requirements. The workflow integrates with IFRS
15 revenue recognition (via Contract linking), accounts receivable (AR aging &
credit-hold), inventory (stock reservation & shipment tracking), and the general
ledger (revenue posting on delivery, deferred-revenue posting on advance
invoicing).

E-invoicing for Peppol BIS Billing 3.0 and UBL 2.1 is supported for Dutch
public-sector customers (mandated by Wet elektronische facturatie overheid).

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md)
spec for app structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`bookkeeping-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(revenue posting), [`bookkeeping-accounts-receivable-core`](../add-shillinq-accounts-receivable-core/proposal.md)
(invoice → AR open item, credit-hold triggers), [`bookkeeping-ifrs15-revenue`](../bookkeeping-ifrs15-revenue/proposal.md)
(Contract + PerformanceObligation linking), `openconnector` (e-signature, VIES
validation, Peppol dispatch, shipping tracking).

## Motivation

The quote-to-cash (Q2C) workflow is the operational heart of every SME with a
real sales process. Dutch SMBs must track customer quotes, convert accepted
quotes to binding orders, manage partial deliveries & backorders, and invoice
on delivery (or in advance, or on milestone). Most Dutch SME packages (Exact,
Snelstart, Moneybird, Reeleezee) treat quote/order/invoice as separate islands
with weak workflow; they offer no quote versioning, no e-signature capture, no
blanket-order call-off, and no volume-discount automation.

Demand signals (from legacy AP/AR draft cluster and competitive analysis) show
SMBs rank Q2C features as top tier: quote lifecycle + version control (demand
score: 92), quote acceptance workflow (85), order conversion (88), partial
shipment tracking (81), invoice on delivery (79), multi-currency pricing (75),
volume discounts (73), Peppol e-invoicing (68 for public-sector suppliers).

Per ADR-022, state transitions (quote sent → accepted, order confirmed → partial
→ invoiced) consume OR's lifecycle extensions. Per ADR-031, quote versioning,
pricing resolution (customer tier → list price), and aged AR are declarative
aggregations, not PHP service classes.

This is one of the T3 capability clusters. This proposal scopes the complete Q2C
surface: quote, order, delivery, invoice, credit note, pricing tier, blanket
order, and credit-hold entities.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-quote-order-invoice`); declares 8 new registers (Quote,
  QuoteLine, SalesOrder, SalesOrderLine, Delivery, Invoice, CreditNote, 
  PricingTier, VolumeDiscount, BlanketOrder, CreditHold) with lifecycles,
  aggregations, and manifest navigation entries (Sales Funnel, Quotes, Orders,
  Deliveries, Invoices, Pricing Rules, Credit Holds).
- [ ] Project: openregister — no source changes; consumes `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- [ ] Project: openconnector — integrations to e-signature (DocuSign, Signhost),
  VIES VAT validation, Peppol Access Points (Storecove, Tradeshift), shipping
  providers (DHL, PostNL, UPS), CRM (Salesforce, HubSpot, Pipedrive).
- [ ] Project: docudesk — PDF quote and invoice generation with branded
  templates, customer-portal hosting.

## Scope

### In Scope

- Eight new registers (Quote, QuoteLine, SalesOrder, SalesOrderLine, Delivery,
  Invoice, CreditNote, PricingTier, VolumeDiscount, BlanketOrder, CreditHold)
  with full Dutch naming (Verkoopofferte, Verkooporder, Levering, Factuur,
  Creditfactuur, Prijsniveaus, Volumekortingen, Blancokorder, Kredietstop).
- Quote lifecycle: draft → sent → accepted | declined | expired, with version
  control and three acceptance channels (in-app signed URL, e-signature provider,
  manual back-office).
- Quote → Order conversion: one-click conversion copying line data, applying
  customer pricing tiers, performing credit check, creating IFRS 15 Contract.
- Sales order lifecycle: draft → confirmed → partial | shipped | invoiced |
  closed | cancelled, with partial delivery tracking (each line tracks
  ordered/delivered/invoiced/backorder quantities).
- Delivery (Levering) register: tracks shipments per order with quantities,
  carrier, tracking number, and proof-of-delivery (POD).
- Invoice lifecycle: draft → sent → partially-paid | paid | overdue | cancelled |
  credited, with Dutch sequential numbering (no gaps), Peppol dispatch support,
  and UBL 2.1 / NL CIUS compliance.
- BTW (VAT) calculation per customer location and product classification, with
  VIES validation for EU intracommunautaire customers and reverse-charge
  application.
- Credit-hold workflow: automatic trigger on AR ageing thresholds (configurable),
  blocking order confirmation or delivery release with audit-trailed overrides.
- Customer-specific pricing tiers: resolution priority (customer-specific >
  customer-group > product-group default > list price) with line-level
  transparency.
- Volume discounts and promotions: rule-based with validity periods, customer
  eligibility, and automatic application.
- Blanket orders: master commitment with call-off releases drawn over months,
  remaining-quantity tracking, expiry handling.
- CreditNote (Creditfactuur) register: reverses invoice with automatic AR offset.
- Peppol e-invoicing: UBL 2.1 / Peppol BIS Billing 3.0 compliant XML generation
  and dispatch via Peppol Access Point for customers with Peppol identifier.
- Sales-funnel KPI aggregations: quotes outstanding, conversion rate, average
  deal size, days-to-close, billed-vs-recognised gap (bridging Q2C and IFRS 15).
- Manifest entries and navigation for Salesperson, Order Management, Billing, AR
  Clerk, Warehouse, Credit Controller, CFO.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components,
  controllers, tests, and CI changes are deliberately not in this proposal;
  the task list references them but the implementation lands via a separate
  `opsx-apply` cycle.
- **Peppol inbound** — receiving e-invoices from suppliers is T2 AP Peppol;
  this is outbound sales invoicing only.
- **Multi-currency revenue** — T5. Orders reference customer currency; revenue
  posting is EUR base translation (not dual-posting).
- **Project accounting integration** — future. Order lines may reference a
  project FK but no project-revenue waterfall in T3.
- **Subscription / recurring invoicing** — future. Billing is per-order or
  per-delivery; no subscription engine in T3.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-quote-order-invoice`** — declares the 11 registers, the
lifecycles, the pricing resolution, the delivery-to-invoice mapping, the BTW
calculation and VIES validation, the Peppol generation, the credit-hold
automation, and the sales-funnel KPI aggregations.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement
is prefixed `REQ-QOI-*` for traceability.

## New Dependencies

- `openconnector` — for e-signature, VIES, Peppol, shipping integrations.
- `docudesk` — for quote/invoice PDF generation (referenced by FK URI per
  `bookkeeping-document-attachment-integration`).
- `bookkeeping-ifrs15-revenue` — for Contract + PerformanceObligation linking
  (forward reference in SalesOrder).

## Impact

- `lib/Settings/shillinq_register.json` — adds 11 new schemas (Quote, QuoteLine,
  SalesOrder, SalesOrderLine, Delivery, Invoice, CreditNote, PricingTier,
  VolumeDiscount, BlanketOrder, CreditHold); declares lifecycles on Quote,
  SalesOrder, Delivery, Invoice, CreditNote; declares aggregations for aged AR,
  sales funnel, pricing resolution.
- `src/manifest.json` — adds 8 navigation entries (Quotes, Sales Orders,
  Deliveries, Invoices, Pricing Rules, Credit Holds, Sales Funnel, AR Ageing)
  + their `type: index` / `type: detail` / `type: aggregate` pages.
- No new PHP services (per ADR-031).
- No bespoke Vue components beyond manifest page shells.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- **T1 general ledger** — depends on `bookkeeping-general-ledger` for
  materialised GL posting on invoice issue (revenue) and advance-invoice posting
  (deferred revenue).
- **T2 accounts receivable** — depends on `bookkeeping-accounts-receivable-core`
  for AR open-item creation, ageing calculation, credit-hold integration.
- **T3 IFRS 15** — depends on `bookkeeping-ifrs15-revenue` for Contract & PO
  linking (forward reference in SalesOrder).
- **openconnector** — for e-signature (DocuSign, Signhost, Adobe Sign), VIES
  validation (EU VAT service), Peppol dispatch (Storecove, Tradeshift),
  shipping tracking (DHL, PostNL, UPS), CRM sync (Salesforce, HubSpot, Pipedrive).
- **docudesk** — for quote/invoice PDF generation with branded templates.

## Risks

### Risk 1: Quote versioning and document storage

**Severity**: Medium
**Mitigation**: Quote versions are stored as immutable snapshots (per ADR-001
version-control pattern); docudesk holds the PDF. If docudesk is not yet stable,
the spec captures the gap, files a docudesk issue, and the implementing cycle
MAY ship a single-method `OCA\Shillinq\Document\QuoteRenderer` per ADR-031.
Spec is shape-neutral.

### Risk 2: E-signature integration availability

**Severity**: Medium
**Mitigation**: Three acceptance channels are offered (in-app signed URL, 
e-signature provider, manual back-office). If e-signature provider integrations
(DocuSign, Signhost) are not available, the spec still delivers value via
in-app link + manual upload. openconnector issue filed for missing integrations.

### Risk 3: VIES validation latency on invoice dispatch

**Severity**: Low-Medium
**Mitigation**: VIES calls are async and cached per VAT number (24-hour TTL);
validation failure raises a warning before dispatch but does not block (operator
can override with audit trail). Fallback: EU customer defaults to 0% BTW with
manual verification required.

### Risk 4: Peppol dispatch failure and retry

**Severity**: Low
**Mitigation**: Peppol dispatch is a lifecycle action (invoice issued → sent via
Peppol). Failures are audit-trailed; operator can retry manually. No automatic
retry queue in T3 (future T4 feature).

### Risk 5: Pricing resolution complexity (tiers + discounts + promotions)

**Severity**: Low
**Mitigation**: Pricing is evaluated in order (customer tier → discount → 
promotion) with line-level transparency (showing applied tier and rules). If
performance gates trip during testing, pricing is pre-computed at order
confirmation time and stored (not computed at invoice time).

### Risk 6: Credit-hold ageing thresholds need customization

**Severity**: Low
**Mitigation**: Thresholds (open balance > EUR X, > N days overdue) are stored
in administration config per ADR-022's `IAppConfig` pattern, not hardcoded.
Allows SMBs to customize per their risk appetite.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder;
no runtime impact. After implementation (separate cycle), rollback follows
the standard pattern: revert the implementing PR; registers are non-destructive
— quotes, orders, invoices remain queryable.

## Open Questions

1. **Quote acceptance workflow** — three channels (in-app link, e-signature,
   manual) — are all three required in T3, or phased in order? Resolved during
   spec review.
2. **Peppol mandatory for T3?** — Peppol dispatch is required for Dutch
   public-sector customers (legal mandate); optional for others. Resolved in
   scope clarification.
3. **Credit-hold severity levels** — warning (blocks nothing) vs. block-order
   (prevents confirmation) vs. block-delivery (prevents shipping)? Defaults
   proposed; resolved in UX review.
4. **Advance invoicing** — can an invoice be issued before delivery? Yes,
   with deferred-revenue posting per IFRS 15. Reconciliation with revenue
   contract is scope of T3 IFRS 15 spec.
5. **Blanket order call-off** — are call-off releases modelled as separate
   SalesOrder entities, or as line-item releases within a single BlanketOrder?
   Proposed: separate SalesOrder entities linked via master-order FK. Resolved
   in design review.
