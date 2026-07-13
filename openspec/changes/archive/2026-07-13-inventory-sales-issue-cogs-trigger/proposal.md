---
kind: code
depends_on: []
---

# Proposal: inventory-sales-issue-cogs-trigger

## Summary

Shillinq's inventory valuation engine (`FifoValuationService`,
`MovingAverageValuationService`, `CogsPosterService`, dispatched by
`StockMoveTransitionedListener`) correctly consumes `StockMove` records with
`movementType: issue`, but **nothing in `lib/` ever writes one**. The only
existing `StockMove` writers are `GoodsReceiptNoteService` (`receipt`) and
`CycleCountService` (`receipt`/`issue` on count variance). The `SalesOrder` →
`Delivery` → `Invoice` sales-funnel model (`bookkeeping-quote-order-invoice`)
is fully wired for AR/billing but has **zero** contact with the stock layer:
goods are received and valued, but a sale never decrements stock and never
posts COGS. This voids the outbound half of four already-"done" specs
(`inventory-stock-movement-ledger`, `inventory-valuation-fifo-avg`,
`inventory-cogs-posting`, and the outbound side of `inventory-stock-tracking`)
in any real install. This change wires the missing outbound trigger: when a
`Delivery` is confirmed (the existing goods-issue event in the Q2C model),
the system emits one `issue` `StockMove` per stock-tracked line, reusing the
existing valuation + COGS pipeline unchanged, and reverses it when the
delivery is cancelled before shipment.

## Motivation

This is a correctness fix for a silent, unowned gap discovered by a
cross-app gap sweep: four specs across `inventory-stock-movement-ledger`,
`inventory-valuation-fifo-avg`, and `inventory-cogs-posting` report
`Status: done`, and their acceptance scenarios all pass in isolation
(they test the consumer side against hand-built fixture `StockMove` rows).
But in a live install driven through the UI, a sale never produces a
`StockMove`, so `InventoryValuation` never decreases, COGS never posts, and
`InventoryStock.quantity` only ever grows. Every dispatch silently fails to
decrement stock — this is a correctness bug, not a missing nice-to-have,
and it must be fixed before any of the four specs can be considered
genuinely done end-to-end.

## Affected Projects

- [x] Project: `shillinq` — new listener + service wiring the `Delivery`
  confirm/cancel lifecycle to the existing `StockMove` issue/cancel
  pipeline; small schema additions to `Delivery` and `InventoryGLConfig`.

## Scope

### In Scope

- Emit one `issue` `StockMove` per stock-tracked `Delivery` line when a
  `Delivery` transitions `draft → confirmed` (the existing goods-issue
  event in the Quote → Order → Delivery → Invoice model).
- Resolve warehouse (`sourceLocationId`) per delivery/line, quantity from
  `lines[].quantityShipped`, and product identity from
  `lines[].productReference` (reused verbatim as `StockMove.itemId`, the
  same convention `StockMove`'s own relation declaration already uses).
- Determine "stock-tracked" without a schema change to the (cross-app)
  Product catalogue: a line is stock-tracked iff an `InventoryStock` row
  already exists for `(administrationId, sku)`; otherwise it is treated as
  a service line and skipped.
- Idempotency: re-confirming / re-saving a `Delivery` MUST NOT double-issue
  stock for the same line.
- Insufficient-stock policy: block `Delivery` confirmation by default when
  a stock-tracked line's shipped quantity exceeds available stock at the
  resolved warehouse; allow (with a warning) per a new
  `InventoryGLConfig.allowNegativeStockOnDispatch` administration-level
  toggle, consistent with REQ-IST-013's documented intent in the existing
  `inventory-stock-tracking` spec.
- Reversal: add a `cancel` transition to `Delivery` (currently absent) and
  route it through the existing `StockMove.cancel` transition (which
  already materialises an offsetting move + reverses the GL entry) for
  every `StockMove` the cancelled delivery issued.
- Reuse the **existing** `FifoValuationService` / `MovingAverageValuationService`
  / `CogsPosterService` pipeline unchanged — this change only ever creates
  and cancels `StockMove` rows; it never computes valuation or posts GL
  entries itself.

### Out of Scope

- Restocking triggered by a `CreditNote` (AR/billing reversal only in the
  current `CreditNote` schema; it carries no line-level quantity or
  delivery linkage). Reversal in this change is scoped to `Delivery.cancel`
  only, which is the one existing, directly-corresponding physical-goods
  event. Restock-on-credit-note is a follow-up.
- Filling the pre-existing gap where `Delivery.confirm` does not yet update
  `SalesOrderLine.deliveredQuantity` (a `bookkeeping-quote-order-invoice`
  gap, not a stock/COGS gap — this change reads `quantityShipped` directly
  off `Delivery.lines` and does not depend on that aggregate).
- Reconciling the two independent GL-posting paths already present on
  `StockMove` (the schema's own `materialise-gl-transaction` lifecycle
  action vs. the `InventoryGLConfig`-driven `CogsPosterService` path) —
  this is a pre-existing overlap on *every* `StockMove`, including
  existing goods-receipt and cycle-count moves, not something this change
  introduces. Flagged as a follow-up finding.
- Any change to `pipelinq`'s Product catalogue schema.

## Approach

A new event listener (`DeliveryDispatchListener`), mirroring the existing
`StockMoveTransitionedListener` pattern, reacts to `Delivery`
`ObjectTransitionedEvent`s. On `confirmed`, a new
`SalesDispatchStockIssueService` creates one posted `issue` `StockMove` per
stock-tracked line (same direct-posted-create convention already used by
`GoodsReceiptNoteService` / `CycleCountService`). On `cancelled`, the same
service looks up the `StockMove`s it created (by `referenceDocumentUri`)
and transitions each through the existing `StockMove.cancel` transition.
The insufficient-stock policy is enforced *before* the transition commits,
as an extension to the existing `QuoteOrderInvoiceGuard::canConfirmDelivery`
guard (ADR-031 exception path, same class already used for the Q2C
lifecycle's other cross-schema preconditions) — this is the only place a
hard block on the transition itself is possible.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/` — one new fragment adding
  `Delivery.sourceLocationId`, a `Delivery.cancel` transition, and
  `InventoryGLConfig.allowNegativeStockOnDispatch`.
- `lib/Lifecycle/QuoteOrderInvoiceGuard.php` — extended with a
  stock-availability check on `canConfirmDelivery` and a new
  `canCancelDelivery` guard.
- `lib/Service/` — new `SalesDispatchStockIssueService.php`.
- `lib/Listener/` — new `DeliveryDispatchListener.php`, registered in
  `lib/AppInfo/Application.php`.
- No change to `FifoValuationService`, `MovingAverageValuationService`,
  `CogsPosterService`, or `StockMoveTransitionedListener` — they are
  consumed as-is.

## Cross-Project Dependencies

None. `SalesOrderLine.productReference` / `Delivery.lines[].productReference`
are reused as opaque identifiers exactly as `StockMove.itemId` already
treats them; no `pipelinq` schema is read or modified.

## Risks

### Risk 1: Two independent GL-posting paths may already double-post on every StockMove
**Severity:** Medium — **Mitigation:** Pre-existing on every `StockMove`
(receipt and cycle-count moves already trigger both `StockMove`'s own
`materialise-gl-transaction` action and the `CogsPosterService` path). Not
introduced or worsened by this change; documented as a follow-up rather
than fixed here to avoid destabilising already-shipped receipt/cycle-count
flows within this change's scope.

### Risk 2: Warehouse resolution has no single source of truth today
**Severity:** Low — **Mitigation:** `Delivery` gains an explicit
`sourceLocationId` (header, with optional per-line override); when unset,
the service falls back to the `InventoryStock` row with the largest
available quantity for the SKU. Documented in design.md; covered by a
unit test for both paths.

## Rollback Strategy

Revert the register.d fragment (schema/lifecycle additions deep-merge
additively and are safe to drop), delete the new listener/service files,
and revert the `QuoteOrderInvoiceGuard` diff. No data migration is
introduced — `StockMove` rows already created remain valid, immutable
ledger entries; nothing needs to be backed out of the ledger itself.

## Open Questions

None — see Out of Scope for deliberately deferred items.
