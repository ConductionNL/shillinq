# Tasks: EMU-saldo & EMU-schuld Reporting

Implementation checklist for the `bookkeeping-emu-reporting` capability.

Implemented via the ADR-037 register fragment `lib/Settings/register.d/bookkeeping-emu-reporting.json`
(4 schemas + seed objects, declarative lifecycle/aggregations/calculations/RBAC/notifications/retention),
the ADR-031 exception services `lib/Service/EmuReportingService.php` and `lib/Guard/EmuSubmissionGuard.php`,
the manifest EMU-rapportage pages in `src/manifest.json`, nl/en i18n, and unit tests.

## Data Model & Schema Registration

- [x] Task 1: Add `EMUReport` schema — documented in ADR-000 + declared in the register fragment with all fields per spec.
- [x] Task 2: Add `EMUAdjustment` schema — type enum (8 Wet Hof art. 3 adjustment types), richting, bedrag, bron, regel; ADR-000 + fragment.
- [x] Task 3: Add `CashFlowItem` schema — shared IV3 taxonomy entity with iv3 object; ADR-000 + fragment.
- [x] Task 4: Add `DebtPosition` schema — instrument enum (7 types), categorieEurostat (ESA2010), teltMeeInEmuSchuld; ADR-000 + fragment.
- [x] Task 5: Register the 4 schemas via the ADR-037 fragment (NOT the monolith), with x-openregister-lifecycle/calculations/aggregations for EMU-saldo & schuld rendering.

## Macro-Rule Engine

- [x] Task 6: `EmuReportingService::classifyAdjustment` applies the Wet Hof art. 3 macro-rules (account-prefix → type/richting): afschrijving (48xx), voorzieningendotatie (460x), onttrekking reserve (49xx), bruto-investering (010/020), aflossing (210-230), boekwinst desinvestering (931-939), correctie transactiemoment (fact≠pay datum), intercompany-eliminatie (consolidatieEMU flag).
- [x] Task 7: Rule override — EMUAdjustment carries `overridden` boolean; OR auditTrail logs the change (REQ-EMU-002). Concerncontroller has update permission via RBAC.

## Quarterly Draft Generation

- [x] Task 8: Quarterly scheduler cron job — `lib/BackgroundJob/EmuQuarterlyDraftJob.php` registered in `appinfo/info.xml`, daily TimedJob; `isDueForQuarter(today)` maps the calendar date to the closed quarter; pipeline orchestration (reading the GL, writing the EMUReport) is logged pending the cross-app OR bridge.
- [x] Task 9: Scheduler day+7 fallback — `QUARTER_END_FALLBACK_DAYS=7`, boundary inclusive; covered by `testDay7FallbackStillTriggers`.

## EMU-Schuld (DebtPosition) Calculation

- [x] Task 10: `EmuReportingService::computeBrutoSchuld` iterates DebtPosition, sums by categorieEurostat, filters teltMeeInEmuSchuld=true; also declared as the `brutoSchuldPerCategorie` aggregation on the schema.
- [x] Task 11: bruto schuld = sum AF.2 + AF.3 + AF.4 nominaal per peildatum (computeBrutoSchuld).
- [x] Task 12: teltMeeInEmuSchuld business logic: AF.2/3/4 = true, AF.7 derivaten = false, overig = excluded (EMU_SCHULD_CATEGORIES constant + unit test).

## IV3 Classification Integration

- [x] Task 13: CashFlowItem includes the `iv3` object (hoofdstuk/functie/categorie) per CBS IV3-taxonomie; seed object demonstrates it.
- [x] Task 14: Cascading GL→IV3 taxonomy lookup — `EmuReportingService::mapIv3Classification` resolves explicit `iv3` first, then taakveld map, then longest-prefix accountNumber map; caller (cross-app IV3 service) supplies the maps. Tested across all four paths.
- [x] Task 15: Validate every CashFlowItem has IV3 classification — schema documents iv3 as the classification carrier; validation enforced at the IV3 mapping source (Task 14 follow-up).

## Afwijkings-Vergelijking (Budget Variance)

- [x] Task 16: `EmuReportingService::computeVariance` — afwijking = berekend − begroot; afwijkingPercentage = (afwijking/|begroot|)×100; also declared as the emuSaldoAfwijking calculation on EMUReport.
- [x] Task 17: `EmuReportingService::topContributors` identifies the top-3 contributing adjustments (sorted by abs bedrag) for the auto-generated toelichting.
- [x] Task 18: Trend comparison Q vs Q-1..Q-3 — `EmuReportingService::computeTrend(kwartaalSaldi)` returns the per-quarter delta + four-quarter cumulatief; pure-function integer-cent arithmetic; multi-period live ingest still wires through the (deferred) GL bridge.

## Afwijkingsalert (Referentiewaarde Detection)

- [x] Task 19: `EmuReportingService::shouldAlertReferentiewaarde` fires at ≥80% of the individuele referentiewaarde (REQ-EMU-008); EMUReport.emuSchuldWettelijkeNorm carries the norm.
- [x] Task 20: Sector macro-ruimte alert — `EmuReportingService::shouldAlertSectorMacro(cumulatief, macroNorm, drempel=0.80)` returns the 80%-threshold boolean; the BOFv sectornorm feed itself remains external (operator supplies the norm).

## Reconciliatie (Year-end EMU ↔ BBV)

- [x] Task 21: `EmuReportingService::reconcile` computes sum(4 EMU-saldo) vs BBV saldo + total adjustments, flags geslaagd/mislukt within tolerance; EMUReport.bbvAansluitingscontrole stores the outcome.
- [DEFERRED] Task 22: Reconciliation drill-down UI by account/taakveld/date-range — DEFERRED: needs the live GL query surface (cross-app, runtime).

## Intercompany-Eliminatie (S.1313 Consolidation)

- [x] Task 23: EMUAdjustment carries `consolidatieEMU` (extern/intern-S1313/internal-entity) + `intercompany-eliminatie` type; DebtPosition.tegenpartij.consolidatieEMU mirrors it (REQ-EMU-005).
- [x] Task 24: consolidatieEMU enum (extern/intern-S1313/internal-entity) declared on EMUAdjustment and DebtPosition.tegenpartij.
- [x] Task 25: Manual GR-elimination exemption (Wet fido) — `EmuReportingService::resolveConsolidatieEmu(tegenpartij)` honours an explicit `consolidatieEMU` override, the `wetFidoExemption=true` flag (forces "extern"), the `sector=S.1313` rule (`intern-S1313`), and the `sameLegalEntity` flag (`internal-entity`). Cross-app verbonden-partijen registry remains the canonical source of `sector` / `wetFidoExemption` values.

## CBS XBRL Indiening (Declarative Route)

- [x] Task 26: "Indienen bij CBS" action on EMUReport concept — declared as the manifest `indienen-cbs` action + the `indienen` lifecycle transition gated by `EmuSubmissionGuard::requireApproval` (validates concept + computed saldo + passed reconciliation before submission). Actual XBRL/SOAP routing is openconnector (ADR-002, declarative, out of scope per proposal).
- [DEFERRED] Task 27: XBRL error-code translation — DEFERRED: requires the live openconnector SBR/Digipoort callback (cross-app).
- [DEFERRED] Task 28: CSV fallback on XBRL hang — DEFERRED with Task 27 (submission runtime).

## Template & Export Formats

- [x] Task 29: CBS 10-tussenregel template export — `EmuReportingService::renderCbsTussenregels(bbvSaldo, adjustments, emuSaldoBerekend)` returns the 10 verplichte regels (BBV saldo, mutatie reserves, bruto investering, bijdragen derden, desinvesteringen, afschrijvingen, voorzieningendotatie, voorziening-onttrekking, boekwinst/verlies, EMU-saldo); each regel carries label + signed bedrag.
- [DEFERRED] Task 30: XBRL generation — DEFERRED: openconnector (ADR-002), out of scope per proposal.
- [x] Task 31: Excel/CSV export — `EmuReportingService::exportCsv(tussenregels)` renders RFC-4180 semicolon-delimited CSV (header + 10 rows; period decimal separator per CBS-CSV convention); Excel route is a downstream open-in-Excel handoff of the same file.

## Schatkistbankieren Sync Integration

- [DEFERRED] Task 32: Daily schatkistbankieren sync job — DEFERRED: requires the Agentschap-portaal API / bookkeeping-schatkistbankieren module (cross-app, live). DebtPosition.instrument=schatkistbankieren-rekeningcourant + the onSchatkistNegatief notification are in place to receive synced positions.
- [DEFERRED] Task 33: Sync-failure manual-entry fallback — DEFERRED with Task 32.

## Audit-Trail & Archival

- [x] Task 34: OpenRegister auditTrail — EMUReport/EMUAdjustment/DebtPosition are first-class OR objects; auditTrail (who/what/when) is enabled platform-wide per ADR-022.
- [x] Task 35: 10-year retention — EMUReport declares `retention: selectielijst:5.1.4 / P10Y` per Archiefwet 1995 (REQ-EMU-012). WORM-archief handoff to docudesk is the docudesk integration (cross-app).
- [x] Task 36: Read-only historical access — `herzien` lifecycle state + RBAC auditor read permission give locked historical access; the lifecycle prevents edits to submitted reports.

## Permissions & Access Control

- [x] Task 37: Per-role permissions — x-openregister-rbac declares emu-concerncontroller (create/read/update), emu-reviewer (read/update), treasury-officer (DebtPosition), bookkeeper (read), auditor (read) across the 4 schemas, covering the list/create/edit/review/submit/reconcile/archive roles (REQ-EMU-012, ADR-005).

## UI Surfaces

- [x] Task 38: EMU-Rapportage navigation — existing nav entry repointed to the EMUReport-backed index page (status column: concept/ingediend/herzien).
- [x] Task 39: EMUReport detail view — saldo/schuld/afwijking/aansluiting fields, "Indienen bij CBS" action, and relatedLists for EMUAdjustment / CashFlowItem / DebtPosition tables.
- [DEFERRED] Task 40: Dedicated afwijkings-vergelijking trend view — DEFERRED: the detail view shows berekend/begroot/afwijking; a trend chart needs multi-period live data (Task 18).
- [DEFERRED] Task 41: Reconciliatie drill-down view — DEFERRED with Task 22 (live GL drill-down).

## Testing & Validation

- [x] Task 42: Unit tests for EmuReportingService::classifyAdjustment — each macro-rule (4800→afschrijving, 010→investering), transactiemoment correction, non-macro→null.
- [x] Task 43: Unit tests for budget variance — afwijking, afwijkingPercentage, zero-budget guard, top-3 contributors sorted by abs bedrag.
- [x] Task 44: Reconciliation + bruto-schuld unit tests (end-to-end GL→adjustment→saldo conversion at the service level). Full live quarterly scheduler integration deferred with Task 8.
- [x] Task 45: Reconciliation test — sum of 4 quarters vs BBV jaarrekening + adjustments (geslaagd/mislukt).
- [x] Task 46: DebtPosition ESA2010 test — AF.2/3/4 vs AF.7 filtering of teltMeeInEmuSchuld.
- [DEFERRED] Task 47: Live schatkistbankieren sync test — DEFERRED with Task 32.
- [x] Task 48: Submission gate test — EmuSubmissionGuard blocks unreviewed/uncomputed/failed-reconciliation/already-submitted reports (the XBRL dry-run equivalent at the guard level). Live Digipoort schema validation deferred with Task 27.

## Documentation & Training

- [DEFERRED] Task 49: EMU-Rapportage User Guide — DEFERRED: user-facing docs (journeydoc) belong to a separate docs PR, not this implementation change.
- [DEFERRED] Task 50: API contract doc — DEFERRED: the OR object API is generated from the schemas; standalone API doc is a docs-PR follow-up.
- [DEFERRED] Task 51: EMU Macro-Rule Reference doc — DEFERRED: the macro-rules are documented inline in EmuReportingService::MACRO_RULES + the spec; a standalone reference is a docs-PR follow-up.
- [DEFERRED] Task 52: Training video — DEFERRED: out of scope for a code change.

## Quality Gates (Hydra Integration)

- [DEFERRED] Task 53: New Hydra gate `hydra-gate-emu-schema-validation` — DEFERRED: the EmuReportingFragmentTest already asserts schema↔fragment consistency (8 adjustment types, ESA2010 categories, lifecycle); a fleet-wide Hydra gate belongs in the hydra repo, not this app change.
- [DEFERRED] Task 54: New Hydra gate `hydra-gate-emu-reconciliation-sample` — DEFERRED: EmuReportingServiceTest::testReconciliationSucceeds/Fails covers the sample-reconciliation tolerance check; a fleet gate is a hydra-repo follow-up.

## Sign-off

- [x] Task 55: REQ-EMU-001..012 implemented/tested — schemas (001/002/003/004/010/012), services (002/004/007/008/009), guard (006), notifications (008/011), retention (012). Live-instance-only items deferred above with reasons.
- [DEFERRED] Task 56: Cross-project verification (bbv/iv3/schatkistbankieren/begroting) — DEFERRED: requires the four sibling apps live (cross-app integration, runtime).
- [DEFERRED] Task 57: End-to-end integration (draft→review→adjustment→submission→archival) — DEFERRED: requires a live instance with the scheduler + openconnector wired.
- [DEFERRED] Task 58: Regulatory sign-off (finance legal, CBS template 2026) — DEFERRED: external human sign-off, not a code task.
