# Spec Delta: 3-way Match Audit Trail & Compliance Export

## ADDED Requirements

### Requirement: Capture and export the complete 3-way-match audit trail

The system SHALL audit-log every lifecycle transition on PurchaseOrder,
GoodsReceiptNote, SupplierInvoice, and ThreeWayMatch — including approver
identities + timestamps on the approval chain and exception resolution
details (resolved_by + resolution_action + resolution_notes + resolved_at)
— and SHALL provide an export of the complete lifecycle history for a
given invoice as an immutable structured package (ZIP: PDF summary + JSON
ledger + file attachments including GRN photos and signed approval
records). The package SHALL be cryptographically linked, timestamped, and
retained for 7 years per BW2 art 2:10 and NV COS 230.

#### Scenario: External auditor exports an invoice audit package

- **GIVEN** an external auditor reviews a sample invoice during year-end audit
- **WHEN** they request the complete audit trail for invoice INV-ERS-2026-00445
- **THEN** the system generates an immutable audit package containing the PO creation record, approval-chain signatures with timestamps, Peppol transmission metadata, GRN receipt with photos, invoice receipt metadata, ThreeWayMatch evaluation + divergence details, exception resolution notes (if any), GL posting records, and payment record, exported as a structured ZIP (PDF summary + JSON + attachments)

#### Scenario: Every lifecycle transition is logged

- **GIVEN** a PO progresses from creation through approval, GRN, invoice, match, GL posting, and payment
- **WHEN** the audit trail is queried
- **THEN** each transition shows a timestamp and actor, and exception resolutions show the resolver, action, and notes
