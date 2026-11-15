# Tasks: purchasing-call-offs-and-reorder-proposals

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 3. -->

## 1. Build

- [ ] 1.1 Agreement picker on `PurchaseOrderForm.vue` sending `frameworkAgreementId`, and agreed prices applied in `PurchaseOrderService` (REQ-PG-005). Verify: PHPUnit for price and ceiling; Vitest on the form.
- [ ] 1.2 A `ReorderProposalJob` over the reorder rules that writes the alert and the draft purchase order (REQ-IRA-011). Verify: PHPUnit red first; `appinfo/info.xml` lists the job.
- [ ] 1.3 English and Dutch strings, then live: order against a seed agreement; lower a seed stock under its point and see the draft order.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
