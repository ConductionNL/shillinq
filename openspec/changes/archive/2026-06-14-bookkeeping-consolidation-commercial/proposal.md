# Proposal: bookkeeping-consolidation-commercial

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(`consolidation-group`, `group-entity`, `intercompany-relation`,
`consolidation-period`, `elimination-entry`, `translation-adjustment`,
`minority-interest`, `goodwill`, `consolidated-balance`,
`consolidated-income-statement`) + `x-openregister-lifecycle` for
consolidation-run workflows and audit trails. No PHP consolidation
calculation service is authored (subject to ADR-031 exception: a
declarative elimination-matching and currency-translation engine).

## Summary

Introduce the **commercial consolidation (RJ 217 / IAS 27)** capability for
Shillinq as one of the T3 regulatory + compliance capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares eight new registers
and enables Dutch MKB holding companies to consolidate multi-entity financial
statements:

- `consolidation-group` — definition of a consolidation circle (moeder +
  dochters, valuta, rapportage-grondslag, boekjaar-einde)
- `group-entity` — deelneming within a group (administratie-id, eigendomspercentage,
  consolidatie-methode, eerste-consolidatie-datum)
- `intercompany-relation` — mapping of internal trade relationships for
  elimination matching
- `consolidation-period` — a consolidation run for a specific period (status:
  open, eliminatie-fase, review, gesloten, gearchiveerd)
- `elimination-entry` — individual elimination bookings (intercompany sales,
  AR/AP, loans, dividend, margin-in-inventory, goodwill, minority-interest)
- `translation-adjustment` — currency revaluation (CTA) for foreign-currency
  entiteiten
- `minority-interest` — non-controlling interest tracking per entiteit
- `goodwill` — acquisition goodwill/badwill with amortisation schedule

The consolidation flow is declarative `x-openregister-lifecycle` on both
`consolidation-group` (multi-period planning) and `consolidation-period`
(per-period measurement). Aggregation logic is trigger-driven: per-administratie
balansen + eliminations → pre-elimination totaal → eliminatie-fase (matching +
validation) → review (accountant sign-off) → gesloten (locked snapshot).

This change conforms to the shared `nextcloud-app` spec for app structure and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-multi-administratie`](../bookkeeping-multi-administratie/proposal.md) (NEW spec, REQUIRED) — consolidation ingests per-entiteit administraties
- [`bookkeeping-financial-statements`](../add-shillinq-financial-statements/proposal.md) — per-entiteit jaarrekening is input to consolidatie
- [`bookkeeping-intercompany-elimination`](../bookkeeping-intercompany-elimination/proposal.md) (NEW spec) — matching engine for intercompany transactions

## Motivation

Dutch groepstructuren (holding + werkmaatschappijen) are the norm for commercial
MKB entities. Wettelijk art. 2:406 BW mandates a **geconsolideerde jaarrekening**
when the moeder exercises "overheersende zeggenschap" over dochters, unless a
formal exemption applies (art. 2:407, 2:408, 2:403).

Consolidatie is conceptually straightforward — aggregate per-administratie
balances and eliminate internal transactions — but operationally complex:
harmonisation of valuation methods, elimination of intercompany sales/margins,
elimination of AR/AP and loans between groepsentiteiten, revaluation of
foreign-currency entiteiten, measurement of goodwill/badwill on acquisitions,
tracking of minority interests, and audit-proof elimination audit trails.

Today, accountants handle consolidation in **Excel worksheets** (one column per
entiteit, elimination columns with hand-coded formulas) or expensive dedicated
tools (Caseware, Exact Online Plus, Visma — typisch €500–2000/maand in top
tiers). Software-based consolidation for Dutch MKB holding companies is
under-served: generic accounting tools lack the elimination-matching logic;
specialist tools are overpriced for small groups.

Shillinq's consolidation module targets this gap: **professional consolidation
for MKB holdings**, priced for a holding-werkmaatschappij-structuur, built on
top of `bookkeeping-multi-administratie` (per-entiteit GL) and
`bookkeeping-financial-statements` (per-entiteit output), with declarative
elimination logic, currency translation, minority-interest tracking, and a
full audit trail per RJ 217 (Dutch commercial norm) or IAS 27 / IFRS 10 (for
groups choosing IFRS).

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-consolidation-commercial`); declares 8 new registers
  (`consolidation-group`, `group-entity`, `intercompany-relation`,
  `consolidation-period`, `elimination-entry`, `translation-adjustment`,
  `minority-interest`, `goodwill`, `consolidated-balance`,
  `consolidated-income-statement`) with lifecycles + aggregations; adds 2
  manifest navigation entries (Consolidation Groups, Consolidation Periods).
- [ ] Project: bookkeeping-intercompany-elimination — new spec; supplies the
  matching algorithm for intercompany transaction detection + validation
  (rounding tolerance, sign checking, timing mismatches).
- [ ] Project: bookkeeping-multi-administratie — new spec; required prerequisite
  for multi-entity GL management within a single Shillinq instance.
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations` for pre-elimination
  aggregation, currency translation, minority-interest computation.

## Scope

### In Scope

- One new capability spec (`bookkeeping-consolidation-commercial`) — see the
  `design.md` below.
- 10 new registers: `consolidation-group` (groepsdefintie), `group-entity`
  (deelneming), `intercompany-relation` (handelsrelatie-mapping),
  `consolidation-period` (consolidatie-run), `elimination-entry` (individuele
  eliminatie), `translation-adjustment` (valuta-herwaardering), `minority-interest`
  (minderheidsbelang), `goodwill`, `consolidated-balance` (geconsolideerde balans),
  `consolidated-income-statement` (geconsolideerde V&W).
- Consolidatie-grondslag: RJ 217 (Nederlandse commerciële norm) of IAS 27 / IFRS
  10 per keuze groep; mandatory disclosure per Titel 9 BW art. 2:416–406.
- Consolidatie-methode: integraal (100%-belang of controllerend), proportioneel
  (joint venture), equity (geassocieerde deelneming <50%) per RJ 217 / IFRS 10.
- Eliminatie-typen: intercompany sales / AR-AP matching / intercompany loans /
  dividend / margin-in-inventory / goodwill / badwill / minority-interest-split.
- Valuta-translatie: current-rate methode (balansposten tegen slotkoers, V&W
  tegen gemiddelde koers) per RJ 122 / IAS 21; CTA (Cumulative Translation
  Adjustment) in eigen vermogen met non-recycling op desinvestering.
- Goodwill-accounting: acquisitie-accounting per RJ 216 / IFRS 3; goodwill
  afschrijving (RJ-default 10 jaar lineair, max 20 jaar) of jaarlijkse
  impairment-test (IFRS).
- Minderheidsbelang: aandeel derden in dochters <100% eigendom;
  geconsolideerd resultaat gesplitst naar aandeelhouders moeder vs minderheid;
  minderheidsbelang apart in geconsolideerd eigen vermogen.
- Intercompany-matching toleranties: default €10 absoluut of 0.5% relatief;
  mismatches buiten-tolerantie in exception-queue voor handmatige resolutie.
- Comparatieve periodes: geconsolideerde cijfers altijd comparatief (huidig +
  vorig jaar); herclassificaties automatisch op vorig jaar voor
  vergelijkbaarheid.
- Consolidatie-toelichting: geauto-gegenereerde notes met RJ 217 / IFRS 10
  verplichte uitsplitsingen (consolidatiegrondslag, groepsmaatschappijen-lijst,
  verloop eigen vermogen met minderheid + CTA, goodwill-verloop,
  intercompany-eliminatie-overzicht).
- Audit trail: volledige tracking per eliminatie (wie/wanneer/waarom);
  accountant-review per eliminatie met goed/afkeuring + motivatie.

### Out of Scope

- Fiscale consolidatie (fiscale eenheid voor vennootschapsbelasting) — aparte
  module `bookkeeping-fiscal-unit`.
- Cash-flow consolidatie en management-reporting consolidaties (segment-
  rapportage, geografische uitsplitsing) — future tiers.
- Real-time consolidatie voor maandelijks/kwartaalmaatschappij (v1 is per-
  jaarrekening; maandelijkse consolidatie in T4).
- Intercompany netting (wisselkoopkredietering) — aparte spec.

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Intercompany-matching algoritme kan edge cases missen (timing, currency rounding, classification). Mismatch leidt tot handmatig tussenwerk. | Default-toleranties (€10 of 0.5%) configurable per groep; exception-queue voor accountant triage; bookkeeping-intercompany-elimination spec details matching-regels. |
| Entiteit voegt halverwege jaar dochter toe aan groep; consolidatie moet pro-rata vanaf acquisitie-datum zonder handmatig aanpassen. | ConsolidationGroup-lifecycle met eerste-consolidatie-datum per entiteit; aggregatie-query skips pre-acquisitie periodes automatically. |
| Valuta-translatie (CTA) complex; entiteit mis-activeert CTA in resultaat i.p.v. OCI. Audit controleert maar reparatie retrospectief lastig. | Schema-validatie dwingt CTA aan OCI-rekening; P&L-posting-rules blokkeren CTA-posting naar resultaat. |
| Goodwill afschrijving (RJ 10–20 jaar) vs IFRS impairment-testing vergt separate workflows; operator kiest verkeerde methode per grondslag. | `valuationFramework` enum (RJ of IFRS) bepaalt goodwill-treatment; afschrijving-method en impairment-test gegatekeeped per keuze. |
| Minderheidsbelang-berekening complex; operator vergeet <100%-belang aan eliminatie-matching toe te voegen of splitst minderheid verkeerd. | MinorityInterest-register first-class; eliminatie-engine valideert <100% belangen; toelichting toont minderheidsbelang per entiteit. |

## Rollback

Consolidatie is non-reversible eenmaal opgenomen in de geconsolideerde jaarrekening
voor publicatie (wettelijk verplicht, audit-getekend). Rollback kan alleen als de
spec wordt geweigerd voordat een entiteit productie-consolidatie-data invoert.
Eenmaal live, correcties zijn journaalposten (wijzigings-journalen), niet
verwijderingen.

## Open Questions

1. **Intercompany-matching automatisering**: Welke match-strategy default?
   (fuzzy amount matching, expliciete invoice-matching, time-window tolerance?)
   Aanbeveling: fuzzy amount (default) + optional expliciete invoice-matching
   (T4).
2. **Maandelijkse consolidatie**: Is dit voor v1, of only per-jaarrekening?
   Aanbeveling: v1 per-jaarrekening; maandelijkse consolidatie (rolling-close,
   forecast consolidation) in T4.
3. **Intercompany netting**: Mag entiteit netto-boeking gebruiken i.p.v.
   eliminatie? Regelkader TBD in bookkeeping-intercompany-elimination spec.

## Dependencies

- **bookkeeping-multi-administratie**: Consolidatie ingests per-entiteit GL's
  (via Administration FK) vanuit multi-administratie module. Verplicht
  prerequisiet.
- **bookkeeping-financial-statements**: Per-entiteit balans + V&W zijn input
  voor aggregatie; rekeningschema-mapping consistent nodig.
- **bookkeeping-intercompany-elimination**: Matching-algoritme voor
  intercompany-transactie detectie + validatie per IntercompanyRelation-
  mapping.

## Success Criteria

- Accountant kan een consolidatiegroep definiëren (moeder-administratie +
  dochters met eigendomspercentage + consolidatie-methode), per-administratie
  saldi aggregeren, intercompany-transacties elimineren (handmatige triage van
  mismatches), valuta vertalen, minderheidsbelang splitsen, goodwill
  amortiseren, en een volledige RJ 217 of IFRS 10 consolidatie-output
  genereren zonder Excel.
- Valuta-translatie met CTA automatisch gehandeeld per current-rate methode;
  geconsolideerde balans-sluitingen klopt (activa = passiva + EV).
- Eliminatie-audit-trail volledig (wie/wanneer/waarom) per eliminatie;
  accountant kan per-eliminatie goedkeuren of afkeuren met motivatie.
- Geconsolideerde balans + V&W + toelichting (consolidated-balance +
  consolidated-income-statement + notes) exporteerbaar als PDF / Excel voor
  depositie KvK of interne management-rapportage.
