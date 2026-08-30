# Tasks: shillinq-signing-via-events

> **Not yet archived (2026-07-22).** All tasks below are genuinely done, wired, and
> test-green (see Task 4). `openspec archive shillinq-signing-via-events --yes` currently
> fails because the canonical spec `openspec/specs/shillinq-delegate-signing/spec.md` does
> not exist — a pre-existing gap unrelated to this change (the `2026-06-14-shillinq-delegate-
> signing` archive never synced it), which also blocks the still-open sibling change
> `shillinq-delegation-via-events`. Tracked in
> Codeberg issue shillinq#491 (pre-migration, not migrated to GitHub). Re-run the
> archive once that is resolved.

## 1. Rewire the request path onto the docudesk event contract

- [x] 1.1 Inject `OCP\EventDispatcher\IEventDispatcher` into `SigningDelegationService`'s constructor; remove the `object $registry` parameter from `requestSignature()`.
- [x] 1.2 In `requestSignature()`, delete the `$registry->call('docudesk', 'createSigningRequest', [...])` path. Add a `class_exists(\OCA\DocuDesk\Event\DocumentSigningRequestedEvent::class)` guard that throws (FAIL CLOSED) when docudesk is not installed.
- [x] 1.3 Build and `dispatchTyped()` a `DocumentSigningRequestedEvent` (sourceApp='shillinq', subjectRegister, subjectSchema, subjectId, subjectLabel, documentReference, signers, signatureLevel, signingMode, externalReference, correlationId).
- [x] 1.4 Read back `isHandled()` / `getSigningRequestId()`; FAIL CLOSED (throw) if not handled or null id. On success store `signingRequestRef` + `signingStatus = requested`.
- [x] 1.5 Keep the idempotency short-circuit (already-`signed` → no-op) and keep `onSigningCallback()` unchanged in shape (terminal-status map, idempotency guard, consequence-callback once on `signed`).

## 2. Add the conclusion listener

- [x] 2.1 Add `lib/Listener/SigningConcludedListener.php` consuming `OCA\DocuDesk\Event\SigningConcludedEvent`, mirroring `SignoffDecisionConcludedListener` (lazy OR ObjectService, fail-soft, idempotent).
- [x] 2.2 Filter `getSourceApp() === 'shillinq'`; map status (`signed`/`declined`/`expired`/`cancelled`→`expired`) onto the finance object via `onSigningCallback()`; persist mirror + the local consequence delta in one OR write.
- [x] 2.3 Register the listener for `SigningConcludedEvent::class` in `lib/AppInfo/Application.php`.

## 3. Remove dead code + verify

- [x] 3.1 Grep-confirm `->call('docudesk'` and `createSigningRequest` are GONE from `lib/`.
- [x] 3.2 Update `tests/Unit/Service/Signing/SigningDelegationServiceTest.php` to the event-dispatcher contract (mock `IEventDispatcher`, drive the result slot, assert fail-closed when docudesk absent).
- [x] 3.3 `openspec validate shillinq-signing-via-events --strict`; `php -l` every changed PHP file; run the hydra mechanical gates.

## 4. Wire the request side to a real production caller (orphaned-capability fix, 2026-07-22)

- [x] 4.1 Root cause: `requestSignature()` transport was correctly rewired but had **zero
  production callers**. The `ACMReport.sign` transition (`draft` -> `ready-for-submission`,
  `lib/Settings/register.d/bookkeeping-market-government-separation.json`) is purely
  declarative (from/to/label only) — no `x-openregister-lifecycle` handler and nothing else in
  `lib/` called `requestSignature()`.
- [x] 4.2 Add `lib/Listener/ACMReportSignTransitionListener.php`, mirroring the established
  `*TransitionListener` pattern (`VerplichtingTransitionListener`,
  `OpdrachtUitvoeringTransitionListener`): consume `ObjectTransitionedEvent`, filter to schema
  `ACMReport` + action `sign`, call `SigningDelegationService::requestSignature()`, persist the
  returned `signingRequestRef` + `signingStatus` via OR `ObjectService` (lazy container
  resolution, matching `SigningConcludedListener`'s persistence pattern). Fail-soft: the
  transition already committed by the time the event fires, so a request failure logs and
  leaves the object un-mirrored rather than corrupting the transition.
- [x] 4.3 Register the listener for `ObjectTransitionedEvent::class` in
  `lib/AppInfo/Application.php`.
- [x] 4.4 Add `tests/Unit/Listener/ACMReportSignTransitionListenerTest.php` (6 tests) proving:
  the `sign` transition on `ACMReport` calls `requestSignature()` and persists the mirror; a
  non-`sign` action is ignored; a non-`ACMReport` schema is ignored; an already
  requested/in-progress/signed object does not raise a duplicate request; a fail-closed
  `requestSignature()` (docudesk absent) is swallowed (fail-soft, no persist); a non-matching
  event type is ignored.
- [x] 4.5 Updated `specs/shillinq-delegate-signing/spec.md` (REQ-SIGN-001) + `proposal.md` to
  document the real trigger mechanism.
- [x] 4.6 `openspec validate shillinq-signing-via-events --strict`; `php -l` every changed PHP
  file; full `phpunit-unit.xml` suite still 3819 green.
