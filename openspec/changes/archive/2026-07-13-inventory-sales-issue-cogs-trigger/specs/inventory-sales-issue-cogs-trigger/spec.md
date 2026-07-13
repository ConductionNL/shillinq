# inventory-sales-issue-cogs-trigger Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- inventory-sales-issue-cogs-trigger (this change)

## Purpose

Wires the missing outbound (sale/dispatch) trigger into shillinq's
inventory valuation + COGS pipeline. `FifoValuationService`,
`MovingAverageValuationService`, and `CogsPosterService` (per
`inventory-valuation-fifo-avg` / `inventory-cogs-posting`) already
correctly consume `StockMove` records with `movementType: issue`, but
nothing in the sales funnel (`bookkeeping-quote-order-invoice`'s
`SalesOrder` → `Delivery` → `Invoice` model) ever produced one. This
capability closes that gap: a confirmed `Delivery` emits one `issue`
`StockMove` per stock-tracked line, and a cancelled `Delivery` reverses
it through the existing `StockMove.cancel` transition. No valuation or
GL-posting logic is duplicated — this capability is purely the missing
trigger.

## ADDED Requirements

### Requirement: REQ-001: A confirmed Delivery MUST emit one issue StockMove per stock-tracked line

When a `Delivery` transitions `draft → confirmed`, the system MUST create
one posted `StockMove` (`movementType: issue`) for every line in
`Delivery.lines` whose product is stock-tracked (an `InventoryStock` row
exists for `(administrationId, sku=productReference)`). Each `StockMove`
MUST carry `itemId = line.productReference`, `quantity =
line.quantityShipped`, `sourceLocationId` resolved per REQ-003, `movementType:
"issue"`, `movementReason: "normal"`, `referenceDocumentUri` pointing back
to the `Delivery` and line index, and `lifecycleState: "posted"`. Lines
whose product is not stock-tracked (no matching `InventoryStock` row) MUST
be skipped without error.

#### Scenario: Confirming a delivery with a stock-tracked line issues stock

- **GIVEN** a `Delivery` in `draft` with one line
  (`productReference: "sku-widget-a"`, `quantityShipped: 5`) and an
  `InventoryStock` row for `(administrationId, "sku-widget-a")` at the
  resolved warehouse with `quantity: 20`
- **WHEN** the `Delivery` transitions to `confirmed`
- **THEN** exactly one `StockMove` MUST be created with `movementType:
  "issue"`, `itemId: "sku-widget-a"`, `quantity: 5`,
  `lifecycleState: "posted"`.

#### Scenario: Confirming a delivery with a non-stock-tracked (service) line issues nothing for that line

- **GIVEN** a `Delivery` line whose `productReference` has no matching
  `InventoryStock` row in any location for this administration
- **WHEN** the `Delivery` is confirmed
- **THEN** no `StockMove` MUST be created for that line, and the
  `Delivery` confirmation MUST still succeed.

### Requirement: REQ-002: A sale dispatch MUST feed the existing valuation and COGS pipeline unchanged

The `StockMove` created per REQ-001 MUST be a normal, unmodified `StockMove`
row — it MUST trigger the existing `StockMoveTransitionedListener` →
`FifoValuationService` / `MovingAverageValuationService` →
`CogsPosterService` pipeline exactly as an issue move from any other
source would. This capability MUST NOT compute valuation or post GL
entries directly.

#### Scenario: A dispatch-issued StockMove posts COGS through the existing pipeline

- **GIVEN** an active `InventoryValuation` snapshot with a computable
  `unitCost` and an active `InventoryGLConfig` for the administration
- **WHEN** a `Delivery` confirmation issues a `StockMove` per REQ-001
- **THEN** `CogsPosterService::postCogs()` MUST be invoked by the existing
  `StockMoveTransitionedListener` dispatch (same code path as a
  cycle-count-driven issue move), and a balanced `GLTransaction` MUST be
  posted.

### Requirement: REQ-003: Warehouse resolution MUST use an explicit field with a documented fallback

`Delivery` MUST carry an optional `sourceLocationId` field (header level).
The `StockMove.sourceLocationId` for a given line MUST resolve, in order:
(1) the line's own `sourceLocationId` if present in the `lines[]` entry,
(2) `Delivery.sourceLocationId`, (3) the `InventoryStock` row with the
largest available quantity (`quantity - reservedQuantity`) for
`(administrationId, sku)` across all locations.

#### Scenario: Explicit Delivery-level warehouse is used when set

- **GIVEN** a `Delivery` with `sourceLocationId: "loc-w01-z01-b100"` and a
  line with no per-line override
- **WHEN** the delivery is confirmed
- **THEN** the resulting `StockMove.sourceLocationId` MUST equal
  `"loc-w01-z01-b100"`.

#### Scenario: Fallback resolves to the location with the most available stock

- **GIVEN** a `Delivery` with no `sourceLocationId` set anywhere, and two
  `InventoryStock` rows for the line's SKU with available quantities 3 and
  17 respectively
- **WHEN** the delivery is confirmed
- **THEN** the resulting `StockMove.sourceLocationId` MUST resolve to the
  location with available quantity 17.

### Requirement: REQ-004: Re-confirming or re-saving a Delivery MUST NOT double-issue stock

The system MUST NOT create a second `StockMove` for a `Delivery` line that
has already been issued. Before creating a `StockMove` for a line, the
system MUST check for an existing, non-cancelled `StockMove` whose
`referenceDocumentUri` matches this delivery and line index.

#### Scenario: Re-processing an already-confirmed delivery is a no-op

- **GIVEN** a `Delivery` line that already produced a posted `StockMove`
  (`referenceDocumentUri` referencing this delivery + line)
- **WHEN** the dispatch listener runs again for the same `Delivery` (e.g.
  a duplicate event delivery)
- **THEN** no second `StockMove` MUST be created for that line.

### Requirement: REQ-005: Insufficient stock MUST block delivery confirmation unless negative stock is explicitly allowed

Before a `Delivery` may transition `draft → confirmed`, the system MUST
verify, for every stock-tracked line, that available quantity
(`InventoryStock.quantity - InventoryStock.reservedQuantity`) at the
resolved warehouse is `>= line.quantityShipped`. If any line fails this
check AND `InventoryGLConfig.allowNegativeStockOnDispatch` is not `true`
for the administration, the `confirm` transition MUST be denied. If
`allowNegativeStockOnDispatch` is `true`, the transition MUST proceed and
the resulting negative on-hand condition MUST be logged as a structured
warning.

#### Scenario: Confirmation is blocked by default when stock is insufficient

- **GIVEN** an `InventoryGLConfig` with `allowNegativeStockOnDispatch:
  false` (or absent) and a `Delivery` line requesting `quantityShipped: 10`
  against `InventoryStock.quantity: 4`, `reservedQuantity: 0`
- **WHEN** the operator attempts to confirm the `Delivery`
- **THEN** the `confirm` transition MUST be denied.

#### Scenario: Confirmation proceeds when negative stock is explicitly allowed

- **GIVEN** an `InventoryGLConfig` with `allowNegativeStockOnDispatch: true`
  and the same insufficient-stock line as above
- **WHEN** the operator confirms the `Delivery`
- **THEN** the `confirm` transition MUST succeed, the `StockMove` MUST be
  created per REQ-001, and a structured warning MUST be logged.

### Requirement: REQ-006: Cancelling a Delivery before shipment MUST reverse any stock it issued

`Delivery` MUST gain a `cancel` transition (from `draft` or `confirmed` to
a new `cancelled` state). When a `Delivery` that already issued
`StockMove`s (per REQ-001) is cancelled, the system MUST transition each
of those `StockMove`s through the existing `StockMove.cancel` transition,
reusing its existing offsetting-move + GL-reversal materialisation
unchanged.

#### Scenario: Cancelling a confirmed delivery reverses its stock issue

- **GIVEN** a `Delivery` in `confirmed` state that issued one posted
  `StockMove` (`movementType: "issue"`)
- **WHEN** the `Delivery` is cancelled
- **THEN** the original `StockMove` MUST be transitioned to `cancelled`,
  and an offsetting `StockMove` MUST exist referencing it via
  `offsetOfMoveId`.

## Non-Functional Requirements

- **Performance:** Dispatch processing for a `Delivery` with up to 50
  lines MUST complete within the existing per-request timeout budget used
  by `GoodsReceiptNoteService`'s equivalent GRN-accept fan-out (no new
  performance budget introduced).
- **Accessibility:** N/A — no new UI surface.
- **Internationalization:** Any new operator-facing guard denial message
  MUST be provided in English + Dutch (`messageNl`), per ADR-005.

## Acceptance Criteria

- [ ] A confirmed `Delivery` with a stock-tracked line produces exactly
  one `issue` `StockMove`.
- [ ] That `StockMove` drives the existing FIFO/moving-average valuation
  and posts a balanced COGS `GLTransaction` via the existing
  `CogsPosterService` — unmodified.
- [ ] Re-confirming the same `Delivery` does not double-issue.
- [ ] Insufficient stock blocks confirmation by default; the
  `allowNegativeStockOnDispatch` administration toggle permits it with a
  logged warning.
- [ ] Cancelling a `Delivery` before shipment reverses the `StockMove`(s)
  it issued via the existing `StockMove.cancel` mechanics.

## Notes

Restocking triggered by a `CreditNote` (post-invoice return) is explicitly
out of scope — see the change proposal's Out of Scope section. The
`CreditNote` schema carries no line-level quantity or delivery linkage
today; adding that is a larger, separate increment.
