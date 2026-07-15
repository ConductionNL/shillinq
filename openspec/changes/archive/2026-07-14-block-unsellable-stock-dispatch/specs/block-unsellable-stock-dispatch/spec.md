# block-unsellable-stock-dispatch Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- block-unsellable-stock-dispatch (2026-07-14, archived)

## Purpose

Closes a financial-integrity and product-safety defect in the sales-dispatch
trigger (`SalesDispatchStockIssueService`, PR #404): the path that turns a
confirmed `Delivery` line into a posted `issue` `StockMove` (and thence into
FIFO/AVG valuation and a COGS `GLTransaction`) never consulted lot status, so
stock in a quarantined, expired (by `lotStatus` or by an `active` lot whose
`expiryDate` has passed), or exhausted `InventoryLot` could be dispatched and
booked as cost of goods sold. Enforcement is added at the dispatch code path
(an ADR-031 PHP-guard seam) because the dispatch creates a `StockMove` rather
than transitioning the lot/stock object — so a declarative lifecycle guard
would never fire.

## ADDED Requirements

### Requirement: REQ-BLK-001: A Delivery line MUST NOT be issued from unsellable stock

When `SalesDispatchStockIssueService::issueForDelivery()` processes a
stock-tracked, lot-controlled Delivery line (≥1 `InventoryLot` exists for the
line's product in the administration), it MUST issue the line only from
SELLABLE lots. A lot is sellable iff `lotStatus === 'active'` AND its
`expiryDate` is empty OR not before today (`today > expiryDate` ⇒ unsellable;
a lot expiring exactly today is sellable). When no combination of sellable
lots can cover the line quantity, the line MUST fail CLOSED: no `StockMove` is
created (and therefore no COGS is posted), and the failure MUST be logged with
the offending lot(s) named and the reason (quarantined / expired / exhausted /
past expiry date) given. A line whose product has no `InventoryLot` rows is
not lot-controlled and dispatches unchanged.

#### Scenario: A quarantined lot is not dispatched
- **GIVEN** the only `InventoryLot` for a line's product has `lotStatus: quarantined`
- **WHEN** the confirmed Delivery is processed by `issueForDelivery()`
- **THEN** no `issue` `StockMove` is created for that line
- **AND** the result reports the line as blocked, naming the quarantined lot

#### Scenario: A lot marked expired is not dispatched
- **GIVEN** the only `InventoryLot` for a line's product has `lotStatus: expired`
- **WHEN** the confirmed Delivery is processed
- **THEN** no `issue` `StockMove` is created for that line, and the line is reported blocked with reason "expired"

#### Scenario: An active lot past its expiry date is not dispatched (expiry first-class)
- **GIVEN** the only `InventoryLot` for a line's product has `lotStatus: active` and an `expiryDate` before today
- **WHEN** the confirmed Delivery is processed
- **THEN** no `issue` `StockMove` is created for that line, and the reason names the past expiry date

#### Scenario: A line with no lots is unaffected
- **GIVEN** a line's product has no `InventoryLot` rows in the administration
- **WHEN** the confirmed Delivery is processed
- **THEN** the line is issued exactly as before this change (no lot constraint applied)

@e2e exclude unbuilt UI: dispatch runs from an OpenRegister lifecycle event with no dedicated shillinq UI surface to drive; covered by PHPUnit unit tests exercising the guard and the dispatch service directly.

### Requirement: REQ-BLK-002: Sellable stock MUST dispatch normally and be preferred over hard-failing

A clean, active, non-expired lot with sufficient quantity MUST still produce
exactly one posted `issue` `StockMove` for the line, preserving PR #404's
happy path (which feeds the balanced COGS `GLTransaction`). When a line's
product has both unsellable and sellable lots, the presence of a quarantined
or expired sibling MUST NOT hard-fail the line as long as the sellable lots
can cover its quantity — the line is issued from sellable stock. Sellable lots
MUST be considered in FEFO (earliest-expiry-first, null-expiry-last) order.

#### Scenario: A clean sellable lot dispatches a posted issue move
- **GIVEN** the only `InventoryLot` for a line's product has `lotStatus: active`, an `expiryDate` in the future, and quantity ≥ the line quantity
- **WHEN** the confirmed Delivery is processed
- **THEN** exactly one `issue` `StockMove` is created and driven to `posted`, feeding the existing valuation + COGS pipeline unchanged

#### Scenario: A sellable lot is preferred over a quarantined sibling
- **GIVEN** a line's product has a quarantined lot AND an active, non-expired lot whose quantity covers the line
- **WHEN** the confirmed Delivery is processed
- **THEN** the line is issued (one posted `issue` `StockMove`), not blocked

@e2e exclude unbuilt UI: same as REQ-BLK-001 — no dedicated UI surface; covered by the dispatch-service integration test (`DeliveryDispatchListenerTest`) that also asserts the balanced COGS `GLTransaction`.

## Non-Functional Requirements

- **Performance:** enforcement adds at most two bounded `ObjectService::findAll()`
  lookups per stock-tracked line (`administrationId` + `productId`/`productSku`
  equality filters); no unbounded scan.
- **Accessibility:** N/A — no UI surface.
- **Internationalization:** each block reason carries an English and a Dutch
  (`reason` / `reasonNl`) string; no new translated UI copy is introduced.

## Acceptance Criteria

- [ ] A quarantined lot, a lot marked `expired`, and an `active` lot past its
  `expiryDate` are each blocked (no `issue` `StockMove` created).
- [ ] A clean active non-expired lot with sufficient quantity issues exactly
  one posted `issue` `StockMove`.
- [ ] A quarantined/expired sibling does not block a line whose sellable lots
  cover it.
- [ ] A line with no `InventoryLot` rows dispatches unchanged.
- [ ] The balanced-COGS integration path (`DeliveryDispatchListenerTest`,
  `CogsPosterServiceTest`) remains green.

## Notes

- The `active | quarantined | expired | exhausted` `lotStatus` enum lives on
  `InventoryLot` (REQ-LOT-006), not on `InventoryStock`; the summary's
  `damaged`/`blocked` values do not exist in the enum.
- Per-lot commitment (stamping `StockMove.lotId`) is out of scope until the
  `StockMove.lotId` chain spec lands; enforcement is at the aggregate
  (a sellable lot MUST exist and cover the line).
- No admin override is provided — dispatch of non-active/expired stock is
  always fail-closed.
