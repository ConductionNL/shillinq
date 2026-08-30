# Tasks: grir-accrual-wiring

## Implementation Tasks

### Task 1: GRIRClearingService orchestration methods
- **spec_ref**: `openspec/changes/grir-accrual-wiring/specs/grir-accrual-wiring/spec.md#req-001`, `#req-002`, `#req-003`
- **files**: `lib/Service/GRIRClearingService.php`
- **acceptance_criteria**:
  - GIVEN a GoodsReceiptNote with 2 accepted GoodsReceiptLine rows and 1 zero-quantity row WHEN `postGRIRForGoodsReceiptAccept()` runs THEN exactly 2 `createGRIRPosting()` calls post and the zero-quantity line is skipped
  - GIVEN a SvcReceipt with accepted SvcReceiptLine rows WHEN `postGRIRForServiceReceiptAccept()` runs THEN each posts via `createGRIRPosting()` with `grnNumber`/`receivedAt` normalised from `receiptNumber`/`periodEnd`
  - GIVEN an invoiceId with one `auto_approved` ThreeWayMatch WHEN `settleGRIRForMatchedInvoice()` runs THEN `settleGRIRPosting()` is called with that match; GIVEN no approved match exists THEN it returns `posted: false` without throwing
- [x] Implement
- [x] Test

### Task 2: GRIRClearingListener + Application.php wiring
- **spec_ref**: `openspec/changes/grir-accrual-wiring/specs/grir-accrual-wiring/spec.md#req-001`, `#req-002`, `#req-003`, `#req-004`
- **files**: `lib/Listener/GRIRClearingListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a `GoodsReceiptNote` ObjectTransitionedEvent to `accepted` WHEN the listener handles it THEN `postGRIRForGoodsReceiptAccept()` is invoked with the transitioned object
  - GIVEN a `SvcReceipt` ObjectTransitionedEvent to `accepted` WHEN the listener handles it THEN `postGRIRForServiceReceiptAccept()` is invoked
  - GIVEN a `SupplierInvoice` ObjectTransitionedEvent to `matched` WHEN the listener handles it THEN `settleGRIRForMatchedInvoice()` is invoked with the invoice id
  - GIVEN any other schema/transition WHEN the listener handles it THEN no `GRIRClearingService` method is called
  - GIVEN the downstream service throws WHEN the listener handles the event THEN the exception is logged and not rethrown (fail-soft, matching `DeliveryDispatchListener`)
- [x] Implement
- [x] Test

### Task 3: End-to-end correctness test — balanced GL on accept + match
- **spec_ref**: `openspec/changes/grir-accrual-wiring/specs/grir-accrual-wiring/spec.md#req-001`, `#req-003`
- **files**: `tests/Unit/Listener/GRIRClearingListenerTest.php`
- **acceptance_criteria**:
  - GIVEN this test is run against pre-change code WHEN a GoodsReceiptNote is accepted THEN no GLTransaction exists (fails on HEAD before this change)
  - GIVEN this test is run against post-change code WHEN the same GRN is accepted THEN a balanced clearing GLTransaction posts (Dr PO-line account / Cr GR/IR clearing), and WHEN the matching SupplierInvoice subsequently transitions to `matched` THEN a balanced settlement GLTransaction posts and the GR/IR clearing account nets to zero across both postings
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — GRIRClearingServiceTest (+3 orchestration tests) and new GRIRClearingListenerTest (dispatch + fail-soft + end-to-end balanced-GL proof)
- New/changed API endpoints covered by Newman/Postman tests — N/A, no new HTTP endpoints
- UI changes covered by Playwright browser tests — N/A, no new UI surface
- All tests pass (`vendor/bin/phpunit -c phpunit-unit.xml` in the PHP 8.3 container) — full unit suite green, delta-zero against the documented baseline
- Feature documentation updated in `docs/` if user-facing (ADR-010) — N/A, internal accounting-trigger wiring only; the capability itself (GR/IR clearing) was already documented when member 09 shipped
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007) — N/A, no new user-facing strings (posting descriptions reuse the existing English-only format `createGRIRPosting()`/`settleGRIRPosting()` already produce)
- `openspec validate` passes — confirmed
