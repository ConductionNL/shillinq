# Tasks: shillinq-delegation-via-events

## 1. Rewire the decidesk DECISION request onto IEventDispatcher

- [x] 1.1 In `SignoffDecisionService`, inject `OCP\EventDispatcher\IEventDispatcher` into the constructor; remove the `object $registry` parameter from `requestSignoff()`.
- [x] 1.2 In `requestSignoff()`, guard with `class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class)` → throw (fail closed) if decidesk is absent.
- [x] 1.3 Build and `dispatchTyped()` an `OCA\Decidesk\Event\DecisionRequestedEvent` (sourceApp=shillinq, subjectRegister/Schema/Id, decisionType, payload title/text/decisionDate, externalReference=finance id).
- [x] 1.4 Read back `isHandled()` / `getDecisionId()`; fail closed (throw) if not handled or id null; else store `decisionRef` + `decisionOutcome = pending`. Keep the already-approved idempotency short-circuit.
- [x] 1.5 Delete the dead `$registry->call('decidesk', 'createDecision', ...)` path.

## 2. Consume the concluded outcome via a listener

- [x] 2.1 Add `lib/Listener/SignoffDecisionConcludedListener.php` (`implements IEventListener`) that filters `getSourceApp() === 'shillinq'`, resolves the finance object by externalReference/subjectId across the three subject schemas, maps `getStatus()` approved/rejected, and calls `SignoffDecisionService::onDecisionCallback()` with a consequence callback that runs the existing GL/lifecycle posting (REQ-SIGN-006). Fail-soft on lookup errors.
- [x] 2.2 Register the listener on `OCA\Decidesk\Event\DecisionConcludedEvent` in `lib/AppInfo/Application.php`.
- [x] 2.3 (Orphaned-capability fix, discovered during verification) Add `lib/Listener/AnnualReportSignoffRequestListener.php` — the REAL production trigger for `requestSignoff()`, which previously had zero callers despite being fully transported and tested. Reacts to `ObjectTransitionedEvent` on AnnualReport transitioning to `opgemaakt` (shared by `vaststellen`/`vaststellenZonderReview`), idempotent on existing `decisionOutcome`, fail-soft. Registered in `lib/AppInfo/Application.php`.

## 3. Verify

- [x] 3.1 `php -l` every changed PHP file.
- [x] 3.2 Grep-confirm `$registry->call('decidesk'` is gone.
- [x] 3.3 Run the 18 hydra mechanical gates (report).
- [x] 3.4 `openspec validate shillinq-delegation-via-events --strict` passes.
