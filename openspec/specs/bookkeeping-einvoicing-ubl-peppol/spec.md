---
status: in-progress
---

# bookkeeping-einvoicing-ubl-peppol Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- [add-invoice-pdf-export-with-ubl-peppol-support](../../changes/archive/2026-07-12-add-invoice-pdf-export-with-ubl-peppol-support/) _(archived 2026-07-12)_
- facturx-cii-conformance

## Purpose

Defines Shillinq's **outbound e-invoicing** capability: generation of EN 16931 /
NLCIUS UBL 2.1 XML from an issued `ARInvoice`, embedding that XML in a PDF
alongside the human-readable rendering (a hybrid PDF, `AFRelationship =
Alternative`), pre-send validation of Dutch business identifiers (KvK,
BTW-nummer via VIES, Peppol participant ID), and Peppol BIS Billing 3.0
transmission via a generalised transmission-adapter port with delivery-status
feedback. This is the T4 UBL/Peppol capability deferred by
`bookkeeping-accounts-receivable-core` REQ-AR-009. **NL is the only market
served today**: the embedded XML is NLCIUS UBL 2.1, not UN/CEFACT CII, so
this capability does NOT produce Factur-X/ZUGFeRD invoices and does NOT
declare PDF/A-3 conformance (see REQ-EINV-002 / REQ-EINV-008).

Market driver: NL B2G e-invoicing is already mandatory; a B2B e-invoicing draft
law is expected Q4 2026 on the EU ViDA trajectory toward a 2030 structured-invoicing
mandate with Peppol as the preferred network.
## Requirements
### Requirement: REQ-EINV-000 — Shillinq SHALL generate and transmit EN 16931 / NLCIUS e-invoices over Peppol from issued AR invoices

The system SHALL be able to turn an issued `ARInvoice` into an EN 16931 / NLCIUS
UBL 2.1 document, embed it in a hybrid PDF, validate the debtor's KvK /
BTW-nummer / Peppol participant ID, and transmit it over Peppol BIS Billing 3.0 via
a generalised transmission port, tracking delivery status back onto the invoice.
The detailed sub-requirements are REQ-EINV-001 … REQ-EINV-008 below.

#### Scenario: An issued invoice can be sent as a Peppol e-invoice

- GIVEN an `ARInvoice` in `lifecycleState: issued` with a debtor carrying a valid
  Peppol participant ID
- WHEN the operator triggers Send e-invoice
- THEN the system MUST generate the NLCIUS UBL, validate the identifiers, and queue
  the document for Peppol transmission, advancing the invoice `deliveryStatus`

The normative requirements below (REQ-EINV-001 … REQ-EINV-008) cover: NLCIUS
UBL 2.1 generation, hybrid PDF embedding (NL/Peppol UBL — explicitly not
Factur-X/ZUGFeRD CII, REQ-EINV-008), KvK/BTW/Peppol validation, the
generalised transmission port + Log adapter, the
`nl.conduction.peppol.outbound.requested` / `nl.conduction.peppol.delivery.status`
event contract, B2G send provenance, and the AR-detail Send action + delivery
indicator.

### Requirement: REQ-EINV-001 — The system SHALL generate EN 16931-compliant UBL 2.1 / NLCIUS XML from an issued ARInvoice

A stateless mapper (`lib/Service/EInvoice/ArInvoiceUblMapper.php`) MUST render an
`ARInvoice` plus its billable lines, creditor (`AccountingSupplierParty`) and
debtor (`AccountingCustomerParty`) into a UBL 2.1 `Invoice` document restricted to
the **NLCIUS** customisation. The document MUST declare
`cbc:CustomizationID = urn:cen.eu:en16931:2017#compliant#urn:fdc:nen.nl:nlcius:v1.0`
and `cbc:ProfileID = urn:fdc:peppol.eu:2017:poacc:billing:01:1.0`, and MUST reuse
the CBC/CAC vocabulary already parsed by
`SupplierInvoiceService::parseUblInvoice` (`cbc:ID`, `cbc:IssueDate`, `cbc:DueDate`,
`cbc:DocumentCurrencyCode`, `cac:LegalMonetaryTotal`, `cac:TaxTotal`,
`cac:InvoiceLine`). Monetary amounts MUST be serialised as decimal amounts in the
invoice currency (cents ÷ 100), inverse to the parser's `toCents`.

#### Scenario: Issued invoice renders conformant NLCIUS XML

- GIVEN an `ARInvoice` `2026-0042` in `lifecycleState: issued` with two lines,
  `grossAmount: 1210`, `vatAmount: 210`, `netAmount: 1000`, `currency: EUR`
- WHEN the UBL mapper renders the document
- THEN the output MUST be well-formed UBL 2.1 with the NLCIUS `CustomizationID`
- AND `cac:LegalMonetaryTotal/cbc:PayableAmount` MUST equal `1210.00`
- AND each line MUST carry `cbc:ID`, `cbc:InvoicedQuantity`,
  `cac:Price/cbc:PriceAmount`, and `cac:Item/cac:ClassifiedTaxCategory/cbc:Percent`

#### Scenario: A draft invoice cannot be rendered for transmission

- GIVEN an `ARInvoice` in `lifecycleState: draft`
- WHEN e-invoice generation is requested
- THEN the system MUST refuse with a validation error naming the required
  `issued` state, and no XML is produced

### Requirement: REQ-EINV-002 — The system SHALL embed the UBL XML into a PDF/A-3 hybrid invoice

`InvoicePdfGenerator` MUST gain a hybrid path that attaches the generated UBL XML
into a **PDF/A-3** document as an embedded file with `AFRelationship = Alternative`
and a well-known filename (Factur-X `factur-x.xml` or the NLCIUS-declared name),
producing a single artefact that is both human-readable and machine-readable
(Factur-X / ZUGFeRD pattern). The existing plain-PDF path
(`generatePdf(...): {filename, html, mimeType}`) MUST remain unchanged and
callable.

#### Scenario: Hybrid export embeds the XML

- GIVEN an issued `ARInvoice` and its rendered NLCIUS XML
- WHEN the hybrid export is generated
- THEN the returned artefact MUST be `application/pdf` conforming to PDF/A-3
- AND the UBL XML MUST be retrievable as an embedded file with
  `AFRelationship = Alternative`

#### Scenario: Plain PDF path is unaffected

- GIVEN the existing `generatePdf(invoice, lines, creditor, recipient)` call
- WHEN invoked without requesting the hybrid path
- THEN it MUST return the same `{filename, html, mimeType}` shape as before

### Requirement: REQ-EINV-003 — The system SHALL validate KvK, BTW-nummer, and Peppol participant ID before send

A `EInvoiceValidationService` MUST run pre-send checks and MUST block
transmission on failure: **KvK** number is exactly 8 digits; **BTW-nummer** matches
the NL pattern `NL` + 9 digits + `B` + 2 digits and is VIES-consistent (reusing
the existing VIES integration; a VIES outage MUST degrade to a non-blocking
warning, not a false rejection); and the recipient **Peppol participant ID** is
resolvable via the openconnector lookup contract
`GET /participants/{peppolId}` → `{exists, supportedDocTypes[]}` and its
`supportedDocTypes` MUST include the invoice document type.

#### Scenario: Malformed BTW-nummer blocks send

- GIVEN a debtor with `vatID: "NL123"`
- WHEN the operator triggers Send e-invoice
- THEN validation MUST fail with a message naming the BTW-nummer format, and no
  outbound event is emitted

#### Scenario: Unknown Peppol participant falls back to PDF + email

- GIVEN a debtor whose Peppol participant lookup returns `{exists: false}`
- WHEN the operator triggers Send e-invoice
- THEN the system MUST NOT emit `nl.conduction.peppol.outbound.requested`
- AND it MUST offer the PDF + email fallback (mirroring the PO null-participant
  fallback), setting `deliveryStatus: not-sent`

#### Scenario: VIES outage degrades to a warning

- GIVEN the VIES service is unavailable
- WHEN BTW-nummer validation runs on a syntactically valid number
- THEN validation MUST return a non-blocking warning and allow the operator to
  proceed

### Requirement: REQ-EINV-004 — The Peppol transmission port SHALL be generalised across Order and Invoice documents without regressing the PO path

The transmission port MUST be generalised: the existing PO-only
`PeppolTransmissionAdapterInterface` + `LogPeppolTransmissionAdapter` MUST become
a shared, document-type-agnostic port `OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface`
exposing `lookupParticipant(administrationId, partyId): ?string` and
`submit(participantId, documentType, payloadFileUri): string` (returning the
Peppol message id URN). A **Log adapter** MUST remain the default DI binding so
dev/CI works without the openconnector access point. The existing
`PeppolTransmissionAdapterInterface` MUST be retained as a thin alias/extension so
the purchase-order 3-way-match transmission path continues to compile and behave
identically (no behavioural regression).

#### Scenario: Log adapter fabricates a URN for an invoice submit

- GIVEN the Log adapter is the active binding and a valid participant id
- WHEN `submit(participantId, 'ubl-invoice-2.1', payloadFileUri)` is called
- THEN it MUST return a `urn:uuid:...` message id and log a single redacted line

#### Scenario: PO transmission path is unchanged

- GIVEN the existing purchase-order Peppol transmission flow
- WHEN a PO is transmitted after the port generalisation
- THEN it MUST resolve the participant and submit the UBL Order exactly as before,
  with no change to its persisted `peppolMessageId` behaviour

### Requirement: REQ-EINV-005 — Sending SHALL emit and consume the canonical Peppol transport events

On a successful pre-send validation, the system MUST emit
`nl.conduction.peppol.outbound.requested` with payload
`{sourceApp: 'shillinq', objectType: 'ar-invoice', objectUri, recipientPeppolId,
documentType: 'ubl-invoice-2.1', payloadFileUri}`. The system MUST register a
listener for `nl.conduction.peppol.delivery.status` with payload
`{objectUri, transmissionId, status, timestamp, detail}` and MUST map each
`status` value (`queued|sent|delivered|rejected|failed`) onto the `ARInvoice`
delivery sub-lifecycle (bookkeeping-accounts-receivable-core REQ-AR-011). Events
use the openconnector cloud-events dialect; cross-app coupling is event-only
(ADR-022) — shillinq MUST NOT call the access point over direct RPC.

#### Scenario: Send emits the outbound-requested event

- GIVEN a validated issued `ARInvoice` with a resolvable Peppol participant
- WHEN the operator triggers Send e-invoice
- THEN exactly one `nl.conduction.peppol.outbound.requested` event MUST be emitted
  carrying `objectType: 'ar-invoice'` and `documentType: 'ubl-invoice-2.1'`
- AND `ARInvoice.deliveryStatus` MUST advance to `queued`

#### Scenario: Delivery-status event advances the sub-lifecycle

- GIVEN an `ARInvoice` with `deliveryStatus: sent` and a known `transmissionId`
- WHEN a `nl.conduction.peppol.delivery.status` event arrives with
  `status: delivered` for that `objectUri`
- THEN `ARInvoice.deliveryStatus` MUST become `delivered` and the event `detail`
  MUST be recorded on the invoice

#### Scenario: Rejected delivery is surfaced, not silent

- GIVEN an in-flight e-invoice
- WHEN a delivery-status event arrives with `status: rejected`
- THEN `deliveryStatus` MUST become `rejected`, the `detail` MUST be persisted,
  and the finance operator MUST be notified (ADR-031 notification dialect)

### Requirement: REQ-EINV-006 — B2G invoices SHALL be sendable and the send path SHALL record provenance

The system MUST allow e-invoice transmission to a government-body debtor (a B2G
debtor, flagged or carrying a B2G Peppol participant) and MUST persist the
resulting `transmissionId` and `payloadFileUri` (the stored UBL/PDF-A3 artefact)
on the `ARInvoice` for audit. The stored artefact URI MUST be a Docudesk/Files
FK, not inline XML, consistent with the app's document-attachment pattern.

#### Scenario: B2G send records provenance

- GIVEN an issued `ARInvoice` to a government debtor with a valid B2G participant
- WHEN it is transmitted
- THEN the `ARInvoice` MUST persist `transmissionId` and `payloadFileUri`
  referencing the stored artefact

### Requirement: REQ-EINV-007 — The AR invoice detail surface SHALL expose a Send e-invoice action and delivery-status indicator

The AR invoice detail view MUST expose a **Send e-invoice** action (enabled only
for `lifecycleState: issued`) and MUST display the current `deliveryStatus` with a
human-readable label. Validation failures MUST be shown inline before any event
is emitted.

#### Scenario: Operator sends an e-invoice from the detail view

- @e2e tests/e2e/ar-invoice-einvoice.spec.ts
- GIVEN a finance operator viewing an issued `ARInvoice` with valid debtor
  identifiers
- WHEN they click Send e-invoice and confirm
- THEN the delivery-status indicator MUST update to reflect `queued`
- AND a success toast MUST confirm the invoice was queued for Peppol delivery

## Notes

- Cross-app coupling to openconnector's Peppol access point is event-only
  (ADR-022); document generation and external transmission are allowed imperative
  surfaces (ADR-031).
- Related canonical specs: `bookkeeping-accounts-receivable-core` (owns
  `ARInvoice` + the `deliveryStatus` sub-lifecycle), `semantic-invoice-consume`,
  and openconnector's cloud-events / participant-lookup specs.
