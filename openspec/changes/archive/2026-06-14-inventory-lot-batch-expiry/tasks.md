# Tasks — Inventory Lot/Batch + Expiry with FEFO

> Implementation cycle (2026-06-07): the spec-only task list authored at
> proposal time has been executed end-to-end. Schemas, declarative
> lifecycles, manifest navigation, ADR-031 exception guards, demo seed,
> daily expiry cron and l10n strings all landed on this branch. The
> single deviation from the proposal-time list is task 12: the
> requiresLotTracking precondition is implemented as a single-method PHP
> guard (`LotTrackingReceiptGuard::validate`) rather than a purely
> declarative GoodsReceipt rule, because the cross-schema EXISTS check
> against InventoryLot.goodsReceiptId is not yet expressible in OR's
> validation DSL — this is the documented ADR-031 exception path per
> design.md §D2.

## Tasks

- [x] **Task 1:** Confirm no `InventoryLot` or `ExpiryAlert` schema already exists — scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, and `adr-000-data-model.md`; catalogue the existing `InventoryItem` and `InventoryStock` entries for the additive patch and aggregation wiring
- [x] **Task 2:** Confirm the `inventory-stock-movement-ledger` change has landed and `StockMovement` register is declared in `lib/Settings/shillinq_register.json`; if not, block this task and record the dependency gap
- [x] **Task 3:** Author `specs/inventory-lot-batch-expiry/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (inventory operations)` / `Depends on: inventory-stock-movement-ledger` header, `REQ-LOT-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN — covering InventoryLot register, FEFO sort, lot lifecycle, ExpiryAlert register, and InventoryItem patch
- [x] **Task 4:** Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (Risk 1: `x-openregister-sort` enforcement; Risk 2: expiry date absent) / Rollback / Open Questions per shillinq `config.yaml` `rules.proposal`
- [x] **Task 5:** Author `design.md` with Reuse Analysis table per `config.yaml` `rules.design`, including D1 (InventoryLot as atomic FEFO unit), D2 (declarative FEFO sort with ADR-031 exception path), D3 (four-state lifecycle), D4 (ExpiryAlert as separate register); include 5-object Dutch seed data
- [x] **Task 6:** Declare the `InventoryLot` schema in `lib/Settings/register.d/inventory-lot-batch-expiry.json` with all REQ-LOT-002 fields (`lotNumber`, `batchCode`, `productSku`, `manufactureDate`, `expiryDate`, `bestBeforeDate`, `quantity`, `unitCode`, `unitCost`, `warehouseLocation`, `lotStatus`, `receivedDate`, `goodsReceiptId`, `notes`) typed per spec; adds `x-schema-org-type: schema:Product` and `x-openregister-unique` on `(administrationId, lotNumber)`
- [x] **Task 7:** Add `x-openregister-sort: [{field: expiryDate, direction: asc, nulls: last}]` to the `InventoryLot` schema per REQ-LOT-005 AND register the `OCA\Shillinq\Sort\FefoSort::sortLots` ADR-031 exception-path guard for the case the directive is advisory at the API layer (single-method, pure ordering — documented in design.md §D2)
- [x] **Task 8:** Add `x-openregister-lifecycle` to `InventoryLot` declaring `active`, `quarantined`, `expired`, `exhausted` states and the five transitions per REQ-LOT-006; the `quantity == 0 → exhausted` postcondition is declared via the `exhaust` transition validation, and the `today > expiryDate` guard is declared via the `expireFromActive` / `expireFromQuarantine` transition validations
- [x] **Task 9:** Add `x-openregister-relations` FKs on `InventoryLot`: `productSku → Product.sku` (required, many-to-one), `goodsReceiptId → GoodsReceipt.id` (optional, many-to-one), reverse-relations to `StockMove.lotId` and `ExpiryAlert.lotId` (one-to-many)
- [x] **Task 10:** Declare the `ExpiryAlert` schema in `lib/Settings/register.d/inventory-lot-batch-expiry.json` with all REQ-LOT-007 fields (`lotId`, `alertType`, `alertDate`, `daysBeforeExpiry`, `status`, `resolvedDate`, `recipientId`, `notes`); add `x-openregister-relations` FK: `lotId → InventoryLot.id`; add `x-openregister-lifecycle` for `pending → acknowledged → resolved` transitions
- [x] **Task 11:** Patch `Product` schema (the shillinq catalogue slug for the spec entity 'InventoryItem') with additive field `requiresLotTracking: boolean (default: false)` per REQ-LOT-008; non-breaking — existing `Product` objects without the field default to `false`
- [x] **Task 12:** Add `requiresLotTracking: true` precondition on `GoodsReceipt.save` — when a `Product` with `requiresLotTracking: true` is received without an associated `InventoryLot`, the save MUST be rejected. Implemented as `OCA\Shillinq\Lifecycle\LotTrackingReceiptGuard::validate` per ADR-031 exception path; the `GoodsReceipt` schema declares the `lotRequiredForTrackedProduct` lifecycle validation with the guard hook
- [x] **Task 13:** Add Lots & Batches and Expiry Alerts navigation + pages to `src/manifest.json` (menu entries `Inventory > Lots & Batches`, `Inventory > Expiry Alerts`; `type: index` page binding to `InventoryLot` with default columns `lotNumber`, `productSku`, `expiryDate`, `quantity`, `lotStatus`, `warehouseLocation`; `type: detail` page per REQ-LOT-009 with `lifecycleActions: true`; same pair for `ExpiryAlert`); manifest version bumped 1.3.3 → 1.3.4; `node tests/validate-manifest.js` exits 0
- [x] **Task 14:** Ship demo seed data as `lib/Settings/seeds/inventory-lots-demo.json` with the 5 Dutch pet-food lot examples from `design.md`; extend the repair step (`InitializeSettings::seedInventoryLotsDemo` calling `SettingsService::seedInventoryLots`) to load this file idempotently (deduplicates on `(administrationId, lotNumber)`); also ships `OCA\Shillinq\Cron\LotExpiryAlertJob` — a daily TimedJob raising `approaching_expiry` (30/7-day) and `expired` ExpiryAlert rows; app version bumped 0.5.3 → 0.5.4 for the bundle cache-bust
- [x] **Task 15:** Update `openspec/architecture/adr-000-data-model.md` with two new entity entries (`InventoryLot`, `ExpiryAlert`) and an annotation note on the additive `requiresLotTracking` field on the `Product` schema (catalogue slug for the spec entity 'InventoryItem'); includes primary spec reference (`inventory-lot-batch-expiry`), Schema.org types (`schema:Product` for `InventoryLot`, `schema:Action` for `ExpiryAlert`), declarative-vs-imperative annotations, and forward references to the ADR-031 FefoSort and LotExpiryAlertJob seams

## Verification

`openspec validate` exits clean on the change folder. Warehouse-operator
persona peer review confirms the lot schema matches real FEFO warehouse
practice: FEFO sort is correct (NULLS LAST), quarantine release is
recognisable, expiry alert thresholds (30 + 7 days) match real pet-food
operator expectations. Architecture reviewer confirms ADR-022 + ADR-024 +
ADR-031 + ADR-032 compliance:

- ADR-022: no app-local audit table, no parallel Mapper for lots
- ADR-024: `lib/Settings/register.d/inventory-lot-batch-expiry.json`
  carries the schema; `src/manifest.json` carries the navigation
- ADR-031: FEFO sort, GoodsReceipt lot-required precondition and the
  daily expiry sweep are each implemented as a SINGLE-method PHP seam,
  each linked back to design.md's declarative-vs-imperative decision
  table (FefoSort::sortLots, LotTrackingReceiptGuard::validate, the
  LotExpiryAlertJob daily run)
- ADR-032: spec is a `kind: config` slice (schemas + manifest +
  declarative lifecycle); the daily cron is a small `kind: code`
  companion shipping in the same change because the declarative engine
  cannot express date-arithmetic thresholds across all lots

## Tests (company-wide ADR-009)

PHPUnit unit tests authored against the implementation:

- `tests/Unit/Sort/FefoSortTest.php` — 5 tests / 11 assertions
  - Three lots in ascending expiry order (REQ-LOT-005)
  - Null expiry sorts last (NULL-last semantics)
  - Missing / empty expiryDate keys treated as null
  - Empty list returns empty
  - Equal-expiry lots preserve input order (stability)
- `tests/Unit/Lifecycle/LotTrackingReceiptGuardTest.php` — 6 tests / 6 assertions
  - Tracked SKU received without a lot — rejects (REQ-LOT-008)
  - Tracked SKU with matching lot — accepts
  - Non-tracked SKU without a lot — accepts
  - Missing requiresLotTracking field defaults to false (additive non-breaking)
  - Unknown product (null) — silent (FK validator's concern)
  - Lot for different SKU does not satisfy the guard
- `tests/Unit/Cron/LotExpiryAlertJobTest.php` — 4 tests / 4 assertions
  - daysBetween future expiry returns positive
  - daysBetween past expiry returns negative
  - daysBetween same date returns zero
  - daysBetween seven-day boundary (REQ-LOT-007 inner threshold)

Combined lot-related suite: 15 tests, 22 assertions, all green under
`./vendor/bin/phpunit --bootstrap tests/bootstrap-stubs.php`.

The full sweep across active lots and ExpiryAlert uniqueness-key
deduplication is exercised via integration tests once a running
OpenRegister is available; the unit suite pins the date arithmetic
because off-by-one bugs there would shift every threshold by a day.

## Documentation (company-wide ADR-010)

Implementation-side journeydoc lives in `docs/user-guide/inventory/`
under the established conduction app convention (out of scope for this
schema-led implementation cycle — a follow-up journeydoc-add-story pass
will add the four stories enumerated in proposal.md).

## i18n (company-wide ADR-005)

Dutch (`l10n/nl.json`) and English (`l10n/en.json`) translation strings
shipped:

`Lots & Batches`, `Lot Number`, `Batch Code`, `Manufacture Date`,
`Expiry Date`, `Best Before`, `Warehouse Location`, `Received Date`,
`Goods Receipt`, `Quarantined`, `Expired`, `Exhausted`,
`Expiry Alerts`, `Expiry Alert`, `Alert Date`, `Alert Type`,
`Days Before Expiry`, `Resolved Date`, `Recipient`, `FEFO`,
`Lot tracking required`,
`Lot number required for tracked item: receipt MUST reference an InventoryLot.`,
`Cannot expire lot: expiry date not yet reached.`,
`Cannot exhaust lot: quantity is greater than zero.`,
`Quarantine lot`, `Release from quarantine`, `Mark expired`,
`Mark exhausted`, `Acknowledge`, `Resolve`.
