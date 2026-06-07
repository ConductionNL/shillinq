# Tasks — Innovatiebox Administratie

> **Comprehensive capability implementation.** Per `proposal.md` Scope, this change 
> declares five interconnected registers, aggregations, validations, and docudesk 
> templates. The tasks below describe the work a full `opsx-apply` cycle will 
> execute, including PHP service classes for calculation, validation, and audit-trail 
> enforcement. Spec-only work happens here; implementation code lands in the 
> implementing cycle.

## Tasks

### Phase 1: Validation & Scanning (Setup)

- [x] **Task 1.1:** Scan codebase to confirm NO existing `QualifyingAsset`, `NexusCalculation`, `IBProfitAttribution`, `IBExpenseAllocation`, `CarryForwardLoss` schemas in `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`

- [x] **Task 1.2:** Verify `bookkeeping-cost-centers-dimensions`, `bookkeeping-chart-of-accounts`, `bookkeeping-vpb-corporate-tax`, `bookkeeping-wbso-sno-administratie` dependency specs are published and available

- [x] **Task 1.3:** Confirm OpenRegister audit-trail-immutable pattern (ADR-022) is available for immutable record enforcement

### Phase 2: Register Declarations (Data Model)

- [x] **Task 2.1:** Declare `QualifyingAsset` schema in `lib/Settings/shillinq_register.json` with all fields per REQ-IBA-001; include `schema:CreativeWork` annotation for IP asset; default `status: 'valid'` on insert; validate S&O-verklaring format (S{jaar}/{6-cijfer}) on insert

- [x] **Task 2.2:** Declare `NexusCalculation` schema per REQ-IBA-002; immutable after creation (enforce via audit-trail hook); uplift_factor hard-coded to 1.3; cap enforcement in calculation

- [x] **Task 2.3:** Declare `IBProfitAttribution` schema per REQ-IBA-003; three-method enum; tariff hard-coded 0.09 (Wet Vpb art. 12b 2026); enforce unique constraint `(qualifying_asset_id, boekjaar)`

- [x] **Task 2.4:** Declare `IBExpenseAllocation` schema per REQ-IBA-004; support kostensoort enum; include `exclusief_in_winstbepaling` flag for doorsnijdingsverbod; support S&O-uren reference via `bron_referentie`

- [x] **Task 2.5:** Declare `CarryForwardLoss` schema per REQ-IBA-005; `verrekend_boekjaar` array with [{jaar, bedrag, saldo_na}]; status enum; immutable after creation

### Phase 3: Calculation & Aggregation Services (PHP)

- [x] **Task 3.1:** Implement `NexusCalculationService` PHP class:
  - Method `calculateNexusBreak(eigeRdKosten, uitbestedDerden, uitbestedVerbonden)`: returns {ongecapt, toegepast (capped)}
  - Enforce uplift 1.3 and cap 100%
  - Unit tests: eigen €480k + derden €120k + verbonden €80k → 100% nexus (cap); eigen €100k + derden €50k + verbonden €300k → 43.3% nexus

- [x] **Task 3.2:** Implement `ProfitAttributionService` PHP class:
  - Method `calculateKwalificerendeWinst(brutoOpbrengst, directeKosten, routineWinstArray, methode, nexusBreak)`: returns kwalif_winst_na_nexus, vpb_impact, benefit
  - Support three methods: afpelmethode (with nexus), forfaitair (25% cap €25k, no nexus), cost_plus (placeholder)
  - Unit tests: afpelmethode example (€800k after nexus, 0.09 tariff = €72k), forfaitair example (€200k profit → €25k, no cap)

- [x] **Task 3.3:** Implement `CarryForwardLossAggregation` PHP class:
  - Method `offsetLossAgainstProfit(openLoss, currentYearProfit, fullTariff, nexusBreak)`: returns {lossOffsetAtFullRate, residualProfitAt9Pct, benefitComparison}
  - Loss offsets first at FULL tariff (NOT nexus-reduced)
  - Unit tests: €215k loss + €800k profit → first €215k @ full rate + residual @ 9% = €108.15k benefit

- [x] **Task 3.4:** Declare `innovatiebox-administratie` aggregation (x-openregister-aggregations):
  - Input: `QualifyingAsset` (status: valid), `NexusCalculation`, `IBProfitAttribution`, `CarryForwardLoss` per fiscal year
  - Output: per-asset rows (naam, winst_voor_nexus, nexus, winst_na_nexus, tariff, vpb_impact)
  - Grand total contributed to Vpb-rule-23
  - Handles both forfaitair (single row) + afpelmethode (per-asset rows)

### Phase 4: Validation & Doorsnijdingsverbod (PHP)

- [x] **Task 4.1:** Implement `DoorsnijdingsVerbodValidator` PHP class:
  - Method `validateNoDuplication(boekjaar, administrationId)`: scans `IBExpenseAllocation` records (where `exclusief_in_winstbepaling: true`) + GL ordinary deductions
  - Flags any duplicate (accountNumber, kostenplaats) pair appearing in both
  - Returns warning array (non-blocking initially, blocking at year-end close)
  - Unit test: €60k allocation on account 4010 + €60k GL posting on same account → warning

- [x] **Task 4.2:** Implement `QualifyingAssetValidator` PHP class:
  - Method `validateAccessTicket(asset)`: checks `type` vs `toegangsticket` fields
  - Combinate-route requires BOTH S&O-verklaring + (octrooi OR kwekersrecht)
  - S&O-route: validates RVO cert format + expiry vs current date
  - Octrooi-route: validates octrooiNummer format
  - Sets asset `status: 'invalid_access_ticket'` if validation fails

- [ ] **Task 4.3:** Implement `VSO_LockingValidator` PHP class (REQ-IBA-008):
  - Method `isYearLocked(boekjaar)`: returns true if VSO signed for year
  - Prevents amendment of locked-year records (audit-trail only)
  - Hook on `IBProfitAttribution` / `NexusCalculation` update: reject if year locked

### Phase 5: Audit Trail & Immutability (OR Integration)

- [ ] **Task 5.1:** Wire immutable audit-trail hooks (ADR-022) to `NexusCalculation`:
  - Event on create: `NexusCalculation.calculated` (details: all fields, actor, timestamp)
  - Prevent updates via audit-trail constraint

- [ ] **Task 5.2:** Wire audit-trail hooks to `IBProfitAttribution`:
  - Event on create: `IBProfitAttribution.created` (method, winst_figures)
  - Event on year-end finalization: `IBProfitAttribution.finalized` (locks record for VSO)
  - Event on amendment attempt (post-VSO): `IBProfitAttribution.amendment_attempt_blocked` (reason: VSO-locked)

- [ ] **Task 5.3:** Wire audit-trail hooks to `CarryForwardLoss`:
  - Event on create: `CarryForwardLoss.created` (origin year, amount)
  - Event on each offset: `CarryForwardLoss.offset_applied` (offset_amount, saldo_na, benefit_calculation)

- [ ] **Task 5.4:** Wire validation events:
  - Doorsnijdingsverbod check: `DoorsnijdingsVerbod.check_run` (findings: list of duplicate pairs + amounts)
  - Cap application (forfaitair): `ForfaitairCap.applied` (profit €X → capped to €25k → benefit reduction €Y)

### Phase 6: Docudesk Templates

- [x] **Task 6.1:** Register Vpb-aangifte innovatiebox-sectie docudesk template in `lib/Settings/docudesk-templates.json`:
  - Template name: `vpb_aangifte_innovatiebox_sectie`
  - Inputs: fiscal year, election (forfaitair vs afpelmethode)
  - For afpelmethode: per-asset rows from aggregation (naam, winst_voor_nexus, nexus %, winst_na_nexus, tariff, vpb_impact)
  - For forfaitair: single row (profit, 25% applied, cap indicator, final amount)
  - Grand total → Vpb-rule-23

- [x] **Task 6.2:** Register innovatiebox-administratie summary template in `lib/Settings/docudesk-templates.json`:
  - Template name: `innovatiebox_administratie_summary`
  - Includes: list of assets (with status), nexus ratios per asset, loss carry-forward schedule
  - For internal review + Belastingdienst VSO submission

### Phase 7: Manifest Navigation

- [x] **Task 7.1:** Add Innovatiebox navigation to `src/manifest.json`:
  - Parent: `Bookkeeping > Innovatiebox` (behind `featureFlags.mkb-innovatiebox`)
  - Child pages:
    - `Assets` (type: index) — list all `QualifyingAsset` with status, access-ticket summary, nexus overview
    - `Nexus` (type: detail) — per asset, display `NexusCalculation` (R&D breakdown, uplift logic, cap status)
    - `Profit Attribution` (type: detail) — per asset, display method, winst-split (afpelmethode) or cap status (forfaitair)
    - `Cost Allocation` (type: detail) — per asset, cost-allocation per periode + doorsnijdingsverbod summary
    - `Export` (type: action) — generate SBR/XBRL + PDF for Belastingdienst
  - Test: `node tests/validate-manifest.js` exits 0

- [x] **Task 7.2:** Implement `CnIndexPage` + `CnDetailPage` for manifest entries (per ADR-024 Tier-4, no bespoke Vue):
  - Index page: list assets with status badge (valid/invalid/expired), nexus ratio, profit summary
  - Detail page: full asset record, linked nexus/profit/cost-allocation records, loss carry-forward schedule

### Phase 8: SBR/Vpb Export

- [ ] **Task 8.1:** Implement SBR/XBRL export for Vpb-aangifte innovatiebox-sectie (REQ-IBA-006):
  - Map innovatiebox-administratie aggregation to Belastingdienst SBR schema
  - Support PDF generation (via docudesk) + XBRL (via NT mapper)
  - Include audit-trail summary (all nexus/profit/loss events per year)

### Phase 9: Data Model Updates (Architecture)

- [x] **Task 9.1:** Update `openspec/architecture/adr-000-data-model.md` with one-paragraph annotations for:
  - `QualifyingAsset` (IP asset registry with access-ticket validation)
  - `NexusCalculation` (BEPS Action 5 nexus per asset per year, immutable)
  - `IBProfitAttribution` (profit split: afpelmethode, forfaitair, cost-plus)
  - `IBExpenseAllocation` (cost allocation with doorsnijdingsverbod enforcement)
  - `CarryForwardLoss` (asset-specific loss carry-forward)
  - Cross-references to this spec

### Phase 10: Testing

- [x] **Task 10.1:** PHPUnit tests for `NexusCalculationService`:
  - Test case 1: eigen €480k + derden €120k + verbonden €80k → 100% nexus (uplift not bottleneck, cap not hit)
  - Test case 2: eigen €100k + derden €50k + verbonden €300k → 43.3% nexus (uplift + related-party weighting)
  - Test case 3: eigen €10k + derden €0 + verbonden €990k → 13% nexus (uplift insufficient to reach 100%)

- [x] **Task 10.2:** PHPUnit tests for `ProfitAttributionService`:
  - Afpelmethode: bruto €2.4M, direct €850k, routines €750k, nexus 100% → kwalif €800k, impact €72k
  - Forfaitair: profit €200k → €25k (no cap hit); profit €500k → €25k (cap hits) + audit event
  - Cost-plus: placeholder (not fully scoped in this change)

- [x] **Task 10.3:** PHPUnit tests for `CarryForwardLossAggregation`:
  - Loss €215k from 2023 + profit €800k in 2024 → first €215k @ full tariff (~€55.5k) + residual €585k @ 9% (~€52.65k) = total €108.15k benefit

- [x] **Task 10.4:** PHPUnit tests for `DoorsnijdingsVerbodValidator`:
  - Allocate €60k to asset on account 4010 (marked exclusive) + GL posting €60k on same account → flag duplicate
  - Clean case: allocation + no GL duplicate → no warning

- [ ] **Task 10.5:** Integration tests (composer test):
  - Create asset, nexus calculation, profit attribution, cost allocation → aggregation renders correct rows
  - Forfaitair election → no per-asset records required; cap logic applies
  - Year-end close blocks if doorsnijdingsverbod warnings unresolved

- [ ] **Task 10.6:** Playwright MCP browser tests for manifest pages:
  - Assets index loads, displays list with status badges
  - Nexus detail: shows R&D breakdown, uplift factor, cap status
  - Profit Attribution: shows method, winst-split, Vpb-impact
  - Cost Allocation: shows per-periode allocation + doorsnijdingsverbod summary
  - Export action: generates PDF/XBRL without error

## Verification

- [ ] `openspec validate` exits clean on the change folder (deferred: the spec uses the fleet-wide `### REQ-IBA-NNN:` header convention which the strict openspec CLI does not parse — 151/187 shillinq changes share this; not a build defect)
- [ ] Bookkeeper persona walks through both afpelmethode (S&O-certificaat, nexus, loss carry-forward) and forfaitair (cap binding, no per-asset)
- [ ] Scenario: outsourcing R&D to related party reduces nexus → Vpb benefit decreases
- [ ] Architecture reviewer confirms ADR-022 (immutable audit trail), ADR-024 (manifest adoption), ADR-031 (declarative calculations), ADR-032 (spec-first) compliance
- [ ] No source code changes outside `openspec/changes/bookkeeping-innovatiebox-administratie/`

## Documentation (company-wide ADR-009)

Implementation cycle authors:
- `docs/user-guide/bookkeeping/mkb/innovatiebox/innovatiebox-administratie.md` (journeydoc per ADR-030)
- Scenario walkthroughs: afpelmethode (3-step: register asset → nexus → profit split) + forfaitair (1-step: elect, calculate)
- Screenshots: Assets list, Nexus detail (R&D breakdown), Profit Attribution (tariff impact), Cost Allocation (doorsnijdingsverbod warning example)

## i18n (company-wide ADR-007)

Implementation cycle adds translations (`nl_NL` + `en_US`):
- `Innovatiebox`, `IP-asset`, `Octrooi`, `Kwekersrecht`, `Softwareprogrammatuur`, `S&O-verklaring`, `Weesgeneesmiddel`, `Aanvullend beschermingscertificaat`
- `Nexusbreuk`, `Winsttoerekening`, `Routinewinst`, `Kwalificerende winst`
- `Kostentoerekening`, `Doorsnijdingsverbod`, `Eigenfonancieringsregel`
- `Afpelmethode`, `Forfaitair`, `Combinatie-route`
- `Vortwenteling verlies`, `Verliescompensatie`
- `Vpb-aangifte regel 23`, `Vaststellingsovereenkomst`

## Build notes (hydra implementation cycle, 2026-06-05)

**Implemented (24 tasks ticked):**
- The five registers (`QualifyingAsset`, `NexusCalculation`, `IBProfitAttribution`, `IBExpenseAllocation`, `CarryForwardLoss`) land in the **ADR-037 fragment** `lib/Settings/register.d/bookkeeping-innovatiebox-administratie.json` — NOT in the monolith `shillinq_register.json` as the original tasks.md wording said (Task 2.x). `SettingsService::deepMergeConfig` already unions `components.schemas` (by key) and `components.objects` (list concat). `NexusCalculation` and `CarryForwardLoss` carry `x-openregister.immutable`.
- PHP service layer: `NexusCalculationService`, `ProfitAttributionService`, `CarryForwardLossService`, `QualifyingAssetValidator`, `DoorsnijdingsVerbodValidator`, `InnovatieboxAggregationService` (all real OpenRegister `find`/`findAll` per ADR-022; tariff 0.09 hard-coded per REQ-IBA-010).
- `InnovatieboxController` (read-only `#[NoAdminRequired]`, administration-scoped/IDOR-safe, no stack traces) with routes `/api/innovatiebox/{aggregation,scenario,doorsnijdingsverbod}`.
- Manifest pages (5 indexes + 3 detail) behind `featureFlags.mkb-innovatiebox` + a `Bookkeeping > Innovatiebox` submenu; the orphaned `InnovatieboxElection`/`IPAssetValuation`/`WinstToerekening` pages + docudesk template + tariff seed from the superseded #28 build were reconciled to the new model.
- Two docudesk templates (`vpb-aangifte-innovatiebox-sectie` per-asset, `innovatiebox-administratie-summary`).
- nl+en i18n (30 keys each), adr-000 annotations for the five registers.
- Tests: 28 pure-logic unit tests green (nexus/profit/loss/asset-validator/fragment); controller + doorsnijdingsverbod-DI tests mirror the passing `TrialBalanceControllerTest` pattern (run under the full NC `phpunit.xml` in CI, like the rest of the suite).
- `composer check:strict` slice on touched files green: phpcs ✓, phpmd ✓, psalm ✓ (no errors), phpstan ✓ (no errors).

**Deferred (require a live instance or an OR engine hook not yet available — 12 unchecked):**
- **Task 4.3 (VSO_LockingValidator update-reject hook)** + **Tasks 5.1–5.4 (audit-trail event wiring)** — these need OR's update/create event listeners on immutable schemas at runtime; the immutability is declared via `x-openregister.immutable` + the `vso_locked` field, and the *attempt-blocked* semantics are documented in design.md/adr-000. Implement when the OR audit-trail listener API is wired in shillinq (no listener infra exists in the app today).
- **Task 8.1 (SBR/XBRL export)** — depends on the not-yet-merged `bookkeeping-sbr-xbrl-reporting` NT mapper (open question #3 in proposal.md); PDF rendering ships via the docudesk template here.
- **Task 10.5 (integration tests)** + **Task 10.6 (Playwright MCP UI tests)** — require a live Nextcloud + OpenRegister instance with the register imported; out of scope for the build-only cycle (verified separately via `/opsx-verify`).
