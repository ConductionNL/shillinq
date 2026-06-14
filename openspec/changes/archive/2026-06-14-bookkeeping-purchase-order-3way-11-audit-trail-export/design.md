# Design — Member 11: Audit Trail & Compliance Export (code)

## Context

Final `kind: code` member. Consumes the lifecycle transitions emitted by
all earlier members and produces an immutable, exportable audit package
per NV COS 230 / BW2 art 2:10.

## Decisions

### Audit capture leverages bookkeeping-audit-trail

All lifecycle transitions on PO, GRN, SupplierInvoice, and ThreeWayMatch
are audit-logged via the existing T2 `bookkeeping-audit-trail` capability,
plus approver identities + timestamps on the approval chain and exception
resolution details (resolver + action + notes + timestamp).

### AuditExportService produces an immutable ZIP package

`generateAuditPackage(invoiceId)` assembles the complete lifecycle history
— PO creation, approval signatures, Peppol metadata, GRN receipt + photos,
invoice receipt + OCR confidence, match evaluation + divergence details,
exception resolution, GL postings, payment — and exports a structured ZIP
(PDF summary + JSON ledger + file attachments). Records are
cryptographically linked and timestamped; the package is immutable and
archived via docudesk for 7-year retention.

### Why this is the closing member (no deletion step)

The original change was net-new (no imperative implementation to migrate
away from), so the ADR-032 "delete imperative" terminal step does not
apply. The chain instead closes on the audit/export consumer, which
naturally depends on every prior member's events being in place.

## Security (ADR-005)

- The audit package is read-only and immutable; export is server-side and
  the package content is derived from server records, not client input.

## Reuse
- T2 `bookkeeping-audit-trail` for lifecycle logging
- docudesk for immutable archival
- nextcloud-vue for the timeline view
