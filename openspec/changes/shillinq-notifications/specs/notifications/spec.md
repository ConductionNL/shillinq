## ADDED Requirements

### Requirement: Shillinq MUST declare finance and procurement notification rules on its financial schemas
Shillinq MUST declare `x-openregister-notifications` rules on its `Invoice`, `PurchaseOrder`, `Contract`, and `Payment` schemas, consumed by the OpenRegister `notificatie-engine`. The rules MUST cover invoice overdue and paid, the purchase-order approval chain (submitted / approved / rejected), and contract renewal/expiry. All subjects MUST be provided in both Dutch (`nl`) and English (`en`) and MUST be metadata-only (no financial-document contents in the subject). Status-change rules MUST use the `updated` trigger with a field-change `condition` (per `notification-updated-field-change-condition`); deadline rules MUST use the `scheduled` trigger against the relevant date field.

These rules are declarative annotations only — Shillinq MUST NOT author app-local notification service code (ADR-031).

This requirement is GATED on the financial data model: the `Invoice`, `PurchaseOrder`, `Contract`, and `Payment` schemas (with `status`, `dueDate`/`renewalDate`/`expiryDate`, and owner/approver fields as applicable) MUST be modelled in the register before these rules can be attached.

#### Scenario: Invoice becomes overdue notifies the owner
- GIVEN the `Invoice` schema declares an overdue rule (a `scheduled` deadline check against `dueDate`, or an `updated`+`condition` `{"field":"status","operator":"equals","value":"overdue"}`) with recipients `{"kind":"field","field":"ownerUid"}` plus a finance group
- WHEN an invoice passes its due date without payment (or its `status` becomes `overdue`)
- THEN the OpenRegister notification engine MUST deliver a notification to the invoice owner and the finance group
- AND the subject MUST be available in both `nl` and `en` and contain only metadata (e.g. invoice number, due date)

#### Scenario: Invoice paid notifies the owner
- GIVEN the `Invoice` schema declares an `updated`+`condition` `{"field":"status","operator":"equals","value":"paid"}` rule with recipients `{"kind":"field","field":"ownerUid"}`
- WHEN an invoice's `status` changes to `paid`
- THEN the engine MUST deliver a notification to the invoice owner

#### Scenario: Purchase order submitted notifies the approver
- GIVEN the `PurchaseOrder` schema declares an `updated`+`condition` `{"field":"status","operator":"equals","value":"submitted"}` rule with recipients `{"kind":"field","field":"approverId"}`
- WHEN a purchase order's `status` changes to `submitted`
- THEN the engine MUST deliver an approval-request notification to the approver

#### Scenario: Purchase order approved or rejected notifies the owner
- GIVEN the `PurchaseOrder` schema declares `updated`+`condition` rules on `status` equals `approved` and `rejected` with recipients `{"kind":"field","field":"ownerUid"}`
- WHEN a purchase order's `status` changes to `approved` (or `rejected`)
- THEN the engine MUST deliver the corresponding outcome notification to the purchase-order owner

#### Scenario: Contract renewal approaching notifies the owner
- GIVEN the `Contract` schema declares a `scheduled` deadline rule against `renewalDate` / `expiryDate` with recipients `{"kind":"field","field":"ownerUid"}` plus a contracts/procurement group
- WHEN a contract's renewal/expiry date is approaching within the configured window
- THEN the engine MUST deliver a renewal-reminder notification to the contract owner and the contracts group
- AND the subject MUST be available in both `nl` and `en`

#### Scenario: Rules are gated on the financial data model existing
- GIVEN the register declares only the `Account` schema and not `Invoice`/`PurchaseOrder`/`Contract`/`Payment`
- WHEN the financial schemas have not yet been modelled
- THEN these notification rules MUST NOT be attached (there is no schema to annotate), and the change MUST remain blocked until the financial data model lands
