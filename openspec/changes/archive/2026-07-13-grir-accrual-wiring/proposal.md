---
kind: code
depends_on: []
---

# Proposal: grir-accrual-wiring

## Summary

`GRIRClearingService` (member 09 of `bookkeeping-purchase-order-3way`,
REQ-PO3W-009) fully implements the two-stage GR/IR clearing posting —
`createGRIRPosting()` on goods/service receipt accept and
`settleGRIRPosting()` on invoice-match approval — with passing unit and
integration tests. But **nothing in `lib/` ever calls either method**:
`GoodsReceiptNoteService::acceptGRN()`, `ServiceReceiptService::acceptServiceReceipt()`,
and `ThreeWayMatchingEngine::evaluateMatch()` all reference member 09 in
their docblocks as "firing declaratively" once the chain lands, but no
listener or lifecycle action was ever wired. Filed as shillinq#412. This
voids the accrual-accounting half of REQ-PO3W-009 in any live install:
goods and services are received and the PO lines update, but the
"accrued, not yet invoiced" liability never reaches the balance sheet, and
the GR/IR clearing account never clears when the matching invoice is
approved. This change wires the existing, unchanged `GRIRClearingService`
into the existing `GoodsReceiptNote`/`SvcReceipt` accept transitions and
the `SupplierInvoice` `matching → matched` transition, reusing the
`ObjectTransitionedEvent` listener idiom already proven by
`DeliveryDispatchListener`/`SalesDispatchStockIssueService` (PR #404).

## Motivation

Same defect shape as two prior fixes in this codebase this wave
(`FxRevaluationService` orphaned call-site, PR #403; missing `issue`
StockMove producer, PR #404): a fully-implemented, fully-tested GL-posting
capability with zero production callers. The spec `bookkeeping-purchase-order-3way-09-gl-gr-ir-clearing`
reports `Status: done` and its own unit/integration tests pass in
isolation (they call `GRIRClearingService` directly), but a live install
driven through the UI never posts a single GR/IR entry — the capability is
100% dead while every other signal (spec status, tests, code review of the
service in isolation) says it works.

## Affected Projects

- [x] Project: `shillinq` — new listener wiring three existing lifecycle
  transitions to the existing, unmodified `GRIRClearingService`; two new
  thin orchestration methods added to that service to resolve the
  per-line fan-out and the settlement's driving `ThreeWayMatch`.

## Scope

### In Scope

- On `GoodsReceiptNote` `* → accepted`: post the GR/IR clearing entry
  (`createGRIRPosting()`) for every `GoodsReceiptLine` with
  `quantityAccepted > 0`.
- On `SvcReceipt` `confirmed → accepted`: post the same clearing entry
  for every `SvcReceiptLine` with `quantityAccepted > 0` (field-name
  identical to `GoodsReceiptLine` by design — see `ServiceReceiptService`
  docblock).
- On `SupplierInvoice` `matching → matched` (the existing `matchSuccess`
  transition `ThreeWayMatchingEngine::evaluateMatch()` already fires):
  resolve the driving `ThreeWayMatch` (`auto_approved`/`within_tolerance`)
  for that invoice and post the settlement (`settleGRIRPosting()`).
- Two new thin orchestration methods on `GRIRClearingService`
  (`postGRIRForGoodsReceiptAccept()`, `postGRIRForServiceReceiptAccept()`,
  `settleGRIRForMatchedInvoice()`) that resolve the line/match fan-out;
  `createGRIRPosting()`/`settleGRIRPosting()` themselves are unchanged.
- A new `GRIRClearingListener` (mirrors `DeliveryDispatchListener`),
  registered in `Application.php`, fail-soft per the established
  contract (an accounting-posting failure never blocks the receipt/match
  transition itself — logged, not swallowed silently).
- Tests proving a balanced GL: the clearing posting on receipt-accept and
  the settlement posting on invoice-match, using the existing
  `InMemoryObjectService` test double (real service/listener/engine
  classes, no mocked business logic).

### Out of Scope

- `reconcileGRIRSaldoForPeriod()` — already implemented, already has an
  integration test exercising it directly; no caller wiring needed
  (it's an operator-invoked reconciliation report, not a lifecycle
  reaction). No controller/route exists for it yet — tracked as a
  separate, non-blocking gap (see Part 1 sweep report).
- The unrelated, independently-orphaned `InventoryValuation`
  `postCOGS`/`postReceipt`/`postVariance` declarative lifecycle actions
  (`inventory-cogs-posting.json`) — a different capability on a different
  schema, already flagged as a known risk in the
  `inventory-sales-issue-cogs-trigger` proposal ("two independent
  GL-posting paths"), confirmed still orphaned by this wave's sweep, and
  filed as its own Codeberg issue rather than fixed here.
  `CogsPosterService` (app-config-driven) is the actually-wired COGS
  path; it is untouched by this change.
- Any change to `ThreeWayMatchingEngine`'s matching/tolerance logic,
  `GoodsReceiptNoteService`'s StockMove posting, or
  `ServiceReceiptService`'s lifecycle guards.

## Approach

A new `GRIRClearingListener`, registered against
`OCA\OpenRegister\Event\ObjectTransitionedEvent`, dispatches three cases
by `(schema, to)`:

1. `GoodsReceiptNote` → `accepted`: calls
   `GRIRClearingService::postGRIRForGoodsReceiptAccept()`.
2. `SvcReceipt` → `accepted`: calls
   `GRIRClearingService::postGRIRForServiceReceiptAccept()`.
3. `SupplierInvoice` → `matched`: calls
   `GRIRClearingService::settleGRIRForMatchedInvoice()`.

All three read-side lookups (receipt lines; the driving `ThreeWayMatch`)
reuse the same `find`/`findAll` via `ObjectService` idiom every other
service in this file already uses. No new schema, no new register.d
fragment — every field the posting needs already exists on
`GoodsReceiptLine`/`SvcReceiptLine`/`ThreeWayMatch`.

## New Dependencies

None.

## Impact

- `lib/Service/GRIRClearingService.php` — three new public orchestration
  methods added; `createGRIRPosting()`/`settleGRIRPosting()` unchanged.
- `lib/Listener/GRIRClearingListener.php` — new file.
- `lib/AppInfo/Application.php` — one new `registerEventListener()` call.
- `tests/Unit/Service/GRIRClearingServiceTest.php` — new orchestration-method
  tests.
- `tests/Unit/Listener/GRIRClearingListenerTest.php` — new file, including
  an end-to-end correctness proof mirroring
  `DeliveryDispatchListenerTest::testConfirmedDeliveryProducesIssueMoveThatDrivesCogsPosting()`.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Re-firing an already-accepted GRN/SvcReceipt could double-post
**Severity:** Low — **Mitigation:** both `acceptGRN()` and
`acceptServiceReceipt()` reject re-acceptance from a terminal state
(`RuntimeException`), so the transition — and therefore the event — can
only fire once per receipt. `createGRIRPosting()`'s deterministic
`transactionNumber` is an additional existing safety net.

### Risk 2: A ThreeWayMatch record is CREATED with its terminal
`matchStatus` already set, not transitioned into it
**Severity:** Medium — **Mitigation:** confirmed via OpenRegister's
`SaveObject::seedLifecycleFieldOnCreate()` docblock ("Dispatches no
`ObjectTransitionedEvent` — this is initialisation") that a lifecycle
field set at object-creation time does not fire a transition event. This
change therefore does NOT listen on `ThreeWayMatch` creation; it listens
on the `SupplierInvoice`'s own `matching → matched` transition instead
(an update to a pre-existing object), exactly as
`ThreeWayMatchingEngine`'s own docblock already documents as the intended
trigger point ("...so member 09's GR/IR clearing posting fires
declaratively").

## Rollback Strategy

Remove the `registerEventListener()` call and delete
`GRIRClearingListener.php`; the three new `GRIRClearingService` methods
are additive and inert without a caller. No data migration — any
`GLTransaction`/`GLLine` rows already posted remain valid ledger entries.

## Open Questions

None.
