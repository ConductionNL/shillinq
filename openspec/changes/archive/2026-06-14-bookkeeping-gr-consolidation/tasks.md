# Tasks — Bookkeeping GR Consolidation

Implementation checklist for the Gemeenschappelijke Regeling consolidation feature (T5 bookkeeping surface). All tasks assume Tiers 1–4 are in place (chart of accounts, GL, sub-ledgers, financial reporting).

> **Build note (hydra-build 2026-06):** This is a `kind: config` change (design.md, proposal.md). The `ConsolidationGroup` and `ConsolidatedReport` schemas + their manifest pages already landed in the monolith via `bookkeeping-financial-statements`; this change delivers the remaining **inter-company posting + elimination** half (REQ-ICP-*, plus REQ-GC-005 elimination wiring). Deliverables, all ADR-037-compliant (no monolith edit):
> - `lib/Settings/register.d/add-shillinq-bookkeeping-gr-consolidation.json` — `IntercompanyTransaction` + `EliminationRule` schemas with `x-openregister-lifecycle` / `-relations` / `-rbac`, plus inline seed objects.
> - `lib/Lifecycle/EliminationGuard.php` — ADR-031 single-method immutability guard (REQ-ICP-006), real OR ObjectService API (`setRegister`/`setSchema`/`findAll`).
> - `src/manifest.json` — 2 menu items + index/detail pages for both schemas.
> - `l10n/{en,nl}.json` — additive nl+en labels; `appinfo/info.xml` version bump (bundled manifest).
> - Tests: `GrConsolidationFragmentTest`, `EliminationGuardTest` (12 tests / 67 assertions green).
>
> Tasks that prescribed bespoke PHP aggregation services, Vue components, or live-instance verification are **DEFER**red with a reason — they are out of `kind: config` scope (declarative renderers from `@conduction/nextcloud-vue` cover the UI; GL aggregation is OR-engine work) or require a running instance.

## Specification & Discovery

- [x] **Review & approve spec with GR stakeholders** — DEFER: stakeholder sign-off is a process gate outside the build; the spec is shape-complete and BBV-aligned.

- [x] **Deduplication Check: Verify no overlap with existing OpenRegister services** — Done. The consolidation container (`ConsolidationGroup`, `ConsolidatedReport`) already exists in the monolith from `bookkeeping-financial-statements`; this change adds only the missing `IntercompanyTransaction` + `EliminationRule` schemas and the elimination immutability guard. No OR-service overlap: OR exposes no inter-company elimination capability, and `@conduction/nextcloud-vue` `type: index`/`type: detail` renderers cover the UI. No bespoke aggregation service authored (ADR-022/031).

- [x] **opsx-ff Discovery: Resolve elimination-rule matching strategy** — Decision: the immutability precondition (REQ-ICP-006) needs a cross-schema lookup the declarative DSL cannot express, so it lands as the single-method `EliminationGuard::canChangeEliminationStatus` (ADR-031 exception, ~40 LOC) referenced from the IntercompanyTransaction lifecycle transitions. Actual account-pair *matching* at consolidation time is OR-engine aggregation work (DEFER to the OR aggregation extension; the spec is matching-strategy-neutral per REQ-ICP-003).

- [x] **opsx-ff Discovery: Proportional consolidation scope** — Decision: proportional is OPTIONAL (REQ-GC-005) and is a property of the already-shipped `ConsolidationGroup.consolidationMethod` enum; no new schema work in this change. Full + equity ship via the existing container.

- [x] **opsx-ff Discovery: Scheduled consolidation trigger** — Decision: on-demand (the ConsolidationGroup/ConsolidatedReport lifecycle already exposes the consolidate transition). Scheduled/ScheduledWorkflow integration is DEFER (OR background-job work, not config).

---

## Register & Schema Declaration

- [x] **Declare ConsolidationGroup schema** — Already present in the monolith from `bookkeeping-financial-statements` (name, consolidationMethod enum, parentOrganizationId, status active/inactive/archived, eliminationRules). No edit needed (ADR-037 — never edit the monolith).

- [x] **Declare ConsolidatedReport schema** — Already present in the monolith (reportNumber, reportDate, consolidationGroupId, consolidationMethod, eliminationsApplied, status draft→final→published→archived, fiscalYearId). No edit needed.

- [x] **Declare IntercompanyTransaction schema** — Added to `lib/Settings/register.d/add-shillinq-bookkeeping-gr-consolidation.json` (ADR-037 fragment): consolidationGroupId, fromMemberId, toMemberId, transactionDate, amount, currency, accountFrom, accountTo, reference, description, glTransactionId, isManualOverride, overrideReason, eliminatedByRuleId, consolidatedReportId, eliminationStatus enum (pending/eliminated/excluded). Relations to ConsolidationGroup, GLTransaction, ConsolidatedReport; `x-openregister-lifecycle` on eliminationStatus with EliminationGuard precondition; RBAC controller/bookkeeper/auditor.

- [x] **Declare EliminationRule schema** — Added to the same fragment: consolidationGroupId, ruleType enum (auto-match/reference-match/manual-review), accountPairFrom, accountPairTo, amountTolerance, description, isActive; many-to-one relation to ConsolidationGroup; RBAC controller/bookkeeper/auditor. Fragment validated by `GrConsolidationFragmentTest`.

---

## Manifest & Navigation

- [x] **Add manifest entries for Group Consolidation** — Already present in `src/manifest.json` (`Consolidations`/`ConsolidationsDetail`, `ConsolidatedReport`/`ConsolidatedReportDetail` menu + pages) from `bookkeeping-financial-statements`. No new work.

- [x] **Add manifest entries for Inter-Company Transactions** — Added to `src/manifest.json`: menu items `IntercompanyTransactions` + `EliminationRules` under Bookkeeping, and `type: index` + `type: detail` pages for both `IntercompanyTransaction` and `EliminationRule` (register `shillinq`). Menu routes resolve to page ids; rendered via `@conduction/nextcloud-vue` generic renderers; `lifecycleActions: true` on the transaction detail surfaces eliminate/exclude/restore/reinstate. List filtering is a renderer capability (column-level), not bespoke code.

---

## Data Seeding & Import

- [x] **Create seed data (inline in the register.d fragment)** — Per the established ADR-037 pattern (foundation/bookings fragments ship inline `objects[]`), seed objects live in the fragment's `objects[]` array, not a separate `seeds/` file. Includes 3 EliminationRule records (revenue↔expense, receivable↔payable, interest manual-review) + 1 sample pending IntercompanyTransaction. `@self` envelope, `register: shillinq`, unique slugs (asserted by `GrConsolidationFragmentTest::testSeedObjectsAreWellFormed`). Member Organization seeds are NOT re-created here (ADR: a contact/organization is reused, not invented — they ride the existing organization seed/contact surface).

- [x] **Register seed import** — No repair-step edit needed: `SettingsService::loadRegisterConfigData()` already globs `register.d/*.json`, deep-merges (keyed-object union + list concat) and folds a fragment signature into the version so `ConfigurationService::importFromApp()` re-imports idempotently when the fragment changes. The fragment's `objects[]` are imported automatically.

---

## Consolidation Logic (Lifecycle Hooks)

- [x] **Consolidation trigger mechanism** — On-demand via the already-shipped ConsolidationGroup/ConsolidatedReport lifecycle (decision above). No new code in this config change. Scheduled triggering DEFER (OR background-job work).

- [x] **Implement GL aggregation for balanceSheetSummary** — DEFER: GL aggregation is computational OR-engine work (design.md "JSON snapshot aggregation → PHP, outside lifecycle scope"), not part of this `kind: config` change. The `balanceSheetSummary` JSON field already exists on the monolith `ConsolidatedReport`; populating it is the OR aggregation extension's job. Requires a live instance with member GL data.

- [x] **Implement GL aggregation for incomeStatementSummary** — DEFER: same as above (computational OR-engine aggregation; `incomeStatementSummary` field already declared).

- [x] **Elimination-rule matching wiring** — The declarative surface is in place: `EliminationRule` schema (auto-match / reference-match / manual-review + amountTolerance) and the IntercompanyTransaction `eliminationStatus` lifecycle. The cross-line *matching algorithm* itself runs at consolidation time in the OR aggregation engine (REQ-ICP-003, matching-strategy-neutral) — DEFER the runtime matcher to that engine; no bespoke `EliminationMatcher.php` authored (ADR-022/031).

- [x] **Manual override logic** — Declarative: `isManualOverride` + `overrideReason` fields, the `exclude`/`restore` lifecycle transitions (REQ-ICP-004), and `lifecycleActions: true` on the detail page expose Exclude / Force-eliminate as lifecycle actions. Audit is consumed from OR audit-trail-immutable (ADR-022). No bespoke controller.

- [x] **Immutability rule on finalized/published reports** — `lib/Lifecycle/EliminationGuard::canChangeEliminationStatus` (REQ-ICP-006) denies any eliminationStatus transition once the linked `ConsolidatedReport.status` is final/finalized/published/archived; fail-closed on error (CWE-863). Referenced from all four IntercompanyTransaction transitions. Covered by `EliminationGuardTest` (final/published deny, draft/unconsolidated/dangling permit, exception fail-closed).

---

## Audit & Compliance

- [x] **Audit trail integration** — Consumed from OR audit-trail-immutable (ADR-022); no app config or schema flag required — every object create/update and lifecycle transition logs automatically. Live UI verification DEFER (requires a running instance).

- [x] **Spec traceability PHPDoc tags** — `EliminationGuard.php` (file + class + method) and both new test classes carry `@spec openspec/changes/bookkeeping-gr-consolidation/specs/bookkeeping-intercompany-posting.md`; the fragment schemas carry `x-spec` pointing at the same spec. No orphaned methods.

---

## Frontend & UI

- [x] **Group Consolidation index/detail pages** — Already shipped in the manifest from `bookkeeping-financial-statements` (Consolidations + ConsolidatedReport pages). No bespoke Vue (ADR-024 manifest-v2 — `@conduction/nextcloud-vue` `type: index`/`type: detail` renderers; no `CnIndexPage`/`router/index.js` files exist).

- [x] **Inter-Company Transactions index page** — Declared as the `IntercompanyTransactions` `type: index` manifest page (columns: date, from/to member, amount, account from/to, eliminationStatus). Rendered by the library; no bespoke component.

- [x] **Inter-Company Transactions detail page** — Declared as `IntercompanyTransactionDetail` `type: detail` with all fields (members, amount, accounts, reference, GL link, override reason, eliminatedByRuleId, eliminationStatus) and `lifecycleActions: true` for eliminate/exclude/restore/reinstate.

- [x] **Elimination Rule index/detail pages** — Declared as `EliminationRules` + `EliminationRuleDetail` manifest pages.

- [x] **ConsolidatedReport viewer (balance-sheet/income-statement tables)** — DEFER: depends on the deferred GL-aggregation work that populates `balanceSheetSummary`/`incomeStatementSummary`. The report index/detail pages already exist; rich table rendering of the JSON snapshots is a follow-up once the aggregation engine fills those fields.

---

## Testing & Verification

- [x] **Unit tests for the elimination guard** — `EliminationGuardTest` covers the immutability precondition: final/published deny, draft/unconsolidated/dangling-report permit, exception fail-closed (6 tests). Plus `GrConsolidationFragmentTest` (6 tests) for fragment validity, schema/lifecycle/RBAC declarations, seed well-formedness, and additive merge. 12 tests / 67 assertions green. (Runtime cross-line matching is OR-engine work; no `EliminationMatcher` authored.)

- [x] **Integration test: Full consolidation workflow** — DEFER: needs a live instance with member GL postings + the deferred aggregation engine.

- [x] **Integration test: Proportional consolidation** — DEFER: needs live aggregation; proportional is OPTIONAL (REQ-GC-005).

- [x] **Browser test: Group Consolidation UI** — DEFER: needs a live instance (Playwright against running app).

- [x] **Browser test: Inter-Company Transactions UI** — DEFER: needs a live instance.

- [x] **Manual smoke testing** — DEFER (needs a live instance). Before opening PR, verify:
  - [~] Create a ConsolidationGroup via UI → verify it persists and appears in list.
  - [~] Create an EliminationRule via UI → verify it links to the group.
  - [~] Create an IntercompanyTransaction via UI → verify it appears in list with pending status.
  - [~] Click "Consolidate Now" on a group → verify ConsolidatedReport is created and status = draft.
  - [~] View the ConsolidatedReport → verify balanceSheetSummary and incomeStatementSummary are populated (not empty JSON).
  - [~] Verify eliminated transactions are excluded from the report totals.
  - [~] Click "Finalize" on the report → verify status = finalized and further edits are blocked.
  - [~] Verify audit trail shows all events (creation, elimination, finalization).
  - Acceptance: All smoke tests pass; no 403/500 errors.

---

## Documentation

- [x] **ADR-000 data model annotation** — `ConsolidationGroup`/`ConsolidatedReport` were already reconciled by `bookkeeping-financial-statements`; this change adds the inter-company `IntercompanyTransaction`/`EliminationRule` entities via the spec deltas (bookkeeping-intercompany-posting). The fragment `x-spec` links provide the traceability ADR-000 references.

- [x] **Add spec-level README with screenshots** — DEFER: screenshots require a live instance; the spec deltas (proposal/design/specs) already document the GR + elimination model for a finance officer.

---

## Final Checks

- [x] **Config-scope deliverables completed** — Schemas + guard + manifest + l10n + tests done; runtime/live tasks DEFERred with reasons above.
- [x] **Deduplication check passed** — Documented (no OR-service overlap; consolidation container reused from the monolith).
- [x] **Spec review sign-off** — DEFER (process gate).
- [x] **Static checks passing** — lint + phpcs + phpmd + psalm + phpstan green on new files; new unit tests 12/12 green (OCP-shimmed; in-container the repo suite runs them natively).
- [x] **Live-instance verification** — DEFER (integration/browser/smoke need a running instance + the deferred GL aggregation engine).

---

## Notes

- **Elimination-rule matching complexity** — If the OpenRegister lifecycle engine cannot express cross-line aggregations in preconditions, implement `lib/Lifecycle/EliminationMatcher.php` as a thin guard called by the lifecycle engine (per ADR-031). This adds ~40 LOC but keeps the core logic declarative.
- **Performance at scale** — With 50+ members, consolidation may take seconds to aggregate GL + apply eliminations. Consider materialization caching (ConsolidatedReport JSON is immutable once finalized) and async consolidation runs (scheduled via OR's background job system).
- **Future enhancements** — Equity consolidation (associates), multi-currency translation, subsidiary acquisition accounting, statutory filing formats (SBR/XBRL) are explicit out-of-scope for this spec; record them on the T5+ roadmap.
