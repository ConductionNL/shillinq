---
status: in-progress
---

# bookkeeping-einvoicing-ubl-peppol Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- `add-invoice-pdf-export-with-ubl-peppol-support`

## Purpose

Defines Shillinq's **outbound e-invoicing** capability: generation of EN 16931 /
NLCIUS UBL 2.1 XML from an issued `ARInvoice`, embedding that XML in a PDF/A-3
hybrid (Factur-X / ZUGFeRD), pre-send validation of Dutch business identifiers
(KvK, BTW-nummer via VIES, Peppol participant ID), and Peppol BIS Billing 3.0
transmission via a generalised transmission-adapter port with delivery-status
feedback. This is the T4 UBL/Peppol capability deferred by
`bookkeeping-accounts-receivable-core` REQ-AR-009.

Market driver: NL B2G e-invoicing is already mandatory; a B2B e-invoicing draft
law is expected Q4 2026 on the EU ViDA trajectory toward a 2030 structured-invoicing
mandate with Peppol as the preferred network.

## Requirements

The normative requirements (REQ-EINV-001 … REQ-EINV-007) are authored as the
change delta at
`openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md`
and will be synced into this canonical spec on archive (`openspec sync`). They
cover: NLCIUS UBL 2.1 generation, PDF/A-3 embedding, KvK/BTW/Peppol validation,
the generalised transmission port + Log adapter, the
`nl.conduction.peppol.outbound.requested` / `nl.conduction.peppol.delivery.status`
event contract, B2G send provenance, and the AR-detail Send action + delivery
indicator.

## Notes

- Cross-app coupling to openconnector's Peppol access point is event-only
  (ADR-022); document generation and external transmission are allowed imperative
  surfaces (ADR-031).
- Related canonical specs: `bookkeeping-accounts-receivable-core` (owns
  `ARInvoice` + the `deliveryStatus` sub-lifecycle), `semantic-invoice-consume`,
  and openconnector's cloud-events / participant-lookup specs.
