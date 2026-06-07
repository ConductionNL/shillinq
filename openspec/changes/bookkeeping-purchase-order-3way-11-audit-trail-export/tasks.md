# Tasks — Member 11: Audit Trail & Compliance Export (code)

## Audit-trail capture

- [ ] Ensure all lifecycle transitions on PO, GRN, SupplierInvoice, ThreeWayMatch are audit-logged via bookkeeping-audit-trail
- [ ] Record approver identities + timestamps on the approval chain
- [ ] Record exception resolution details (resolver + action + notes + timestamp)

## AuditExportService

- [ ] Implement `generateAuditPackage(invoiceId)` — export complete lifecycle history
- [ ] Generate PDF summary + JSON ledger + file attachments (photos, signed approval records)
- [ ] Create immutable ZIP archive for external auditor review; archive via docudesk (7-year retention)

## Vue view

- [ ] Create `AuditTrailDetail.vue`: timeline view (PO → approval → GRN → invoice → match → exception → GL → payment), each event with timestamp + actor + details, export-as-PDF/ZIP button

## Tests

- [ ] Unit tests: audit-trail capture on all lifecycle transitions, audit package + ZIP generation, timestamp + actor recording
- [ ] Integration test: full PO → approval → GRN → invoice → match → exception → approval → GL → payment lifecycle with audit-trail verification
