# Tasks: purchasing-call-offs-and-reorder-proposals

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 3. -->

## 1. Build

- [x] 1.1 Agreement picker on `PurchaseOrderForm.vue` sending `frameworkAgreementId`, and agreed prices applied in `PurchaseOrderService` (REQ-PG-005). Verify: PHPUnit for price and ceiling; Vitest on the form.
- [x] 1.2 A `ReorderProposalJob` over the reorder rules that writes the alert and the draft purchase order (REQ-IRA-011). Verify: PHPUnit red first; `appinfo/info.xml` lists the job.
- [ ] 1.3 English and Dutch strings, then live: order against a seed agreement; lower a seed stock under its point and see the draft order.
  - Call-off half written (24 Nov 2026): `tests/e2e/purchasing-call-offs-and-reorder-proposals.spec.ts` orders 20 boxes against an agreement of its own and expects the agreed EUR 42 and the ceiling drawn (REQ-PG-005); writing it found that `PurchaseOrderController::orderPayload()` dropped `frameworkAgreementId` and `requisitionId`, fixed with `PurchaseOrderControllerTest::testCreatePassesTheFrameworkAgreementAndRequisition`. Left: the reorder half (REQ-IRA-011) is `test.fixme`, `ReorderProposalJob` has no route the nightly can start.
  - Live run (25 Nov 2026, sandbox Nextcloud 34 + OpenRegister main, MariaDB): the call-off spec failed with 400. Three causes, in order: (1) `FrameworkAgreementDrawdownGuard::findOne()` kept only array rows while OpenRegister's `findAll()` answers `ObjectEntity` rows, so every call-off ended in "Framework agreement not found". Fixed here: rows are read through `jsonSerialize()`, with `FrameworkAgreementDrawdownGuardTest::testAgreementRowsAsEntitiesAreRead` (red without the fix). (2) `PurchaseOrderService::assignApprovalTask()` saves to an `ApprovalTask` schema that no register fragment declares ("Failed to persist ApprovalTask"), so no purchase order can be created on a live instance. Approval now runs through OpenRegister's approval chain (REQ-PAD-001), so the in-app task write needs a decision on whether to drop it or declare the schema; it touches the approval flow and is left for that. (3) The stored `PurchaseOrder` schema (0.1.0) does not declare `requesterId`, `frameworkAgreementId`, `lines`, `totalAmount`, `createdAt` or `notes`, so OpenRegister discards them on save. The call-off check stays open until (2) and (3) are built.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
