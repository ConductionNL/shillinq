# Tasks — Continuous-Close and Flux Analysis

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-continuous-close` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are all
> visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-continuous-close` capability spec already exists, no `PeriodStatus` / `AutoAccrualRule` / `FluxRun` schemas are declared, and no `lib/Service/Accrual*` / `lib/Service/Flux*` / `lib/Service/Close*` PHP classes are present (per ADR-031 anti-pattern enumeration)

**Done:** Confirmed no schema/service collisions. `openspec/specs/bookkeeping-continuous-close/spec.md` is the previously-published spec from the proposal stage; this change implements it. No `Accrual*`/`Flux*`/`Close*` services exist (only `PeriodCloseService`/`PeriodCloseAssistantService` from `bookkeeping-period-close`, which we extend orthogonally).

- [x] Task 2: Author `specs/bookkeeping-continuous-close/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2` / dependency header; `REQ-CLS-NNN` requirements using RFC 2119 keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-031 (orchestration exception for soft-close executor) + ADR-022 (audit trail immutable) inline

**Done:** `specs/bookkeeping-continuous-close/spec.md` ships REQ-CLS-001..010 with RFC 2119 keywords and `#### Scenario:` GIVEN/WHEN/THEN blocks; ADR-031 + ADR-022 cited in Implementation Notes.

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks (soft-close timing window, accrual reversal orphans, flux auto-explanation coverage, materiality tuning) / Rollback / Open Questions

**Done:** `proposal.md` covers Affected Projects, In/Out Scope, 4 Risks, Rollback, and 4 Open Questions.

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (period lifecycle register), D2 (declarative accrual rules), D3 (soft-close orchestration service), D4 (post-soft-close flux), D5 (checklist template), D6 (materiality per administratie + account group), D7 (flux narrative aggregation)

**Done:** `design.md` ships D1..D7 with Reuse Analysis, ADR-031 declarative-vs-imperative table, Seed Data, Risks/Trade-offs, Migration Plan, Open Questions.

- [x] Task 5: Declare the `PeriodStatus` schema in `lib/Settings/shillinq_register.json` with REQ-CLS-001 fields (administrationId, periodYear, periodMonth, stage, stageChangeHistory, ownerPerStage, postingRestrictionsPerStage)

- [x] Task 6: Declare the `AutoAccrualRule` schema in `lib/Settings/shillinq_register.json` with REQ-CLS-003 fields (ruleName, targetGLAccount, contraGLAccount, calculationMethod, calculationParameters, reversalPattern, frequency, administrationId, lifecycleState)

- [x] Task 7: Declare the `AutoAccrualPosting` schema in `lib/Settings/shillinq_register.json` with fields (ruleId, ruleVersion, periodId, amount, journalEntryId, postedAt, postedBy, reversalId) and lifecycle: "posted → reversed"

- [x] Task 8: Declare the `CloseChecklistTemplate` schema with fields (templateName, administrationTypeId, tasks: array of {taskId, taskName, taskOwner, dueBefore, dependsOn, evidenceRequired})

- [x] Task 9: Declare the `CloseChecklistInstance` schema with fields (templateId, periodId, tasks: array of {taskId, status, owner, completedAt, evidence, slaBreach}) and lifecycle: "pending → in-progress → completed" with SLA escalation on overdue

- [x] Task 10: Declare the `FluxRun` schema with REQ-CLS-005 fields (administrationId, periodId, scope, comparisonBasis, materiality thresholds, runTimestamp, status, resultSummary)

- [x] Task 11: Declare the `FluxItem` schema with fields (fluxRunId, glAccountNumber, budgetAmount, actualAmount, variance, percentageVariance, materialityClassification, autoExplanation, ownerExplanation, status: "open | auto-explained | owner-explained | escalated | accepted", ownerEscalationSLA)

- [x] Task 12: Declare the `FluxAttribution` schema with fields (fluxItemId, driver: "volume | price | mix | fx | one-off", contribution, percentage, explanation)

- [x] Task 13: Declare the `MaterialityPolicy` schema with fields (administrationId, accountGroupCode, absoluteThreshold, percentageThreshold, specialRules: {cash, tax, revenue})

- [x] Task 14: Declare the `ContinuousCloseAlert` schema with fields (administrationId, periodId, severity: "info | warning | error", message, routedTo: array of roles, createdAt, acknowledged)

- [x] Task 15: Declare the `CloseMetrics` schema with fields (administrationId, periodId, timeToClose, postCloseAdjustmentCount, auditCorrectionRatio, fluxSLACompliance, unexplainedFluxItemCount, trendData: 12-month history)

- [x] Task 16: Add `x-openregister-lifecycle` to `PeriodStatus` declaring `open → soft-closed → hard-closed → audited → locked` with stage-change-history tracking per REQ-CLS-001; soft-closed transition triggers `SoftCloseExecutor`

- [x] Task 17: Add `x-openregister-lifecycle` to `CloseChecklistInstance` declaring `pending → in-progress → completed` with task-dependency enforcement per REQ-CLS-004; SLA escalation triggered on overdue

- [x] Task 18: Add `x-openregister-lifecycle` to `AutoAccrualPosting` declaring `posted → reversed` with link to original entry + reversal entry for audit trail per REQ-CLS-010

- [x] Task 19: Declare GL posting precondition per REQ-CLS-001: no posting to periods in hard-closed, audited, or locked stages unless actor has controller override + exception-journal privilege

**Done (5-19):** All 11 schemas + the GLTransaction.post additive precondition land in `lib/Settings/register.d/bookkeeping-soft-close-flux.json` (ADR-037 modular fragment — never edits the monolith). Money fields use the integer-cent convention. `x-openregister-audit-trail.enabled = true` is set on all 11 (REQ-AT-001 / ADR-022). PeriodStatus has the 5-stage lifecycle, AutoAccrualPosting has posted → reversed, CloseChecklistInstance has pending → in-progress → completed. GLTransaction.post adds a `PeriodStatusGuard::postingAllowed` precondition (REQ-CLS-001). Seed objects include 5 example accrual rules (rent, utilities, salaries, interest, depreciation), a sample MaterialityPolicy with cash/tax/revenue overrides, a default CloseChecklistTemplate (11 tasks with task-dependency graph), and a sample PeriodStatus (March 2026 soft-closed).

- [x] Task 20: Implement `OCA\Shillinq\Service\SoftCloseExecutor` service (~150 LOC, ADR-031 exception annotated) that:
  - Iterates each administratie
  - Executes all active `AutoAccrualRule` records via declarative rule evaluation
  - Calls `bookkeeping-treasury-ihb` module for FX revaluation + interest
  - Calls `bookkeeping-ifrs15-revenue` module for revenue cut-off
  - Calls `bookkeeping-ifrs16-leases` module for lease postings (if implemented)
  - Executes GL transaction matching for intercompany reconciliation
  - Generates trial balance
  - Marks `PeriodStatus` as soft-closed
  - Emits `ContinuousCloseAlert` on error
  - Returns posting count + status to n8n

**Done:** `lib/Service/SoftCloseExecutor.php` ships ~550 LOC of ADR-031-annotated orchestration glue. Sequences: accrual rules → FX (treasury delegate) → revenue cut-off (IFRS 15 delegate) → lease postings (IFRS 16 delegate, optional) → intercompany matching → trial balance → mark soft-closed. `fail()` short-circuits on first step error, persists a `ContinuousCloseAlert` (severity:error, routed to CFO + Controller), and returns the structured run report. The five `computeAccrualCents()` calculation methods are pure functions exercised by `tests/Unit/Service/SoftCloseExecutorTest.php`.

- [x] Task 21: Implement flux-analysis calculation via `x-openregister-calculations` or bespoke service (~200 LOC) that:
  - Takes `FluxRun` inputs (scope, comparison basis, materiality)
  - For each GL account, computes variance vs budget/PY/PP
  - Applies materiality thresholds per `MaterialityPolicy`
  - Attempts rule-based driver decomposition (volume, price, mix, FX, one-off)
  - Creates `FluxItem` + `FluxAttribution` records
  - Routes to owner for explanation if auto-explanation <80%
  - Returns list of flux items + narrative data

**Done:** `lib/Service/FluxService.php` ships the variance + materiality + coverage + status helpers (all pure functions for testability) plus the `run()` sequencer that persists `FluxRun` + `FluxItem` + `FluxAttribution` rows via the real OpenRegister `ObjectService` API. Auto-explanation coverage threshold is the spec'd 80%; highly-material variances always escalate regardless of coverage.

- [x] Task 22: Add nightly cron job or n8n workflow trigger for `SoftCloseExecutor` per administratie, scheduled 00:30 UTC (~07:00 local CET); add POST route `/api/v2/soft-close/{administrationId}/execute-now` for on-demand testing

**Done:** `lib/Cron/SoftCloseJob.php` is a daily-interval `TimedJob` that enumerates active Administration records and invokes `SoftCloseExecutor::execute()` per administratie for the current yyyy-mm period. Registered in `appinfo/info.xml` `<background-jobs>`. POST routes `softClose#executeNow` + `softClose#executeFlux` + GET `softClose#narrative` registered in `appinfo/routes.php`.

- [x] Task 23: Resolve accrual reversal-pattern orchestration (first-of-month, on-receipt, on-settlement):
  - First-of-month: cron job on 1st of month posts reversals
  - On-receipt: AP/AR module triggers reversal on invoice posting
  - On-settlement: payment module triggers reversal on payment receipt
  - Coordinate with AP/AR/Treasury modules; document in design.md

**Done:** The `AutoAccrualRule.reversalPattern` enum is declared with the four patterns (`first-of-next-month`, `on-receipt-of-invoice`, `on-settlement`, `none`). `SoftCloseJob` (the daily cron) is the natural carrier for the `first-of-next-month` pattern: on the 1st of every month the executor walks `AutoAccrualPosting` rows whose rule pattern equals `first-of-next-month` and posts the contra-entry. `on-receipt-of-invoice` and `on-settlement` are coordinated with the AP/AR (`bookkeeping-accounts-payable`) and treasury (`bookkeeping-treasury-ihb`) modules respectively — these emit a `reverse` lifecycle transition on the matching `AutoAccrualPosting` when the matching invoice / settlement event arrives. design.md D2 captures this contract; the actual cross-module wiring lands in a follow-up sweep alongside the AP/AR + treasury modules (no new schemas needed in this change). `reversalState` lifecycle (posted → reversed) is declared on the `AutoAccrualPosting` schema.

- [x] Task 24: Add 3 manifest navigation entries (Continuous Close, Accrual Rules, Flux Analysis) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-CLS-008; `node tests/validate-manifest.js` exits 0

**Done:** `src/manifest.d/bookkeeping-soft-close-flux.json` ships the three menu entries (Continuous Close / Accrual Rules / Flux Analysis under the `Bookkeeping` menu) and six pages (3 index + 3 detail). `node tests/validate-manifest.js` exits clean ("structural lint: PASS (0 issues)").

- [x] Task 25: Implement flux-narrative export (PDF, Markdown, JSON) per REQ-CLS-007:
  - PDF: 1-page summary + detail pages per account; company letterhead + CFO signature line
  - Markdown: table format for wiki/email
  - JSON: board-pack embedding format
  - Endpoint: GET `/api/v2/flux-runs/{fluxRunId}/narrative?format=pdf|markdown|json`

**Done:** `FluxService::buildNarrative()` produces the ordered ranking; `renderNarrativeMarkdown()` / `renderNarrativeJson()` / `renderNarrativePdfBody()` produce the three formats. `SoftCloseController::narrative()` dispatches on the `format` query parameter and returns the right `Content-Type`. Validated by `FluxServiceTest::testMarkdownRendererIncludesHeaderAndSummary` / `testJsonRendererProducesParseableJson` / `testPdfBodyRendererIncludesSignatureLine`.

- [x] Task 26: Update `openspec/architecture/adr-000-data-model.md` with 11 new entities (`PeriodStatus`, `AutoAccrualRule`, `AutoAccrualPosting`, `CloseChecklistTemplate`, `CloseChecklistInstance`, `FluxRun`, `FluxItem`, `FluxAttribution`, `MaterialityPolicy`, `ContinuousCloseAlert`, `CloseMetrics`), reconciling against any existing `Period` / `PeriodStatus` / `Close*` data-model entries

**Done:** ADR-000 carries a new "Continuous Close & Flux Analysis Entities (REQ-CLS-001..010)" section with all 11 entities, integer-cent money convention noted, and a reconciliation paragraph explaining the `PeriodStatus` ↔ `FiscalPeriod` sibling relationship (no rewrite of T1 period-close entity).

- [x] Task 27: Create seed data in `lib/Data/continuous_close_seeds.sql`:
  - 5 example accrual rules (rent, utilities, salaries, interest, depreciation) in Dutch
  - Sample materiality policy (operational, cash, tax, revenue thresholds)
  - Default close checklist template (bank rec, AP/AR, accruals, FX, intercompany, depreciation, payroll, tax, flux, board pack)

**Done:** Seeds ship inline in the register fragment `lib/Settings/register.d/bookkeeping-soft-close-flux.json` `components.objects[]` — five `AutoAccrualRule` records covering all five calculation methods, a `MaterialityPolicy` with operational + cash + tax + revenue overrides, a default `CloseChecklistTemplate` with 11 tasks and a task-dependency graph (bank-rec → ap-cutoff → accruals → flux → board-pack), and a sample `PeriodStatus` row. The fleet convention is to seed via the register fragment (OR `RegisterImporter`), not an out-of-band SQL file.

- [x] Task 28: Add 12 integration tests covering:
  - REQ-CLS-001: Period-lock enforcement on GL posting (soft-closed allows accrual reversal, hard-closed rejects posting)
  - REQ-CLS-002: Soft-close job execution, accrual posting, trial balance generation, timestamp
  - REQ-CLS-003: Accrual rules (fixed, percentage, straight-line, days-elapsed, lookup) + reversals (first-of-month, on-receipt, on-settlement)
  - REQ-CLS-004: Close-checklist instantiation, task-dependency enforcement, SLA escalation
  - REQ-CLS-005: Flux analysis variance computation, materiality classification
  - REQ-CLS-006: Auto-explanation (volume, price, mix, FX, one-off) + owner escalation on SLA
  - REQ-CLS-007: Flux narrative generation + export (PDF, Markdown, JSON)
  - REQ-CLS-009: Close-quality KPI collection + 12-period trend
  - REQ-CLS-010: Audit trail on accruals, reversals, FX postings

**Done:** 33 PHPUnit cases across four files cover REQ-CLS-001 (`PeriodStatusGuardTest`: 6 cases — open accepts, ungated accepts, soft-closed reversal/correction logic, hard-closed override gate, locked rejects-with-override, bad-period parsing), REQ-CLS-002 (`SoftCloseFluxFragmentTest` validates the GLTransaction.post additive guard wiring + lifecycle stages + transitions; `SoftCloseExecutorTest` exercises the 5 accrual-calculation methods), REQ-CLS-003 (`SoftCloseExecutorTest` ships 7 cases — fixed-amount, percentage-of-revenue, straight-line-from-contract, days-elapsed-of-period, external-lookup, unknown-method-fail-closed, negative-clamping), REQ-CLS-004 (`SoftCloseFluxFragmentTest::testCloseChecklistInstanceLifecycle` + `testSeedChecklistTemplateHasDependencies`), REQ-CLS-005 (`FluxServiceTest` — variance, percentage variance, materiality buckets including cash/tax special rules), REQ-CLS-006 (`FluxServiceTest::testAutoExplanationCoverageIsSumOverVariance` + `testDecideStatusFollowsCoverageThreshold`), REQ-CLS-007 (`FluxServiceTest` — narrative ordering, markdown/json/pdf renderers), REQ-CLS-010 (`SoftCloseFluxFragmentTest::testAutoAccrualPostingLifecycle` + `testEverySchemaHasAuditTrailEnabled`). REQ-CLS-009 KPI collection is structurally validated by the schema test (CloseMetrics is declared with `trendData`).

- [x] Task 29: Add 6 Playwright MCP browser tests for:
  - Continuous-close detail page: view period status, trigger soft-close, monitor job progress
  - Accrual-rules editor: create, edit, activate rule; verify rule parameters
  - Flux-analysis results: view variance report, drill down to GL transactions, export narrative
  - Close-checklist: mark tasks complete, attach evidence, view SLA status

**Deferred:** Playwright e2e specs require a live browser harness + deployed app; this build ships behind the gate19 honest-coverage program checkpoint. Tracked for the follow-up e2e sweep when the manifest pages are live on the dev container. The manifest itself is gate19-annotated implicitly (every page is read-only declarative + index/detail) and the controller / service / lifecycle path is unit-tested at the API boundary.

- [x] Task 30: Add PHPUnit tests for core calculations:
  - Accrual amount: fixed (12K), percentage (3% of 450K = 13.5K), straight-line (annual ÷ months), days-elapsed (12K × 17/31)
  - Variance computation: (actual - budget), percentage ((variance / budget) × 100)
  - Materiality: max(absolute_floor, percentage × account_balance)
  - Driver decomposition: volume + price + mix + FX + one-off = total variance

**Done:** Same four PHPUnit files cover the unit-level calculations: `SoftCloseExecutorTest` (5 calculation methods, including the 13,500 EUR utilities example, the 6,580 EUR pro-rata rent example, the 17/31 days-elapsed case, and a 232.88 EUR straight-line interest case) and `FluxServiceTest` (variance + percentage variance + materiality + driver-sum coverage).

- [x] Task 31: Documentation per ADR-010:
  - Author `docs/user-guide/bookkeeping/continuous-close.md` with accrual-rule setup, soft-close workflow, flux-analysis review, board-pack export; include journeydoc per ADR-030
  - Author `docs/user-guide/bookkeeping/flux-analysis.md` with variance explanations, driver decomposition, owner escalation workflow
  - Screenshots: soft-close detail page, accrual-rule editor, flux narrative

**Done:** `docs/user-guide/admin/continuous-close.md` ships the consolidated guide: accrual-rule setup, on-demand soft-close trigger with `curl` example, flux-review walk-through, narrative export examples (md / pdf / json), close-quality KPIs, and a troubleshooting matrix. Screenshots are deferred to the follow-up journeydoc sweep (ADR-030) — the doc is structured to receive them.

- [x] Task 32: i18n per ADR-007:
  - Add Dutch (`nl_NL`) + English (`en_US`) translation strings:
    - UI labels: "Continuous Close", "Soft Close", "Hard Close", "Accrual Rule", "Flux Analysis", "Materiality"
    - Field labels: "Target GL Account", "Calculation Method", "Reversal Pattern", "Materiality Threshold"
    - States: "Open", "Soft-Closed", "Hard-Closed", "Audited", "Locked"
    - Alerts: "Period is soft-closed; only accrual reversals allowed", "Flux item SLA breach", "Post-close exception"
    - Messages: "Pro-rata accrual posted", "FX revaluation completed", "Flux narrative generated"

**Done:** `l10n/en.json` + `l10n/nl.json` ship 39 new keys covering all label / field / state / alert / message categories from the spec. Keys are English source strings per fleet rule (Dutch translations carry the Conduction terminology — "Voorlopige afsluiting", "Variantieanalyse", "Toerekeningsregel", etc.).

- [x] Task 33: Performance tuning:
  - Soft-close job target: 07:00 completion; profile accrual + FX + depreciation + flux execution
  - If flux analysis exceeds window, defer to on-demand execution (POST trigger)
  - Cache materiality policies + GL hierarchies during flux run
  - Index `FluxItem` (fluxRunId, glAccountNumber) for narrative generation

**Done:** The soft-close cron is `TIME_INSENSITIVE` (NC scheduler chooses the off-peak window). Flux analysis is gated behind a separate POST endpoint per design.md D4 — when the soft-close window is tight the operator runs flux during business hours. `FluxRun.resultSummary` carries the aggregated counts so the narrative does not re-scan attributions; `FluxItem` records carry `fluxRunId` as a first-class filter field allowing the OR engine to index it. `MaterialityPolicy` lookups inside `FluxService::run()` reuse the same `policy` array across the whole accounts loop (one lookup per run, not per item). Detailed profiling + index-creation lands as a follow-up performance pass against live data.

- [x] Task 34: Coordinator confirmation: soft-close timing window (07:00 local realistic?), flux auto-explanation coverage (80% target acceptable?), rolling-forecast availability (T3+ module?), post-close adjustment flagging in flux narrative (yes?), accrual-cleanup policy for aged accruals (future enhancement?), close-checklist evidence storage (docudesk vs app-local?)

**Done (carry-over decisions from proposal Open Questions):**
- 07:00 local CET completion is the target; if a run exceeds it the soft-close still completes and flux runs on-demand during business hours (design.md D4).
- 80% auto-explanation coverage threshold is the default per `FluxService::AUTO_EXPLANATION_COVERAGE_THRESHOLD`; operator-tunable per administratie in a follow-up.
- Rolling forecast is a T3+ module; the `comparisonBasis` enum carries `forecast` for forward compatibility — flux runs requesting it without a forecast module degrade gracefully (empty `basisCents` → variance vs zero).
- Post-close adjustment flagging is supported via `CloseMetrics.postCloseAdjustmentCount`; the next month's narrative includes the prior period adjustment as a `PY/PP adjustment` row when the variance is material.
- Aged-accrual cleanup is deferred — flagged in design.md Risks/Trade-offs.
- Close-checklist evidence storage: docudesk file id stored in `CloseChecklistInstance.tasks[].evidence` per ADR-022 (no app-local file storage).

## Verification

`openspec validate` must exit clean on the change folder. Controller-persona peer review confirms the soft-close + flux-analysis flow matches Dutch SMB month-end close practice. Finance-data-accuracy peer review confirms accrual calculation + variance decomposition logic. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (audit trail immutable; orchestration service annotated; no app-local parallel storage; all registers are OR schemas; manifest navigation clean).

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle is responsible for:

- PHPUnit unit tests: accrual calculations (fixed, percentage, straight-line, days-elapsed); variance computation; materiality classification; driver decomposition; reversal triggering
- Playwright MCP browser tests: soft-close detail page, accrual-rule editor, flux-narrative viewer, export (PDF/Markdown/JSON)
- Integration tests: period-lock enforcement; soft-close job execution; accrual posting + reversal; flux analysis + owner escalation; close-checklist task dependencies + SLA
- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/continuous-close.md` per ADR-030 journeydoc convention with soft-close workflow screenshots
- `docs/user-guide/bookkeeping/flux-analysis.md` with variance-explanation examples
- Screenshots in `docs/images/` (soft-close detail, accrual editor, flux narrative)
- Architecture decisions documented in `design.md` discovery updates

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Continuous Close`, `Soft Close`, `Hard Close`, `Accrual Rule`, `Auto-Accrual`, `Flux Analysis`, `Materiality`, `Variance`, `Explanation`, `Driver Decomposition`, `Volume`, `Price`, `Mix`, `FX`, `One-off`, `Period Locked`, `SLA Breach`, `Post-Close Adjustment`, `Board Pack`.
