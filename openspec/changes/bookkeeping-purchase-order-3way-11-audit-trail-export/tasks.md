# Tasks — Member 11: Audit Trail & Compliance Export (code)

## Audit-trail capture

- [x] Ensure all lifecycle transitions on PO, GRN, SupplierInvoice, ThreeWayMatch are audit-logged via bookkeeping-audit-trail
- [x] Record approver identities + timestamps on the approval chain
- [x] Record exception resolution details (resolver + action + notes + timestamp)

## AuditExportService

- [x] Implement `generateAuditPackage(invoiceId)` — export complete lifecycle history
- [x] Generate PDF summary + JSON ledger + file attachments (photos, signed approval records)
- [x] Create immutable ZIP archive for external auditor review; archive via docudesk (7-year retention)

## Vue view

- [x] Create `AuditTrailDetail.vue`: timeline view (PO → approval → GRN → invoice → match → exception → GL → payment), each event with timestamp + actor + details, export-as-PDF/ZIP button

## Tests

- [x] Unit tests: audit-trail capture on all lifecycle transitions, audit package + ZIP generation, timestamp + actor recording
- [x] Integration test: full PO → approval → GRN → invoice → match → exception → approval → GL → payment lifecycle with audit-trail verification
