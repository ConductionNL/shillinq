---
kind: code
depends_on: [bookkeeping-purchase-order-3way-10-vendor-performance]
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

# Proposal: bookkeeping-purchase-order-3way-11-audit-trail-export

Member 11 of 11 (final member) in the `bookkeeping-purchase-order-3way`
chain. Predecessor:
`bookkeeping-purchase-order-3way-10-vendor-performance`. This `kind: code`
member implements the **audit-trail capture + compliance export**
(REQ-PO3W-010): a complete, immutable lifecycle history exportable per NV
COS 230 / BW2 art 2:10 for external auditors. It is the closing member —
no obsolete imperative code needs deleting, so the chain ends with the
audit/export consumer rather than a deletion step.

## Why (carried from the giant)

REQ-PO3W-010: an external auditor sampling 25 invoices must be able to
call up, per invoice, the complete chain — PO creation, approval-chain
signatures, Peppol transmission, GRN receipt with photos, invoice receipt,
match evaluation, exception resolution, GL postings, and payment — and
export it as an immutable structured package. This is the forensic
backbone that makes the whole control auditable.

## What this member does

- Ensure all lifecycle transitions on PO, GRN, SupplierInvoice,
  ThreeWayMatch are audit-logged (leveraging `bookkeeping-audit-trail`),
  including approver identities + exception resolution details
- `AuditExportService`: `generateAuditPackage(invoiceId)` — exports the
  complete lifecycle history as a ZIP (PDF summary + JSON ledger + file
  attachments: photos, signed approval records)
- `AuditTrailDetail.vue` (timeline view + export button)
- Unit tests (audit capture on transitions, package + ZIP generation);
  integration test (full lifecycle audit-trail verification)

## Scope

### In Scope
- Audit-trail wiring on all lifecycle transitions + approval/exception events
- `AuditExportService` (audit package + ZIP)
- `AuditTrailDetail.vue`
- Audit-capture + export unit/integration tests

### Out of Scope
- The lifecycle transitions themselves — earlier members
- Vendor scoring, GL, matching — members 06-10

## Impact
- `lib/Service/AuditExportService.php`
- `src/components/AuditTrailDetail.vue`
- `tests/` audit capture + export

## Cross-Project Dependencies
- **docudesk** — 3-way match evidence packages archived per BW2 art 2:10 (7-year retention)
- **bookkeeping-audit-trail (T2)** — lifecycle audit logging
