## ADDED Requirements

### Requirement: Shillinq MUST declare finance and procurement notification rules on its real AR/AP/PO schemas
Shillinq MUST declare `x-openregister-notifications` rules on its `ARInvoice`, `APInvoice`, `PaymentRun`, and `PurchaseOrder` schemas, consumed by the OpenRegister `notificatie-engine`. The rules MUST cover AR invoice overdue and paid, the AP approval chain (approval-needed and approved), payment-run ready, and the purchase-order approval flow (submitted and approved). All subjects MUST be provided in both Dutch (`nl`) and English (`en`) and MUST be metadata-only (no financial-document line contents in the subject). Status-change rules MUST use the `updated` trigger with a field-change `condition` (per `notification-updated-field-change-condition`) on the real `state` / `approvalState` / `status` field; the AR overdue rule MUST use the `scheduled` trigger with a filter on the real `dueDate` and `state` fields.

These rules are declarative annotations only — Shillinq MUST NOT author app-local notification service code (ADR-031).

This requirement is CHAINED on the schema-owning changes: the `ARInvoice` / `APInvoice` / `PaymentRun` schemas (`add-shillinq-accounts-receivable-core`, `add-shillinq-accounts-payable-core`) and the `PurchaseOrder` schema (`bookkeeping-purchase-order-3way`) MUST be modelled in the register before these rules can be attached.

Recipient resolution MUST use only fields that exist on the real schemas: the `PurchaseOrder.requester` uid field for `{"kind":"field"}`, and `{"kind":"object-acl","permission":"manage"}` plus shillinq groups (`shillinq-finance`, `shillinq-procurement`) where no per-object owner/approver uid field exists. No `ownerUid` / `approverId` field MUST be invented.

#### Scenario: AR invoice becomes overdue notifies the manager and finance
- GIVEN the `ARInvoice` schema declares an overdue rule as a `scheduled` trigger (intervalSec ≥ 86400) filtering on `state` not in `{paid, written-off, voided}` and `dueDate` in the past, with recipients `{"kind":"object-acl","permission":"manage"}` plus the `shillinq-finance` group
- WHEN an AR invoice passes its `dueDate` without payment
- THEN the OpenRegister notification engine MUST deliver a notification to the invoice managers and the finance group
- AND the subject MUST be available in both `nl` and `en` and contain only metadata (e.g. invoice number, due date)

#### Scenario: AR invoice paid notifies the manager
- GIVEN the `ARInvoice` schema declares an `updated` rule with `condition` `{"field":"state","operator":"equals","value":"paid"}` and recipients `{"kind":"object-acl","permission":"manage"}`
- WHEN an AR invoice's `state` changes to `paid`
- THEN the engine MUST deliver a notification to the invoice managers

#### Scenario: AP invoice needs approval notifies finance
- GIVEN the `APInvoice` schema declares an `updated` rule with `condition` `{"field":"approvalState","operator":"equals","value":"pending"}` and recipients `{"kind":"object-acl","permission":"manage"}` plus the `shillinq-finance` group
- WHEN an AP invoice's `approvalState` changes to `pending`
- THEN the engine MUST deliver an approval-request notification to the finance group (the pool eligible to approve), because the eligible approver comes from OR's approval-workflow config and is not a schema field

#### Scenario: AP invoice approved notifies the manager
- GIVEN the `APInvoice` schema declares an `updated` rule with `condition` `{"field":"approvalState","operator":"equals","value":"approved"}` and recipients `{"kind":"object-acl","permission":"manage"}`
- WHEN an AP invoice's `approvalState` changes to `approved`
- THEN the engine MUST deliver the approval-outcome notification to the invoice managers

#### Scenario: Payment run ready notifies finance
- GIVEN the `PaymentRun` schema declares an `updated` rule with `condition` `{"field":"state","operator":"equals","value":"ready"}` and recipients `{"kind":"object-acl","permission":"manage"}` plus the `shillinq-finance` group
- WHEN a payment run's `state` changes to `ready`
- THEN the engine MUST deliver a "payment run ready" notification to the finance group

#### Scenario: Purchase order submitted notifies the requester and procurement
- GIVEN the `PurchaseOrder` schema declares a `created` rule with recipients `{"kind":"field","field":"requester"}` plus the `shillinq-procurement` group
- WHEN a purchase order is created (submitted into the approval flow)
- THEN the engine MUST deliver a notification to the PO `requester` and the procurement group, because `approval_chain` is an array of roles/users rather than a single resolvable uid

#### Scenario: Purchase order approved notifies the requester
- GIVEN the `PurchaseOrder` schema declares an `updated` rule with `condition` `{"field":"status","operator":"equals","value":"approved"}` and recipients `{"kind":"field","field":"requester"}`
- WHEN a purchase order's `status` changes to `approved`
- THEN the engine MUST deliver the approval-outcome notification to the PO `requester`

#### Scenario: Contract renewal rule is deferred — no Contract schema exists
- GIVEN no `Contract` schema exists anywhere in Shillinq
- WHEN the notification rule set is reviewed
- THEN no contract renewal/expiry rule MUST be declared, and the rule MUST remain deferred (and unchained) until a contract is modelled, rather than targeting an invented schema

#### Scenario: Rules are chained on the schema-owning changes landing first
- GIVEN the `ARInvoice` / `APInvoice` / `PaymentRun` / `PurchaseOrder` schemas are not yet present in the register (their owning changes are unmerged)
- WHEN the financial/procurement schemas have not yet been modelled
- THEN these notification rules MUST NOT be attached (there is no schema to annotate), and this change MUST remain chained behind `add-shillinq-accounts-receivable-core`, `add-shillinq-accounts-payable-core`, and `bookkeeping-purchase-order-3way`
