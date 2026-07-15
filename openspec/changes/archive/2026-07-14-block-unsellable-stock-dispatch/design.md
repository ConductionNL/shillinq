# Design: block-unsellable-stock-dispatch

## Architecture Overview

The sales-dispatch trigger (PR #404) is a fail-soft listener on
OpenRegister's `ObjectTransitionedEvent` for `Delivery`:

```
Delivery.draft → confirmed   (ObjectTransitionedEvent)
  → DeliveryDispatchListener::handle()
    → SalesDispatchStockIssueService::issueForDelivery($delivery)
      → per stock-tracked line: create draft `issue` StockMove
        → TransitionEngine post → FIFO/AVG valuation → CogsPosterService
          → balanced GLTransaction (debit COGS / credit Inventory)
```

`issueForDelivery()` resolves the line's `InventoryStock` rows (its
stock-tracked signal), a source warehouse, then creates and posts one `issue`
`StockMove`. **Verified at HEAD:** nowhere in that path is a lot's
`lotStatus` or `expiryDate` read. Quarantined / expired / exhausted stock is
issued and booked as COGS exactly like good stock.

## Evidence trail (the real enum + expiry field)

Established from the register.d source, not the task summary:

1. **`InventoryLot.lotStatus`** (`lib/Settings/register.d/inventory-lot-batch-expiry.json`):
   enum `["active", "quarantined", "expired", "exhausted"]`, default `active`.
   Only `active` is pickable ("active: available for picking; quarantined:
   held for quality inspection (not pickable); expired: past legal expiry
   (terminal); exhausted: quantity 0 (terminal)"). The task summary's
   `damaged`/`blocked` values DO NOT EXIST in this enum — the spec even has a
   scenario asserting `lotStatus: "damaged"` is rejected as an enum violation
   (REQ-LOT-002). Enforcement is written against the real four-value enum.
2. **Expiry field**: `InventoryLot.expiryDate` (`format: date`, nullable) —
   "Legal expiry date per REQ-LOT-002 … drives the expired-state lifecycle
   transition." First-class: a lot with `lotStatus: active` but
   `today > expiryDate` is physically unsellable regardless of status.
3. **`StockMove` has no `lotId`** (`inventory-stock-movement-ledger.json`):
   the `InventoryLot.stockMovements` reverse relation is annotated "Declared
   from `StockMove.lotId` … (additive patch WHEN that chain spec lands;
   harmless until then)." So the dispatch cannot record which lot it drew
   from — enforcement is at the aggregate, not per-committed-lot (see below).

## The rule

`LotSellabilityGuard::evaluate(array $lots, float $requiredQuantity, string $today)`:

- A lot is **sellable** iff `lotStatus === 'active'` AND (`expiryDate` is
  null/empty OR `expiryDate >= today`). Date strings in `Y-m-d` compare
  lexicographically; expiry is exclusive (`today > expiryDate` ⇒ unsellable, a
  lot expiring exactly today is still sellable).
- Sellable lots are FEFO-ordered via the existing `FefoSort` (earliest expiry
  first, null-expiry last) so a future `StockMove.lotId`-stamping consumer
  draws FEFO.
- The line is **satisfiable** iff the summed available `quantity` of the
  sellable lots ≥ `requiredQuantity` (a small epsilon absorbs integer-cent
  float representation). Otherwise the verdict is blocked, with a positive
  `shortfall` and every unsellable lot reported as `offendingLots` carrying an
  EN + NL reason (`quarantined` / `expired` / `exhausted` / `past expiry date
  <date>`).

## Enforcement point

`SalesDispatchStockIssueService::issueForDelivery()`, per line, AFTER the
existing stock-tracked check and BEFORE warehouse resolution / move creation:

1. Read `productId` off the line's `InventoryStock` rows; fetch `InventoryLot`
   rows for the product (by `productId` and by the transitional `productSku`
   alias, merged/de-duplicated by id).
2. If ZERO lots → the product is not lot-controlled → proceed unchanged (no
   regression for non-lot-tracked SKUs).
3. If ≥1 lot → run the guard. If not sellable → **fail closed**: increment
   `blocked`, log an error naming the offending lot(s) + reason, append to
   `blockedLines`, and `continue` — no `StockMove` is created, so no COGS is
   posted for unsellable stock. Prefer-sellable: quarantined/expired siblings
   do not block a line whose sellable lots cover it.

Fail-closed here means the harmful side effect (issuing stock + posting COGS)
never happens. The `Delivery.confirm` transition itself already committed
(the listener is post-transition and fail-soft, mirroring
`StockMoveTransitionedListener`); the enforcement is placed at the exact code
that would otherwise create the COGS-posting `StockMove`.

## Declarative-vs-imperative decision (ADR-031)

A declarative `x-openregister-lifecycle` edit is NOT viable here, and the
schema-edit "fix" the routing audit proposed would have ZERO runtime effect
while looking compliant. Two independent, cited reasons:

1. **The dispatch never transitions the stock/lot object — it creates a
   StockMove.** OR's declarative lifecycle guards
   (`x-openregister-lifecycle.validations` / transition `guard`s) fire only
   when the guarded object undergoes a transition. `issueForDelivery()`
   creates a NEW `StockMove`; it does not transition `InventoryStock` or
   `InventoryLot`. A guard declared on `InventoryStock.status` or
   `InventoryLot.lotStatus` would therefore never be invoked during dispatch —
   the missing transition is the crux (this IS the orphaned-capability defect
   class: implemented, spec-green, but nothing invokes it).
2. **Even a StockMove-side declarative validation cannot express it.** The
   only object that IS created/transitioned in the path is the `StockMove`,
   but `StockMove` carries no `lotId` field today (see Evidence #3), so no
   declarative validation on `StockMove` can reference the lot it draws from.
   And the rule itself is cross-schema aggregation — sum sellable
   `InventoryLot.quantity` for a product vs the line quantity, FEFO ordering,
   today-vs-`expiryDate` — which OR's single-object validation dialect
   (field-level conditions on the object under save) cannot express.

Hence the ADR-031 "PHP guards remain a legitimate seam" exception: a pure
imperative decision class (`LotSellabilityGuard`) invoked from the dispatch
service — the same shape as the existing `FefoSort` (ADR-031 FEFO seam) and
`LotTrackingReceiptGuard` (ADR-031 lot-required-on-receipt seam) already in
this app. The guard is invoked unconditionally on every lot-controlled line,
so it has real, provable runtime effect (covered by
`LotSellabilityGuardTest` + the service tests).

## Seed Data

No new schemas or seed records are introduced. The `InventoryLot` register
(`inventory-lot-batch-expiry`) and its `lotStatus`/`expiryDate` fields already
exist and ship via `ConfigurationService::importFromApp`. Test fixtures seed
in-memory `InventoryLot`/`InventoryStock` rows only. No production seed data
change is required for the enforcement to take effect — it reads whatever lots
the administration already has.

## Trade-offs

- **No admin override.** An override to ship non-active or expired stock was
  considered and rejected: the task explicitly warns that any override must
  default strict/OFF and must never silently allow shipping expired goods. The
  simplest way to honour that with zero foot-gun is to omit the override
  entirely — strict, always-fail-closed. If a legitimate "ship quarantined
  under supervisor sign-off" workflow is ever needed it should be an explicit,
  audited transition on the lot (release from quarantine), not a dispatch-time
  bypass.
- **Aggregate vs per-lot enforcement.** Because `StockMove` has no `lotId`,
  the guard enforces "a sellable lot MUST exist and cover the line" rather
  than committing a specific lot and decrementing it. This is the correct
  ceiling for today's data model; per-lot commitment lands with the
  `StockMove.lotId` chain spec.

## Nextcloud Integration

- Services: `LotSellabilityGuard` is auto-wired by Nextcloud's container (its
  only constructor dependency, `FefoSort`, is itself dependency-free);
  `SalesDispatchStockIssueService` is auto-wired (no explicit registration in
  `Application.php`), so adding the constructor param needs no DI change.
- Events/Hooks: consumed only — the existing `DeliveryDispatchListener` calls
  `issueForDelivery()`; unchanged.

## Security / Integrity Considerations

Fail-closed throughout (CWE-863 style): a line whose sellable lots cannot
cover it is never issued and never posts COGS. This closes a real
financial-integrity + product-safety bypass — every confirmed Delivery since
PR #404 could dispatch quarantined/expired/exhausted stock and book it as COGS.
No historical backfill/re-evaluation of already-posted moves is in scope
(a separate, larger undertaking).

## File Structure

```
lib/
  Lifecycle/
    LotSellabilityGuard.php            (new — pure sellability decision)
  Service/
    SalesDispatchStockIssueService.php (modified — enforcement + lot lookup)
tests/
  Unit/Lifecycle/
    LotSellabilityGuardTest.php        (new — 7 tests)
  Unit/Service/
    SalesDispatchStockIssueServiceTest.php (modified — +InventoryLot fake, +5 tests)
  Unit/Listener/
    DeliveryDispatchListenerTest.php   (modified — guard wired into real service)
```
