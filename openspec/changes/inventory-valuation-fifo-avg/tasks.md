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
  valuation services (REQ-INV-003, REQ-INV-004)
  _Note: dependency not yet merged into development; services implemented
  defensively against the declared field shape and will be inert until
  the upstream ledger is merged._
- [x] Task 2: Verify `add-shillinq-general-ledger` (T1) is merged and
  that `JournalEntry` and `Account` registers are queryable in shillinq
  (REQ-INV-007)
  _Note: JournalEntry schema found in shillinq_register.json (T1 merged)._
- [x] Task 3: Declare `InventoryValuation` schema in
  `lib/Settings/register.d/inventory-valuation-fifo-avg.json` with fields `quantity`,
  `unitCost`, `totalValue`, `valuationMethod` (enum: `FIFO`, `average`),
  `date`, `warehouse`, `status` (enum: `active`, `adjusted`, `obsolete`),
  Schema.org type `schema:Product`, relations to `Product` and
  `CostCenter` (REQ-INV-001, REQ-INV-002)
- [x] Task 4: Add `x-openregister-lifecycle` block to `InventoryValuation`
  declaring `active ↔ adjusted`, `active → obsolete`, `adjusted →
  obsolete` transitions; reference `InventoryValuationMethodGuard::checkZeroStock()`
  as the `requires` guard on `obsolete` transitions (REQ-INV-009)
- [x] Task 5: Add `x-openregister-lifecycle` `methodChange` transition
  on `InventoryValuation` with `requires: InventoryValuationMethodGuard::checkZeroStock()`
  precondition blocking method switch when `quantity > 0` (REQ-INV-006)
- [x] Task 6: Author
  `lib/Lifecycle/InventoryValuationMethodGuard.php` — single-method
  class (`checkZeroStock(InventoryValuation $v): bool`), ≤15 LOC,
  `@spec` tag pointing to `tasks.md#task-6` (ADR-003, ADR-031)
- [x] Task 7: Author `lib/Service/FifoValuationService.php` — listens to
  `StockMovement.inbound` event (creates cost lot reference) and
  `StockMovement.outbound` event (traverses open inbound lots
  chronologically, deducts quantity, computes weighted COGS, updates
  `InventoryValuation` snapshot); idempotent on retry via `StockMovement.uuid`
  deduplication; `@spec` tag pointing to `tasks.md#task-7` (REQ-INV-003)
- [x] Task 8: Author `lib/Service/MovingAverageValuationService.php` —
  listens to `StockMovement.inbound` event for `average` items;
  recalculates running weighted average (`new_avg = (cur_qty × cur_cost
  + rcv_qty × rcv_cost) / (cur_qty + rcv_qty)`); rounds `unitCost` to 4
  decimal places, `totalValue` to 2; updates `InventoryValuation`
  snapshot; `@spec` tag pointing to `tasks.md#task-8` (REQ-INV-004)
- [x] Task 9: Author `lib/Service/CogsPosterService.php` — posts one
  balanced `JournalEntry` (debit COGS `7000`, credit Inventory `3000`,
  configurable per administration) per outbound `StockMovement`; sets
  `InventoryValuation.status = adjusted` and logs WARNING if GL accounts
  are not configured; reference `StockMovement.uuid` in
  `JournalEntry.reference`; `@spec` tag pointing to `tasks.md#task-9`
  (REQ-INV-007)
- [x] Task 10: Wire `FifoValuationService`, `MovingAverageValuationService`,
  and `CogsPosterService` into the Nextcloud event dispatcher via
  constructor DI (`private readonly`); register listeners in
  `lib/AppInfo/Application.php` (ADR-003)
- [x] Task 11: Ship `lib/Settings/seeds/inventory-valuation-examples.json`
  — JSON array of 5 `InventoryValuation` example records (GT-10-2026
  FIFO Magazijn Noord, KP-A4-500 average Centraal Depot, HP-200-B FIFO
  Magazijn Zuid, SM-5L-PRO average Centraal Depot, VHG-S-M FIFO
  Magazijn Noord) with SPDX header and `_meta` block
  (`source: "seed-example"`) (design.md §Seed Data)
- [x] Task 12: Extend the repair step `lib/Repair/InitializeSettings.php` to import
  `inventory-valuation-examples.json` idempotently on first install
  (operator edits persist across re-runs; productId+warehouse-keyed
  deduplication ensures no duplicate records) (REQ-INV-001)
- [x] Task 13: Add `x-openregister-relations` on `InventoryValuation`
  linking `Product` (many-to-one) and `CostCenter` (many-to-one) per
  ADR-000 entity relations (REQ-INV-002)
- [x] Task 14: Add uniqueness constraint on `InventoryValuation`
  (`productId` + `warehouse`, status = `active`) via `x-openregister-uniqueness`
  in the register fragment (REQ-INV-005)
- [x] Task 15: Add Inventory Valuation navigation + pages to
  `src/manifest.json` (menu entry `Inventory > Valuation`, `type: index`
  page binding to `InventoryValuation` register with columns `warehouse`,
  `quantity`, `unitCost`, `totalValue`, `valuationMethod`, `status`;
  `type: detail` page for individual records); `node
  tests/validate-manifest.js` exits 0 (REQ-INV-008)

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
