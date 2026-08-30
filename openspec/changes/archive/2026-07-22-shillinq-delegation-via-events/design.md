# Design: shillinq-delegation-via-events

## Context

`shillinq-delegate-signing` (archived) established shillinq as a pure *consumer* of
decidesk decisions and docudesk signatures for finance-document governance. Its REQ-SIGN-005
stated cross-app calls go "through the ADR-019 integration registry". The implemented code
expressed that registry as an opaque `object $registry` parameter on which it called
`$registry->call('decidesk', 'createDecision', [...])`.

No such registry object / `call()` method exists anywhere in the fleet. The decidesk
delegation therefore never fired. It fail-closed (the phantom call threw → caught → rethrown;
shillinq never auto-approved), so it was *safe* but *non-functional*.

decidesk has now merged a concrete, synchronous, in-process **event contract**. This change
adopts it for the DECISION path.

## The decidesk event contract (consumed verbatim)

**Dispatch (shillinq → decidesk), synchronous via `IEventDispatcher::dispatchTyped()`:**

`OCA\Decidesk\Event\DecisionRequestedEvent` (extends `OCP\EventDispatcher\Event`), ctor:

```
(string $sourceApp, string $subjectRegister, string $subjectSchema, string $subjectId,
 string $subjectLabel = '', string $decisionType = 'contract', string $actorId = '',
 array $payload = [], string $externalReference = '', string $correlationId = '')
```

`payload` keys consumed for the decision body: `title`, `text`, `decisionDate`, `outcome`.

After dispatch, read the synchronous result the decidesk listener wrote:
`$event->isHandled(): bool` and `$event->getDecisionId(): ?string`. If `isHandled()` is false
OR `getDecisionId()` is null → decidesk did NOT handle it → **FAIL CLOSED** (throw).

**Listen (decidesk → shillinq) for the terminal outcome:**

`OCA\Decidesk\Event\DecisionConcludedEvent` (extends Event). Getters used:
`getSourceApp()`, `getStatus()` (`approved`|`rejected`|`withdrawn`|`pending`),
`getDecisionId()`, `getSubjectId()`, `getExternalReference()`, `getCorrelationId()`.

## Decisions

### D1 — Dispatch in `requestSignoff`, guarded by `class_exists`

`SignoffDecisionService::requestSignoff()`:

1. Drops the `object $registry` parameter; injects `IEventDispatcher` in the ctor.
2. `class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class)` → if false, decidesk is
   not installed → **throw** (fail closed; never proceed/auto-approve). Preserves the prior
   fail-closed posture.
3. Builds `DecisionRequestedEvent` with:
   - `sourceApp = 'shillinq'`
   - `subjectRegister = <configured register slug>` (via `SettingsService::getRegisterSlug()`)
   - `subjectSchema = <finance schema>` (ACMReport / ActuarialValuation / AnnualReport — passed by caller)
   - `subjectId = <finance object id>`
   - `subjectLabel = <human label if present>`
   - `decisionType = $decisionType` (`sign-off` / `adoption`)
   - `payload = ['title' => ..., 'text' => ..., 'decisionDate' => gmdate(...)]`
   - `externalReference = <finance object id>` (so the conclusion can be matched back)
4. `dispatchTyped($event)`, then read `isHandled()` / `getDecisionId()`. If not handled or
   id null → **throw** (fail closed).
5. On success: `decisionRef = getDecisionId()`, `decisionOutcome = 'pending'`; return the
   updated finance object for the caller to persist via OR ObjectService (ADR-022).

The idempotency short-circuit (already `approved` → no-op) is retained.

### D2 — Consume the conclusion in a listener, keep the GL consequence local

New `OCA\Shillinq\Listener\SignoffDecisionConcludedListener implements IEventListener`:

- Registered on `DecisionConcludedEvent` in `Application::register()` (guarded the same way
  the OR-event listeners are — it only fires if decidesk dispatches the event).
- Filters `if ($event->getSourceApp() !== 'shillinq') return;`.
- Resolves the finance object by `externalReference` (the finance object id we sent) /
  `subjectId`, across the three subject schemas; loads it via OR ObjectService.
- Maps decidesk `getStatus()` → shillinq outcome: `approved` → `approved`, `rejected` →
  `rejected`. `withdrawn` / `pending` / unknown → ignored (no terminal projection).
- Calls `SignoffDecisionService::onDecisionCallback(...)` with a consequence callback that
  performs the **existing** GL posting / lifecycle flip — the accounting consequence stays in
  shillinq (REQ-SIGN-006). `onDecisionCallback` keeps its idempotency guard, so a repeated
  conclusion is a no-op.
- Fail-soft on lookup/projection errors (logged, never rethrown into decidesk's dispatch),
  consistent with the other shillinq OR-event listeners. This is distinct from the
  fail-*closed* request path: a missing decidesk on *request* must block; a hiccup while
  *recording* an already-made decision must not crash decidesk's pipeline.

`onDecisionCallback` itself is unchanged in shape — it already writes `decisionRef` +
`decisionOutcome`, fires the consequence callback exactly once on `approved`, and is
idempotent. Only its *caller* changes (a real listener instead of a phantom registry callback).

### D3 — Remove the phantom registry plumbing

`$registry->call('decidesk', 'createDecision', ...)` and the `object $registry` parameter are
deleted from `SignoffDecisionService::requestSignoff()`. Grep must show no
`$registry->call('decidesk'` remaining.

## Known remaining gap — docudesk DOCUMENT e-signature (OUT OF SCOPE)

`lib/Service/Signing/SigningDelegationService.php` delegates the DOCUMENT e-signature to
**docudesk** via the same phantom `$registry->call('docudesk', 'createSigningRequest', ...)`.
docudesk does **not** yet expose an event contract analogous to decidesk's
`DecisionRequestedEvent` / `DecisionConcludedEvent`. Therefore:

- `SigningDelegationService` is **left as-is** in this change. It remains non-functional and
  **fail-closed** (the phantom call throws; shillinq never auto-signs).
- Closing this gap requires docudesk to ship a `SigningRequestedEvent` /
  `SigningConcludedEvent` (or equivalent) contract; shillinq would then rewire
  `requestSignature()` / `onSigningCallback()` onto it in a follow-up change mirroring this
  one.

This is intentional scope discipline: only the path with a real contract on the other side is
rewired now; the other stays safe-but-dead until its contract exists.

## Risks

- **decidesk not installed** → `requestSignoff` throws (fail closed) — desired; no auto-approve.
- **Listener fail-soft vs fail-closed asymmetry** — explicit and documented (D2); the GL
  consequence still fires exactly once via the existing idempotent `onDecisionCallback`.
- Stale local OCP/OR stubs may make Psalm/PHPStan emit phantom errors about NC-internal APIs;
  these are reasoned about and deferred to CI, not fake-fixed.
