---
kind: code
depends_on: []
---

# Proposal: verplichtingen-commitment-accounting

## Summary

Close the loop between the **purchase-order / contract approval** surfaces and
the existing **verplichtingenadministratie** (commitment accounting). Today the
`Verplichting` register, `BudgetBlocker`, `MandaatEnforcer` and the drie-staps
mutatie model all exist, but a commitment must be raised through the verplichting
lifecycle directly — approving a PO or signing a contract does **not** yet
materialise the matching `Verplichting`, so budget is only reserved if someone
remembers to open one. This change adds the thin event-glue that **auto-creates a
`Verplichting` at PO approval and at contract signature**, adds **committed-vs-
realised reporting per budget line** (a drilldown beyond the existing
per-programma BBV columns), and wires the auto-created commitment into the
existing **rechtmatigheid** toetsing so lawfulness checks fire at the commitment
stage on system-raised commitments, not only manual ones.

## Motivation

Dutch `verplichtingenadministratie` (a BBV / Comptabiliteitswet requirement for
gemeenten, provincies and waterschappen, and a rechtmatigheid cornerstone)
demands that budget is consumed **when the organisation becomes legally bound**
— PO signed, contract executed — not when the invoice arrives. The heavy lifting
is already built (REQ-VPL-001…009), but the trigger is manual: `BudgetBlocker`
only reserves budget once a `Verplichting` reaches `aangegaan`, and nothing turns
an approved PO (`bookkeeping-purchase-order-3way` REQ-PO3W-001) or a signed
contract into that `Verplichting`. The consequence is a real rechtmatigheid gap —
commitments made outside the register are invisible to `vrije_ruimte` and to the
rechtmatigheidsverantwoording. 83 parsed tender requirements on
budget/verplichtingen and gemeente journeys point at exactly this seam: "when I
approve the order, the budget must be committed automatically." This change
supplies only that missing glue plus the per-line visibility and rechtmatigheid
linkage that make the committed figure trustworthy.

## Affected Projects

- [x] Project: `shillinq` — thin listener that materialises a `Verplichting`
  from the PO `approved` and contract `signed` transitions (reusing
  `BudgetBlocker` + `MandaatEnforcer` + `BudgetImpactEmitter`); a declarative
  per-budget-line committed-vs-realised aggregation + drilldown; and a
  rechtmatigheid linkage on the auto-created commitment.

## Capabilities

- `bookkeeping-verplichtingenadministratie` (MODIFIED — ADDED requirements
  REQ-VPL-010, REQ-VPL-011, REQ-VPL-012).

## Scope

### In Scope

- A **thin-glue** listener that, when a PurchaseOrder reaches `approved` (or a
  Contract reaches `signed`/`executed`), materialises a `Verplichting` with
  provenance (`bronReferentie` → PO/contract), one `VerplichtingRegel` per budget
  coderingscombinatie (programma + kostenplaats + boekjaar + grootboekrekening),
  and drives it through the existing `MandaatEnforcer` / `BudgetBlocker` guards.
  Idempotent: re-emitting the transition MUST NOT create a duplicate commitment.
- A declarative **committed-vs-realised per budget line** aggregation
  (`x-openregister-aggregations`) exposing, per `VerplichtingRegel` budget line,
  `geautoriseerd` / `verplicht (openstaand)` / `gerealiseerd` / `vrij`, plus a UI
  drilldown from a budget line to its underlying commitments.
- A **rechtmatigheid tie-in**: the auto-created `Verplichting` SHALL carry the
  linkage that triggers the existing rechtmatigheid toetsing at the commitment
  stage (REQ-RV-008), and any `MandaatEnforcer` override reason SHALL be recorded
  as a rechtmatigheid-relevant afwijking.

### Out of Scope

- The `Verplichting` schema, lifecycle, drie-staps mutatie model, mandaat-toetsing,
  raamovereenkomsten, and drie-weg-matching — all **already delivered** by the
  archived `bookkeeping-verplichtingenadministratie` change (REQ-VPL-001…009); not
  redefined here.
- The rechtmatigheid toetsing engine itself (REQ-RV-001…010) — this change only
  ensures the auto-created commitment is fed into it.
- Any new budget-overrun guard on GL postings (programmabegroting REQ-010 is
  unchanged; commitment blocking already lives in `BudgetBlocker`).

## Approach

A single listener (`CommitmentMaterialisationListener`) subscribes to the PO/
contract approval transitions and calls a small `CommitmentMaterialisationService`
that builds the `Verplichting` + regels from the source object and delegates to the
existing guards. Reporting is fully declarative (an aggregation + a list-view
drilldown). The rechtmatigheid linkage is a field/reference the materialiser sets;
no change to the toetsing engine. Details, including the thin-glue LOC budget and
the declarative-vs-imperative split, are in design.md.

## New Dependencies

None. Reuses `BudgetBlocker`, `MandaatEnforcer`, `BudgetImpactEmitter`, the
`Verplichting`/`VerplichtingRegel` registers, and OR's aggregation engine.

## Impact

- New `lib/Listener/CommitmentMaterialisationListener.php` +
  `lib/Service/Commitment/CommitmentMaterialisationService.php` (thin glue).
- New declarative aggregation in
  `lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json` (or a
  companion fragment) — additive.
- New/extended budget-line drilldown view (frontend).
- No schema-breaking change; the `Verplichting` gains at most an optional
  provenance/rechtmatigheid reference field if not already present.

## Cross-Project Dependencies

- Consumes the shillinq-internal PO `approved` and contract `signed` lifecycle
  transitions (both owned by shillinq). No cross-app RPC. The existing
  `BudgetImpactEmitter` obligation-activated CloudEvent continues to inform
  downstream consumers (openconnector / pipelinq) unchanged.

## Risks

### Risk 1: Duplicate commitments on transition replay

**Severity:** Medium — **Mitigation:** Materialisation MUST be idempotent, keyed
on `bronReferentie` (PO/contract id); a second `approved` event for the same
source MUST be a no-op. Covered by a dedicated scenario.

### Risk 2: PO approval blocked by insufficient budget creates operator friction

**Severity:** Medium — **Mitigation:** Reuse the existing `BudgetBlocker`
fail-closed + override-mandate path; when budget is insufficient the commitment
is denied with the existing "insufficient budget" message and the PO surfaces it,
rather than silently approving an unfunded PO.

### Risk 3: Double-spec against the archived verplichtingen change

**Severity:** Low — **Mitigation:** This proposal explicitly scopes to the
materialisation glue, per-line reporting, and rechtmatigheid linkage only; the
delta specs use ADDED requirements continuing the REQ-VPL sequence (010+).

## Rollback Strategy

Additive and event-driven. Rollback = unregister the listener (commitments revert
to manual creation) and revert the aggregation fragment + drilldown view. Existing
commitments, budgets, and reports are unaffected.

## Open Questions

- Whether a signed **Contract** entity exists as a first-class lifecycle in this
  repo today or arrives with contract-lifecycle-management — resolved by keying the
  listener on whichever `signed`/`executed` transition exists; if only PO exists at
  apply time, the contract trigger is registered but dormant until the contract
  lifecycle lands (documented in design.md).
