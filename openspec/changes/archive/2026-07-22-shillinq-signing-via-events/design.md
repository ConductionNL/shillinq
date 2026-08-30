# Design: shillinq-signing-via-events

## Context

`shillinq-delegate-signing` (archived) shipped two delegation services in
`lib/Service/Signing/`:

1. `SignoffDecisionService` — governance DECISION delegation to **decidesk**.
2. `SigningDelegationService` — document e-signature delegation to **docudesk**.

Both originally transported via a phantom `object $registry` with a `->call($app, $method,
$params)` method that does not exist anywhere in the fleet. The decidesk path (1) was already
fixed in `shillinq-delegation-via-events` by switching to decidesk's in-process
`IEventDispatcher` event contract. This change applies the **same shape** to the docudesk
path (2), now that docudesk has merged its analogous contract.

## The docudesk event contract (verbatim — use exactly)

Dispatch (consumer → docudesk), synchronous in-process via
`IEventDispatcher::dispatchTyped($event)`:

- Class: `OCA\DocuDesk\Event\DocumentSigningRequestedEvent` (note the casing: `DocuDesk` with
  capital D; extends `OCP\EventDispatcher\Event`).
- Constructor getters/fields: `sourceApp`, `subjectRegister`, `subjectSchema`, `subjectId`,
  `subjectLabel`, `documentReference`, `signers` (array), `signatureLevel`, `signingMode`,
  `externalReference`, `correlationId`.
- AFTER dispatch, read the synchronous result the docudesk listener wrote: `isHandled(): bool`
  and `getSigningRequestId(): ?string`. If `isHandled()` is false OR `getSigningRequestId()` is
  null → docudesk did NOT handle it (not installed / listener failed) → **FAIL CLOSED** (throw;
  never proceed / never mark signed on local authority).

Listen (docudesk → consumer) for the terminal outcome:

- Class: `OCA\DocuDesk\Event\SigningConcludedEvent` (extends Event).
- Getters: `getSourceApp()`, `getSubjectRegister()`, `getSubjectSchema()`, `getSubjectId()`,
  `getExternalReference()`, `getCorrelationId()`, `getSigningRequestId()`, `getStatus()`
  (`signed`|`declined`|`expired`|`cancelled`), `getSignedDocumentRef()`, `getSigners()`,
  `getSignedAt()`.
- Register the listener via `$context->registerEventListener(SigningConcludedEvent::class,
  SigningConcludedListener::class)` in `lib/AppInfo/Application.php`. In the listener, FILTER:
  `if ($event->getSourceApp() !== 'shillinq') return;`, then project the outcome onto the
  finance record (match by `subjectId` / `externalReference`).

## Decisions

- **Mirror the decidesk listener exactly.** `SigningConcludedListener` mirrors
  `SignoffDecisionConcludedListener`: lazy OR `ObjectService` resolution, fail-SOFT (a
  projection error logs but never bubbles into docudesk's synchronous dispatch — recording an
  already-made signature must not crash docudesk), idempotent projection via
  `SigningDelegationService::onSigningCallback`, single OR write of the mirror + the local
  accounting-consequence delta.
- **Fail CLOSED on the request, fail SOFT on the conclusion.** `requestSignature()` throws when
  docudesk is absent or did not handle the request (a document MUST NEVER be marked signed on
  local authority). The conclusion listener fail-softs (the signature already happened; we are
  only recording it).
- **`class_exists()` guard, register by FQCN string.** Exactly as the decidesk path:
  registering / dispatching by the docudesk event FQCN is safe even when docudesk is not
  installed (NC only needs the string key; the listener early-returns and the dispatch fails
  closed).
- **Remove the `$registry` plumbing.** The `object $registry` parameter on `requestSignature()`
  and the `$registry->call('docudesk', 'createSigningRequest', ...)` body are deleted; inject
  `IEventDispatcher` into the constructor instead. `grep '\->call(.docudesk' lib/` and
  `grep createSigningRequest lib/` MUST both return nothing.
- **`onSigningCallback()` is unchanged in shape.** It already projects the terminal status,
  guards idempotency, and fires the consequence callback exactly once on `signed`. The listener
  drives it; the local GL/lifecycle consequence stays in shillinq (REQ-SIGN-006).

## Status mapping

| docudesk `SigningConcludedEvent::getStatus()` | shillinq `signingStatus` | consequence |
|---|---|---|
| `signed` | `signed` | fire local submission/GL consequence (once) |
| `declined` | `declined` | none |
| `expired` | `expired` | none |
| `cancelled` | `expired` (terminal, no submission) | none |

`cancelled` is mapped onto the existing `expired` terminal value because the
`SigningDelegationService` terminal-status set is `signed|declined|expired` and a cancelled
request, like an expired one, is a non-completing terminal outcome that must not open the
submission gate.

## Risks

- docudesk's contract classes live on docudesk's `development` branch, not in this checkout —
  the same situation as the decidesk path. The `class_exists()` guard + FQCN-string
  registration mean shillinq compiles and behaves correctly (fail-closed request, inert
  listener) whether or not docudesk is installed.
