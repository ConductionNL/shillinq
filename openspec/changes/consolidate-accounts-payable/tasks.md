## 1. Broaden Payee (register patch)

- [x] 1.1 In `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`, broaden the `Payee` `description` to explicitly cover suppliers, vendors, and freelancers (Dutch ZZP'ers / sole traders).
- [x] 1.2 Add a `payeeType` enum property to `Payee`: `[supplier, vendor, freelancer, contractor, government, other]` (default `supplier`).
- [x] 1.3 Fold the `VendorFinancialProfile` financial fields into `Payee` as top-level fields: add `creditLimit` (number, nullable) and `apBalance` (number, nullable).
- [x] 1.4 Restructure `Payee.bankAccount` from a flat string into an object `bankAccount: { iban (string, nullable), bic (string, nullable), accountHolderName (string, nullable) }` (D2). Fold the retired profile's `iban`/`bic` INTO this object — do NOT add separate top-level `iban`/`bic`/`swift` fields (BIC = SWIFT, ISO 9362). Add a schema comment noting non-SEPA/cross-border international payments are out of scope (future capability).

## 2. Add PaymentRun schema (register patch)

- [x] 2.1 Add a `PaymentRun` schema to the core `register.d` fragment with fields `runNumber`, `administrationId`, `executionDate`, `debtorAccountIban`, `status`, `totalAmount`, `currency`, `paymentLines[]` (`payeeId`, `payeeName`, `creditorIban`, `amount`, `remittanceInfo`, `apTransactionRef`), `exportedFileRef`, `exportedAt`, `reconciledAt`, `lifecycleState`. Add `x-schema-org: schema:PaymentService` + `x-openregister-audit-trail`.
- [x] 2.2 Add `x-openregister-lifecycle` to `PaymentRun` in the fragment: states `draft → approved → exported → reconciled`; transition `approve` (`draft → approved`, RBAC role `controller`), `export` (`approved → exported`), `reconcile` (`exported → reconciled`). NO direct `draft → exported` transition (approval gate, D3/D4). Do NOT add a PaymentRunService PHP class.
- [x] 2.3 Add `x-openregister-rbac` to `PaymentRun` mirroring the AP-core roles (bookkeeper/controller/auditor).

## 3. Retire legacy duplicates

- [x] 3.1 Confirm (grep `lib/Settings/register.d/`) that no `register.d` source defines `VendorFinancialProfile` or the legacy `PaymentRun`. (Confirmed: NO fragment declares either as a `components.schemas` key. `VendorFinancialProfile` appears only as a mapping target in `add-shillinq-bookkeeping-compliance.json` and a description-text mention in `shillinq-pipelinq-product-vendor-integration.json` — neither is a schema definition; both out of scope here.)
- [x] 3.2 Remove the stale `VendorFinancialProfile` schema + objects from the generated `lib/Settings/shillinq_register.json`.
- [x] 3.3 Remove the stale legacy `PaymentRun` schema + objects from the generated `lib/Settings/shillinq_register.json` (the new one comes from the fragment regeneration).

## 4. Regenerate + reconcile register

- [x] 4.1 N/A — runtime merge, base+fragment edited directly. There is no generator script; `SettingsService::deepMergeConfig` (ADR-037) deep-merges `register.d/*.json` onto the base `shillinq_register.json` at runtime. "Regeneration" = (a) the §3 deletions of the stale schemas/objects from the base + (b) the §1/§2/§7 schema+seed edits in the fragment; the runtime merge produces the final register.
- [x] 4.2 Reconcile against live OR: verify `Payee` (1059) + `APTransaction` (1060) re-import cleanly, `PaymentRun` imports as a new live schema, and `VendorFinancialProfile` is absent.

## 5. Menu consolidation (manifest)

- [x] 5.1 In `src/manifest.json`, remove the legacy base AP leaves `Vendors`, `AccountsPayable`, `APAging`, `PaymentRuns` from the Bookkeeping group AND delete the now-orphaned legacy base-AP page definitions of the same names (the base `AccountsPayable`/`APAging` page defs used the legacy `APTransaction` schema). The consolidated module's own pages replace them — leave no orphaned page defs for a follow-up.
- [x] 5.2 In `src/manifest.d/bookkeeping-accounts-payable-core.json`, drop the "(T2)" suffix from the AP group label.
- [x] 5.3 Set clean leaf labels: Payees, AP Invoices, AP Aging, Dunning Notices, Payment Runs (incl. a Payment Runs index + detail page referencing the new `PaymentRun` schema).

## 6. Thin-glue repair repoint

- [x] 6.1 Repoint `lib/Repair/MigrateProductVendorMasterToPipelinq.php` from `VendorFinancialProfile` → `Payee` (schema slug + read/write target, ≤20 LOC, no new logic — Mixed-spec rationale).
- [x] 6.2 Update the old flat `bankAccount` references in `src/manifest.d/bookkeeping-accounts-payable-core.json` (the two Payee index/detail column/field keys `bankAccount` → `bankAccount.iban`). Repo-wide grep confirmed NO `lib/` PHP reads of the flat `Payee.bankAccount` string, so this stays thin glue (manifest JSON only). If a future audit surfaces real `lib/` reads, those updates are instead carried by the dependent `payment-run-sepa-export` code change.

## 7. Seed data (ADR-001)

- [x] 7.1 Extend the existing `Payee` seed objects in the fragment to add `payeeType` + top-level folded financial fields (`creditLimit`, `apBalance`), and restructure each `bankAccount` from a flat string to an object `{ iban, bic, accountHolderName }`; add a travel-agency freelancer (ZZP) `Payee` and a municipality `government` `Payee`. Use SAFE placeholder IBAN/BIC values only (`NL00BANK0123456789` / `<BANKNL2A>`).
- [x] 7.2 Add an approved `PaymentRun` seed object (`PR-2026-001`, status `approved`) with 2 `paymentLines[]` referencing seeded payees + AP invoices — ready for the change-2 export. SAFE placeholder IBANs only.

## 8. Verify

- [x] 8.1 Grep `lib/` + `src/` for residual `VendorFinancialProfile` references; confirm only the (repointed) repair remains and now targets `Payee`.
- [x] 8.2 Run `openspec validate consolidate-accounts-payable --strict` and confirm the change passes.
- [x] 8.3 Live-verify: one AP menu group (no "(T2)"), clean leaf labels, `PaymentRun` index renders, no legacy AP leaves AND no orphaned legacy AP page definitions in `src/manifest.json`; the Payee index/detail shows the IBAN via `bankAccount.iban`.
