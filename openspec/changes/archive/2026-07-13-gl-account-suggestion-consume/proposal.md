---
kind: code
---

# Proposal: gl-account-suggestion-consume

## Summary

Consumes docudesk's newly-shipped `ai-gl-account-suggestion` capability (PRs #173/#174) so a
bookkeeper reviewing a supplier-invoice draft in `BillImportModal` (and a receipt draft in
`ReceiptCapture`) sees a **suggested grootboekrekening** with its confidence and a human-readable
rationale ("booked to 4300 Kantoorkosten in 8 of the last 10 invoices from this supplier"), reuses
the existing `FieldConfidenceBadge` pattern to display it, always requires an explicit human
confirmation before booking, and — when the operator books to a different account than
suggested (or simply confirms one) — feeds that outcome back to docudesk as a correction so the
ranker's history improves over time. Without this change docudesk's suggester is dead weight:
nothing in shillinq asks for a suggestion and nothing feeds the booking history back.

## Motivation

docudesk's `ai-gl-account-suggestion` (archived `docudesk/openspec/changes/archive/2026-07-13-ai-gl-account-suggestion/`)
explicitly named this shillinq change as its consume-side follow-up (design.md Decision D6). It
deliberately never learns a chart of accounts — account codes are opaque strings the *consumer*
supplies as `candidateAccounts` and as `glAccountCode` corrections. shillinq is the only fleet app
with a real RGS chart of accounts (`bookkeeping-chart-of-accounts`, the `Account` OR schema) and
the only place a human actually books a document to a GL account
(`BillImportModal`'s `glAccount` field, backed by `GlAccountPicker`). Today that booking is 100%
manual guesswork; docudesk's ranker already exists and is unused.

## Affected Projects

- [x] Project: `shillinq` — requests a suggestion (supplying candidate GL accounts from its own
  chart of accounts), surfaces it with confidence + rationale, and posts the eventual booking back
  to docudesk as a correction.
- [ ] Project: `docudesk` — owns the ranking/suggestion engine and the correction-feedback
  endpoint (`ai-gl-account-suggestion` spec, already shipped and archived). Coupled via two
  outbound HTTP requests to docudesk's already-shipped REST endpoints (ADR-022); not modified by
  this change.

## Scope

### In Scope

- A synchronous request for a GL-account suggestion for a financial-extraction draft
  (`SupplierInvoice`/`Receipt`), supplying shillinq's own active chart-of-accounts entries (scoped
  to the draft's administration) as `candidateAccounts`.
- Surfacing the suggestion (code, label, confidence, rationale, source) on the review step,
  reusing the `FieldConfidenceBadge` component/pattern already used for extraction-field
  confidence.
- A "use suggestion" action that fills the account picker — never an auto-fill/auto-book; the
  operator always clicks Save/confirm explicitly (REQ-RXC-006 precedent, unchanged).
- Feeding the operator's eventual booking decision back to docudesk as a `glAccountCode`
  correction on the underlying financial extraction, so future suggestions for that supplier
  improve — this closes the loop and is the point of the change.
- Graceful degradation: no known docudesk extraction id, no reachable docudesk, or an empty
  suggestion result all degrade to today's plain manual `GlAccountPicker` booking — never an
  error, never a fabricated suggestion.

### Out of Scope

- Any change to docudesk's ranking, cold-start rules, or its shipped REST/event contract — it is
  consumed exactly as shipped and archived.
- Auto-booking without human confirmation — out of scope by design (REQ-RXC-006 already
  established this boundary for the extraction-consume change; this change does not weaken it).
- A dedicated admin UI for docudesk's `glAccountMappingRule` cold-start table — that is plain OR
  CRUD per docudesk's own design and is not shillinq's concern.
- Deriving a `financialExtraction` id from anything other than the synchronous response of the
  extraction-request call shillinq already makes (see design.md — the async
  `extraction.completed`/`gl-account.suggested` events carry no field shillinq could otherwise
  correlate a draft by).

## Approach

Extends the already-shipped `receipt-extraction-consume` proxy: `DocudeskExtractionClient`
additionally captures the `financialExtraction` object id from the synchronous response of its
existing `POST /api/extraction/financial` call and persists it on the shillinq draft
(`docudeskExtractionId`); a new `GlAccountSuggestionClient` calls docudesk's already-shipped
`POST /api/extraction/{id}/suggest-account` and `POST /api/extraction/{id}/corrections`; a new
`ChartOfAccountsCandidateService` supplies shillinq's own active `Account` rows as candidates;
`ExtractionRequestController` gains a `suggest-account` proxy action and, on every committed
booking with a known extraction id, posts the booked `glAccountCode` back as a correction.
`BillImportModal`/`ReceiptCapture` fetch and render the suggestion on the review step. Full detail
in design.md.

## New Dependencies

None. Uses the already-shipped docudesk `ai-gl-account-suggestion` REST endpoints (NC intra-instance
routes) exactly as archived; no new package/library/external service.

## Impact

- New: `lib/Service/Extraction/GlAccountSuggestionClient.php`,
  `lib/Service/Extraction/ChartOfAccountsCandidateService.php`.
- Modified: `lib/Service/Extraction/DocudeskExtractionClient.php` (captures the extraction id),
  `lib/Controller/ExtractionRequestController.php` (new `suggestGlAccount` action; `confirm()`
  posts a correction), `appinfo/routes.php` (new route), `lib/Settings/register.d/` (new fragment
  adding `docudeskExtractionId` + `suggestedGlAccount` to `SupplierInvoice` and `Receipt`, plus
  `glAccount` to `Receipt`).
- Frontend: `src/modals/BillImportModal.vue` + `src/modals/billImportModal.js`,
  `src/views/ReceiptCapture.vue` + `src/views/receiptCapture.js`,
  `src/utils/extractionConfidence.js` (shared suggestion helpers).

## Cross-Project Dependencies

- Consumes docudesk's `ai-gl-account-suggestion` REST endpoints
  (`POST /api/extraction/{id}/suggest-account`, the `glAccountCode` extension of
  `POST /api/extraction/{id}/corrections`) exactly as shipped/archived — read-only dependency, no
  changes requested of docudesk.

## Risks

### Risk 1: The financial-extraction id is only known when shillinq itself requested the extraction

**Severity:** Medium — **Mitigation:** docudesk's shipped contract does not include an
`extractionId` in either the `extraction.completed` or the `gl-account.suggested` event payload
(verified by reading both event classes on `origin/development`), so the only channel is the
synchronous 201 response of shillinq's own `POST /api/extraction/financial` call, which already
happens via `DocudeskExtractionClient`. A draft whose extraction predates this change (or was
never (re-)requested through shillinq's own proxy) has no known extraction id and the suggestion
feature degrades gracefully to plain manual booking — never blocking, never guessing an id.

### Risk 2: Feeding every booking back (not only overrides) could be noisy

**Severity:** Low — **Mitigation:** docudesk's `HistoryRanker` counts frequency over *all* past
bookings, agreeing or not (that is how "8 of the last 10" becomes meaningful); posting every
committed booking — not only overrides — is the behaviour docudesk's own ranker is designed to
consume. Best-effort/fail-soft: a failed correction POST never blocks or rolls back the local
booking that already succeeded.

## Rollback Strategy

Additive only. Rollback = stop calling the new `suggest-account`/`corrections` docudesk endpoints
(remove the frontend suggestion block + the controller action), leaving the plain
`GlAccountPicker` manual-booking flow exactly as it was before this change; the new
`docudeskExtractionId`/`suggestedGlAccount` schema fields are additive and harmless if left
unused.

## Open Questions

None outstanding — the one open design question (how to correlate a shillinq draft to a docudesk
`financialExtraction` id) is resolved in design.md Decision D1.
