---
kind: config
depends_on: [notification-updated-field-change-condition]
---

# Shillinq Notifications

## ⚠ BLOCKED ON DATA MODEL — read first

Shillinq's register today declares only an `Account` schema (plus the scaffold
`example`). The financial schemas this notification change targets —
**`Invoice`, `PurchaseOrder`, `Contract`, `Payment`** — **do not exist yet**.
Notification rules are declared as `x-openregister-notifications` annotations
**on a schema's properties**, so there is nothing to attach these rules to
until those schemas are modelled. **This change request therefore documents the
target state; it cannot be applied until the financial data model lands** (see
the blocking prerequisite task in tasks.md). Related unmerged Shillinq work
(`bookkeeping-accounts-receivable-core` introducing `ARInvoice`/`CustomerMaster`,
`bookkeeping-accounts-payable-core`) is the natural home for these schemas; this
change should be sequenced after whichever change establishes the canonical
financial schema names and their `status`/`dueDate`/`renewalDate`/owner fields.

## Why

The fleet notification analysis (`hydra/openspec/fleet-notification-plan.md`,
shillinq row) recommends finance/procurement notifications: **invoice
overdue/paid, purchase-order approval chain, contract renewal/expiry**. These
keep finance and procurement users on top of money-movement and contractual
deadlines without polling. Shillinq owns no notification code of its own — it
declares rules on its schemas and the OpenRegister notification engine
(`notificatie-engine`) delivers them, so this is `kind: config` (declarative
annotations only) once the schemas exist.

## Prerequisite (must land first)

Model the financial schemas with the fields the rules need:

- **`Invoice`** — `status` (e.g. `draft → issued → paid` / `overdue`),
  `dueDate`, `ownerUid` (issuer/finance owner).
- **`PurchaseOrder`** — `status` (`draft → submitted → approved → rejected`),
  `approverId`, `ownerUid`.
- **`Contract`** — `status`, `renewalDate` / `expiryDate`, `ownerUid`.
- **`Payment`** — `status`, links to the `Invoice` it settles, `ownerUid`.

(Field names are indicative; align with the canonical financial schemas from
the bookkeeping changes once chosen.)

## What Changes (target state, after the prerequisite)

Declare `x-openregister-notifications` on the financial schemas. Subjects are
bilingual nl/en and metadata-only. Builds on
`notification-updated-field-change-condition` so status-change rules use
`updated`+`condition`.

- **Invoice overdue** — `scheduled` deadline check against `dueDate`, or
  `updated`+`condition` `{"field":"status","operator":"equals","value":"overdue"}`
  → `{"kind":"field","field":"ownerUid"}` + finance group.
- **Invoice paid** — `updated`+`condition`
  `{"field":"status","operator":"equals","value":"paid"}` →
  `{"kind":"field","field":"ownerUid"}`.
- **PO submitted for approval** — `updated`+`condition`
  `{"field":"status","operator":"equals","value":"submitted"}` →
  `{"kind":"field","field":"approverId"}` (the approval chain).
- **PO approved / rejected** — `updated`+`condition` on `status` equals
  `approved` / `rejected` → `{"kind":"field","field":"ownerUid"}`.
- **Contract renewal / expiry approaching** — `scheduled` deadline check
  against `renewalDate` / `expiryDate` → `{"kind":"field","field":"ownerUid"}`
  + a contracts/procurement group.

## Capabilities

### Added Capabilities

- `notifications`: Shillinq declares finance/procurement notification rules
  (`x-openregister-notifications`) on its `Invoice`, `PurchaseOrder`,
  `Contract`, and `Payment` schemas — invoice overdue/paid, PO approval chain,
  contract renewal/expiry — consumed by the OpenRegister `notificatie-engine`.
  **Gated on the financial data model being modelled first.**

## Impact

- **Config (Shillinq):** `x-openregister-notifications` annotations on the
  financial schemas in `lib/Settings/shillinq_register.json` (no PHP).
- **Blocked:** cannot apply until `Invoice`/`PurchaseOrder`/`Contract`/`Payment`
  schemas (with the listed fields) exist in the register.
- **Engine dependency:** status-change rules require
  `notification-updated-field-change-condition` (archived in openregister).
- **No** new external-email channel needed — all recipients are internal uids
  (owner/approver) or groups.
