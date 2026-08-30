---
kind: config
---

## Why

Shillinq ships **two overlapping Accounts-Payable modules**. A legacy
base-monolith AP (`VendorFinancialProfile` + `PaymentRun` schemas, menu
leaves in the base `src/manifest.json`) is dead weight — its schemas are
not loaded in the live OpenRegister instance and have no `register.d`
source, yet they still pollute the generated `shillinq_register.json` and
the base menu. The canonical "core" AP fragment
(`bookkeeping-accounts-payable-core.json`: `Payee`, `APTransaction`,
`DunningNotice`) is the one actually live in OR (schema ids 1059/1060/727)
and referenced by 15 `lib/` files, but it carries a tier-suffixed group
label ("Accounts Payable (T2)") and lacks a `PaymentRun` schema entirely.

The duplication confuses navigation (two "Accounts Payable" entry points,
two vendor masters), breaks the data model's single-source-of-truth
principle (ADR-000), and leaves a `PaymentRun` gap that the downstream
`payment-run-sepa-export` change needs. All shillinq data is test data, so
there is no production migration risk — this is the moment to consolidate.

## What Changes

- **BREAKING (schema retirement):** Retire the legacy `VendorFinancialProfile`
  schema. Remove its stale generated-register entry; confirm no `register.d`
  source exists. Its unique financial fields (`creditLimit`, `apBalance`) fold
  as top-level `Payee` fields; its `iban`/`bic` fold into `Payee`'s new
  `bankAccount` object.
- **Broaden `Payee` to the canonical "anyone we pay" master.** Re-describe it
  to explicitly cover suppliers, vendors, **and freelancers (Dutch ZZP'ers /
  sole traders)**. Add a `payeeType` enum
  `[supplier, vendor, freelancer, contractor, government, other]`. Fold in
  `creditLimit` + `apBalance` as top-level fields.
- **Restructure `Payee.bankAccount` from a flat string into an object**
  `bankAccount: { iban, bic, accountHolderName }`. `VendorFinancialProfile`'s
  `iban`/`bic` fold into this object (not as separate top-level fields).
  **BIC and SWIFT are the same identifier** (ISO 9362 — a "SWIFT code" *is* a
  BIC), so `bic` already covers SWIFT; no separate `swift` field is added. True
  non-SEPA / cross-border international payments (non-IBAN account numbers,
  intermediary banks) are OUT OF SCOPE — a future capability.
- **Re-establish `PaymentRun` as a real, sourced, live schema** under the core
  fragment: a batch of approved Payee payments with a declarative
  `x-openregister-lifecycle` (draft → approved → exported → reconciled) and a
  `paymentLines[]` array. (The bank-file *generation* is the separate
  `payment-run-sepa-export` change.)
- **Collapse the AP menu to ONE group.** Remove the legacy base
  `Vendors` / `AccountsPayable` / `APAging` / `PaymentRuns` leaves from the
  base `src/manifest.json` Bookkeeping group, drop the "(T2)" suffix on the
  core group, and present clean leaf labels: Payees, AP Invoices, AP Aging,
  Dunning Notices, Payment Runs.
- **Delete the orphaned legacy base-AP page definitions now.** In the same
  `src/manifest.json`, also remove the now-orphaned legacy base-AP page
  definitions (`Vendors`, `AccountsPayable`, `APAging`, `PaymentRuns` — the
  base `AccountsPayable`/`APAging` pages used the legacy `APTransaction`
  schema). The consolidated module's own pages
  (`src/manifest.d/bookkeeping-accounts-payable-core.json`) replace them, so no
  dead page defs are left behind for a follow-up cleanup.
- **Repoint the existing `MigrateProductVendorMasterToPipelinq` repair**
  from `VendorFinancialProfile` → `Payee` (≤20 LOC thin glue — see design
  "Mixed-spec rationale").
- **Regenerate `lib/Settings/shillinq_register.json`** from `register.d` and
  reconcile against live OR.

## Capabilities

### New Capabilities
- `accounts-payable-consolidation`: defines the consolidated AP module — the
  canonical `Payee` master (covering suppliers/vendors/freelancers with
  `payeeType` + folded financial fields), the retirement of the legacy
  `VendorFinancialProfile` duplicate, the `PaymentRun` batch schema with its
  declarative lifecycle, and the single-group AP menu topology.

### Modified Capabilities
- `bookkeeping-accounts-payable-core`: the existing core AP capability gains
  the broadened `Payee` (payeeType + folded financial fields) and the new
  `PaymentRun` schema; its menu group loses the "(T2)" suffix. Requirements
  change at the schema-shape level, so this needs a delta spec.

## Impact

- **Declarative JSON (in scope):** `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`
  (broaden `Payee`, restructure `bankAccount` into an object, add `PaymentRun`),
  `lib/Settings/shillinq_register.json`
  (regenerate; drop `VendorFinancialProfile` + legacy `PaymentRun`),
  `src/manifest.d/bookkeeping-accounts-payable-core.json` (group label +
  PaymentRun pages), `src/manifest.json` (remove legacy AP leaves AND the
  orphaned legacy base-AP page definitions).
- **Thin glue (≤20 LOC):** `lib/Repair/MigrateProductVendorMasterToPipelinq.php`
  repointed `VendorFinancialProfile` → `Payee`, plus updating the `lib/`
  references to the old flat `bankAccount` string to read the new
  `bankAccount.iban` object shape (see design "Mixed-spec rationale").
- **Live OR:** `Payee` (1059) and `APTransaction` (1060) schemas re-imported;
  `PaymentRun` newly imported; `VendorFinancialProfile` retired.
- **Downstream:** unblocks `payment-run-sepa-export` (depends on the
  `PaymentRun` schema established here).
- **No new PHP service classes.** Lifecycle and AP-aging stay declarative
  (`x-openregister-lifecycle` / `x-openregister-aggregations`) per ADR-031.
