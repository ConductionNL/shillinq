---
kind: code
depends_on: []
---

# Proposal: purchasing-approval-delegation

## Summary

The tender asks that an approver can name a stand-in who takes over their
approvals while they are away. Reading the code first turned up a harder
fact: purchase order approval does not work at all today. The chain is
written with one set of field names and read with another, no screen calls
the decision endpoint, and the decision endpoint checks no role. This change
moves purchase order approval onto OpenRegister's declarative approval
chain, as commitments already are, and then consumes OpenRegister's
delegation of approval work for the stand-in. The standing "while I am
away" rule is a platform capability that OpenRegister does not have yet;
this change names it as a dependency rather than building a second one in
shillinq.

## Motivation

One row of the shillinq capability matrix
(`openspec/parity/capabilities.json`). The OpenSpec pass of 2026-09-27
decided `build`. This change covers it.

**`pur-approval-delegate`**, "Name a stand-in who takes over your approvals
while you are away." Rated no, built state none. The note: "No approval
delegation or stand-in concept anywhere in lib/ or src/ (searched delegate,
deputy, substitute, plaatsvervang, absence)." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. No competitor
rates it yes; odoo is partial: approvers can "delegate approval rights to
other users with defined expiration dates", only through Studio approval
rules (https://www.odoo.com/documentation/19.0/applications/studio/approval_rules.html).

The note is not quite right: `PurchaseOrderApprovalService` accepts a
decision `delegated` (`DECISION_DELEGATED`), which records the word and
routes nothing (design.md, Context).

## Affected Projects

- [ ] Project: `shillinq`: the `PurchaseOrder` schema's approval declaration, the order page's approval panel, the approval audit export, and the retirement of the in-object approval chain.

## Scope

### In Scope

- Declaring `x-openregister-approval-chains` on `PurchaseOrder`, gating its `approve` transition with the three amount tiers shillinq uses today.
- Approving and rejecting from the purchase order page through OpenRegister's approval steps.
- Handing a pending approval to a named colleague with a mandate, through OpenRegister's delegation of approval work.
- Showing, on the order and in the audit export, who decided and on whose behalf.
- Retiring `PurchaseOrderApprovalService`, the in-object `approvalChain` writes and the `delegated` decision value.

### Out of Scope

- A standing absence rule ("from Monday to Friday my approvals go to Karin"). It belongs to OpenRegister's task performer model and does not exist there yet; see Cross-Project Dependencies.
- Commitment approvals (`Commitment.goedkeuren`). They already run on OpenRegister's chain and gain the same delegation from OpenRegister without a shillinq change.
- Supplier invoice approval (`pur-invoice-approval`, deferred).

## Approach

Declare, do not implement: the chain moves to the schema, and the order page
reads and acts on OpenRegister's steps. The per-step delegation verb and its
`on_behalf_of` record come from OpenRegister. Details in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json`: an `x-openregister-approval-chains` block on `PurchaseOrder`; `approvalChain` marked read-only history.
- `lib/Service/PurchaseOrderService.php`: `determineApprovalChain()`, `initialiseApprovalChainEntries()` and `blockSendUntilApproved()` removed; creation starts in draft. `PurchaseOrderController::previewApprovalChain()` and `send()` follow.
- `CommitmentMaterialisationListener` starts firing on approved orders, as REQ-VPL-010 intended (design.md D4).
- `lib/Service/PurchaseOrderApprovalService.php`, `lib/Controller/PurchaseOrderApprovalController.php` and route `purchaseOrderApproval#decide` removed.
- `src/components/purchase-order/PurchaseOrderDetail.vue`: an approval panel on OpenRegister's steps.
- The audit export of `bookkeeping-purchase-order-3way` REQ-PO3W-010: two columns.

## Cross-Project Dependencies

- OpenRegister, available on development: `x-openregister-approval-chains` with amount tiers and separation of duties (`ApprovalChainAnnotationInstaller`, `ApprovalChainGateListener`, `ApprovalChainAdvanceListener`), and role enforcement on each step (`approval-workflow` REQ-005).
- OpenRegister, open change `flow-approval-consolidation`: moves approval steps onto the task service, whose `TaskService::delegate()` (on development) hands a task to a delegate with a mandate and records `on_behalf_of`. OpenRegister's own proposal says the current approval step has "no claim, no delegation, no on_behalf_of". REQ-PAD-002 needs this change landed.
- OpenRegister, not yet specified: a standing delegation on the task performer model, a person's rule that routes their new and pending tasks in a scope to a named stand-in between two dates. The tender row's full wording needs it. It is a platform capability every app with approvals shares, so it is requested there rather than built here.

## Risks

### Risk 1: Orders in flight lose their approval state
**Severity:** Low. **Mitigation:** the in-object chain never recorded a decision (design.md, Context), so no order has partial approvals to carry over. Orders in the undeclared state `pending_approval` are moved to draft by a repair step and get OpenRegister steps on their next submit.

### Risk 2: Delegation arrives before the consolidation
**Severity:** Medium. **Mitigation:** REQ-PAD-002 is built only after `flow-approval-consolidation` lands; until then the order page shows who may decide and nothing else. REQ-PAD-001 stands alone.

## Rollback Strategy

Remove the approval chain declaration. Orders then go from draft to
approved without a chain, which is what effectively happens today, since
the in-object chain cannot record a decision.

## Open Questions

- Will OpenRegister take the standing delegation into the task performer model (the third Cross-Project Dependency)? Until it does, the row is met for handing over single approvals, not for an absence period.
