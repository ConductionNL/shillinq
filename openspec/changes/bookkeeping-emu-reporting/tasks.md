# Tasks: EMU-saldo & EMU-schuld Reporting

Implementation checklist for the `bookkeeping-emu-reporting` capability.

## Data Model & Schema Registration

- [ ] Task 1: Add `EMUReport` schema to `openspec/architecture/adr-000-data-model.md` with all fields per spec; mark as primary spec `bookkeeping-emu-reporting`.
- [ ] Task 2: Add `EMUAdjustment` schema to ADR-000 with type enum (8 adjustment types per Wet Hof art. 3); include richting, bedrag, bron, regel.
- [ ] Task 3: Add `CashFlowItem` schema to ADR-000; reference as shared entity with `bookkeeping-iv3-reporting`; include IV3-taxonomie fields.
- [ ] Task 4: Add `DebtPosition` schema to ADR-000; include instrument enum (7 types), ESA2010 categorie_eurostat, telt_mee_in_EMU_schuld boolean.
- [ ] Task 5: Register 4 schemas in `lib/Settings/shillinq_register.json` with OpenRegister lifecycle and x-openregister-calculations for EMU-saldo rendering.

## Macro-Rule Engine

- [ ] Task 6: Implement `EMUAdjustmentCalculator` service class; apply macro-rules per Wet Hof art. 3:
  - Regel 1: Eliminatie afschrijving (alle GL account 48xx → saldo-verhogend)
  - Regel 2: Eliminatie voorzieningendotatie (GL account 460x → saldo-verhogend)
  - Regel 3: Eliminatie onttrekking reserve (GL 49xx, onttrekking → saldo-neutraal, betaling → adjustment)
  - Regel 4: Toevoeging bruto-investering (GL 010-020 nieuw aanschaf → saldo-verlagend)
  - Regel 5: Toevoeging aflossing (GL 210-230 schuld-aflossing → saldo-verlagend)
  - Regel 6: Eliminatie boekwinst desinvestering (GL 931-939 boekresultaat → saldo-verlagend/verhogend per richting)
  - Regel 7: Correctie transactiemoment (fact.datum ≠ pay.datum → separate adjustment)
  - Regel 8: Intercompany-eliminatie (GR S.1313 → consolidatie flag)
- [ ] Task 7: Implement rule override mechanism: concerncontroller can adjust bedrag per adjustment (audit-trail logged).

## Quarterly Draft Generation

- [ ] Task 8: Implement `EmquartQuarterlyScheduler` cron job: runs day+5 after quarter-end at 06:00 UTC.
  - Query BBV-grootboek for GL lines in quarter.
  - Auto-generate EMUAdjustment records per macro-rules.
  - Create CashFlowItem records from GL; map to IV3-taxonomie.
  - Calculate emuSaldo.berekend.
  - Create EMUReport with status="concept".
  - Send email notification to concerncontroller.
- [ ] Task 9: Implement fallback: if quarter-end GL not yet closed, scheduler runs on day+7; alerts operator.

## EMU-Schuld (DebtPosition) Calculation

- [ ] Task 10: Implement ESA2010 debt classification: iterate DebtPosition records, sum by categorie_eurostat (AF.2/3/4), filter telt_mee_in_EMU_schuld=true.
- [ ] Task 11: Calculate bruto schuld = sum AF.2 + AF.3 + AF.4 nominaal bedragen per peildatum.
- [ ] Task 12: Implement debtPosition.telt_mee_in_EMU_schuld business logic: AF.2/3/4 = true, AF.7 (derivaten) = false, overig = false per Eurostat ESA2010.

## IV3 Classification Integration

- [ ] Task 13: CashFlowItem MUST include iv3 object (hoofdstuk, functie, categorie) per CBS IV3-taxonomie 2026.
- [ ] Task 14: Implement cascading taxonomy: GL account → (via chart-of-accounts mapping) → IV3 hoofdstuk/functie/categorie.
- [ ] Task 15: Validate that every CashFlowItem has valid IV3 classification (no null/empty categorie).

## Afwijkings-Vergelijking (Budget Variance)

- [ ] Task 16: Implement budget variance calculation: fetch Budget record for organization, jaar, kwartaal.
  - Calculate afwijking = emuSaldo.berekend − begroot.
  - Calculate afwijkingPercentage = (afwijking / begroot) × 100.
- [ ] Task 17: Identify top-3 contributor EMUAdjustment records to afwijking; expose in EMUReport.toelichting (auto-generated).
- [ ] Task 18: Trend comparison: compare Q current vs Q-1, Q-2, Q-3 prior year; flag if 50%+ delta.

## Afwijkingsalert (Referentiewaarde Detection)

- [ ] Task 19: Implement alert logic when EMU-saldo cumulatief (Jan-Sep) reaches 80% of individuele EMU-referentiewaarde.
  - Fetch referentiewaarde from Budget.wettelijkeNorm (or via Wet Hof article 5 lookup).
  - Sum Q1 + Q2 + Q3 emuSaldo.berekend.
  - Alert on UI + email to concerncontroller if >= 80%.
  - Include Q4 prognose based on prior-year pattern + known investeringen/aflossingen.
- [ ] Task 20: Track sector macro-ruimte: alert if BOFv-announced sectornorm (e.g., 110% utilization) is published; flag to controller.

## Reconciliatie (Year-end EMU ↔ BBV)

- [ ] Task 21: Implement `ReconciliationEngine` service: at year-end, compute sum of 4 quarterly EMU-saldo values.
  - Query sum(emuSaldo.berekend) for Q1..Q4 same year.
  - Fetch BBV jaarrekening saldo baten/lasten.
  - Compute total adjustments = sum(EMUAdjustment.bedrag) for year.
  - Verify: (BBV saldo) + (total adjustments) = (sum of 4 EMU-saldo).
  - If mismatch: flag as "unreconciled"; log GL date range + account filter to investigate.
- [ ] Task 22: Reconciliation detail drill-down: allow accountant to filter GL by account/taakveld/date-range to trace discrepancy root cause.

## Intercompany-Eliminatie (S.1313 Consolidation)

- [ ] Task 23: For EMUReport marked as consolidation-group (GR), implement elimination rules:
  - Query all member organizations (gemeente, provincie, waterschap, other GRs) in sector S.1313.
  - For each intercompany transaction: identify counterparty consolidatie-flag.
  - If "intern-S1313": apply opposite adjustment on consolidation-group total.
- [ ] Task 24: Implement tegenpartij.consolidatieEMU enum: extern / intern-S1313 / internal-entity (same org, cross-fund).
- [ ] Task 25: Manual override: concerncontroller can exempt specific intercompany elimination if Wet fido exception applies.

## CBS XBRL Indiening (Declarative Route)

- [ ] Task 26: Implement "Indienen bij CBS" action on EMUReport with status="concept":
  - Validate XBRL schema before submission (dry-run).
  - Require PKIoverheid services-server certificaat selection.
  - Trigger openconnector route (ADR-002) via declarative action manifest.
  - Poll for CBS response (bevestigingsnummer).
  - Update status → "ingediend"; store cbsBevestigingsnummer.
- [ ] Task 27: Implement XBRL error handling: if CBS rejects (schema validation), translate error codes to Dutch; keep status="concept" for correction.
- [ ] Task 28: Implement fallback: if XBRL submission hangs, allow export to CSV for manual CBS submission; log escalation ticket.

## Template & Export Formats

- [ ] Task 29: Implement CBS-template export: EMU-saldo values mapped to 10 verplichte tussenregels per CBS-enquête 2026.
  - Regel 1: Saldo baten en lasten BBV
  - Regel 2: Mutatie reserves
  - Regel 3: Bruto investeringen MVA
  - Regel 4: Bijdragen van derden in investeringen
  - Regel 5: Desinvesteringen
  - Regel 6: Afschrijvingen (totaal geëlimineerd)
  - Regel 7: Dotaties voorzieningen
  - Regel 8: Onttrekkingen voorzieningen
  - Regel 9: Boekwinst/verlies desinvesteringen
  - Regel 10: EMU-saldo (final)
- [ ] Task 30: Implement XBRL generation: map EMUReport/EMUAdjustment/DebtPosition to CBS XML taxonomy (via openconnector service call or declarative template).
- [ ] Task 31: Implement Excel/CSV export: EMUReport summary + EMUAdjustment detail table + DebtPosition list for local archival/audit.

## Schatkistbankieren Sync Integration

- [ ] Task 32: Implement daily sync job (02:00 UTC) with Agentschap-portaal API (or bookkeeping-schatkistbankieren module):
  - Fetch schatkistbankieren saldo per ultimo vorige werkdag.
  - If saldo < 0 (rood): create/update DebtPosition with instrument="schatkistbankieren-rekeningcourant", telt_mee_in_EMU_schuld=true.
  - Alert if status change (positive → negative) mid-quarter.
- [ ] Task 33: Fallback: if sync fails, operator receives alert; can manually enter schatkistbankieren saldo; audit-trail logged.

## Audit-Trail & Archival

- [ ] Task 34: Enable OpenRegister auditTrail on EMUReport, EMUAdjustment, DebtPosition: track who/what/when per entity.
- [ ] Task 35: Implement WORM-archief handoff to docudesk: post-submission, mark EMUReport as "archived"; store signed XBRL + CBS-bevestiging in docudesk with retention policy (10 years per Archiefwet 1995).
- [ ] Task 36: Implement read-only historical access: accountant/auditor can view EMU-aangifte from prior years (locked, no edits).

## Permissions & Access Control

- [ ] Task 37: Define permissions per role:
  - `emu:report:list` — view all EMU-rapportages
  - `emu:report:create` — manual EMU-aangifte creation (normally scheduler)
  - `emu:report:edit` — edit concept EMUReport + adjustments
  - `emu:report:review` — approve concept → sign for submission
  - `emu:report:submit` — execute "Indienen bij CBS" action
  - `emu:report:reconcile` — run year-end reconciliatie analysis
  - `emu:report:archive` — view historical locked reports

## UI Surfaces

- [ ] Task 38: Implement EMU-Rapportage navigation menu: list of EMUReport records (organization-scoped) with status indicators (concept/ingediend/herzien).
- [ ] Task 39: Implement EMUReport detail view:
  - emuSaldo summary card (berekend, begroot, afwijking, afwijkingPercentage)
  - emuSchuldUltimo summary card (bruto, wettelijkeNorm, ruimte)
  - EMUAdjustment table (type, richting, bedrag, regel, toelichting); allow inline edit of toelichting, bedrag override.
  - CashFlowItem table (datum, bedrag, IV3-classificatie, tegenrekening).
  - DebtPosition table (instrument, tegenpartij, nominaal, ESA2010-categorie, AF-eligibility).
  - Action buttons: "Indienen bij CBS", "Reconciliatie-analyse", "Export XBRL", "Export Excel".
- [ ] Task 40: Implement afwijkings-vergelijking view: begroot vs berekend per kwartaal; trend chart; top-3 contributors.
- [ ] Task 41: Implement reconciliatie detail view (year-end): drill-down by GL account/taakveld/date-range; export unreconciled discrepancies for accountant.

## Testing & Validation

- [ ] Task 42: Unit tests for EMUAdjustmentCalculator:
  - Each macro-rule applies correctly (e.g., GL 4800 → eliminatie-afschrijving).
  - Rule override updates bedrag and logs audit-trail.
  - Correctie transactiemoment creates separate adjustment when fact.datum ≠ pay.datum.
- [ ] Task 43: Unit tests for budget variance:
  - afwijking = berekend − begroot correctly.
  - afwijkingPercentage formula correct.
  - Top-3 contributors identified (sorted by abs bedrag).
- [ ] Task 44: Integration test: quarterly scheduler generates concept-aangifte with correct emuSaldo.berekend (end-to-end GL → EMU conversion).
- [ ] Task 45: Integration test: year-end reconciliatie matches sum of 4 quarters to BBV jaarrekening.
- [ ] Task 46: Integration test: DebtPosition ESA2010 classification (AF.2/3/4 vs AF.7) correctly filters telt_mee_in_EMU_schuld.
- [ ] Task 47: Integration test: schatkistbankieren sync creates/updates DebtPosition; negative saldo marked as schuld.
- [ ] Task 48: Integration test: XBRL dry-run validation catches missing/invalid fields before CBS submission.

## Documentation & Training

- [ ] Task 49: Write "EMU-Rapportage User Guide":
  - Quarterly workflow (concept generation → review → submission).
  - Adjustment override workflow (when/how to adjust per-transaction).
  - Budget variance interpretation + top-3 contributors.
  - Reconciliatie process (year-end, drill-down guide).
  - XBRL submission checklist.
  - Schatkistbankieren sync behavior.
- [ ] Task 50: Document API contract:
  - POST /emu-reports (manual creation, requires user input).
  - GET /emu-reports/{id} (detail + embedded adjustments/cashflow/debt).
  - PATCH /emu-reports/{id} (edit concept, adjustments).
  - POST /emu-reports/{id}/reconciliation (run year-end analysis).
  - POST /emu-reports/{id}/submit (XBRL submission action).
- [ ] Task 51: Write "EMU Macro-Rule Reference" doc:
  - All 8 adjustment types per Wet Hof art. 3.
  - Account mapping (e.g., 4800 → eliminatie-afschrijving).
  - Ejemplos from common gemeente scenarios (investeringen MFA, pensioen-voorziening, aflossingen BNG).
- [ ] Task 52: Create training video (5-10 min): "How to Review & Submit EMU-Aangifte" for concerncontrollers.

## Quality Gates (Hydra Integration)

- [ ] Task 53: Implement Hydra quality gate `hydra-gate-emu-schema-validation`:
  - Pre-commit: validate EMUReport, EMUAdjustment, DebtPosition schemas match ADR-000.
  - Check: all adjustment types present in EMUAdjustmentCalculator service.
  - Check: IV3-taxonomie mapping up-to-date (vs bookkeeping-iv3-reporting).
  - Check: ESA2010 categorie_eurostat values match Eurostat standard.
- [ ] Task 54: Implement Hydra quality gate `hydra-gate-emu-reconciliation-sample`:
  - Generate sample EMUReport for fictional gemeente.
  - Run reconciliatie algorithm.
  - Verify sum(4 EMU-saldo) = BBV-saldo + total-adjustments ± tolerance (EUR 100K).
  - Fail if tolerance exceeded.

## Sign-off

- [ ] Task 55: Spec review sign-off: verify all REQ-EMU-001 through REQ-EMU-012 requirements implemented and tested.
- [ ] Task 56: Cross-project verification: bookkeeping-bbv (GL export), bookkeeping-iv3-reporting (taxonomie), bookkeeping-schatkistbankieren (sync), bookkeeping-begroting-meerjaren (budget lookup) all integrated.
- [ ] Task 57: Integration testing with EMU-rapportage end-to-end: draft generation → review → adjustment → submission → archival.
- [ ] Task 58: Regulatory sign-off: finance legal team confirms Wet Hof art. 3, 5 compliance; CBS-enquête template 2026 conformance.
