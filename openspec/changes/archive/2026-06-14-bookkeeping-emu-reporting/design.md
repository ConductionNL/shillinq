# Design — EMU-saldo & EMU-schuld Reporting

## Context

Nederlandse decentrale overheden (gemeenten, provincies, waterschappen, gemeenschappelijke regelingen) zijn onder Wet Houdbare Overheidsfinanciën verplicht periodiek hun EMU-saldo (kassaldo: kasbasis) en EMU-schuld (bruto schuld) aan het CBS te rapporteren. Het CBS aggregeert deze rapportages voor Notificatie EDP richting Eurostat (toetsing Europese Stabiliteits- en Groeipact-normen: 3% BBP tekort, 60% BBP schuld).

Huidige situatie: Financial beleidsmedewerker doet dit handmatig via spreadsheet — export BBV-grootboek, pas macro-regels toe voor accrual→kas conversie (per Wet Hof art. 3: afschrijvingen eruit, investeringen erin, etc.), reconcilieer met begroting en vorig jaar, vul CBS-enquête handmatig in. Foutgevoelig. Geen audit-trail. Lastig voor accountant.

Per ADR-000 data model zijn Account, GLLine, Administration al gestandaardiseerd. Zie context-brief voor volledige Wet Hof, CBS-instructie, ESA2010 context.

## Goals

- Express EMU reporting as **automated pipeline** (BBV-grootboek → draft EMU-saldo → user review → signed indiening CBS).
- Lock in **Wet Hof art. 3 adjustment rules** as declarative macros + transaction-level overrides.
- Support **shared IV3 taxonomie** with IV3-reporting; one classification, two outputs (IV3 + EMU).
- Enable **reconciliation** between annual EMU (sum of 4 quarters) and BBV jaarrekening; audit-ready.
- Compute **bruto schuld** per ESA2010 (AF.2/3/4 instruments, nominaal waarde).
- Alert on **referentiewaarde overschrijding** and **macro-sectornorm risk**.
- Support **intercompany-eliminatie** for consolidated reporting (gemeenschappelijke regelingen S.1313).
- Design for **regulatory auditability**: every EMU-aangifte locked in docudesk archief, CBS-bevestiging stored, adjustment audit-trail complete.

## Non-Goals

- No WYSIWYG template designer for adjustment rules.
- No multi-entity consolidation visualization UI (roadmap).
- No predictive/what-if EMU modeling (roadmap).
- No SMS/multi-channel notifications; email alerts only.
- No SBR/Digipoort connector code (this spec is declarative route only).

## Decisions

### D1 — Four entities, not monolithic

`EMUReport` (aangifte per periode) | `EMUAdjustment` (individual conversie) | `CashFlowItem` (kaasstroom IV3-geclassificeerd) | `DebtPosition` (schuld per instrument per peildatum)

Separated so:
- EMUReport is the atomic versioned-submission unit.
- Adjustments are traceable and auditable row-by-row.
- CashFlowItem reuses IV3 classification (shared taxonomy).
- DebtPosition is independent (may be sourced from schatkistbankieren or manual).

**Alternative considered**: Single `EMUAangifte` with nested arrays. Rejected — audit-trail and per-item reconciliatie require separate entities.

### D2 — Macro-rules + transaction-level overrides

Wet Hof art. 3 defines categories (afschrijving, voorzieningendotatie, bruto-investering, etc.). System auto-applies macro-rules (e.g., "eliminatie-afschrijving: alle account 48xx bedragen tellen saldo-verhogend"). User can override per-transaction in EMUAdjustment.

**Alternative considered**: Purely manual adjustment per transaction. Rejected — gemeenten with 1000s of GL lines would drown; macro-rules are standard per Wet Hof.

### D3 — IV3-classificatie as shared taxonomy

`CashFlowItem` uses `iv3.hoofdstuk`, `iv3.functie`, `iv3.categorie` (same as IV3-rapportage). One classification flow, two reports: IV3 uses accrual-basis items too, EMU uses kas-basis only (same schema, different filter).

**Alternative considered**: Separate EMU-classification system. Rejected — CBS explicitly mandates IV3 taxonomy for reconciliation between IV3 and EMU.

### D4 — Quarterly auto-draft + review gate before submission

Scheduler runs 5 working days after quarter-end (06:00) → generates EMUReport with status=concept. Concerncontroller reviews, adjusts if needed, approves. Then signed submission to CBS.

**Alternative considered**: Manual trigger on-demand. Rejected — Wet Hof deadline is fixed; early draft gives time for review.

### D5 — Reconciliation algorithm: sum of 4 quarters must equal BBV-saldo + adjustments

At year-end, system computes sum of 4 quarterly EMU-saldo values. Compares to BBV jaarrekening saldo baten/lasten ± all adjustments for the year. If mismatch > 0, flag as "unreconciled" with underzoekstaak. Accountant drills down to GL date range that explains discrepancy.

**Alternative considered**: Separate annual EMU-schuld aangifte (standalone). Rejected — Wet Hof art. 3 defines EMU-saldo jaarlijks as sum of quarters; jaarlijkse EMU-schuld is a separate report but linked.

### D6 — Bruto schuld per ESA2010 (AF.2/3/4, nominaal)

DebtPosition records: vaste-geldlening, obligatie, schatkistbankieren (negatief saldo = AF.2 deposito-passief), crediteurensaldo > 1 jaar, derivaten-passief (separate, telt NIET in bruto schuld per art. 4 Wet Hof). System sums AF.2+AF.3+AF.4 nominale bedragen per peildatum.

**Alternative considered**: Market-value debt (zoals derivaten swap). Rejected — Eurostat ESA2010 mandates nominaal; market value only for specific risk disclosure.

### D7 — Intercompany-eliminatie for S.1313 consolidation

When GR (gemeenschappelijke regeling) is in sector S.1313 (lokale overheid), system flags intercompany transactions and on consolidation-group aggregation, applies elimination: gemeente betaalt EUR X bijdrage aan VR → GR saldo-verhogend (bijdrage "weg"), gemeente saldo-verlagend (betaling "weg"), netto = 0 op geconsolideerde niveau.

Tegenpartij carries consolidatieEMU flag ("intern-S1313"). Manual override possible if specific exemption (Wet fido article or special arrangement).

**Alternative considered**: No elimination; report per entity only. Rejected — Wet Hof art. 3 defines geconsolideerde rapportage verplicht; CBS requires consolidated sums.

### D8 — CBS XBRL indiening via declarative openconnector route

EMUReport.status = "ingediend" requires digitally signed XBRL submission via SBR/Digipoort. This spec declares the submission surface (action: "Indienen bij CBS", requires PKIoverheid certificaat). Actual XBRL gen + SOAP routing lives in openconnector project (ADR-002).

**Alternative considered**: Baked-in XBRL service in Shillinq. Rejected — per ADR-002, all external integrations route through openconnector.

## Reuse Analysis

| Capability needed | What exists | Reuse strategy |
|---|---|---|
| General ledger, accounts | Account, GLLine (ADR-000) | Macro-rules query GL by account (48xx = afschrijving) |
| Budget/meerjarenraming | Budget, BudgetAllocation (ADR-000) | Fetch begroot-saldo for kwartaal-EMU vergelijking |
| IV3 classification | CashFlowItem (bookkeeping-iv3-reporting) | Reuse same entity; EMU filters kas-basis items only |
| Schatkistbankieren data | bookkeeping-schatkistbankieren (sync) | Daily import of saldo; creates DebtPosition records |
| GR/related-party data | bookkeeping-verbonden-partijen (registratie) | Lookup GR sector (S.1313) for intercompany rules |
| XBRL/Digipoort | openconnector (future) | Declarative route; spec does not implement |
| Archival/WORM | docudesk (future) | CBS-bevestiging + EMU reports stored post-indiening |

**Net new code in implementation cycle**: EMUReportingService (orchestration), EMUAdjustmentCalculator (Wet Hof rules), reconciliatie algorithm, quarterly scheduler, UI surfaces for review + submission. ~1500 lines PHP + 800 lines Vue.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Macro-rule application (eliminatie afschrijving, etc.) | Imperative (PHP service) | Complex Wet Hof logic; rule conditions vary per rule type |
| Quarterly draft generation | Imperative (scheduler + service) | Time-based trigger + state machine |
| Reconciliatie berekening | Imperative (PHP) | Complex sum + variance logic |
| IV3-classificatie lookup | Consumed from CashFlowItem | Pure data lookup |
| CBS XBRL route | Declarative (action in manifest) | External system call via openconnector |

Service class authored: EMUReportingService, EMUAdjustmentCalculator, ReconcialiationEngine (3 classes, ~400 lines each).

## Seed Data

#### EMUReport (example — Q2 2026, Gemeente Voorbeeldam)

```json
{
  "id": "emu-2026-q2-gem-1742",
  "rapporterendeOrganisatie": {
    "rsin": "001234567",
    "gemeentecode": "1742",
    "naam": "Gemeente Voorbeeldam",
    "soort": "gemeente"
  },
  "periode": {
    "jaar": 2026,
    "kwartaal": 2,
    "type": "kwartaal-emu-saldo"
  },
  "status": "ingediend",
  "indieningsdatum": "2026-07-15T09:23:00+02:00",
  "cbsBevestigingsnummer": "CBS-EMU-2026Q2-001234567",
  "emuSaldo": {
    "berekend": -2300000,
    "begroot": -1800000,
    "afwijking": -500000,
    "afwijkingPercentage": -27.8,
    "valuta": "EUR"
  },
  "emuSchuldUltimo": {
    "bruto": 142500000,
    "wettelijkeNorm": 156000000,
    "ruimte": 13500000
  },
  "bbvAansluiting": {
    "saldoBatenLasten": 4200000,
    "totaleAdjustments": -6500000,
    "aansluitingscontrole": "geslaagd"
  },
  "toelichting": "Afwijking veroorzaakt door versnelde dotatie aan voorziening pensioen wethouders (+450K) en hogere investering MFA Centrum (+820K kas)."
}
```

#### EMUAdjustment (example — afschrijving gebouwen)

```json
{
  "id": "adj-2026-q2-0142",
  "reportId": "emu-2026-q2-gem-1742",
  "type": "eliminatie-afschrijving",
  "richting": "saldo-verhogend",
  "bedrag": 1240000,
  "bron": {
    "grootboekrekening": "4801000",
    "omschrijving": "Afschrijving gebouwen onderwijs",
    "taakveld": "4.2",
    "taakveldNaam": "Onderwijshuisvesting",
    "programma": "Onderwijs"
  },
  "regel": "Wet Hof art. 3 lid 2: afschrijvingen zijn geen kasuitgaven en worden geëlimineerd uit EMU-saldo",
  "toelichting": "Lineaire afschrijving brede school De Hoeksteen, boekwaarde EUR 24,8M, looptijd 40 jaar"
}
```

#### CashFlowItem (example — investering MFA Centrum)

```json
{
  "id": "cf-2026-q2-08745",
  "reportId": "emu-2026-q2-gem-1742",
  "datum": "2026-05-22",
  "bedrag": -820000,
  "iv3": {
    "hoofdstuk": "8",
    "hoofdstukNaam": "Volkshuisvesting, ruimtelijke ordening en stedelijke vernieuwing",
    "functie": "810",
    "functieNaam": "Ruimtelijke ordening",
    "categorie": "3.4.1",
    "categorieNaam": "Investeringen materiële vaste activa met economisch nut"
  },
  "taakveld": "8.1",
  "tegenrekening": {
    "soort": "leverancier",
    "naam": "BAM Infra Nederland B.V.",
    "factuurnummer": "F-2026-44218"
  },
  "kasOfTransactiebasis": "kas",
  "betaalmoment": "2026-05-22T14:11:00+02:00",
  "factuurmoment": "2026-04-30T00:00:00+02:00"
}
```

#### DebtPosition (example — BNG-lening)

```json
{
  "id": "debt-2026-q2-bng-0034",
  "reportId": "emu-2026-q2-gem-1742",
  "peildatum": "2026-06-30",
  "instrument": "vaste-geldlening",
  "tegenpartij": {
    "naam": "BNG Bank N.V.",
    "soort": "sector-S122-bank",
    "consolidatieEMU": "extern"
  },
  "hoofdsomOorspronkelijk": 25000000,
  "uitstaandeSchuld": 18750000,
  "rentevoet": 2.85,
  "rentevorm": "vast",
  "looptijdJaren": 20,
  "einddatum": "2034-12-31",
  "telt_mee_in_EMU_schuld": true,
  "categorie_eurostat": "AF.4-loans"
}
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Wet Hof rule interpretation differs per gemeente | Macro-rule configurable per org; concerncontroller override; audit-trail logged; accountant sign-off required |
| CBS template change mid-year | Version field on EMUReport; spec update TBD next year; dry-run validation before indiening |
| Consolidation GR data stale → wrong elimination | Weekly sync from verbonden-partijen; alert on GR status change mid-quarter; manual review gate |
| XBRL validation rejects aangifte mid-deadline | Dry-run validation before final indiening; fallback CSV export for emergency CBS submission |
| Afwijkingsdetectie misses fraud pattern | Top-3 adjustment contributor analysis + trend comparison prior 4 quarters; flagged to controller |
| Reconciliation mismatch difficult to trace | Drill-down to GL line date ranges; filter by account/taakveld; export detail for accountant |

## Migration Plan

Spec-only — no data migration in this change. When implementation lands:

1. `openspec/architecture/adr-000-data-model.md` is updated with 4 new entities (additive).
2. `lib/Settings/shillinq_register.json` declares 4 schemas (additive).
3. `src/manifest.json` adds EMU-rapportage navigation entry.
4. Quarterly scheduler job deployed (cron 06:00, day+5 after quarter-end).
5. Seed data: default Gemeente Voorbeeldam EMUReport Q1 2026 (test/demo).

Down-direction: all EMUReports remain in docudesk archief (WORM, immutable). Reverting removes scheduler and manifest entries; historical data intact.

## Open Questions

1. **Macro-rule thresholds** — e.g., is every GL line account 48xx an afschrijving, or only marked items? Resolved during design review with BBV-commissie.
2. **Concerncontroller authority scope** — can bulk-adjust via Excel import or only UI line-by-line? Resolved during UX design.
3. **Schatkistbankieren sync cadence** — daily or per quarter-end only? Resolved with treasury PM.
4. **Revision aangifte auto-trigger** — on any late GL entry, or manual user request only? Resolved during workflow design.
5. **Multi-entity consolidation** — supported in V1 or roadmap for T3? Resolved per product roadmap.
