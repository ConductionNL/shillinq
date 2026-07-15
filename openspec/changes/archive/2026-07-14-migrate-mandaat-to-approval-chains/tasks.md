# Tasks — migrate-mandaat-to-approval-chains

## 1. Verify the cross-app contract (read-only)
- [x] 1.1 Confirm OR #396/#397 merged on `origin/development` (installer + both listeners + ApprovalService + archived REQ-006…010 spec).
- [x] 1.2 Confirm the deployed OR (container) predates #396 — no installer/listeners, key would be dropped on save. Record the sequencing in design.md.

## 2. Coverage analysis
- [x] 2.1 Read `MandaatEnforcer` fully + every caller (BudgetBlocker, CommitmentMaterialisationService, Application.php, register.d fragment) and the canonical spec.
- [x] 2.2 Build the coverage table (design.md): what OR covers (the `goedkeuren` workflow) vs what stays imperative (mandate-record resolution — ADR-031 exception). Confirm 0 lines removable from `MandaatEnforcer`.

## 3. Declarative migration (additive)
- [x] 3.1 Add the `x-openregister-approval-chains` block to the `Verplichting` schema, gating `goedkeuren` with amount-tier routing (`totaalbedrag_excl_btw`), `separationOfDuties: true`, tiers `commitment-administrator`/`finance-director`, `onApprove: advanceTransition`. Mirror the shipped `BcfClaim` shape.
- [x] 3.2 Keep `MandaatEnforcer`, `BudgetBlocker`, the `indienen`/`goedkeuren` guard refs, and all seeds unchanged.

## 4. Spec delta
- [x] 4.1 Add REQ-VPL-013 to `specs/bookkeeping-verplichtingenadministratie/spec.md` (the declared approval chain + its scenarios).

## 5. Tests (contract-level; runtime gate proven in OR)
- [x] 5.1 Add `VerplichtingApprovalChainFragmentTest`: the declared chain is well-formed against the OR contract (transition matches a real lifecycle transition; `amountField` is a real integer property; tiers ordered with role/min/minAmount; SoD true; onApprove advanceTransition).
- [x] 5.2 Assert `MandaatEnforcer` is retained and still referenced by `indienen` (no dead-control regression).
- [x] 5.3 Run the full suite in the `php:8.3-cli` container; report real numbers.

## 6. Follow-up filed
- [x] 6.1 Document the named activation follow-up (blocked on OR deploy) + the orthogonal shillinq#433 guard-registration gap in design.md.
