# Design — Sales Funnel: Quote → Order → Invoice

## Context

The quote-to-cash (Q2C) workflow is the operational heart of Dutch SMEs with a
real sales process. Most legacy SME packages treat quote/order/invoice as
separate islands with weak workflow. This spec unifies them into a connected
workflow: quote (non-binding offer) → order (binding commitment) → delivery
(shipment) → invoice (billing event), with status transitions, partial delivery,
backorder handling, customer-specific pricing, volume discounts, BTW compliance,
Peppol e-invoicing, credit-hold automation, and integration with IFRS 15
revenue recognition.

Demand signals show SMBs rank Q2C features as top tier (demand scores: 92 for
quote lifecycle, 88 for order conversion, 79 for invoice on delivery, 73 for
volume discounts, 68 for Peppol e-invoicing).

Per ADR-022, state transitions consume OR's lifecycle extensions. Per ADR-031,
pricing resolution (tier → discount → promotion), aged AR calculation, and
sales-funnel KPIs are declarative aggregations, not PHP report services.

The change is **spec-only**. Implementation lands later through `opsx-apply` and
the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire Q2C surface as **declarative metadata** — schemas + 
  lifecycles + aggregations + manifest entries — per ADR-031.
- Unify the quote → order → delivery → invoice workflow with versioning,
  acceptance channels, credit-hold automation, and status transparency.
- Support Dutch VAT (BTW) compliance per Belastingdienst 13-field requirements,
  VIES validation for EU customers, and Peppol BIS 3.0 e-invoicing for
  public-sector customers.
- Deliver **customer-specific pricing tiers, volume discounts, and promotional
  rules** with automatic application and line-level transparency.
- Bridge Q2C workflow with IFRS 15 revenue recognition (Contract → PO linking)
  and AR ageing (credit-hold automation).
- Make the spec a **Dutch SME bookkeeper readable contract** — quote → order →
  shipment → invoice flow recognisable end-to-end.

## Non-Goals

- No PHP Q2C service classes (`QuoteService`, `OrderService`, `InvoiceService`).
- No Peppol inbound e-invoicing — future AP capability. Outbound only.
- No multi-currency revenue posting — T5. Orders reference customer currency;
  GL posting is EUR base-translated.
- No subscription/recurring invoicing — future. Billing is per-order or
  per-delivery.
- No project-accounting integration in T3 (future). Order lines may reference
  project FK but no project-revenue waterfall.

## Decisions

### D1 — Quote is a versioned non-binding offer with multi-channel acceptance

Quote versioning allows the salesperson to revise a quote while preserving the
originally-sent version as evidence. Three acceptance channels are offered:
(a) in-app signed URL (no login, token-based), (b) e-signature provider
integration (DocuSign, Signhost, Adobe Sign), (c) manual back-office
mark-as-accepted with uploaded evidence. This flexibility addresses market
demand (customer-portal, e-signature, and legacy paper workflows).

Quote lifecycle: `draft → sent → (accepted | declined | expired)`. Conversion
to order happens in one click, copying all line data, applying customer's
current pricing tier and active volume discounts, performing a credit check,
and creating the IFRS 15 Contract record.

### D2 — SalesOrder is a binding commitment with partial delivery and backorder support

SalesOrder lifecycle: `draft → confirmed → (partial | shipped | invoiced |
closed | cancelled)`. Each order line tracks four quantities: ordered,
delivered, invoiced, backorder. Partial deliveries trigger partial invoicing
(delivery line → invoice line, same quantity). Backorders are retained on the
order and trigger replenishment workflows (future T4 inventory integration).

### D3 — Delivery (Levering) is a shipment record linking order to invoice

Delivery register tracks shipments per SalesOrder: delivery number, shipped-at
date, carrier, tracking number, line quantities, and proof-of-delivery (POD)
reference. When a delivery is confirmed, the order line's `deliveredQuantity`
is incremented; if this equals `orderedQuantity`, the line moves to "shipped"
status. Delivery is the trigger for delivery-based invoicing (most common
workflow).

### D4 — Invoice lifecycle supports multiple triggering modes: on-order, on-delivery, on-milestone

Invoice can be issued: (a) on order confirmation (advance invoicing, triggers
deferred-revenue posting per IFRS 15), (b) on delivery (most common, revenue
posting on shipment), (c) on milestone (project-based, future), (d) on
schedule (subscription, future). An order may generate one or many invoices
(e.g., consolidated monthly billing per customer).

Invoice lifecycle: `draft → sent → (partially-paid | paid | overdue | cancelled
| credited)`. Dutch sequential numbering (no gaps); no deletion allowed
(Belastingdienst guidance), only credit notes for reversal.

### D5 — BTW (VAT) is calculated per line based on customer location, product classification, and reverse-charge rules

BTW calculation per line:
- **Binnenland (domestic)**: 21% standard, 9% reduced, 0% zero-rated.
- **Intracommunautair (EU)**: 0% on B2B if VIES-validated VAT number present
  (reverse-charge per Article 196 VAT Directive); warning if VIES fails.
- **Derde landen (export)**: 0% if documented export (future T4).

VIES validation is async and cached (24-hour TTL). Validation failure raises
a warning on invoice preview; operator can override with audit trail. Lines
show applied BTW rate and reverse-charge legend (if applicable).

### D6 — PricingTier resolution follows priority order with line-level transparency

Customer-specific pricing tiers and volume discounts are evaluated in order:
(1) customer-specific product pricing, (2) customer-group product pricing,
(3) product-group default pricing, (4) list price. Discounts (absolute or
percentage) are applied in sequence (tier discount → promotional discount),
recalculated if customer crosses quantity thresholds during an order or over
a billing period.

Each order line shows: resolved unit price, tier name + discount breakdown,
promotional rules applied, and final line total. This transparency supports
customer disputes and sales follow-up.

### D7 — VolumeDiscount and Promotion rules are time-bounded and customer-eligible

Volume discounts and promotions are rule objects: buy X units get Y% off,
percentage off above threshold, free shipping above amount, etc. Each rule
has: validity period, customer-eligibility criteria (all customers, specific
customer list, customer-group), automatic-application flag. Rules are evaluated
at quote generation and order confirmation; recomputed at invoice time if
customer balance crosses thresholds (e.g., cumulative order volume for the
month).

### D8 — BlanketOrder is a master commitment with call-off releases

BlanketOrder (Blancokorder) is a master commitment: total authorized quantity,
validity period, and child SalesOrder entities (call-off releases) drawn
against it over months. BlanketOrder tracks remaining-quantity; as call-offs
are confirmed, remaining decrements. On expiry, remaining quantity is voided or
rolled over per customer agreement.

### D9 — CreditHold is automated and audit-trailed

CreditHold is triggered automatically when AR ageing thresholds are breached
(e.g., open AR balance > EUR 5,000 AND most-overdue invoice > 60 days). Severity
levels: warning (no blocking), block-order (prevents order confirmation),
block-delivery (prevents shipment release). Thresholds and severity are
administration-configurable per ADR-022. Overrides are logged (who, when, reason,
amount).

CreditHold is also manually liftable by Credit Controller after negotiation
with customer (e.g., payment plan agreed, or disputed invoice resolved).

### D10 — CreditNote reverses invoice with automatic AR offset

CreditNote (Creditfactuur) is issued to reduce customer debt due to returns,
price disputes, or quality issues. CreditNote references source invoice,
includes reason code, and automatically offsets the AR open item (customer
credit balance). CreditNote numbering follows Dutch sequential rules
(Belastingdienst). No deletion allowed.

### D11 — Peppol e-invoicing is declarative and opt-in per customer

Peppol dispatch is a lifecycle action: `invoice sent → sent via Peppol`. UBL 2.1
/ Peppol BIS Billing 3.0 compliant invoice XML is generated; Peppol message ID
is stored. Dispatch succeeds if customer has registered Peppol identifier
(scheme + ID). For Dutch public-sector customers, Peppol dispatch is the
default (Wet elektronische facturatie overheid mandate); optional for others.

Dispatch failures are audit-trailed; operator can retry. No automatic retry
queue in T3 (future T4).

### D12 — Sales-funnel KPIs are declarative aggregations

Aggregations (not PHP report services per ADR-031):
- **Quotes outstanding**: count and sum by sales stage (sent, awaiting response,
  overdue for response).
- **Conversion rate**: (orders confirmed / quotes sent) × 100%.
- **Average deal size**: mean and median order value, grouped by customer segment.
- **Days to close**: mean elapsed time from quote sent to order confirmed.
- **Billed vs. recognised**: sum(invoiced amount) vs. sum(revenue recognised per
  IFRS 15) — the Q2C ↔ IFRS 15 reconciliation.

All aggregations use OR's `x-openregister-aggregations` extension with WHERE
clauses filtering by date range, customer, and status.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Quote lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on Quote (`draft → sent → (accepted \| declined \| expired)`) |
| Order lifecycle + partial delivery | OR `x-openregister-lifecycle` | Lifecycle on SalesOrder (`draft → confirmed → (partial \| shipped \| invoiced \| closed \| cancelled)`) tracking ordered/delivered/invoiced/backorder quantities per line |
| Delivery tracking | OR `x-openregister-lifecycle` | Delivery register with FK to SalesOrder; no separate lifecycle (issue → confirm → close) |
| Invoice lifecycle + BTW | T2 AP (`bookkeeping-accounts-payable-core`) pattern | Lifecycle on Invoice (`draft → sent → (partially-paid \| paid \| overdue \| cancelled \| credited)`); BTW per line calculated per product + customer location + reverse-charge rules |
| GL posting on invoice issue | T1 `JournalEntry` materialisation | Same lifecycle action shape: invoice issued → materialise balanced GL posting (debit AR, credit revenue, credit BTW) |
| GL posting on advance invoice (deferred revenue) | T1 `JournalEntry` materialisation | Advance invoice issued → materialise (debit AR, credit deferred-revenue, credit BTW); reversed on delivery |
| AR ageing + credit-hold | T2 AR (`bookkeeping-accounts-receivable-core`) + aggregations | AR open item created on invoice issue; credit-hold triggered by ageing thresholds; aggregations for AR age buckets |
| Pricing tier resolution | OR `x-openregister-aggregations` | Aggregation query: resolve customer pricing tier; apply volume discounts; return resolved unit price + tier name |
| Sales-funnel KPIs | OR `x-openregister-aggregations` | Aggregations: quotes outstanding, conversion rate, avg deal size, days-to-close, billed-vs-recognised |
| VIES validation | `openconnector` integrations | Call openconnector VIES service (async, cached 24h) on invoice preview; warning if fail |
| E-signature acceptance | `openconnector` integrations + docudesk | Call openconnector e-signature API (DocuSign, Signhost, Adobe) on quote acceptance request; docudesk hosts signed copy |
| Peppol dispatch | `openconnector` integrations | Call openconnector Peppol API (Storecove, Tradeshift) on invoice sent lifecycle action |
| Quote/Invoice PDF generation | `docudesk` | FK URI to docudesk PDF per `bookkeeping-document-attachment-integration` pattern |
| Contract + PO linking (IFRS 15) | T3 `bookkeeping-ifrs15-revenue` | FK from SalesOrder to Contract; SalesOrder lines FK to PerformanceObligation |
| Shipping tracking | `openconnector` integrations | Call openconnector shipping API (DHL, PostNL, UPS) on delivery creation; tracking number stored |
| CRM sync (quote/order push) | `openconnector` integrations | Call openconnector CRM API (Salesforce, HubSpot, Pipedrive) on quote created/order confirmed; push Quote/Order record |

**Net new code in implementation cycle**: 11 schema declarations + 5 lifecycle
blocks + 5 aggregations + 8 manifest entry pairs. Zero PHP service classes
(per ADR-031).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Quote lifecycle + versioning | Declarative (`x-openregister-lifecycle`) | Pure state machine; versioning via OR's version-control pattern |
| Quote acceptance (e-signature) | Lifecycle action calling openconnector e-signature API | Async, operator-initiated; not a pure state machine |
| Order confirmation + credit check | Lifecycle action calling AR credit-check aggregation | Async, operator-initiated |
| Order → Invoice conversion | Lifecycle-action-driven query (GROUP BY order line, generate invoice lines) | Templated per invoicing mode (on-delivery, on-order, etc.) |
| Delivery → Order line quantity update | Lifecycle action (delivery confirmed) updating SalesOrderLine.deliveredQuantity | Pure state update |
| GL posting on invoice issue | Lifecycle action invoking T1's materialisation extension | No new service |
| GL posting on advance invoice (deferred revenue) | Lifecycle action invoking T1's materialisation extension | No new service |
| Pricing tier resolution | Declarative (`x-openregister-aggregations`) | GROUP BY customer tier + product; join to PricingTier + Discount rules; calculate unit price |
| Volume discount recomputation (crossing threshold) | Aggregation + lifecycle action (order confirmed, invoice issued) | Trigger recompute; store resolved price on order line (not recomputed at invoice time) |
| BTW calculation per line | Lifecycle action (invoice generated) evaluating product BTW + customer location + reverse-charge rule | Deterministic; stored on line |
| VIES validation | Async task (invoice preview → dispatch) calling openconnector | Returns 0% reverse-charge or warning; operator can override |
| Peppol dispatch | Lifecycle action (invoice sent) generating UBL XML and calling openconnector Peppol API | No new service; integration call only |
| AR ageing + credit-hold trigger | Aggregation query (aged AR) + scheduled workflow (daily check) triggering credit-hold state change | Per ADR-031 scheduled-workflow pattern (path 2) |
| Sales-funnel KPIs | Declarative (`x-openregister-aggregations`) | GROUP BY sales stage; aggregate count/sum/avg; compute derived metrics (conversion %, days-to-close) |

No service class authored in this envelope (per ADR-031). Lifecycle actions are
declarative OR extensions; openconnector calls are thin API wrappers.

## Seed Data

Mock Q2C data (3–5 realistic sales scenarios per administration) with:

- **Customers** (3–5 examples):
  - Large B2B industrial buyer (€50k annual spend, 60-day payment terms, tiered pricing, monthly consolidation)
  - Small retail reseller (€5k annual spend, net30, volume discounts on bulk orders)
  - Public-sector buyer (€20k annual spend, Peppol required, net45, strict delivery dates)

- **Quotes** (4–6 examples):
  - Sent 2 weeks ago, awaiting response (status: sent)
  - Accepted 1 week ago, ready to convert (status: accepted)
  - Expired (status: expired, issued 60 days ago)
  - Revised version (v2 after customer negotiation, showing version history)

- **Sales Orders** (3–5 examples):
  - Confirmed, awaiting shipment (status: confirmed, 100 units ordered)
  - Partial shipment (status: partial, 60 delivered of 100 ordered, backorder 40)
  - Shipped, awaiting invoice (status: shipped)
  - Invoiced (status: invoiced)

- **Deliveries** (2–3 examples):
  - First shipment of partial order (60 units, carrier PostNL, tracking number)
  - Second shipment (40 units, backorder release)

- **Invoices** (3–5 examples):
  - Advance invoice (issued on order confirmation, deferred-revenue posting)
  - Delivery-based invoice (issued on first delivery, 60 units)
  - Partial-invoice on second delivery (40 units)
  - Peppol-dispatched invoice (public-sector customer)

- **Pricing Tiers** (2–3 examples):
  - Tier 1: 1–9 units at €100/unit
  - Tier 2: 10–49 units at €90/unit (10% discount)
  - Tier 3: 50+ units at €80/unit (20% discount)

- **Volume Discounts** (1–2 examples):
  - "Free shipping above EUR 1,000" (validity: 2026-01-01 to 2026-12-31, automatic application)
  - "Buy 100+ units of product X, get 5% off" (validity: Q1 2026, customer-group eligible)

- **Credit Hold** (1 example):
  - Customer with 2 invoices overdue 45+ days, hold applied (block-delivery), awaiting payment plan

Seed data SHALL be included in `lib/Settings/shillinq_register.json` under
`components.objects[]` with `@self` envelope per ADR-001 seed-data pattern.
Seed data is idempotent — re-importing skips objects matched by slug.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Quote versioning and docudesk availability | Versions stored as immutable snapshots (ADR-001 pattern); docudesk holds PDF. If docudesk not stable, single-method `QuoteRenderer` per ADR-031; gap filed with docudesk. |
| E-signature integration availability (DocuSign, Signhost, Adobe) | Three acceptance channels (in-app link, e-signature, manual back-office). If e-signature not available, in-app + manual still deliver value; openconnector issue filed. |
| VIES validation latency and failure | VIES calls async + cached (24h TTL). Validation failure → warning on preview; operator can override (audit-trailed). Fallback: EU customer defaults 0% BTW + manual verification required. |
| Peppol dispatch failure and retry | Dispatch failures audit-trailed; operator can retry. No auto-retry queue in T3 (future T4). |
| Pricing resolution complexity (tiers + discounts + promotions) | Pricing evaluated in order (tier → discount → promotion) with line-level transparency. If perf gates trip, pricing pre-computed at order-confirmation time and stored (not computed at invoice time). |
| Credit-hold thresholds need customization per SMB risk appetite | Thresholds (open balance > EUR X, > N days overdue) in administration config (ADR-022 `IAppConfig`); not hardcoded. |
| Blanket order call-off release complexity | Call-off releases modelled as separate SalesOrder entities linked via master-order FK (clean hierarchy; allows per-release invoicing). Alternative: line-item releases within single BlanketOrder (less flexible). Current design chosen for flexibility. |
| Advance invoicing and deferred-revenue reconciliation | Advance invoice issues deferred-revenue GL posting; reversed on delivery. Reconciliation (Q2C ↔ IFRS 15) is aggregation query (sum billed vs. sum recognised). Resolved in T3 IFRS 15 spec. |
| BTW complexity (reverse-charge, intracommunautair, export) | Per-line BTW with VIES validation + reverse-charge legend. Binnenland, intracommunautair, and derde-landen rules encoded in product + customer-location matrix. Handled in T3; export rules (T4 future). |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the 11 schemas
   (additive — no existing schema changes).
2. Seed data (3–5 customers, 4–6 quotes, 3–5 orders, 2–3 deliveries, 3–5
   invoices, 2–3 pricing tiers, 1–2 volume discounts, 1 credit hold) is inserted
   via `ConfigurationService::importFromApp()` repair step.
3. `src/manifest.json` is patched with 8 navigation entries (Quotes, Orders,
   Deliveries, Invoices, Pricing Rules, Credit Holds, Sales Funnel, AR Ageing)
   + their pages (additive).
4. Lifecycle blocks and aggregations are declared in register JSON (no PHP).

Down-direction: registers are non-destructive — reverting removes manifest
entries; quotes, orders, invoices remain queryable but unreferenced.

## Open Questions

1. **Quote acceptance workflow** — are all three channels (in-app link,
   e-signature, manual) required in T3, or phased? Recommend: in-app + manual
   in T3, e-signature in T3+ (pending openconnector e-signature stability).
   Resolved during spec review.

2. **Peppol mandatory for T3?** — Peppol dispatch is required for Dutch
   public-sector customers (legal mandate); optional for others. Recommend:
   implement for all (same code path); flag as optional in manifest. Resolved
   in scope clarification.

3. **Credit-hold severity levels** — three tiers (warning, block-order,
   block-delivery) or binary (hold vs. no hold)? Recommend: three tiers;
   defaults (open AR > EUR 5k + 60 days overdue = block-delivery). Resolved
   in UX review.

4. **Advance invoicing deferred revenue** — should deferred-revenue reversal
   happen automatically on delivery, or require operator confirmation? Recommend:
   automatic (same lifecycle action that materialises revenue posting). Resolved
   in T3 IFRS 15 spec review.

5. **Blanket order call-off releases** — model as separate SalesOrder entities
   (current design, allows per-release invoicing) or as line-item releases
   within single BlanketOrder (less flexible, simpler query)? Recommend: separate
   SalesOrder entities (flexible, matches real B2B workflows). Resolved in
   design review.

6. **Customer pricing tier inheritance** — does a customer-group pricing tier
   apply to all customers in the group automatically, or require explicit
   assignment per customer? Recommend: group tiers apply automatically to all
   group members; customer-specific tiers override. Resolved in pricing-engine
   spec.

7. **Invoice consolidation per customer** — can multiple orders/deliveries be
   consolidated into a single invoice (e.g., monthly invoice with all orders)?
   Recommend: yes, via manual invoice creation (operator selects orders/deliveries
   to consolidate) or via billing-rule aggregation (future T4). T3 supports both
   1:1 (delivery → invoice) and 1:many (manual consolidation). Resolved in
   invoicing-rules spec.
