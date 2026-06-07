## 1. Prerequisite (chained via depends_on)

- [ ] 1.1 The AR/AP/PO schema changes land their schemas first — `add-shillinq-accounts-receivable-core` (`ARInvoice`), `add-shillinq-accounts-payable-core` (`APInvoice`, `PaymentRun`), `bookkeeping-purchase-order-3way` (`PurchaseOrder`). Tracked via frontmatter `depends_on`; this change cannot be applied until they merge. **STATUS (2026-06-07):** PARTIAL — `APInvoice` + `PaymentRun` ARE on `development` (the AP-core schemas have landed). `ARInvoice` (AR-core, tasks 5-13 unchecked) and `PurchaseOrder` (3-way change archived as SUPERSEDED-BY-CHAIN) are NOT yet on dev → rules for those schemas (tasks 2.1, 2.2, 2.6, 2.7) remain DEFERRED until the owning schemas land.
- [x] 1.2 Confirm `notification-updated-field-change-condition` is available in the deployed OpenRegister (it is archived) so `updated` + field-change `condition` rules dispatch. — Verified: `NotificationAnnotationValidator::VALID_TRIGGERS` accepts `updated`, and the field-change `condition` block is parsed inside `trigger` per the archived `2026-05-31-notification-updated-field-change-condition` spec.

## 2. Declare notification rules on the real schemas

- [ ] 2.1 `ARInvoice` overdue — `scheduled` (intervalSec ≥ 86400) filtering `state` not in `{paid, written-off, voided}` and `dueDate` in the past → `{"kind":"object-acl","permission":"manage"}` + `shillinq-finance` group; bilingual metadata-only subject. **DEFERRED:** `ARInvoice` schema not yet on dev.
- [ ] 2.2 `ARInvoice` paid — `updated` + `condition` `{"field":"state","operator":"equals","value":"paid"}` → `{"kind":"object-acl","permission":"manage"}`. **DEFERRED:** `ARInvoice` schema not yet on dev.
- [x] 2.3 `APInvoice` approval-needed — `updated` + `condition` `{"field":"approvalState","operator":"equals","value":"pending"}` → `{"kind":"object-acl","permission":"manage"}` + `shillinq-finance` group. — `approvalNeeded` rule added in `lib/Settings/shillinq_register.json` (APInvoice block); validator green.
- [x] 2.4 `APInvoice` approved — `updated` + `condition` `{"field":"approvalState","operator":"equals","value":"approved"}` → `{"kind":"object-acl","permission":"manage"}`. — `approvalApproved` rule added; validator green.
- [x] 2.5 `PaymentRun` ready — `updated` + `condition` `{"field":"state","operator":"equals","value":"ready"}` → `{"kind":"object-acl","permission":"manage"}` + `shillinq-finance` group. — `runReady` rule added; validator green.
- [ ] 2.6 `PurchaseOrder` submitted — `created` → `{"kind":"field","field":"requester"}` + `shillinq-procurement` group. **DEFERRED:** `PurchaseOrder` schema not yet on dev.
- [ ] 2.7 `PurchaseOrder` approved — `updated` + `condition` `{"field":"status","operator":"equals","value":"approved"}` → `{"kind":"field","field":"requester"}`. **DEFERRED:** `PurchaseOrder` schema not yet on dev.
- [x] 2.8 Do NOT declare a contract renewal/expiry rule — no `Contract` schema exists; the rule stays deferred (see proposal `## Caveats`). — Verified: no `Contract` schema anywhere in `lib/Settings/*`; no rule authored.

## 3. Validate

- [x] 3.1 Run OpenRegister's notification-annotation validation over the declared rules (valid `trigger` / `recipients` / `subject` shapes per the `x-openregister-notifications` dialect). — Ran `NotificationAnnotationValidator::validate()` over every schema with `x-openregister-notifications`; `APInvoice` + `PaymentRun` both return zero errors. Four pre-existing schemas (`BankStatement`, `KorRegime`, `FiscalYear`, `InventoryReorderRule`) carry malformed rules from earlier changes — those are out of scope here.
- [x] 3.2 Verify all subjects carry both `nl` and `en` keys and are metadata-only. — `APInvoice.approvalNeeded` / `APInvoice.approvalApproved` / `PaymentRun.runReady` subjects each declare `nl` + `en` keys; payloads contain only `invoiceNumber` / `dueDate` / `runNumber` / `runDate` metadata, never line contents.
- [ ] 3.3 Browser-verify a representative rule dispatches end-to-end (state change → in-app notification) once the chained schemas + engine are deployed. **DEFERRED:** End-to-end browser verification requires the AR/PO schemas to land and the OR magic tables to materialise; covered by the chained changes' own verify step.

## Acceptance criteria

- The `ARInvoice`, `APInvoice`, `PaymentRun`, and `PurchaseOrder` schemas exist (via the chained changes) with the `state` / `approvalState` / `status`, `dueDate`, and `requester` fields the rules reference.
- `x-openregister-notifications` rules cover AR overdue/paid, AP approval-needed/approved, payment-run ready, and PO submitted/approved.
- Recipients use only real fields (`PurchaseOrder.requester`) or `object-acl` / group kinds — no invented `ownerUid` / `approverId` field.
- No contract rule is declared (no `Contract` schema yet); it is deferred.
- All subjects are bilingual (nl/en) and metadata-only.
- No app-local notification service code is authored (ADR-031); rules are declarative annotations only.
