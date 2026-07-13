# Design: inventory-sales-issue-cogs-trigger

## Architecture Overview

Today: `GoodsReceiptNoteService` / `CycleCountService` → write `StockMove`
(`posted`) → `ObjectTransitionedEvent` → `StockMoveTransitionedListener` →
`FifoValuationService` / `MovingAverageValuationService` →
`CogsPosterService`. This pipeline is untouched.

New: `Delivery` (`draft → confirmed`) → `ObjectTransitionedEvent` → new
`DeliveryDispatchListener` → new `SalesDispatchStockIssueService` → writes
one `StockMove` (`movementType: issue`, `posted`) per stock-tracked line →
feeds into the *same* existing pipeline above, unmodified. On
`Delivery` (`* → cancelled`), the same listener/service transitions the
`StockMove`(s) it created through the existing `StockMove.cancel`
transition, which already materialises the offsetting move and reverses
the GL entry — no new reversal logic is written.

A hard stock-availability block is enforced *before* the `Delivery.confirm`
transition commits, as an extension to the existing
`QuoteOrderInvoiceGuard::canConfirmDelivery` (a `requires:` guard, the only
point in the lifecycle that can deny a transition synchronously).

```
Delivery.confirm (draft -> confirmed)
  requires: QuoteOrderInvoiceGuard::canConfirmDelivery
    [EXTENDED] + per-line stock-availability check (block unless
    InventoryGLConfig.allowNegativeStockOnDispatch)
  -> ObjectTransitionedEvent(schema=Delivery, to=confirmed)
     -> DeliveryDispatchListener::handle()
        -> SalesDispatchStockIssueService::issueForDelivery($delivery)
           for each stock-tracked line:
             -> StockMove{movementType: issue, lifecycleState: posted}  [NEW WRITE]
                -> ObjectTransitionedEvent(schema=StockMove, to=posted)  [EXISTING, unchanged]
                   -> StockMoveTransitionedListener
                      -> Fifo/MovingAverage ValuationService -> CogsPosterService

Delivery.cancel (draft|confirmed -> cancelled)   [NEW transition]
  requires: QuoteOrderInvoiceGuard::canCancelDelivery  [NEW guard]
  -> ObjectTransitionedEvent(schema=Delivery, to=cancelled)
     -> DeliveryDispatchListener::handle()
        -> SalesDispatchStockIssueService::reverseForDelivery($delivery)
           for each StockMove this delivery issued:
             -> transition StockMove to 'cancelled'  [EXISTING transition, unchanged]
                -> StockMoveOffsetCreator emits the offsetting move + GL reversal
```

## Nextcloud Integration

- Controllers: none (no new HTTP surface).
- Services: `OCA\Shillinq\Service\SalesDispatchStockIssueService` (new).
- Mappers/Entities: none — all persistence via `OCA\OpenRegister\Service\ObjectService`.
- Events/Hooks: `OCA\Shillinq\Listener\DeliveryDispatchListener` (new),
  registered against `OCA\OpenRegister\Event\ObjectTransitionedEvent` in
  `lib/AppInfo/Application.php`, mirroring the existing
  `StockMoveTransitionedListener` registration.

## Security Considerations

No new HTTP endpoints. `SalesDispatchStockIssueService` scopes every read/
write by `administrationId` exactly like every existing sibling service
(`GoodsReceiptNoteService`, `CycleCountService`, `StockReservationGuard`).
The new guard methods on `QuoteOrderInvoiceGuard` fail closed on any
exception (existing `evaluate()` wrapper), consistent with the rest of the
class. No new secrets, no new external calls.

## File Structure

```
lib/
  Settings/register.d/
    inventory-sales-issue-cogs-trigger.json   [NEW fragment: Delivery.sourceLocationId,
                                                Delivery.cancel transition,
                                                InventoryGLConfig.allowNegativeStockOnDispatch]
  Lifecycle/
    QuoteOrderInvoiceGuard.php                [MODIFIED: canConfirmDelivery stock check,
                                                new canCancelDelivery]
  Service/
    SalesDispatchStockIssueService.php        [NEW]
  Listener/
    DeliveryDispatchListener.php              [NEW]
  AppInfo/
    Application.php                           [MODIFIED: register listener]
tests/Unit/
  Lifecycle/QuoteOrderInvoiceGuardStockTest.php   [NEW]
  Service/SalesDispatchStockIssueServiceTest.php  [NEW]
  Listener/DeliveryDispatchListenerTest.php       [NEW]
```

## Seed Data

No new schemas are introduced (this change only adds fields to the
existing `Delivery` and `InventoryGLConfig` schemas), so no new seed
*objects* are required. The register fragment sets defaults so existing
seed data stays valid without edits:

### Schema: `Delivery` (field addition, no new objects)
| Field | Default applied to existing seed rows |
|-------|----------------------------------------|
| `sourceLocationId` | `null` (existing `qoi-sample-delivery-retail-1` / `-2` seed rows fall back to the InventoryStock-resolution path at runtime — covered by the fallback unit test) |

### Schema: `InventoryGLConfig` (field addition, no new objects)
| Field | Default applied to existing seed rows |
|-------|----------------------------------------|
| `allowNegativeStockOnDispatch` | `false` (block policy, matching REQ-IST-013's documented intent) |

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Decision | Rationale |
|---|---|---|
| `Delivery.sourceLocationId` field, `Delivery.cancel` transition, `InventoryGLConfig.allowNegativeStockOnDispatch` field | **Declarative** — register.d fragment | Plain schema/lifecycle additions; no logic. |
| Fan-out from `Delivery.lines` (an inline array field) into N `StockMove` creates | **Imperative** — `SalesDispatchStockIssueService` | `x-openregister-iterate-and-create` (the only fan-out action in the dialect) iterates a *queryable schema* via `sourceRegister`/`sourceSchema`/`sourceFilter`; it has no syntax to iterate an inline `@self.lines[]` array. No declarative fan-out primitive exists for this shape. |
| "Is this line stock-tracked" (cross-schema existence check against `InventoryStock`) | **Imperative** | Requires a conditional per-item schema lookup inside the fan-out; same class of gap as the existing `QuoteOrderInvoiceGuard` exceptions already documented for this register. |
| Idempotent re-confirm (no double-issue) | **Imperative** | Requires a pre-create existence check (`referenceDocumentUri` lookup) — the same idempotency pattern already used by `StockMoveOffsetCreator::offsetAlreadyExists()`. |
| Insufficient-stock block (hard deny) | **Imperative** — extension to `QuoteOrderInvoiceGuard::canConfirmDelivery` | Only a `requires:` guard can synchronously deny a transition; this is the exact mechanism `canConfirmOrder`/`canConfirmDelivery` already use for the credit-hold checks in this same register. |
| Reversal on `Delivery.cancel` | **Imperative** dispatch, but re-uses the fully **declarative** `StockMove.cancel` transition (offset + GL reversal) | The dispatch (find which `StockMove`s belong to this delivery) is imperative; the actual reversal mechanics are 100% existing declarative code, unchanged. |
| Valuation (FIFO/moving-average) + COGS GL posting | **Not touched** — 100% existing declarative + `FifoValuationService`/`MovingAverageValuationService`/`CogsPosterService` | This change's entire purpose is to feed the *existing* pipeline; re-implementing any of it here would violate the "do NOT reimplement valuation" constraint. |

No new PHP class exceeds the single-responsibility precedent already set by
`GoodsReceiptNoteService` (imperative writer) and `QuoteOrderInvoiceGuard`
(imperative guard) in this same register — this change adds one writer
service and extends the one existing guard class, per ADR-031.

## Risks / Trade-offs

- [Risk] Warehouse resolution fallback (largest-available `InventoryStock`
  row) could pick a source location the operator did not intend when
  `sourceLocationId` is left unset on both `Delivery` and the line →
  [Mitigation] documented behaviour, covered by a unit test, and
  `sourceLocationId` is the recommended field to set going forward; a UI
  affordance is a follow-up, not required for correctness.
- [Risk] A concurrent sale could pass the `canConfirmDelivery` stock check
  and then lose a race to another concurrent dispatch before the
  `StockMove` write lands → [Mitigation] `StockReservationGuard::commitReservation`
  underneath the `StockMove.post` transition still performs its own
  on-hand check; a race is surfaced as a fail-soft warning log on the
  `StockMove`, identical to today's behaviour for concurrent receipts. Not
  a regression.

## Migration Plan

No data migration — purely additive schema fields (safe defaults) + new
PHP classes + one new lifecycle transition. Deploy: merge, `occ upgrade`
re-imports the register (fragment signature changes force a re-import,
per the existing `SettingsService` convention). Rollback: revert the
register.d fragment and the two modified/new PHP files; no existing
`StockMove`/`Delivery` rows are affected by a rollback.

## Open Questions

None.

## Trade-offs

Considered driving the trigger off `SalesOrder.ship`/`shipDirect` instead
of `Delivery.confirm`. Rejected: `Delivery` is the schema that actually
carries shipped line quantities (`lines[].quantityShipped`); `SalesOrder`'s
own transitions carry no quantity data and would require re-deriving it
from `SalesOrderLine`, which is one more indirection for no benefit —
`Delivery.confirm` is the literal "goods leave the warehouse" event.
