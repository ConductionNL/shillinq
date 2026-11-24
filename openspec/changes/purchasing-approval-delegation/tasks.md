# Tasks: purchasing-approval-delegation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Approval on OpenRegister

- [x] 1.1 Add the `purchase-order-approval` `x-openregister-approval-chains` block on `PurchaseOrder.approve` with the three tiers and separation of duties, and create the three groups in the setup wizard (REQ-PAD-001). Verify: a contract test like `VerplichtingApprovalChainFragmentTest` against the OpenRegister shape; a live re-import provisions the steps for a seed order.
  - Built: the block on `PurchaseOrder` (cumulative tiers on `totalExclVat` in cents, separation of duties, `advanceTransition`), the three roles mapped to the groups `shillinq_teamleider`, `shillinq_facility_manager` and `shillinq_procurement_manager` in `authorization.roles` the way the commitment chain maps its roles, and `tests/Unit/Settings/PurchaseOrderApprovalChainFragmentTest.php`. No setup wizard in shillinq creates groups today, so the groups are named, not created; the live re-import is still to do.
- [x] 1.2 Replace the chain display in `PurchaseOrderDetail.vue` with an approval panel on OpenRegister's steps, with Approve and Reject for a user who may decide (REQ-PAD-001). Verify: Playwright approves PO-2026-040 as Karin de Wit and sees the requester refused on PO-2026-041.
  - Built as manifest configuration, not a hand-built panel: the `PurchaseOrderDetail` page (a declarative `detail` page; the custom `PurchaseOrderDetail.vue` is not routed by it) gets an Approval sidebar tab with the shared `tasks` widget over the order's OpenRegister flow tasks (`objectUuid: @objectId`, table, finished steps included), which shows each step's role, state, handler and deadline and offers the step's outcomes to the user who may decide it; the server refuses the requester and anyone outside the step's group. Strings in `l10n/en.json` and `l10n/nl.json`. The Playwright run is still to do.
- [x] 1.3 Remove `PurchaseOrderApprovalService`, its controller and route, `determineApprovalChain()`, `initialiseApprovalChainEntries()`, `blockSendUntilApproved()` and the `delegated` value; point `previewApprovalChain()` at the schema tiers and `send()` at `statusCode`; add the repair step for `pending_approval` orders (REQ-PAD-001). Verify: hydra gates orphan-auth and route-reachability pass; the repair step is idempotent on a local instance; a live approval of a seed order materialises its `Commitment`.
  - Built: the service, its interface, controller, route, MCP tool `shillinq.purchase_order.decide`, `PurchaseOrderApprovalGuard` and `PurchaseOrderApprovalEntry` are gone; `blockSendUntilApproved()` became `markSent()`, which waits for `statusCode` approved; `previewApprovalChain()` reads the schema tiers through `PurchaseOrderApprovalTiers`; the lock listener now refuses any user write of the `approvalChain` history; repair step `ResetPendingApprovalPurchaseOrders` with unit tests. The hydra gates and the live runs (repair on an instance, a seed order materialising its `Commitment`) are still to do.
- [x] 1.4 Add the `supplier-invoice-approval` chain on `SupplierInvoice.approve` with amount tiers and let `ThreeWayMatchingEngine` hand matched invoices to it instead of approving them, with the approval panel on `SupplierInvoiceDetail.vue` (REQ-PAD-004). Verify: PHPUnit for both scenarios; Playwright approves a large matched invoice.
  - Built: the `supplier-invoice-approval` chain on `SupplierInvoice.approveMatched` (a controller from EUR 10.000 on `totalInclVat`, separation of duties), its contract test, and an Approval tab on `SupplierInvoiceDetail` with the shared `tasks` widget. `ThreeWayMatchingEngine` now hands every matched invoice to `approveMatched` and lets OpenRegister's gate decide: below the tier the match approves it, from the tier on the write is refused with `approval-chain-pending` and the invoice stays matched (so a payment run leaves it out) until the controller approves. `SchemaRecordStore` keeps the cause of a failed save so the engine can tell that refusal apart. PHPUnit: `ThreeWayMatchingEngineApprovalHandOverTest` (EUR 18.547 waits, EUR 450 is approved, another refusal stays matched and is logged, an exception is not handed over). Playwright: `tests/e2e/purchasing-approval-delegation.spec.ts` is written; the live run is still to do. An invoice whose exception is accepted still goes through the guarded manual `approveException` transition, which the chain does not gate.

## 2. Delegation

- [ ] 2.1 After OpenRegister's `flow-approval-consolidation` is on the instance, add "Hand to a colleague" with a mandate to the approval panel, calling the task `delegate` verb, and show "on behalf of" on decided steps (REQ-PAD-002). Verify: Playwright hands the step to Karin de Wit and sees the on-behalf-of line; an empty mandate is refused.
  - Left: OpenRegister has `POST /api/flow-tasks/{uuid}/delegate` with a mandate and `on_behalf_of`, but the shared `tasks` widget of @conduction/nextcloud-vue offers no delegate verb; the hand-over belongs in that widget (a library change), not in a hand-built shillinq panel. The Playwright scenario is in `tests/e2e/purchasing-approval-delegation.spec.ts` as a `fixme` until the widget has the verb.
  - Dependency built (24 Nov 2026): ConductionNL/nextcloud-vue#2314 adds `content.delegate` to the `tasks` widget (Hand to a colleague with a mandate, on behalf of shown in the table). Left: once a release carries it, raise the dependency, set `delegate: true` on the Approval tabs and turn the `fixme` in `tests/e2e/purchasing-approval-delegation.spec.ts` into a test.

## 3. Audit

- [ ] 3.1 Add decider, on-behalf-of and mandate columns to the purchase order audit export (REQ-PAD-003). Verify: PHPUnit on the exporter with a delegated and a direct decision.
  - Left: OpenRegister publishes no PHP contract to read an object's approval tasks (decider, `onBehalfOf`, `mandate`), so the audit export cannot read them server side yet; it still lists the decisions in the order's `approvalChain` history.
  - Dependency built (24 Nov 2026): ConductionNL/openregister#11045 publishes `ObjectTaskReaderInterface::tasksOnObject()` with `ObjectTaskRecord` (decidedBy, onBehalfOf, mandate), left for the OpenRegister integrator. Left: once it is released, read it in the purchase order audit export and add the PHPUnit test with a delegated and a direct decision.

## 4. Platform request

- [ ] 4.1 Open an issue on ConductionNL/openregister for a standing delegation on the task performer model (a person's dated rule routing their tasks in a scope to a stand-in), linking this change and the tender row. Verify: the issue link is in the PR body; the issue text is shown to Ruben before it is posted.
  - Left: the issue text has to be shown to Ruben before it is posted.

## 5. Docs

- [x] 5.1 Release note: purchase order approval now works and runs on OpenRegister, single hand-overs are possible, and an absence period is not yet. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/purchasing-approval-delegation/tasks.md#task-N` on every new method, Dutch and English strings for every label.
