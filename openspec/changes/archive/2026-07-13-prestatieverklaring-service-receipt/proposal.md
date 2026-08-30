---
kind: code
related_chain: bookkeeping-purchase-order-3way
---

# Proposal: prestatieverklaring-service-receipt

## Summary

Adds a **prestatieverklaring** (service-entry-sheet / service-receipt) as the
third leg of the 3-way match for **service** purchase-order lines. Today
`GoodsReceiptNoteService` + the `GoodsReceiptNote`/`GoodsReceiptLine`
registers only model physical goods receipt; a service PO (consultancy,
maintenance, subscription, contract labour) has no equivalent receipt leg,
so `ThreeWayMatchingEngine::evaluateMatch()` can never find an accepted
receipt for it and every service invoice is permanently routed to
`exception_missing_grn` — a state with no legal way out. This change adds a
`SvcReceipt`/`SvcReceiptLine` register pair, a `ServiceReceiptService`
mirroring the GRN lifecycle, and teaches `ThreeWayMatchingEngine` to accept
either an accepted `GoodsReceiptNote` or an accepted `SvcReceipt` as the
matching engine's third leg.

## Motivation

`bookkeeping-purchase-order-3way` (member 04, `GoodsReceiptNoteService`)
implements only the goods-receipt path. `ThreeWayMatchingEngine::
evaluateMatch()` hard-codes `exception_missing_grn` for any invoice whose PO
has no GRN in `accepted`/`quality_checked` state — including POs that were
never meant to have one because the line item is a service. This makes
100% of service-PO invoices permanently stuck in the exception queue: there
is no `resolutionAction` that produces a legitimate matched state for a
service invoice, because the engine's only accepted "third leg" is a
goods receipt that will never exist. Approvers must resolve every single
service invoice by hand, defeating the entire purpose of the 3-way-match
automation for the (frequently majority-of-spend) services categories.

## Affected Projects

- [x] Project: `shillinq` — new `SvcReceipt`/`SvcReceiptLine` registers,
      `ServiceReceiptService`, `ServiceReceiptController` + routes, and a
      `ThreeWayMatchingEngine` change so service receipts satisfy the
      matching engine's third leg.

## Scope

### In Scope
- `SvcReceipt` / `SvcReceiptLine` OpenRegister schemas (declarative
  lifecycle, ADR-031) as member 12 of the `bookkeeping-purchase-order-3way`
  chain
- `ServiceReceiptService`: create receipt, add line (period + amount or
  percentage confirmation), confirm, accept — mirrors
  `GoodsReceiptNoteService`'s lifecycle shape
- Partial/periodic confirmation: one `SvcReceipt` line per billing period
  (e.g. one per month for a 12-month contract), cumulative completion
  tracked the same way `GoodsReceiptNoteService::
  updatePurchaseOrderReceiptLifecycle()` already tracks partial goods
  receipt
- `ThreeWayMatchingEngine::evaluateMatch()`: resolve an accepted
  `SvcReceipt` as an alternative third leg alongside the existing
  `GoodsReceiptNote` path; convert `SvcReceiptLine` rows into the same
  tuple shape `calculateDivergence()` already consumes
  (`quantityAccepted`/`quantityReceived`) so no divergence-scoring logic is
  duplicated
- `ServiceReceiptController` + routes (mirrors `GoodsReceiptNoteController`)
- Unit test proving a service PO + supplier invoice can now reach
  `auto_approved`/`within_tolerance`, which was previously impossible

### Out of Scope
- Vue UI components for capturing a service receipt (`ServiceReceiptForm.vue`
  / `Detail.vue`) — the backend + API surface is the correctness fix; a
  dedicated UI slice can follow the same pattern as member 04's
  `GoodsReceiptNoteForm.vue` in a follow-up change
- GR/IR GL clearing postings for service receipts — `GRIRClearingService`
  is not currently wired to fire from `GoodsReceiptNoteService::acceptGRN()`
  either (pre-existing gap, not introduced or fixed by this change); wiring
  either leg into GL clearing is tracked separately
- Multi-PO consolidated service invoices — inherits the same
  single-PO-per-match scope boundary member 06 already documents

## Approach

Add a new declarative register.d fragment (member 12) declaring
`SvcReceipt` (lifecycle `draft → confirmed → accepted → rejected`, no
quality-check step — services have no physical inspection) and
`SvcReceiptLine` (poLineId, periodStart/periodEnd, percentageComplete OR
quantityConfirmed OR amountConfirmedCents, approver, confirmedAt). Add
`ServiceReceiptService` as an imperative service (justified in design.md)
mirroring `GoodsReceiptNoteService`'s four public transitions minus the
quality-check step and the StockMove posting (services never move
inventory). Extend `ThreeWayMatchingEngine::evaluateMatch()` to also query
`SvcReceipt`/`SvcReceiptLine` for the resolved PO id, and treat an accepted
`SvcReceipt` the same way an accepted `GoodsReceiptNote` is treated today —
by contributing lines into the same tuple pool `matchLineItems()` already
matches against `poLines`.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/bookkeeping-purchase-order-3way-12-service-receipt.json` (new)
- `lib/Service/ServiceReceiptService.php` (new)
- `lib/Controller/ServiceReceiptController.php` (new)
- `lib/Service/ThreeWayMatchingEngine.php` (modified — third-leg resolution)
- `appinfo/routes.php` (modified — new routes)
- `tests/Unit/Service/ServiceReceiptServiceTest.php` (new)
- `tests/Unit/Service/ThreeWayMatchingEngineTest.php` (modified — new
  service-PO-matches test)
- `openspec/specs/bookkeeping-purchase-order-3way/spec.md` (modified —
  REQ-PO3W-011 added, OpenSpec changes list updated)

## Cross-Project Dependencies

None — this is a self-contained shillinq change; it consumes only
shillinq's own registers via OpenRegister's ObjectService (ADR-022).

## Risks

### Risk 1: Field-shape drift between GoodsReceiptLine and SvcReceiptLine breaks the shared matching-engine code path

**Severity:** Medium — **Mitigation:** `SvcReceiptLine` deliberately reuses
the exact field names (`quantityAccepted`, `quantityReceived`) the matching
engine already reads off `GoodsReceiptLine`, computed from
percentage/amount/quantity confirmation at write time in
`ServiceReceiptService`, so `ThreeWayMatchingEngine::calculateDivergence()`
needs zero field-name branching. A unit test asserts the conversion.

### Risk 2: Cumulative periodic confirmation under/over-counts against quantityOrdered

**Severity:** Low — **Mitigation:** Reuses the identical integer-thousandths
accumulation pattern `GoodsReceiptNoteService::
updatePurchaseOrderReceiptLifecycle()` already uses for partial goods
receipt, which is exercised and tested.

## Rollback Strategy

Revert the four new/changed files; the new `SvcReceipt`/`SvcReceiptLine`
registers are additive (no existing register is modified) so no data
migration is needed. `ThreeWayMatchingEngine`'s third-leg resolution change
is additive (adds an `OR` branch); reverting it restores today's
goods-only behaviour with no data loss — no `SvcReceipt` records exist yet
for anything to orphan.

## Open Questions

None — decisions logged in design.md's Declarative-vs-imperative section.
