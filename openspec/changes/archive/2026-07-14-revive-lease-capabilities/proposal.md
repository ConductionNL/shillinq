---
kind: code
depends_on: []
---

# Proposal: revive-lease-capabilities

## Summary

Hydra gate-52 (`orphaned-write-capability`) flagged 28 zero-caller
side-effecting methods in shillinq; the 2026-07-14 triage
(`scratchpad/orphan-triage-2026-07-14.md`) named an **IFRS-16 lease
cluster** as high blast radius (shillinq#446):

- `LeaseReassessmentService::recordIndexationEvent`
- `LeaseReassessmentService::recordExtensionOptionReassessment`
- `LeaseReassessmentService::recordModification`
- `LeaseReassessmentService::recordImpairment`
- `LeasePaymentScheduleService::generateSchedule`

Each is fully implemented, its spec says `done`, its unit tests pass — and
**nothing invokes it**. The IFRS-16 right-of-use (RoU) asset and lease
liability are therefore silently wrong on the balance sheet: a lease
activates but its amortization schedule is never persisted (only the
read-only `buildSchedule` preview runs), and none of the four remeasurement
events (indexation / extension-option / modification / impairment) can be
recorded because no route or listener reaches them.

This change verifies each method against `origin/development` (Step 1 of the
brief — see `design.md` for the per-method verdict table with caller
evidence at file:line), confirms none is superseded, then wires each to its
real, executing trigger.

## Motivation

For a lessee, IFRS 16 recognises a RoU asset and a lease liability at
commencement and then amortizes both period-by-period; a reassessment
(CPI indexation, an extension-option likelihood change, a scope/term
modification, or an impairment) remeasures them. A dead schedule generator
means the per-period interest/principal split and RoU depreciation never
hit the ledger — the balance sheet keeps the opening figures forever. A
dead remeasurement path means the liability and asset never move when the
contract changes. Both are money-path controls the app currently claims to
have.

## Verification surfaced why "obvious" declarative wiring would be a no-op

The natural instinct — wire `generateSchedule` to the `LeaseContract`
`draft→active` lifecycle **action** — does not work, and would have shipped a
second dead capability:

1. **OpenRegister has no declarative action executor.** `TransitionEngine`
   only mutates the lifecycle field and saves; `LifecycleValidationListener`
   only runs `requires` *validation* guards (allow/deny). The
   `actionParameters` / `actions[]` blocks in shillinq's own `register.d`
   (e.g. `StockMoveOffsetCreator::emitOffset`) are **never executed** by any
   OR engine code — `emitOffset` has zero real callers fleet-wide, exactly
   the orphan trap. A declarative lease "action" would be equally dead.
2. **`LeaseContract` transitions are list-form.** The annotation declares
   `transitions: [ {from, to, ...} ]`, but `TransitionEngine::transition()`
   indexes `$transitions[$action]` (map-form). The `/transition` endpoint
   therefore never fires for a lease and **no `ObjectTransitionedEvent` is
   dispatched** — so a listener on that event (the `StockMove` pattern)
   would also never run.

The trigger that genuinely executes is `ObjectUpdatedEvent` /
`ObjectCreatedEvent` (dispatched by `MagicMapper` on every save through the
public mutation surface, carrying old + new). See design D1.

## Scope

In scope (the IFRS-16 lease cluster of the triage table):

- `LeasePaymentScheduleService::generateSchedule` → a `LeaseActivationListener`
  on `ObjectUpdatedEvent`/`ObjectCreatedEvent` that persists the schedule on
  the `LeaseContract` `draft→active` edge (capitalised leases only).
- The four `LeaseReassessmentService::record*` methods → a write
  `LeaseReassessmentController` (four `POST` endpoints, `#[NoAdminRequired]`
  + per-administration IDOR guard) that a UI/API client invokes with the
  event-specific inputs the methods require (indexed payment, updated
  extension options, new terms, recoverable value).

## Non-goals

- No new lease arithmetic. Every liability / RoU / GL rule already exists in
  the two services and their unit tests; this change supplies the missing,
  executing triggers only.
- No schedule regeneration on `modified→active` re-activation
  (partial-period `fromSequence` regen). The listener fires on the initial
  `draft→active` edge only, where no posted rows exist to clobber; the
  re-activation regen is a documented follow-up (design D5).
- The lease disclosure/XBRL/CSV/PDF **exports** (`LeaseDisclosureService::*`)
  are a separate export cluster in #446 (regulatory export, not GL posting)
  and are deliberately left untouched here, not silently claimed.
