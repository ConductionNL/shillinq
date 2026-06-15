# Spec: shillinq-delegate-signing (delta)

## MODIFIED Requirements

### Requirement: REQ-SIGN-005 — Cross-app sign-off DECISIONS SHALL be delegated to decidesk via IEventDispatcher, request-then-consume-outcome; DOCUMENT e-signature stays a (fail-closed) consumer pending a docudesk event contract

The governance **sign-off DECISION** delegation to decidesk MUST use a synchronous,
in-process **`OCP\EventDispatcher\IEventDispatcher`** dispatch — NOT a hard-coded
`decidesk` hostname and NOT the non-existent integration-registry `call()` method. shillinq
implements only the ADR-031 integration exception path; it owns no approval logic.

- `SignoffDecisionService::requestSignoff()` MUST dispatch
  `OCA\Decidesk\Event\DecisionRequestedEvent` (guarded by `class_exists()` — if decidesk is
  not installed it MUST **fail closed** by throwing, never proceed or auto-approve), then read
  the synchronous result `isHandled()` / `getDecisionId()`. If `isHandled()` is false OR
  `getDecisionId()` is null it MUST fail closed (throw). On success it MUST store the returned
  decision id as `decisionRef` and set `decisionOutcome = pending`.
- A registered listener on `OCA\Decidesk\Event\DecisionConcludedEvent` MUST consume the
  terminal outcome, filtering to `getSourceApp() === 'shillinq'`, and MUST project the
  `approved` / `rejected` outcome onto the originating finance object via
  `SignoffDecisionService::onDecisionCallback()`. The callback MUST stay idempotent (a repeated
  conclusion is a no-op) and MUST fire the local accounting consequence exactly once
  (REQ-SIGN-006).
- The DOCUMENT e-signature delegation to **docudesk** (`SigningDelegationService`) MUST remain
  a fail-closed consumer until a docudesk event contract (analogous to decidesk's) exists; it
  MUST NOT auto-sign on any failure path. No raw fleet hostname or hard-coded HTTP client to
  decidesk/docudesk MUST appear in `lib/Service/Signing/`.

@e2e exclude backend integration: the IEventDispatcher dispatch + DecisionConcludedEvent consumption are server-side, asserted via unit, not UI

#### Scenario: Sign-off decisions are dispatched to decidesk via IEventDispatcher and fail closed when decidesk is absent

- **GIVEN** a finance object (`ACMReport` / `ActuarialValuation` / `AnnualReport`) awaiting governance sign-off
- **WHEN** an operator requests the sign-off
- **THEN** shillinq MUST dispatch `OCA\Decidesk\Event\DecisionRequestedEvent` via `IEventDispatcher`, and only on `isHandled() === true` with a non-null `getDecisionId()` set `decisionRef` and `decisionOutcome = pending`; if decidesk is not installed or did not handle the event it MUST throw and MUST NOT advance or auto-approve

#### Scenario: The concluded decision outcome is consumed by a listener and drives the local GL consequence

- **GIVEN** a finance object with `decisionOutcome = pending` and a stored `decisionRef`
- **WHEN** decidesk dispatches `OCA\Decidesk\Event\DecisionConcludedEvent` with `getSourceApp() === 'shillinq'` and `getStatus() === 'approved'`
- **THEN** the registered shillinq listener MUST project `decisionOutcome = approved` onto the matching finance object and fire the existing GL posting / lifecycle consequence exactly once; a repeated conclusion MUST be a no-op

#### Scenario: No phantom integration-registry call remains and docudesk stays fail-closed

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for `$registry->call('decidesk'` and for hard-coded decidesk/docudesk transport in `lib/Service/Signing/`
- **THEN** the decidesk `$registry->call(...)` path MUST be gone (replaced by the IEventDispatcher dispatch), and the docudesk DOCUMENT e-signature path MUST remain a fail-closed consumer with no auto-sign on any failure path
