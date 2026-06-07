# Tasks — Immutable Stock-move Ledger

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately
> out of scope here. The tasks below describe the work an `opsx-apply` cycle will
> execute against the `inventory-stock-movement-ledger` spec — they are recorded now
> so the spec-review gate, dependency planning, and tier-cascade impact are all
> visible at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `inventory-stock-movement-ledger` capability spec already exists,
  no `StockMove` schema is declared, and no `lib/Service/StockMove*` / `lib/Service/Inventory*`
  PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this
  capability "follows Odoo's double-entry + Tryton's Stock Move pattern"

- [x] Task 2: Author `specs/inventory-stock-movement-ledger/spec.md` with `Status: proposed` /
  `Scope: shillinq` / `Tier: T2 (inventory + operations)` / `Depends on: inventory-stock-tracking,
  add-shillinq-general-ledger` header, `REQ-SM-NNN` requirements using RFC 2119 keywords, and
  `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including
  Affected Projects / Scope / Risks (GL materialisation performance, reserved qty concurrency,
  cost method variance, manufacture BOM dependency) / Rollback / Open Questions

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (double-entry semantics),
  D2 (declarative lifecycle with immutability), D3 (GL materialisation per move type),
  D4 (reserved quantity workflow), D5 (stock ledger aggregation), D6 (audit trail with reason codes)

- [x] Task 5: Declare the `StockMove` schema in `lib/Settings/shillinq_register.json` with all
  REQ-SM-002 fields (movementNumber, itemId, quantity, unitCost, movementType, sourceLocationId,
  destinationLocationId, referenceDocumentUri, movementReason, notes, draftedAt, postedAt,
  cancelledAt, administrationId, locked, lifecycleState)

- [x] Task 6: Add `x-openregister-lifecycle` to `StockMove` declaring every transition in
  REQ-SM-003 (`draft → posted → cancelled`) consuming OR lifecycle extension; immutability
  lock (`locked = true` on post) enforced via guard or computed field

- [x] Task 7: Implement reserved quantity workflow per REQ-SM-004 — draft `StockMove` reserves
  qty from source `InventoryStock` via OR's optimistic-lock (CAS); posting commits; cancellation
  releases. Collision handling: operator sees "another operator is updating" message + retry suggestion

- [x] Task 8: Declare GL materialisation rules per REQ-SM-006 via `x-openregister-materialisation`
  extension — receipt increases asset, issue decreases asset + posts COGS, transfer/repack neutral,
  manufacture posts finished goods. No PHP service; rule-driven. GL lines reference `StockMove`
  UUID via `subLedgerType: "inventory"`, `subLedgerRef: "<UUID>"`

- [x] Task 9: Declare stock ledger aggregation per REQ-SM-005 — InventoryStock.quantity =
  SUM(destination moves) - SUM(source moves) excluding cancelled. Index on (sourceLocationId,
  destinationLocationId, lifecycleState). Operator can drill down from InventoryStock → all
  constituent moves with running-total trace

- [x] Task 10: Implement immutability: posted moves (`locked = true`) reject edits; cancellation
  creates offsetting `StockMove` (not patch) to preserve immutable log. Original + offset linked
  via `relations` (OR built-in field). Offset move references original in `notes` or dedicated
  `offsetMoveId` field

- [x] Task 11: Declare audit trail per REQ-SM-007 — `auditTrail` captures operator, timestamp,
  previousState JSON, movementReason on every transition. Reason code mandatory on post (enum:
  normal, damaged, expired, shrinkage, inter-warehouse, adjustment, sample, demo, theft, loss;
  admin-configurable). Immutable log, no edits

- [x] Task 12: Add manifest navigation entries per REQ-SM-008 (Stock Movements index, Stock Ledger
  index with drill-down, Reserved Stock index) + their `type: detail` pages. Filters on Stock
  Movements: date range, type, status, location. Reserved Stock grouped by source location,
  showing age + operator

- [x] Task 13: Implement reserved quantity visibility per REQ-SM-009 — InventoryStock detail page
  shows Available = quantity - reservedQty. Reserved Stock index lists draft moves holding reservations
  (age, operator name). Alert flag if reservedQty > 50% of quantity

- [x] Task 14: Declare movement reason codes as admin-configurable enum (default: normal, damaged,
  expired, shrinkage, inter-warehouse, adjustment, sample, demo, theft, loss). Operator can add
  custom codes per administration. Reason code mandatory on post (REQ-SM-007 guard)

- [x] Task 15: Confirm Location entity (from budget-planning-control spec) carries minimum fields
  (name, code, address) for warehouse identity. If missing fields, file ADR-032 review or patch
  Location schema. StockMove simply FKs to Location

- [x] Task 16: Update `openspec/architecture/adr-000-data-model.md` with `StockMove` entry,
  declaring schema, primary spec (`inventory-stock-movement-ledger`), all fields per REQ-SM-002,
  and relations to Product (via itemId), Location (sourceLocationId, destinationLocationId),
  InventoryStock (implicitly via product+location trace)

## Verification

`openspec validate` must exit clean on the change folder. Accountant-persona peer review
(e.g. `/test-persona-janwillem` for SMB) confirms the stock-move flow matches Dutch SMB
practice (receipt with PO, inter-warehouse transfer, customer issue with COGS GL posting,
repack, GL reconciliation to InventoryStock, audit trail with reason codes). Architecture
reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local stock-move service;
lifecycle + GL materialisation declarative; manifest carries the navigation). No source
code changes outside `openspec/changes/inventory-stock-movement-ledger/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate
`opsx-apply`) is responsible for: PHPUnit unit tests for StockMove lifecycle (draft→posted,
cancellation offset creation, immutability lock), reserved quantity CAS collision + retry,
GL materialisation per move type (receipt asset, issue COGS, transfer neutral, manufacture,
repack), InventoryStock reconciliation to move sum, audit trail + reason code capture,
manifest detail pages (pre-declared on Tasks 8–14); Playwright MCP browser tests for the
3 manifest navigation entries + filters (Stock Movements: date range, type, status, location;
Stock Ledger drill-down; Reserved Stock grouping); `composer test` green at the implementing
PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors
`docs/user-guide/inventory/stock-movements.md` per ADR-030 journeydoc convention and
commits stock-movement screenshots (receipt, issue, stock ledger, reserved stock, GL
reconciliation trace) to `docs/images/`. Includes: "How to receive goods (receipt move,
GL posting)", "How to transfer stock between warehouses", "How to issue stock (COGS posting)",
"Stock ledger audit trail", "Reserved quantity workflow (draft moves)".

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch
(`nl_NL`) and English (`en_US`) translation strings for: `Stock Movements`, `Stock Move`,
`Stock Ledger`, `Reserved Stock`, `Receipt`, `Transfer`, `Issue`, `Manufacture`, `Repack`,
`Draft`, `Posted`, `Cancelled`, `Available`, `Reserved`, `Movement Reason`, `Normal`,
`Damaged`, `Expired`, `Shrinkage`, `Inter-warehouse`, `Adjustment`, `Sample`, `Demo`,
`Theft`, `Loss`, `Confirm`, `Cancel`, `Create Offset`, `High Reservation Ratio`.
