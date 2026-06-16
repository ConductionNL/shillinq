# Tasks — Inventory Min/Max + Reorder Point

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `inventory-reorder-automation` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Verify dependency precondition — confirm `InventoryStock` register exists with fields: sku, quantity, reorderLevel, reorderQuantity, location, unitCost, lastRestockDate, status per `inventory-stock-tracking`. If schema has drifted, file ADR-000 amendment before proceeding; implementation cannot start until satisfied.

- [x] Task 2: Verify OpenRegister stability — confirm `x-openregister-notifications`, `x-openregister-lifecycle`, and `x-openregister-aggregations` are stable; if not, file OR issue and proceed with fallback per ADR-031 exception.

- [x] Task 3: Confirm no `lib/Db/` Mapper classes exist naming `reorder_rule`, `reorder_*`, `min_max_*`, `stock_alert`, or `procurement_automation_*`; explicitly note this capability "enables per-item, per-location min/max management" aligned with competitor landscape (22/22 coverage).

- [x] Task 4: Author `specs/inventory-reorder-automation/spec.md` with `Status: proposed` / `Scope: nextcloud-inventory` / `Tier: T1 (core inventory automation)` / `Depends on: inventory-stock-tracking, catalog-purchase-management` header, `REQ-IRA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline.

- [x] Task 5: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (OR notification stability, InventoryStock schema completeness, lead-time calculation, auto-PO runaway orders) / Rollback / Open Questions.

- [x] Task 6: Author `design.md` with Reuse Analysis table, D1 (reorder rule as first-class register per-location), D2 (OR notification consumed with PHP-guard fallback), D3 (reorder trigger as aggregation), D4 (optional auto-PO materialisation), D5 (lead-time-aware calculation), D6 (low-stock dashboard aggregation).

- [x] Task 7: Declare the `InventoryReorderRule` schema in `lib/Settings/shillinq_register.json` with all REQ-IRA-002 fields (inventoryStockId, supplierId, minimumLevel, maximumLevel, reorderPoint, reorderQuantity, leadTimeDays, safetyStockDays, alertThreshold, autoPurchaseOrder, autoPurchaseOrderApprovalRequired, spendingLimit, alertChannel, alertRecipients, snoozeUntil, lifecycleState, administrationId).

- [x] Task 8: Add `x-openregister-lifecycle` to `InventoryReorderRule` declaring every transition in REQ-IRA-009 (active ↔ paused, active → archived, archived → active) consuming OR notification engine per REQ-IRA-004 (or `NotificationGuard` fallback per ADR-031 exception, documented).

- [x] Task 9: Declare low-stock alert trigger as `x-openregister-aggregations` precondition per REQ-IRA-007 (SUM(InventoryStock.quantity) ≤ reorderPoint); alert lifecycle action fires on every InventoryStock quantity change matching the condition.

- [x] Task 10: Implement the alert notification dispatch per REQ-IRA-004 — when alert fires, lifecycle action creates notification record via OR's `x-openregister-notifications` with recipient, channels, payload (item, location, quantity, reorderQuantity, supplier), and action links (Order Now, Snooze, Update Rule).

- [x] Task 11: Implement optional auto-PO generation per REQ-IRA-006 — when alert fires AND autoPurchaseOrder=true, lifecycle action materialises a `PurchaseOrder` to rule's supplier with reorderQuantity, deliveryDate = today + leadTimeDays, status per approval policy, and source="auto-reorder"; block if totalPrice > spendingLimit.

- [x] Task 12: Implement reorder-point calculation per REQ-IRA-005 — formula: reorderPoint = (expectedDailyUsage × leadTimeDays) + safetyStock; if expectedDailyUsage unavailable, default to minimumLevel + (leadTimeDays × 5 + 10); allow manual override.

- [x] Task 13: Declare low-stock dashboard aggregation per REQ-IRA-008 — query: GROUP BY location, COUNT(*) low-stock items, SUM(deficit) = SUM(minimumLevel - quantity) where quantity ≤ minimumLevel; widget displays location, item count, total deficit, quick-action links.

- [x] Task 14: Add 3 manifest navigation entries (Reorder Rules, Low Stock Alerts, Stock Levels) + their `type: index` / `type: detail` / `type: dashboard` pages to `src/manifest.json` per REQ-IRA-010; `node tests/validate-manifest.js` exits 0.

- [x] Task 15: Update `openspec/architecture/adr-000-data-model.md` with `InventoryReorderRule` entry, reconciling against any existing `ReorderRule` / `StockAlert` / `MinMaxPolicy` data-model entries; add Relations pointing to InventoryStock, Supplier, Organization.

- [x] Task 16: Create seed data SQL/JSON for three example reorder rules (grocer apple juice, office supply paper, warehouse fasteners) with distinct min/max/reorder configurations, auto-PO flags, and supplier lead times; populate via `ConfigurationService::importFromApp()` repair-step.

## Verification

`openspec validate` must exit clean on the change folder. SMB-operator
persona peer review (e.g. `/test-persona-janwillem` for small business)
confirms the reorder-automation flow matches Dutch MKB practice (set
min/max rules, monitor stock levels, trigger alerts, optionally
auto-order, receive goods). Architecture reviewer confirms ADR-022 +
ADR-024 + ADR-031 compliance (no app-local notification queue; reorder
trigger declarative or ADR-031-exception-annotated guard; manifest
carries the navigation). Supplier lead-time integration verified against
purchaseq OpenAPI contract. No source code changes outside
`openspec/changes/inventory-reorder-automation/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation
cycle (separate `opsx-apply`) is responsible for:

- PHPUnit unit tests for InventoryReorderRule lifecycle, low-stock
  alert trigger (aggregation evaluation on InventoryStock quantity
  change), notification dispatch, optional auto-PO materialisation,
  spending-limit enforcement, lead-time-aware reorder-point calculation,
  snooze behavior (pre-declared on Tasks 7–13).
- Playwright MCP browser tests for the 3 manifest navigation entries
  (index + detail pages for Reorder Rules, Low Stock Alerts, Stock
  Levels dashboard; create/edit/pause/resume rule workflows;
  alert dismissal + snooze; order-now button; pre-declared on Task 14).
- Integration tests with OR's notification engine (if stable) or
  fallback NotificationGuard; purchaseq auto-PO API mocking.
- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation
cycle authors:

- `docs/user-guide/inventory/reorder-automation.md` per ADR-030
  journeydoc convention with step-by-step rule creation, alert
  monitoring, manual + auto-PO workflows.
- Screenshots of Reorder Rules index + detail page, Low Stock Alerts
  dashboard, alert notification, auto-PO confirmation dialog.
- Troubleshooting section: "Why isn't my alert firing?", "How do I
  adjust lead-time margins?", "Can I disable auto-PO for certain items?".

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- UI labels: `Reorder Rules`, `Low Stock`, `Minimum Level`, `Maximum
  Level`, `Reorder Point`, `Reorder Quantity`, `Lead Time Days`,
  `Safety Stock`, `Auto Purchase Order`, `Spending Limit`, `Alert
  Channel`, `Snooze`, `Update Rule`, `Order Now`, `Stock Levels`,
  `Item Count Below Minimum`, `Total Deficit`.
- Alert messages: `Low stock alert: {{item}} at {{location}} ({{quantity}}
  units, minimum {{minimum}}, reorder {{reorder}})`, `Auto purchase order
  created: {{orderNumber}} for {{reorderQuantity}} units of {{item}}`,
  `Spending limit exceeded: {{totalPrice}} > {{limit}} — approval required`.
- Navigation breadcrumbs and page titles.
