# Tasks — Inventory Valuation (FIFO + moving-average)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `inventory-valuation-fifo-avg` spec — they are recorded now so that
> the spec-review gate, dependency planning, and tier-cascade impact are
> visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Verify `inventory-stock-movement-ledger` change is merged
  and that `StockMovement` schema exposes `movementType`, `quantity`,
  `unitCost`, `warehouse`, `itemId`, and `date` fields required by the
  valuation services (REQ-INV-003, REQ-INV-004) — verified: `StockMove`
  schema lives in `lib/Settings/register.d/inventory-stock-movement-ledger.json`
  (development HEAD `73af7f36`), exposing `movementType`, `quantity`,
  `unitCost`, `itemId`, `sourceLocationId`, `destinationLocationId`,
  `postedAt`, `lifecycleState`. Spec vocabulary ("StockMovement",
  "warehouse", "date") maps to the shillinq vocabulary ("StockMove",
  source / destination location FKs, `postedAt`).
- [x] Task 2: Verify `add-shillinq-general-ledger` (T1) is merged and
  that `JournalEntry` and `Account` registers are queryable in shillinq
  (REQ-INV-007) — verified: `GLTransaction` + `GLLine` + `Account` are
  declared in `lib/Settings/shillinq_register.json` (REQ-GL-001 /
  REQ-GL-003). The spec uses "JournalEntry" generically; the COGS
  posting in Task 9 materialises a balanced 2-line `GLTransaction`
  (debit COGS line + credit Inventory line) matching shillinq's GL.
- [x] Task 3: Declare `InventoryValuation` schema in
  `lib/Settings/shillinq_register.json` with fields `quantity`,
  `unitCost`, `totalValue`, `valuationMethod` (enum: `FIFO`, `average`),
  `date`, `warehouse`, `status` (enum: `active`, `adjusted`, `obsolete`),
  Schema.org type `schema:Product`, relations to `Product` and
  `CostCenter` (REQ-INV-001, REQ-INV-002) — declared in the new
  `lib/Settings/register.d/inventory-valuation-fifo-avg.json` fragment
  (ADR-037 modular fragment, merged into the monolith by
  `SettingsService::loadRegisterConfigData()`). Schema declares the
  ADR-000 minimum field set plus `productId` / `costCenterId` FKs
  (relations declared in Task 13), `lastStockMoveUuid` for idempotent
  retry, `pendingCogs` for the missing-GL-config flag.
- [x] Task 4: Add `x-openregister-lifecycle` block to `InventoryValuation`
  declaring `active ↔ adjusted`, `active → obsolete`, `adjusted →
  obsolete` transitions; reference `InventoryValuationMethodGuard::checkZeroStock()`
  as the `requires` guard on `obsolete` transitions (REQ-INV-009) —
  added: transitions `adjust`, `confirmAdjustment`,
  `obsoleteFromActive`, `obsoleteFromAdjusted`. Both obsolete
  transitions reference `InventoryValuationMethodGuard::checkZeroStock`
  as `requires` per ADR-031 thin-PHP-guard pattern.
- [x] Task 5: Add `x-openregister-lifecycle` `methodChange` transition
  on `InventoryValuation` with `requires: InventoryValuationMethodGuard::checkZeroStock()`
  precondition blocking method switch when `quantity > 0` (REQ-INV-006) —
  added a self-loop `active → active` `methodChange` transition guarded
  by `InventoryValuationMethodGuard::checkZeroStock` for the
  operator-explicit transition path AND an `onUpdate`
  `methodChangeRequiresZeroStock` validation that fires the same
  guard on any direct `valuationMethod` patch (defence-in-depth so
  generic OR CRUD edits cannot bypass the guard).
- [x] Task 6: Author
  `lib/Lifecycle/InventoryValuationMethodGuard.php` — single-method
  class (`checkZeroStock(InventoryValuation $v): bool`), ≤15 LOC,
  `@spec` tag pointing to `tasks.md#task-6` (ADR-003, ADR-031) —
  authored with `LoggerInterface` DI, fail-closed on exceptions,
  `@spec` tag pointing at `task-6`. Companion unit test
  `tests/Unit/Lifecycle/InventoryValuationMethodGuardTest.php`
  covers zero / non-zero / missing / fractional quantity branches.
- [x] Task 7: Author `lib/Service/FifoValuationService.php` — listens to
  `StockMovement.inbound` event (creates cost lot reference) and
  `StockMovement.outbound` event (traverses open inbound lots
  chronologically, deducts quantity, computes weighted COGS, updates
  `InventoryValuation` snapshot); idempotent on retry via `StockMovement.uuid`
  deduplication; `@spec` tag pointing to `tasks.md#task-7` (REQ-INV-003) —
  authored. Adapts shillinq vocabulary (`StockMove`, `movementType`
  receipt/issue, `sourceLocationId`/`destinationLocationId`). Cost lots
  live in the StockMove ledger itself (design.md D2); the service
  derives open lots by querying posted receipts and subtracting
  historical outbound consumption oldest-first. Idempotency keyed on
  `lastStockMoveUuid` on the snapshot. PHPUnit test
  `FifoValuationServiceTest` covers the two-lot split scenario
  (30+5 of 35 = EUR 360,00 COGS, residual qty 15 @ EUR 12,00 / EUR 180,00),
  full-lot exhaustion, and idempotent retry. All 3 tests pass.
- [x] Task 8: Author `lib/Service/MovingAverageValuationService.php` —
  listens to `StockMovement.inbound` event for `average` items;
  recalculates running weighted average (`new_avg = (cur_qty × cur_cost
  + rcv_qty × rcv_cost) / (cur_qty + rcv_qty)`); rounds `unitCost` to 4
  decimal places, `totalValue` to 2; updates `InventoryValuation`
  snapshot; `@spec` tag pointing to `tasks.md#task-8` (REQ-INV-004) —
  authored. Handles both receipt (recalculate running weighted average)
  and issue (COGS at current average; cost retained). Idempotency
  keyed on `lastStockMoveUuid` like the FIFO service. Money discipline:
  integer-cent arithmetic for `totalValue` / COGS, `unitCost` rounded
  4 dp. PHPUnit `MovingAverageValuationServiceTest`: first receipt /
  REQ-INV-004 main scenario (100@3.50 + 200@4.00 = 300@3.8333) /
  outbound COGS at current average (50@3.8333 = 19167 cents = EUR
  191,67). All 3 pass.
- [x] Task 9: Author `lib/Service/CogsPosterService.php` — posts one
  balanced `JournalEntry` (debit COGS `7000`, credit Inventory `3000`,
  configurable per administration) per outbound `StockMovement`; sets
  `InventoryValuation.status = adjusted` and logs WARNING if GL accounts
  are not configured; reference `StockMovement.uuid` in
  `JournalEntry.reference`; `@spec` tag pointing to `tasks.md#task-9`
  (REQ-INV-007) — authored. Materialises a balanced 2-line
  `GLTransaction` (header + GLLine debit COGS + GLLine credit
  Inventory; shillinq GL vocabulary). Account numbers configurable
  via app config `cogs_account` / `inventory_account`. Fail-soft on
  missing config (logs WARNING, sets `status=adjusted` +
  `pendingCogs=true` on snapshot per REQ-INV-007 — never silently
  skips). `sourceReference: StockMove.id` carries the back-link.
  PHPUnit `CogsPosterServiceTest`:
  - testBalancedTransactionWhenAccountsConfigured (5 × EUR 89,00 =
    EUR 445,00 debit/credit pair matching REQ-INV-007 scenario)
  - testMissingAccountsAdjustsValuationAndWarns (status=adjusted +
    pendingCogs=true + WARNING logged + no GL rows posted)
  - testZeroCogsIsNoop. All 3 pass.
- [x] Task 10: Wire `FifoValuationService`, `MovingAverageValuationService`,
  and `CogsPosterService` into the Nextcloud event dispatcher via
  constructor DI (`private readonly`); register listeners in
  `lib/AppInfo/Application.php` (ADR-003) — added
  `StockMoveTransitionedListener` (private readonly constructor DI of
  all three services + ContainerInterface + IAppConfig + LoggerInterface).
  Subscribes to OR's `ObjectTransitionedEvent`; filters to StockMove
  `to=posted` transitions, dispatches by valuationMethod and forwards
  COGS basis to `CogsPosterService` for issue moves. Fail-soft: any
  exception logged but never bubbles up to block the StockMove
  transition itself (REQ-INV-007 + design.md D4 separation). Registered
  in `Application::register()` alongside the existing listeners.
- [x] Task 11: Ship `lib/Settings/seeds/inventory-valuation-examples.json`
  — JSON array of 5 `InventoryValuation` example records (GT-10-2026
  FIFO Magazijn Noord, KP-A4-500 average Centraal Depot, HP-200-B FIFO
  Magazijn Zuid, SM-5L-PRO average Centraal Depot, VHG-S-M FIFO
  Magazijn Noord) with SPDX header and `_meta` block
  (`source: "seed-example"`) (design.md §Seed Data) — shipped. SPDX +
  copyright in `_meta`, exact values from design.md §Seed Data table.
- [x] Task 12: Extend the repair step under `lib/Migration/` to import
  `inventory-valuation-examples.json` idempotently on first install
  (operator edits persist across re-runs; `StockMovement.uuid`-keyed
  deduplication ensures no duplicate records) (REQ-INV-001) — added
  `SettingsService::seedInventoryValuationExamples($administrationId)`
  using the same OR ObjectService fluent API as
  `seedInventoryBarcodes`. Dedupe key is
  `(productId, warehouse, status=active, administrationId)` per
  REQ-INV-005 (snapshots are uuid-less; the uniqueness key prevents
  duplicate active records). Wired into
  `InitializeSettings::seedInventoryValuationExamples()` which runs
  after the existing barcode demo step on every install/upgrade,
  skipping cleanly when `administration_id` is not configured (C2).
- [x] Task 13: Add `x-openregister-relations` on `InventoryValuation`
  linking `Product` (many-to-one) and `CostCenter` (many-to-one) per
  ADR-000 entity relations (REQ-INV-002) — `product`
  (productId -> Product.sku, many-to-one) and `costCenter`
  (costCenterId -> CostCenter.id, many-to-one) declared in the
  schema block.
- [x] Task 14: Add uniqueness constraint on `InventoryValuation`
  (`productId` + `warehouse`, status = `active`) — use OR uniqueness
  validator if supported, otherwise a lifecycle guard on
  `InventoryValuation.create` (REQ-INV-005) — OR's
  `x-openregister-unique` is a flat-tuple form (no `where` clause), so
  enforced via a lifecycle guard:
  `InventoryValuationMethodGuard::checkUniqueActiveSnapshot()` invoked
  from `validations.onCreate.uniqueActiveSnapshot` AND from
  `validations.onUpdate.uniqueActiveSnapshotOnTransitionToActive` (so
  re-activating an adjusted snapshot also re-checks uniqueness). Allows
  own-row self-update (by id). PHPUnit tests cover permit / block /
  self-match. All 7 guard tests pass.
- [x] Task 15: Add Inventory Valuation navigation + pages to
  `src/manifest.json` (menu entry `Inventory > Valuation`, `type: index`
  page binding to `InventoryValuation` register with columns `warehouse`,
  `quantity`, `unitCost`, `totalValue`, `valuationMethod`, `status`;
  `type: detail` page for individual records); `node
  tests/validate-manifest.js` exits 0 (REQ-INV-008) — added child nav
  item `InventoryValuation` under `Inventory` (order 70, icon
  ScaleBalance, EN+NL label). Pages `InventoryValuation` (`type: index`)
  and `InventoryValuationDetail` (`type: detail`) bound to
  register/schema. Index columns include `productId`, `warehouse`,
  `quantity`, `unitCost`, `totalValue`, `valuationMethod`, `status`.
  `node tests/validate-manifest.js` exits 0 (structural + consistency
  PASS, 144 pages).

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper
persona peer review (`/test-persona-janwillem` for Dutch MKB)
confirms the FIFO and moving-average algorithms produce correct COGS
amounts on a hand-calculated example. Architecture reviewer confirms
ADR-022 + ADR-031 compliance: no custom entity beyond ADR-000; FIFO
service is imperative with documented ADR-031 rationale; `CogsPosterService`
ADR-031 exception is documented in `design.md` under D4. No source code
changes outside `openspec/changes/inventory-valuation-fifo-avg/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation
cycle (`opsx-apply`) is responsible for:

- PHPUnit unit tests for `FifoValuationService`: FIFO lot traversal
  correctness (two-lot split, full-lot exhaustion, idempotency on
  retry).
- PHPUnit unit tests for `MovingAverageValuationService`: weighted
  average recalculation (first receipt, subsequent receipt,
  outbound COGS amount).
- PHPUnit unit tests for `CogsPosterService`: balanced JournalEntry
  posted, WARNING + `adjusted` status when GL accounts are not
  configured.
- PHPUnit unit tests for `InventoryValuationMethodGuard`: zero-stock
  pass, non-zero stock block.
- Integration test for the repair step: seed loads 5 records on first
  run, re-run does not duplicate records.
- Playwright MCP browser tests for Inventory Valuation index and detail
  pages.
- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation
cycle authors `docs/user-guide/inventory/valuation.md` per ADR-030
journeydoc convention, covering: how to select FIFO vs average per item,
how COGS is posted to the GL, and how to read the valuation report for
balance sheet purposes. A screenshot of the Inventory Valuation index
page is committed to `docs/images/`.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings
for: `Inventory Valuation`, `Valuation Method`, `FIFO`, `Moving Average`,
`Unit Cost`, `Total Value`, `Warehouse`, `Cost of Goods Sold`,
`Active`, `Adjusted`, `Obsolete`, `Non-zero stock — cannot change
valuation method`.
