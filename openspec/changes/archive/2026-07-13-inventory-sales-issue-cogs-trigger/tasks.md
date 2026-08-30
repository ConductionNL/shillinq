# Tasks: inventory-sales-issue-cogs-trigger

## Implementation Tasks

### Task 1: Declarative schema additions — Delivery + InventoryGLConfig
- **spec_ref**: `openspec/changes/inventory-sales-issue-cogs-trigger/specs/inventory-sales-issue-cogs-trigger/spec.md#req-003`, `#req-005`, `#req-006`
- **files**: `lib/Settings/register.d/inventory-sales-issue-cogs-trigger.json`
- **acceptance_criteria**:
  - GIVEN the fragment is merged WHEN `Delivery` is read THEN it carries a nullable `sourceLocationId` field and a `cancel` transition to a new `cancelled` state
  - GIVEN the fragment is merged WHEN `InventoryGLConfig` is read THEN it carries a boolean `allowNegativeStockOnDispatch` field defaulting to `false`
- [x] Implement
- [x] Test

### Task 2: Extend QuoteOrderInvoiceGuard with stock-availability + cancel guards
- **spec_ref**: `openspec/changes/inventory-sales-issue-cogs-trigger/specs/inventory-sales-issue-cogs-trigger/spec.md#req-005`
- **files**: `lib/Lifecycle/QuoteOrderInvoiceGuard.php`
- **acceptance_criteria**:
  - GIVEN a stock-tracked line with insufficient available quantity and `allowNegativeStockOnDispatch: false` WHEN `canConfirmDelivery` runs THEN it returns false
  - GIVEN `allowNegativeStockOnDispatch: true` WHEN the same check runs THEN it returns true
  - GIVEN a `Delivery` in `shipped` WHEN `canCancelDelivery` runs THEN it returns false
- [x] Implement
- [x] Test

### Task 3: SalesDispatchStockIssueService — issue path
- **spec_ref**: `openspec/changes/inventory-sales-issue-cogs-trigger/specs/inventory-sales-issue-cogs-trigger/spec.md#req-001`, `#req-003`, `#req-004`
- **files**: `lib/Service/SalesDispatchStockIssueService.php`
- **acceptance_criteria**:
  - GIVEN a confirmed Delivery with one stock-tracked line WHEN `issueForDelivery()` runs THEN exactly one posted `issue` StockMove is created
  - GIVEN a line with no matching InventoryStock row WHEN `issueForDelivery()` runs THEN no StockMove is created for that line
  - GIVEN a line already issued (matching `referenceDocumentUri`) WHEN `issueForDelivery()` runs again THEN no duplicate StockMove is created
- [x] Implement
- [x] Test

### Task 4: SalesDispatchStockIssueService — reversal path
- **spec_ref**: `openspec/changes/inventory-sales-issue-cogs-trigger/specs/inventory-sales-issue-cogs-trigger/spec.md#req-006`
- **files**: `lib/Service/SalesDispatchStockIssueService.php`
- **acceptance_criteria**:
  - GIVEN a Delivery that issued a posted StockMove WHEN `reverseForDelivery()` runs THEN that StockMove is transitioned to `cancelled` via the existing StockMove.cancel transition
- [x] Implement
- [x] Test

### Task 5: DeliveryDispatchListener + Application.php wiring
- **spec_ref**: `openspec/changes/inventory-sales-issue-cogs-trigger/specs/inventory-sales-issue-cogs-trigger/spec.md#req-001`, `#req-006`
- **files**: `lib/Listener/DeliveryDispatchListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a Delivery ObjectTransitionedEvent to `confirmed` WHEN the listener handles it THEN `SalesDispatchStockIssueService::issueForDelivery()` is invoked
  - GIVEN a Delivery ObjectTransitionedEvent to `cancelled` WHEN the listener handles it THEN `SalesDispatchStockIssueService::reverseForDelivery()` is invoked
  - GIVEN the downstream service throws WHEN the listener handles the event THEN the exception is logged and not rethrown (fail-soft, matching StockMoveTransitionedListener)
- [x] Implement
- [x] Test

### Task 6: End-to-end correctness test — sale produces issue movement + COGS posting
- **spec_ref**: `openspec/changes/inventory-sales-issue-cogs-trigger/specs/inventory-sales-issue-cogs-trigger/spec.md#req-002`
- **files**: `tests/Unit/Listener/DeliveryDispatchListenerTest.php`
- **acceptance_criteria**:
  - GIVEN this test is run against pre-change code WHEN a Delivery is confirmed THEN no StockMove exists and CogsPosterService is never invoked (fails on HEAD before this change)
  - GIVEN this test is run against post-change code WHEN the same Delivery is confirmed THEN an `issue` StockMove exists and the existing valuation/COGS pipeline is invoked
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — 15 new tests (QuoteOrderInvoiceGuardStockTest, SalesDispatchStockIssueServiceTest, DeliveryDispatchListenerTest), all passing
- New/changed API endpoints covered by Newman/Postman tests — N/A, no new HTTP endpoints
- UI changes covered by Playwright browser tests — N/A, no new UI surface
- All tests pass (`composer test`, `newman run`) — full unit suite 3431 tests, 15 pre-existing ZipArchive-extension env errors (unrelated, delta-zero), all new tests green
- Feature documentation updated in `docs/` if user-facing (ADR-010) — N/A, internal trigger wiring only
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007) — N/A, the new guards (canConfirmDelivery extension, canCancelDelivery) return booleans only, per the existing QuoteOrderInvoiceGuard `requires:` pattern; no new message/messageNl schema text was added
- `openspec validate` passes — confirmed
