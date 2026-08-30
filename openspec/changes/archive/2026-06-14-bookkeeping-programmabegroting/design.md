# Design — Programmabegroting & Meerjarenraming

## Context

The programmabegroting is the master financial and political authorisation document under BBV. Every 
Dutch gemeente, provincie, and waterschap must adopt one annually. Shillinq must operationalise the 
complete begrotingsproces (opstellen, behandelen, vaststellen, wijzigen) and enforce the sluitend-criterium 
(structural and real balance) that the provinciale toezichthouder uses to determine repressief vs. 
preventief toezicht.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra 
pipeline; this doc explains *why* the shape is what it is.

## Goals

- **Express the entire programmabegroting surface as declarative metadata** — registers + lifecycle + 
  aggregations + manifest entries — per ADR-031.
- **Enforce sluitend-criterium automatically** — both struktureel and reëel balance computed from 
  meerjarenraming, with flags set on Programmabegroting for raad visibility.
- **Make the spec a competent-bookkeeper readable contract** — Dutch BBV/WTO process recognisable 
  end-to-end (draft → behandeling → vaststelling → wijzigingen → exports).
- **Decouple programma (locally-chosen) from taakveld (BBV-mandated)** — parallel views over same 
  data; no rounding drift between political and technical views.
- **Event-source begrotingswijzigingen** — immutable vastgestelde basis + Σ(vastgestelde wijzigingen); 
  audit trail intact across reversals.
- **Integrate with BBV compliance + forecast** — taakveldcatalogus from `bookkeeping-bbv-compliance`, 
  cijfers from `bookkeeping-budget-forecast`.
- **Produce machine-readable exports** — iv3 for CBS (taakveld-aggregated), EMU-saldo for Wet Hof, 
  JSON for OpenCatalogi.

## Non-Goals

- No UBL 2.1 outbound e-invoicing for begroting documents.
- No real-time sluitend-criterium updates (batch evaluation on lifecycle transitions).
- No multi-language paragraaf support (Dutch-only in T2).
- No stress-testing or macro-economic scenario modelling (BI tools).
- No revenue forecasting automation (forecast supplied externally).

## Design Decisions

### D1 — Programmabegroting and Taakveld are parallel views over same data

**The canonical view is Taakveld.** Each Programma contains one or more Taakvelden; the Taakveld 
carries the baten/lasten numbers. The Programma's aggregated baten/lastenTotaal are automatically 
computed as Σ(child Taakvelde.baten) and Σ(child Taakvelde.lasten) respectively.

This design eliminates rounding drift between the political view (Programma, locally chosen) and the 
technical BBV view (Taakveld, mandated for comparability). The iv3-aanlevering to CBS uses Taakveld 
aggregates, exactly matching what the raad has adopted.

### D2 — Sluitend-criterium is evaluated as two independent flags: struktureel and reëel

The provinciale toezichthouder distinguishes:
- **Struktureel:** Recurring lasten (lastenStructureel) ≤ recurring baten (batenStructureel) for every 
  year in the meerjarenraming.
- **Reëel:** Saldocorrected for nominale-ontwikkeling (loon- en prijsindexatie); saldoReëel ≥ 0.

Both flags are independently computed and persisted on Programmabegroting. This allows the raad to see 
which constraint binds. The system MUST set flags during lifecycle transitions (on `in-behandeling → 
vastgesteld`).

### D3 — Begrotingswijzigingen are event-sourced deltas, not full-document overwrites

Once a Programmabegroting is vastgesteld, it is **immutable.** Every wijziging is an independent 
Begrotingswijziging document with:
- wijzigingsnummer (sequential per begroting).
- mutaties (delta: per-programma per-taakveld {baten_delta, lasten_delta}).
- raadsbesluit (required FK before status → vastgesteld).
- vaststellingsDatum and effectiefVanaf.

The current stand of the begroting is always: `vastgestelde basis + Σ(vastgestelde wijzigingen)`. 
The audit trail remains intact even when wijzigingen are reversed (terugdraaiing is itself a wijziging 
with negative delta).

### D4 — Paragrafen are structured records, not free-text documents

The seven verplichte paragrafen (lokale heffingen, weerstandsvermogen, onderhoud kapitaalgoederen, 
financiering, bedrijfsvoering, verbonden partijen, grondbeleid) are declared as Paragraaf records 
with:
- type (enum, one of the seven).
- narrative (rich-text, required on vastgesteld).
- kerncijfers (structured numeric fields per paragraaftype — e.g., weerstandsvermogen has ratios).

This prevents drift between paragraaf-tekst and operational reality (e.g., weerstandsvermogen ratio 
calculated from risicoregister; financiering paragraaf fed by treasury data).

### D5 — Toezichtregime is determined by combining sluitend-flags and 4-year history

The system computes toezichtRegime (repressief/preventief/artikel-12) based on:
1. Sluitend-flags (struktureel and reëel) in the meerjarenraming.
2. Resultaat of the preceding 4 years (no sustained tekorten without dekkingsplan).
3. Weerstandsvermogen ratio (algemene reserve / totale lasten ≥ 1.0 per IPO beoordelingskader).

Only full conformity across all four dimensions permits repressief toezicht. The system emits an event 
when the regime would shift from repressief to preventief, allowing proactive toezichthouder engagement.

### D6 — Forecast figures are consumed from bookkeeping-budget-forecast, not computed here

The meerjarenraming is seeded from cijfers supplied by `bookkeeping-budget-forecast` (prognosticijfers 
voor T+1..T+4). Shillinq reads the forecast table, not computes revenue projections.

### D7 — Nominale-ontwikkeling is user-configured annually per administration

The loon- en prijsindexatie (nominale-ontwikkeling) used to correct reëel-sluitend is **not** derived 
from an external macro-economic service. Instead, each administration enters the figure annually 
(typically the year before begroting vaststelling). This allows local customization (some administraties 
may use different assumptions than the national average).

## Reuse Analysis

| Capability Needed | What Already Exists | Reuse Strategy |
|---|---|---|
| Programmabegroting lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on Programmabegroting: `draft → in-behandeling → vastgesteld` with raadsbesluit guard and sluitend-evaluation action |
| Sluitend-criterium evaluation | OR `x-openregister-aggregations` | Aggregations predicate: `SUM(lastenStructureel) ≤ SUM(batenStructureel)` and `SUM(saldoReëel) ≥ 0` per year |
| Scheduled evaluation (sluitend recalc) | OR `ScheduledWorkflow` (if stable) | Recurring task walks meerjarenraming, re-evaluates sluitend-flags (e.g., nightly during behandeling phase) |
| Taakveld lookup | T2 `bookkeeping-bbv-compliance` | BBVTaakveldCatalogus consumed; Taakveld.taakveldCode must match catalogue |
| Budget forecast integration | T2 `bookkeeping-budget-forecast` | Meerjarenraming.batenStructureel seeded from forecast; operator may override |
| Greatbookposten budget overrun | T2 `bookkeeping-general-ledger` | GL posting phase validates: does this posting exceed authorized lasten per programma (considering vastgestelde wijzigingen)? |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on all Programmabegroting + Begrotingswijziging lifecycle transitions |
| Manifest navigation | T1 manifest pattern | 3+ entries (Budget, Programma, Meerjarenraming, Paragrafen) + their pages |
| iv3 export to CBS | T4 / OpenConnector | Design assumes an OpenConnector source with iv3 koppelvlak; details resolved in T2 discovery |
| EMU-saldo computation | T2 / Wet Hof definitions | EMU-saldo = Σ(baten) - Σ(lasten) with investerings/reserve/voorziening corrections per SNA-2010 (spec defines fields; implementation cycle validates formula) |

**Net new code in implementation cycle:** 9 register declarations + 1 lifecycle block + 3 aggregations 
+ 3 manifest entry pairs. No PHP service (all declarative per ADR-031).

## Declarative-vs-Imperative Decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Programmabegroting lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Sluitend-criterium evaluation | Declarative (`x-openregister-aggregations` predicates) | Pure SUM/aggregate computation |
| Toezichtregime determination | Lifecycle action (aggregations query + config lookup) | Query-based; no service |
| Scheduled sluitend recalc | OR `ScheduledWorkflow` (if available) or T2 background task | Resolution in opsx-ff discovery |
| Paragraaf validation (required on vastgesteld) | Lifecycle precondition | Guard checking all 7 paragrafen have non-empty narrative |
| Begrotingswijziging delta application | Declarative via lifecycle action | Pure addition of delta to vastgestelde basis |
| Budget-overrun detection | GL posting precondition | Validation during `JournalEntry` materialisation |
| iv3 export | T4 / OpenConnector (shape-neutral) | Spec defines data shape; export mechanism separate |

**No service class authored in this envelope.** All behaviour is declarative per ADR-031.

## Seed Data

**Programmabegroting:** No seed data. The budget is operator-authored annually per administration.

**Paragraaf:** A template set of 7 empty Paragraaf records is created on first Programmabegroting draft 
(one per mandated type). Narrative fields are blank; operator fills in per vaststelling requirement.

**Example Programma seed (Dutch municipal example, for illustrative purposes only):**
```json
{
  "nummer": "1",
  "naam": "Veiligheid & Handhaving",
  "portefeuillehouder": "Wethouder Veiligheid",
  "doelstellingen": "Handhaving van openbare orde en veiligheid in de gemeente",
  "batenTotaal": 250000,
  "lastenTotaal": 2500000,
  "saldoVoorMutaties": -2250000,
  "mutatiesReserves": 0,
  "saldoNaMutaties": -2250000
}
```

**Example Taakveld seed (per BBV taakveldcatalogus, e.g. 6.71 = Brandweer):**
```json
{
  "programmaId": "1",
  "taakveldCode": "6.71",
  "taakveldNaam": "Brandweerzorg",
  "baten": 50000,
  "lasten": 450000
}
```

These are **illustrative only.** Actual seeding depends on administration data supplied during 
begroting-opstellen phase.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Taakveldcatalogus not yet available | Spec assumes FK link; `bookkeeping-bbv-compliance` integration confirmed in opsx-ff discovery. Fallback: allow user-entry of taakveldCode (validated post-fetch when catalogue available). |
| Forecast figures missing or unreliable | Meerjarenraming seeded from forecast but operator can override. Unreliability is an operational (policy) problem, not a system defect. |
| Nominale-ontwikkeling not set (reëel-sluitend can't compute) | Configuration form requires entry before begroting is moved to in-behandeling. Default to 2% per IPO guideline if unset. |
| Sluitend-criterium interpretation differs between toezichthouders | Spec references Commissie BBV notities + IPO beoordelingskader; ambiguities resolved per regional guidance (province-specific). |
| Paragraaf narrative becomes stale between versions | Audit trail captures history. Stale content is an operational (raad review) problem. System enforces non-empty at vaststelling but doesn't police updates post-adoption. |
| Event-sourcing wijzigingen creates audit complexity | Trade-off accepted: audit trail is the primary goal. Wijziging reversal-traceability is more valuable than simplified storage. |
| ScheduledWorkflow not available (sluitend recalc) | Fallback: manual re-evaluation via operator action during behandeling phase. Cost: toezichthouder alignment requires explicit operator step. |
| IV3 export not yet integrated (CBS aanlevering blocked) | Design is OpenConnector-neutral (spec defines data shape). Implementation cycle confirms CBS koppelvlak + integrates OpenConnector source as needed. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with nine schemas (additive — no existing schema 
   changes).
2. `src/manifest.json` is patched with 3+ new menu entries + their pages (additive).
3. A configuration form is added (or a configuration UI) for entering nominale-ontwikkeling annually.
4. No data migration required; first Programmabegroting is operator-created on first draft.

Down-direction: registers are non-destructive — reverting removes the register declarations with zero 
data loss (assuming no vastgestelde begrotingen exist). If production data exists, a migration script 
backs up the Programmabegroting + Begrotingswijziging tables before reverting.

## Open Implementation Notes

1. **ScheduledWorkflow vs. background task:** Discovery (opsx-ff) must confirm whether OR's 
   `ScheduledWorkflow` is available and stable enough for sluitend-criterium recalculation. Fallback 
   is a T2 background task (e.g., Nextcloud background job) that runs nightly during behandeling phase.

2. **CBS iv3-koppelvlak:** The spec assumes an OpenConnector integration for iv3 export. Discovery must 
   confirm the CBS API is accessible and document the integration point.

3. **Toezichthouder export mechanism:** Different provinces may require different export formats 
   (XML vs. JSON vs. API feed). Design is format-neutral; implementation cycle confirms per 
   organisationType.

4. **Paragraaf kerncijfers per type:** The spec declares structured fields (e.g., 
   weerstandsvermogen.ratio, financiering.kasstroomPositief). Implementation cycle must detail the 
   exact field set for each of the seven paragraaftypen per Commissie BBV guidance.

5. **Toezichtregime thresholds:** The spec names the beoordelingskader (IPO voor gemeenten, BZK voor 
   provincies/waterschappen) as the source. Implementation cycle must extract exact numeric thresholds 
   (e.g., weerstandsvermogen ratio ≥ 1.0, resultaat history ≥ 4 years without tekort) and code them.
