## 1. PREREQUISITE — model the financial schemas (BLOCKING)

- [ ] 1.1 Model the `Invoice` schema (`status` with `draft → issued → paid` / `overdue`, `dueDate`, `ownerUid`) in `lib/Settings/shillinq_register.json`. Align names with the canonical bookkeeping financial schemas (`bookkeeping-accounts-receivable-core`) if those land first.
- [ ] 1.2 Model the `PurchaseOrder` schema (`status` with `draft → submitted → approved → rejected`, `approverId`, `ownerUid`).
- [ ] 1.3 Model the `Contract` schema (`status`, `renewalDate`/`expiryDate`, `ownerUid`).
- [ ] 1.4 Model the `Payment` schema (`status`, link to settled `Invoice`, `ownerUid`).
- [ ] 1.5 Confirm `notification-updated-field-change-condition` is available in the deployed OpenRegister (it is archived) so `updated`+`condition` rules dispatch.

## 2. Declare notification rules (after the prerequisite)

- [ ] 2.1 `Invoice` overdue rule — `scheduled` against `dueDate` (or `updated`+`condition` equals `overdue`) → `{"kind":"field","field":"ownerUid"}` + finance group; bilingual metadata-only subject.
- [ ] 2.2 `Invoice` paid rule — `updated`+`condition` equals `paid` → `{"kind":"field","field":"ownerUid"}`.
- [ ] 2.3 `PurchaseOrder` submitted rule — `updated`+`condition` equals `submitted` → `{"kind":"field","field":"approverId"}`.
- [ ] 2.4 `PurchaseOrder` approved/rejected rules — `updated`+`condition` equals `approved` / `rejected` → `{"kind":"field","field":"ownerUid"}`.
- [ ] 2.5 `Contract` renewal/expiry rule — `scheduled` against `renewalDate`/`expiryDate` → `{"kind":"field","field":"ownerUid"}` + contracts/procurement group.

## 3. Validate

- [ ] 3.1 Run OpenRegister's `NotificationAnnotationValidator` shape over the declared rules (valid trigger/recipient/subject shapes).
- [ ] 3.2 Verify all subjects carry both `nl` and `en` keys.
- [ ] 3.3 Browser-verify a representative rule dispatches end-to-end (status change → in-app notification) once schemas + engine are deployed.

## Acceptance criteria

- The financial schemas (`Invoice`, `PurchaseOrder`, `Contract`, `Payment`) exist with the fields the rules reference.
- `x-openregister-notifications` rules cover invoice overdue/paid, PO approval chain, contract renewal/expiry.
- All subjects are bilingual (nl/en) and metadata-only.
- No app-local notification service code is authored (ADR-031); rules are declarative annotations only.
