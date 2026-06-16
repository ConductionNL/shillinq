# Design — Commercial Consolidation (RJ 217 / IAS 27)

## Context

Commercial consolidation (RJ 217 voor Nederlandse MKB, IAS 27 / IFRS 10 voor
internationale groepen) combines per-entiteit GL's en financial statements
(balance sheet, P&L) into a single group-level view, eliminating internal
transactions, revaluing foreign currencies, adjusting for goodwill acquisitions,
and splitting results between moeder-aandeelhouders en minderheidsbelangen.

The consolidation cycle is annual (or quarterly/monthly in advanced scenarios):
1. Per-entiteit GJ wordt afgesloten (per Administration)
2. Per-entiteit balans + V&W gegenereerd (via bookkeeping-financial-statements)
3. Consolidatie-periode geopend voor groep (ConsolidationPeriod)
4. Pre-eliminatie aggregatie: alle entiteit-kolommen + totaal
5. Eliminatie-fase: intercompany transacties gematcht + gevalideerd
6. Accountant-review: per-eliminatie goed/afkeurd
7. Gesloten: snapshot immutabel gearchiveerd (7+ jaar)

Per ADR-031, het volledige consolidatie-algoritme is declaratief:
- Schemas voor groepsdefintie, entiteiten, relaties, eliminaties
- x-openregister-lifecycle en x-openregister-aggregations voor
  pre-eliminatie aggregatie en eliminatie-matching
- Geen PHP consolidatie-berekeningsservice

De verandering is **spec-only**. Implementatie landed via `opsx-apply` en
standaard Hydra pipeline.

## Goals

- Express consolidatie-accounting als **declaratieve metadata** — schemas +
  lifecycle + aggregation formulas — per ADR-031
- Maak spec **competent-CFO readable** — RJ 217 / IFRS 10 consolidatie-cyclus
  herkenbaar end-to-end (groepsdefintie, per-administratie input, pre-eliminatie
  aggregatie, eliminatie-matching, valuta-translatie, minderheidsbelang,
  goodwill, review, sluit)
- Dwing RJ 217 / IFRS 10 consolidatie-methodes + eliminatie-regels +
  valuta-translatie zonder PHP-berekeningsservice
- Ondersteun integraal/proportioneel/equity consolidatie-methodes per
  eigendomspercentage en mate van controle (RJ 217 §4–6 / IFRS 10 §2–B86)
- Houd volledige audit-trail per-eliminatie (wie/wanneer/waarom) met
  accountant-review goed/afkeuring

## Non-Goals

- Geen PHP consolidatie-berekeningsservice (ConsolidationCalculator.php,
  EliminationMatcher.php)
- Geen real-time consolidatie (maandelijks/kwartaalmaatschappij in T4 planning)
- Geen fiscale consolidatie (separate module)
- Geen segment-rapportage of geografische uitsplitsing (T4 planning)
- Geen intercompany netting (aparte spec)

## Decisions

### D1 — Tien registers: groep + entiteiten + relaties + periode + eliminaties + translatie + minderheid + goodwill + output

Consolidatie wordt gedecomponeerd in:
- **consolidation-group**: groepsdefintie (naam, moeder-administratie, rapportage-valuta,
  rapportage-grondslag RJ/IFRS, boekjaar-einde, consolidatie-methode-default)
- **group-entity**: entiteit in groep (groep-FK, administratie-FK,
  entiteit-type moeder/dochter/JV/geassocieerde, eigendomspercentage,
  stem-percentage, consolidatie-methode, eerste-consolidatie-datum,
  functionele-valuta)
- **intercompany-relation**: mapping van interne handelsrelaties (groep-FK,
  debiteur-entiteit-FK, crediteur-entiteit-FK, type sales/services/royalties/
  interest/dividend/loan, default-eliminatie-rekening, default-counterparty-rekening)
- **consolidation-period**: consolidatie-run voor specifieke periode (groep-FK,
  periode-start/eind, status open/eliminatie-fase/review/gesloten/gearchiveerd,
  executor-user-FK, timestamp, totaal-aantal-eliminaties, totaal-bedrag)
- **elimination-entry**: individuele eliminatie (periode-FK, type intercompany-sales/
  AR-AP/loan/dividend/margin-inventory/goodwill/minority-split, datum,
  omschrijving, regels-JSONB, bron-entiteiten, bron-transacties, auto-gegenereerd,
  accountant-review-status pending/approved/rejected)
- **translation-adjustment**: koerseverschillen (periode-FK, entiteit-FK,
  valuta-koppel USD-EUR, translatie-methode current-rate/gemiddelde/historisch,
  bedrag-in-functionele-valuta, bedrag-in-rapportage-valuta, CTA-component)
- **minority-interest**: minderheidsbelangen registratie (groep-FK, entiteit-FK,
  percentage-derden, openingssaldo-minderheid, aandeel-in-resultaat, dividend-
  aan-minderheid, eindsaldo-minderheid)
- **goodwill**: acquisitie-goodwill/badwill (groep-FK, dochter-entiteit-FK,
  acquisitie-datum, koopprijs, fair-value-netto-activa, goodwill-bedrag,
  afschrijvingsmethode RJ-lineair-10-20-jaar / IFRS-impairment, restwaarde,
  opgebouwde-afschrijvingen, impairment-correcties)
- **consolidated-balance**: output-object met geaggregeerde + geëlimineerde
  balans-totalen per rapportageregel (comparatief vorig jaar + footnote-refs)
- **consolidated-income-statement**: output-object met geconsolideerde V&W
  (comparatief vorig jaar + footnote-refs)

**Alternative considered**: Monolithisch consolidation-valuation register met
alle velden. Rejected — multi-period run + per-eliminatie audit-trail +
currency-translation + minority-interest-split vereisen first-class records
voor drill-down en accountant-bewijs.

### D2 — Consolidatie-methode: integraal (100%), proportioneel (JV <100%), equity (<50%)

Elke entiteit in de groep krijgt een consolidatie-methode:
- **Integraal** (default): 100%-dochter of controllerend belang (>50% eigendom +
  directe/indirecte controle per RJ 217 §3 / IFRS 10 §19). Alle balans- en V&W-
  posten opgenomen, daarna minderheidsbelang afgesplitst.
- **Proportioneel**: Joint venture (50% eigendom, gezamenlijke controle per RJ 217
  §6 / IFRS 11). Alleen 50%-aandeel in netto-positie opgenomen (geen
  balans-regelposten).
- **Equity**: Geassocieerde deelneming (<50% eigendom, material influence per RJ 217
  §7 / IFRS 28). Boekwaarde eigen vermogen + aandeel-in-resultaat opgenomen (geen
  balans-regelposten).

**Alternative considered**: Auto-detectie uit actuele aandeel-registers. Rejected
— consolidatie-methode is governance-besluit (kan afwijken van juridische
aandeel); expliciete keuze per groep-setup nodig.

### D3 — Intercompany-matching toleranties en exception-queue

Pre-eliminatie-fase scant per IntercompanyRelation (debiteur-entiteit,
crediteur-entiteit, transaction-type) voor matching. Default-toleranties:
- Absolute: €10 (rounding difference)
- Relatief: 0.5% (timing/exchange-rate difference)

Matches buiten-tolerantie gaan in exception-queue (ConsolidationPeriod.mismatches-
JSONB array) voor handmatige accountant-resolutie (override-reason, apply-ja/nee).

Geaccordeerde mismatches worden journaalposten (aparte elimination-entry met
`rounding-difference` type).

**Alternative considered**: Nul-tolerantie (strikte match). Rejected — praktijk
bevat altijd rounding/timing-verschillen; nul-tolerantie = 100% handmatig werk.

### D4 — Valuta-translatie: current-rate methode (RJ 122 / IAS 21)

Per RJ 122 (Nederlandse norm) en IAS 21 (IFRS):
- **Balansposten** (active/passief): vertaald tegen slotkoers (periodeeinde)
- **V&W-posten** (inkomsten/lasten): vertaald tegen gemiddelde koers (periode)
- **Eigen-vermogen**: historische koers (eerste-consolidatie-moment)

Saldoverschil (verschil tussen EV via balans-translatie vs via-V&W-doorrol)
geboekt als CTA (Cumulative Translation Adjustment) onder eigen vermogen.
CTA is **non-recycling**: bij desinvestering dochter wordt cumulatieve CTA
gerealiseerd naar V&W (overgenomen uit OCI).

**Alternative considered**: Temporele methode. Rejected — current-rate is RJ
222 / IAS 21 standaard voor buitenlandse tak (afhankelijk operatie);
temporeel voor buitenlandse maatschappij (integraal onderneming) — current-rate
geschikt voor groep-consolidatie.

### D5 — Goodwill-accounting: RJ vs IFRS afschrijving vs impairment

**RJ 217 (Nederlands)**: Goodwill afgeschreven lineair over max 20 jaar
(default 10 jaar, onderbouwing voor >10 jaar nodig).
**IFRS 3/10**: Goodwill niet afgeschreven; jaarlijks impairment-test
(recoverable-amount bepaling per CGU, write-down naar VIU of FVLCS).

Groep selecteert `valuationFramework` (RJ of IFRS) bij consolidatie-groep-setup;
goodwill-treatment automatic gated op keuze.

Badwill (negatieve goodwill): RJ 216 direct als bate in V&W (na hertoetsing fair
value). IFRS: rare, typically indicates koop-voordeel dat hertoetst moet worden.

**Alternative considered**: Hybrid (RJ-afschrijving + IFRS-impairment). Rejected
— vermengeling van regimes; entiteit moet keuze maken.

### D6 — Minderheidsbelang-split: aandeel derden in dochters <100%

Voor dochters met <100% eigendom (bijv. 70% groep, 30% derden):
- Geconsolideerde V&W toont volledige resultaat dochter (100%)
- Split daaronder: "Toe te rekenen aan aandeelhouders moeder: 70%",
  "Toe te rekenen aan minderheidsbelang: 30%"
- Geconsolideerde balans toont minderheidsbelang (30% EV dochter) apart onder
  eigen vermogen als "Aandeel derden"

MinorityInterest-register tracked opening-saldo, aandeel-in-resultaat-periode,
dividend-aan-minderheid, closing-saldo per dochter. Eliminatie-engine valideert
<100%-belangen en past minority-interest-split toe op post-eliminatie-saldi.

**Alternative considered**: Consolidatie integraal, geen minority-split. Rejected
— RJ 217 §8 / IFRS 10 §B95–B96 mandateert minority interest disclosure.

### D7 — Eliminatie-audit-trail: volledige wie/wanneer/waarom per boeking

Elke EliminationEntry bewaart:
- `generatedBy`: "system" of user-naam
- `generatedAt`: timestamp
- `reviewStatus`: pending / approved / rejected
- `reviewedBy`: user-naam (als reviewed)
- `reviewComment`: motivatie accountant
- `sourceTransactions`: links naar originele journaalposten in bron-administraties

Immutabel gearchiveerd per ConsolidationPeriod. Accountant kan reversale of
wijziging aanvragen → nieuwe elimination-entry (niet overwrit oud).

**Alternative considered**: Geen audit-trail, vorige versie overwrite. Rejected
— compliance wettelijk (Titel 9 BW art. 2:404–405 vergt conservering
jaarrekening-werkpapieren 7+ jaar).

### D8 — Comparatieve periodes en herclassificatie

Geconsolideerde balans + V&W altijd comparatief (huidig jaar + vorig jaar).
Bij wijziging groepssamenstelling, rekeningschema-mapping, of herclassificatie:
- Vorig-jaar-cijfers automatisch herclassificeren voor vergelijkbaarheid
- Expliciet melding aan gebruiker: "2024 herclassificaties door groepswijziging
  (entiteit X toegevoegd 1-2-2025)"
- Toelichting in jaarrekening-output

**Alternative considered**: Vorig-jaar laten staan, compara niet vergelijkbaar.
Rejected — RJ 217 §19 / IFRS 1 vereist vergelijkbare cijfers.

### D9 — Consolidatie-toelichting: auto-gegenereerde notes

Per ConsolidatedReport (output van consolidatie-periode) worden standaard-
paragrafen gegenereerd:
1. **Consolidatiegrondslag**: RJ 217 of IFRS 10, methodes (integraal/proportioneel/
   equity per entiteit), eerste-consolidatie-datum, wettelijke vrijstellingen
2. **Groepsmaatschappijen-lijst**: naam / zetel / eigendomspercentage /
   consolidatie-methode / functionele-valuta / eerste-consolidatie
3. **Verloop eigen vermogen**: matrixvorm (geplaatst-kapitaal, agio, reserves,
   herwaardering, CTA, onverdeeld resultaat, minderheidsbelang)
4. **Verloop goodwill**: beginstand, acquisities, afschrijvingen (RJ) /
   impairments (IFRS), eindstand
5. **Intercompany-eliminaties**: overzicht per categorie (sales, AR/AP, leningen,
   dividenden, margin-inventory)
6. **Minderheidsbelang** (als significant): percentage / aandeel-resultaat / saldo
   per dochter
7. **Valuta-translatie** (als foreign-currency dochters): koersen toegepast, CTA-
   mutatie-jaar, cumulatief CTA-saldo

**Alternative considered**: Handmatige toelichting-authoring. Rejected —
gegenereerde toelichting garandeert consistentie met GL posting + reduceert
transcriptie-fout.

### D10 — Werkpapier-aggregatie: pre-eliminatie kolom-view

ConsolidationPeriod genereert pre-eliminatie werkpapier (consolidation-balance
+ consolidation-income-statement voorstadia):
- Kolom per entiteit (GL + onderling balances)
- Kolom "Subtotaal pre-eliminatie" (horizontaal sum)
- Kolom "Eliminaties" (per rij sum van elimination-entries die betrekking hebben)
- Kolom "Geconsolideerd post-eliminatie"

Elke rij is rapportageregel uit groeps-rekeningschema (RGS-conform). Verticaal
optellen klopt altijd (by-design).

**Alternative considered**: Geen werkpapier, direct geconsolideerd. Rejected —
audit wil zien hoe pre-eliminatie saldi eruitzagen (toetsing consolidatie-
logica).

## Reuse Analysis

| Capability | What already exists | Reuse strategy |
|---|---|---|
| Per-administratie GL aggregatie | bookkeeping-financial-statements (T2) | Consume per-administratie balans + V&W via Administration FK |
| Multi-entiteit GL management | bookkeeping-multi-administratie (NEW T1) | Prerequisite: administration-per-groep-entiteit |
| Intercompany-relatie mapping | bookkeeping-intercompany-elimination (NEW T2) | Consume IntercompanyRelation + matching-algoritme |
| Valuta-translatie koersbepaling | treasury-cash-management (T3) | Reuse FX-rates per CurrencyBalance / market-data |
| Goodwill-amortisatie | bookkeeping-fixed-assets-depreciation (T2) | Reuse DepreciationSchedule pattern voor goodwill-afschrijving |
| GL-posting van eliminaties | bookkeeping-general-ledger (T2) | Auto-post elimination-entries naar GL per elimination-entry.regels-JSONB |
| Lifecycle management | openregister x-openregister-lifecycle (core) | Consolidation-period workflow: open → eliminatie-fase → review → gesloten |
| Audit-trail | openregister auditTrail + notes (core) | Per-entiteit + per-eliminatie tracking automatisch via OR |
| Jaarrekening-output | bookkeeping-financial-statements (T2) | Consume consolidated-balance + consolidated-income-statement +notes-output |

## Implementation Sequence

1. **Consolidation-group schema**: Groepsdefintie (moeder-administratie, rapportage-
   valuta, rapportage-grondslag, boekjaar-einde, consolidatie-methode-default).
2. **Group-entity schema**: Deelneming-mapping (entiteit-type, eigendomspercentage,
   consolidatie-methode, eerste-consolidatie, functionele-valuta).
3. **Intercompany-relation schema**: Handelsrelatie-mapping (debiteur / crediteur /
   type / eliminatie-rekening).
4. **Consolidation-period schema**: Consolidatie-run-container (groep-FK,
   periode-start/eind, status-lifecycle, executor, totaal-eliminaties).
5. **Elimination-entry schema**: Individuele eliminatie (periode-FK, type,
   regels-JSONB, bron-transacties, review-status, audit-trail).
6. **Translation-adjustment schema**: Valuta-translatie (periode-FK, entiteit-FK,
   valuta-koppel, translatie-methode, CTA-component).
7. **Minority-interest schema**: Minderheidsbelang-tracking (groep-FK, entiteit-FK,
   percentage-derden, verloop-saldi).
8. **Goodwill schema**: Acquisitie-goodwill/badwill (groep-FK, dochter-FK,
   acquisitie-datum, koopprijs, fair-value, goodwill-bedrag, afschrijving-methode,
   schema-verloop).
9. **Consolidated-balance schema**: Output-balans (groep-FK, periode-FK, per-
   rapportageregel-rij met comparatief).
10. **Consolidated-income-statement schema**: Output-V&W (groep-FK, periode-FK,
    per-rapportageregel-rij met split moeder/minderheid, comparatief).

## Lifecycle & Aggregations

**Consolidation-period lifecycle**:
- `open`: Groep-setup afgerond, bron-administraties afgesloten, pre-eliminatie
  aggregatie start.
- `eliminatie-fase`: Pre-eliminatie werkpapier gegenereerd, intercompany-matching
  loopt, exception-queue bevat mismatches, elimination-entries gegenereerd
  (auto + handmatig).
- `review`: Accountant reviewed elimination-entries (goed/afkeuring per item),
  saldi balanceren.
- `gesloten`: Snapshot immutabel gearchiveerd, geen wijzigingen meer toegestaan
  (audit-proof).
- `gearchiveerd`: Verplaatst naar archive (na retentie-vereiste).

**Aggregations**:
- Pre-eliminatie aggregatie: Group-entity kolommen samellen per rapportageregel.
- Eliminatie-matching: Per IntercompanyRelation genereer elimination-entries
  (of flag exceptions buiten-tolerantie).
- Post-eliminatie aggregatie: Werkpapier-totaal = pre-eliminatie - eliminaties.
- Valuta-translatie: Per buitenlandse entiteit translatie-adjustment genereren,
  CTA naar EV-reserve.
- Minderheidsbelang-split: Post-eliminatie resultaat opsplitsen naar moeder &
  minderheid.
- Goodwill-verloop: Goodwill-afschrijving (RJ) of impairment-test-vlag (IFRS)
  per GJ.
- Toelichting-generatie: Standaard-paragrafen per RJ 217 / IFRS 10 uit
  consolidated-balance + consolidated-income-statement + metadata.

## Success Metrics

- CFO/controller kan groep definiëren, per-administratie saldi importeren, intercompany-
  transacties interactief matchen en elimineren, valuta-translatie + minderheidsbelang-
  split + goodwill-amortisatie automatisch uitvoeren, en volledige RJ 217 / IFRS 10
  geconsolideerde output (balans + V&W + notes) genereren en downloadbaar maken als
  PDF/Excel.
- Eliminatie-audit-trail volledig (per-entry wie/wanneer/waarom + accountant-review)
  en immutabel gearchiveerd (7+ jaar).
- Comparatieve periodes correct (vorig-jaar-herclassificatie automatisch).
- Valuta-translatie-CTA in eigen vermogen (niet in V&W).
- Minderheidsbelang-aandeel correct gesplit (moeder vs derden).
- Consolidatie-toelichting RJ 217 / IFRS 10-conform.
