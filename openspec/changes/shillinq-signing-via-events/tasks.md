# Tasks: shillinq-signing-via-events

## 1. Rewire the request path onto the docudesk event contract

- [ ] 1.1 Inject `OCP\EventDispatcher\IEventDispatcher` into `SigningDelegationService`'s constructor; remove the `object $registry` parameter from `requestSignature()`.
- [ ] 1.2 In `requestSignature()`, delete the `$registry->call('docudesk', 'createSigningRequest', [...])` path. Add a `class_exists(\OCA\DocuDesk\Event\DocumentSigningRequestedEvent::class)` guard that throws (FAIL CLOSED) when docudesk is not installed.
- [ ] 1.3 Build and `dispatchTyped()` a `DocumentSigningRequestedEvent` (sourceApp='shillinq', subjectRegister, subjectSchema, subjectId, subjectLabel, documentReference, signers, signatureLevel, signingMode, externalReference, correlationId).
- [ ] 1.4 Read back `isHandled()` / `getSigningRequestId()`; FAIL CLOSED (throw) if not handled or null id. On success store `signingRequestRef` + `signingStatus = requested`.
- [ ] 1.5 Keep the idempotency short-circuit (already-`signed` → no-op) and keep `onSigningCallback()` unchanged in shape (terminal-status map, idempotency guard, consequence-callback once on `signed`).

## 2. Add the conclusion listener

- [ ] 2.1 Add `lib/Listener/SigningConcludedListener.php` consuming `OCA\DocuDesk\Event\SigningConcludedEvent`, mirroring `SignoffDecisionConcludedListener` (lazy OR ObjectService, fail-soft, idempotent).
- [ ] 2.2 Filter `getSourceApp() === 'shillinq'`; map status (`signed`/`declined`/`expired`/`cancelled`→`expired`) onto the finance object via `onSigningCallback()`; persist mirror + the local consequence delta in one OR write.
- [ ] 2.3 Register the listener for `SigningConcludedEvent::class` in `lib/AppInfo/Application.php`.

## 3. Remove dead code + verify

- [ ] 3.1 Grep-confirm `->call('docudesk'` and `createSigningRequest` are GONE from `lib/`.
- [ ] 3.2 Update `tests/Unit/Service/Signing/SigningDelegationServiceTest.php` to the event-dispatcher contract (mock `IEventDispatcher`, drive the result slot, assert fail-closed when docudesk absent).
- [ ] 3.3 `openspec validate shillinq-signing-via-events --strict`; `php -l` every changed PHP file; run the hydra mechanical gates.
