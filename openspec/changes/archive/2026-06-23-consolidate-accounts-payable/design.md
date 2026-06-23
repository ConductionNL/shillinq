## Context

Shillinq carries two overlapping Accounts-Payable modules (see proposal). The
**canonical "core"** fragment (`lib/Settings/register.d/bookkeeping-accounts-payable-core.json`)
holds `Payee`, `APTransaction`, `DunningNotice` — live in OR (schema ids
1059/1060/727), referenced by 15 `lib/` files, with full index/detail pages
under `src/manifest.d/bookkeeping-accounts-payable-core.json`. The **legacy
base monolith** holds `VendorFinancialProfile` + `PaymentRun` — not loaded in
live OR, no `register.d` source, surviving only as stale entries in the
*generated* `lib/Settings/shillinq_register.json` plus dead menu leaves
(`Vendors`/`AccountsPayable`/`APAging`/`PaymentRuns`) in the base
`src/manifest.json`.

All shillinq data is test data, so there are no production-migration
concerns. This change is **declarative JSON only** (schema register + manifest)
plus one ≤20-LOC repair repoint.

## Goals / Non-Goals

**Goals:**

- One canonical AP module: `Payee` + `APTransaction` + `DunningNotice` +
  `PaymentRun`, all sourced from the core `register.d` fragment.
- `Payee` = canonical "anyone we pay" master (suppliers, vendors,
  freelancers/ZZP'ers), with `payeeType` enum, folded top-level financial
  fields (`creditLimit`/`apBalance`), and a restructured `bankAccount` object
  `{ iban, bic, accountHolderName }`.
- `PaymentRun` re-established as a real, sourced, live schema with a
  declarative lifecycle.
- One AP menu group, "(T2)" suffix dropped, clean leaf labels, with the
  orphaned legacy base-AP page definitions deleted (not left for follow-up).
- Regenerated `shillinq_register.json` reconciled with live OR.

**Non-Goals:**

- The actual bank-file (SEPA pain.001 / CSV) **generation** — that is the
  separate `payment-run-sepa-export` change (kind: code).
- Any new PHP service / state-machine / aggregation class.
- Multi-currency, Peppol inbound e-invoicing (deferred per T2 scope).
- Reworking `APTransaction` field set (unchanged here).

## Decisions

### D1 — Fold `VendorFinancialProfile` into `Payee`, don't keep both

`VendorFinancialProfile` was a denormalised read-cache keyed by
`contactsUid`, duplicating `Payee`'s identity fields. Its only *unique* fields
are `creditLimit`, `apBalance`, `iban`, `bic`. We fold `creditLimit` +
`apBalance` into `Payee` as top-level fields, and fold `iban` + `bic` into
`Payee`'s restructured `bankAccount` object (see D2 — not as separate top-level
fields). We retire `VendorFinancialProfile` entirely. *Alternative considered:*
keep `VendorFinancialProfile` as a 1:1 financial-extension of `Payee` —
rejected, it reintroduces the two-master split the change exists to remove.

### D2 — `Payee.bankAccount` is restructured into an object `{ iban, bic, accountHolderName }`

We restructure `Payee`'s `bankAccount` from a flat string into a structured
object: `bankAccount: { iban, bic, accountHolderName }`. The
`VendorFinancialProfile` `iban`/`bic` fields fold INTO this object — they are
**not** added as separate top-level `Payee` fields. The SEPA export (change 2)
reads `bankAccount.iban` / `bankAccount.bic` for the creditor agent.

**BIC = SWIFT.** A bank's "SWIFT code" and its BIC are the same ISO 9362
identifier ("SWIFT code" is the colloquial name for a BIC). The `bic` field
therefore already covers SWIFT; we deliberately do **not** add a separate
`swift` field, which would be a redundant duplicate.

*Alternative considered:* keep the flat `bankAccount` string + add discrete
top-level `iban`/`bic` — rejected; an object keeps the bank identity cohesive,
avoids a redundant flat-string-plus-fields split, and there are no `lib/` PHP
reads of the flat `Payee.bankAccount` string to break (the only references are
two manifest column/field keys in the Payee index/detail page, updated as thin
glue — see "Mixed-spec rationale").

**Non-Goal / future:** true non-SEPA / cross-border *international* payments
(non-IBAN account numbers, intermediary/correspondent banks, full
ISO 20022 `CdtrAgtAcct`) are OUT OF SCOPE here — a future capability. This
`bankAccount` object models SEPA IBAN/BIC only.

### D3 — `PaymentRun` lifecycle is declarative (`x-openregister-lifecycle`)

`draft → approved → exported → reconciled`, declared in the register fragment.
`approved` is a hard gate before `exported` (no direct `draft → exported`).
The legacy `PaymentRun` used a `draft/ready/submitted/executed/failed`
lifecycle plus `sepaXml` as an `x-openregister-calculations` string — we drop
that calculated-XML approach because real bank-file generation needs an
imperative document writer (handled in change 2 via `exportedFileRef`), not a
synchronous calc field.

### D4 — Single approver (provisional)

The `approved` transition is a single-approver gate (RBAC role `controller`),
not a multi-step approval chain. *Alternative considered:* a chained
maker/checker approval — deferred; a single approver matches the AP-core
`controller` RBAC role already in the fragment. **(DEFERRED_QUESTION.)**

### D5 — Menu consolidation is manifest-only

Remove the four legacy base AP leaves from `src/manifest.json`; drop "(T2)"
from the core group label in `src/manifest.d/bookkeeping-accounts-payable-core.json`;
rename leaf labels to Payees / AP Invoices / AP Aging / Dunning Notices /
Payment Runs. No route registration changes (ADR-024 manifest-driven shell).

### Mixed-spec rationale (the one code touch)

This change is `kind: config`. The code touches are thin glue made necessary
by the schema restructure, adding no business logic and no new service:

1. **Repair repoint** — `lib/Repair/MigrateProductVendorMasterToPipelinq.php`
   repointed `VendorFinancialProfile` → `Payee` (≤20 LOC, a schema-slug
   constant + the read/write target).
2. **`bankAccount`-ref update** — the only references to the old flat
   `Payee.bankAccount` string are two manifest column/field keys in
   `src/manifest.d/bookkeeping-accounts-payable-core.json` (the Payee index +
   detail page), updated from `bankAccount` → `bankAccount.iban`. A repo-wide
   grep confirms **no `lib/` PHP code reads the flat `Payee.bankAccount`
   string** (the `bankAccountId`/`bankAccountIban` references in the
   reconciliation/statement code belong to other schemas, not `Payee`), so the
   restructure stays thin glue (≤20 LOC of manifest JSON) rather than a
   code-shaped change. Should a future audit surface real `lib/` reads, those
   `bankAccount`-ref updates are instead carried by the dependent
   `payment-run-sepa-export` code change.

Per ADR-031 neither touch is a state machine / aggregation / calculation /
notification service, so they do not trip the declarative-first anti-patterns.
They are landed as dedicated tasks, kept out of the spec's behavioural surface.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Placement | Rationale |
|---|---|---|
| `PaymentRun` state machine (draft→approved→exported→reconciled) | **Declarative** `x-openregister-lifecycle` | ADR-031 lifecycle extension fits exactly; no PHP state-machine service. |
| `Payee.apBalance` / AP aging | **Declarative** `x-openregister-aggregations` (`vendorOpenApBalance`, `agedPayables*` already in the fragment) | Aggregation extension fits; `apBalance` is a derived open-AP total, not hand-maintained. |
| Approval gate (`approved` before `exported`) | **Declarative** lifecycle transition `from: approved` + RBAC `controller` role | No guard service needed; the transition graph enforces it. |
| `MigrateProductVendorMasterToPipelinq` repoint | **Imperative** (existing repair) | A one-off data repair, not lifecycle/aggregation/calculation/notification; ADR-031 leaves data repairs in PHP. ≤20 LOC, no new class. |

No new `*Service` class is introduced by this change. The SEPA file
generation (the only genuinely imperative piece) is explicitly deferred to
change 2, where it is justified as ADR-031 document generation.

## Seed Data (ADR-001)

Re-seed the consolidated AP objects so every new/changed schema has realistic
objects across the three reference orgs (a consultancy, a travel agency, a
municipality). All IBAN/BIC values are SAFE placeholders.

**Payee (consultancy — supplier):**
```json
{ "vendorNumber": "V-2026-001", "name": "Eneco Energie B.V.", "payeeType": "supplier",
  "kvkNumber": "24502797", "btwNumber": "NL003726244B01", "paymentTermDays": 30,
  "bankAccount": { "iban": "NL00BANK0123456789", "bic": "<BANKNL2A>", "accountHolderName": "Eneco Energie B.V." },
  "creditLimit": 10000, "apBalance": 892.50, "administrationId": "adm-consultancy",
  "lifecycleState": "active" }
```

**Payee (travel agency — freelancer/ZZP):**
```json
{ "vendorNumber": "V-2026-010", "name": "Jan de Vries (ZZP)", "payeeType": "freelancer",
  "kvkNumber": "11122233", "btwNumber": "NL001234567B01", "paymentTermDays": 14,
  "bankAccount": { "iban": "NL00TEST0222222222", "bic": "<TESTNL2A>", "accountHolderName": "Jan de Vries" },
  "creditLimit": 2000, "apBalance": 605.00, "administrationId": "adm-travel",
  "lifecycleState": "active" }
```

**Payee (municipality — government):**
```json
{ "vendorNumber": "V-2026-020", "name": "Gemeente Demo Inkoopbureau", "payeeType": "government",
  "kvkNumber": "55566677", "paymentTermDays": 30,
  "bankAccount": { "iban": "NL00BANK0333333333", "bic": "<BANKNL2A>", "accountHolderName": "Gemeente Demo Inkoopbureau" },
  "creditLimit": 50000, "apBalance": 0, "administrationId": "adm-municipality",
  "lifecycleState": "active" }
```

**PaymentRun (consultancy — approved batch ready for change-2 export):**
```json
{ "runNumber": "PR-2026-001", "administrationId": "adm-consultancy",
  "executionDate": "2026-07-01", "debtorAccountIban": "NL00BANK9999999999",
  "status": "approved", "lifecycleState": "approved", "currency": "EUR",
  "totalAmount": 1497.50,
  "paymentLines": [
    { "payeeId": "payee-eneco", "payeeName": "Eneco Energie B.V.",
      "creditorIban": "NL00BANK0123456789", "amount": 892.50,
      "remittanceInfo": "ENECO-2026-04-0001", "apTransactionRef": "ap-txn-eneco-2026-04-0001" },
    { "payeeId": "payee-jandevries", "payeeName": "Jan de Vries (ZZP)",
      "creditorIban": "NL00TEST0222222222", "amount": 605.00,
      "remittanceInfo": "JDV-2026-06-0003", "apTransactionRef": "ap-txn-jdv-2026-06-0003" }
  ] }
```

(The existing 3 `Payee` + 8 `APTransaction` + 2 `DunningNotice` seed objects in
the fragment are extended/retagged to add `payeeType` + folded fields; the
travel-agency + municipality payees + the `PaymentRun` are net-new.)

## Risks / Trade-offs

- **[Stale generated register drifts from live OR]** → Regenerate
  `shillinq_register.json` from `register.d` and reconcile against live OR
  (schema-id reconciliation task); verify `VendorFinancialProfile` is gone and
  `PaymentRun` is present.
- **[Repair repoint breaks if `Payee` slug differs from expectation]** → The
  repoint references the canonical `Payee` slug already live (1059); covered by
  a dedicated task + smoke run of `occ` repair step.
- **[`bankAccount` object migration of existing seed data]** → All
  `bankAccount` values are restructured from flat string to
  `{ iban, bic, accountHolderName }` (D2); `bankAccount.iban`/`bankAccount.bic`
  are the SEPA-export source of truth. All data is test data, re-seeded with
  the object shape; the two manifest column keys are updated to
  `bankAccount.iban`.
- **[Hidden code references to `VendorFinancialProfile`]** → Grep `lib/` +
  `src/` for `VendorFinancialProfile` before regenerating; the only known
  reference is the repair (repointed).

## Migration Plan

1. Broaden `Payee` + add `PaymentRun` in the `register.d` fragment.
2. Remove `VendorFinancialProfile` + legacy `PaymentRun` from generated register;
   regenerate `shillinq_register.json` from `register.d`.
3. Update manifests (base leaf removal + orphaned legacy base-AP page-def
   removal + group/leaf relabel + PaymentRun pages).
4. Repoint the repair; update the two manifest `bankAccount` column keys to
   `bankAccount.iban`.
5. Re-seed objects; reconcile against live OR.

Rollback: revert the JSON edits + repair; since all data is test data, re-seed
from the previous fragment. No irreversible data loss path exists.

## Open Questions

- Approval depth (single approver vs chain) — provisional: single approver (D4).

## Resolved Decisions

- **Bank-account modelling** — RESOLVED: `bankAccount` is a structured object
  `{ iban, bic, accountHolderName }` (D2). `VendorFinancialProfile`'s `iban`/`bic`
  fold into it (not separate top-level fields). BIC = SWIFT (ISO 9362), so no
  separate `swift` field. Non-SEPA / cross-border international payments are a
  future capability (out of scope).
- **Legacy base-AP page definitions** — RESOLVED: deleted NOW in this same
  change, not left orphaned for a follow-up. `src/manifest.json` loses both the
  legacy AP menu leaves AND the orphaned legacy base-AP page definitions
  (`Vendors`/`AccountsPayable`/`APAging`/`PaymentRuns`; the base
  `AccountsPayable`/`APAging` pages used the legacy `APTransaction` schema). The
  consolidated module's own pages replace them, leaving no dead page defs.
