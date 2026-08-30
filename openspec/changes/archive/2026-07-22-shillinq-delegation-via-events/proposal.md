# Change: shillinq-delegation-via-events

## Why

The `shillinq-delegate-signing` change (archived 2026-06-14) delegated governance
sign-off DECISIONS to **decidesk** through what it called the "ADR-019 integration
registry". In practice `SignoffDecisionService::requestSignoff()` calls
`$registry->call('decidesk', 'createDecision', [...])` on a `object $registry`
parameter. There is **no integration-registry object with a `call()` method** in the
fleet — the parameter was a phantom. The call therefore never reaches decidesk; the
delegation is dead. It is only "safe" because it fail-closes (the call throws / returns
nothing and shillinq never auto-approves).

decidesk has since merged a real, in-process **event contract**
(`OCA\Decidesk\Event\DecisionRequestedEvent` dispatched synchronously via
`IEventDispatcher`, with `OCA\Decidesk\Event\DecisionConcludedEvent` emitted on the
terminal outcome). This change rewires shillinq's sign-off DECISION delegation onto that
contract so the request actually reaches decidesk and the approved/rejected outcome is
consumed to drive the local GL posting / lifecycle consequence.

This is the **decidesk DECISION path only** (`SignoffDecisionService`). The DOCUMENT
e-signature path to **docudesk** (`SigningDelegationService`) is left untouched: docudesk
has no event contract yet, so that path stays on the same phantom `$registry->call(...)`
and remains fail-closed. Fixing it requires a docudesk event contract analogous to
decidesk's — recorded as a known remaining gap (see design.md).

## What Changes

- **MODIFIED** `REQ-SIGN-005` — the decidesk sign-off DECISION path transport changes from
  the (non-existent) integration-registry `call()` to a synchronous **IEventDispatcher**
  dispatch of `OCA\Decidesk\Event\DecisionRequestedEvent`, with the terminal outcome
  consumed via a registered `OCA\Decidesk\Event\DecisionConcludedEvent` listener. The
  docudesk DOCUMENT e-signature path is explicitly carved out as still phantom/fail-closed
  pending a docudesk event contract.
- `SignoffDecisionService::requestSignoff()` injects `IEventDispatcher`, builds and
  dispatches `DecisionRequestedEvent` (guarded by `class_exists()` → fail-closed if decidesk
  absent), reads back `isHandled()` / `getDecisionId()`, stores `decisionRef` +
  `decisionOutcome = pending`; the dead `$registry->call(...)` path and the `object $registry`
  parameter are removed.
- A new `SignoffDecisionConcludedListener` consumes `DecisionConcludedEvent`, filters
  `getSourceApp() === 'shillinq'`, projects `approved` / `rejected` onto the finance object
  (via `SignoffDecisionService::onDecisionCallback`), and fires the existing GL / lifecycle
  consequence. Registered in `lib/AppInfo/Application.php`.
- Fail-closed and the local GL consequence are preserved exactly (REQ-SIGN-002 / 003 / 006).

## Out of Scope

- The docudesk DOCUMENT e-signature delegation (`SigningDelegationService`) — left as-is,
  still fail-closed via the phantom `$registry->call('docudesk', ...)`. Tracked as a known
  gap pending a docudesk event contract.
- Any change to the finance-side accounting consequence, GL surface, or schema fragments.

## Impact

- Affected specs: `shillinq-delegate-signing` (REQ-SIGN-005 modified).
- Affected code: `lib/Service/Signing/SignoffDecisionService.php`,
  `lib/Listener/SignoffDecisionConcludedListener.php` (new), `lib/AppInfo/Application.php`.
