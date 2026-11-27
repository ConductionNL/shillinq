---
status: done
---

# bookkeeping-einvoicing-ubl-peppol Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- [add-invoice-pdf-export-with-ubl-peppol-support](../../changes/archive/2026-07-12-add-invoice-pdf-export-with-ubl-peppol-support/) _(archived 2026-07-12)_
- [facturx-cii-conformance](../../changes/archive/2026-07-13-facturx-cii-conformance/) _(archived 2026-07-13)_

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

### Requirement: REQ-EINV-002 — The system SHALL embed the UBL XML into a PDF as an Associated File, honestly labelled

`InvoicePdfGenerator` MUST gain a hybrid path that attaches the generated NLCIUS
UBL XML into a PDF document as an embedded file with `AFRelationship = Alternative`,
under the filename `ubl-invoice.xml` (`InvoicePdfGenerator::HYBRID_XML_FILENAME`),
producing a single artefact that is both human-readable and machine-readable for
the NL/Peppol UBL path. The existing plain-PDF path
(`generatePdf(...): {filename, html, mimeType}`) MUST remain unchanged and
callable.

The artefact MUST NOT claim conformance it does not meet:
- it MUST NOT be named or labelled as a Factur-X/ZUGFeRD document (those
  formats require UN/CEFACT CII syntax; the embedded payload is UBL — see
  REQ-EINV-008 for the explicit non-goal);
- it MUST NOT assert `pdfaid:part`/`pdfaid:conformance` PDF/A XMP metadata
  unless the generator actually emits the corresponding ISO 19005-3
  requirements (at minimum an ICC `OutputIntent` and fully embedded fonts) —
  today it emits neither, so no `pdfaid:*` claim MUST be present.

#### Scenario: Hybrid export embeds the XML under a truthful filename

- GIVEN an issued `ARInvoice` and its rendered NLCIUS XML
- WHEN the hybrid export is generated
- THEN the returned artefact MUST be `application/pdf`
- AND the UBL XML MUST be retrievable as an embedded file named
  `ubl-invoice.xml` with `AFRelationship = Alternative`
- AND the PDF's XMP metadata MUST NOT contain a `pdfaid:part` or
  `pdfaid:conformance` assertion

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

### Requirement: REQ-EINV-008 — True Factur-X/ZUGFeRD (UN/CEFACT CII) invoice output is explicitly NOT provided

The system SHALL NOT represent its hybrid PDF export as a Factur-X/ZUGFeRD
invoice to any caller, document, or trading partner. Factur-X (FR) and
ZUGFeRD (DE) both mandate an embedded **UN/CEFACT CrossIndustryInvoice
(CII)** XML document, not UBL; Shillinq's e-invoicing pipeline generates
NLCIUS **UBL 2.1** (REQ-EINV-001) for the NL/Peppol market and does not
generate CII. A DE/FR trading partner requiring true Factur-X/ZUGFeRD
conformance MUST be told this generator cannot serve that requirement
rather than being handed a UBL payload under a Factur-X-branded filename.

#### Scenario: A DE/FR trading partner cannot be served Factur-X/ZUGFeRD by this generator

@e2e exclude pure backend/document-generation logic — not browser-testable

- GIVEN a trading partner that requires true Factur-X/ZUGFeRD (CII) conformance
- WHEN Shillinq's e-invoicing pipeline is asked to produce that partner's
  invoice artefact
- THEN `InvoicePdfGenerator::generateHybridPdf()` MUST NOT be presented as
  satisfying that requirement — the embedded XML is UBL, the filename is
  `ubl-invoice.xml` (not a Factur-X/ZUGFeRD well-known name), and no
  `pdfaid:*` PDF/A conformance metadata is asserted
- AND a genuine UN/CEFACT CII generator is out of scope until a follow-up
  change ships one behind a profile flag with a real conformance validator
  in the loop (KoSIT/Mustang for CII, veraPDF for PDF/A)

#### Scenario: NL/Peppol UBL delivery is unaffected

@e2e exclude pure backend/document-generation logic — not browser-testable

- GIVEN an NL debtor with a valid Peppol participant ID
- WHEN an issued `ARInvoice` is sent via `EInvoiceService::sendInvoice()`
- THEN the NLCIUS UBL 2.1 XML (REQ-EINV-001) is generated, embedded in the
  hybrid PDF (REQ-EINV-002), and transmitted over Peppol exactly as before
  this change — only the embedded filename and the (now absent) false
  PDF/A-3B XMP claim changed

### Requirement: REQ-EINV-009: The hybrid PDF SHALL print the ARInvoice's own lines

`InvoicePdfGenerator` SHALL read a line in either shape: the BillableInvoiceLine
keys (`lineNumber`, `description`, `billableUnits`, `rateApplied.rateCents`,
`costAmount`) first, and the `invoiceLines` keys (`lineId`, `itemName`,
`quantity`, `netPrice`, `netAmount`) when those are absent. The hybrid PDF page
SHALL print one text line per invoice line under its summary (number,
description, quantity x price = amount with the currency code, VAT rate), up to
45 lines. A BillableInvoice PDF SHALL render exactly as before.

#### Scenario: An e-invoice PDF shows the consultancy line

- GIVEN an ARInvoice line `lineId` "1", `itemName` "Consulting", `quantity` 2, `netPrice` 100, `netAmount` 200, `vatRate` 21
- WHEN the hybrid PDF is generated
- THEN its page prints "1  Consulting  2 x 100,00 = 200,00 EUR  btw 21%" under the summary
- AND the HTML row for the same line shows 1, Consulting, 2, € 100,00, € 200,00 and 21%
- @e2e exclude document generation; covered by `InvoicePdfGeneratorTest::testTheHybridPdfPrintsTheArInvoiceLines` and `::testTheHtmlRowReadsEitherLineShape`

#### Scenario: A time-and-expense PDF is unchanged

- GIVEN a BillableInvoiceLine with `description`, `billableUnits` and `costAmount`
- WHEN the PDF is generated
- THEN the row is the same as before this change
- @e2e exclude document generation; covered by the existing `InvoicePdfGeneratorTest` cases

### Requirement: REQ-EINV-010: The NLCIUS document SHALL carry the customer's reference as the buyer reference (BT-10)

`ArInvoiceUblMapper::toNlciusXml()` SHALL write the ARInvoice's `customerReference`
as `cbc:BuyerReference`, escaped, directly after `cbc:DocumentCurrencyCode` (the
UBL 2.1 element order). A blank reference SHALL write no element.

#### Scenario: The reference is the buyer reference

- GIVEN an issued invoice with customer reference `PO-4711 & co`
- WHEN it is rendered
- THEN the document holds `<cbc:BuyerReference>PO-4711 &amp; co</cbc:BuyerReference>` after the document currency
- @e2e exclude XML mapper; covered by `ArInvoiceUblMapperTest::testCustomerReferenceIsTheBuyerReference`

#### Scenario: No reference, no element

- GIVEN an issued invoice whose customer reference is blank
- WHEN it is rendered
- THEN the document holds no `BuyerReference`
- @e2e exclude XML mapper; covered by `ArInvoiceUblMapperTest::testNoCustomerReferenceWritesNoBuyerReference`

## Notes

- Cross-app coupling to openconnector's Peppol access point is event-only
  (ADR-022); document generation and external transmission are allowed imperative
  surfaces (ADR-031).
- Related canonical specs: `bookkeeping-accounts-receivable-core` (owns
  `ARInvoice` + the `deliveryStatus` sub-lifecycle), `semantic-invoice-consume`,
  and openconnector's cloud-events / participant-lookup specs.

### Requirement: Sending hands the invoice to integriq on its CloudEvent register (REQ-SEIX-001)

When a user sends an issued `ARInvoice` as an e-invoice, shillinq SHALL save one
object in integriq's register, schema `event`, of type
`nl.conduction.peppol.outbound.requested`, whose `data` carries `sourceApp`,
`objectUri`, `recipientPeppolId`, `documentType` and `payloadFileUri`. The
invoice MUST go to `deliveryStatus` `queued` only after that object was saved,
and the invoice path MUST NOT call the log-only local transmission port.

#### Scenario: A bookkeeper sends an e-invoice to a municipality

- GIVEN a bookkeeper on the AR invoice page of issued invoice 2026-0420 for Gemeente Voorbeeld, which has a valid Peppol participant id
- WHEN they choose Send e-invoice
- THEN integriq's event register holds one outbound request naming invoice 2026-0420 and its UBL file
- AND the page shows delivery status queued

#### Scenario: Without integriq nothing pretends to be queued

- GIVEN an instance without integriq installed
- WHEN the bookkeeper chooses Send e-invoice on issued invoice 2026-0420
- THEN the page says Peppol sending is not available on this instance and offers Send by email
- AND the invoice stays at delivery status not-sent

### Requirement: The payload named in the hand-off is a stored file (REQ-SEIX-002)

Before the hand-off, shillinq SHALL store the invoice's UBL XML and hybrid PDF
in the administration's Nextcloud Files, and `payloadFileUri` MUST reference
the stored XML. A placeholder reference that resolves to nothing MUST NOT be
written.

#### Scenario: The e-invoice file can be opened afterwards

- GIVEN invoice 2026-0420 sent as an e-invoice
- WHEN a bookkeeper opens the payload file link on its AR invoice page
- THEN the UBL XML of invoice 2026-0420 opens from the administration's e-invoices folder

### Requirement: Delivery status from integriq updates the invoice (REQ-SEIX-003)

Shillinq SHALL take every `nl.conduction.peppol.delivery.status` CloudEvent
integriq saves in its event register and apply it to the `ARInvoice` its
`objectUri` names, following the existing allowed transitions (`queued` to
`sent`, `failed` or `rejected`; `sent` to `delivered` or `rejected`), and SHALL
write `transmissionId` and `deliveryDetail` from it. A status for an unknown
invoice or an illegal transition MUST be logged and ignored.

#### Scenario: The access point confirms delivery

- GIVEN invoice 2026-0420 at delivery status queued
- WHEN integriq reports sent and then delivered for it
- THEN its AR invoice page shows delivered and the transmission id integriq assigned

### Requirement: A rejected e-invoice reaches the bookkeeper (REQ-SEIX-004)

When an e-invoice is reported `rejected`, shillinq SHALL notify every member
of the invoice's administration whose AdministrationMembership role owns its
receivables (`debiteurenadmin`, `boekhouder`, `controller` or `eigenaar`;
the membership schema has no `ar-controller` role, #1754) through a
notification that Nextcloud can render and that links to the invoice, and the
notification MUST carry the rejection reason. `AccountsReceivable` SHALL offer
a filter that lists rejected e-invoices.

#### Scenario: A municipality rejects an invoice for a missing order number

- GIVEN invoice 2026-0420 sent to Gemeente Voorbeeld
- WHEN integriq reports it rejected with the reason "Ordernummer ontbreekt"
- THEN each member of the administration with one of those roles sees a notification naming invoice 2026-0420 and the reason, which opens its AR invoice page
- AND the Rejected e-invoices filter on the Accounts Receivable page lists invoice 2026-0420

### Requirement: A customer's self-billing agreement is recorded (REQ-SEIX-005)

`CustomerMaster` SHALL carry a `selfBillingAgreement` with the date agreed, the
end date and an optional document reference, editable on `CustomerDetail`, and
`ARInvoice` SHALL declare `selfBilled` and `selfBillingMention`.

#### Scenario: A farm records the agreement with its buyer

- GIVEN the customer page of Aardappelverwerking De Kuil B.V.
- WHEN the bookkeeper records a self-billing agreement from 2026-01-05 to 2026-12-31
- THEN the customer page shows the agreement and its end date

### Requirement: A received self-billed invoice becomes a sales invoice to review (REQ-SEIX-006)

When integriq reports an inbound document whose UBL is a self-billed invoice,
shillinq SHALL match the seller party to an administration and the buyer party
to a `CustomerMaster` with a current self-billing agreement, and SHALL write an
`ARInvoice` in `draft` with `selfBilled` true, the mention "Factuur uitgereikt
door afnemer", the lines and VAT from the document and the customer's own
invoice number, once per sender and number. Without a match or a current
agreement it MUST NOT write an invoice and SHALL record a refusal with the
reason. Any other inbound invoice SHALL go to the supplier invoice intake.

#### Scenario: A potato processor self-bills a delivery

- GIVEN Akkerbouwbedrijf Jansen with a current agreement for Aardappelverwerking De Kuil B.V.
- WHEN De Kuil sends self-billed invoice SB-2026-00042 over Peppol for 30,000 kg at EUR 6,000 plus 9 percent VAT
- THEN the Accounts Receivable page lists a draft invoice of EUR 6,540 for De Kuil marked as issued by the customer, with number SB-2026-00042

#### Scenario: A self-billed invoice without an agreement is refused

- GIVEN a customer without a self-billing agreement
- WHEN a self-billed invoice from that customer arrives
- THEN no invoice is written
- AND a refusal naming the sender and the reason no agreement is recorded is listed for the bookkeeper

### Requirement: A self-billed invoice is booked only after review (REQ-SEIX-007)

A draft `ARInvoice` with `selfBilled` true SHALL be issued only by a user on
`ARInvoiceDetail`, and the `issue` transition MUST refuse it when the
customer's agreement is absent or expired on the invoice date. The user MAY
reject it with a reason instead, which SHALL leave it unbooked.

#### Scenario: The bookkeeper accepts the self-billed invoice

- GIVEN draft self-billed invoice SB-2026-00042 that matches the delivery
- WHEN the bookkeeper issues it on its AR invoice page
- THEN it shows state issued and a posted general ledger transaction for EUR 6,540
