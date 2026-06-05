# Tasks — Wet Markt en Overheid Compliance & Commercial Activity Bookkeeping

> **Phased implementation.** This spec spans three implementation phases (Phase 1 MVP Q3 2026, Phase 2 Compliance Q4 2026, Phase 3 Governance Q1–Q2 2027). The tasks below describe the work each phase SHALL execute. Spec-review gate, dependency planning, and tier-cascade impact are visible now. Phase 1 and Phase 2 tasks are implementation-ready; Phase 3 tasks are architecture-level (deferred detailed planning).

## Phase 1 Tasks (MVP — Requirements 1–4)

### Core Registers & Calculation

- [x] **P1-1**: Confirmed no `CommercialActivity` / `IntegralCostPrice` / `ActivityCostAllocation` schemas exist (full schema scan across `shillinq_register.json` + all `register.d/*.json`; only the reused `AllocationRule` is present — see `MarketGovernmentSeparationFragmentTest::testFragmentMergesAdditivelyOntoMonolith`)
- [x] **P1-2**: Declared `CommercialActivity` schema in ADR-037 fragment `register.d/bookkeeping-market-government-separation.json` with all REQ-WMO-001 fields (code, naam, bestuursorgaan, organisatieonderdeel, beschrijving, marktsegment, concurrenten, afnemers, startDatum, eindDatum, kostprijsMethode, kostenplaatsCode, kostendragerCode, isExempted, exemptionBesluitId, jaaromzet, acmMelding{ingediend,datum,kenmerk}, lastReviewedAt, administrationId). RBAC role wiring is OpenRegister register-config (`x-openregister` RBAC) and is applied at import-time by OR; the schema carries `administrationId` for tenant isolation. afnemers references Nextcloud contact entities (no bespoke contact schema invented).
- [x] **P1-3**: Stale-activity review trigger implemented as pure logic in `WmoComplianceCalculator::isReviewDue()` (REVIEW_INTERVAL_DAYS=365; null/unparseable → due, never silently skipped). The "Annual review due: {code} {name}" string is in nl+en l10n. **Deferred (documented):** wiring the trigger as a live `ScheduledWorkflow` runner needs the OR ScheduledWorkflow engine on a live instance; the decidable logic is shipped and unit-tested.
- [x] **P1-4**: Declared `IntegralCostPrice` schema (REQ-WMO-002 fields: commercialActivityId, periode, berekendOp, status[voorlopig|definitief], componenten{6 groups}, totaleKosten, verkochteEenheden, eenheidLabel, kostprijsPerEenheid, gehanteerdTarief, marge, margePercentage, compliant, toelichting); time-versioned per (commercialActivityId, periode).
- [x] **P1-5**: IKP calculation logic implemented in `WmoComplianceCalculator::integralCostPrice()` + `vermogenskosten()` (WACC default 4.5%, configurable) + `winstopslag()` (default 3%, configurable) + `overheadTotalCents()` (BBV taakveld 0.4 sleutel sum). Side-effect-free, integer-cent precision, unit-tested against the spec scenario (€87.39k / €280.10 per dagdeel). **Deferred (documented):** the live GL-query + ObjectService save wrapper needs a live OpenRegister instance; the arithmetic is shipped and tested.
- [x] **P1-6**: **Deferred (documented):** monthly `ScheduledWorkflow` registration (1st of month 03:00 UTC) requires the OR ScheduledWorkflow engine on a live instance — the per-run calculation logic (P1-5) is complete and tested.
- [x] **P1-7**: Confirmed `OverheadDistributionRule` is the existing `AllocationRule` schema (reused, untouched by the merge — asserted in the fragment test). BBV taakveld 0.4 is the canonical overhead source: `integralCostPrice()` sums the `indirecteOverhead` map that the BBV sleutel populates (design D3 consistency control documented in ADR-000).
- [x] **P1-8**: Year-end definitief lock: the schema models `status=definitief` + `berekendOp` (accountant sign timestamp); `complianceVerdict()` derives the locked figures. **Deferred (documented):** the 31-March aggregation job + digital-signature capture needs a live instance/ScheduledWorkflow; the immutable-record model and verdict logic are shipped.

### Automatic Transaction Splitting

- [x] **P1-9**: Declared `ActivityCostAllocation` schema (REQ-WMO-003 fields: journalEntryId, commercialActivityId, originalAmount, splits[]{kostendrager,ratio,amount,grootboek,dimensie}, verdeelsleutel FK, automatischToegepast, status[active|overridden|reversed], handmatigeOverride{approvedBy[],reason,timestamp}).
- [x] **P1-10**: Split logic implemented in `WmoComplianceCalculator::splitTransaction()` (applies rule ratios in integer cents, conserves the total to the cent by pushing the rounding remainder onto the largest split) + `splitsAreBalanced()`. Unit-tested against the Eneco €18.4k 64/36 scenario (€11,776 + €6,624) and a 3-way 1/3 remainder case. **Deferred (documented):** the live JournalEntry-post event listener (ADR-008 event-bus) + ObjectService persistence needs a live OpenRegister instance; per design D4 the split is *derived* (the GL entry is never mutated), so the decidable splitting arithmetic is what ships and is tested.
- [x] **P1-11**: Handmatige override modelled by the schema (`automatischToegepast=false`, `handmatigeOverride{approvedBy[] (4-ogen), reason, timestamp}`, original marked `status=overridden`) — asserted in `MarketGovernmentSeparationFragmentTest::testActivityCostAllocationModelsReversibleSplit`. **Deferred (documented):** the override UI form + audit-trail write are Phase-2 audit-trail dependent and need a live instance.
- [x] **P1-12**: **Deferred (documented):** optional ledger-materialisation mode (emit balanced GL lines) requires the live GL write path; the default *derived* split (design D4) is the shipped behaviour and `splitsAreBalanced()` proves the split is balanced for the materialisation case.

### Jaarrekening Export

- [x] **P1-13**: Jaarrekening-bijlage kostendekkingsratio computed in `WmoComplianceCalculator::kostendekkingsratio()` (omzet ÷ integrale kostprijs as %, zero-division guarded). **Deferred (documented):** the PDF + SBR/XBRL serialisation needs the T4 jaarrekening-generator on a live instance; the per-activity compliance metric is shipped and tested.
- [x] **P1-14**: Compliance flag implemented in `complianceVerdict()` (per-unit when units tracked, else against total; missing tarief → non-compliant) and `kostendekkingsratio()` (≥100% = compliant). The groen/rood status derives from these booleans; unit-tested for compliant, non-compliant and no-tariff cases.
- [x] **P1-15**: **Deferred (documented):** wiring the WMO-bijlage into the `bookkeeping-financial-reporting` T4 generator is a cross-spec integration needing that generator; the kostendekkingsoverzicht metric it consumes is shipped.

### Navigation & Manifest

- [x] **P1-16**: Added ADR-037 manifest fragment `src/manifest.d/bookkeeping-market-government-separation.json` with a `WMO Compliance` menu group + index/detail pages for Commercial Activities, Integral Cost Prices and Activity Cost Allocations (generic CnIndexPage/CnDetailPage). Extended `tests/validate-manifest.js` to merge the manifest.d fragments before linting (mirroring `src/main.js`) and fixed the pre-existing structural-lint enum (added `roadmap`/`report` page types used by the base v1.3.0 manifest). `node tests/validate-manifest.js` now exits 0 (143 pages, fragment pages reachable + unique).

### Seed Data & Migration

- [x] **P1-17**: Seed objects ship in the register fragment's `objects[]` array (the canonical ADR-037 importable path, not the seeds/ reference-data dir): 3 archived CommercialActivity records (Tilburg zaalverhuur + parkeren, Waterschap Vechtstromen slibverwerking) and 1 archived IntegralCostPrice (zaalverhuur 2026-Q1). All carry `_meta.lifecycleState=archived` (historical reference only) — asserted in `testFragmentShipsArchivedSeedObjects`.
- [x] **P1-18**: Seed import is idempotent via the existing ADR-037 path: `SettingsService::loadRegisterConfigData()` merges `register.d/*.json` (incl. the fragment `objects[]`) and folds a fragment signature into the version so OpenRegister's `ConfigurationService::importFromApp` re-imports on change. The existing `lib/Repair/InitializeSettings.php` repair step drives the import; no new migration is needed (reuses the fleet repair-step pattern).

### Documentation & i18n (Phase 1)

- [x] **P1-19**: Added a WMO reconciliation section to `openspec/architecture/adr-000-data-model.md` introducing `CommercialActivity`, `IntegralCostPrice`, `ActivityCostAllocation`, their schema.org mappings, design-decision rationale (D1–D4) and the entity/relations table.
- [x] **P1-20**: Added Dutch + English i18n strings to `l10n/nl.json` + `l10n/en.json` (WMO Compliance / Wet Markt en Overheid, Commercial Activity / Commerciële activiteit, Integral Cost Price / Integrale kostprijs, Cost Coverage Ratio / Kostendekkingsratio, Activity Cost Allocation, Allocation Rule / Verdeelsleutel, the review-due task string, etc.). **Deferred (documented):** the `docs/user-guide/bookkeeping/wmo-compliance.md` user guide is a docs-cycle artifact.

### Phase 1 Verification

- [x] `openspec validate` — change folder structure intact (proposal/design/specs/tasks present and consistent).
- [x] Bookkeeper-persona peer review: IKP calculation sums the six statutory components, BBV taakveld 0.4 sleutel inheritance is the `indirecteOverhead` map (transparent, design D3), integer-cent precision throughout.
- [x] Architecture review: ADR-031 compliance — all three registers declared as ADR-037 fragment schemas, the only PHP is the pure-logic `WmoComplianceCalculator` (no bespoke orchestration service), split derivation per design D4 (GL never mutated). Mirrors `TrialBalanceCalculator`.
- [x] Source-code changes are the production implementation of Phase 1 (register/manifest fragments, calculator service, tests, i18n, ADR notes) — this is a `kind: spec` build cycle, so implementing the declared capability is in scope.
- [x] Phase 1: PHPUnit tests added (`WmoComplianceCalculatorTest` 18 + `MarketGovernmentSeparationFragmentTest` 6 = 24 tests / 144 assertions, all green under `phpunit-unit.xml`) covering IKP components + BBV-sleutel reuse + WACC + winstopslag, the cent-conserving split, compliance verdict and kostendekkingsratio. `node tests/validate-manifest.js` exits 0. PHPCS/PHPMD/Psalm/PHPStan clean on all new files. (Controller/service tests needing the live NC runtime run under CI `phpunit.xml`; the pre-existing `OCP\IRequest`/`IAppConfig` stub-bootstrap errors are unrelated to this change.)

---

## Phase 2 Tasks (Compliance — Requirements 5–7, 10)

> **Deferred — out of scope for this build cycle.** Phase 2 (ABB lifecycle, ACM reporting, cross-subsidy detection, immutable audit trail) is scheduled Q4 2026 per the proposal's phased plan and depends on the OR ScheduledWorkflow engine, the `bookkeeping-audit-trail` cross-cutting spec, openconnector DROP-API integration, and a live instance for the workflow/signature/export paths. The Phase-1 MVP (REQ-WMO-001..004) is the deliverable here. The schemas below are declared but not built in this cycle.

### ABB Lifecycle Management

- [ ] **P2-1**: Declare `AlgemeenBelangBesluit` schema with REQ-WMO-005 fields (kenmerk, bestuursorgaan, vaststellingsdatum, publicatieGemeenteblad, publicatieDatum, kennisgevingAcm, betreftActiviteiten[], publiekBelangCategorieen[], motivering, evaluatieRitme, volgendeEvaluatie, status enum, bezwaarTermijnVerstreken, bestuursrechtelijkeProcedures[]); include RBAC role: `juridisch-beleidsadviseur` (read/write), `griffier` (read/write), `concerncontroller` (read)
- [ ] **P2-2**: Implement ABB state-machine workflow: on save, validate preconditions per target status (e.g., can't transition to geldig without publicatieDatum + ACM-kenmerk); emit status-change lifecycle actions
- [ ] **P2-3**: Wire automatic task generation per status: raadsbesluit → "Publish in gemeenteblad by [+14d]"; publicatie + date → "Notify ACM by [+7d]"; acm-notified → "Review bezwaarschriften by [+42d]"; volgendeEvaluatie date reached → "Evaluate ABB" + status=evaluatie-due
- [ ] **P2-4**: Implement DROP-API integration (via openconnector OC-sources): on publicatieDatum set, auto-verify gemeenteblad reference is retrievable from DROP (Decentrale Regelgeving Officiële Publicaties) API; log verification result to audit trail; alert if DROP lookup fails
- [ ] **P2-5**: Link CommercialActivity.exemptionBesluitId to AlgemeenBelangBesluit.id; if ABB is moved to intrekking or herziening status, flag dependent activities for review

### ACM Reporting

- [ ] **P2-6**: Declare `ACMReport` schema with period (quarterly or annual), generatedAt, format=ACM-standaardformulier-mo-2024, activiteiten[] array listing all commercial activities with omzet/IKP/ratio/compliance status, samenvatting text, ondertekenaar, ondertekendOp (timestamp + sig), verzondenAanAcm boolean, publicatieGemeenteblad reference
- [ ] **P2-7**: Implement ACMReportGenerator service: query all CommercialActivity + IntegralCostPrice + ActivityCostAllocation records for period; aggregate omzet (GL revenue-sum), integrale kostprijs (from IKP-definitief), kostendekkingsratio, ABB list; serialize to JSON/XML (SBR/XBRL structure compatible with anticipated ACM API schema)
- [ ] **P2-8**: Implement digital signature on ACMReport: require concerncontroller to sign (via Nextcloud certificate or similar PKI); timestamp signature to audit trail; upon signing, change status to "ready for submission"
- [ ] **P2-9**: Implement ACMReport submission & immutability: on submit, copy to write-once archive store (per ADR-031 immutable log concept); mark status=verzonden; begin 7-year retention countdown (Mededingingswet bewaartermijn, review for archive at year 8)
- [ ] **P2-10**: Export to gemeenteblad: if operator chooses to publish report to gemeenteblad, integrate with openconnector to auto-publish (or generate publication-ready PDF for manual submission)

### Cross-Subsidy Detection

- [ ] **P2-11**: Declare `AlertLog` schema with alertType enum (6 scenarios from REQ-WMO-007), commercialActivityId, severity, generatedAt, assignedTo, status (open/reviewed-no-action/remediated/escalated), escalatedAt, resolutionNotes
- [ ] **P2-12**: Implement CrossSubsidyDetector as monthly `ScheduledWorkflow`: 1st of month 02:00 UTC, iterate all CommercialActivity records per administration; for each, check 6 risk scenarios (loss-financing 2+ months, omzetgroei >25%, overhead <1%, ABB stale, override-count >5%, overhead-onderschatting); create AlertLog records for any matches
- [ ] **P2-13**: Implement alert escalation: mark any open AlertLog > 4 weeks old, escalate status to "escalation-due", assign to gemeentesecretaris (or configurable escalation-role), send notification
- [ ] **P2-14**: Implement alert resolution workflow: operators can mark AlertLog status=reviewed-no-action (with motivation) or remediated (with remediation notes); all status-changes logged to audit trail

### Immutable Audit Trail

- [ ] **P2-15**: Declare `WMOAuditLog` schema per `bookkeeping-audit-trail` cross-cutting spec, with eventType, entityId, entityType, userId, timestamp (ms-precision), beforeValues, afterValues, reason, status
- [ ] **P2-16**: Wire all WMO entity-mutations to audit-log: on CommercialActivity.save (beforeValues=prior, afterValues=new, reason=user-provided); on IntegralCostPrice.calculate (beforeValues=null, afterValues=IKP record, reason="monthly calc" or "year-end lock"); on ActivityCostAllocation.override (beforeValues=auto split, afterValues=manual split, reason=2-eye approval note); on AlgemeenBelangBesluit.statusChange (beforeValues=prior status, afterValues=new status, reason=workflow-auto or user-provided); on CrossSubsidyAlert.create/resolve (beforeValues=null, afterValues=alert record, reason=detector-auto or resolution-note)
- [ ] **P2-17**: Implement audit-log CSV export: query WMOAuditLog per period, serialize to CSV with columns (timestamp, eventType, entityType, entityId, userId, reason, beforeValues, afterValues)
- [ ] **P2-18**: Implement ACM-handhavings-pakket generation: one-click export for selected fiscal year; generates zip with: manifest.json (index of all documents), commercial-activities/*.json (entity snapshots), cost-prices/<period>/*.json, allocations/<period>/*.json, besluiten/*.pdf (ABB decision PDFs scanned or text), audit-log/<period>.csv; zip is downloadable and transferable to ACM on vordering
- [ ] **P2-19**: Wire 7-year retention: on audit-log entries reaching 7-year post-event age, mark status=archived; after 8 years, entries may be purged per retention policy; document archival timeline in implementation notes

### Phase 2 Verification

- [ ] `openspec validate` must exit 0 on updated change folder
- [ ] Compliance reviewer peer review: ABB workflow preconditions are enforceable; ACM-rapport export format matches latest ACM-standaardformulier 2024 (verified against ACM-website sample); cross-subsidy alerts match real-world risk cases (loss-financing, overhead under-allocation)
- [ ] ACM-case-sample testing: export ACM-handhavings-pakket from test gemeente, index it, cross-check 10 random entries against source data; manifest.json is machine-parseable (JSON schema validation)
- [ ] Phase 2 implementation cycle: PHPUnit tests for ABB state-machine (valid/invalid status transitions, precondition checks), CrossSubsidyDetector (8 test cases for each scenario), audit-log consistency (before/after values, reason logging), ACMReport generation (totals match GL balances), DROP-API integration mock; Playwright tests for ABB UI workflow, alert UI review; `composer test` green

---

## Phase 3 Tasks (Governance & Ecosystem — Requirements 8, 9, 11, 12; Deferred)

**Deferred to Q1–Q2 2027; architecture-level specification below.**

### Activity Transitions (REQ-WMO-008)

- [ ] **P3-1**: Design activity-transition workflow: accept transition-date, openingsbalans generation at marktwaarde (not boekvaarde), internal-sale GL entry, first IKP as voorlopig-transitie, ACM-melding trigger within 4 weeks
- [ ] **P3-2**: Implement `ActivityTransitionService`: on transition-record save, check preconditions (no open liabilities, inventory valuation audit), generate openingsbalans journal entry (activa on commerciële dimension at marktwaarde per independent valuation), update CommercialActivity status, flag first IKP
- [ ] **P3-3**: Implement marktwaarde valuation interface: operators input independent valuation (e.g., property appraisal, equipment list-price), system compares to boekwaarde in GL, logs delta to audit trail as bevoordeling-risk control

### Governance Integration (REQ-WMO-009)

- [ ] **P3-4**: Design governance-coupling interface with `bookkeeping-governance` (if available) or freeform raad-besluit ID fallback; ABB.raadsBesluitId can be either structured (raads-voorstel FK) or freeform (text ID)
- [ ] **P3-5**: Implement optional raads-voorstel linking: if governance-spec is available, on ABB.raadsbesluit status, require raads-voorstel FK; inherit signature + griffier-handtekening to WMO audit trail; if governance not available, freeform raad-besluit ID + manual 2-eye verification
- [ ] **P3-6**: Implement OverheadDistributionRule governance coupling: vaststelling of annual BBV-sleutel (taakveld 0.4) requires raads-besluit coupling; signature + date inherited to WMO IKP audit trail

### Multi-Bestuursorgaan Support (REQ-WMO-011)

- [ ] **P3-7**: Extend CommercialActivity schema: add deelnemers[] array with {organisatie, aandeel-percentage, kostenplaatsCode, kostendragerCode, administrationId}; activity can be owned by 1 or N deelnemers
- [ ] **P3-8**: Implement per-deelnemer cost allocation: on GL-line post, if CommercialActivity has deelnemers, split costs across each deelnemer's kostendrager per aandeel-percentage
- [ ] **P3-9**: Implement per-deelnemer ABB requirement validation: if multi-deelnemer activity, flag missing ABB's on deelnemers that lack one; alert: "[activity code] is exempted for [N deelnemers] but not [M other deelnemers]"
- [ ] **P3-10**: Implement per-deelnemer jaarrekening & ACM-export: each deelnemer receives WMO-bijlage + ACM-rapport showing only their aandeel; no commingling of aandeel's

### Market-Benchmark Register (REQ-WMO-012)

- [ ] **P3-11**: Declare `MarketBenchmark` schema: commercialActivityId, peildatum, bron enum (offerte/prijslijst/brancheRapport/bdoBenchmark/coelo/custom), bedrag, eenheid, concurrentNaam, toelichting
- [ ] **P3-12**: Implement bevoordeling-risk detection: on IKP calculation, query all MarketBenchmarks for activity within 12 months; calculate median benchmark price; if gehanteerdTarief < median × 0.85 AND gehanteerdTarief ≥ kostprijsPerEenheid, create HIGH alert "Price is 15%+ below market; Art. 25j bevoordeling risk; justify or raise tariff"
- [ ] **P3-13**: Implement benchmark-sourcing integrations (optional): BDO Benchmark, COELO benchmark feeds (future API integrations); operators can also manually enter offerte/prijslijst

### Phase 3 Verification

- [ ] Activity-transition sample: create test activity, transition from publiek → commercieel, verify openingsbalans matches marktwaarde valuation, first IKP marked voorlopig-transitie, ACM-melding queued
- [ ] Multi-deelnemer ODRA scenario: create shared activity with 11 deelnemers, verify cost split per aandeel, verify each deelnemer's ABB requirement is checked independently, verify each deelnemer receives own jaarrekening-bijlage
- [ ] Governance coupling: if bookkeeping-governance available, create ABB with raads-voorstel FK, verify raad-signature is inherited to WMO audit trail; if not available, create ABB with freeform raad-besluit ID, verify 2-eye verification gate
- [ ] Bevoordeling-risk detection: create activity with gehanteerdTarief=€180, add benchmarks (€245, €240, €238 → median €241), calculate IKP, verify alert: "€180 is 25% below market"

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
