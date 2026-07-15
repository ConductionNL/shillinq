# Tasks: block-unsellable-stock-dispatch

## Implementation Tasks

### Task 1: Verify the defect and the real lot enum/expiry field against HEAD
- **spec_ref**: `openspec/changes/block-unsellable-stock-dispatch/specs/block-unsellable-stock-dispatch/spec.md#requirement-req-blk-001`
- **files**: `lib/Service/SalesDispatchStockIssueService.php`, `lib/Settings/register.d/inventory-lot-batch-expiry.json`, `lib/Settings/register.d/inventory-stock-movement-ledger.json`
- **acceptance_criteria**:
  - GIVEN `SalesDispatchStockIssueService` at HEAD WHEN grepped for `status`/`lotStatus` THEN zero hits confirm no lot-status check
  - GIVEN `inventory-lot-batch-expiry.json` THEN `lotStatus` enum is confirmed `active|quarantined|expired|exhausted` and `expiryDate` is the expiry field (no `damaged`/`blocked`)
  - GIVEN `inventory-stock-movement-ledger.json` THEN `StockMove` is confirmed to have no `lotId` field
- [x] Implement
- [x] Test

### Task 2: Add LotSellabilityGuard (pure ADR-031 decision seam)
- **spec_ref**: `openspec/changes/block-unsellable-stock-dispatch/specs/block-unsellable-stock-dispatch/spec.md#requirement-req-blk-001`
- **files**: `lib/Lifecycle/LotSellabilityGuard.php`
- **acceptance_criteria**:
  - GIVEN a lot WHEN `lotStatus != 'active'` (quarantined/expired/exhausted) THEN it is unsellable
  - GIVEN an `active` lot WHEN `today > expiryDate` THEN it is unsellable (expiry first-class); a lot expiring exactly today is sellable
  - GIVEN sellable lots WHEN summed `quantity >= requiredQuantity` THEN sellable=true, else blocked with a positive shortfall; sellable lots are reported FEFO-ordered
  - GIVEN an unsellable lot THEN it is reported with an EN + NL reason
- [x] Implement
- [x] Test

### Task 3: Enforce at the dispatch point in issueForDelivery()
- **spec_ref**: `openspec/changes/block-unsellable-stock-dispatch/specs/block-unsellable-stock-dispatch/spec.md#requirement-req-blk-001`
- **files**: `lib/Service/SalesDispatchStockIssueService.php`
- **acceptance_criteria**:
  - GIVEN a lot-controlled line whose sellable lots cannot cover it THEN no `StockMove` is created (fail closed), `blocked` increments, and the offending lot(s)+reason are logged and returned in `blockedLines`
  - GIVEN a line with no `InventoryLot` rows for its product THEN dispatch proceeds unchanged (non-lot-tracked SKUs unaffected)
  - GIVEN a line whose sellable lots CAN cover it despite a quarantined/expired sibling THEN it is issued (prefer sellable over hard-fail)
- [x] Implement
- [x] Test

### Task 4: Prove the failing paths and preserve PR #404's balanced-COGS happy path
- **spec_ref**: `openspec/changes/block-unsellable-stock-dispatch/specs/block-unsellable-stock-dispatch/spec.md#requirement-req-blk-002`
- **files**: `tests/Unit/Lifecycle/LotSellabilityGuardTest.php`, `tests/Unit/Service/SalesDispatchStockIssueServiceTest.php`, `tests/Unit/Listener/DeliveryDispatchListenerTest.php`
- **acceptance_criteria**:
  - GIVEN a quarantined lot / a lot marked `expired` / an `active` lot past `expiryDate` THEN each is BLOCKED (no move issued)
  - GIVEN a clean active non-expired lot with sufficient quantity THEN one posted `issue` `StockMove` is created
  - GIVEN the existing `DeliveryDispatchListenerTest` (no lots seeded) THEN the balanced-COGS integration path still passes
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests — N/A, no HTTP endpoint changes
- UI changes covered by Playwright browser tests — N/A, no UI surface (backend dispatch-guard logic only)
- All tests pass (`vendor/bin/phpunit` in the PHP 8.3 container)
- Feature documentation updated in `docs/` if user-facing (ADR-010) — N/A, internal dispatch-integrity logic, no user-facing feature
- Dutch (`nl_NL`) and English (`en_US`) strings added for any new user-facing strings (ADR-007) — block reasons carry EN + NL (`reason`/`reasonNl`); no new translated UI copy
- `openspec validate` passes
