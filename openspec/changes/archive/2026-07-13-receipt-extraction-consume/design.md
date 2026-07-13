# Design: receipt-extraction-consume

## Architecture Overview

```
docudesk (extraction engine, owns the contract)
   │  POST /api/extraction/financial  {fileId|documentUri, docType, callbackEvent:true}
   │◀───────────── shillinq DocudeskExtractionClient (re-request)
   │
   ▼  nl.conduction.docudesk.extraction.completed
      {documentUri, requestedBy, sourceApp, docType, fields, fieldConfidence, overallConfidence}
   │
   ▼
ExtractionCompletedListener
   │  resolve by documentUri + docType
   ▼
ExtractionPrefillService
   │  write uncommitted draft (SupplierInvoice | Receipt)
   │  values + fieldConfidence map + overallConfidence + sourceDocumentUri (docudesk://…)
   ▼
Draft (OpenRegister object, status: draft)
   │
   ▼  operator opens
BillImportModal / Receipt capture surface
   - per-field values + confidence + review flags
   - correction flow (edit any field, record human-corrected)
   - human confirmation required to commit (confidence never bypasses it)
```

## API Design

### `POST /api/extraction/financial` (docudesk — consumed, not defined here)
**Request (shillinq → docudesk):**
```json
{ "documentUri": "docudesk://attachments/00000000-0000-0000-0000-000000000000/invoice.pdf", "docType": "supplier-invoice", "callbackEvent": true }
```
**Response:** `202 Accepted` — result arrives asynchronously as
`nl.conduction.docudesk.extraction.completed`.

### Event consumed: `nl.conduction.docudesk.extraction.completed`
```json
{ "documentUri": "docudesk://attachments/00000000-0000-0000-0000-000000000000/invoice.pdf",
  "requestedBy": "alice", "sourceApp": "shillinq", "docType": "supplier-invoice",
  "fields": { "invoiceNumber": "F-2026-88", "totalIncl": 1210.00 },
  "fieldConfidence": { "invoiceNumber": 0.97, "totalIncl": 0.9 }, "overallConfidence": 0.93 }
```

A thin shillinq endpoint (`POST /api/v1/extraction/request`) proxies the operator's
re-request to docudesk so the frontend never needs docudesk credentials directly.

## Database Changes

None (ADR-022). Drafts are OpenRegister `SupplierInvoice` / `Receipt` objects; the
per-field confidence is stored on the draft as a `fieldConfidence` map plus
`overallConfidence` and a `humanCorrected` set (additive optional fields on the
draft schemas, defined in the register fragment).

## Nextcloud Integration

- Controllers: `ExtractionRequestController` (thin proxy to docudesk;
  `#[NoAdminRequired]`, acts within the user's administration — no IDOR).
- Services: `Extraction/ExtractionPrefillService` (map event → draft),
  `Extraction/DocudeskExtractionClient` (outbound request).
- Listeners: `ExtractionCompletedListener` for
  `nl.conduction.docudesk.extraction.completed`, registered in
  `lib/AppInfo/Application.php`.
- Events/Hooks: consume only; no new shillinq event class.

## Security Considerations

- The re-request proxy validates the caller may act on the target document within
  their administration (guarded in the method body — no IDOR on arbitrary
  `documentUri`).
- Extracted values are treated as untrusted input: numeric/date fields are parsed
  and validated by the draft's JSON Schema before persistence; no eval of payload.
- `sourceDocumentUri` is validated against the docudesk URI regex
  (`^docudesk://attachments/[a-f0-9-]{36}/.+$`, REQ-DA-002).
- No document bytes are stored in shillinq — only the docudesk FK URI.

## NL Design System

Confidence is shown as a labelled indicator (e.g. a percentage + a text badge
"needs review"), never colour-only; review flags use `NcNoteCard`/inline text.
Correctable fields use standard NC inputs with `inputLabel`s. CSS variables only.

## File Structure

```
lib/
  Listener/
    ExtractionCompletedListener.php          (new)
  Service/Extraction/
    ExtractionPrefillService.php             (new — event → draft)
    DocudeskExtractionClient.php             (new — outbound re-request)
  Controller/
    ExtractionRequestController.php          (new — thin proxy)
  AppInfo/Application.php                     (modified — register listener + routes)
  Settings/register.d/
    receipt-extraction-consume.json          (new — draft confidence fields)
src/
  modals/BillImportModal.vue                  (modified — prefill + confidence + correction)
  views/ReceiptCapture.vue                    (modified — confidence-aware prefill)
  components/FieldConfidenceBadge.vue          (new)
```

## Seed Data

Draft objects carry the `@self` envelope `{register: "shillinq", schema:
"SupplierInvoice"}` / `{... schema: "Receipt"}`. Consultancy + MKB flavour.

### Schema: `SupplierInvoice` (extraction-draft seed)
| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | supplier-invoice-draft-0001 | supplier-invoice-draft-0002 |
| status | draft | draft |
| invoiceNumber | F-2026-88 | — (low confidence, flagged) |
| totalIncl | 1210.00 | 452.00 |
| sourceDocumentUri | docudesk://attachments/00000000-0000-0000-0000-000000000000/invoice.pdf | docudesk://attachments/00000000-0000-0000-0000-000000000001/bon.pdf |
| overallConfidence | 0.93 | 0.61 |
| fieldConfidence | {invoiceNumber:0.97, totalIncl:0.90, glAccount:0.55} | {totalIncl:0.62, invoiceNumber:0.40} |

### Schema: `Receipt` (extraction-draft seed)
| Field | Object 1 |
|-------|----------|
| slug | receipt-draft-0001 |
| amount | 45.00 |
| currency | EUR |
| receiptDate | 2026-02-10 |
| extractedText | "LUNCH CAFE DE JONG ... TOTAAL 45,00" |
| photoUri | docudesk://attachments/00000000-0000-0000-0000-000000000002/receipt.jpg |
| overallConfidence | 0.88 |

**Related items per object:**
- Files: each draft's `sourceDocumentUri`/`photoUri` references a docudesk document.
- Notes: Object 2 (low confidence) carries a "needs review" note.
- Tasks: none.
- Contacts: none.

## Declarative-vs-imperative decision (ADR-031)

- **Declarative:** the draft `fieldConfidence` / `overallConfidence` /
  `humanCorrected` fields and the `sourceDocumentUri` URI-format validation are
  declared on the draft schemas (register fragment); drafts start in the schema's
  initial `draft` state and MUST NOT trigger accounting side effects on arrival
  (consistent with semantic-invoice-consume REQ-SIC-004).
- **Imperative (justified — external integration):**
  - `ExtractionCompletedListener` + `ExtractionPrefillService` — consume an external
    event and map it onto a draft (integration glue; the mapping is not expressible
    declaratively because it cross-walks docudesk's field vocabulary).
  - `DocudeskExtractionClient` + `ExtractionRequestController` — outbound HTTP to
    docudesk (external integration).

## Trade-offs

- **Consume docudesk vs. build OCR in shillinq.** Chosen: consume — document
  intelligence is docudesk's domain (ADR-022); duplicating it forks a hard problem
  and diverges. Alternative rejected.
- **Draft-first vs. direct commit.** Chosen: always land an uncommitted draft; a
  human confirms. Alternative (auto-post above a confidence threshold) is rejected —
  a wrong booking is worse than a manual click, and rechtmatigheid requires a human
  in the loop.
- **Keep the honest 422 deferral.** Chosen: when docudesk has no result, retain the
  existing `deferred: pdf-ocr` behaviour rather than showing empty "extracted"
  fields — never fabricate.

## Migration Plan

Additive. Deploy the register fragment (draft confidence fields), the listener +
services + proxy controller, then the UI. Rollback = unregister the listener, hide
the confidence UI and re-request button; the PDF path reverts to the 422 deferral,
UBL/CSV imports unaffected.

## Open Questions

- The confidence thresholds (review flag / one-click gate) — proposed 0.8 per-field
  and 0.9 overall; a config value, does not change observable behaviour (confidence
  shown, human confirms).
