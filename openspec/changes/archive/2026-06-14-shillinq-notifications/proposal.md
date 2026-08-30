---
kind: config
depends_on:
  - notification-updated-field-change-condition
  - add-shillinq-accounts-receivable-core
  - add-shillinq-accounts-payable-core
  - bookkeeping-purchase-order-3way
---

# Shillinq Notifications

## ⚠ BLOCKED until the chained schema changes merge — read first

The financial schemas these notification rules attach to are created by
**other, still-unmerged Shillinq changes**, not by this one. Notification rules
are declared as `x-openregister-notifications` annotations **on a schema's
properties**, so there is nothing to attach to until those schemas land. This
change is therefore `depends_on`-chained to the changes that own the real
schemas (see frontmatter) and **cannot be applied until they merge**:

- `add-shillinq-accounts-receivable-core` → creates **`CustomerMaster`,
  `ARInvoice`, `DunningRecord`** (sales / AR invoicing). `ARInvoice` carries a
  `state` enum (`draft → issued → partially-paid → paid` / `overdue` /
  `disputed` / `written-off` / `voided`) and a `dueDate` date field.
- `add-shillinq-accounts-payable-core` → creates **`VendorMaster`, `APInvoice`,
  `PaymentRun`** (purchase invoices + payment runs). `APInvoice` carries a
  `state` enum (`draft → pending → approved → posted → paid` / `disputed` /
  `voided`), a separate `approvalState` enum (`not-required` / `pending` /
  `approved` / `rejected`) and a `dueDate`. `PaymentRun` carries a `state` enum
  (`draft → ready → submitted → executed` / `failed`).
- `bookkeeping-purchase-order-3way` → creates **`PurchaseOrder`** (plus
  `PurchaseOrderLine`, `GoodsReceiptNote`, `SupplierInvoice`, `ThreeWayMatch`,
  …). `PurchaseOrder` carries a `status` enum (`draft → approved → sent →
  partial_received → fully_received → invoiced → closed` / `cancelled`) and a
  `requester` field (FK to Person — the employee that initiated the PO).

The earlier draft of this change targeted generic `Invoice` / `PurchaseOrder` /
`Contract` / `Payment` schemas with invented `ownerUid` / `approverId` fields.
**Those schemas and fields do not exist.** This revision re-targets the rules
onto the real schema slugs and real field names listed above. See `## Caveats`
for the dropped contract rule and the purchase-order recipient note.

## Why

The fleet notification analysis (`hydra/openspec/fleet-notification-plan.md`,
shillinq row) recommends finance/procurement notifications: **invoice
overdue/paid, the AP approval chain, payment-run ready, and purchase-order
approval**. These keep finance and procurement users on top of money-movement
and approval deadlines without polling. Shillinq owns no notification code of
its own — it declares rules on its schemas and the OpenRegister notification
engine (`notificatie-engine`) delivers them, so this is `kind: config`
(declarative annotations only) once the chained schemas exist.

## What Changes (target state, after the chained changes merge)

Declare `x-openregister-notifications` on the real financial schemas. Subjects
are bilingual nl/en and metadata-only (invoice/PO number, due date — never line
contents). Status-change rules build on
`notification-updated-field-change-condition` so they use `updated` + a
field-change `condition` on the real `state` / `approvalState` / `status`
field. Deadline rules use `scheduled` with a filter on the real date field.

Recipient note: none of the AR/AP schemas declares a per-object owner/assignee
**uid** field, so the owner recipient is resolved via
`{"kind":"object-acl","permission":"manage"}` (the actor who can manage the
object) rather than an invented uid field. The `PurchaseOrder` schema **does**
declare a real `requester` uid field, so PO rules can use
`{"kind":"field","field":"requester"}`.

Rules (schema → trigger → recipient field):

- **`ARInvoice` overdue** — `scheduled` (intervalSec ≥ 86400) with a filter on
  `state` ≠ `paid`/`written-off`/`voided` and `dueDate` in the past →
  `{"kind":"object-acl","permission":"manage"}` + the `shillinq-finance` group.
- **`ARInvoice` paid** — `updated` + `condition`
  `{"field":"state","operator":"equals","value":"paid"}` →
  `{"kind":"object-acl","permission":"manage"}`.
- **`APInvoice` approval needed** — `updated` + `condition`
  `{"field":"approvalState","operator":"equals","value":"pending"}` →
  `{"kind":"object-acl","permission":"manage"}` + the `shillinq-finance` group
  (the eligible approver comes from OR's approval-workflow config, not a schema
  field — see Caveats).
- **`APInvoice` approved** — `updated` + `condition`
  `{"field":"approvalState","operator":"equals","value":"approved"}` →
  `{"kind":"object-acl","permission":"manage"}`.
- **`PaymentRun` ready** — `updated` + `condition`
  `{"field":"state","operator":"equals","value":"ready"}` →
  `{"kind":"object-acl","permission":"manage"}` + the `shillinq-finance` group.
- **`PurchaseOrder` submitted for approval** — `created` →
  `{"kind":"field","field":"requester"}` + the `shillinq-procurement` group.
- **`PurchaseOrder` approved** — `updated` + `condition`
  `{"field":"status","operator":"equals","value":"approved"}` →
  `{"kind":"field","field":"requester"}`.

## Capabilities

### Added Capabilities

- `notifications`: Shillinq declares finance/procurement notification rules
  (`x-openregister-notifications`) on its **`ARInvoice`, `APInvoice`,
  `PaymentRun`, and `PurchaseOrder`** schemas — AR invoice overdue/paid, AP
  approval-needed/approved, payment-run ready, PO submitted/approved — consumed
  by the OpenRegister `notificatie-engine`. **Chained on the AR/AP/PO schema
  changes landing first (see frontmatter `depends_on`).**

## Caveats

- **No `Contract` schema exists yet** anywhere in Shillinq. The contract
  renewal/expiry rule from the earlier draft is **deferred** until a contract
  is modelled (likely a future `bookkeeping-*` change). It is intentionally NOT
  included here and is NOT chained, to avoid inventing a schema.
- **AP/PO approver is not a schema field.** AP approval routing comes from OR's
  approval-workflow configuration (REQ-AP-005), and `PurchaseOrder.approval_chain`
  is an *array* of approver roles/users, not a single resolvable uid. So the
  AP approval-needed rule notifies the finance group (the pool eligible to
  approve) via a group + `object-acl manage`, and the PO submitted rule notifies
  the `requester` plus the procurement group — neither invents a single-approver
  uid field. If the AP/PO changes later add a concrete `approverUid` field, a
  follow-up can narrow these rules to `{"kind":"field","field":"approverUid"}`.
- **No per-object owner uid field on AR/AP schemas.** Owner-targeted rules use
  `{"kind":"object-acl","permission":"manage"}` rather than an invented
  `ownerUid` field.

## Impact

- **Config (Shillinq):** `x-openregister-notifications` annotations on the
  `ARInvoice`, `APInvoice`, `PaymentRun`, and `PurchaseOrder` schemas in
  `lib/Settings/shillinq_register.json` (no PHP).
- **Chained:** cannot apply until `add-shillinq-accounts-receivable-core`,
  `add-shillinq-accounts-payable-core`, and `bookkeeping-purchase-order-3way`
  land their schemas (frontmatter `depends_on`).
- **Engine dependency:** status-change rules require
  `notification-updated-field-change-condition` (archived in openregister) so
  `updated` + field-change `condition` dispatches.
- **No** new external-email channel needed — all recipients are internal uids
  (`requester`), object ACLs (`object-acl manage`), or groups.
