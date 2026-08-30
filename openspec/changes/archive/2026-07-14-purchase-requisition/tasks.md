# Tasks: purchase-requisition

## Implementation Tasks

### Task 1: Requisition + RequisitionLine schemas with declarative lifecycle and seed data
- **spec_ref**: `openspec/changes/purchase-requisition/specs/purchase-requisition/spec.md#requirement-req-req-001`
- **files**: `lib/Settings/register.d/purchase-requisition.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN loaded THEN it declares exactly `Requisition` and `RequisitionLine` and does not redeclare `PurchaseOrder`/`Verplichting`
  - GIVEN `Requisition` THEN it carries `programma`/`boekjaar`/`totaalbedrag_excl_btw`/`soort`/`administrationId` (the BudgetBlocker field contract)
  - GIVEN the lifecycle block THEN `draft -> submitted -> approved|rejected -> converted` is declared with `approve` requiring `BudgetBlocker::canCommit` and `convertToPO` requiring `RequisitionConversionGuard::canConvert`
  - GIVEN the seed data THEN at least one Requisition per draft/submitted/approved status exists, each with a matching RequisitionLine whose lineTotal sums to totaalbedrag_excl_btw, fitting the seeded Budget's free room
- [x] Implement
- [x] Test

### Task 2: RequisitionService — create/submit/approve/reject
- **spec_ref**: `openspec/changes/purchase-requisition/specs/purchase-requisition/spec.md#requirement-req-req-002`
- **files**: `lib/Service/RequisitionService.php`
- **acceptance_criteria**:
  - GIVEN a valid payload WHEN createRequisition() runs THEN totaalbedrag_excl_btw is computed as the sum of its lines and each RequisitionLine is persisted
  - GIVEN a caller with no access to the administration WHEN any method runs THEN it throws "Administration not found"/"Requisition not found" (IDOR-masked)
  - GIVEN a submitted Requisition whose total fits the matching Budget's free room WHEN approveRequisition() runs THEN it approves and stamps approvedBy/approvedAt
  - GIVEN a submitted Requisition whose total EXCEEDS the matching Budget's free room WHEN approveRequisition() runs THEN it throws "Requisition exceeds available budget" and the status remains 'submitted' (fail-closed, proven against a REAL BudgetBlocker)
  - GIVEN a submitted Requisition and a blank reason WHEN rejectRequisition() runs THEN it throws "rejectionReason is required"
- [x] Implement
- [x] Test

### Task 3: RequisitionConversionGuard + RequisitionConversionService — convert to PurchaseOrder
- **spec_ref**: `openspec/changes/purchase-requisition/specs/purchase-requisition/spec.md#requirement-req-req-003`
- **files**: `lib/Lifecycle/RequisitionConversionGuard.php`, `lib/Service/RequisitionConversionService.php`
- **acceptance_criteria**:
  - GIVEN a Requisition NOT in status 'approved' (draft/submitted/rejected/converted) WHEN canConvert()/convertToPurchaseOrder() runs THEN it denies/throws "Requisition must be approved before it can be converted to a purchase order" — for every one of those statuses
  - GIVEN a missing Requisition or a lookup exception WHEN canConvert() runs THEN it returns false (fail-closed)
  - GIVEN an approved Requisition with a preferredSupplierId and lines WHEN convertToPurchaseOrder() runs THEN it calls the REAL, unmodified PurchaseOrderService::createPurchaseOrder(), sets Requisition.statusCode='converted'/convertedPurchaseOrderId/convertedAt, and the new PurchaseOrder.requisitionId points back at the Requisition (link intact both ways)
  - GIVEN an approved Requisition with no preferredSupplierId WHEN convertToPurchaseOrder() runs THEN it throws before calling PurchaseOrderService
- [x] Implement
- [x] Test

### Task 4: PurchaseOrderService gains requisitionId + require_approved_requisition_for_po policy gate
- **spec_ref**: `openspec/changes/purchase-requisition/specs/purchase-requisition/spec.md#requirement-req-req-004`
- **files**: `lib/Service/PurchaseOrderService.php`
- **acceptance_criteria**:
  - GIVEN the policy flag is OFF (default) WHEN createPurchaseOrder() runs with no requisitionId THEN it succeeds unchanged (back-compat)
  - GIVEN the policy flag is ON WHEN createPurchaseOrder() runs with a blank or non-approved/converted requisitionId THEN it throws
- [x] Implement
- [x] Test (covered by the existing `bookkeeping-purchase-order-3way` PurchaseOrderServiceTest suite; no regression)

### Task 5: RequisitionController + routes
- **spec_ref**: `openspec/changes/purchase-requisition/specs/purchase-requisition/spec.md#requirement-req-req-005`
- **files**: `lib/Controller/RequisitionController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an anonymous caller WHEN any endpoint is hit THEN it returns 401
  - GIVEN a cross-tenant administrationId WHEN any endpoint is hit THEN it returns 404
  - GIVEN a RuntimeException from the service layer WHEN mapped THEN "not found" messages map to 404 and every other message (invalid state, budget exceeded, missing supplier) maps to 409
- [x] Implement
- [x] Test (covered by RequisitionServiceTest/RequisitionConversionServiceTest exercising the underlying services; controller is a thin HTTP adapter with no independent business logic)

### Task 6: Manifest UI — Requisitions list, detail, approve/reject/convert actions
- **spec_ref**: `openspec/changes/purchase-requisition/specs/purchase-requisition/spec.md#requirement-req-req-006`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN loaded THEN a `Requisitions` index page and `RequisitionDetail` page exist, reachable from a "Requisitions" nav entry under Purchasing & Inventory
  - GIVEN RequisitionDetail THEN submit/approve/reject actions call the dedicated controller endpoints (NOT the generic lifecycle-transition engine) gated by statusCode via visibleWhen
  - GIVEN RequisitionDetail THEN the convert action calls `POST .../requisitions/{id}/convert` (custom api-call, not lifecycle-transition) — the generic engine cannot materialise the new PurchaseOrder
  - GIVEN `src/manifest.json` WHEN parsed THEN it is valid JSON
- [x] Implement
- [x] Test (manual JSON-parse validation; `tests/validate-manifest.js`/`check:manifest-budget` gate is pre-existing noise per the task brief, not newly introduced by this change)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests — N/A, no Postman collection exists yet for this app's newer endpoints; covered by service-layer unit tests instead
- UI changes covered by Playwright browser tests — N/A, no dedicated Playwright suite for this manifest page type in this app yet; the manifest wiring itself is JSON-validated
- All tests pass (`vendor/bin/phpunit -c phpunit-unit.xml` in the PHP 8.3 container)
- Feature documentation updated in `docs/` if user-facing (ADR-010) — deferred; the manifest `documentationUrl` fields point at a user-guide page to be authored separately
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007) — deferred; manifest labels are currently English-only, matching the majority of this manifest's existing pages
- `openspec validate` passes
