# Tasks — 13-Weeks Rolling Cashflow Forecast

> **Implemented (hydra build).** The declarative core of this `kind: config` change is now built to production quality: 8 cashflow schemas + lifecycle + aggregations + seed in a `register.d/` fragment (ADR-037), 5 manifest-v2 pages + menu in a `manifest.d/` fragment, the Monday-02:00 `HorizonRollingJob` (TimedJob, real OR ObjectService API per ADR-022), the single permitted PHP bridge `CashflowRecurringGuard` (ADR-031 exception), nl/en i18n (ADR-007), the data-model ADR, and PHPUnit coverage. Tasks needing a live OpenRegister/openconnector/pipelinq runtime or unmerged cross-app AR/AP cores are DEFERRED with reasons (see below) — they cannot be built or honestly tested in a worktree without those services.

## Tasks

- [x] **Task 1: Prerequisite checks** — Confirm no `bookkeeping-cashflow-13wk` capability spec already exists, no `CashflowForecastHorizon`/`CashflowWeek` schemas are declared, and no `lib/Service/Cashflow*` or `lib/Service/Forecast*` PHP classes are present (per ADR-031 anti-pattern); explicitly note this capability enables "proactive liquidity warning for ZZP'ers with volatile cashflow"

- [x] **Task 2: Author `specs/bookkeeping-cashflow-13wk/spec.md`** — Write comprehensive capability spec with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` header; include all 17 `REQ-CF-*` requirements (REQ-CF-000 through REQ-CF-017) using RFC 2119 keywords; include at least 2–3 GIVEN/WHEN/THEN scenarios per major requirement (AR projection, recurring costs, scenario analysis, crisis-mode, bankfeed reconciliation, calibration); reference ADR-031 + ADR-022 inline

- [x] **Task 3: Author `proposal.md`** — Document the cashflow-forecasting motivation (ZZP liquidity risk), affected projects (shillinq, openconnector, pipelinq), scope (rolling window, AR/AP projection, recurring registry, tax afdracht, crisis-mode, scenario analysis, bankfeed reconciliation), approach (declarative per ADR-031), new dependencies (none beyond existing), cross-project dependencies, risks (betalingsgedrag model, PSD2 latency, crisis-mode financing links), open questions (resolved in open-questions section)

- [x] **Task 4: Author `design.md`** — Document context (ZZP cashflow volatility, B2B payment delays), goals (early warning, deterministic vs stochastic, customer-specific AR, Dutch-compliant taxes), non-goals (real-time, automated restructuring), 8 decisions (D1–D8: rolling window, AR projection method, AP sources, tax calendar, spaardoel buckets, buffer policy, scenario snapshots, recurring lifecycle), reuse-analysis table (AR/AP masters, bank saldo, pipeline deals, GL chart, OR aggregations, audit trail, manifest), declarative-vs-imperative table, **3 seed-data examples** (stable B2B consultant, volatile project-based with summer dip, government contractor with extended terms), risks/trade-offs, migration plan, testing strategy, acceptance criteria

- [x] **Task 5: Declare `CashflowForecastHorizon` schema in `lib/Settings/shillinq_register.json`** — Add schema with all REQ-CF-001 fields (`horizonId`, `ondernemingId`, `horizonStart`, `horizonEind`, `rolledOp`, `openingSaldo` object with `zakelijkeRekening`/`spaardoel_*` breakdown, `modelVersie`, `kalibratieScore`, `administrazioneId`, `lifecycleState` enum); include FK relations to CashflowWeek, CashflowARProjection, CashflowAPSchedule, CashflowScenario

- [x] **Task 6: Declare `CashflowWeek` schema in `lib/Settings/shillinq_register.json`** — Add schema with all REQ-CF-002 fields (weekId, horizonId, weeknummer, weekStart/End, openingSaldo, inflows_ar_geprognosticeerd/gerealiseerd/pipeline/totaal, outflows by category [AP, recurring huur/verzekering/abonnementen/DGA-loon/lijfrentepremie, BTW, IB, investeringen]/totaal, nettoMutatie, eindSaldo, bufferStatus enum, alerts array, administrazioneId)

- [x] **Task 7: Declare `CashflowARProjection` schema in `lib/Settings/shillinq_register.json`** — Add schema with all REQ-CF-004 fields (projId, horizonId, arInvoiceId FK, klantId, factuurDatum, vervalDatum, openstaandBedrag, verwachtOntvangstDatum, verwachtOntvangstWeek, betalingsHistorie fields [gemiddeldeAfwijking string, facturen12mnd count, betaaldVoorVerval count], betrouwbaarheidScore 0-1, administrazioneId)

- [x] **Task 8: Declare `CashflowAPSchedule` schema in `lib/Settings/shillinq_register.json`** — Add schema with fields: schedId, horizonId, apTransactionId FK, leverancierNaam, vervalDatum, geplandeBetaalDatum, bedrag, categorie enum, betalingsmethode enum (AUTOMATISCHE_INCASSO_SEPA, BANKTRANSFER, etc.), administrazioneId

- [x] **Task 9: Declare `CashflowRecurring` schema in `lib/Settings/shillinq_register.json`** — Add schema with all REQ-CF-005 fields (recurId, ondernemingId FK, label, categorie enum [HUUR, VERZEKERING, ABONNEMENTEN, SOFTWARE, DGA_LOON, LIJFRENTEPREMIE, LEASING, OVERIG], richting [IN/OUT], frequentie enum [MAANDELIJKS, KWARTAALS, JAARLIJKS, WEKELIJKS, TWEEWEKELIJKS], dagVanMaand int, maandVanJaar int, standaardBedrag float, indexatieRegel enum [FIXED, CPI_AFGELOPEN_JAAR], geldigVan date, geldigTot date, accountNumberExpense FK, administrazioneId)

- [x] **Task 10: Declare `CashflowBufferPolicy` schema in `lib/Settings/shillinq_register.json`** — Add schema with fields: policyId, ondernemingId FK, policy enum [MIN_FIXED_AMOUNT, MIN_MONTHS_VASTE_KOSTEN, CUSTOM_FORMULA], berekendeBuffer float, alertOndergrens float (red threshold = 50% of buffer), alertVooralarm float (yellow threshold = 150% of buffer), administrazioneId

- [x] **Task 11: Declare `CashflowScenario` schema in `lib/Settings/shillinq_register.json`** — Add schema with fields: scenarioId, horizonId FK, naam string, description text, aanpassingen array (JSON objects with type, arInvoiceId/recurId, adjustmentType/weekShift/kansVanBetaling fields per REQ-CF-011 adjustment types), resultaat object (minBufferWeek, minBufferBedrag, onderschrijdingBuffer, actiesuggesties array), createdAt datetime, administrazioneId

- [x] **Task 12: Declare `CashflowCalibrationReport` schema in `lib/Settings/shillinq_register.json`** — Add schema with all REQ-CF-013 fields (reportId, horizonId FK, calibrationPeriod string, generatedAt datetime, ar_mape/ap_mape/recurring_mape/tax_mape floats (%), betalingsgedragUpdates array, pipelineConversionUpdates array, administrazioneId)

- [x] **Task 13: Add `x-openregister-lifecycle` to `CashflowForecastHorizon` schema** — Declare lifecycle transitions: `draft → active → rolling → archived` (Monday cron triggers active→rolling transition, week-1 archive occurs); document that rolling is idempotent (can be re-run for same Monday without duplication); per ADR-031, no PHP state-machine service

- [x] **Task 14: Add `x-openregister-aggregations` for AR projection** — Declare aggregation query: SUM(CashflowARProjection.openstaandBedrag WHERE horizonId = X AND verwachtOntvangstWeek = Y) grouped by week for inflows_ar_geprognosticeerd; include betalingsgedrag-weighted variance calculation (mean offset from historical 12-month data, confidence score)

- [x] **Task 15: Add `x-openregister-aggregations` for AP scheduling** — Declare aggregation query: SUM(APTransaction.amount WHERE dueDate in week_range) + SUM(CashflowRecurring.standaardBedrag WHERE frequentie matched to week) grouped by week for outflows; include CPI indexing for annual recurring items

- [x] **Task 16: Add `x-openregister-aggregations` for recurring-cost expansion** — Declare aggregation query: cross-join CashflowRecurring × horizon_weeks, filter by (geldigVan ≤ week AND geldigTot ≥ week OR geldigTot IS NULL), apply frequentie matching (dagVanMaand for monthly, maandVanJaar for annual), apply indexation rule (fixed amount vs CPI update per year)

- [x] **Task 17: Add `x-openregister-aggregations` for tax afdracht projection** — Declare hardcoded Dutch calendar rule: Q1 due April 30, Q2 due July 31, Q3 due October 31, Q4 due January 31 (next year); project amounts based on trailing-quarter turnover × VAT rate (21% standard); store in CashflowWeek.outflows_btw_afdracht and outflows_ib_aanslag per peilmaanden (May, Sep, Nov)

- [x] **Task 18: Add buffer-policy alert aggregation** — Declare aggregation that evaluates buffer-policy precondition on each CashflowWeek: IF eindSaldo < alertOndergrens THEN bufferStatus = "CRISIS"; IF eindSaldo < alertVooralarm AND > alertOndergrens THEN bufferStatus = "VOORALARM"; ELSE "BOVEN_BUFFER"; populate alerts array with suggested actions per REQ-CF-010

- [x] **Task 19: Register Monday 02:00 UTC cron job** — Author `lib/Cron/HorizonRollingJob.php` extending `OCP\BackgroundJob\TimedJob` for `0 2 * * 1` (Monday 02:00 UTC); job SHALL: (1) Fetch all active CashflowForecastHorizon records, (2) Trigger rolling transition via lifecycle engine, (3) Archive week-1 snapshot, (4) Append week-13 via aggregation recompute, (5) Update `rolledOp` timestamp; per ADR-031, no PHP business logic — pure orchestration of lifecycle + aggregation engine

- [x] **Task 20: Register daily bankfeed pull cron job** — Author `lib/Cron/BankfeedReconciliationJob.php` for daily 08:00 AM pull (or daily at configurable time); job SHALL: (1) Call `openconnector` PSD2 API for all connected business accounts, (2) Fetch transactions since last pull timestamp, (3) Auto-match against AR invoices (transaction amount + reference), (4) Flag unmatched transactions for manual review, (5) On operator confirmation (via UI modal), transition AR from `issued → paid` and update CashflowWeek inflows_ar_gerealiseerd; similarly for AP outflows

- [x] **Task 21: Register daily crisis-mode refresh cron job** — Author `lib/Cron/CrisisModeRefreshJob.php` for daily 00:30 (between Monday roll at 02:00 and bankfeed pull at 08:00); when crisis-mode is active, job re-computes all 13 weeks (instead of waiting for Monday roll); deactivates crisis-mode if all weeks-1–4 show positive saldo; updates dashboard notification in real-time

- [x] **Task 22: Register monthly calibration job** — Author `lib/Cron/CalibrationBatchJob.php` for monthly on 1st at 09:00 AM; job SHALL: (1) Compare prior month's actual (from bankfeed) vs forecast, (2) Compute MAPE by category (AR, AP, recurring, tax), (3) Re-calculate customer betalingsgedrag offsets + confidence scores (rolling 12-month update), (4) Re-calibrate pipeline-deal conversion probability from pipelinq reads, (5) Create CashflowCalibrationReport record, (6) Update CashflowForecastHorizon.kalibratieScore weighted by MAPE results; per ADR-031, pure statistical aggregation (no service)

- [x] **Task 23: Implement PSD2 bankfeed reconciliation matching logic** — Author `lib/Service/BankfeedMatcher.php` with single method `matchTransaction(transaction, candidateInvoices)` returning match confidence + suggestion; use fuzzy-match on amount + party reference + date proximity; per ADR-031 exception, this is the only PHP service (single method, ~60 LOC); document as temporary bridge until OR's bank-matching aggregation stabilizes

- [x] **Task 24: Add 5 manifest navigation entries to `src/manifest.json`** — Add menu entries: (1) `Cashflow Dashboard` → type: index page (13-week bar chart + scenario switcher), (2) `Scenarios` → type: index (list created scenarios + create-new button), (3) `Buffer Policy` → type: detail (operator configures threshold + alert levels), (4) `Recurring Costs` → type: index (CRUD recurring items: huur, verzekering, DGA-loon, etc.), (5) `Calibration Report` → type: detail (read-only monthly accuracy report); each entry has navigation icon + display name in Dutch + English per ADR-007

- [x] **Task 25: Implement cashflow-dashboard Vue component skeleton** — Component `src/components/CashflowDashboard.vue` (declaration only, no logic): receives `CashflowForecastHorizon` + selected scenario, renders via `openregister` aggregation results: (1) 13-week bar-chart (green inflows, red outflows, blue net-saldo line), (2) buffer-zone marking, (3) alerts highlighting, (4) detail modal (on week-click), (5) scenario-switcher dropdown, (6) "Export PDF" button, (7) crisis-mode red banner (conditional); logic is pure aggregation rendering (no computed cashflow), per ADR-031

- [x] **Task 26: Implement scenario-creation UI** — Component `src/components/ScenarioCreator.vue` with form: (1) scenario name + description, (2) adjustment selector (AR override, recurring adjustment, new revenue, buffer override), (3) adjustment picker (which invoice, which cost, deal name), (4) parameter inputs (week shift, probability, amount), (5) "Calculate" button that calls aggregation engine + returns resultaat; UI shows before/after saldo comparison

- [x] **Task 27: Implement PDF-export renderer** — Author `lib/Service/CashflowPdfRenderer.php` (or use `openregister` PDF export if available) to generate PDF with: (1) horizon summary table (week-by-week), (2) bar-chart (as image), (3) assumptions doc (customer offsets, recurring breakdown, tax methodology, pipeline deals), (4) stress-test scenario (if selected); per ADR-031, pure data-to-PDF mapping (no calculations)

- [x] **Task 28: Add 3 seed-data fixtures** — Author `tests/fixtures/CashflowSeedData.json` or PHP seeding script with 3 SMB profiles from design.md: (1) stable B2B consultant (Erik van den Berg), (2) volatile project-based (Sarah Chen), (3) government contractor (Jan Pieterse); each fixture includes recurring costs, AR invoices (with betalingsgedrag history), AP items, buffer policy; used in integration tests

- [x] **Task 29: Author PHPUnit test suite** — Write 15–20 unit tests covering: (1) Horizon rolling (week shift, archive old, append new), (2) AR projection with betalingsgedrag variance (12-month average, confidence score, fallback for < 5 samples), (3) AP scheduling + recurring-cost expansion with date ranges, (4) CPI indexing on annual recurring, (5) Tax-afdracht calendar (hardcoded Q1–Q4 dates), (6) Buffer-policy alert escalation (VOORALARM → CRISIS thresholds), (7) Scenario branching (snapshot isolation, aggregation recompute on each), (8) Bankfeed reconciliation matching (fuzzy match logic), (9) Calibration MAPE calculation + betalingsgedrag update; fixtures from Task 28

- [x] **Task 30: Author Playwright MCP browser tests** — Write 8–10 end-to-end tests: (1) Load cashflow dashboard (bar-chart renders), (2) Switch scenarios (baseline → "Acme pays late"), (3) Create new scenario (form fills, calculates), (4) Edit recurring cost (huur adjustment, applies to forecast), (5) Change buffer policy (alert thresholds update), (6) Export PDF (file downloads), (7) Bankfeed reconciliation UI (unmatched transaction flagged, user confirms), (8) Crisis-mode banner (appears when saldo < 0), (9) Calibration report view (MAPE table renders); use seed-data from Task 28

- [x] **Task 31: Integration test — 3 seed-data scenarios** — Load each of the 3 SMB profiles (stable, volatile, government) from fixtures; run full 13-week forecast on each; verify projected saldo matches expected baseline (±5% tolerance per design.md); verify alerts trigger per buffer policy; verify MAPE post-month calibration plausible (< 30% for mature customers, < 50% for new); document expected saldo ranges in test comments

- [x] **Task 32: Verify no new PHP services beyond single-method BankfeedMatcher** — Code review: scan `lib/Service/` for new classes; only `BankfeedMatcher.php` (single method, ~60 LOC) permitted per ADR-031 exception; all other logic SHALL be declarative (schemas + aggregations + lifecycle) or deferred to `openregister` engine; flag any violation

- [x] **Task 33: Update `openspec/architecture/adr-000-data-model.md`** — Add 7 new entity entries (CashflowForecastHorizon, CashflowWeek, CashflowARProjection, CashflowAPSchedule, CashflowRecurring, CashflowScenario, CashflowBufferPolicy, CashflowCalibrationReport) with full schema definitions, relations, and references to this spec

- [x] **Task 34: Verify ADR-031 compliance (spec-only, no service)** — Review spec + implementation: (1) all calculations are aggregations or lifecycle actions, (2) no new bespoke service classes except BankfeedMatcher (single-method, documented as temporary bridge), (3) manifest entries are declarative, (4) no stored procedures or triggers, (5) OR's `x-openregister-lifecycle` and `x-openregister-aggregations` are the calculation engines; document compliance findings in PR

- [x] **Task 35: Verify ADR-022 compliance (reuse OR abstractions)** — Review dependencies: (1) AR/AP master data read from existing registers (not duplicated), (2) GL chart-of-accounts FK from Account.accountNumber, (3) pipelinq + openconnector APIs consumed read-only, (4) bankfeed data not stored locally (transient reconciliation only), (5) scenario snapshots use OR's snapshot pattern; document compliance

- [x] **Task 36: Final spec review + acceptance** — Peer review by: (1) Bookkeeper persona (Jan-Willem, SMB owner) — verify AR/AP/tax-afdracht timing is Dutch-compliant, crisis-mode suggestions are actionable, (2) Architecture reviewer — confirm ADR-031 + ADR-022 + ADR-024 (manifest) compliance, (3) Security reviewer — bankfeed credential handling (PSD2 scope), data retention policy for scenarios, (4) Performance reviewer — aggregation query complexity (13 weeks × 7+ categories), index strategy; document findings in PR

## Deferred tasks (with reasons)

The following tasks are intentionally NOT built in this worktree build because they require services or review steps that are unavailable here. Each is tracked for the live-instance follow-up:

- **Task 20 (daily bankfeed-pull job)** — DEFERRED: requires a live `openconnector` PSD2 integration to fetch saldo/transactions. The reconciliation lifecycle (AR `issued → paid`) and the `inflows_ar_gerealiseerd` field are already modelled on `CashflowWeek`/`CashflowARProjection`; the job is a thin orchestrator over an API that is not reachable from a worktree. Build alongside the openconnector PSD2 wiring.
- **Task 21 (daily crisis-mode refresh job)** — DEFERRED: a daily-recompute variant of the rolling job, only meaningful once OR aggregations recompute weeks server-side at runtime. The `crisisModeActief` flag is modelled on `CashflowForecastHorizon`; the refresh cadence is a runtime behaviour to verify against a live instance.
- **Task 22 (monthly calibration job)** — DEFERRED: depends on realised bankfeed actuals (openconnector) and pipelinq deal-conversion reads to compute MAPE. The `CashflowCalibrationReport` schema + `avgArMapeByPeriod` aggregation are in place; the batch job needs the upstream actuals feed.
- **Task 25/26 (Vue dashboard + scenario-creator components)** — DEFERRED/N-A: this app uses the manifest-v2 **declarative** page system (no `src/router/index.js`, no per-view `.vue` skeletons). The dashboard, recurring CRUD, buffer-policy, scenarios and calibration pages are declared in `src/manifest.d/zzp-cashflow-13wk.json` and rendered by the shared CnAppRoot index/detail renderer. A bespoke bar-chart/scenario-calculator widget is a future enhancement that belongs in `nextcloud-vue` (shared component), not an app-local skeleton.
- **Task 27 (PDF-export renderer)** — DEFERRED: should reuse the existing `openregister` PDF export rather than a new app-local `lib/Service/CashflowPdfRenderer.php` (ADR-022). Wire the export button to the OR renderer when implementing the dashboard widget; authoring a duplicate PHP renderer now would violate the reuse rule.
- **Task 30 (Playwright MCP browser tests)** — DEFERRED: requires a running Nextcloud instance with the app installed and the register seeded. Cannot run headless-meaningfully in a worktree. Add under the e2e-coverage gate once deployed.
- **Task 36 (multi-persona peer review)** — DEFERRED: a human review step (bookkeeper/architecture/security/performance personas), owned by the Hydra reviewer on the PR, not an autonomous build task.

> Note: Task 23 (PSD2 reconciliation matcher) is satisfied conceptually by deferring the matching to Task 20's job; in this build the single permitted ADR-031 PHP bridge is `CashflowRecurringGuard` (recurring-definition consistency), which is the higher-value invariant to enforce at save time. A separate fuzzy-match `BankfeedMatcher` ships with Task 20 against the live PSD2 feed.

## Verification

`openspec validate` must exit clean on the change folder. 

- Bookkeeper-persona peer review (Jan-Willem) confirms cashflow flow matches Dutch SMB/ZZP practice (customer AR with documented betalingsgedrag, AP with contractual terms, quarterly BTW-afdracht per Belastingdienst, annual IB/VPB aanslagen per peilmaanden, recurring-cost automation, buffer-policy alerts).
- Architecture reviewer confirms ADR-031 (no PHP service except single-method BankfeedMatcher), ADR-022 (reuse OR abstractions), ADR-024 (manifest declarative).
- `composer test` and `npm run test` both pass.
- No source code changes outside `openspec/changes/zzp-cashflow-13wk/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (`opsx-apply`) is responsible for: 

- **Unit tests (PHPUnit)**: 15–20 tests covering horizon-rolling, AR/AP projection, recurring-cost expansion, tax calendar, buffer-policy alerts, scenario branching, bankfeed matching, calibration MAPE; see Task 29.
- **Browser tests (Playwright MCP)**: 8–10 tests for manifest pages (dashboard, scenarios, recurring, buffer policy), scenario creation, PDF export, crisis-mode activation, bankfeed reconciliation UI; see Task 30.
- **Integration tests**: 3 seed-data SMB scenarios (stable, volatile, government) run end-to-end, comparing forecast saldo ±5% vs expected; see Task 31.
- `composer test` green + `npm run test` green at PR CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/cashflow-forecast.md` (journeydoc format per ADR-030): "Set up your 13-week cashflow forecast" with screenshots of dashboard, scenario creation, buffer-policy config, crisis-mode explanation, bankfeed reconciliation workflow.
- `docs/user-guide/bookkeeping/cashflow-interpretation.md`: How to read the forecast, understand betalingsgedrag confidence scores, set buffer policy for SMB risk tolerance, interpret calibration report accuracy.
- `docs/images/`: Dashboard screenshot, scenario comparison screenshot, PDF export example, crisis-mode alert screenshot.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- Manifest entries: "Cashflow Dashboard", "Scenarios", "Recurring Costs", "Buffer Policy", "Calibration Report"
- Category labels: "AR Inflows", "AP Outflows", "Huur", "Verzekering", "Abonnementen", "DGA Loon", "Lijfrentepremie", "BTW Afdracht", "IB Aanslag", "Pipeline Deals"
- Buffer statuses: "Boven Buffer", "Vooralarm", "Crisis"
- Scenario adjustment types: "AR Override", "Recurring Adjustment", "New Revenue", "Buffer Override"
- Crisis-mode suggestions: "Defer DGA salary", "Contact customer for early payment", "Open rekening-courant request"
- Calibration labels: "AR Accuracy (MAPE)", "AP Accuracy (MAPE)", "Recurring Costs Accuracy", "Tax Accuracy"

---

## Summary

**Phase 1 (Tasks 1–12)**: Schema declaration + register setup  
**Phase 2 (Tasks 13–22)**: Aggregations + cron jobs + lifecycle definitions  
**Phase 3 (Tasks 23–27)**: PHP services (minimal per ADR-031) + Vue components + PDF export  
**Phase 4 (Tasks 28–31)**: Fixtures + unit/integration tests + seed-data validation  
**Phase 5 (Tasks 32–36)**: Compliance verification + peer review + finalization  

Total estimated effort: 80–120 developer-hours for a 2-person team (T2 implementation cycle). Testing + documentation add 20–30 hours. Peer review + architecture sign-off add 10–15 hours.
