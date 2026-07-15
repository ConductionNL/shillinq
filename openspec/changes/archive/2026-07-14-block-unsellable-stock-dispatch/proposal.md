---
kind: code
depends_on: []
---

# Proposal: block-unsellable-stock-dispatch

## Summary

`lib/Service/SalesDispatchStockIssueService.php` (merged in PR #404 —
`Delivery.confirm` → issue `StockMove` → FIFO/AVG valuation → COGS) turns a
confirmed Delivery line into a posted `issue` `StockMove` that feeds the
valuation + COGS-posting pipeline. Verified against HEAD: the service never
consults lot status — `grep -n 'status\|lotStatus' SalesDispatchStockIssueService.php`
returns ZERO hits. So stock held in a **quarantined**, **expired** (by status
or by an `active` lot whose `expiryDate` has passed) or **exhausted**
`InventoryLot` can be dispatched and sold, posting COGS as if it were good
stock. This is a correctness/financial-integrity defect: unsellable inventory
becomes a booked cost of goods sold.

**Correction to the routing audit's premise (do NOT repeat it):** an earlier
audit claimed this was a declarative `x-openregister-lifecycle` edit on
`InventoryStock.status`. That premise is false and verified false at HEAD:

- Nothing mirrors `Product.status` onto stock; `InventoryStock.status` has no
  `quarantined`/`damaged` values.
- The `quarantined`/`expired`/`exhausted` states live on the SIBLING
  `InventoryLot.lotStatus` (enum `active | quarantined | expired | exhausted`,
  `lib/Settings/register.d/inventory-lot-batch-expiry.json`), not on
  `InventoryStock`.
- The dispatch path CREATES a `StockMove`; it never TRANSITIONS the
  stock/lot object. A declarative lifecycle guard fires only on a transition
  of the guarded object, so a guard on `lotStatus`/`InventoryStock.status`
  would intercept a transition that never happens — zero runtime effect while
  looking compliant (the orphaned-capability defect class). See design.md
  §"Declarative-vs-imperative decision (ADR-031)" for the full citation.

The fix is therefore a REAL enforcement point in the dispatch code path.

## Motivation

An accounting app that dispatches quarantined/expired/exhausted stock and
posts it as COGS launders unsellable inventory into the general ledger and
onto customer shipments — a financial-integrity and product-safety failure
(expired goods physically shipped). The pipeline reported healthy on every
delivery because nothing ever checked.

## Affected Projects

- [x] Project: `shillinq` — 1 new class (`Lifecycle/LotSellabilityGuard.php`),
  1 modified service (`Service/SalesDispatchStockIssueService.php`), 2 modified
  test files, 1 new test file.

## Scope

### In Scope

- New `LotSellabilityGuard` (pure decision logic, ADR-031 PHP-guard seam):
  a lot is SELLABLE iff `lotStatus === 'active'` AND (`expiryDate` empty OR
  `expiryDate >= today`). Expiry is first-class — an `active` lot past its
  `expiryDate` is unsellable. Reports sellable lots FEFO-ordered (via the
  existing `FefoSort`) and unsellable lots with an EN + NL reason.
- Enforcement in `SalesDispatchStockIssueService::issueForDelivery()`: for a
  lot-controlled product (≥1 `InventoryLot` exists for the line's product in
  the administration), the line is issued only when the summed available
  quantity of sellable lots covers it. When it cannot, the line fails CLOSED —
  no `StockMove` is created, so no COGS is posted — and a clear error naming
  the offending lot(s) + reason is logged and returned in the result envelope.
- Prefer-sellable-over-hard-fail: quarantined/expired sibling lots do not
  block a line as long as sellable lots can satisfy it (REQ-BLK-002).
- Tests proving all four failing/clean paths (quarantined blocked, lot marked
  expired blocked, active-but-past-expiry blocked, clean sellable lot
  dispatches a posted `issue` move) plus the prefer-sellable and guard-level
  edge cases; the balanced-COGS happy path continues to pass
  (`DeliveryDispatchListenerTest`, `CogsPosterServiceTest`).

### Out of Scope

- Per-lot `StockMove` splitting / stamping `StockMove.lotId`: `StockMove` has
  no `lotId` field today (the `InventoryLot.stockMovements` reverse relation
  documents it as landing "when that chain spec lands"). Without a `lotId`
  column the dispatch cannot record WHICH lot it drew from, so this change
  enforces sellability at the aggregate (a sellable lot MUST exist and cover
  the line) rather than committing a specific lot. Filed as a follow-up when
  the `StockMove.lotId` chain spec lands.
- Any admin override to ship non-active/expired stock: deliberately omitted.
  A strict, always-fail-closed mode avoids the "silently ship expired goods"
  trap the task warns against. See design.md §"Trade-offs".
- Blocking the `Delivery.confirm` transition itself: the dispatch pipeline is
  a fail-soft post-transition listener; the enforcement point is the code that
  creates the COGS-posting `StockMove`, which is where the harm occurs.

## Approach

See design.md for the evidence trail (enum values, expiry semantics, the
missing `StockMove.lotId`, the missing transition) and the ADR-031 decision.

## New Dependencies

None.

## Impact

- `lib/Lifecycle/LotSellabilityGuard.php` — new pure guard (~1 public method).
- `lib/Service/SalesDispatchStockIssueService.php` — constructor gains a
  `LotSellabilityGuard` param; new `productIdFromStock()` +
  `inventoryLotRows()` helpers; per-line enforcement block; result envelope
  gains `blocked` + `blockedLines`.
- `tests/Unit/Lifecycle/LotSellabilityGuardTest.php` — new, 7 tests.
- `tests/Unit/Service/SalesDispatchStockIssueServiceTest.php` — fake gains an
  `InventoryLot` store + guard wiring; 5 new tests.
- `tests/Unit/Listener/DeliveryDispatchListenerTest.php` — the real-service
  constructor call gains the guard; no lots seeded so the balanced-COGS
  happy path is unchanged.

## Cross-Project Dependencies

None — OpenRegister (`ObjectService`) is consumed read-only per ADR-022.

## Risks

### Risk 1: Linking a Delivery line to its lots relies on productId / productSku
**Severity:** Low — **Mitigation:** the guard is invoked only when
`inventoryLotRows()` returns ≥1 lot; it matches lots by the canonical
`productId` (read off the line's `InventoryStock` rows) AND by the transitional
`productSku` alias, merged. If neither resolves a lot the product is treated as
not-lot-controlled and dispatch proceeds exactly as before — no regression for
non-lot-tracked SKUs.

### Risk 2: Up to two extra ObjectService lookups per stock-tracked line
**Severity:** Low — **Mitigation:** both lookups are bounded equality filters
(`administrationId` + `productId`/`productSku`), the same filtering shape the
service already uses for `InventoryStock`/`StockMove`; no unbounded scan.

## Rollback Strategy

Revert `SalesDispatchStockIssueService.php`, delete `LotSellabilityGuard.php`,
revert the test files. No schema, seed-data, or register.d change is made, so
no data migration is needed.

## Open Questions

None.
