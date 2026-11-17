# Tasks: enforced-schema-authorization

## 1. Authorization blocks
- [x] 1.1 Purchasing and inventory schemas
- [x] 1.2 Bank, payments, payment-run and expense schemas
- [x] 1.3 Ledger, journals, budget, BBV, closing and intercompany schemas
- [x] 1.4 Payroll schemas
- [x] 1.5 Reporting, report schedules, consolidation and IFRS schemas
- [x] 1.6 Tax schemas
- [x] 1.7 Audit, compliance and public-sector schemas

## 2. State guards
- [x] 2.1 An approved payment run's lines and amounts are immutable (`PaymentRunLineLockListener`)
- [x] 2.2 Releasing a payment block needs a role and a reason, and keeps the block reason: `PaymentBlockReleaseListener` refuses, on the save path of an APTransaction or Payee, a release (`paymentBlocked` true to false) by a caller who is neither an administrator nor in `shillinq_controller` or `shillinq_treasurer`, a release without a new `paymentBlockReleaseReason`, and a release that changes `paymentBlockReason`. The release dialog asks the reason and leaves the block reason alone. `PaymentBlockReleaseListenerTest` (7 tests)
- [x] 2.3 The payment-run `reconcile` transition only follows a CAMT.053 match (`PaymentRunReconcileGuard`)
- [x] 2.4 Purchase order `approve` only follows a complete approval chain signed through the approval service (`PurchaseOrderApprovalGuard`, `PurchaseOrderApprovalChainListener`)
- [x] 2.5 Supplier invoice `approveException` only follows an accepted three-way-match exception, and `pay` only a paid AP transaction (`SupplierInvoiceExceptionApprovalGuard`, `SupplierInvoicePayGuard`)
- [x] 2.6 Accepting a three-way-match exception needs the exception approver role: `ThreeWayMatchExceptionController::accept()` refuses with 403, before reading anything, a caller who is neither an administrator nor in `shillinq_approver` or `shillinq_controller` (`EXCEPTION_APPROVER_GROUPS`); dispute and reject are unchanged. `ThreeWayMatchExceptionControllerTest::testAcceptRefusesACallerWhoIsNoExceptionApprover`, `::testAControllerOrAnAdministratorMayAccept`, `::testDisputeAndRejectNeedNoApproverRole`
- [x] 2.7 The BBV allocation rules are declared under `x-openregister-validations`, the key OpenRegister reads
- [x] 2.8 A report schedule's recipients, status and next run are written by its owner only, and delivery skips recipients without access to the administration (`ReportScheduleWriteListener`, `ReportRecipientPolicy`)
