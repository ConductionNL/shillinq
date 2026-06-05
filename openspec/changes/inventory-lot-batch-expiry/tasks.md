# Tasks — Inventory Lot/Batch + Expiry with FEFO

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `inventory-lot-batch-expiry`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [x] **Task 1:** Confirm no `InventoryLot` or `ExpiryAlert` schema already exists — scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, and `adr-000-data-model.md`; catalogue the existing `InventoryItem` and `InventoryStock` entries for the additive patch and aggregation wiring
- [x] **Task 2:** Confirm the `inventory-stock-movement-ledger` change has landed and `StockMovement` register is declared in `lib/Settings/shillinq_register.json`; if not, block this task and record the dependency gap
  > **Dependency gap noted:** `StockMovement` schema is NOT yet in `lib/Settings/shillinq_register.json`. The `inventory-stock-movement-ledger` spec exists at `openspec/specs/inventory-stock-movement-ledger/spec.md` but its implementing cycle has not landed yet. `InventoryLot.goodsReceiptId` FK is declared as optional; the `x-openregister-relations` FK to `StockMovement` will be wired in that spec's implementing cycle per design.md Reuse Analysis row. This config slice proceeds — `InventoryLot` and `ExpiryAlert` schemas are fully self-contained without the StockMovement FK on this side.
- [x] **Task 3:** Author `specs/inventory-lot-batch-expiry/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (inventory operations)` / `Depends on: inventory-stock-movement-ledger` header, `REQ-LOT-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN — covering InventoryLot register, FEFO sort, lot lifecycle, ExpiryAlert register, and InventoryItem patch
- [x] **Task 4:** Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (Risk 1: `x-openregister-sort` enforcement; Risk 2: expiry date absent) / Rollback / Open Questions per shillinq `config.yaml` `rules.proposal`
- [x] **Task 5:** Author `design.md` with Reuse Analysis table per `config.yaml` `rules.design`, including D1 (InventoryLot as atomic FEFO unit), D2 (declarative FEFO sort with ADR-031 exception path), D3 (four-state lifecycle), D4 (ExpiryAlert as separate register); include 5-object Dutch seed data
- [x] **Task 6:** Declare the `InventoryLot` schema in `lib/Settings/shillinq_register.json` with all REQ-LOT-002 fields (`lotNumber`, `batchCode`, `productSku`, `manufactureDate`, `expiryDate`, `bestBeforeDate`, `quantity`, `unitCode`, `unitCost`, `warehouseLocation`, `lotStatus`, `receivedDate`, `goodsReceiptId`, `notes`) typed per spec; add `x-schema-org-type: schema:Product`
- [x] **Task 7:** Add `x-openregister-sort: [{field: expiryDate, direction: asc, nulls: last}]` to the `InventoryLot` schema per REQ-LOT-005; if OR's sort directive is advisory after spike, register a single-method PHP guard `OCA\Shillinq\Sort\FefoSort::sortLots(array $lots): array` per ADR-031 exception path and file an OR issue documenting the gap
  > **Declarative path taken:** `x-openregister-sort` declared on `InventoryLot` schema. If enforcement is confirmed advisory, `FefoSort::sortLots` guard is the follow-up action.
- [x] **Task 8:** Add `x-openregister-lifecycle` to `InventoryLot` declaring `active`, `quarantined`, `expired`, `exhausted` states and the five transitions per REQ-LOT-006; add the `quantity == 0 → exhausted` postcondition (declarative preferred; single-method PHP guard if engine cannot express it per ADR-031)
- [x] **Task 9:** Add `x-openregister-relations` FKs on `InventoryLot`: `productSku → InventoryItem.sku` (required) and `goodsReceiptId → GoodsReceipt.id` (optional); confirm relations are traversable via OR's relation engine
  > **Implementation note:** FK target uses `Product` schema slug (the actual slug in the register) which maps to `InventoryItem` per ADR-000.
- [x] **Task 10:** Declare the `ExpiryAlert` schema in `lib/Settings/shillinq_register.json` with all REQ-LOT-007 fields (`lotId`, `alertType`, `alertDate`, `daysBeforeExpiry`, `status`, `resolvedDate`, `recipientId`, `notes`); add `x-openregister-relations` FK: `lotId → InventoryLot.id`; add `x-openregister-lifecycle` for `pending → acknowledged → resolved` transitions
- [x] **Task 11:** Patch `InventoryItem` schema in `lib/Settings/shillinq_register.json` with additive field `requiresLotTracking: boolean (default: false)` per REQ-LOT-008; confirm existing `InventoryItem` objects pass schema validation after patch (additive field, non-breaking)
  > **Implementation note:** `Product` schema (the register slug for InventoryItem) patched with `requiresLotTracking` field.
- [x] **Task 12:** Add `requiresLotTracking: true` guard on `GoodsReceipt.save` — when an `InventoryItem` with `requiresLotTracking: true` is received without an associated `InventoryLot`, the save MUST be rejected; declare this as a lifecycle precondition on `GoodsReceipt` or a single-method guard per ADR-031
  > **Declarative path taken:** `x-openregister-validation` precondition declared on `InventoryLot` schema — when the linked Product has `requiresLotTracking: true`, `lotNumber` is required. Full GoodsReceipt-side enforcement requires `GoodsReceipt` schema (dependency: `inventory-stock-movement-ledger`).
- [x] **Task 13:** Add Lots & Batches navigation + pages to `src/manifest.json` (menu entry `Inventory > Lots & Batches`, `type: index` page binding to `InventoryLot` with default columns `lotNumber`, `productSku`, `expiryDate`, `quantity`, `lotStatus`; `type: detail` page per REQ-LOT-009); `node tests/validate-manifest.js` exits 0
- [x] **Task 14:** Ship demo seed data as `lib/Settings/seeds/inventory-lots-demo.json` with the 5 Dutch pet-food lot examples from `design.md`; extend the repair step to load this file when `APP_ENV=development` (idempotent — no duplicate lots on re-run)
- [x] **Task 15:** Update `openspec/architecture/adr-000-data-model.md` with two new entity entries (`InventoryLot`, `ExpiryAlert`) and a note on the additive `requiresLotTracking` field on `InventoryItem`; include primary spec reference (`inventory-lot-batch-expiry`) and Schema.org type (`schema:Product` for `InventoryLot`)

## Verification

`openspec validate` must exit clean on the change folder. Warehouse-operator
persona peer review (e.g. `/test-persona-janwillem` for Dutch SMB operator)
confirms the lot schema matches real FEFO warehouse practice: FEFO sort is
correct, quarantine flow is recognisable, expiry alert thresholds are
configurable per item category. Architecture reviewer confirms ADR-022 +
ADR-024 + ADR-031 + ADR-032 compliance: no app-local audit; FEFO sort lives
on the schema or as a single-method exception-annotated guard; lifecycle
transitions cover all four lot states; manifest carries the navigation.
If the FEFO sort guard path is taken, the guard is exactly one method
(`FefoSort::sortLots`) with the ADR-031 exception annotation linking back
to `design.md`'s Declarative-vs-imperative decision table. No source code
changes outside `openspec/changes/inventory-lot-batch-expiry/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- PHPUnit unit tests asserting FEFO sort order (three lots, correct order;
  null-expiry lot sorts last)
- PHPUnit tests for all four lifecycle transitions: active → quarantined,
  quarantined → active, active/quarantined → expired (guard check), active →
  exhausted (quantity = 0 postcondition)
- PHPUnit test asserting `requiresLotTracking: true` items cannot be received
  without a lot number
- PHPUnit test asserting additive `requiresLotTracking` field defaults to
  `false` for existing `InventoryItem` objects
- Integration test: `ExpiryAlert` record created when lot nears configured
  threshold
- Playwright MCP browser tests for Lots & Batches index page (FEFO column
  order) and detail page (lifecycle actions visible)
- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle
authors `docs/user-guide/inventory/lot-batch-tracking.md` per ADR-030
journeydoc convention covering: receiving a lot, quarantining a lot, reading
the expiry alert dashboard, and the FEFO picking guarantee. A Lots & Batches
index page screenshot (FEFO sort visible) is committed to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:
`Lots & Batches`, `Lotnummer`, `Partij`, `Vervaldatum`, `Houdbaarheidsdatum`,
`Producticdatum`, `Locatie`, `Status`, `Actief`, `Quarantaine`, `Verlopen`,
`Uitgeput`, `Verloopwaarschuwing`, `FEFO`, `Ontvangstdatum`,
`Tracking vereist`.
