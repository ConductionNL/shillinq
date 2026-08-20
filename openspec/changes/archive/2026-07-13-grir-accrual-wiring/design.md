# Design: grir-accrual-wiring

## Architecture Overview

Today: `GoodsReceiptNoteService::acceptGRN()` / `ServiceReceiptService::acceptServiceReceipt()`
transition their record to `accepted`, and `ThreeWayMatchingEngine::evaluateMatch()`
transitions the matched `SupplierInvoice` to `matched` — all three fire
`ObjectTransitionedEvent`, but nothing listens for them on the GR/IR side.
`GRIRClearingService::createGRIRPosting()`/`settleGRIRPosting()` are fully
implemented, unit- and integration-tested in isolation, and never called.

New: a single `GRIRClearingListener`, registered against
`ObjectTransitionedEvent`, dispatches on `(schema, to)`:

```
GoodsReceiptNote.accept (quality_checked|received -> accepted)   [EXISTING, unchanged]
  -> ObjectTransitionedEvent(schema=GoodsReceiptNote, to=accepted)
     -> GRIRClearingListener::handle()                            [NEW]
        -> GRIRClearingService::postGRIRForGoodsReceiptAccept()   [NEW orchestration method]
           for each GoodsReceiptLine (grnId=this GRN, quantityAccepted > 0):
             -> GRIRClearingService::createGRIRPosting()           [EXISTING, unchanged]
                -> GLTransaction{Dr PO-line account / Cr GR/IR clearing}

SvcReceipt.accept (confirmed -> accepted)                         [EXISTING, unchanged]
  -> ObjectTransitionedEvent(schema=SvcReceipt, to=accepted)
     -> GRIRClearingListener::handle()                            [NEW]
        -> GRIRClearingService::postGRIRForServiceReceiptAccept() [NEW orchestration method]
           for each SvcReceiptLine (serviceReceiptId=this receipt, quantityAccepted > 0):
             -> GRIRClearingService::createGRIRPosting()           [EXISTING, unchanged]
                -> GLTransaction{Dr PO-line account / Cr GR/IR clearing}

SupplierInvoice.matchSuccess (matching -> matched)                [EXISTING, unchanged —
                                                                     ThreeWayMatchingEngine::
                                                                     evaluateMatch() already
                                                                     fires this via
                                                                     SupplierInvoiceService::
                                                                     setStatus()]
  -> ObjectTransitionedEvent(schema=SupplierInvoice, to=matched)
     -> GRIRClearingListener::handle()                            [NEW]
        -> GRIRClearingService::settleGRIRForMatchedInvoice()     [NEW orchestration method]
           resolves the driving ThreeWayMatch (auto_approved/within_tolerance,
           most recent, for this invoiceId+administrationId)
             -> GRIRClearingService::settleGRIRPosting()           [EXISTING, unchanged]
                -> GLTransaction{Dr GR/IR clearing + Dr VAT / Cr Accounts Payable}
```

Why `SupplierInvoice.matched`, not `ThreeWayMatch` creation: a
`ThreeWayMatch` row is *created* with `matchStatus` already set to its
terminal value (`evaluateMatch()` builds the full array then calls
`saveObject()` once) — it is never *transitioned* into that status on an
existing object. OpenRegister's `SaveObject::seedLifecycleFieldOnCreate()`
docblock is explicit: a lifecycle field set at create time "Dispatches no
`ObjectTransitionedEvent` — this is initialisation." Listening for
`ObjectTransitionedEvent` on `ThreeWayMatch` would therefore never fire.
`ThreeWayMatchingEngine`'s own docblock already documents the correct
trigger: "...triggers an immediate SupplierInvoice transition out of
`received` → `matching` → `matched` so member 09's GR/IR clearing posting
fires declaratively" — `SupplierInvoiceService::setStatus()` performs a
genuine find-then-save on the pre-existing invoice object, so the
transition event fires reliably, matching the precedent set by
`DeliveryDispatchListener` (listens on `Delivery`'s own transition, not on
a side-effect record's creation).

## Nextcloud Integration

- Controllers: none (no new HTTP surface — three existing lifecycle
  transitions gain a listener, nothing new is user-triggerable).
- Services: three new public methods on the existing
  `OCA\Shillinq\Service\GRIRClearingService`.
- Mappers/Entities: none — all persistence via
  `OCA\OpenRegister\Service\ObjectService`, matching every sibling service
  in this register.
- Events/Hooks: `OCA\Shillinq\Listener\GRIRClearingListener` (new),
  registered against `OCA\OpenRegister\Event\ObjectTransitionedEvent` in
  `lib/AppInfo/Application.php`, mirroring the existing
  `DeliveryDispatchListener`/`StockMoveTransitionedListener` registrations.

## Security Considerations

No new HTTP endpoints. The listener extracts `administrationId` from the
transitioned object itself (server-authoritative — the object was already
validated and persisted under that scope by the service that wrote it) and
passes it straight into `GRIRClearingService`'s existing
`assertAccess()`/`AdministrationContextService::canAccess()` guard,
unchanged. Fail-soft: any exception (including a denied administration
scope) is logged and never bubbles into the receipt-accept or invoice-match
transition itself — the accounting posting is a downstream effect, not a
precondition, matching `DeliveryDispatchListener`'s and
`StockMoveTransitionedListener`'s established contract. No new secrets, no
new external calls.

## File Structure

```
lib/
  Service/
    GRIRClearingService.php            [MODIFIED: +postGRIRForGoodsReceiptAccept(),
                                          +postGRIRForServiceReceiptAccept(),
                                          +settleGRIRForMatchedInvoice();
                                          createGRIRPosting()/settleGRIRPosting()
                                          unchanged]
  Listener/
    GRIRClearingListener.php           [NEW]
  AppInfo/
    Application.php                    [MODIFIED: register listener]
tests/Unit/
  Service/GRIRClearingServiceTest.php  [MODIFIED: +orchestration-method tests]
  Listener/GRIRClearingListenerTest.php [NEW]
```

## Seed Data

No new schemas or fields are introduced — this change only adds callers
for schemas/fields that already exist (`GoodsReceiptLine.quantityAccepted`,
`SvcReceiptLine.quantityAccepted`, `ThreeWayMatch.matchStatus`, the
existing `gr_ir_clearing_account`/`accounts_payable_account`/
`vat_payable_account` app-config keys `GRIRClearingService` already reads).
No new seed objects required; existing demo administrations
(`adm-demo-1`, etc.) already carry `PurchaseOrderLine.glAccount` and the
default GR/IR account codes, so existing seeded GRN/SvcReceipt accept
flows start posting without any seed-data edit.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Decision | Rationale |
|---|---|---|
| Dispatch on `GoodsReceiptNote`/`SvcReceipt`/`SupplierInvoice` transitions | **Imperative** — `GRIRClearingListener` on `ObjectTransitionedEvent` | Cross-schema reaction (a transition on one schema must read rows from another schema and write a third+fourth). No declarative `x-openregister-lifecycle-action` primitive exists that reads a *different* schema's child rows (`GoodsReceiptLine`/`SvcReceiptLine`) by parent-FK and fans out a posting per row — the existing `materialise-gl-transaction` action (used by `inventory-cogs-posting.json`) only posts from `@self`'s own fields, not from a joined child collection. Same class of exception already accepted for `DeliveryDispatchListener`/`StockMoveTransitionedListener` in this register. |
| Per-line fan-out (`GoodsReceiptLine`/`SvcReceiptLine` by parent id) | **Imperative** — `findAll()` via `ObjectService`, same idiom as `GoodsReceiptNoteService::acceptGRN()`'s own existing per-line StockMove fan-out | Consistent with the existing pattern in the very method this change wires into; no new query mechanism introduced. |
| Resolving the driving `ThreeWayMatch` for a matched invoice | **Imperative** — `findAll()` filtered by `invoiceId`+`administrationId`, most-recent `auto_approved`/`within_tolerance` row | A relation/aggregation declarative primitive could resolve "the ThreeWayMatch for this invoice" in principle, but `GRIRClearingService` already resolves every other cross-schema reference (`PurchaseOrderLine`, `SupplierInvoice`, `ToleranceProfile`) imperatively via the same `findOne()`/`findAll()` helpers — introducing one declarative relation here while every sibling lookup in the same class stays imperative would fragment, not simplify, the class's contract (design D6 in the class docblock: "materialise the GR/IR accounting in one place"). |
| The GL posting itself (`createGRIRPosting()`/`settleGRIRPosting()`) | **Not touched** — already-implemented imperative service, unchanged | This change's entire purpose is to feed the *existing* posting logic; re-implementing or forking it would violate the "one place" contract already documented on the class. |

No new PHP class exceeds the single-responsibility precedent already set
by `DeliveryDispatchListener` (thin dispatch-only listener) and
`GRIRClearingService` (imperative posting service) in this same register;
this change adds one listener and three thin orchestration methods to the
existing service, per ADR-031.

## Risks / Trade-offs

- [Risk] A receipt or invoice accepted/matched before this change deployed
  never posted its GR/IR entry and will not retroactively post one →
  [Mitigation] out of scope for this change (a backfill/reconciliation
  job is a separate, larger piece of work); `reconcileGRIRSaldoForPeriod()`
  already exists to surface any such dangling balance to an operator.
- [Risk] `settleGRIRForMatchedInvoice()`'s "most recent matching
  ThreeWayMatch" resolution could pick the wrong match if
  `evaluateMatch()` is somehow invoked twice for the same invoice →
  [Mitigation] `settleGRIRPosting()`'s deterministic `transactionNumber`
  (derived from invoiceNumber/matchId/invoiceId, not the ThreeWayMatch's
  own id) means a second settlement attempt targets the same GL
  transaction number rather than silently double-posting; any write
  conflict is caught by the existing fail-soft listener wrapper and
  logged, not raised to the user.

## Migration Plan

No data migration — purely additive PHP (new listener + three new service
methods) plus one new `registerEventListener()` call. Deploy: merge; no
register.d fragment changes means no schema re-import is required.
Rollback: revert the two PHP file diffs and delete
`GRIRClearingListener.php`; no existing `GLTransaction`/`GLLine` rows are
affected.

## Open Questions

None.

## Trade-offs

Considered adding a declarative `x-openregister-lifecycle-action` block
directly on the `GoodsReceiptNote`/`SvcReceipt`/`SupplierInvoice`
transitions instead of a listener. Rejected: none of the three actions fit
the existing `materialise-gl-transaction` dialect's contract (single-object
field-driven posting) — each requires reading a *different* schema's rows
(child lines, or the driving match) before computing the posting, which
the dialect does not express today. Filing a dialect-extension request on
the `openregister` repo is a reasonable follow-up but out of scope for
this correctness fix.
