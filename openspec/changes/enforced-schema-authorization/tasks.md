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
- [x] 2.2 Releasing a payment block needs the controller or treasurer role and a release reason, and keeps the block reason (`PaymentBlockReleaseListener`)
- [x] 2.3 The payment-run `reconcile` transition only follows a CAMT.053 match (`PaymentRunReconcileGuard`)
- [x] 2.4 Purchase order `approve` only follows a complete approval chain signed through the approval service (`PurchaseOrderApprovalGuard`, `PurchaseOrderApprovalChainListener`)
- [x] 2.5 Supplier invoice `approveException` only follows an accepted three-way-match exception, and `pay` only a paid AP transaction (`SupplierInvoiceExceptionApprovalGuard`, `SupplierInvoicePayGuard`)
- [ ] 2.6 Accepting a three-way-match exception needs the exception approver role: done in #2072, not here
- [x] 2.7 The BBV allocation rules are declared under `x-openregister-validations`, the key OpenRegister reads
- [x] 2.8 A report schedule's recipients, status and next run are written by its owner only, and delivery skips recipients without access to the administration (`ReportScheduleWriteListener`, `ReportRecipientPolicy`)
