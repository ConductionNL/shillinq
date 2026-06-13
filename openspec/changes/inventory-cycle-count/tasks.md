# Tasks — Inventory Cycle Count

> **Implementation applied.** All 15 tasks below have been implemented by the Hydra
> builder in PR feature/133/inventory-cycle-count.

## Tasks

- [x] Task 1: Confirm no `inventory-cycle-count` capability spec already exists, no 
  `InventoryCycleCount`/`InventoryCycleCountLine`/`InventoryVarianceReason` schemas are 
  declared, and no `lib/Service/CycleCount*` or `lib/Service/StockCount*` PHP classes 
  are present (per ADR-031 anti-pattern enumeration).

- [x] Task 2: Author `specs/inventory-cycle-count/spec.md` with `Status: proposed` / 
  `Scope: shillinq` / `Tier: T2 (inventory operations)` / `Depends on: inventory-stock-
  tracking, cost-accounting-allocation` header; `REQ-ICC-NNN` requirements using RFC 2119 
  keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-024 + ADR-031 inline; 
  explicitly address the legacy competitor intelligence-db cycle-count cluster (16/22 
  competitors).

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and 
  including Affected Projects / Scope (in: cycle count, variance reasons, partial counts, 
  line variance, reconciliation; out: mobile scanner, barcode generation, scheduling) / 
  Risks (variance threshold conditional logic, partial-count filtering, scanner T4 
  deferral) / Rollback / Open Questions (reason-code policy, scheduler).

- [x] Task 4: Author `design.md` with context, goals, non-goals, decisions (D1: line-
  item register, D2: configurable reason codes, D3: declarative threshold, D4: GL posting, 
  D5: standard filters, D6: webhook documented), data-model tables, sample seed data 
  (3–5 cycle counts + lines + reason codes, Dutch SKUs and company names), and lifecycle 
  state diagram.

- [x] Task 5: Declare the `InventoryCycleCount` schema in `lib/Settings/shillinq_register.json` 
  with all REQ-ICC-002 fields (countId, countDate, initiatedBy, countType, locationFilter, 
  categoryFilter, expectedValue, countedValue, varianceValue, variancePercentage, state, 
  notes, administrationId); include `x-openregister-metadata` for variance thresholds 
  (quantityVarianceThresholdPercent default 5%, valueVarianceThresholdAbsolute default €500).

- [x] Task 6: Declare the `InventoryCycleCountLine` schema in `lib/Settings/shillinq_register.json` 
  with all REQ-ICC-003 fields (lineId, countId, sku, productName, expectedQuantity, 
  countedQuantity, unitCost, expectedValue, countedValue, quantityVariance, valueVariance, 
  requiresReason, reasonCode, notes); calculated fields (expectedValue, countedValue, 
  quantityVariance, valueVariance, requiresReason) declared as `x-openregister-calculations`.

- [x] Task 7: Declare the `InventoryVarianceReason` schema in `lib/Settings/shillinq_register.json` 
  with all REQ-ICC-005 fields (reasonId, name, category, description, isActive, 
  administrationId); auto-seed 7 default reason codes (DMG, OBS, ERR-COUNT, ERR-STOCK, 
  THEFT, SYS, OTHER) on administration creation.

- [x] Task 8: Add `x-openregister-lifecycle` to `InventoryCycleCount` declaring all 
  transitions in REQ-ICC-006 (draft → submitted → counting → posted → reconciled, plus 
  cancellation from any state); implement snapshot-creation on `draft → submitted` 
  transition (populate `InventoryCycleCountLine` from `InventoryStock` query); implement 
  reason-code validation on `counting → posted` transition per REQ-ICC-004.

- [x] Task 9: Implement variance threshold flagging on `InventoryCycleCountLine` via 
  `x-openregister-calculations.requiresReason` (preferred) OR if engine cannot express 
  conditional logic, register `OCA\Shillinq\Lifecycle\VarianceGate::requiresInvestigation()` 
  (~30 LOC, ADR-031 exception annotated) checking both % and absolute thresholds per 
  REQ-ICC-004.

- [x] Task 10: Implement variance adjustment posting on `InventoryCycleCount.posted → 
  reconciled` transition: create `InventoryAdjustment` records (one per non-zero-variance 
  line), update `InventoryStock.quantity` per REQ-ICC-007, post GL impact to variance-
  expense account with cost-center allocation + reason-code FK; serialize overlapping 
  count conflicts via pessimistic locking or transaction guard.

- [x] Task 11: Implement partial-count filtering (location / category scope) as standard 
  OR register queries (no bespoke indexing) per REQ-ICC-008; query `InventoryStock` by 
  `location` or `product.category` filter on count-submit to populate line scope.

- [x] Task 12: Document webhook endpoint shape (POST `/api/cycle-count/{countId}/count-
  line`) in `design.md` and `docs/architecture/integration-points.md` as a future T4 
  mobile-scanner integration point per REQ-ICC-009; primary T2 path is manual count-line 
  entry via UI.

- [x] Task 13: Add 3 manifest navigation entries (`Cycle Counts`, `Count Templates`, 
  `Variance Reports`) + their `type: index` / `type: detail` pages to `src/manifest.json` 
  per REQ-ICC-010; include filtering by state, date range, location; drill-down from 
  variance reports to individual count lines; `node tests/validate-manifest.js` exits 0.

- [x] Task 14: Declare `InventoryAdjustment` (stub) in `openspec/architecture/adr-000-
  data-model.md` with back-reference to `InventoryCycleCount` for audit trail; full 
  definition of `InventoryAdjustment` deferred to a separate inventory-adjustment spec 
  (T2 follow-up).

- [x] Task 15: Update `openspec/architecture/adr-000-data-model.md` with full entries for 
  `InventoryCycleCount`, `InventoryCycleCountLine`, `InventoryVarianceReason` per spec 
  schema definitions; confirm no conflicting `StockCount`, `PhysicalCount`, or 
  `CountReason` entries already exist.

## Verification

`openspec validate` must exit clean on the change folder. Warehouse-supervisor persona 
peer review (e.g., using `/test-persona-*` for SMB warehouse roles) confirms the cycle-
count flow matches Dutch SMB practice (count initiation → line counting → variance 
investigation → GL posting → reconciliation). Architecture reviewer confirms ADR-024 + 
ADR-031 compliance (no app-local count service; variance calculation declarative or 
exception-annotated guard; manifest carries navigation; webhook shape documented for T4). 
No source code changes outside `openspec/changes/inventory-cycle-count/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate 
`opsx-apply`) is responsible for:

- PHPUnit unit tests for:
  - Cycle count state machine (all transitions in REQ-ICC-006).
  - Variance calculation (threshold % and absolute amount per REQ-ICC-004).
  - Reason-code validation (mandatory for flagged lines).
  - Variance adjustment posting (inventory update + GL posting per REQ-ICC-007).
  - Partial-count filtering (location/category scoping).
  - Conflict detection (overlapping counts on same SKU).
  - Snapshot creation on count submission.

- Playwright MCP browser tests for:
  - 3 manifest navigation pages (Cycle Counts, Count Templates, Variance Reports).
  - Count creation wizard (full vs. partial, location selection).
  - Count-line data entry and variance auto-flagging.
  - Reason-code dropdown population and selection.
  - State-transition button visibility (conditional on rules in REQ-ICC-006).
  - Variance report aggregation by reason code and location.

- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/inventory/cycle-count.md` — step-by-step cycle-count workflow with 
  screenshots of count creation, line entry, variance review, GL posting, and reconciliation.
- `docs/user-guide/inventory/variance-reasons.md` — explanation of variance categories 
  and reason-code governance; how to customize reason codes per administration.
- `docs/architecture/integration-points.md` — update with future mobile-scanner webhook 
  endpoint (T4).
- Commit count + variance-report screenshots to `docs/images/inventory-cycle-count/`.

Per ADR-030 journeydoc convention.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch 
(`nl_NL`) and English (`en_US`) translation strings for:

- **UI labels**: "Cycle Count", "Count Date", "Count Type", "Full Count", "Partial Count", 
  "Location", "Category", "Expected Qty", "Counted Qty", "Variance", "Requires Reason", 
  "Reason Code", "Draft", "Submitted", "Counting", "Posted", "Reconciled", "Cancelled", 
  "Count Templates", "Variance Reports", "Count by Reason", "Count by Location".

- **Reason codes (seed)**: "Damaged Goods", "Obsolescence", "Counting Error", "Stocking 
  Error", "Loss/Theft", "System Discrepancy", "Other".

- **Action labels**: "Submit Count", "Begin Counting", "Complete Count", "Post Count", 
  "Reconcile Count", "Cancel Count", "View Variance Report", "Download Variance Report".

- **Error messages**: "Partial count requires location or category scope", "Variance 
  investigation required; select a reason code", "Counting error discovered; count 
  cancelled", "Overlapping count in-flight for same SKU; resolve conflicts before posting".

- **Help text**: "Select reason code to categorize this variance for audit trail", 
  "Variance threshold configured at X% qty and €Y absolute cost", "Mobile scanner 
  integration available in T4; currently manual entry only".
