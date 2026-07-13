# Design: gl-account-suggestion-consume

## Architecture Overview

```
shillinq (this change)                         docudesk (already shipped, archived, read-only)
─────────────────────                          ──────────────────────────────────────────────
DocudeskExtractionClient::requestExtraction()
   POST /api/extraction/financial          ───▶  FinancialExtractionService::extractFinancial()
   (unchanged call; NOW also reads the id         persists `financialExtraction`, returns it
   out of the 201 response body)           ◀───  (201, body includes the object's `id`)
   │
   ▼ persist `docudeskExtractionId` on the shillinq draft (SupplierInvoice|Receipt)

Operator opens the draft in BillImportModal / ReceiptCapture (review step)
   │
   ▼ ExtractionRequestController::suggestGlAccount($id, $schema)
      ChartOfAccountsCandidateService::activeCandidates($administrationId)
         (shillinq's OWN Account register — the candidate set docudesk never has)
      GlAccountSuggestionClient::requestSuggestion($extractionId, $candidateAccounts)
         POST /api/extraction/{id}/suggest-account                ───▶  GlAccountSuggestionService::suggest()
                                                                    ◀───  {suggestedAccounts:[{code,label,
                                                                          confidence,rationale}], source}
   │
   ▼ FieldConfidenceBadge + rationale text rendered next to GlAccountPicker
      operator picks an account (suggested or not) and clicks Save — human ALWAYS confirms

ExtractionRequestController::confirm($id, $schema)
   persists the correction as today (REQ-RXC-004, unchanged)
   │
   ▼ when docudeskExtractionId is known: GlAccountSuggestionClient::postCorrection()
      POST /api/extraction/{id}/corrections {fields:{glAccountCode,glAccountLabel}}   ───▶  recorded as
                                                                                             glAccountBooking
                                                                                             history (feeds
                                                                                             future rankings)
```

## API Design

Both endpoints below are docudesk's — already shipped and archived in
`docudesk/openspec/changes/archive/2026-07-13-ai-gl-account-suggestion/`. Consumed exactly as
shipped; documented here only so the shillinq call sites are traceable to the real contract (read
from `GlAccountSuggestionController.php` / `GlAccountSuggestionService.php` on
`docudesk` `origin/development`, not from the proposal text).

### `POST /apps/docudesk/api/extraction/{id}/suggest-account` (docudesk, consumed)
Route name (NC intra-instance convention): `docudesk.glAccountSuggestion.suggestAccount`.
**Request (shillinq → docudesk):**
```json
{ "candidateAccounts": [ { "code": "4300", "label": "Kantoorkosten" }, { "code": "4400", "label": "Representatiekosten" } ],
  "sourceApp": "shillinq" }
```
**Response:**
```json
{ "extractionId": "00000000-0000-0000-0000-000000000000", "supplierIdentity": "12345678",
  "identityType": "kvk",
  "suggestedAccounts": [ { "code": "4300", "label": "Kantoorkosten", "confidence": 0.8,
    "rationale": "Booked to 4300 in 8 of the last 10 invoices from this supplier" } ],
  "source": "history" }
```

### `POST /apps/docudesk/api/extraction/{id}/corrections` (docudesk, consumed — already used by receipt-extraction-consume, extended field)
Route name: `docudesk.extraction.corrections`. shillinq now additionally posts `glAccountCode`
(+ optional `glAccountLabel`) inside `fields` after every committed booking with a known
extraction id (not only overrides — see Decision D3).
```json
{ "fields": { "glAccountCode": "4300", "glAccountLabel": "Kantoorkosten" } }
```

### `POST /apps/shillinq/api/v1/extraction/drafts/{id}/suggest-account` (NEW, this change)
Thin proxy, mirrors the existing `ExtractionRequestController::confirm()` IDOR guard.
**Request:** `{ schema: "SupplierInvoice" | "Receipt" }` (query param, same convention as `confirm()`).
**Response (suggestion available):**
```json
{ "suggestion": { "code": "4300", "label": "Kantoorkosten", "confidence": 0.8,
  "rationale": "Booked to 4300 in 8 of the last 10 invoices from this supplier", "source": "history" } }
```
**Response (graceful degradation — no known extraction id, docudesk unreachable, or an empty
result):**
```json
{ "suggestion": null, "reason": "extraction-id-unknown" }
```
`reason` is one of `extraction-id-unknown` | `provider-unavailable` | `no-suggestion` — informational
only, the frontend treats all three identically (fall back to plain manual booking).

## Database Changes

None (ADR-022/ADR-024) — additive OR schema properties only, declared in a new register.d
fragment; see Seed Data.

## Nextcloud Integration

- Controllers: `ExtractionRequestController` (extended — new `suggestGlAccount()` action;
  `confirm()` extended to post the correction).
- Services: `Extraction/GlAccountSuggestionClient` (new — outbound HTTP, mirrors
  `DocudeskExtractionClient`), `Extraction/ChartOfAccountsCandidateService` (new — reads
  shillinq's own `Account` OR schema), `Extraction/DocudeskExtractionClient` (modified — captures
  the extraction id from the synchronous response).
- Events/Hooks: none new. This change is entirely request/response (two outbound HTTP calls to
  already-shipped docudesk endpoints) — no new event class in either direction, consistent with
  `ai-gl-account-suggestion`'s own sibling-event design not needing a mirror in the consumer when
  the consumer already gets what it needs synchronously (Decision D1).

## Security Considerations

- `suggestGlAccount()` reuses `ExtractionRequestController::guardDraftAccess()` verbatim (IDOR
  guard, ADR-005) — a caller can only request a suggestion for a draft in an administration they
  belong to; masked 404, never 403.
- `ChartOfAccountsCandidateService` scopes its OR query to the draft's own `administrationId` —
  never leaks another tenant's chart of accounts as candidates.
- The outbound calls to docudesk carry no credentials/secrets (NC intra-instance route,
  `IURLGenerator::linkToRouteAbsolute`, same as `DocudeskExtractionClient`); account codes/labels
  are the only payload, already opaque strings by docudesk's own design (REQ-GLS-07).
- Both new HTTP calls are best-effort/fail-soft: a docudesk-side failure never blocks or reverts
  the operator's already-committed local booking (correction feedback is telemetry for future
  suggestions, not part of the booking's correctness).

## NL Design System

The suggestion is rendered with the existing `FieldConfidenceBadge` (percentage + text label,
never colour-only, WCAG 2.1 AA) plus a plain-text rationale sentence; a "Use suggestion" button is
a standard `NcButton` (secondary). No new component styling primitives.

## File Structure

```
lib/
  Service/Extraction/
    DocudeskExtractionClient.php          (modified — captures extractionId)
    GlAccountSuggestionClient.php         (new)
    ChartOfAccountsCandidateService.php   (new)
  Controller/
    ExtractionRequestController.php       (modified — suggestGlAccount() + confirm() feedback)
  Settings/register.d/
    gl-account-suggestion-consume.json    (new)
appinfo/
  routes.php                             (modified — 1 new route)
src/
  modals/BillImportModal.vue              (modified — suggestion block)
  modals/billImportModal.js               (modified — pure helpers)
  views/ReceiptCapture.vue                (modified — suggestion block + glAccount field)
  views/receiptCapture.js                 (modified — pure helpers)
  utils/extractionConfidence.js           (modified — shared suggestion-summary helper)
```

## Seed Data

Extends the existing `receipt-extraction-consume` seed drafts additively (same objects, new
fields) rather than introducing new seed objects — a cached suggestion is a *result* of
requesting one, not a standalone entity, so seeding it on an existing draft is the realistic
shape.

### Schema: `SupplierInvoice` (additive fields on the existing `supplier-invoice-draft-0001` seed)
| Field | Value |
|-------|-------|
| `docudeskExtractionId` | `00000000-0000-0000-0000-000000000099` |
| `suggestedGlAccount` | `{code: "4300", label: "Kantoorkosten", confidence: 0.8, rationale: "Booked to 4300 in 8 of the last 10 invoices from this supplier", source: "history"}` |

### Schema: `Receipt` (additive fields on the existing `receipt-draft-0001` seed)
| Field | Value |
|-------|-------|
| `glAccount` | `""` (unset — operator has not booked it yet) |
| `docudeskExtractionId` | `00000000-0000-0000-0000-000000000098` |
| `suggestedGlAccount` | `{code: "4400", label: "Representatiekosten", confidence: 0.4, rationale: "Keyword 'lunch' matched mapping rule → 4400", source: "keyword-rule"}` |

**Related items per object:** unchanged from `receipt-extraction-consume` (Files:
`sourceDocumentUri`; Notes: the low-confidence draft's existing "needs review" note also covers
the cold-start GL suggestion; Tasks/Contacts: none).

## Declarative-vs-imperative decision (ADR-031)

**Declarative:** the two new schema properties (`docudeskExtractionId`, `suggestedGlAccount`) and
the additive `glAccount` field on `Receipt` are plain OR schema properties in a register.d
fragment — no lifecycle, no calculation, no relation (they are opaque values written by imperative
code below, not derived from other fields).

**Imperative (justified — external integration, same exception `receipt-extraction-consume`
already used for its own client/listener):**
- `GlAccountSuggestionClient` / `ChartOfAccountsCandidateService` — outbound HTTP to docudesk and
  a cross-walk from shillinq's own `Account` rows to docudesk's opaque `candidateAccounts` shape;
  neither is expressible as an OR calculation.
- `ExtractionRequestController::suggestGlAccount()` / the `confirm()` correction-feedback addition
  — request/response orchestration, not a derived-field recomputation.

## Decisions

### D1 — Correlate a shillinq draft to a docudesk `financialExtraction` id via the synchronous request response, not the async events

docudesk's shipped contract gives no channel to learn a `financialExtraction` id other than the
*synchronous* response of `POST /api/extraction/financial` — verified by reading both
`FinancialExtractionCompletedEvent::toPayload()` (carries `documentUri`, not an id) and
`GlAccountSuggestedEvent::toPayload()` (carries `extractionId`, but not `documentUri`, so it
cannot be correlated back to a shillinq draft that is keyed by `sourceDocumentUri`). But
`FinancialExtractionService::extractFinancial()` already returns the persisted `financialExtraction`
object (which naturally includes its `id`) as the 201 response body of the very call
`DocudeskExtractionClient::requestExtraction()` already makes — shillinq was simply discarding it.
**Chosen:** capture `id` from that response and persist it as `docudeskExtractionId` on the
shillinq draft at (re-)request time. **Rejected alternative:** querying docudesk's OR-backed
`financialExtraction` register/schema directly by `documentUri` — works in principle (OR's
generic CRUD is instance-wide) but requires knowing docudesk's admin-configurable register/schema
slugs and reads a foreign app's internal storage shape rather than its documented contract; the
synchronous-response approach needs no such knowledge and is exactly the "optionally calling
`POST /api/extraction/{id}/suggest-account` directly" path docudesk's own design.md D6 names as
the expected shillinq behaviour.

**Consequence, honestly stated:** a draft whose extraction was never (re-)requested through
shillinq's own proxy (e.g. one seeded before this change, or a hypothetical future path that
creates a draft some other way) has no known `docudeskExtractionId` and the suggestion feature
degrades to plain manual booking. This is not a limitation this change can remove without
docudesk adding an id to its event payloads — out of scope (proposal.md, docudesk not modified).

### D2 — Suggestion is requested synchronously on demand, not cached from an event subscription

**Chosen:** `suggestGlAccount()` is a pull (operator opens the review step → shillinq calls
docudesk synchronously) rather than subscribing to `nl.conduction.docudesk.gl-account.suggested`.
**Rationale:** the sibling event's payload cannot be correlated to a shillinq draft (D1) since it
carries no `documentUri`; even if it could, the suggestion is only actionable while an operator is
looking at the review step, exactly when the synchronous round-trip is cheap and no listener/queue
plumbing is needed. **Rejected:** subscribing to the event and caching the last suggestion
per-extraction-id would require inventing a lookup table keyed by an id docudesk never
correlates back to shillinq's own object identity for shillinq's purposes — solving a problem D1
already avoids.

### D3 — Feed every committed booking back, not only overrides

**Chosen:** `ExtractionRequestController::confirm()` posts a `glAccountCode` correction whenever a
`docudeskExtractionId` is known and a `glAccount` was set, whether or not it matches the
suggestion. **Rationale:** docudesk's `HistoryRanker` counts frequency over *all* past bookings —
an operator confirming the suggested account is exactly the "yes, keep booking this supplier to
4300" signal the ranker is designed to accumulate; only feeding overrides would silently starve
the ranker of its main confirming signal and bias the corpus toward corrections. **Rejected:**
posting only on override (mirrors the wording "when the user books to a different account…"
literally) — rejected because it would mean an operator who agrees with 9 out of 10 suggestions
teaches the ranker nothing on the 9 agreements, which contradicts how docudesk's own ranker
computes "N of the last 10" confidence. The override case remains the one this change's tests
name explicitly, because it is the case that proves the posted code is the operator's actual
choice, not an echo of the suggestion.

### D4 — Candidate accounts are the administration's active chart-of-accounts entries, not the full chart

**Chosen:** `ChartOfAccountsCandidateService::activeCandidates()` queries shillinq's own `Account`
schema filtered to `administrationId` (the draft's own tenant) and `lifecycleState: active`
(REQ-CoA-005 — blocked/archived accounts are not valid new-posting targets). **Rationale:** this
is precisely "shillinq must supply its own GL accounts (RGS chart) as candidates" — passing the
full, unscoped chart (including another tenant's accounts, or blocked/archived ones) would leak
data across tenants and let docudesk rank codes the operator could not legally book to anyway.

## Trade-offs

- **Synchronous suggestion request vs. event-driven caching (D2).** Chosen: synchronous — see D2.
  Trade-off accepted: one extra round-trip per review-step open, bounded by the operator already
  waiting on the review UI to render.
- **Feed every booking vs. only overrides (D3).** Chosen: every booking — see D3. Trade-off:
  slightly more outbound traffic to docudesk's corrections endpoint; mitigated by best-effort/
  fail-soft (never blocks the local commit).
- **Extraction id from the synchronous response vs. cross-app OR read (D1).** Chosen: synchronous
  response — see D1. Trade-off: drafts predating this change (or created outside shillinq's own
  proxy) never get a suggestion; accepted as an honest, documented limitation rather than reading
  docudesk's internal storage.

## Migration Plan

1. Ship the register.d fragment (additive `docudeskExtractionId`, `suggestedGlAccount` on
   `SupplierInvoice`/`Receipt`, `glAccount` on `Receipt`); bump the register version so
   `ConfigurationService::importFromApp()` re-imports on boot (same pattern as
   `receipt-extraction-consume`).
2. Ship `GlAccountSuggestionClient`, `ChartOfAccountsCandidateService`, the
   `DocudeskExtractionClient` id-capture change, and the controller/route additions.
3. Ship the frontend suggestion block in `BillImportModal`/`ReceiptCapture`.
4. No data migration — fully additive. Rollback = remove the frontend suggestion block and the
   new controller action/route; existing manual booking is completely unaffected (D1's schema
   fields are inert if unused).

## Open Questions

None outstanding at time of writing (see proposal.md).
