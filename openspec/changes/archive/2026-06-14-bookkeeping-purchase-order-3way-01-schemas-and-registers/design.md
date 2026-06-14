# Design — Member 01: Schemas & Registers (config)

## Context

This is the `kind: config` foundation member of the
`bookkeeping-purchase-order-3way` chain. It declares the eight registers
the whole feature rests on, plus the manifest navigation, with seed data
and an integration test. No consumer code is written here — downstream
members (02-11) read these declarative fields.

Per ADR-031 (declarative-first) the entire register surface — fields,
lifecycle, dimensional columns — is expressed as OpenRegister schema
metadata rather than imperative model classes. Per ADR-001 the registers
are accessed through OpenRegister `ObjectService` (find / findAll /
saveObject / createObject / updateObject / deleteObject); no bespoke
mapper layer is introduced.

## Declarative-vs-imperative decision

Everything in this member is declarative:

| Surface | Declarative home | Why not imperative |
|---|---|---|
| 8 entity field sets | `shillinq_register.json` schemas | OR materialises columns + validation from schema; no model class needed |
| PO / GRN / Invoice / Match lifecycles | `x-openregister-lifecycle` on each schema | State machine lives in metadata; downstream guards read it |
| Navigation (5 entries) | `src/manifest.json` (ADR-024) | Manifest renderer builds index/detail from declarative entries |

No imperative code is appropriate at this layer. Approval-chain routing,
matching, GL posting, Peppol, and vendor scoring — the parts that genuinely
need PHP — are deferred to the `kind: code` members so this member stays
reviewer-cheap (schema validation + ADR-031 fit + integration test only).

## Entities (declared here, consumed downstream)

The full field tables for all eight entities — `PurchaseOrder`,
`PurchaseOrderLine`, `GoodsReceiptNote`, `GoodsReceiptLine`,
`SupplierInvoice`, `ThreeWayMatch`, `ToleranceProfile`,
`VendorPerformance` — are carried verbatim from the giant's design.md and
are reproduced in this member's spec delta. Schema.org bindings:
`schema:Order` (PO), `schema:OrderItem` (PO line), `schema:ReceiveAction`
(GRN), `schema:Invoice` (SupplierInvoice), `schema:AggregateRating`
(VendorPerformance).

## Lifecycles

- **PurchaseOrder**: draft → approved → sent → partial_received →
  fully_received → invoiced → closed (+ cancelled)
- **GoodsReceiptNote**: draft → received → quality_checked → accepted →
  rejected
- **SupplierInvoice**: received → matching → matched → exception →
  approved → paid → rejected
- **ThreeWayMatch**: created → (within_tolerance | exception_*) → resolved

## Seed Data

3-5 examples per entity, carried from the giant's design.md:
- `PurchaseOrder`: PO-2026-0001 (new supplier, approved), PO-2026-0002
  (established, sent via Peppol), PO-2026-0003 (partial_received, 180/200)
- `GoodsReceiptNote`: GRN-2026-0011 (full), GRN-2026-0012 (short-shipped 180/200)
- `SupplierInvoice`: INV-ERS-2026-00445 (matched), INV-NL-2026-18547 (matched)
- `ThreeWayMatch`: MATCH-2026-001 (auto_approved), MATCH-2026-002 (within_tolerance)
- `ToleranceProfile`: TP-GLOBAL-DEFAULT, TP-SUPPLIER-NieuweLeverancierBV,
  TP-CATEGORY-ElectricalEquipment
- `VendorPerformance`: VENDOR-ERS-2026-05 (eligible), VENDOR-NIEUW-2026-05 (not eligible)

## Integration test (config member requirement)

One integration test seeds the fixtures and asserts: all 8 registers
materialise, each PO/GRN/Invoice/Match record carries its declared
lifecycle state, dimensional fields (cost_center, project_code) round-trip,
and the manifest exposes the 5 navigation entries.

## Reuse

| Need | Existing | Reuse |
|---|---|---|
| Lifecycle | OR `x-openregister-lifecycle` | declarative on PO/GRN/Invoice/Match |
| Object CRUD | OR `ObjectService` (ADR-001) | find/saveObject/etc. — no mapper |
| Navigation | manifest pattern (ADR-024) | 5 entries, index/detail |
