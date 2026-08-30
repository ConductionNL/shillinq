# gl-account-suggestion-consume Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- gl-account-suggestion-consume

## Purpose

Consumes docudesk's already-shipped `ai-gl-account-suggestion` capability (archived
`docudesk/openspec/changes/archive/2026-07-13-ai-gl-account-suggestion/`) so a bookkeeper
reviewing a `SupplierInvoice`/`Receipt` extraction draft sees a suggested grootboekrekening with
confidence + rationale, always confirms explicitly before booking, and has that booking fed back
to docudesk so future suggestions improve. shillinq supplies its own chart of accounts as the
candidate set (docudesk never learns one, ADR-022) and is the only place a human actually books a
document to a GL account. Builds on `receipt-extraction-consume` (the `SupplierInvoice`/`Receipt`
extraction-draft surface, `FieldConfidenceBadge`, the `ExtractionRequestController` proxy pattern)
without modifying that capability's requirements.

## ADDED Requirements

@e2e exclude the outbound HTTP clients and the correlation/candidate-resolution logic are
backend/integration surfaces covered by PHPUnit; the suggestion display, "use suggestion" action
and graceful-degradation UI are covered by the vitest specs referenced per scenario below.

### Requirement: REQ-GAC-001 — The system SHALL learn a docudesk financial-extraction id from the synchronous extraction-request response

When `DocudeskExtractionClient::requestExtraction()` succeeds, the system SHALL read the
`financialExtraction` object id from the response body and, when the request targeted an existing
shillinq draft, persist it as `docudeskExtractionId` on that draft. This id is the ONLY channel
available (docudesk's `extraction.completed` and `gl-account.suggested` events carry no field a
shillinq draft can be correlated by) and MUST NOT be fabricated or guessed when absent.

#### Scenario: A successful (re-)extraction request captures and persists the extraction id

- **GIVEN** an existing `SupplierInvoice` draft with a `sourceDocumentUri`
- **WHEN** the operator (re-)requests extraction and docudesk responds `201` with
  `{id: "ext-123", ...}`
- **THEN** the draft SHALL be updated with `docudeskExtractionId: "ext-123"`

#### Scenario: A first-ever extraction request (no existing draft) has nothing to persist onto

- **GIVEN** no shillinq draft exists yet for a `documentUri`
- **WHEN** the operator triggers extraction and docudesk responds `201` with an id
- **THEN** the request SHALL still succeed (`202`-equivalent acceptance to the caller)
- **AND** no error SHALL be raised for the absence of a draft to persist the id onto

### Requirement: REQ-GAC-002 — The system SHALL supply shillinq's own active chart-of-accounts as candidate accounts, scoped to the draft's administration

Before requesting a suggestion, the system SHALL load the draft's administration's `Account`
records filtered to `lifecycleState: active` and map them to `{code: accountNumber, label: name}`
candidates. Accounts belonging to a different administration, or in `blocked`/`archived` state,
SHALL NOT be included.

#### Scenario: Candidates are scoped to the draft's own administration

- **GIVEN** administration `adm-1` has active accounts `4300`/`4400` and administration `adm-2`
  has active account `9999`
- **WHEN** a suggestion is requested for a draft in `adm-1`
- **THEN** the candidate set SHALL include `4300` and `4400`
- **AND** SHALL NOT include `9999`

#### Scenario: Blocked and archived accounts are excluded from candidates

- **GIVEN** administration `adm-1` has an active account `4300` and a blocked account `4999`
- **WHEN** a suggestion is requested for a draft in `adm-1`
- **THEN** the candidate set SHALL include `4300`
- **AND** SHALL NOT include `4999`

### Requirement: REQ-GAC-003 — The system SHALL request and surface a GL-account suggestion with confidence and rationale

Given a draft with a known `docudeskExtractionId`, the system SHALL call docudesk's
`POST /api/extraction/{id}/suggest-account` with the REQ-GAC-002 candidate set and surface the
top-ranked result (code, label, confidence, rationale) on the review step, reusing the existing
`FieldConfidenceBadge` display pattern (percentage + text label, never colour-only). The operator
SHALL be offered a "use suggestion" action that fills the account picker; selecting it SHALL NOT
itself commit the booking.

#### Scenario: A history-backed suggestion is surfaced with its rationale

- **GIVEN** a draft with a known `docudeskExtractionId` and docudesk returns a suggestion
  `{code: "4300", label: "Kantoorkosten", confidence: 0.8, rationale: "Booked to 4300 in 8 of the
  last 10 invoices from this supplier"}`
- **WHEN** the operator opens the review step
- **THEN** the suggested code/label, its confidence badge, and its rationale text SHALL all be
  displayed
- @e2e src/modals/**/BillImportModal*.spec.js

#### Scenario: Using the suggestion fills the picker without committing

- **GIVEN** a surfaced suggestion for account `4300`
- **WHEN** the operator clicks "Use suggestion"
- **THEN** the GL-account picker SHALL be set to `4300`
- **AND** the booking SHALL NOT be committed until the operator separately clicks Save
- @e2e src/modals/**/BillImportModal*.spec.js

### Requirement: REQ-GAC-004 — Confidence SHALL inform, never bypass, human confirmation of the booked account

The system MUST NOT auto-fill or auto-book the suggested account without an explicit operator
action; the existing Save/confirm flow (`receipt-extraction-consume` REQ-RXC-006) remains the only
way a booking commits, regardless of how confident the suggestion is.

#### Scenario: A high-confidence suggestion still requires the operator to act

- **GIVEN** a suggestion with `confidence: 0.95`
- **WHEN** the review step renders
- **THEN** the GL-account picker SHALL remain whatever it already was (unset, or a prior value) —
  NOT auto-set to the suggestion
- **AND** committing the booking SHALL still require the explicit Save action
- @e2e src/modals/**/BillImportModal*.spec.js

### Requirement: REQ-GAC-005 — The system SHALL feed every committed booking back to docudesk as a correction

The system SHALL POST `{fields: {glAccountCode, glAccountLabel}}` to docudesk's
`POST /api/extraction/{id}/corrections` whenever a draft with a known `docudeskExtractionId` is
committed (Save/confirm) with a non-empty GL-account value, whether or not the committed code
matches the suggestion. This call SHALL be best-effort: a failure SHALL be logged and SHALL NOT
block, undo, or error the already-successful local booking.

#### Scenario: Booking to the suggested account still posts a correction

- **GIVEN** a draft with `docudeskExtractionId` known and suggestion `4300`
- **WHEN** the operator books to `4300` (matching the suggestion) and saves
- **THEN** a correction `{fields: {glAccountCode: "4300"}}` SHALL be posted to docudesk

#### Scenario: Overriding the suggestion posts the operator's chosen account, not the suggestion

- **GIVEN** a draft with `docudeskExtractionId` known and suggestion `4300`
- **WHEN** the operator books to `4900` instead and saves
- **THEN** a correction `{fields: {glAccountCode: "4900"}}` SHALL be posted to docudesk
- **AND** the posted code SHALL be the operator's chosen account, never the original suggestion

#### Scenario: A docudesk-side failure does not affect the already-committed local booking

- **GIVEN** a draft with `docudeskExtractionId` known
- **WHEN** the operator books and saves, and the correction POST to docudesk fails
- **THEN** the local booking SHALL remain committed and the Save action SHALL report success
- **AND** the failure SHALL be logged, not surfaced as an error to the operator

### Requirement: REQ-GAC-006 — Absence of a suggestion SHALL degrade gracefully to plain manual booking

The system SHALL NOT show an error and SHALL NOT crash when `docudeskExtractionId` is unknown,
docudesk is unreachable, or the suggestion result is empty; the review step SHALL behave exactly
as plain manual booking (existing `GlAccountPicker`, no suggestion block rendered) in all three
cases.

#### Scenario: No known extraction id — plain manual booking, no error

- **GIVEN** a draft with no `docudeskExtractionId` (e.g. seeded before this change)
- **WHEN** the operator opens the review step
- **THEN** no suggestion block SHALL be rendered
- **AND** the GL-account picker SHALL work exactly as before this change
- @e2e src/modals/**/BillImportModal*.spec.js

#### Scenario: docudesk unreachable — graceful degradation

- **GIVEN** a draft with a known `docudeskExtractionId`
- **WHEN** the suggestion request to docudesk fails (network error, 503, or docudesk not
  installed)
- **THEN** the review step SHALL render with no suggestion block and no error dialog
- **AND** the operator SHALL still be able to book the account manually and save

#### Scenario: docudesk returns an empty suggestion result

- **GIVEN** a draft with a known `docudeskExtractionId` and no booking history / rule match exists
  for its supplier
- **WHEN** the suggestion is requested
- **THEN** docudesk's honest empty `suggestedAccounts: []` result SHALL be treated identically to
  "no suggestion available" — no fabricated suggestion SHALL ever be displayed

## Non-Functional Requirements

- **Performance:** the suggestion request MUST NOT block rendering the review step's other
  fields; it MAY resolve asynchronously after the step is already visible.
- **Accessibility:** the suggestion badge and rationale MUST meet WCAG 2.1 AA and MUST NOT convey
  status by colour alone (reuses `FieldConfidenceBadge`, already compliant).
- **Internationalization:** Dutch and English MUST be supported (ADR-005); i18n keys in English;
  the rationale text is passed through verbatim from docudesk (already English/opaque per
  REQ-GLS-07) and is not itself translated.

## Acceptance Criteria

- [ ] `docudeskExtractionId` captured from the synchronous extraction-request response and persisted on the draft
- [ ] Candidate accounts supplied from shillinq's own active, administration-scoped chart of accounts
- [ ] Suggestion (code/label/confidence/rationale) surfaced via the `FieldConfidenceBadge` pattern; "use suggestion" fills the picker without auto-committing
- [ ] Human confirmation always required — no auto-booking regardless of confidence
- [ ] Every committed booking with a known extraction id posts a `glAccountCode` correction back to docudesk, override or not
- [ ] No known extraction id / docudesk unreachable / empty suggestion all degrade to plain manual booking with no error

## Notes

- Canonical suggestion/ranking contract owned by docudesk `ai-gl-account-suggestion`
  (archived `2026-07-13-ai-gl-account-suggestion`); this spec is the consume side and does not
  modify that capability.
- Related: `receipt-extraction-consume` (the extraction-draft surface, `FieldConfidenceBadge`,
  `ExtractionRequestController` proxy pattern this change extends), `bookkeeping-chart-of-accounts`
  (the `Account` schema this change reads as candidates).
