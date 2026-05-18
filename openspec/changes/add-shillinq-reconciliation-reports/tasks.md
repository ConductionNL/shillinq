# Tasks — Reconciliation Reports

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-reconciliation-reports` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact
> are all visible at proposal time. No source files are edited by
> this change itself.

## Tasks

- [ ] Task 1: Confirm no `Budget` schema, no `bookkeeping-reconciliation-reports` capability, and no `lib/Service/*Report*.php` / `*Reconciliation*.php` / `*Variance*.php` classes already exist (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`, `lib/Service/`)
- [ ] Task 2: Author `specs/bookkeeping-reconciliation-reports/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine)` / `Depends on: bookkeeping-general-ledger (T1), bookkeeping-accounts-payable-core (T2), bookkeeping-accounts-receivable-core (T2)` header, `REQ-RR-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN; explicitly cites `feedback_mydash-no-or-dependency.md`
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (engine support, report-engine creep, mydash dep drift) / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [ ] Task 4: Author `design.md` with Decisions (saved-query not report engine, severity as calculated field, mydash runtime-GraphQL no install-time dep, cross-administration engine-vs-guard resolved in opsx-ff) and Reuse Analysis table per hydra `rules.design`
- [ ] Task 5: Declare the `Budget` schema in `lib/Settings/shillinq_register.json` with REQ-RR-004 fields (accountNumber, periodId, budgetAmount, currency, administrationId, lifecycleState) and FK from `accountNumber → Account.accountNumber`
- [ ] Task 6: Declare the sub-ledger ↔ GL match saved-query as `x-openregister-aggregations` on a `SavedQuery` record per REQ-RR-002 (joins T2 sub-ledger objects with T1 `GLLine` aggregations on `subLedgerRef`)
- [ ] Task 7: Declare the intercompany match saved-query as `x-openregister-aggregations` on a `SavedQuery` record per REQ-RR-003 (cross-administration join; shape-neutral — engine-vs-guard decision deferred to opsx-ff)
- [ ] Task 8: Declare the variance vs `Budget` saved-query as `x-openregister-aggregations` on a `SavedQuery` record per REQ-RR-004 (joins T1 `GLLine` aggregations to `Budget`; threshold check as calculated field)
- [ ] Task 9: Declare the controller exception report saved-query as `x-openregister-aggregations` on a `SavedQuery` record per REQ-RR-005 (consolidates REQ-RR-002/003/004 outputs; severity classification as calculated field; sortable by severity)
- [ ] Task 10: Add Reconciliation Reports + Budgets navigation + pages to `src/manifest.json` (`Bookkeeping > Reconciliation Reports` listing the saved-query catalog with each report rendered via `type: detail` pages bound to the saved-query metadata, plus a `Bookkeeping > Budgets` index/detail pair) per REQ-RR-006; `node tests/validate-manifest.js` exits 0
- [ ] Task 11: (Conditional, only if opsx-ff discovery confirms aggregation engine cannot express) Author `lib/Aggregation/IntercompanyMatchGuard.php` (single method `matchPostings(string $groupId, string $periodId): array`, ~20 LOC, ADR-031 exception annotated) and/or `lib/Aggregation/BudgetVarianceJoinGuard.php` (single method `computeVariance(string $accountNumber, string $periodId, string $administrationId): array`); each carries an exception annotation linking back to design.md
- [ ] Task 12: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph reconciliation note introducing `Budget` and noting the four saved-query records consumed by mydash via runtime GraphQL (no install-time dep per `feedback_mydash-no-or-dependency.md`)

## Verification

`openspec validate` must exit clean on the change folder. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance — specifically: no `lib/Service/*Report*.php` / `*Reconciliation*.php` / `*Variance*.php` classes; reports declared as `SavedQuery` / `x-openregister-aggregations`; severity as calculated field; mydash carries no install-time dep on shillinq (grep `mydash/appinfo/info.xml` for `<dependencies>`); if conditional guards are authored, each is single-method with ADR-031 exception annotation. No source code changes outside `openspec/changes/add-shillinq-reconciliation-reports/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests asserting matched reconciliation reports zero variance; mismatched surfaces as exception; intercompany match for grouped administrations; within-threshold variance does not flag; exception report sorted by severity; mydash GraphQL discovery (pre-declared on Tasks 5–9); if conditional guards land, PHPUnit covers matched-pair zero variance, unmatched leg returns open amount, mixed-currency match uses base-currency amount, within/above-threshold variance cases (Task 11); Playwright MCP browser tests for the Reports + Budgets pages and mydash widget end-to-end (Task 10); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/reconciliation-reports.md` per ADR-030 journeydoc convention and commits a controller-exception-report screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Reconciliation Report`, `Aansluitingsrapport`, `Variance`, `Verschil`, `Exception`, `Uitzondering`, `Controller`, `Controlerend Boekhouder`, `Budget`, `Budget vs Actual`, `Critical`, `Warning`, `Info`, `Severity`.
