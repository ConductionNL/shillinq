# Spec: shillinq-delegate-signing (delta)

## MODIFIED Requirements

### Requirement: REQ-SIGN-005 — Cross-app sign-off DECISIONS SHALL be delegated to decidesk via IEventDispatcher, request-then-consume-outcome; DOCUMENT e-signature is governed by REQ-SIGN-001

The governance **sign-off DECISION** delegation to decidesk MUST use a synchronous,
in-process **`OCP\EventDispatcher\IEventDispatcher`** dispatch — NOT a hard-coded
`decidesk` hostname and NOT the non-existent integration-registry `call()` method. shillinq
implements only the ADR-031 integration exception path; it owns no approval logic. The
DOCUMENT e-signature delegation to docudesk is a separate transport, specified in full by
REQ-SIGN-001 (also `IEventDispatcher`-based); it is not restated here.

- `SignoffDecisionService::requestSignoff()` MUST dispatch
  `OCA\Decidesk\Event\DecisionRequestedEvent` (guarded by `class_exists()` — if decidesk is
  not installed it MUST **fail closed** by throwing, never proceed or auto-approve), then read
  the synchronous result `isHandled()` / `getDecisionId()`. If `isHandled()` is false OR
  `getDecisionId()` is null it MUST fail closed (throw). On success it MUST store the returned
  decision id as `decisionRef` and set `decisionOutcome = pending`.
- The request MUST be raised from a REAL production trigger, not merely be callable —
  `AnnualReportSignoffRequestListener` reacts to `OCA\OpenRegister\Event\ObjectTransitionedEvent`
  on the AnnualReport schema transitioning to `opgemaakt` (the state shared by both the
  `vaststellen` and `vaststellenZonderReview` AV-adoption transitions) and is the sole
  production caller of `requestSignoff()`. It is idempotent — skipped once the object already
  carries a non-empty `decisionOutcome` — and fail-soft at the listener boundary (the
  `opgemaakt` transition has already committed by the time the event fires; a request failure
  leaves `decisionOutcome` unset rather than corrupting the record or auto-approving).
- A registered listener (`SignoffDecisionConcludedListener`) on
  `OCA\Decidesk\Event\DecisionConcludedEvent` MUST consume the terminal outcome, filtering to
  `getSourceApp() === 'shillinq'`, and MUST project the `approved` / `rejected` outcome onto
  the originating finance object via `SignoffDecisionService::onDecisionCallback()`. The
  callback MUST stay idempotent (a repeated conclusion is a no-op) and MUST fire the local
  accounting consequence exactly once (REQ-SIGN-006).
- No raw fleet hostname or hard-coded HTTP client to decidesk MUST appear in
  `lib/Service/Signing/`.

@e2e exclude backend integration: the IEventDispatcher dispatch + DecisionConcludedEvent consumption are server-side, asserted via unit, not UI

#### Scenario: Sign-off decisions are dispatched to decidesk via IEventDispatcher and fail closed when decidesk is absent

- **GIVEN** a finance object (`ACMReport` / `ActuarialValuation` / `AnnualReport`) awaiting governance sign-off
- **WHEN** an operator requests the sign-off
- **THEN** shillinq MUST dispatch `OCA\Decidesk\Event\DecisionRequestedEvent` via `IEventDispatcher`, and only on `isHandled() === true` with a non-null `getDecisionId()` set `decisionRef` and `decisionOutcome = pending`; if decidesk is not installed or did not handle the event it MUST throw and MUST NOT advance or auto-approve

#### Scenario: The decision request is wired to a real AnnualReport lifecycle trigger, not left orphaned

- **GIVEN** an AnnualReport with no `decisionOutcome` set yet
- **WHEN** the AnnualReport transitions to `opgemaakt` (via `opmaken` or `reviewAnnuleren`)
- **THEN** `AnnualReportSignoffRequestListener` MUST call `SignoffDecisionService::requestSignoff()` exactly once and persist the returned `decisionRef` / `decisionOutcome = pending`; a subsequent re-entry into `opgemaakt` while `decisionOutcome` is already set MUST NOT raise a duplicate decision

#### Scenario: The concluded decision outcome is consumed by a listener and drives the local GL consequence

- **GIVEN** a finance object with `decisionOutcome = pending` and a stored `decisionRef`
- **WHEN** decidesk dispatches `OCA\Decidesk\Event\DecisionConcludedEvent` with `getSourceApp() === 'shillinq'` and `getStatus() === 'approved'`
- **THEN** the registered shillinq listener MUST project `decisionOutcome = approved` onto the matching finance object and fire the existing GL posting / lifecycle consequence exactly once; a repeated conclusion MUST be a no-op

#### Scenario: No phantom integration-registry call remains for either delegation path

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `$registry->call('decidesk'`, `$registry->call('docudesk'`, and for hard-coded decidesk/docudesk transport in `lib/Service/Signing/`
- **THEN** neither `$registry->call(...)` path MUST exist; both delegation paths MUST be `IEventDispatcher`-based (decidesk per this requirement, docudesk per REQ-SIGN-001)
