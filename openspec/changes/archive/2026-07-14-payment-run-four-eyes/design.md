# Design: payment-run-four-eyes

## Verify-first finding (truth against HEAD)

| Question | Finding |
| --- | --- |
| Is there TODAY a server-side guarantee approver ≠ preparer on payment-run release? | **No.** |
| `PaymentRun.approve` transition | `x-rbac-role: controller` only; **no `requires` guard**. Description self-labels it a "Single-approver gate (D4)". |
| `bookkeeping-ccm-rule-engine.json` SoD | Declares `CcmSegregationMatrix` + SoD scorecard as **monitoring metadata** — does not gate the transition. |
| `ComplianceValidator::evaluateRule()` | Hardcodes `'segregation' => true` (line 203) — unconditional PASS; treasury-scoped, not payment-run. Classic "declared ≠ enforced / fabricated pass". |
| Any approver-vs-preparer comparison in `lib/`? | None (`grep` for preparer/selfApprov/fourEyes/segregationOfDuties → 0 hits). |

**Verdict: real gap.** A controller could prepare and self-approve an outgoing SEPA batch
server-side. Build the control.

## How the control is enforced

OpenRegister's `LifecycleValidationListener` (openregister) fires on every lifecycle-field save.
When the matched transition declares `requires: "<tag>"`, it resolves that DI tag through
`LifecycleGuardRegistry::resolve()` and calls `$guard->check($newData, $action, $userId)` where
`$userId` is the authenticated caller (the **approver**) and `$newData` is the object payload
(`ObjectEntity::getObject()` injects the uuid as `id`). This runs on the actual save path, so a
direct `saveObject()` with a changed `lifecycleState` is gated too — not only the `/transition`
endpoint.

`FourEyesPaymentRunGuard` implements `LifecycleGuardInterface::check()` directly (rather than
reusing the shared `RegisterRequiresGuardAdapter`, whose `check()` forwards only `$object` and
would drop the approver uid the four-eyes comparison needs). Algorithm:

1. `approverId = trim($userId)`. Empty → **DENY** (`MESSAGE_NO_APPROVER`), before any audit read.
2. `objectId` from `$object['id']` / `$object['@self']['id']`. Empty → **DENY**
   (`MESSAGE_NO_OBJECT`).
3. Read the object's OpenRegister audit trail via `ObjectService::getLogs($objectId)` (ADR-022).
   Build the **preparer set** = every distinct non-empty actor with action `create` or `update`.
   The `create` actor is mandatory.
4. No rows, or no determinable `create` actor → **DENY** (`MESSAGE_INDETERMINATE`, fail-closed).
5. Any thrown exception during the read → **DENY** (`MESSAGE_INDETERMINATE`, fail-closed).
6. `approverId ∈ preparerSet` → **DENY** (`MESSAGE_SELF_APPROVAL`).
7. Otherwise → **ALLOW**.

Why the preparer set includes `update` actors, not just `create`: true segregation of duties
requires the approver to be someone who did not shape the batch's content. Blocking only the
`create` actor would let a second user quietly edit the draft and then approve it. Read/delete
actions are excluded — only content-affecting actions count as "preparing".

### ADR-031 decision (dialect / control placement)

This is a **lifecycle transition guard** (ADR-022 audit trail as the single source of preparer
truth; ADR-031 register-declared `requires` DI-tag dialect), not an imperative controller check
and not a notification. The guard is read-only (MUST NOT mutate the object), consistent with the
`LifecycleGuardInterface` contract. Preparer identity is NOT re-derived into a bespoke
`preparedBy`/`approvedBy` field on the schema — that would be a hand-rolled parallel actor log the
audit trail already owns and could drift from it. The audit trail is authoritative.

### Fail-closed rationale

The prior `ComplianceValidator` fabricated `segregation => true`. The lesson: an indeterminate
segregation check must block, never pass. Every path where the guard cannot POSITIVELY establish
approver ≠ preparer denies the release. `getLogs`'s `_rbac` / `_multitenancy` flags are passed
`false` because they are ignored by `getLogs` itself (`@SuppressWarnings(UnusedFormalParameter)`);
the actor is read purely for the internal decision and the rows are never surfaced.

## Mandate / threshold dimension (scoped out, documented)

A larger batch (higher `totalAmount`) plausibly warrants a higher-authority approver — an
amount-tiered mandate. The canonical home for that is OpenRegister's
`x-openregister-approval-chains` (the "mandaat" capability). Per the mandaat-migration finding,
that capability is **merged but NOT yet deployed to this environment**, so depending on it now
would ship an inert control. Therefore:

- The four-eyes control (approver ≠ preparer) is the reliable, deployable guarantee **today** and
  is what this change ships.
- Amount-tiered approver authority is deliberately **out of scope** and left to the
  approval-chains capability once deployed. The `controller`-role RBAC gate on the transition
  already provides a floor (only controllers approve); the tier refinement is additive.

## Seed Data

No new seed objects. The AP-core seed already ships an approved `PaymentRun` (REQ-AP-011). Adding
a `requires` guard does not require new fixtures; the guard reads the audit trail of whatever
`PaymentRun` objects exist at runtime. The seed's pre-approved batch was written by the seeder
(system actor) and is already in `approved` state, so it never re-runs `approve`.

## Test strategy

`tests/Unit/Lifecycle/FourEyesPaymentRunGuardTest.php` drives `check()` directly with a fake
`ObjectService` (via a mocked `ContainerInterface`) returning controlled audit rows:

- **preparer self-approves → REJECTED** (`MESSAGE_SELF_APPROVAL`) — the whole point.
- different / uninvolved user approves → ALLOWED.
- draft *modifier* approves → REJECTED (preparer set includes `update` actors).
- indeterminate preparer (no `create` actor / empty audit trail / unknown `create` user) → BLOCKED.
- unknown approver / unidentifiable batch → BLOCKED without an audit read.
- audit read throws → BLOCKED (fail-closed).
- `@self`-envelope id and array-shaped rows honoured (defensive shape parity with `AuditTrail`).
