# Tasks: budget-core-schema

## 1. Resolve the `Budget` collision — schema rename (REQ-BCS-001)
- [x] Rename `bookkeeping-provincies-bbv-variant.json:67`'s schema key
      `Budget` → `BbvProgrammeBudget` (slug + title updated to match;
      required/properties/icon/x-spec unchanged otherwise, `design.md` §1b).
- [x] Rename `bookkeeping-verplichtingenadministratie.json:922`'s schema key
      `Budget` → `CommitmentBudget` (same treatment). Also fixed a
      pre-existing gap encountered on the same lines: `CommitmentBudget` was
      missing `x-openregister-audit-trail` (REQ-AT-001) — added.
- [x] Update both fragments' seed `objects[]` blocks: `@self.schema` values
      (6 BBV rows, 2 verplichtingen rows) to the new slugs, and — while
      already editing these exact lines — prefix the BBV fragment's example
      seed slugs with `example-` per ADR-001 (`design.md` §2d; the
      verplichtingen fragment's 2 seed slugs were also non-compliant and
      prefixed too).
- [x] Update `bookkeeping-provincies-bbv-variant.json`'s
      `BbvComplianceDashboard` widget `config.schema` to `BbvProgrammeBudget`
      (actually lives in `src/manifest.d/bookkeeping-provincies-bbv-variant.json`,
      not the register.d fragment — design.md's line-35 reference pointed at
      the register.d file; corrected here).
- [x] Update `bookkeeping-verplichtingenadministratie.json:536`'s
      `committedVsRealisedPerBudgetLine.join.through` to `CommitmentBudget`
      (`join.on`/`join.select` field-qualifiers updated too).
- [x] Also added `AnnualBudget`/`BudgetLine`/`LedgerGroup` and removed
      `Budget` from `lib/Settings/register.d/000-register-declaration.json`'s
      explicit `schemas[]` enumeration (undocumented in design.md/tasks.md,
      but this file is the ONLY place a schema is registered against the
      `shillinq` Register row — omitting it would make every new schema
      unreachable; discovered while implementing, fixed in scope).

## 2. Update PHP/JS consumers (REQ-BCS-002)
- [x] Delete `lib/Service/BbvBudgetVocabulary.php` (`design.md` §2c).
- [x] `lib/Service/BbvProgrammeBudgetReader.php`: `SCHEMA_BUDGET` constant →
      `'BbvProgrammeBudget'`; removed the `BbvBudgetVocabulary $vocabulary`
      constructor dependency; replaced `vocabulary->year()`/`->programme()`/
      `->amount()` call sites with direct field reads
      (`$budget['fiscalYear']`, `$budget['programmeStructure']`,
      `$budget['totalAmount']`).
- [x] `lib/Service/BbvProgrammeBudgetService.php`: updated the docblock
      comment referencing `Budget.totalAmount` to `BbvProgrammeBudget.totalAmount`
      (no functional literal to change).
- [x] `lib/Lifecycle/BudgetBlocker.php:195`: `schema: 'Budget'` →
      `schema: 'CommitmentBudget'`.
- [x] `lib/Service/Commitment/CommitmentMaterialisationService.php:515`:
      `schema: 'Budget'` → `schema: 'CommitmentBudget'`.
- [x] `src/views/budgetLineCommitmentsHelpers.js`: updated the aggregation
      bucket-key read (`Budget.authorised_amount`) to
      `CommitmentBudget.authorised_amount` (`design.md` §2a — the response
      bucket key follows `join.through`); docblock updated too.
- [x] Confirmed `src/views/BudgetLineCommitments.vue` needs no direct edit
      (it consumes the helper's normalised output only) — verified by
      re-reading the file; no literal schema string present.

## 3. Update PHPUnit tests for the rename (REQ-BCS-002)
- [x] `tests/Unit/Lifecycle/VerplichtingWorkflowTest.php`: `'Budget'`
      mock-array key → `'CommitmentBudget'` (5 occurrences).
- [x] `tests/Unit/Service/Commitment/CommitmentMaterialisationServiceTest.php`:
      same rename (8 occurrences).
- [x] `tests/Unit/Service/RequisitionServiceTest.php`: same rename
      (2 occurrences).
- [x] `tests/Unit/Service/VerplichtingenCommitmentAccountingFragmentTest.php`:
      `assertSame('Budget', $agg['join']['through'])` and 2 other spots →
      `'CommitmentBudget'`.
- [x] `tests/Unit/Service/ProvinciesBbvFragmentTest.php`: `'Budget'` →
      `'BbvProgrammeBudget'` (5 occurrences incl. prose).
- [x] **Not in design.md's inventory, found while implementing and fixed in
      scope**: `tests/Unit/Lifecycle/BudgetBlockerTest.php` (8 occurrences,
      `'Budget'` → `'CommitmentBudget'`) and
      `tests/Unit/Service/BbvProgrammeBudgetServiceTest.php` (seed +
      `'Budget'` → `'BbvProgrammeBudget'`, plus removed
      `testABudgetWrittenInEitherVocabularyIsCounted()` — it asserted the
      now-retired dual-vocabulary tolerant-read behaviour, which no longer
      exists once the schemas are distinct; replaced with
      `testOnlyBbvProgrammeBudgetVocabularyIsRead()`).

## 4. Migrator (REQ-BCS-003)
- [x] Added `lib/Service/Migration/BudgetSchemaSplitMigrator.php`, modelled
      on `SubsidieOrderConsolidationMigrator.php`: `classify(array $object): ?string`
      (BBV-shaped / Commitment-shaped / null-unclassifiable, `design.md`
      §2b), `migrateBatch()` (splits into `[BbvProgrammeBudget => [...],
      CommitmentBudget => [...]]`), `assertCountsMatch()` (RuntimeException
      on any mismatch, source left intact).
- [x] Unit tests (`BudgetSchemaSplitMigratorTest`, 10 tests): both
      vocabularies classify correctly, a deliberately ambiguous (both
      vocabularies) AND a malformed (neither) row classify `null` and abort
      the batch, count mismatch aborts without touching source data.
- [x] Re-ran the live OR-API count check
      (`GET /apps/openregister/api/objects?register=shillinq&schema=Budget&_limit=1`)
      against the shared dev instance (`localhost:8080`) immediately before
      implementing the rename: **`total: 0`**, confirming the 2026-08-20
      measurement still holds. No other real shillinq deployment was
      identified to re-check against in this environment.

## 5. `LedgerGroup` schema + seeds (REQ-BCS-004, REQ-BCS-005)
- [x] Added `LedgerGroup` to a new `lib/Settings/register.d/budget-core-schema.json`
      fragment: `administrationId`, `code`, `name`, `order`,
      `parentLedgerGroupId` (nullable self-FK, resolved by the parent's
      `@self.slug` — matches the `GLLine.transactionId` dual-key/slug-ref
      idiom already live in this codebase's own seed data, since a real
      OpenRegister id is not knowable at seed-authoring time),
      `accountRanges` (array of `{from, to}`), `includedAccountNumbers`,
      `excludedAccountNumbers`, `effectiveFrom`/`effectiveTo` (nullable) —
      per `design.md` §3b. Declared `x-openregister-audit-trail.enabled: true`
      (REQ-AT-001).
- [x] **Amended (2026-08-20): seeded from `rj270-pl.json`, not
      `rj270-balance-sheet.json`** — 16 leaf `LedgerGroup`s, one per
      `rj270-pl.json` `level: 1` section (`NETO`/`WVPV`/`GEAC`/`OVOP`/
      `KPVO`/`INKW`/`LONE`/`SOCL`/`AFSC`/`HUIS`/`EXPL`/`VKKO`/`ALGK`/
      `RBAT`/`RLST`/`VPB`), each with that section's own `accountRange`,
      plus 3 parent `LedgerGroup`s with empty `accountRanges`/
      `includedAccountNumbers`/`excludedAccountNumbers` (`Omzet` parenting
      `NETO`/`WVPV`/`GEAC`/`OVOP`; `Personeel` parenting `LONE`/`SOCL`;
      `Kostprijs van de omzet` parenting `KPVO`/`INKW`) — 19 `LedgerGroup`
      rows total, per `design.md` §3c's table (verified: 3+4+2+2+8=19). Each
      seed row: `@self.seedExemption: "anchor"`, plain (non-`example-`)
      slug `ledger-group-<rj270-code-lowercased>` for leaves,
      `ledger-group-omzet`/`ledger-group-personeel`/
      `ledger-group-kostprijs-van-de-omzet` for the three parents. Did
      **not** seed any `rj270-balance-sheet.json` section.
- [x] `node tests/validate-registers.js` — PASS (473/473 bookkeeping schemas
      carry the audit-trail flag; no slug-case collision).

## 6. `AnnualBudget` schema + lifecycle guard (REQ-BCS-006)
- [x] Added `AnnualBudget` to the same fragment: `administrationId`,
      `fiscalYear`, `name`, `isDefault` (boolean, default false), `state`,
      `x-openregister-lifecycle` (`draft -> active -> closed`, `activate`
      transition `requires: OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard::isUniqueDefault`)
      per `design.md` §4a.
- [x] Added `lib/Lifecycle/AnnualBudgetDefaultGuard.php` (ADR-031
      exception-path guard, same shape as `RateScheduleOverlapGuard`):
      rejects `activate` when another `AnnualBudget` with `isDefault=true`
      already exists for the same `administrationId`+`fiscalYear`. **Also
      registered the guard's literal `Class::method` tag in
      `lib/AppInfo/Application.php` via `RegisterRequiresGuardAdapter`** —
      not in design.md/tasks.md, but shillinq#425/#433's own documented
      defect class means a `requires: "Class::method"` string NEVER
      resolves through Nextcloud's container without an explicit alias;
      every guard added since that fix follows this registration
      convention, and skipping it here would have shipped a
      declared-but-never-enforced guard (found and fixed while
      implementing).
- [x] PHPUnit: `AnnualBudgetDefaultGuardTest` (6 tests) — a second default
      is rejected; a default for a different fiscal year/administration is
      accepted; a non-default budget is unaffected by siblings; no
      siblings/first default is accepted; the candidate itself is not
      treated as its own conflict.

## 7. `BudgetLine` schema (REQ-BCS-007)
- [x] Added `BudgetLine` to the same fragment: `administrationId`,
      `annualBudgetId` (FK, uuid), `ledgerGroupId` (FK, uuid), `source`
      (enum `manual|contract|recurring|projected|scenario`, default
      `manual`), `month01Amount`..`month12Amount` (integer, default 0,
      minor units), `notes` (nullable) — per `design.md` §5a. No stored
      total/cumulative field (`design.md` §5b decision still current —
      no product sign-off on §11.4 found, so the named fields stand).
- [x] `x-openregister-audit-trail.enabled: true` added; confirmed
      `node tests/validate-registers.js` still passes with all three new
      schemas.

## 8. Budget-vs-actuals roll-up — PHP primary + hazard positive control (REQ-BCS-008)
- [x] **Amended (2026-08-20): actuals come from `GLTransaction`+`GLLine`+
      `Account`, not `TrialBalanceLine`.** Added
      `lib/Service/BudgetVsActualsReader.php` +
      `lib/Service/BudgetVsActualsCalculator.php` (reader/calculator split
      mirroring `BbvProgrammeBudgetReader`/`Calculator`): reader resolves
      `LedgerGroup` member accounts (ranges + explicit include/exclude,
      resolved in PHP, dual-keyed by id/slug) and GL activity via the
      ≤5-call batched shape in `design.md` §6b; calculator sums budgeted vs.
      actual per line, applying the parent-rollup rule (`design.md` §3d) —
      **asymmetric by design**: budgeted uses "own `BudgetLine` wins over
      children-sum" (prevents double counting an operator's own two
      choices), actual always sums own-accounts + children unconditionally
      (GL money across disjoint account memberships cannot double-count) —
      see the calculator's own docblock for the full reasoning.
- [x] PHPUnit coverage for both classes (17 + 8 tests, no e2e), including a
      call-count regression test against a real call-counting
      `ObjectServiceInterface` decorator (`CallCountingObjectServiceDecorator`,
      wrapping a filter-aware fixture store since the fleet-wide
      `InMemoryObjectServiceStub` double doesn't support the `{in: [...]}`
      filter shape this reader's `BudgetLine` batch read needs) asserting
      the exact ≤5-call bound — a future change reintroducing a per-account
      or per-month `findAll()` call will fail this test.
- [x] Positive-control task run and recorded (`specs/bookkeeping-
      verplichtingenadministratie/spec.md`'s REQ-VPL-011 delta and
      `design.md` §11.2) — **with a caveat, stated plainly, not silently
      assumed clean**: the platform hazard IS confirmed live on the shared
      dev instance (40 `"annotation on schema"` warnings, `decidesk`
      schemas, 2026-08-20). A shillinq-specific DYNAMIC measurement could
      NOT be completed — the shared instance runs a pre-rename shillinq
      build with no working aggregation-proxy route, and deploying this
      in-progress branch there was judged out of scope. STATIC analysis
      against the real declared property lists stands in: confirms
      `outstanding_commitments` is discarded per the documented hazard, AND
      found a NEW independent defect — `committedVsRealisedPerBudgetLine`'s
      `join.select` references `CommitmentBudget` field names
      (`geautoriseerd_bedrag`/`gerealiseerd_bedrag`) that do not exist under
      any spelling (real fields: `authorised_amount`/`realised_amount`).
      Full detail and the outstanding live re-check: see the spec delta.
- [x] Added a *documentation-only* `x-openregister-aggregations.budgetVsActuals`
      entry on `BudgetLine` (per `design.md` §6b), explicit comment marking
      it UNVERIFIED/EXPECTED-DISCARDED pending the positive control above —
      no page depends on it.

## 9. Minimal pages + nav placement — sequenced on byte headroom (REQ-BCS-009, REQ-BCS-010)
- [x] **Before starting this group**: ran `node tests/check-manifest-budget.js`
      — `nav-six-clusters`/PR #923 had ALREADY merged onto this branch
      (`git merge origin/development` at worktree setup pulled it in), so
      headroom was measured at **32,170 bytes** (manifest.json=452689B
      manifest.d/=641441B total=1094130B budget=1126300B), comfortably
      above the ~8,000B gate.
- [x] Added `src/manifest.d/budget-core-schema.json`: new top-level menu
      group `id: "Budgets"` (icon `PiggyBankOutline` — Wallet was already
      taken by `CommitmentBudget`'s schema icon; order 29, adjacent to
      Cashflow's own order 28 — `design.md` §11.3's icon/order question,
      resolved) with three children (`AnnualBudgets`, `LedgerGroups`,
      `BudgetLines`), and six pages (3 index + 3 detail) per `design.md`
      §7a. Modelled on the CURRENT `dba-compliance-marker.json` pattern
      (`DBAOpdrachten`/`DBAOpdrachtDetail`, design.md's own citation, was
      itself deleted by `nav-six-clusters` as a duplicate before this
      change started).
- [x] **Deviation from design.md §7b, justified by changed circumstances**:
      §7b's "new flat top-level group, coordination item for whichever
      change lands second" instruction was explicitly conditional on
      `nav-six-clusters` NOT having merged yet. It HAD already merged onto
      this branch by implementation time (Cluster 4 `BankingCashflow` exists
      in `src/manifest.json` with `children: []`, the reserved leaf). Rather
      than leave `Budgets` as a temporary flat top-level group for a future
      change to relocate, this change does BOTH steps now: declares
      `Budgets` (as §7a already required) AND adds
      `"Budgets": "BankingCashflow"` to `src/menu-layout.json#relocations`
      in the same commit — the single mechanical fold §7b itself describes,
      done immediately since its own precondition already held.
- [x] `AnnualBudgetDetail` includes a `budgetLines` related list (FK
      `annualBudgetId`); `LedgerGroupDetail` includes a `children` related
      list (FK `parentLedgerGroupId`) plus fields for the resolved
      account-range/explicit-include/exclude display.
- [x] `node tests/check-manifest-budget.js` — PASS. **Exact delta:
      manifest.d/ 641441B → 650045B (+8,604B)** — somewhat above the
      5,676–7,656B estimate (the `relatedLists`/`sidebarProps`/audit-trail
      blocks on the detail pages cost more than the estimator's median/mean
      page), still comfortably within budget: total 1102734B vs 1126300B
      budget, 23,566B headroom remaining.
- [x] `npm run check:nav-reachability` — PASS: "0 new orphans (21
      baselined, 0 stale warnings)" — all 6 new pages are reachable via
      `Budgets` (nested under `BankingCashflow` post-relocation) plus
      cross-links (`detailRoute`/`indexRoute`).

## 10. e2e coverage (REQ-BCS-009)
- [x] Added `tests/e2e/budget-core-schema.spec.ts` covering
      `budget-core-schema::budgets-nav-group-reachable`,
      `budget-core-schema::ledger-group-seeded-on-import`,
      `budget-core-schema::budget-line-monthly-columns-editable`
      (`design.md` §9). `budget-line-commitments.spec.ts`'s own
      `becomesVisible` helper models a drilldown-expand interaction this
      change's plain CRUD pages don't have; modelled instead on
      `provincies-bbv-variant.spec.ts`'s `gotoRoute()`/generic
      `cn-index-page`/`cn-detail-page` testid conventions (this change's
      pages, like that one's, declare no custom `component`), with the same
      data-defensive `test.skip()` discipline. **NOT EXECUTED**, per the
      implementer's brief — lint-clean (`npx eslint`) but not run against a
      live instance; the BudgetLine create-flow test's exact form-field
      selectors (`page.getByLabel(...)`) should be spot-checked against a
      live run before this ships.
- [x] Every test tagged `@e2e budget-core-schema::<slug>` matching
      `specs/budget-core-schema/spec.md`'s scenario ids exactly (gate-19 /
      `hydra-gate-e2e-coverage`) — tags live in the file-level docblock per
      the `invoice-quick-draft.spec.ts`/`list-views-cndatatable.spec.ts`
      convention (one `@e2e` line per covered scenario, not per `test()`).

## 11. Spec sync (REQ-BCS-011 and delta specs)
- [x] Applied `specs/bookkeeping-provincies-bbv-variant/spec.md`'s delta
      (REQ-BBC-001/002 renamed, dead `../budget-planning-control/spec.md`
      dependency reference corrected to `../budget-core-schema/spec.md`) to
      `openspec/specs/bookkeeping-provincies-bbv-variant/spec.md`.
- [x] Applied `specs/bookkeeping-verplichtingenadministratie/spec.md`'s
      delta (REQ-VPL-011 join-target rename + the §6a positive-control
      finding, recorded per its actual measured outcome, task group 8) to
      `openspec/specs/bookkeeping-verplichtingenadministratie/spec.md`.
- [x] Grepped `openspec/specs/*/spec.md` for the bare `Budget` schema slug —
      found and fixed 2 more genuine schema-literal references beyond the
      two deltas (`purchase-requisition/spec.md`'s `RequisitionService`
      scenarios, `bookkeeping-multi-administratie/spec.md`'s financial-schema
      list) — both renamed to `CommitmentBudget`. 5 other matches
      (`bookkeeping-programmabegroting`, `bookkeeping-r-d-subsidies-mkb`,
      `realtime-updates`'s `BudgetBBVMapping` — a different, unaffected
      schema per `design.md`'s "Not affected" list —,
      `bookkeeping-rechtmatigheidsverantwoording`'s Dutch
      `begrotingsruimte`/`begrotingstoets` prose, `bookkeeping-continuous-close`'s
      table-column header) verified as generic English/Dutch prose, not
      schema-literal references — left unchanged.

## 12. Validation
- [x] `node tests/check-manifest-budget.js` — PASS. `manifest.json=452689B
      manifest.d/=650045B total=1102734B budget=1126300B` (23,566B
      headroom remaining).
- [x] `node tests/validate-registers.js` — PASS. 473/473 bookkeeping
      schemas carry `x-openregister-audit-trail.enabled=true`; no
      slug-case collision.
- [x] `npm run check:nav-reachability` — PASS: "0 new orphans (21
      baselined, 0 stale warnings)".
- [x] `grep -rln "'Budget'\|\"Budget\"" lib/ src/ tests/ --include=*.php --include=*.js --include=*.vue --include=*.json | grep -v node_modules` —
      verified by eye: every remaining match is either a substring of
      `BbvProgrammeBudget`/`CommitmentBudget`, an unrelated pre-existing
      "budget"-labelled UI field (cost-center `budget` property, chart
      series labels, table columns — none are the retired schema slug), or
      the migrator's own deliberate `SOURCE_SCHEMA = 'Budget'` reference
      (its whole job is classifying rows still under that retired slug).
      Zero stray schema-literal references.
- [x] Full PHPUnit suite run: **4657 tests, 45416 assertions, 0 failures,
      1 pre-existing skip, exit 0** — every touched/new file green,
      nothing else in the fleet broken by this change.
- [x] `npx vitest run` — **205/205 tests green** across all 18 files,
      including `budgetLineCommitmentsHelpers.spec.js` (fixed: its fixture
      still used the retired `Budget.authorised_amount` bucket key — found
      and corrected in scope, not in design.md's original inventory).
- [x] Hydra gates (`run-hydra-gates.sh`, `HYDRA_GATE_BASE_REF=origin/development`)
      — **all 63 applicable gates GREEN** (9 not-applicable to this
      repo/diff, named and excluded from coverage; 1 advisory-only WARNING
      gate with 19 pre-existing, unrelated Tier-B findings). First run
      caught a REAL new defect: `gate-60 icon-vocabulary` FAILED because
      `FormatListGroup` (LedgerGroup) and `TableColumn` (BudgetLine/menu)
      were valid MDI icon names but not registered in `src/icons.js` — they
      would have rendered with NO icon at all, not a fallback. Fixed by
      registering both (import + registration entry, alphabetically
      placed); re-run confirmed `gate-60: PASS` and the full suite green.
- [x] `tests/e2e/budget-core-schema.spec.ts` written (3 scenarios, tagged
      per gate-19), lint-clean — **NOT EXECUTED**, per the implementer's
      brief.
- [x] `openspec validate budget-core-schema --strict` — PASS ("Change
      'budget-core-schema' is valid"). Also ran `openspec validate --all
      --strict`: every spec this change touches
      (`bookkeeping-provincies-bbv-variant`, `bookkeeping-verplichtingenadministratie`,
      `bookkeeping-multi-administratie`, `purchase-requisition`) passes; the
      9 project-wide failures elsewhere are pre-existing and unrelated
      (inventory/bookings/sisa-reporting/jaarrekening/spend-analytics/
      gl-tax-capabilities domains).
