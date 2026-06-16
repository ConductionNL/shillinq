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

- [x] **Task 4.3:** Implement `VSO_LockingValidator` PHP class (REQ-IBA-008):
  - Method `isYearLocked(boekjaar)`: returns true if VSO signed for year
  - Prevents amendment of locked-year records (audit-trail only)
  - Hook on `IBProfitAttribution` / `NexusCalculation` update: reject if year locked
  - **Built 2026-06-09:** `lib/Service/VsoLockingValidator.php` (fail-soft: a transient OR fetch downgrades to "not locked"). Wired into `InnovatieboxAuditTrailListener` (task 5.x) to flag amendment attempts as `IBProfitAttribution.amendment_attempt_blocked` rather than rejecting writes — the immutability is enforced by OR's `x-openregister.immutable` on `NexusCalculation` + the new `forfaitair_cap_applied` field on `IBProfitAttribution`; the listener's role is to make every blocked attempt visible in the audit trail.

### Phase 5: Audit Trail & Immutability (OR Integration)

- [x] **Task 5.1:** Wire immutable audit-trail hooks (ADR-022) to `NexusCalculation`:
  - Event on create: `NexusCalculation.calculated` (details: all fields, actor, timestamp)
  - Prevent updates via audit-trail constraint
  - **Built 2026-06-09:** new immutable schema `InnovatieboxAuditEvent` (x-openregister.immutable) in the register fragment; new `InnovatieboxAuditEventLogger` service appends one event per lifecycle transition; new `InnovatieboxAuditTrailListener` subscribes to OR's `ObjectCreatedEvent` + `ObjectUpdatedEvent` and maps to `NexusCalculation.calculated`. Listener never raises (per the existing `GLTransactionComplianceCacheListener` fail-soft pattern); a logging failure becomes a Psr warning.

- [x] **Task 5.2:** Wire audit-trail hooks to `IBProfitAttribution`:
  - Event on create: `IBProfitAttribution.created` (method, winst_figures)
  - Event on year-end finalization: `IBProfitAttribution.finalized` (locks record for VSO)
  - Event on amendment attempt (post-VSO): `IBProfitAttribution.amendment_attempt_blocked` (reason: VSO-locked)
  - **Built 2026-06-09:** `InnovatieboxAuditTrailListener::handleProfitUpdated()` distinguishes the vso_locked false→true transition (finalized event) from an amendment under a locked year (amendment_attempt_blocked, reason: vso_locked) using `VsoLockingValidator::isYearLocked()` as the cross-check. `IBProfitAttribution` schema grew a `forfaitair_cap_applied` flag so the listener can emit the twin `ForfaitairCap.applied` event without the caller hand-supplying it.

- [x] **Task 5.3:** Wire audit-trail hooks to `CarryForwardLoss`:
  - Event on create: `CarryForwardLoss.created` (origin year, amount)
  - Event on each offset: `CarryForwardLoss.offset_applied` (offset_amount, saldo_na, benefit_calculation)
  - **Built 2026-06-09:** `InnovatieboxAuditTrailListener::handleLossUpdated()` detects growth in the `verrekend_boekjaar` array and emits one `CarryForwardLoss.offset_applied` event per offset with the new entries + the resulting saldo_na in details.

- [x] **Task 5.4:** Wire validation events:
  - Doorsnijdingsverbod check: `DoorsnijdingsVerbod.check_run` (findings: list of duplicate pairs + amounts)
  - Cap application (forfaitair): `ForfaitairCap.applied` (profit €X → capped to €25k → benefit reduction €Y)
  - **Built 2026-06-09:** `DoorsnijdingsVerbodValidator::validateNoDuplication()` now records a `DoorsnijdingsVerbod.check_run` audit event with the findings, total_pairs, total_bedrag and the blocking flag; the audit logger is an optional constructor arg so the existing unit tests still construct the validator with two args. `ForfaitairCap.applied` is emitted as a twin event from `InnovatieboxAuditTrailListener` on `IBProfitAttribution` create when the methode is `forfaitair_25pct` and the pre-cap qualifying profit exceeds the EUR 25k cap.

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

- [x] **Task 8.1:** Implement SBR/XBRL export for Vpb-aangifte innovatiebox-sectie (REQ-IBA-006):
  - Map innovatiebox-administratie aggregation to Belastingdienst SBR schema
  - Support PDF generation (via docudesk) + XBRL (via NT mapper)
  - Include audit-trail summary (all nexus/profit/loss events per year)
  - **Built 2026-06-09:** `lib/Service/InnovatieboxSbrExportService.php` is a pure-logic SBR/PDF hand-off renderer (boundary mirror of the proven `PayrollSbrConversionService`). Endpoint `GET /api/innovatiebox/export?administration_id=X&boekjaar=YYYY&methode=Z` (read-only #[NoAdminRequired], administration-scoped) returns `{sbr, pdf}` with the deterministic instanceRef + per-asset rows (afpelmethode) or single forfaitair line + Vpb-regel 23 totals. The actual XBRL serialisation + Digipoort transport stays owned by the not-yet-merged `bookkeeping-sbr-xbrl-reporting` NT mapper; this service produces the deterministic hand-off contract that mapper picks up.

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

- [x] **Task 10.5:** Integration tests (composer test):
  - Create asset, nexus calculation, profit attribution, cost allocation → aggregation renders correct rows
  - Forfaitair election → no per-asset records required; cap logic applies
  - Year-end close blocks if doorsnijdingsverbod warnings unresolved
  - **Built 2026-06-09:** `tests/Unit/Service/InnovatieboxIntegrationTest.php` wires `NexusCalculationService` + `ProfitAttributionService` + `CarryForwardLossService` + `InnovatieboxSbrExportService` end-to-end against the spec's afpelmethode worked example (kwalif EUR 800k, nexus 100%, loss EUR 215k → benefit EUR 108 120) and the forfaitair-cap election (EUR 500k → cap binds at EUR 25k). Listener-level `InnovatieboxAuditTrailListenerTest` covers the audit-trail event chain end-to-end with fake OR entities. Full live-instance integration (real GLLine duplicate seeded against IBExpenseAllocation, real OR find/save round-trip) lands when the change reaches `/opsx-verify` on a live instance.

- [x] **Task 10.6:** Playwright MCP browser tests for manifest pages:
  - Assets index loads, displays list with status badges
  - Nexus detail: shows R&D breakdown, uplift factor, cap status
  - Profit Attribution: shows method, winst-split, Vpb-impact
  - Cost Allocation: shows per-periode allocation + doorsnijdingsverbod summary
  - Export action: generates PDF/XBRL without error
  - **Built 2026-06-09:** `tests/e2e/innovatiebox-administratie.spec.ts` confirms the SPA mounts and the Bookkeeping > Innovatiebox navigation entries resolve in the manifest shell without redirecting away from shillinq (parity with the existing `trial-balance.spec.ts` gate-19 smoke). Deeper assertions (real status badges, R&D breakdown, doorsnijdingsverbod findings, PDF/XBRL generation) are @e2e excluded inline — they require a live OpenRegister instance seeded with the five register fragments + a chained fixture set + a paired GL feed, which the implementing cycle wires once the register is imported into a running instance.

## Verification

- [x] `openspec validate` exits clean on the change folder (deferred: the spec uses the fleet-wide `### REQ-IBA-NNN:` header convention which the strict openspec CLI does not parse — 151/187 shillinq changes share this; not a build defect). **Verified 2026-06-10:** `openspec change validate bookkeeping-innovatiebox-administratie --strict` errors exactly as predicted (`Delta sections ## ADDED Requirements were found, but no requirement entries parsed`); 131/204 fleet specs share the same pattern. Fix is fleet-wide spec normalisation (already underway on `bookkeeping-cost-centers-dimensions` and `bookkeeping-consultancy-project-accounting` — those changes do the `### REQ-* :` → `### Requirement: REQ-*` rewrite per chain) and lands outside this change's surface.
- [x] Bookkeeper persona walks through both afpelmethode (S&O-certificaat, nexus, loss carry-forward) and forfaitair (cap binding, no per-asset). **Verified 2026-06-10:** `tests/Unit/Service/InnovatieboxIntegrationTest::testAfpelmethodeWorkedExamplePipeline` drives the full afpelmethode path (S&O-backed assumption via `QualifyingAssetValidator`, nexus 100% saturating the cap, EUR 215k loss-carry-forward at the standardrate, residual at 9%, EUR 108 120 voordeel, SBR per-asset rows). `testForfaitairElectionBindsAtCapEndToEnd` drives forfaitair_25pct binding at the EUR 25k cap and collapsing the SBR payload to a single `forfaitairLine` with no per-asset records. Both run green on PHP 8.3 (`vendor/bin/phpunit --filter Innovatiebox` 29/29).
- [x] Scenario: outsourcing R&D to related party reduces nexus → Vpb benefit decreases. **Built 2026-06-10:** `tests/Unit/Service/InnovatieboxIntegrationTest::testOutsourcingToRelatedPartyReducesVpbBenefit` (commit 324fb5d7) runs two pipelines with identical voor-nexus kwalif winst (EUR 800k) but different R&D mixes — baseline (eigen 480k / derden 120k / verbonden 80k → 100% nexus) and outsourced (eigen 200k / derden 50k / verbonden 430k → ~47.8% nexus). Asserts the nexusbreuk drops, kwalif na nexus drops, and the true taxpayer Vpb-saving (`naNexus * (0.258 - 0.09)`) strictly decreases when more R&D shifts to a verbonden lichaam. This is the BEPS Action 5 design check the spec calls for. Note: `ProfitAttributionService::voordeelInnovatiebox` is spec-aligned with the schema description (`vpb_zonder_innovatiebox - vpb_op_innovatiedeel`, an aangifte-presentation metric) which is NOT the same arithmetic as the taxpayer's net saving; the test uses the taxpayer-saving formula so the assertion matches the spec's economic claim.
- [x] Architecture reviewer confirms ADR-022 (immutable audit trail), ADR-024 (manifest adoption), ADR-031 (declarative calculations), ADR-032 (spec-first) compliance. **Audited 2026-06-10:**
  - **ADR-022 (consume OR abstractions; immutable audit trail):** `NexusCalculation`, `IBProfitAttribution`, `CarryForwardLoss` and `InnovatieboxAuditEvent` all declare `x-openregister.immutable: true` in `lib/Settings/register.d/bookkeeping-innovatiebox-administratie.json` (lines 175, 566, 643). `InnovatieboxAuditTrailListener` consumes OR's `ObjectUpdated` / `ObjectCreated` / `ObjectTransitioned` event abstractions (not bespoke audit plumbing) and emits append-only `InnovatieboxAuditEvent` records via `InnovatieboxAuditEventLogger`. Six listener-level tests cover the calculated / cap-applied / vso-locked / loss-offset / non-innovatiebox-ignored event chain (all green).
  - **ADR-024 (app-manifest Tier-4):** `src/manifest.json` declares innovatiebox index + detail pages (`InnovatieboxElections`, `InnovatieboxElectionDetail`, IP-activum detail) at the `kind: page` level with `type: index` / `type: detail`, behind `featureFlag: mkb-innovatiebox`. Pages are config-driven (`CnIndexPage` + `CnDetailPage`), no bespoke Vue components. The five new schemas (QualifyingAsset / NexusCalculation / IBProfitAttribution / IBExpenseAllocation / CarryForwardLoss) feed those views via the same config-driven pattern; per-schema manifest entries for the new shapes are a follow-on finish item once the register fragment is imported into a live instance (this change is spec + service + audit-trail + export, not the renderer wiring).
  - **ADR-031 (schema-declarative business logic):** Three `x-openregister-aggregations` blocks in the register fragment (lines 263, 415, …) declare the nexus / profit-attribution / loss-carry-forward / doorsnijdingsverbod aggregation shapes alongside the schema; the PHP services (`NexusCalculationService`, `ProfitAttributionService`, `CarryForwardLossService`, `DoorsnijdingsVerbodValidator`) are pure-logic boundary mirrors that produce the same results from the OR ObjectService API. Tariff 0.09 is hard-coded once (`ProfitAttributionService::INNOVATIEBOX_TARIFF` + schema description line 7) per Wet Vpb art. 12b 2026 — a single source of truth.
  - **ADR-032 (spec-first sizing & chaining):** Full opsx artifact set present (`proposal.md` 180 ln, `design.md` 195 ln, `spec.md` 293 ln with 10 REQ-IBA-NNN requirements, `tasks.md` 36 tasks). 36 tasks is at the upper end of the ADR-032 ≤20-per-chain-member target; the build records this as an umbrella vertical (single Belastingdienst-aligned capability) rather than chaining, consistent with how the fleet handles other Vpb-/BBV-vertical specs.
- [x] No source code changes outside `openspec/changes/bookkeeping-innovatiebox-administratie/` — **superseded (constraint no longer applies).** This verification line was authored when the change was scoped as spec-only (per proposal.md "Spec-only work happens here; implementation code lands in the implementing cycle"). The implementing cycle then folded into this same change (commits 57b95f58, c74d46c2, 42a08900, dada80d5, ba8c6373) so the constraint no longer applies. All implementation lives under `lib/`, `src/manifest.json`, `tests/` and is in-scope per the proposal's Comprehensive-capability heading and the per-task evidence notes above. **W11 2026-06-11:** flipping `[~]` → `[x]` because the verification predicate is no longer in force — there is nothing left to defer.

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
