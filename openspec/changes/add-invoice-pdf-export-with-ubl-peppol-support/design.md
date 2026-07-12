# Design: add-invoice-pdf-export-with-ubl-peppol-support

## Architecture Overview

```
ARInvoice (OpenRegister object, lifecycleState: issued)
        │  operator: Send e-invoice
        ▼
EInvoiceService (orchestrator)
  1. EInvoiceValidationService  ── KvK / BTW (VIES) / Peppol participant lookup
  2. ArInvoiceUblMapper         ── ARInvoice + lines + parties → NLCIUS UBL 2.1 XML
  3. InvoicePdfGenerator (hybrid)── embed XML into PDF/A-3 (Factur-X/ZUGFeRD)
  4. store artefact (Docudesk/Files) → payloadFileUri
  5. Peppol\PeppolTransmissionPortInterface.submit(participantId,
        'ubl-invoice-2.1', payloadFileUri)                     [Log adapter default]
  6. emit  nl.conduction.peppol.outbound.requested
        │
        ▼  (event bus / openconnector cloud-events)
   openconnector Peppol access point  ── real transmission (out of this repo)
        │
        ▼  nl.conduction.peppol.delivery.status
PeppolDeliveryStatusListener → advance ARInvoice.deliveryStatus sub-lifecycle
```

The compliance artefact (UBL XML) is transmitted independently of the PDF, so a
PDF-toolchain limitation never blocks Peppol delivery. Cross-app coupling is
event-only (ADR-022); shillinq never calls the access point over direct RPC.

## API Design

No new public HTTP endpoints are strictly required — CRUD stays on OpenRegister
(ADR-022). One thin app action drives the orchestration:

### `POST /apps/shillinq/api/ar-invoices/{invoiceNumber}/send-einvoice`
**Request:**
```json
{ "administrationId": "adm-1" }
```
**Response:**
```json
{ "deliveryStatus": "queued", "transmissionId": "urn:uuid:00000000-0000-0000-0000-000000000000", "payloadFileUri": "docudesk://file/000" }
```

Consumes (from openconnector, cross-app contract — not defined here):
`GET /participants/{peppolId}` → `{ "exists": true, "supportedDocTypes": ["ubl-invoice-2.1"] }`.

## Database Changes

None — Shillinq owns no tables (ADR-022). The `ARInvoice` schema gains additive
optional fields (`deliveryStatus`, `transmissionId`, `payloadFileUri`,
`deliveryDetail`) declared in a new `lib/Settings/register.d/` fragment; no
destructive migration (all new fields optional with defaults).

## Nextcloud Integration

- Controllers: `ARInvoiceEInvoiceController` (single `send-einvoice` action;
  `#[NoAdminRequired]` + per-administration authorization guard — no IDOR).
- Services: `EInvoiceService` (orchestrator), `ArInvoiceUblMapper` (stateless),
  `EInvoiceValidationService`, generalised `Peppol\PeppolTransmissionPortInterface`
  + `LogPeppolTransmissionAdapter`; `InvoicePdfGenerator` (hybrid path).
- Mappers/Entities: none (OpenRegister objects).
- Events/Hooks: emit `nl.conduction.peppol.outbound.requested`; listener
  `PeppolDeliveryStatusListener` for `nl.conduction.peppol.delivery.status`,
  registered in `lib/AppInfo/Application.php`.

## Security Considerations

- The send action MUST be authorized per-administration (guard in the method
  body, not merely `#[NoAdminRequired]`) to prevent IDOR on arbitrary invoice ids.
- UBL parsing/serialisation uses XXE-safe libxml defaults (already relied on by
  `SupplierInvoiceService`); no external entity resolution.
- The outbound event payload carries a `payloadFileUri` (a Files/Docudesk FK),
  never inline PII-bearing XML on the bus.
- BTW-nummer / KvK are business identifiers, not secrets; log lines that include
  participant ids MUST be redacted (as the existing Log adapter already does).

## NL Design System

The Send e-invoice action uses a standard `NcButton`; the delivery-status
indicator uses a status chip with a text label (status not conveyed by colour
alone, WCAG 2.1 AA). No hardcoded colours — CSS variables only.

## File Structure

```
lib/
  Controller/
    ARInvoiceEInvoiceController.php        (new)
  Service/
    InvoicePdfGenerator.php                (modified — hybrid PDF/A-3 path)
    EInvoice/
      EInvoiceService.php                  (new — orchestrator)
      ArInvoiceUblMapper.php               (new — NLCIUS UBL 2.1 mapper)
      EInvoiceValidationService.php        (new — KvK/BTW/Peppol checks)
    Peppol/
      PeppolTransmissionPortInterface.php  (new — generalised shared port)
      LogPeppolTransmissionAdapter.php      (moved/generalised from PurchaseOrder/)
    PurchaseOrder/
      PeppolTransmissionAdapterInterface.php (thin alias/extends shared port)
  Listener/
    PeppolDeliveryStatusListener.php       (new)
  Settings/register.d/
    add-shillinq-einvoicing-ubl-peppol.json (new — ARInvoice delivery fields + lifecycle)
src/
  views/ARInvoiceDetail*.vue               (Send action + status indicator)
```

## Seed Data

Realistic seed so the feature is testable on install (ADR-016). Consultancy +
municipality flavour. All objects carry the `@self` envelope
`{register: "shillinq", schema: "ARInvoice"}`.

### Schema: `ARInvoice` (delivery-status fields added to existing seed objects)
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | ar-invoice-2026-0042 | ar-invoice-2026-0051 | ar-invoice-2026-0060 |
| invoiceNumber | 2026-0042 | 2026-0051 | 2026-0060 |
| customerId | DEB-0001 (Gemeente Zuidoost) | DEB-0007 (Advies B.V.) | DEB-0012 (ZZP Klant) |
| lifecycleState | issued | paid | issued |
| grossAmount | 1210.00 | 605.00 | 3630.00 |
| deliveryStatus | delivered | sent | not-sent |
| transmissionId | urn:uuid:00000000-0000-0000-0000-000000000000 | urn:uuid:00000000-0000-0000-0000-000000000000 | (null) |
| payloadFileUri | docudesk://file/000 | docudesk://file/000 | (null) |
| deliveryDetail | Delivered to recipient AP | Accepted by AP | (null) |

**Related items per object:**
- Files: Object 1 & 2 link the stored PDF/A-3 hybrid artefact via `payloadFileUri`.
- Notes: Object 3 (`not-sent`) carries a note "Debtor Peppol ID pending".
- Tasks: none.
- Contacts: `customerId` links to the existing `CustomerMaster` seed (debtor
  masters carry `kvkNumber`, `vatID`, and a Peppol participant id where present —
  Gemeente Zuidoost B2G participant `0106:00000000` placeholder scheme).

## Declarative-vs-imperative decision (ADR-031)

Default is declarative. This change keeps declarative where OR supports it and
justifies each imperative surface:

- **Declarative (`lib/Settings/register.d/*.json`):**
  - `ARInvoice.deliveryStatus` **field + delivery sub-lifecycle** via
    `x-openregister-lifecycle` — the state graph is data, declared, not coded.
  - Operator notification on `rejected`/`failed` via
    `x-openregister-notifications` (ADR-031 dialect), not imperative dispatch.
- **Imperative (justified):**
  - `ArInvoiceUblMapper` (XML **document generation**) — allowed imperative per
    ADR-031; mirrors the existing `PeppolBisOrderMapper`.
  - `InvoicePdfGenerator` hybrid embed (**document generation**).
  - `EInvoiceValidationService` VIES call + participant lookup (**external
    integration**).
  - Transmission port + Log adapter and the outbound-event emission (**external
    integration**), plus `PeppolDeliveryStatusListener` (event consumption that
    advances the declared sub-lifecycle) — the *listener* only drives declared
    transitions; it does not hand-roll state it could declare.

## Trade-offs

- **Generalise the PO port vs. add a parallel AR port.** Chosen: generalise into a
  shared `Peppol\PeppolTransmissionPortInterface` with a `documentType` parameter,
  keeping the PO interface as a thin alias. Alternative (a second, AR-only port)
  duplicates the Log adapter and the participant-lookup logic and drifts over
  time. The alias preserves the PO 3-way-match path with zero behavioural change.
- **PDF/A-3 embedding.** Chosen: extend `InvoicePdfGenerator` behind a hybrid
  method; add one vetted PHP PDF library only if the current renderer cannot emit
  PDF/A-3. Alternative (always ship a heavy PDF/A-3 toolchain) is rejected as
  gold-plating — the XML is the compliance artefact and travels independently.
- **Synchronous send vs. queued.** Chosen: the app emits an event and returns
  `queued` immediately; openconnector transmits asynchronously and reports back via
  `delivery.status`. Alternative (block on the access point) couples the UI to
  network latency and violates the event-only boundary (ADR-022).

## Migration Plan

Additive only. Deploy the register fragment (new optional fields default to
`not-sent`), then the services + listener, then enable the Send action.
Rollback: disable the action and revert the services/fragment; existing invoices
and the plain PDF are untouched.

## Open Questions

- Exact NLCIUS embedded-filename convention for the PDF/A-3 attachment
  (`factur-x.xml` vs. an NLCIUS-specific name) — resolve against the current
  EN 16931 CIUS guidance during apply; does not affect the spec's observable
  behaviour.
