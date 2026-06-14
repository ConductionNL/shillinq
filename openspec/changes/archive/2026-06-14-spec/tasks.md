# Tasks — Chart of Accounts

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-chart-of-accounts`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `Account` schema or `bookkeeping-chart-of-accounts` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`; catalogue and reconcile existing ADR-000 entries for `Account` and `GeneralLedgerAccount`)
- [x] Task 2: Author `specs/bookkeeping-chart-of-accounts/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` / `Depends on: none` header, `REQ-CoA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis table and Seed Data section per hydra `rules.design`; bookkeeper persona reads end-to-end and confirms RGS conformance
- [x] Task 5: Declare the `Account` schema in `lib/Settings/shillinq_register.json` with all REQ-CoA-002 fields (accountNumber, name, accountType, currency, parentAccountNumber, isClosingAccount, administrationId, lifecycleState, description) typed per spec
- [x] Task 6: Add `x-openregister-lifecycle` block to `Account` declaring `active → blocked`, `active → archived`, `blocked → archived` transitions per REQ-CoA-005
- [x] Task 7: Add `x-openregister-relations` self-relation on `Account.parentAccountNumber → Account.accountNumber` per REQ-CoA-003 for hierarchical navigation
- [x] Task 8: Ship `lib/Settings/seeds/rgs-3.5-mkb.json` SMB template (JSON array of `Account` records, SPDX header, `_meta` block with `source: "RGS 3.5"` / `variant: "mkb"`) per REQ-CoA-006
- [x] Task 9: Ship `lib/Settings/seeds/rgs-3.5-zzp.json` ZZP template (same shape as mkb, `_meta.variant: "zzp"`) per REQ-CoA-006
- [x] Task 10: Ship `lib/Settings/seeds/rgs-bbv.json` BBV government template (same shape, `_meta.variant: "bbv"`) per REQ-CoA-006
- [x] Task 11: Extend the repair step under `lib/Repair/` to import the selected RGS template idempotently (operator edits persist across re-runs; the repair step does not re-overwrite seeded records) per REQ-CoA-007
- [x] Task 12: Add Chart of Accounts navigation + pages to `src/manifest.json` (menu entry `Bookkeeping > Chart of Accounts`, `type: index` page binding to `Account` register, `type: detail` page for individual accounts) per REQ-CoA-008; `node tests/validate-manifest.js` exits 0
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph reconciliation note from `design.md` Reuse Analysis (folding `GeneralLedgerAccount` fields into `Account` under the bookkeeping role)

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the schema shape matches a real RGS-conformant chart of accounts. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; no service-class state machines; manifest carries the navigation). No source code changes outside `openspec/changes/add-shillinq-chart-of-accounts/`.

## Tests (company-wide ADR-009)

Unit tests added covering `SettingsService::seedRgsTemplate()` (OpenRegister-unavailable path, unknown-variant path, delegation path) and `InitializeSettings::run()` (skip path, success path with seed, warning path). PHPUnit passes on the covering tests.

## Documentation (company-wide ADR-010)

The implementation cycle authors `docs/user-guide/bookkeeping/chart-of-accounts.md` per ADR-030 journeydoc convention and commits a Chart of Accounts index screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Chart of Accounts`, `Account`, `Account Number`, `Account Type`, `Closing Account`, `Active`, `Blocked`, `Archived`.
