---
kind: code
depends_on: [bookkeeping-purchase-order-3way-02-purchase-order-core]
chain:
  - bookkeeping-purchase-order-3way-01-schemas-and-registers
  - bookkeeping-purchase-order-3way-02-purchase-order-core
  - bookkeeping-purchase-order-3way-03-peppol-transmission
  - bookkeeping-purchase-order-3way-04-goods-receipt-note
  - bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion
  - bookkeeping-purchase-order-3way-06-matching-engine
  - bookkeeping-purchase-order-3way-07-multi-po-consolidation
  - bookkeeping-purchase-order-3way-08-exception-workflow
  - bookkeeping-purchase-order-3way-09-gl-gr-ir-clearing
  - bookkeeping-purchase-order-3way-10-vendor-performance
  - bookkeeping-purchase-order-3way-11-audit-trail-export
---

# Proposal: bookkeeping-purchase-order-3way-03-peppol-transmission

Member 3 of 11 in the `bookkeeping-purchase-order-3way` chain.
Predecessor: `bookkeeping-purchase-order-3way-02-purchase-order-core`
(PO creation + approval chain). This `kind: code` member adds the
**Peppol BIS Ordering 3.0 transmission** of an approved PO to a supplier,
with graceful **PDF + email fallback** when the supplier is not
Peppol-registered.

## Why (carried from the giant)

REQ-PO3W-002: an approved PO must reach the supplier as a UBL 2.1 Order
via the openconnector Peppol Access Point, recording the peppol_message_id
and peppol_sent_at. When the supplier is not a Peppol participant, the
system falls back to PDF + email and logs peppol_fallback_reason — no PO
is ever silently un-transmitted.

## What this member does

- `PurchaseOrderService::sendToPeppol()` — transforms PO → UBL Order,
  submits to openconnector Peppol Access Point, records peppol_message_id
  + peppol_sent_at
- `PurchaseOrderService::sendToPDFEmail()` — fallback when supplier not
  Peppol-registered; logs peppol_fallback_reason
- "Send to Peppol / PDF+email" action on `PurchaseOrderForm.vue`
- Integration test: PO send → peppol_message_id recorded (and fallback path)

## Scope

### In Scope
- `sendToPeppol()` + `sendToPDFEmail()` on `PurchaseOrderService`
- Send button wiring on the PO form (component from member 02)
- Integration test for Peppol transmission + fallback

### Out of Scope
- PO creation + approval chain — member 02 (predecessor)
- Incoming UBL invoice receipt — member 05
- GRN, matching, GL, vendor scoring, audit export — members 04, 06-11

## Impact
- `lib/Service/PurchaseOrderService.php` (transmission methods)
- `src/components/PurchaseOrderForm.vue` (send action)
- `tests/` integration test for Peppol transmission + fallback

## Cross-Project Dependencies
- **openconnector** — Peppol AS4 gateway for UBL Order transmission
