# Spec: shillinq-delegate-signing (delta)

## MODIFIED Requirements

### Requirement: REQ-SIGN-001 — Document e-signature on a shillinq finance document SHALL be delegated to docudesk via IEventDispatcher, request-then-consume-outcome; shillinq stores only the returned reference and mirrored status and NEVER signs on local authority

shillinq MUST NOT sign documents itself. Any e-signature on a finance document
(jaarrekening PDF, ACM report, management letter) MUST be raised as a **docudesk
`DocumentSigningRequestedEvent`** dispatched through a synchronous, in-process
**`OCP\EventDispatcher\IEventDispatcher`** — NOT a hard-coded `docudesk` hostname and NOT the
non-existent integration-registry `call()` method. shillinq implements only the ADR-031
integration exception path; it owns no signing engine, PKI material, or local trail.

- `SigningDelegationService::requestSignature()` MUST dispatch
  `OCA\DocuDesk\Event\DocumentSigningRequestedEvent` (guarded by `class_exists()` — if docudesk
  is not installed it MUST **fail closed** by throwing, never proceed or mark the document
  signed), then read the synchronous result `isHandled()` / `getSigningRequestId()`. If
  `isHandled()` is false OR `getSigningRequestId()` is null it MUST fail closed (throw). On
  success it MUST store the returned signing-request id as `signingRequestRef` and set
  `signingStatus = requested`.
- A registered listener on `OCA\DocuDesk\Event\SigningConcludedEvent` MUST consume the terminal
  outcome, filtering to `getSourceApp() === 'shillinq'`, and MUST project the
  `signed` / `declined` / `expired` / `cancelled` status onto the originating finance object
  via `SigningDelegationService::onSigningCallback()`. The callback MUST stay idempotent (a
  repeated conclusion is a no-op) and MUST fire the local accounting consequence exactly once
  on `signed` (REQ-SIGN-006).
- The originating finance schema stores only the document-signing **consumer field set**
  (`signingRequestRef`, `signingStatus`, `signingProvider`, `signingLevel`,
  `signedDocumentRef`) — never a signing engine, PKI material, or a local trail. These mirror
  fields are written **only** by the docudesk `SigningConcludedEvent` listener (or by
  `requestSignature` setting `requested`) — never by an app-local transition.
- No raw fleet hostname, hard-coded HTTP client, or phantom `$registry->call('docudesk', ...)`
  MUST appear in `lib/Service/Signing/`.

@e2e exclude backend integration: the IEventDispatcher dispatch + SigningConcludedEvent consumption are server-side, asserted via unit, not UI

#### Scenario: A finance document signature is dispatched to docudesk via IEventDispatcher and fails closed when docudesk is absent

- **GIVEN** a finance document (`ACMReport` / jaarrekening / management letter) in shillinq that requires a signature
- **WHEN** an operator requests the signature
- **THEN** shillinq MUST dispatch `OCA\DocuDesk\Event\DocumentSigningRequestedEvent` via `IEventDispatcher`, and only on `isHandled() === true` with a non-null `getSigningRequestId()` set `signingRequestRef` and `signingStatus = requested`; if docudesk is not installed or did not handle the event it MUST throw and MUST NOT advance, mark signed, or produce any local signature/PKI fingerprint

#### Scenario: The concluded signing outcome is consumed by a listener and drives the local GL consequence

- **GIVEN** a finance object with `signingStatus = requested` and a stored `signingRequestRef`
- **WHEN** docudesk dispatches `OCA\DocuDesk\Event\SigningConcludedEvent` with `getSourceApp() === 'shillinq'` and `getStatus() === 'signed'`
- **THEN** the registered shillinq listener MUST project `signingStatus = signed` (with `signedDocumentRef` from the event) onto the matching finance object and fire the existing submission/GL consequence exactly once; a repeated conclusion MUST be a no-op

#### Scenario: No phantom integration-registry call remains and shillinq never signs on local authority

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned in `lib/` for `$registry->call('docudesk'`, for `createSigningRequest`, and for hard-coded docudesk transport in `lib/Service/Signing/`
- **THEN** the docudesk `$registry->call(...)` / `createSigningRequest` path MUST be gone (replaced by the IEventDispatcher dispatch), and no code path MUST mark a document signed without a docudesk-delivered `SigningConcludedEvent`
