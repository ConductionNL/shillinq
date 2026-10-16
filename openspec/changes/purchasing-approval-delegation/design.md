# Design: purchasing-approval-delegation

Read at shillinq development `79f438f33` and openregister development on
2026-09-27.

## Context

**The purchase order chain today, read end to end.**
`PurchaseOrderService::createPurchaseOrder()` computes a chain with
`determineApprovalChain()` (`lib/Service/PurchaseOrderService.php:379`:
teamleider from EUR 0.01, facility_manager from EUR 10,000, procurement_manager
from EUR 50,000) and stores it on the order through
`initialiseApprovalChainEntries()` (line 839) as entries with `role`,
`order`, `status` pending, `signedAt` and `signedBy`. It sets
`lifecycleState` to `pending_approval` (line 322), a field and a state the
schema does not declare; the declared lifecycle field is `statusCode`.

`PurchaseOrderApprovalService::recordApprovalDecision()`
(`lib/Service/PurchaseOrderApprovalService.php:179`), reached by
`POST /api/purchase-orders/{id}/approval-decision` (`appinfo/routes.php:365`),
looks for the first entry whose `decision` is pending. No entry has a
`decision` field, so it throws "Approval chain is fully signed" on every
call. It stamps the session user and checks no role. It accepts the
decisions approved, rejected and `delegated`; the last "chains the slot to
another role; not advanced here" and has no follow-up. No Vue component
calls the endpoint.

`PurchaseOrderService::blockSendUntilApproved()` (line 418) requires every
entry to have `status` approved and a `signedAt`, which nothing writes.
`PurchaseOrderDetail.vue` shows the chain from the same `status` and
`signedAt` fields (lines 94 to 123, 297 to 302). So an order that has a
chain can never be approved through it. The matrix note's "no delegation
concept anywhere" misses the `delegated` value, which is inert.

**OpenRegister, on development.** `x-openregister-approval-chains` is a
real declarative capability: `ApprovalChainAnnotationInstaller` provisions
the steps from the schema, `ApprovalChainGateListener` blocks the declared
transition until every step is approved with amount tiers and separation of
duties, `ApprovalChainAdvanceListener` releases it. Step decisions go
through `POST /api/approval-steps/{id}/approve` and `/reject`, allowed only
for members of the step's group (`openspec/specs/approval-workflow/spec.md`
REQ-005). shillinq already uses it on `Commitment.goedkeuren`
(`lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json`) and
on `BcfClaim`.

The approval step itself has no delegation. OpenRegister's open change
`flow-approval-consolidation` says so ("no claim, no delegation, no
`on_behalf_of`") and moves steps onto the task service. That service is on
development: `lib/Service/Task/TaskService.php:470`
`delegate(uuid, delegate, mandate, actor)` hands a task to a delegate,
refuses an empty mandate, records `on_behalf_of` and `mandate`, and audits
the action. OpenRegister's `DelegationGrant` (`lib/Db/DelegationGrant.php`)
is a different thing: who may run work as another user's identity, for
flows and agents (`or-delegation-grants`). No standing absence rule exists
in OpenRegister.

## Goals / Non-Goals

**Goals**

- A purchase order above zero is approved by the people its amount requires, from its page, and cannot be sent before.
- An approver can hand one pending approval to a colleague, and the record says on whose behalf it was decided.
- shillinq keeps no approval engine of its own.

**Non-Goals**

- A shillinq-side absence calendar or stand-in store.
- Changing the three thresholds.

## Decisions

### D1. Declare the chain on the schema

`PurchaseOrder` gets an `x-openregister-approval-chains` block
`purchase-order-approval` on transition `approve`, `amountField`
`totalAmount` in cents, `separationOfDuties` true, and approvers teamleider
(min 1, from 1 cent), facility_manager (from 1,000,000 cents) and
procurement_manager (from 5,000,000 cents). An order below the first tier
has no steps and approves directly.

Alternative considered: repair the in-object chain (one field vocabulary,
a role check, a screen). Rejected: it would be a second approval engine
beside OpenRegister's, exactly the kind `migrate-mandaat-to-approval-chains`
retired for commitments, and it would not gain delegation when OpenRegister
does.

### D2. The order page acts on OpenRegister's steps

`PurchaseOrderDetail.vue` lists the order's steps from OpenRegister with
role, state, decider and time, and shows Approve and Reject to a user who
may decide the pending step. The send buttons stay hidden until the order's
`statusCode` is approved.

### D3. Delegation is OpenRegister's verb, surfaced on the order

Once `flow-approval-consolidation` lands, a pending step is a task. The
order page offers "Hand to a colleague" to the user who may decide it,
asking for the colleague and a mandate text, and calls OpenRegister's task
`delegate`. The decider shown on the order is then "Karin de Wit on behalf
of Jeroen Bakker", from `on_behalf_of`. Separation of duties is
OpenRegister's and applies to the delegate: the order's requester cannot
decide it as a delegate.

Alternative considered: a shillinq `ApprovalStandIn` schema that reassigns
steps during an absence. Rejected: approvals are a platform capability
shared by every app, OpenRegister already holds the delegation verb, and a
shillinq-only stand-in would not cover commitments, dossiq or decidiq.

### D4. Retire the dead path

`PurchaseOrderApprovalService`, its controller and route,
`initialiseApprovalChainEntries()` and the `delegated` enum value are
removed. `PurchaseOrderController::previewApprovalChain()`
(`GET /api/purchase-orders/approval-chain`, line 194) reads the tiers from
the schema declaration instead of `determineApprovalChain()`, which goes.
`PurchaseOrderController::send()` (`POST /api/purchase-orders/{id}/send`,
line 235) stops calling `blockSendUntilApproved()`, which goes, and relies
on the order's `statusCode` being approved. The `approvalChain` property
stays readable for history and is no longer written. A repair step moves
orders whose `lifecycleState` is `pending_approval` to `statusCode` draft.

One consequence to name: `CommitmentMaterialisationListener` materialises
a `Commitment` when a purchase order's `statusCode` becomes approved
(REQ-VPL-010). No order has reached that state through approval so far,
so the listener starts firing with this change, and an order that exceeds
its budget is then refused at approval as REQ-VPL-010 intends.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Who must approve an order | Declarative `x-openregister-approval-chains` | OpenRegister's capability, already used for commitments. |
| Blocking approval until the chain completes | Declarative, OpenRegister's gate listener | Same. |
| Handing a step to a colleague | OpenRegister `TaskService::delegate` | The verb exists in the platform. |
| The approval panel | Vue component on OpenRegister's step and task API | Presentation only. |

## Seed Data

No schema property is added; one declaration is added and one enum value
removed. Test data for "Gemeente Voorbeeld": order PO-2026-040 of EUR
12,500 (teamleider and facility_manager steps), requested by Jeroen Bakker;
Karin de Wit in group facility_manager; order PO-2026-041 of EUR 450 (one
teamleider step).

## Risks / Trade-offs

- [Group names] → the roles become Nextcloud groups that must exist; the setup wizard creates the three with the same names the service used.
- [No standing stand-in yet] → named in the proposal's Open Questions and in the release note, so nobody believes it exists.

## Migration Plan

A repair step moves orders in `lifecycleState` `pending_approval` to draft.
No other data changes.

## Open Questions

- The standing delegation in OpenRegister (proposal, Open Questions).
