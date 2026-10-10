# Tasks: purchasing-approval-delegation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Approval on OpenRegister

- [ ] 1.1 Add the `purchase-order-approval` `x-openregister-approval-chains` block on `PurchaseOrder.approve` with the three tiers and separation of duties, and create the three groups in the setup wizard (REQ-PAD-001). Verify: a contract test like `VerplichtingApprovalChainFragmentTest` against the OpenRegister shape; a live re-import provisions the steps for a seed order. Built: block in the fragment, `PurchaseOrderApprovalTiers`, groups in the setup seed action, `totalExclVat` written on create; tests `tests/Unit/Settings/PurchaseOrderApprovalChainFragmentTest.php`, `tests/Unit/Service/Purchasing/PurchaseOrderApprovalTiersTest.php`, `SetupControllerTest`, `PurchaseOrderRegisterSchemaTest`. (live pass, decision 139: the re-import)
- [ ] 1.2 Replace the chain display in `PurchaseOrderDetail.vue` with an approval panel on OpenRegister's steps, with Approve and Reject for a user who may decide (REQ-PAD-001). Verify: Playwright approves PO-2026-040 as Karin de Wit and sees the requester refused on PO-2026-041.
- [ ] 1.3 Remove `PurchaseOrderApprovalService`, its controller and route, `determineApprovalChain()`, `initialiseApprovalChainEntries()`, `blockSendUntilApproved()` and the `delegated` value; point `previewApprovalChain()` at the schema tiers and `send()` at `statusCode`; add the repair step for `pending_approval` orders (REQ-PAD-001). Verify: hydra gates orphan-auth and route-reachability pass; the repair step is idempotent on a local instance; a live approval of a seed order materialises its `Commitment`. Built: service, controller, route and tests removed; `PurchaseOrderService::markSent()` and the transmit paths read `statusCode`; preview reads `PurchaseOrderApprovalTiers`; create writes no chain, no ApprovalTask, no notification (OpenRegister's task inbox notifies); `lib/Repair/MovePendingApprovalOrdersToDraft.php` + `tests/Unit/Repair/MovePendingApprovalOrdersToDraftTest.php` (run twice, idempotent in the unit test). (live pass, decision 139: repair on an instance, gates, the Commitment)

## 2. Delegation

- [ ] 2.1 After OpenRegister's `flow-approval-consolidation` is on the instance, add "Hand to a colleague" with a mandate to the approval panel, calling the task `delegate` verb, and show "on behalf of" on decided steps (REQ-PAD-002). Verify: Playwright hands the step to Karin de Wit and sees the on-behalf-of line; an empty mandate is refused.

## 3. Audit

- [ ] 3.1 Add decider, on-behalf-of and mandate columns to the purchase order audit export (REQ-PAD-003). Verify: PHPUnit on the exporter with a delegated and a direct decision.

## 4. Platform request

- [x] 4.1 Open an issue on ConductionNL/openregister for a standing delegation on the task performer model (a person's dated rule routing their tasks in a scope to a stand-in), linking this change and the tender row. Verify: the issue link is in the PR body; the issue text is shown to Ruben before it is posted. Done: Ruben approved the text (Q-shillinq-4) and it is https://github.com/ConductionNL/openregister/issues/4527 .

## 5. Docs

- [ ] 5.1 Release note: purchase order approval now works and runs on OpenRegister, single hand-overs are possible, and an absence period is not yet. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/purchasing-approval-delegation/tasks.md#task-N` on every new method, Dutch and English strings for every label.
