---
kind: code
depends_on: []
---

# Proposal: purchase-requisition

## Summary

Adds a purchase-requisition (aanvraag) sub-ledger to shillinq: an employee
raises a `Requisition` (with `RequisitionLine` items) before a `PurchaseOrder`
exists, submits it for approval, and an approver approves or rejects it.
Approval reuses the existing commitment mandate/budget infrastructure from
`bookkeeping-verplichtingenadministratie`
(`OCA\Shillinq\Lifecycle\BudgetBlocker`, `OCA\Shillinq\Lifecycle\
MandaatEnforcer`) unmodified — `Requisition` carries the same
`programma`/`boekjaar`/`totaalbedrag_excl_btw`/`soort` field contract those
guards already read off a `Verplichting`, so `BudgetBlocker::canCommit` runs
verbatim against a `Requisition` object. An approved `Requisition` converts
to a `PurchaseOrder` via `RequisitionConversionService`, which reuses
`PurchaseOrderService::createPurchaseOrder()` unmodified and threads a new
optional `requisitionId` FK + traceability link both ways. A new
`require_approved_requisition_for_po` policy flag (default OFF) lets an
administration require every `PurchaseOrder` to trace back to an
approved/converted `Requisition`.

## Motivation

**Architectural decision (2026-07-14): `purchaseq` does not justify existing
as a separate app and will be retired.** Purchase-requisition management was
originally scoped to `purchaseq`, a standalone procurement-suite app. On
review, a requisition-approval decision needs a **synchronous, in-process**
budget/verplichtingen check against the same commitment-mandate ledger
`shillinq` already owns (`Budget`, `Mandaat`, `BudgetBlocker`,
`MandaatEnforcer`) — "does this requisition fit inside the free room for
this programma/boekjaar, or does the approver hold a valid override
mandate?" is not a question a cross-app integration can answer
synchronously. `purchaseq` would have had to either duplicate the entire
budget/mandate engine (drifting from the source of truth in `shillinq`,
ADR-031's "don't reimplement" rule) or make a blocking network call into
`shillinq` on every approval click (a latency and availability liability,
and still not truly in-process/transactional). Building the requisition
sub-ledger directly in `shillinq`, alongside the budget/mandate engine it is
gated by, avoids both problems: `RequisitionService::approveRequisition()`
calls `BudgetBlocker::canCommit()` as a plain, unmodified, in-process PHP
method call — no event bus, no cross-app RPC, no eventual consistency
window. `purchaseq` has no `lib/` (openspec-only scaffold) and is retired in
a companion Codeberg issue; this change is the salvage destination for its
purchase-requisition capability.

## Affected Projects

- [x] Project: `shillinq` — 2 new schemas (`Requisition`, `RequisitionLine`),
  1 new controller, 3 new services/guards, 1 modified service
  (`PurchaseOrderService`), 1 modified schema (`PurchaseOrder` gains a
  nullable `requisitionId`), manifest UI (list, detail, approve/reject/
  convert actions), full test coverage.
- [x] Project: `purchaseq` — retired via a companion Codeberg issue (not a
  code change in this repository).

## Scope

### In Scope

- `Requisition` + `RequisitionLine` OR schemas (register.d fragment,
  ADR-037) with a declarative `draft -> submitted -> approved|rejected ->
  converted` lifecycle (`x-openregister-lifecycle`), RBAC roles, audit
  trail, and seed data covering every lifecycle-reachable status.
- `RequisitionService`: server-authoritative `createRequisition` /
  `submitRequisition` / `approveRequisition` / `rejectRequisition`.
  `approveRequisition` is gated by `BudgetBlocker::canCommit()`, reused
  unmodified — fail-closed (CWE-863): a budget-check failure or exception
  never approves.
- `RequisitionConversionGuard` (declarative-transition precondition,
  fail-closed) + `RequisitionConversionService` (ADR-031 imperative
  materialisation): converts an approved `Requisition` into a
  `PurchaseOrder` by delegating to `PurchaseOrderService::
  createPurchaseOrder()` unmodified, then writes `convertedPurchaseOrderId`/
  `convertedAt`/`statusCode=converted` back onto the `Requisition`.
- `RequisitionController` + routes: create/submit/approve/reject/convert,
  every endpoint `#[NoAdminRequired]` with a manual session guard,
  `AdministrationContextService`-scoped (IDOR-safe, ADR-005), no stack
  traces returned to the client.
- `PurchaseOrderService::createPurchaseOrder()` gains an optional
  `requisitionId` payload field (threaded through to the persisted
  `PurchaseOrder`) and a new `assertRequisitionPolicy()` gate behind the
  `require_approved_requisition_for_po` app-config flag (default `false` —
  existing PO-creation flows that never reference a requisition are
  unaffected).
- `PurchaseOrder` schema gains a nullable `requisitionId` FK
  (`bookkeeping-purchase-order-3way-01` fragment) for the traceability link.
- Manifest UI: `Requisitions` index page + `RequisitionDetail` page (fields,
  lines related-list, submit/approve/reject/convert actions gated by
  `statusCode`, audit-trail tab), nav entry under Purchasing & Inventory.
- Test coverage: register-fragment tests (schema shape, lifecycle wiring,
  seed data), `RequisitionService` unit tests (create/submit/approve/reject
  against a **real, unmodified** `BudgetBlocker` — the over-budget-blocked
  scenario is proven end-to-end, not mocked away, ADR-009),
  `RequisitionConversionGuard` unit tests (fail-closed on every non-approved
  status, on a missing requisition, and on a lookup exception),
  `RequisitionConversionService` unit tests against a **real** unmodified
  `PurchaseOrderService` (approved-converts-with-link-intact,
  unapproved-cannot-convert across every non-approved status,
  no-preferred-supplier refusal, cross-tenant denial).

### Out of Scope

- Formal aanbestedingen (tenders), raamovereenkomsten, TenderNed/Peppol
  adapters, supplier onboarding/BIBOB/MVI — these were `purchaseq`
  capabilities with no synchronous-budget-check dependency on `shillinq`;
  they are not salvaged by this change (see the companion retirement
  issue's "salvage before archiving" check).
- Changing `require_approved_requisition_for_po` to default `true` for any
  existing administration — the flag ships OFF; enabling it per
  administration is a follow-up configuration decision, not a code change.
- A UI affordance for typing a free-text rejection reason via a dedicated
  modal — the shipped UI reuses the existing generic field-edit affordance
  (fill in `Rejection Reason` via the standard edit form, then click
  Reject) rather than introducing a new modal component; a nicer combined
  reject-with-reason dialog is a follow-up.

## Approach

See design.md for the schema shape, the ADR-031 declarative-vs-imperative
split (declarative lifecycle for `submit`/`approve`/`reject`, imperative
`RequisitionConversionService` for `convertToPO` because materialising a new
cross-schema `PurchaseOrder` record and writing its id back is outside what
the generic OR lifecycle engine can do), and the seed-data rationale.

## New Dependencies

None — `RequisitionService` and `RequisitionConversionService` consume
`OCA\Shillinq\Lifecycle\BudgetBlocker`, `OCA\Shillinq\Lifecycle\
MandaatEnforcer`, and `OCA\Shillinq\Service\PurchaseOrderService` unmodified
(ADR-022 — consume, don't reimplement).

## Impact

- `lib/Settings/register.d/purchase-requisition.json` — new fragment (2
  schemas, seed data).
- `lib/Service/RequisitionService.php` — new (587 lines).
- `lib/Service/RequisitionConversionService.php` — new (285 lines).
- `lib/Lifecycle/RequisitionConversionGuard.php` — new (142 lines).
- `lib/Controller/RequisitionController.php` — new (404 lines).
- `lib/Service/PurchaseOrderService.php` — modified: optional
  `requisitionId` payload field + `assertRequisitionPolicy()` gate (~56
  lines).
- `appinfo/routes.php` — 5 new routes.
- `src/manifest.json` — 2 new pages (`Requisitions`, `RequisitionDetail`), 1
  new nav entry.
- `tests/Unit/Settings/PurchaseRequisitionFragmentTest.php`,
  `tests/Unit/Service/RequisitionServiceTest.php`,
  `tests/Unit/Service/RequisitionConversionServiceTest.php`,
  `tests/Unit/Lifecycle/RequisitionConversionGuardTest.php` — new.

## Cross-Project Dependencies

None — this change is entirely internal to `shillinq`. The companion
`purchaseq` retirement issue is a documentation/governance action (Codeberg
issue), not a code dependency.

## Risks

### Risk 1: A direct call to OR's generic lifecycle-transition endpoint could flip `convertToPO` without materialising a PurchaseOrder
**Severity:** Medium — **Mitigation:** `RequisitionConversionGuard::
canConvert` is wired as the transition's `requires:` precondition (defence
in depth against a direct generic-engine call), but the actual PO creation
only happens through `RequisitionConversionService`. The manifest UI does
NOT expose `convertToPO` as a `lifecycle-transition` action for this exact
reason — it calls the dedicated `POST .../requisitions/{id}/convert`
controller endpoint instead, mirroring how `PurchaseOrderService::
blockSendUntilApproved()` itself already mutates lifecycle state outside the
generic transition engine.

### Risk 2: `require_approved_requisition_for_po` policy gate is inert by default
**Severity:** Low — **Mitigation:** deliberate — flipping the default to
`true` would change behaviour for every existing PO-creation caller that has
never referenced a requisition. Shipping it OFF keeps this change additive;
enabling it per administration is an explicit follow-up.

## Rollback Strategy

Revert the 4 new files, the `PurchaseOrderService`/routes/manifest diffs,
and the register.d fragment. No destructive migration: `Requisition`/
`RequisitionLine` are new schemas with no prior data; `PurchaseOrder.
requisitionId` is a new nullable field, so existing `PurchaseOrder` records
are unaffected by a revert.

## Open Questions

None.
