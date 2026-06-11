# Tasks — Financial Statements

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-financial-statements` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-financial-statements` capability spec already exists, no `BalanceSheet`/`TrialBalance`/`ConsolidatedReport`/`ConsolidationGroup` schemas are declared, and no `lib/Service/Financial*` / `lib/Service/Consolidation*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "enables comprehensive audit-ready financial reporting"
- [x] Task 2: Author `specs/bookkeeping-financial-statements/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-chart-of-accounts, bookkeeping-general-ledger` header, `REQ-FS-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (consolidation-extension stability, publication-workflow complexity, aggregation performance) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (statements are aggregates), D2 (balance-sheet as aggregation), D3 (trial-balance as aggregation), D4 (OR consolidation consumed), D5 (lifecycle draft→final→published), D6 (elimination rules declared)
- [x] Task 5: Declare the `BalanceSheet` schema in `lib/Settings/shillinq_register.json` with all REQ-FS-002 fields (reportDate, totalAssets, totalLiabilities, totalEquity, currency, status, fiscalYearId, administrationId)
- [x] Task 6: Declare the `TrialBalance` schema in `lib/Settings/shillinq_register.json` with all REQ-FS-005 fields (reportDate, totalDebits, totalCredits, isBalanced, status, preparedBy, fiscalYearId, administrationId)
- [x] Task 7: Declare the `ConsolidationGroup` schema in `lib/Settings/shillinq_register.json` with all REQ-FS-006 fields (name, consolidationMethod, status, parentOrganizationId, eliminationRules, administrationIds)
- [x] Task 8: Declare the `ConsolidatedReport` schema in `lib/Settings/shillinq_register.json` with all REQ-FS-006 fields (reportNumber, reportDate, consolidationGroupId, consolidationMethod, eliminationsApplied, status, fiscalYearId)
- [x] Task 9: Add `x-openregister-lifecycle` to `BalanceSheet`, `TrialBalance`, and `ConsolidatedReport` declaring every transition in REQ-FS-003 (draft → final → published) consuming OR publication extension (or fallback per ADR-031 exception, documented)
- [x] Task 10: Declare balance-sheet aggregation per REQ-FS-004 as `x-openregister-aggregations` predicate computing totalAssets, totalLiabilities, totalEquity from GL entries grouped by Account.accountType — not a service
- [x] Task 11: Declare trial-balance aggregation per REQ-FS-005 as `x-openregister-aggregations` query listing GL accounts with totalDebits/totalCredits/isBalanced check — not a service
- [x] Task 12: Declare consolidation workflow per REQ-FS-006 consuming OR consolidation extension (or fallback per ADR-031 exception) — inter-company elimination rules declared as object fields on `ConsolidationGroup`
- [x] Task 13: Declare financial-statement publication workflow per REQ-FS-007 consuming OR publication extension (or fallback per ADR-031 exception) — triggers on `final → published` transition
- [x] Task 14: Add 4 manifest navigation entries (`Balance Sheet`, `Trial Balance`, `Consolidations`, `Consolidated Report`) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-FS-008; `node tests/validate-manifest.js` exits 0
- [x] Task 15: Update `openspec/architecture/adr-000-data-model.md` with `BalanceSheet`/`TrialBalance`/`ConsolidatedReport`/`ConsolidationGroup` entries, noting they are read-only aggregates over GL entries

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the financial-reporting flow matches Dutch SMB / public-sector practice (GL posting → balance-sheet → trial-balance → consolidation → publication). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local report-generation service; lifecycle declarative or ADR-031-exception-annotated guard; manifest carries the navigation). No source code changes outside `openspec/changes/bookkeeping-financial-statements/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for balance-sheet aggregation, trial-balance verification, consolidation elimination rules, publication workflow, lifecycle transitions (pre-declared on Tasks 5–13); Playwright MCP browser tests for the 4 manifest navigation entries and GL-to-statement aggregation pipeline (pre-declared on Task 14); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/financial-statements.md` per ADR-030 journeydoc convention and commits financial-statement + consolidation screenshots to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Financial Statements`, `Balance Sheet`, `Trial Balance`, `Consolidations`, `Consolidated Report`, `Assets`, `Liabilities`, `Equity`, `Total Debits`, `Total Credits`, `Balanced`, `Consolidation Group`, `Elimination Rules`, `Published`, `Final`, `Draft`.

## External adapter

- [x] Adapter port: dormant `DigipoortSbrAdapterInterface` + `LogDigipoortSbrAdapter` shipped at `lib/Service/External/Digipoort/` and wired in `lib/AppInfo/Application.php::register()`. The `deponering` lifecycle transition for jaarrekening + consolidations can advance without a live Digipoort connector; the same port covers KvK + DNB SBR delivery once an openconnector source slug `digipoort-sbr` is provisioned.
