# Design — Member 05: Supplier Invoice Ingestion (code)

## Context

`kind: code` member that ingests supplier invoices into the
`SupplierInvoice` register (member 01). Two ingestion paths: Peppol UBL
Invoice and PDF-via-OCR, both through openconnector.

## Decisions

### D8 (incoming side) — Peppol UBL Invoice → SupplierInvoice

Carried from the giant's D8. openconnector's Peppol Access Point delivers
incoming UBL Invoices; `ingestUBLInvoice()` parses the UBL into a
`SupplierInvoice` plus line records. For PDF invoices, `ingestPDFInvoice()`
calls openconnector's OCR module and stores `ocr_confidence_score`.

### D2 — Lifecycle starts at `received`

A newly ingested invoice enters at lifecycle state `received` (declared
in member 01) and is ready for the matching engine (member 06) to move it
to `matching`. This member does not match — it only ingests.

## Security (ADR-005)

- Ingestion is server-side via openconnector events; invoice identity and
  amounts are taken from the UBL/OCR source, not from a client request body.
- ocr_confidence_score is recorded honestly so downstream members can gate
  low-confidence lines.

## Reuse
- openconnector Peppol Access Point + OCR module
- `SupplierInvoice` register + lifecycle (member 01)
- nextcloud-vue for the detail view
