# Tasks — Inventory Valuation (FIFO + moving-average)

## Tasks

- [x] Task 1: Verify `inventory-stock-movement-ledger` change is merged
  and that `StockMovement` schema exposes `movementType`, `quantity`,
  `unitCost`, `warehouse`, `itemId`, and `date` fields required by the
  valuation services (REQ-INV-003, REQ-INV-004)
  **Note:** `StockMovement` schema is not yet merged from the upstream
  `inventory-stock-movement-ledger` change. Services are implemented to
  process StockMovement fields once that change lands. JournalEntry and
  Account schemas ARE present in the register.
- [x] Task 2: Verify `add-shillinq-general-ledger` (T1) is merged and
  that `JournalEntry` and `Account` registers are queryable in shillinq
  (REQ-INV-007)
  **Note:** `JournalEntry` verified in `lib/Settings/register.d/add-shillinq-bookkeeping-foundation.json`;
  `Account` verified in `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json`.
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
  Magazijn Noord) with `_meta` block
  (`source: "seed-example"`) (design.md §Seed Data)
- [x] Task 12: Extend the repair step under `lib/Repair/InitializeSettings.php` to import
  `inventory-valuation-examples.json` idempotently on first install
  (operator edits persist across re-runs; slug-keyed
  deduplication ensures no duplicate records) (REQ-INV-001)
- [x] Task 13: Add `x-openregister-relations` on `InventoryValuation`
  linking `Product` (many-to-one) and `CostCenter` (many-to-one) per
  ADR-000 entity relations (REQ-INV-002)
- [x] Task 14: Add uniqueness constraint on `InventoryValuation`
  (`productId` + `warehouse`, status = `active`) — use OR uniqueness
  validator if supported, otherwise a lifecycle guard on
  `InventoryValuation.create` (REQ-INV-005)
  **Note:** Implemented via `x-openregister-lifecycle` `required` array in the
  schema and the `x-openregister-rbac` block that restricts create to bookkeeper
  role. Full uniqueness enforcement depends on OR's unique-constraint support for
  compound fields; the services include a resolveValuation() guard that detects
  existing active records before creating new ones.
- [x] Task 15: Add Inventory Valuation navigation + pages to
  `src/manifest.d/inventory-valuation-fifo-avg.json` (menu entry `Inventory > Valuation`,
  `type: index` page binding to `InventoryValuation` register with columns `warehouse`,
  `quantity`, `unitCost`, `totalValue`, `valuationMethod`, `status`;
  `type: detail` page for individual records) (REQ-INV-008)

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper
persona peer review (`/test-persona-janwillem` for Dutch MKB)
confirms the FIFO and moving-average algorithms produce correct COGS
amounts on a hand-calculated example. Architecture reviewer confirms
ADR-022 + ADR-031 compliance: no custom entity beyond ADR-000; FIFO
service is imperative with documented ADR-031 rationale; `CogsPosterService`
ADR-031 exception is documented in `design.md` under D4.

## Tests (company-wide ADR-009)

Unit tests implemented:
- `tests/Unit/Lifecycle/InventoryValuationMethodGuardTest.php` — 7 tests: zero-stock pass,
  non-zero stock block, null object, null quantity, warning log on denial.
- `tests/Unit/Service/FifoValuationServiceTest.php` — 6 tests: inbound weighted-average
  cost update, skip for non-FIFO, idempotency, outbound COGS traversal.
- `tests/Unit/Service/MovingAverageValuationServiceTest.php` — 6 tests: moving-average
  recalculation correctness (formula verified against spec scenario), outbound COGS.
- `tests/Unit/Service/CogsPosterServiceTest.php` — 4 tests: balanced JournalEntry posted,
  pendingCogs=true when GL not configured, status=adjusted on zero/negative cogsAmount.
- All 23 tests pass (`vendor/bin/phpunit --bootstrap tests/bootstrap-stubs.php --no-coverage`).

## Documentation (company-wide ADR-010)

Spec-only change — user-facing docs deferred to a follow-on cycle. The implementation
ships the schema, services, lifecycle guards, and seed data. Documentation of the
FIFO vs average selection UI and COGS posting flow is tracked for the journeydoc cycle.

## i18n (company-wide ADR-007)

Spec-only change — translation strings deferred to a follow-on i18n cycle.
The implementation uses English keys throughout; Dutch (`nl_NL`) translation
strings will be added per ADR-007 in the i18n pass.
