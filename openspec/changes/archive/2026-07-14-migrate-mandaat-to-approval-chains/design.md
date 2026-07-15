# Design — migrate-mandaat-to-approval-chains

## Cross-app dependency verdict (resolved FIRST, honestly)

**OpenRegister #396/#397 is merged and real on `origin/development`** — verified
read-only:

- `lib/Service/ApprovalChainAnnotationInstaller.php`, `lib/Listener/ApprovalChainGateListener.php`,
  `lib/Listener/ApprovalChainAdvanceListener.php`, `lib/Db/ApprovalChain{,Mapper}.php`,
  `lib/Service/ApprovalService.php` all present on `origin/development`.
- PRs #396 (apply) and #397 (archive) merged; archived change
  `openspec/changes/archive/2026-07-14-approval-chains-declarative/` with the
  canonical spec (REQ-006…010) and 14 failing-path tests.

**But that release is NOT deployed to the dev environment**, so shillinq cannot
consume it at runtime today:

- The OpenRegister app running in the container
  (`/var/www/html/custom_apps/openregister`, `<version>0.2.17-unstable.14</version>`)
  has the *old* `ApprovalService.php` but **no** `ApprovalChainAnnotationInstaller.php`
  and **no** `ApprovalChainGateListener` / `ApprovalChainAdvanceListener` in
  `lib/Listener/`.
- The OpenRegister version string was **not bumped** when #396 merged
  (`origin/development` is also `0.2.17-unstable.14`), so a version pin cannot
  detect the difference; the local OR checkout backing the mount is stale
  (behind `origin/development`).
- Consequence: the deployed `Schema::ANNOTATION_VOCABULARY` does **not** whitelist
  `x-openregister-approval-chains`, so `Schema::setConfiguration()` **drops the key
  on save** (exactly the failure REQ-006 warns about). The gate/advance listeners
  are not registered. The declaration is **inert** until OR is redeployed.

**Decision (per the guardrail "prove it or ship small"):** ship the smaller,
correct change. Add the real declarative block **alongside** the still-active
`MandaatEnforcer`; do **not** remove any imperative control. The imperative-removal
question is moot here anyway — see the coverage table: nothing in `MandaatEnforcer`
is redundant with the declarative capability.

## Coverage table — each MandaatEnforcer behaviour vs OR approval-chains

| # | MandaatEnforcer behaviour | Does OR `x-openregister-approval-chains` cover it? | Action |
|---|---|---|---|
| 1 | `hasSufficientMandate()` — does a valid **Mandaat record** cover this commitment (queries Mandaat objects: `geldig_van`/`geldig_tot` validity window, `soort_verplichting` matching, `maximumbedrag` ceiling, `administrationId` tenant)? | **No.** OR routes by a *static* `amountField` tier declared in the schema; it cannot query sibling Mandaat records, evaluate date windows, or match `soort`. | **Keep imperative** — legitimate ADR-031 exception |
| 2 | `requiresApproval()` — inverse of (1); the routing decision for the `indienen` (concept → in_goedkeuring) transition | **No** — same reason as (1). This is the *routing into* the chain, not the chain itself. | **Keep imperative** |
| 3 | `resolveApplicableMandate()` — least-privilege selection among applicable mandaten (prefer non-override, then lowest sufficient ceiling) | **No** — record-level selection logic, not amount tiers | **Keep imperative** |
| 4 | `requiresSecondSignature()` — second signature required when the amount ≥ the **applicable mandaat record's** `vereist_tweede_handtekening_boven` | **Partial.** OR can express a *static* second approver tier by amount, but not a threshold read from the matched Mandaat record. | **Keep imperative** (dynamic threshold); the static second tier is orthogonal |
| 5 | The `goedkeuren` **approval workflow** — gate `in_goedkeuring` → `aangegaan` until finance approval steps are all approved, amount-tier routing, approver ≠ requester, auto-advance on completion | **Yes** — exactly REQ-007/008/009/010. **Currently implemented NOWHERE** (described in the state/transition/Goedkeuringsstap schema, but no code materialises or gates steps). | **ADD declarative block** (inert until OR #396 deployed) |

**Net: 0 lines removed from `MandaatEnforcer`.** The premise that `MandaatEnforcer`
"does approval thresholds imperatively because the abstraction was dead code" does
not survive reading the code: `MandaatEnforcer` does mandate-**record** checking
that the amount-tier declarative shape genuinely cannot express (its own docblock
already documents this as the ADR-031 rationale). What *was* dead is the
`goedkeuren` approval chain — described everywhere, implemented nowhere — and that
is precisely what the declarative block now provides.

## ADR-031 decision

`x-openregister-approval-chains` is the canonical declarative dialect for
amount-tiered, separation-of-duties approval workflows (ADR-022: consume the OR
abstraction, do not re-implement). shillinq adopts it for the `goedkeuren`
transition. `MandaatEnforcer` remains an **explicit ADR-031 exception** for
mandate-record resolution (context-specific: amount + soort + effective-date
window + per-record second-signature threshold + least-privilege selection),
which no declarative DSL expresses. The two are **complementary**:
`MandaatEnforcer` decides *whether* a commitment must be routed to
`in_goedkeuring`; the declarative chain runs the workflow that releases it.

## Declared chain shape (matches the OR contract exactly)

```
"x-openregister-approval-chains": {
  "verplichting-goedkeuring": {
    "transition": "goedkeuren",            // matches x-openregister-lifecycle.transitions
    "amountField": "totaalbedrag_excl_btw", // EUR cents — REQ-008 threshold routing
    "separationOfDuties": true,             // REQ-009 approver != requester
    "approvers": [
      { "role": "commitment-administrator", "min": 1, "minAmount": 0 },
      { "role": "finance-director",         "min": 1, "minAmount": 25000000 } // >= EUR 250.000
    ],
    "onApprove": "advanceTransition"        // REQ-010 releases the parent transition
  }
}
```

`commitment-administrator` and `finance-director` are both roles already declared
in this schema's `x-openregister-rbac`. Mirrors the shape already shipped (inert)
on `BcfClaim` (`bookkeeping-bcf-vat-compensation.json`).

## Failing-path proof — where each control is proven

Because OR #396 is **not deployed**, shillinq's own PHPUnit suite (running against
its vendored dependencies, which do not include the OR listeners) **cannot** drive
the runtime gate. Honest split:

- **Proven in OpenRegister (already green, 14 tests):** over-threshold blocked
  (`approval-chain-pending`), approver == requester rejected (SoD), correct tier
  demanded by amount (REQ-008), parent transition stays blocked until the chain
  completes, completion auto-advances (REQ-007…010). These paths execute in OR's
  listeners, not shillinq code.
- **Proven in this change (contract test, `VerplichtingApprovalChainFragmentTest`):**
  the declared chain is well-formed against that contract — `transition` names a
  real lifecycle transition, `amountField` names a real integer property, tiers
  are ordered and each carries `role`/`min`/`minAmount`, `separationOfDuties` is
  `true`, `onApprove` is `advanceTransition` — so that **when** OR is deployed the
  gate enforces rather than silently no-ops. The test also asserts
  `MandaatEnforcer` is retained (no dead-control regression).
- **Unaffected, still green:** every `MandaatEnforcer` / `BudgetBlocker` /
  requisition / commitment-materialisation test — nothing was removed.

## Sequencing & named follow-up

1. **This change** (now): declare the chain; keep all imperative controls; document.
2. **Follow-up `migrate-mandaat-approval-chains-activation`** (blocked on the OR
   deploy): once the OpenRegister release carrying #396/#397 is deployed to the
   environment, (a) verify `x-openregister-approval-chains` survives a Verplichting
   schema save round-trip (REQ-006), (b) live-verify the four failing paths against
   the deployed listeners through the UI, (c) then re-evaluate whether any
   `MandaatEnforcer` behaviour has become genuinely redundant (expected: none).
3. **Orthogonal, pre-existing:** `MandaatEnforcer`'s `requires` guard tags
   (`MandaatEnforcer::requiresApproval`, `BudgetBlocker::canCommit`) are `Class::method`
   strings that OR's `LifecycleGuardRegistry::resolve()` cannot autowire and that
   `Application.php` does not register — the fleet-wide gap already filed as
   **shillinq#433**. That is a *separate* bug from this change and is not touched
   here; it must be fixed for the `indienen`/`goedkeuren` guards to enforce at all.
