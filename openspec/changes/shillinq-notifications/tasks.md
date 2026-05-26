## 1. Prerequisite (chained via depends_on)

- [ ] 1.1 The AR/AP/PO schema changes land their schemas first — `add-shillinq-accounts-receivable-core` (`ARInvoice`), `add-shillinq-accounts-payable-core` (`APInvoice`, `PaymentRun`), `bookkeeping-purchase-order-3way` (`PurchaseOrder`). Tracked via frontmatter `depends_on`; this change cannot be applied until they merge.
- [ ] 1.2 Confirm `notification-updated-field-change-condition` is available in the deployed OpenRegister (it is archived) so `updated` + field-change `condition` rules dispatch.

## 2. Declare notification rules on the real schemas

- [ ] 2.1 `ARInvoice` overdue — `scheduled` (intervalSec ≥ 86400) filtering `state` not in `{paid, written-off, voided}` and `dueDate` in the past → `{"kind":"object-acl","permission":"manage"}` + `shillinq-finance` group; bilingual metadata-only subject.
- [ ] 2.2 `ARInvoice` paid — `updated` + `condition` `{"field":"state","operator":"equals","value":"paid"}` → `{"kind":"object-acl","permission":"manage"}`.
- [ ] 2.3 `APInvoice` approval-needed — `updated` + `condition` `{"field":"approvalState","operator":"equals","value":"pending"}` → `{"kind":"object-acl","permission":"manage"}` + `shillinq-finance` group.
- [ ] 2.4 `APInvoice` approved — `updated` + `condition` `{"field":"approvalState","operator":"equals","value":"approved"}` → `{"kind":"object-acl","permission":"manage"}`.
- [ ] 2.5 `PaymentRun` ready — `updated` + `condition` `{"field":"state","operator":"equals","value":"ready"}` → `{"kind":"object-acl","permission":"manage"}` + `shillinq-finance` group.
- [ ] 2.6 `PurchaseOrder` submitted — `created` → `{"kind":"field","field":"requester"}` + `shillinq-procurement` group.
- [ ] 2.7 `PurchaseOrder` approved — `updated` + `condition` `{"field":"status","operator":"equals","value":"approved"}` → `{"kind":"field","field":"requester"}`.
- [ ] 2.8 Do NOT declare a contract renewal/expiry rule — no `Contract` schema exists; the rule stays deferred (see proposal `## Caveats`).

## 3. Validate

- [ ] 3.1 Run OpenRegister's notification-annotation validation over the declared rules (valid `trigger` / `recipients` / `subject` shapes per the `x-openregister-notifications` dialect).
- [ ] 3.2 Verify all subjects carry both `nl` and `en` keys and are metadata-only.
- [ ] 3.3 Browser-verify a representative rule dispatches end-to-end (state change → in-app notification) once the chained schemas + engine are deployed.

## Acceptance criteria

- The `ARInvoice`, `APInvoice`, `PaymentRun`, and `PurchaseOrder` schemas exist (via the chained changes) with the `state` / `approvalState` / `status`, `dueDate`, and `requester` fields the rules reference.
- `x-openregister-notifications` rules cover AR overdue/paid, AP approval-needed/approved, payment-run ready, and PO submitted/approved.
- Recipients use only real fields (`PurchaseOrder.requester`) or `object-acl` / group kinds — no invented `ownerUid` / `approverId` field.
- No contract rule is declared (no `Contract` schema yet); it is deferred.
- All subjects are bilingual (nl/en) and metadata-only.
- No app-local notification service code is authored (ADR-031); rules are declarative annotations only.
