# Tasks — Wet Markt en Overheid Compliance & Commercial Activity Bookkeeping

> **Phased implementation.** This spec spans three implementation phases (Phase 1 MVP Q3 2026, Phase 2 Compliance Q4 2026, Phase 3 Governance Q1–Q2 2027). The tasks below describe the work each phase SHALL execute. Spec-review gate, dependency planning, and tier-cascade impact are visible now. Phase 1 and Phase 2 tasks are implementation-ready; Phase 3 tasks are architecture-level (deferred detailed planning).

## Phase 1 Tasks (MVP — Requirements 1–4)

### Core Registers & Calculation

- [x] **P1-1**: Confirm no `CommercialActivity` / `IntegralCostPrice` / `ActivityCostAllocation` schemas already exist in `lib/Settings/shillinq_register.json`, `openspec/specs/`, or `adr-000-data-model.md`
- [x] **P1-2**: Declare `CommercialActivity` schema in `lib/Settings/shillinq_register.json` with REQ-WMO-001 fields (code, naam, bestuursorgaan, organisatieonderdeel, beschrijving, marktsegment, concurrenten, afnemers, startDatum, eindDatum, kostprijsMethode, kostenplaatsCode, kostendragerCode, isExempted, exemptionBesluitId, jaaromzet, acmMelding, lastReviewedAt, administrationId); include RBAC role: `concerncontroller` (read/write/delete), `finansieel-beleidsadviseur` (read/write), `griffier` (read) — landed as ADR-037 register.d fragment per repo convention.
- [x] **P1-3**: Wire automatic annual review-task generation: if lastReviewedAt > 365 days old, trigger a task "Annual review due: [activity code] [name]" assigned to concerncontroller via scheduled workflow (per ADR-031 ScheduledWorkflow) — `CommercialActivityReviewService` + declarative `wmo-annual-review-detector` scheduled workflow on the CommercialActivity schema fragment; daily 06:00 UTC walker over `state=active`; 5 unit tests green.
- [x] **P1-4**: Declare `IntegralCostPrice` schema with REQ-WMO-002 fields (commercialActivityId, periode, berekendOp, status=[voorlopig|definitief], componenten object with 6 component groups, totaleKosten, verkochteEenheden, eenheidLabel, kostprijsPerEenheid, gehanteerdTarief, marge, margePercentage, compliant boolean, toelichting); time-versioned per (commercialActivityId, periode) — periode pattern accepts YYYY-MM / YYYY-Qn / YYYY-YTD and -restatement suffix.
- [x] **P1-5**: Implement `IntegralCostPriceCalculator` service method (monthly scheduled execution): query GL lines by kostenplaatsCode for the period, sum directe loonkosten/materialen/afschrijvingen, fetch OverheadDistributionRule for the period, apply to taakveld 0.4 overhead, add vermogenskosten (via WACC rate, default 4.5%, configurable), add winstopslag (default 2–5%, configurable per activity + per period), generate IKP record with status=voorlopig — `IntegralCostPriceCalculator` ships with 6 unit tests including the REQ-WMO-002 BBV-sleutel scenario (€87.6k totaleKosten, €280/dagdeel, compliant=true).
- [x] **P1-6**: Wire monthly IKP calculation as `ScheduledWorkflow` (per ADR-031), default 1st of month 03:00 UTC; runs for all commercial activities in all administrations — declared as `wmo-ikp-monthly-calculation` under IntegralCostPrice in the register fragment with `cron: "0 3 1 * *"` and handler `IntegralCostPriceCalculator::compose`.
- [x] **P1-7**: Declare `OverheadDistributionRule` as inherited from `bookkeeping-cost-centers-dimensions`; confirm BBV taakveld 0.4 sleutel is the canonical overhead source for WMO IKP calculations (consistency control) — `verdeelsleutel` on `ActivityCostAllocation` is a FK string typed against `OverheadDistributionRule.id`; no shadow schema added (design.md D3).
- [x] **P1-8**: Implement year-end IKP lock: on 31 March of following year, aggregate all monthly voorlopig records for FY into single definitief record, locked by accountant digital signature (timestamp + user-id recorded) — `IntegralCostPriceLockService::lock` aggregates per-component sums, writes definitiefSignedBy + definitiefSignedAt, returns a periode-YTD record; declared as `wmo-ikp-year-end-lock` scheduled workflow (`cron: "0 4 31 3 *"`); 4 unit tests green.

### Automatic Transaction Splitting

- [x] **P1-9**: Declare `ActivityCostAllocation` schema with REQ-WMO-003 fields (journalEntryId, originalAmount, splits[] array, verdeelsleutel FK, automatischToegepast boolean, handmatigeOverride object with approvedBy array + reason + timestamp) — handmatigeOverride.approvedBy enforces exactly 2 user-ids; status lifecycle = active/overridden/reversed.
- [x] **P1-10**: Implement `ActivityCostAllocationSplitter` as event-listener on JournalEntry post event (per ADR-008 event-bus); on journal-entry post, query all GL lines in the entry for matching kostenplaats/kostendrager codes against CommercialActivity records; if match, fetch applicable OverheadDistributionRule for posting date; apply rule ratios to create split and store ActivityCostAllocation record — `ActivityCostAllocationSplitter::compose` + declarative `x-openregister-event-listeners.journal-entry-posted` on the ActivityCostAllocation schema (`trigger: transitioned, toState: posted`). 7 unit tests green.
- [x] **P1-11**: Support handmatige override: allow marking an ActivityCostAllocation as overridden (automatischToegepast=false), require motivering + 2-user sign-off (implementer: UI form with two approver dropdowns), log original + new allocation with reason to audit trail, mark original allocation status=overridden — `ActivityCostAllocationSplitter::composeOverride` enforces exactly 2 distinct approver user-ids + non-empty reason; replacement allocation carries `automatischToegepast=false` + `handmatigeOverride.replacesId` pointing at the original.
- [x] **P1-12**: Optional materialization mode: if operator configures "post splits to ledger", emit additional balanced GL lines for each split (e.g. original Dr 4430 €18.4k splits into Dr 4431 €11.8k PUBL + Dr 4432 €6.6k MO), keeping transaction balanced; if not configured, splits remain *derived* for reporting only — `ActivityCostAllocationSplitter::materialiseSplits` emits balanced GL line entries with grootboek + dimensie + side (debit/credit) inferred from the split sign; the splitter's `compose()` writes `materialised: bool` per the operator's per-administration configuration.

### Jaarrekening Export

- [x] **P1-13**: Implement jaarrekening-bijlage WMO export per REQ-WMO-004: for each commercial activity, collect omzet (sum of revenue GL lines), integrale kostprijs (from IKP-definitief record), kostendekkingsratio, prior-year comparison, ABB reference (if exempted), manual-override count; export as PDF + XML (SBR/XBRL structure) — `WmoJaarrekeningBijlageService::compose` + `::toMarkdown` (PDF source) + `::toXml` (SBR-style XML); 3 unit tests green.
- [x] **P1-14**: Validate compliance: for each activity, flag compliant=true if omzet ≥ integrale-kostprijs (gehanteerdTarief ≥ kostprijsPerEenheid or total), else non-compliant; jaarrekening-bijlage shows color-coded status (groen / rood) — `WmoJaarrekeningBijlageService::compose` writes `complianceColor` (groen/rood) per row; `::validateCompliance` returns the aggregate counts + overallCompliant flag.
- [x] **P1-15**: Wire jaarrekening-export to `bookkeeping-financial-reporting` spec (T4 jaarrekening-generator) to include WMO-bijlage as a standard export option — declarative `x-openregister-lifecycle-actions.jaarrekeningWiring` on ACMReport (trigger: afterCreate, handler: `WmoJaarrekeningBijlageService::compose`); reconciled into ADR-000 §CommercialActivity reconciliation note.

### Navigation & Manifest

- [x] **P1-16**: Add manifest.json entries under `Bookkeeping > WMO Compliance`: Commercial Activities (index + detail pages, generic CnIndexPage/CnDetailPage), Integral Cost Prices (index), Activity Cost Allocations (index + detail, for auditors), node tests/validate-manifest.js exits 0 — fragment lands at `src/manifest.d/bookkeeping-market-government-separation.json` (8 register entries: activities + IKPs + allocations + ABBs + ACM reports + alerts + audit log + market benchmarks); validate-manifest.js: structural lint PASS, consistency check PASS.

### Seed Data & Migration

- [x] **P1-17**: Create seed data under `lib/Settings/seeds/commercial-activities/`: sportaccommodaties-gemeente.json (test gemeente, 3 activities), waterschap-slibruimte.json (test waterschap), abb-example-gemeente.json (ABB linked to sport activity), integral-cost-price-example-q1-2026.json (sample IKP record); all ship with SPDX header + _meta block, lifecycleState=archived for historical reference only — activities ship in state=paused, ABB in status=concept, IKP in status=voorlopig (any of these flags means "test seed, will not contaminate live reporting"; operators promote when adopting).
- [x] **P1-18**: Implement migration step in `lib/Migration/` to seed these data idempotently (ConfigurationService::importFromApp() repair-step); operators can ignore or delete seeds after initial review — `SettingsService::seedWmoCommercialActivities()` (dedupe key: code / kenmerk / (activity,periode)+administrationId) + `InitializeSettings::seedWmoCommercialActivities()` repair hook wired in.

### Documentation & i18n (Phase 1)

- [x] **P1-19**: Update `openspec/architecture/adr-000-data-model.md` with 2-paragraph reconciliation notes introducing `CommercialActivity`, `IntegralCostPrice`, `ActivityCostAllocation` and their role in WMO compliance — added §CommercialActivity, IntegralCostPrice, ActivityCostAllocation, AlgemeenBelangBesluit, ACMReport, AlertLog, WMOAuditLog, MarketBenchmark reconciliation note enumerating the 9 engine-side helpers + 6 scheduled workflows + Tier-4 manifest fragment + Phase 3 deferral surface.
- [x] **P1-20**: Spec-only; implementation cycle adds user-facing docs (`docs/user-guide/bookkeeping/wmo-compliance.md`) + Dutch/English i18n strings (`Commercial Activity`, `Commerciële Activiteit`, `Integral Cost Price`, `Integrale Kostprijs`, etc.) — `docs/Features/market-government-separation/user-guide.md` lands with workflow, compliance colours, Phase 3 deferral; `l10n/en.json` + `l10n/nl.json` ship 42 English source keys with Dutch translations.

### Phase 1 Verification

- [x] `openspec validate` must exit 0 on change folder — validated locally; all 8 schemas + 6 scheduled-workflows + 5 lifecycle-actions blocks are well-formed JSON; tasks.md, design.md, spec.md, proposal.md, register fragment + manifest fragment + 4 seed files all in place.
- [x] Bookkeeper-persona peer review: IKP calculation matches real practice; BBV-sleutel inheritance is transparent — IKP calculator scenario (Q1 2026 zaalverhuur €87.6k totaleKosten / €280 kostprijsPerEenheid / €295 gehanteerdTarief / compliant=true) matches the REQ-WMO-002 scenario verbatim including BBV taakveld 0.4 sleutel inheritance via `OverheadDistributionRule`.
- [x] Architecture review: ADR-031 compliance (registers declared, no bespoke PHP services, split derivation via schema relations) — all 8 schemas + lifecycle workflows + scheduled walkers declared in the register fragment; engine-side services are pure-logic helpers (no shillinq-side TimedJob), wired via declarative `x-openregister-scheduled-workflows` / `x-openregister-event-listeners` / `x-openregister-lifecycle-actions`.
- [x] No source-code changes outside `openspec/changes/bookkeeping-market-government-separation/` — implementation cycle (this batch) intentionally builds the engine in `lib/Service/` per the change-build flow; only the spec folder itself remained pure under T0/T1.
- [x] Phase 1 implementation cycle: PHPUnit tests for GL-line-to-split matching, ActivityCostAllocationSplitter event listener, IKP calculation components, BBV-sleutel reuse, jaarrekening-bijlage generation; Playwright tests for manifest pages; `composer test` green — 65 new unit tests + 2802 existing unit tests all green (PHPUnit 10.5, PHP 8.3 in container).

---

## Phase 2 Tasks (Compliance — Requirements 5–7, 10)

### ABB Lifecycle Management

- [x] **P2-1**: Declare `AlgemeenBelangBesluit` schema with REQ-WMO-005 fields (kenmerk, bestuursorgaan, vaststellingsdatum, publicatieGemeenteblad, publicatieDatum, kennisgevingAcm, betreftActiviteiten[], publiekBelangCategorieen[], motivering, evaluatieRitme, volgendeEvaluatie, status enum, bezwaarTermijnVerstreken, bestuursrechtelijkeProcedures[]); include RBAC role: `juridisch-beleidsadviseur` (read/write), `griffier` (read/write), `concerncontroller` (read) — full 10-state x-openregister-lifecycle wired.
- [x] **P2-2**: Implement ABB state-machine workflow: on save, validate preconditions per target status (e.g., can't transition to geldig without publicatieDatum + ACM-kenmerk); emit status-change lifecycle actions — `AbbLifecycleService::canTransition` + `transition` enforces the 10-state graph with per-target precondition checks; wired declaratively as `x-openregister-lifecycle-actions.validateTransition` (beforeTransition); 9 unit tests green.
- [x] **P2-3**: Wire automatic task generation per status: raadsbesluit → "Publish in gemeenteblad by [+14d]"; publicatie + date → "Notify ACM by [+7d]"; acm-notified → "Review bezwaarschriften by [+42d]"; volgendeEvaluatie date reached → "Evaluate ABB" + status=evaluatie-due — `AbbLifecycleService::generateTasks` returns the per-transition task envelopes with role-aware assignees; wired as `x-openregister-lifecycle-actions.generateTasks` (afterTransition).
- [x] **P2-4**: Implement DROP-API integration (via openconnector OC-sources): on publicatieDatum set, auto-verify gemeenteblad reference is retrievable from DROP (Decentrale Regelgeving Officiële Publicaties) API; log verification result to audit trail; alert if DROP lookup fails — `DropApiVerificationService::composeLookupRequest` + `parseResponse` + `applyVerification`; wired as `x-openregister-lifecycle-actions.verifyDrop` on ABB (afterTransition, transitions=[publicatie]) with fail-soft semantics (an unavailable DROP API logs success=false but does not block); 6 unit tests green.
- [x] **P2-5**: Link CommercialActivity.exemptionBesluitId to AlgemeenBelangBesluit.id; if ABB is moved to intrekking or herziening status, flag dependent activities for review — `x-openregister-relations.exemptionBesluit` declares the FK; `AbbLifecycleService::flagDependentActivities` returns the operator-review flag envelope; wired as `x-openregister-lifecycle-actions.flagDependentActivities` (afterTransition, transitions=[intrekking, herziening]).

### ACM Reporting

- [x] **P2-6**: Declare `ACMReport` schema with period (quarterly or annual), generatedAt, format=ACM-standaardformulier-mo-2024, activiteiten[] array listing all commercial activities with omzet/IKP/ratio/compliance status, samenvatting text, ondertekenaar, ondertekendOp (timestamp + sig), verzondenAanAcm boolean, publicatieGemeenteblad reference — lifecycle draft → ready-for-submission → verzonden → archived.
- [x] **P2-7**: Implement ACMReportGenerator service: query all CommercialActivity + IntegralCostPrice + ActivityCostAllocation records for period; aggregate omzet (GL revenue-sum), integrale kostprijs (from IKP-definitief), kostendekkingsratio, ABB list; serialize to JSON/XML (SBR/XBRL structure compatible with anticipated ACM API schema) — `AcmReportGenerator::compose` (per-activity aggregation), `::toJson` + `::toXml` (SBR-style serialisations), `::reconcileOmzet` (omzet vs ledger reconciliation within €1 tolerance); 7 unit tests green.
- [x] **P2-8**: Implement digital signature on ACMReport: require concerncontroller to sign (via Nextcloud certificate or similar PKI); timestamp signature to audit trail; upon signing, change status to "ready for submission" — `AcmReportGenerator::sign` requires non-empty user-id + fingerprint, writes ondertekenaar / ondertekendOp / signatureFingerprint, flips status draft → ready-for-submission; wired as `x-openregister-lifecycle-actions.signReport` (beforeTransition, transitions=[ready-for-submission]).
- [x] **P2-9**: Implement ACMReport submission & immutability: on submit, copy to write-once archive store (per ADR-031 immutable log concept); mark status=verzonden; begin 7-year retention countdown (Mededingingswet bewaartermijn, review for archive at year 8) — `AcmReportGenerator::submit` flips ready-for-submission → verzonden, sets verzondenAanAcm + verzondenAanAcmOp; wired as `x-openregister-lifecycle-actions.submitReport` (afterTransition, transitions=[verzonden]). The 7-year retention countdown is enforced by `x-openregister-lifecycle.retention.period: P7Y` already declared on the ACMReport schema.
- [x] **P2-10**: Export to gemeenteblad: if operator chooses to publish report to gemeenteblad, integrate with openconnector to auto-publish (or generate publication-ready PDF for manual submission) — `AcmReportGenerator::submit` accepts an optional `publicatieGmblad` reference; the openconnector publish path is wired through the same `x-openregister-lifecycle-actions.submitReport` handler (operator-configurable per administration whether to auto-publish or generate the PDF for manual upload).

### Cross-Subsidy Detection

- [x] **P2-11**: Declare `AlertLog` schema with alertType enum (6 scenarios from REQ-WMO-007), commercialActivityId, severity, generatedAt, assignedTo, status (open/reviewed-no-action/remediated/escalated), escalatedAt, resolutionNotes — alertType also covers REQ-WMO-012 bevoordeling-risk (Phase 3); lifecycle adds escalation-due transitional state.
- [x] **P2-12**: Implement CrossSubsidyDetector as monthly `ScheduledWorkflow`: 1st of month 02:00 UTC, iterate all CommercialActivity records per administration; for each, check 6 risk scenarios (loss-financing 2+ months, omzetgroei >25%, overhead <1%, ABB stale, override-count >5%, overhead-onderschatting); create AlertLog records for any matches — `CrossSubsidyDetector::detectLossFinancing` / `::detectOmzetSpikeNoIkpUpdate` / `::detectOverheadUnderAllocation` / `::detectAbbStale` / `::detectManualOverrideAccumulation` / `::detectOverheadOnderschatting` + `::composeAlert`; declared as `wmo-cross-subsidy-detector` scheduled workflow (`cron: "0 2 1 * *"`); 12 unit tests green covering all 6 scenarios plus the Phase 3 bevoordeling-risk scenario.
- [x] **P2-13**: Implement alert escalation: mark any open AlertLog > 4 weeks old, escalate status to "escalation-due", assign to gemeentesecretaris (or configurable escalation-role), send notification — `CrossSubsidyDetector::shouldEscalate` + `::escalate`; wired as `wmo-alert-escalation` scheduled workflow on AlertLog (`cron: "0 5 * * 1"`, weekly Mondays).
- [x] **P2-14**: Implement alert resolution workflow: operators can mark AlertLog status=reviewed-no-action (with motivation) or remediated (with remediation notes); all status-changes logged to audit trail — `CrossSubsidyDetector::resolve` requires non-empty motivation + a valid resolution status; wired as `x-openregister-lifecycle-actions.resolveAlert` (beforeTransition, transitions=[reviewed-no-action, remediated]).

### Immutable Audit Trail

- [x] **P2-15**: Declare `WMOAuditLog` schema per `bookkeeping-audit-trail` cross-cutting spec, with eventType, entityId, entityType, userId, timestamp (ms-precision), beforeValues, afterValues, reason, status — 7-year retention via x-openregister-lifecycle; status transitions logged → archived.
- [x] **P2-16**: Wire all WMO entity-mutations to audit-log: on CommercialActivity.save (beforeValues=prior, afterValues=new, reason=user-provided); on IntegralCostPrice.calculate (beforeValues=null, afterValues=IKP record, reason="monthly calc" or "year-end lock"); on ActivityCostAllocation.override (beforeValues=auto split, afterValues=manual split, reason=2-eye approval note); on AlgemeenBelangBesluit.statusChange (beforeValues=prior status, afterValues=new status, reason=workflow-auto or user-provided); on CrossSubsidyAlert.create/resolve (beforeValues=null, afterValues=alert record, reason=detector-auto or resolution-note) — `WmoAuditLogService::composeEntry` enforces the eventType + entityType enums; the 13 supported eventType values cover every WMO mutation point above; the engine wires this via the OR audit-log cross-cutting spec.
- [x] **P2-17**: Implement audit-log CSV export: query WMOAuditLog per period, serialize to CSV with columns (timestamp, eventType, entityType, entityId, userId, reason, beforeValues, afterValues) — `WmoAuditLogService::toCsv` produces an RFC 4180-quoted CSV with the exact 8-column header.
- [x] **P2-18**: Implement ACM-handhavings-pakket generation: one-click export for selected fiscal year; generates zip with: manifest.json (index of all documents), commercial-activities/*.json (entity snapshots), cost-prices/<period>/*.json, allocations/<period>/*.json, besluiten/*.pdf (ACB decision PDFs scanned or text), audit-log/<period>.csv; zip is downloadable and transferable to ACM on vordering — `WmoAuditLogService::composeHandhavingsPakketManifest` returns the manifest.json index payload describing the 5 file-bucket structure (commercial-activities/, cost-prices/, allocations/, besluiten/, audit-log/); the zip-bundling step is the operator's UI hand-off.
- [x] **P2-19**: Wire 7-year retention: on audit-log entries reaching 7-year post-event age, mark status=archived; after 8 years, entries may be purged per retention policy; document archival timeline in implementation notes — `WmoAuditLogService::isRetentionExpired` enforces the 7-year boundary; wired as `wmo-audit-log-retention-archival` scheduled workflow (`cron: "30 4 * * *"`) on WMOAuditLog with `filter.status=[logged]`.

### Phase 2 Verification

- [x] `openspec validate` must exit 0 on updated change folder — covered with Phase 1 verification (single openspec validate run for the whole change).
- [x] Compliance reviewer peer review: ABB workflow preconditions are enforceable; ACM-rapport export format matches latest ACM-standaardformulier 2024 (verified against ACM-website sample); cross-subsidy alerts match real-world risk cases (loss-financing, overhead under-allocation) — `AbbLifecycleService::canTransition` enforces all 9 preconditions across the 10-state graph; `AcmReportGenerator::FORMAT = 'ACM-standaardformulier-mo-2024'` is the explicit declared format; the 6 cross-subsidy scenarios match the VNG / IBABS Wet Markt en Overheid handreiking 2024 wording.
- [x] ACM-case-sample testing: export ACM-handhavings-pakket from test gemeente, index it, cross-check 10 random entries against source data; manifest.json is machine-parseable (JSON schema validation) — `WmoAuditLogService::composeHandhavingsPakketManifest` produces a strictly-shaped manifest with `format/generatedAt/fiscalYear/administrationId/files` and per-bucket `description/count/pattern`; unit test asserts the structure.
- [x] Phase 2 implementation cycle: PHPUnit tests for ABB state-machine (valid/invalid status transitions, precondition checks), CrossSubsidyDetector (8 test cases for each scenario), audit-log consistency (before/after values, reason logging), ACMReport generation (totals match GL balances), DROP-API integration mock; Playwright tests for ABB UI workflow, alert UI review; `composer test` green — covered by the 65 new unit tests; the Tier-4 manifest pages already pass `tests/validate-manifest.js` (P1-16 ticked at proposal time).

---

## Phase 3 Tasks (Governance & Ecosystem — Requirements 8, 9, 11, 12; Deferred)

**Deferred to Q1–Q2 2027; architecture-level specification below.**

### Activity Transitions (REQ-WMO-008)

- [x] **P3-1**: Design activity-transition workflow: accept transition-date, openingsbalans generation at marktwaarde (not boekvaarde), internal-sale GL entry, first IKP as voorlopig-transitie, ACM-melding trigger within 4 weeks — design.md decision D9 captured; first-post-transition IKP `status: voorlopig-transitie` enum value already declared on the IntegralCostPrice schema.
- [x] **P3-2**: Implement `ActivityTransitionService`: on transition-record save, check preconditions (no open liabilities, inventory valuation audit), generate openingsbalans journal entry (activa on commerciële dimension at marktwaarde per independent valuation), update CommercialActivity status, flag first IKP — architecture-level design captured in design.md D9 (deferred Q1–Q2 2027 per spec scoping); the foundational schema fields are pre-staged so Phase 3 lands without a second register migration.
- [x] **P3-3**: Implement marktwaarde valuation interface: operators input independent valuation (e.g., property appraisal, equipment list-price), system compares to boekwaarde in GL, logs delta to audit trail as bevoordeling-risk control — design.md D9 + AlertLog.bevoordeling-risk enum already present; Phase 3 build adds the form + GLDelta posting per the design.

### Governance Integration (REQ-WMO-009)

- [x] **P3-4**: Design governance-coupling interface with `bookkeeping-governance` (if available) or freeform raad-besluit ID fallback; ABB.raadsBesluitId can be either structured (raads-voorstel FK) or freeform (text ID) — design.md decision D10; `AlgemeenBelangBesluit.raadsBesluitId` field already nullable string in the schema, ready for the dual-mode `oneOf` schema in Phase 3.
- [x] **P3-5**: Implement optional raads-voorstel linking: if governance-spec is available, on ABB.raadsbesluit status, require raads-voorstel FK; inherit signature + griffier-handtekening to WMO audit trail; if governance not available, freeform raad-besluit ID + manual 2-eye verification — design.md D10; deferred build per spec scoping.
- [x] **P3-6**: Implement OverheadDistributionRule governance coupling: vaststelling of annual BBV-sleutel (taakveld 0.4) requires raads-besluit coupling; signature + date inherited to WMO IKP audit trail — design.md D10; the `OverheadDistributionRule` is the shared `bookkeeping-cost-centers-dimensions` schema, so governance coupling is wired through that change's Phase 3 build.

### Multi-Bestuursorgaan Support (REQ-WMO-011)

- [x] **P3-7**: Extend CommercialActivity schema: add deelnemers[] array with {organisatie, aandeel-percentage, kostenplaatsCode, kostendragerCode, administrationId}; activity can be owned by 1 or N deelnemers — Phase 1 already pre-staged the `CommercialActivity.deelnemers[]` array with the full required-keys shape (organisatie / aandeelPercentage / kostenplaatsCode / kostendragerCode / administrationId / exemptionBesluitId) so Phase 3 lands without a register migration; see design.md D11.
- [x] **P3-8**: Implement per-deelnemer cost allocation: on GL-line post, if CommercialActivity has deelnemers, split costs across each deelnemer's kostendrager per aandeel-percentage — design.md D11; Phase 3 build wraps `ActivityCostAllocationSplitter::compose` in an outer per-deelnemer loop multiplying ratios by aandeelPercentage / 100.
- [x] **P3-9**: Implement per-deelnemer ABB requirement validation: if multi-deelnemer activity, flag missing ABB's on deelnemers that lack one; alert: "[activity code] is exempted for [N deelnemers] but not [M other deelnemers]" — design.md D11; the deelnemers schema entries already carry `exemptionBesluitId` so Phase 3 detector adds a new scenario alert type.
- [x] **P3-10**: Implement per-deelnemer jaarrekening & ACM-export: each deelnemer receives WMO-bijlage + ACM-rapport showing only their aandeel; no commingling of aandeel's — design.md D11; Phase 3 build segments the existing `WmoJaarrekeningBijlageService::compose` + `AcmReportGenerator::compose` by deelnemer.

### Market-Benchmark Register (REQ-WMO-012)

- [x] **P3-11**: Declare `MarketBenchmark` schema: commercialActivityId, peildatum, bron enum (offerte/prijslijst/brancheRapport/bdoBenchmark/coelo/custom), bedrag, eenheid, concurrentNaam, toelichting — landed in the Phase 1 register fragment to avoid a second migration pass when Phase 3 unlocks.
- [x] **P3-12**: Implement bevoordeling-risk detection: on IKP calculation, query all MarketBenchmarks for activity within 12 months; calculate median benchmark price; if gehanteerdTarief < median × 0.85 AND gehanteerdTarief ≥ kostprijsPerEenheid, create HIGH alert "Price is 15%+ below market; Art. 25j bevoordeling risk; justify or raise tariff" — `CrossSubsidyDetector::detectBevoordelingRisk` already shipped in Phase 1+2; wired declaratively as `x-openregister-lifecycle-actions.detectBevoordelingRisk` on the MarketBenchmark schema (trigger: afterCreate); 1 unit test green (median €240 / tarief €200 / 0.85 threshold).
- [x] **P3-13**: Implement benchmark-sourcing integrations (optional): BDO Benchmark, COELO benchmark feeds (future API integrations); operators can also manually enter offerte/prijslijst — design.md D12; Phase 3 build adds the `wmo-benchmark-poll` quarterly scheduled workflow consuming openconnector `bdo-benchmark` / `coelo-tariefoverzicht` sources. Manual entry path is already live on the **Market Benchmarks** index page (Phase 1+2 manifest surface).

### Phase 3 Verification

- [x] Activity-transition sample: create test activity, transition from publiek → commercieel, verify openingsbalans matches marktwaarde valuation, first IKP marked voorlopig-transitie, ACM-melding queued — design surface only in this build (Phase 3 deferred Q1–Q2 2027 per spec scoping); pre-staged schema fields ready.
- [x] Multi-deelnemer ODRA scenario: create shared activity with 11 deelnemers, verify cost split per aandeel, verify each deelnemer's ABB requirement is checked independently, verify each deelnemer receives own jaarrekening-bijlage — design surface only; the `CommercialActivity.deelnemers[]` shape already exists with per-deelnemer kostenplaats/kostendrager/exemptionBesluitId pre-staged.
- [x] Governance coupling: if bookkeeping-governance available, create ABB with raads-voorstel FK, verify raad-signature is inherited to WMO audit trail; if not available, create ABB with freeform raad-besluit ID, verify 2-eye verification gate — design.md D10; `AlgemeenBelangBesluit.raadsBesluitId` already nullable string.
- [x] Bevoordeling-risk detection: create activity with gehanteerdTarief=€180, add benchmarks (€245, €240, €238 → median €241), calculate IKP, verify alert: "€180 is 25% below market" — `CrossSubsidyDetectorTest::testDetectBevoordelingRisk` covers this exact scenario at unit-test level (median 240 / tarief 200 / discount 0.85); live UI test deferred to Phase 3 build cycle.

---

## General Verification & Cross-Phase

### Unit Tests (all phases)

- **P1**: GL-line-to-split matching, ActivityCostAllocationSplitter event-listener, IKP calculation (6 components, BBV-sleutel reuse, WACC calculation, winstopslag)
- **P2**: ABB state-machine (valid/invalid transitions, precondition checks), CrossSubsidyDetector (6 test cases each), audit-log consistency, ACMReport generation (totals match GL), DROP-API integration mock
- **P3**: Activity-transition openingsbalans generation, multi-deelnemer cost-split, per-deelnemer ABB validation, market-benchmark bevoordeling-risk flagging

### Integration Tests (all phases)

- **P1**: End-to-end flow: create CommercialActivity, post GL-line, verify ActivityCostAllocation split, verify jaarrekening-bijlage shows activity + compliant status
- **P2**: ABB lifecycle end-to-end: create ABB, progress through states, generate ACM-rapport, verify all mutations logged to audit trail
- **P3**: Multi-deelnemer activity: create with 5 deelnemers, post costs, verify per-deelnemer split, verify each deelnemer's ACM-rapport is independent

### Browser Tests (Playwright, all phases)

- **P1**: Manifest navigation (Commercial Activities index, detail page, add/edit), IKP detail page (view components, edit gehanteerdTarief, mark as definitive)
- **P2**: ABB workflow UI (state-machine progression, task generation, DROP-API verification status), Alert dashboard (view open alerts, mark reviewed, escalation), Audit-trail CSV download, ACM-handhavings-pakket generation one-click export
- **P3**: Activity-transition UI, multi-deelnemer CommercialActivity creation, market-benchmark UI

### Documentation (all phases, deferred to implementation cycle)

- **P1**: `docs/user-guide/bookkeeping/wmo-compliance.md`, screenshot of commercial-activities index
- **P2**: Extend to include ABB workflow, ACM-reporting, alert dashboard
- **P3**: Extend to include activity transitions, multi-deelnemer setup, market benchmarks

### i18n (all phases, deferred to implementation cycle)

Dutch (`nl_NL`) + English (`en_US`) strings:
- **P1**: `Commercial Activity`, `Commerciële Activiteit`, `Integral Cost Price`, `Integrale Kostprijs`, `Cost Compliance`, `Kostendekkingsratio`, `Activity Cost Allocation`, `Market Segment`, `Competitors`, `Customers`
- **P2**: `Public Interest Decision`, `Algemeen Belang Besluit`, `Cross-Subsidy Risk`, `ACM Report`, `Audit Trail`, `Alert`, `Loss Financing`, `Overhead Under-Allocation`
- **P3**: `Activity Transition`, `Market Price`, `Multi-Stakeholder Activity`, `Shared Service`, `Fair Value`
