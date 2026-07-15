# migrate-mandaat-to-approval-chains

## Why

The `in_goedkeuring` state of the `Verplichting` schema
(`lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json`) is
described — in the state label, the `goedkeuren` transition, and the
`Goedkeuringsstap` schema — as running the commitment through a "goedkeuringsstap
approval chain" (REQ-VPL-002, D7). **No such chain has ever been implemented.**
`MandaatEnforcer::requiresApproval` only *routes* an over-mandate commitment into
`in_goedkeuring`; nothing then materialises approval steps, gates the
`goedkeuren` transition on their completion, enforces approver ≠ requester, or
auto-advances on completion. The `goedkeuren` transition is today gated only by
`BudgetBlocker::canCommit` — a commitment can leave `in_goedkeuring` with **zero
recorded approval**. That is a dead control: the app looks like it enforces an
approval chain, and does not.

OpenRegister PR #396/#397 (merged, archived `2026-07-14-approval-chains-declarative`)
turns `x-openregister-approval-chains` into a **real declarative capability**:
`ApprovalChainAnnotationInstaller` provisions the chain from the schema,
`ApprovalChainGateListener` blocks the declared transition until every step is
approved (with amount-tier routing and separation of duties), and
`ApprovalChainAdvanceListener` releases the parent transition on completion
(REQ-006…010). shillinq should *consume* that abstraction to make the
long-described `goedkeuren` approval chain actually enforce — declaratively,
per ADR-022, with the failing paths proven in OpenRegister's own 14 tests.

## What

Add one declarative `x-openregister-approval-chains` block to the `Verplichting`
schema, gating the `goedkeuren` transition on a threshold-routed, separation-of-
duties finance approval chain (mirroring the shape already shipped, inert, on the
`BcfClaim` schema). **No imperative code is removed** — see the coverage table in
`design.md`: `MandaatEnforcer`'s mandate-record checking (validity windows, soort
matching, per-record ceilings, second-signature thresholds, least-privilege
selection) is **not** expressible in the amount-tier declarative shape and remains
a legitimate ADR-031 exception.

## Cross-app dependency (must land first)

The declarative block is **inert until the OpenRegister release carrying #396/#397
is deployed** to the environment. The OpenRegister app *running* in the dev
environment at authoring time (v0.2.17-unstable.14) predates #396 — its deployed
`lib/` has neither `ApprovalChainAnnotationInstaller` nor the two listeners, and
its `Schema::ANNOTATION_VOCABULARY` does not yet whitelist
`x-openregister-approval-chains`, so the key would be silently dropped on save.
Sequencing and the named follow-up are in `design.md`.

## Impact

- `lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json` — one
  additive `x-openregister-approval-chains` block (no schema, lifecycle, or seed
  change).
- `tests/Unit/Settings/VerplichtingApprovalChainFragmentTest.php` — new
  contract test proving the declared chain is well-formed against the
  OpenRegister contract and that `MandaatEnforcer` is retained.
- No change to `MandaatEnforcer`, `BudgetBlocker`, callers, or any other guard.
