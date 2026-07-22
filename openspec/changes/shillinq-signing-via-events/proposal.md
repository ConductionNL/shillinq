# Change: shillinq-signing-via-events

## Why

The `shillinq-delegate-signing` change (archived 2026-06-14) delegated DOCUMENT
e-signature on shillinq finance documents to **docudesk** through what it called the
"ADR-019 integration registry". In practice `SigningDelegationService::requestSignature()`
calls `$registry->call('docudesk', 'createSigningRequest', [...])` on a phantom
`object $registry` parameter. There is **no integration-registry object with a `call()`
method** in the fleet — the parameter was never wired to anything. The call therefore never
reaches docudesk; the delegation is dead. It is only "safe" because it fail-closes (the call
throws / returns nothing and shillinq never marks a document signed on local authority).

The companion sibling change `shillinq-delegation-via-events` already rewired the **decidesk
DECISION** path (`SignoffDecisionService`) onto decidesk's merged in-process event contract
(`DecisionRequestedEvent` / `DecisionConcludedEvent` via `IEventDispatcher`). docudesk has
since merged the analogous **document-signing event contract**
(`OCA\DocuDesk\Event\DocumentSigningRequestedEvent` dispatched synchronously via
`IEventDispatcher`, with `OCA\DocuDesk\Event\SigningConcludedEvent` emitted on the terminal
outcome). This change rewires shillinq's DOCUMENT e-signature delegation onto that contract so
the request actually reaches docudesk and the signed/declined/expired/cancelled outcome is
consumed to drive the local GL posting / lifecycle consequence.

This is the **docudesk DOCUMENT e-signature path only** (`SigningDelegationService`). The
decidesk DECISION path (`SignoffDecisionService`) was already migrated in the prior change and
is **not touched** here.

## What Changes

- **MODIFIED** `REQ-SIGN-001` — the docudesk DOCUMENT e-signature path transport changes from
  the (non-existent) integration-registry `call()` to a synchronous **IEventDispatcher**
  dispatch of `OCA\DocuDesk\Event\DocumentSigningRequestedEvent`, with the terminal outcome
  consumed via a registered `OCA\DocuDesk\Event\SigningConcludedEvent` listener.
- `SigningDelegationService::requestSignature()` injects `IEventDispatcher`, builds and
  dispatches `DocumentSigningRequestedEvent` (guarded by `class_exists()` → fail-closed if
  docudesk absent), reads back `isHandled()` / `getSigningRequestId()`, stores
  `signingRequestRef` + `signingStatus = requested`; the dead
  `$registry->call('docudesk', 'createSigningRequest', ...)` path and the `object $registry`
  parameter are removed.
- A new `SigningConcludedListener` consumes `SigningConcludedEvent`, filters
  `getSourceApp() === 'shillinq'`, projects the `signed` / `declined` / `expired` /
  `cancelled` status onto the finance object (via
  `SigningDelegationService::onSigningCallback`), and fires the existing GL / lifecycle
  consequence on `signed`. Registered in `lib/AppInfo/Application.php`.
- Fail-closed (never sign on local authority) and the local GL consequence are preserved
  exactly (REQ-SIGN-001 / 003 / 006).
- **Orphaned-capability fix (2026-07-22):** the rewired transport had **zero production
  callers** — `requestSignature()` was correct but nothing invoked it, because the
  `ACMReport.sign` transition (`draft` -> `ready-for-submission`,
  `bookkeeping-market-government-separation.json`) is purely declarative with no handler. A
  new `ACMReportSignTransitionListener` consumes `ObjectTransitionedEvent`, filters to schema
  `ACMReport` + action `sign`, calls `requestSignature()`, and persists the returned
  `signingRequestRef` + `signingStatus` back onto the object. Registered in
  `lib/AppInfo/Application.php`, mirroring the established `*TransitionListener` pattern
  (`VerplichtingTransitionListener`, `OpdrachtUitvoeringTransitionListener`).

## Out of Scope

- The decidesk DECISION sign-off delegation (`SignoffDecisionService` /
  `SignoffDecisionConcludedListener`) — already migrated to `IEventDispatcher` in
  `shillinq-delegation-via-events`. Untouched here.
- Any change to the finance-side accounting consequence, GL surface, or schema fragments.
- The legacy `DelegateSigningMigrationRepair` backfill — unchanged (idempotent, fail-soft).

## Impact

- Affected specs: `shillinq-delegate-signing` (REQ-SIGN-001 modified).
- Affected code: `lib/Service/Signing/SigningDelegationService.php`,
  `lib/Listener/SigningConcludedListener.php` (new),
  `lib/Listener/ACMReportSignTransitionListener.php` (new — orphaned-capability fix),
  `lib/AppInfo/Application.php`,
  `tests/Unit/Service/Signing/SigningDelegationServiceTest.php`,
  `tests/Unit/Listener/ACMReportSignTransitionListenerTest.php` (new).
