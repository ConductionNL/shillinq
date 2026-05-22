---
status: proposed
app: shillinq
spec: bookkeeping-ifrs-rj-dual-gaap
depends-on:
  - bookkeeping-general-ledger
  - bookkeeping-financial-statements
target-users:
  - controller
  - group-accountant
  - external-auditor
  - cfo
standards:
  - IFRS-9
  - IFRS-15
  - IFRS-16
  - IAS-19
  - IAS-36
  - RJ-271
  - RJ-290
  - RJ-292
  - BW2-Titel-9
  - EU-2002/1606
---

# Dual GAAP Reporting: IFRS naast Nederlandse Richtlijnen voor de Jaarverslaggeving (RJ)

## Purpose

Nederlandse mid-cap en grote ondernemingen die op een Europese gereglementeerde markt noteren zijn verplicht hun geconsolideerde jaarrekening op te stellen onder IFRS (EU-Verordening 1606/2002). Dochterondernemingen, joint ventures en niet-beursgenoteerde groepsmaatschappijen blijven echter rapporteren onder Nederlandse Richtlijnen voor de Jaarverslaggeving (RJ) zoals uitgegeven door de Raad voor de Jaarverslaggeving en verankerd in BW2 Titel 9. Daarnaast kiezen veel familie-bedrijven, coöperaties en stichtingen bewust voor dubbele rapportage om internationale financiering, M&A-trajecten of cross-border benchmarking te faciliteren.

Dit levert een continue dubbele-grootboek-uitdaging op: dezelfde economische transactie moet onder twee waarderingsstelsels worden geboekt, met materieel verschillende uitkomsten op leases (IFRS 16 versus operationeel onder RJ), pensioenen (IAS 19 actuariële verplichting versus RJ 271 toegezegde-pensioenregeling), financiële instrumenten (IFRS 9 expected credit loss versus RJ 290 incurred-loss model), opbrengstverantwoording (IFRS 15 vijfstappenmodel versus RJ 270) en bijzondere waardeverminderingen (IAS 36 versus RJ 121). Handmatige reconciliatie in Excel kost controllers gemiddeld 80-120 uur per kwartaal en is foutgevoelig.

Shillinq biedt parallel-ledger architectuur waarbij elke transactie automatisch onder beide stelsels wordt verwerkt, met een reconciliatie-engine die het verschil tussen RJ-resultaat en IFRS-resultaat regel voor regel verklaart via aansluitingsboekingen (bridging adjustments). Per grootboekrekening, per kostendrager en per periode is de overgang traceerbaar tot op de oorspronkelijke transactie.

## Data Model

**AccountingFramework** (entity): identifier (IFRS-EU, NL-GAAP-RJ, US-GAAP), version (2026, 2025), effective_date, jurisdictions[], regulator (IASB, RJ, FASB), base_currency_default, statement_templates[].

**ChartOfAccountsMapping** (relation): source_account (RJ rekeningnummer), target_accounts[] (IFRS), mapping_type (one-to-one, one-to-many, many-to-one, recharacterization), allocation_rule (percentage, formula, ratio_driver), effective_from, effective_to, approver, audit_trail. Voorbeeld: RJ "1530 Operationeel geleasede activa" → IFRS "1531 Right-of-Use asset" + "2531 Lease liability current" + "2532 Lease liability non-current".

**DualTransaction** (entity): base_transaction_id, rj_journal_entries[], ifrs_journal_entries[], divergence_amount, divergence_reason_code (LEASE_IFRS16, PENSION_IAS19, ECL_IFRS9, REVENUE_IFRS15, IMPAIRMENT_IAS36, BORROWING_COST_IAS23, DEFERRED_TAX_IAS12, BUSINESS_COMBINATION_IFRS3), divergence_classification (permanent, temporary, reclassification).

**ReconciliationBridge** (entity): period, from_framework (RJ), to_framework (IFRS), opening_balance_rj, adjustments[] (description, amount, account, standard_reference), closing_balance_ifrs, total_temporary_differences, total_permanent_differences, tax_effect (uitgesplitst per jurisdictie), approver, signoff_date.

**StandardSpecificCalculation** (entity): standard_code (IFRS-16, IAS-19, IFRS-9), contract_or_position_reference, calculation_method (incremental_borrowing_rate, projected_unit_credit, expected_credit_loss_stages), inputs (json), outputs (json), revaluation_frequency (monthly, quarterly, annual), last_calculated_at, actuary_signoff (voor IAS 19), audit_evidence_uri.

**FrameworkElection** (entity): per legal_entity de keuze welk stelsel primair is, comply-or-explain motivatie, RJ uiting (RJ 100-onverkort, RJk klein, IFRS-EU, IFRS-volledig), wijzigingsmoment, AVA-besluit referentie.

## Requirements

### REQ-001: Parallel-ledger journaalpost-creatie

**GIVEN** een controller boekt een factuur voor een nieuwe 5-jarige autolease van €450/maand
**WHEN** de boeking wordt opgeslagen
**THEN** maakt het systeem onder RJ-ledger een operationele-lease-boeking (Kostenrekening "Autokosten" / Crediteuren €450) en onder IFRS-ledger gelijktijdig een IFRS 16-boeking (Right-of-Use asset €24.300 / Lease liability €24.300 bij aanvang, plus maandelijkse afschrijving + rente-component), beide gekoppeld aan dezelfde base_transaction_id en met divergence_reason_code "LEASE_IFRS16".

### REQ-002: Chart-of-accounts mapping met allocatie-regels

**GIVEN** een group-accountant configureert de COA-mapping voor de Nederlandse holding
**WHEN** zij rekening "1530 Operationeel geleasede activa" (RJ) koppelt aan IFRS-rekeningen
**THEN** kan zij een one-to-many mapping definiëren met allocatie-formule (ROU-asset = PV(future lease payments at IBR), Liability = same), kan zij testdata uploaden om de mapping te valideren, en blokkeert het systeem activatie totdat tenminste 95% van de bestaande RJ-mutaties succesvol kunnen worden omgezet of een expliciete uitzonderings-rechtvaardiging is vastgelegd.

### REQ-003: IAS 19 pensioen-actuariële berekening

**GIVEN** een onderneming heeft een defined-benefit-regeling met 234 actieve deelnemers
**WHEN** de jaarafsluiting wordt voorbereid
**THEN** importeert het systeem de actuariële rapportage (XBRL-NT of PDF met OCR-extractie), boekt onder RJ 271 de overeengekomen pensioenvoorziening (toegezegde-bijdrageregeling indien doorbelast aan pensioenfonds), boekt onder IAS 19 de projected-unit-credit verplichting met service cost in P&L en remeasurements in OCI, en genereert een aansluiting tussen beide cijfers met expliciete kwantificering van de discount-rate impact, demografische aannames en plan-asset returns.

### REQ-004: IFRS 9 expected credit loss versus RJ 290 incurred loss

**GIVEN** een debiteurenportefeuille van €4.2M met diverse aging-buckets
**WHEN** de maandafsluiting draait
**THEN** berekent het systeem onder RJ 290 een voorziening op basis van daadwerkelijk gebleken oninbaarheid (incurred-loss-model met aging-tabel), berekent onder IFRS 9 een 12-maands of lifetime ECL afhankelijk van stage-1/2/3 classificatie inclusief forward-looking macro-overlays, en toont het delta met onderbouwing per debiteuren-segment.

### REQ-005: Reconciliatie-rapport RJ → IFRS

**GIVEN** een controller bereidt de IFRS-toelichting bij de geconsolideerde jaarrekening voor
**WHEN** zij een reconciliation-bridge genereert voor het boekjaar
**THEN** levert het systeem een gestructureerd rapport met opening RJ-equity, alle bridging adjustments per IFRS-standaard (IFRS 16 leases, IAS 19 pensions, IFRS 9 ECL, IFRS 15 revenue, IAS 36 impairment, deferred-tax-effect per item), closing IFRS-equity, en idem voor net result; elke regel klikbaar tot op de onderliggende journaalposten en source documents.

### REQ-006: Tijdelijke versus permanente verschillen en uitgestelde belastingen

**GIVEN** een IFRS-aanpassing creëert een waarderingsverschil tussen boekwaarde en fiscale grondslag
**WHEN** de divergentie wordt geclassificeerd
**THEN** kan de gebruiker per regel aangeven of het verschil tijdelijk (geeft aanleiding tot uitgestelde belastingvordering of -verplichting onder IAS 12) of permanent (geen tax effect) is, berekent het systeem automatisch de deferred-tax-impact tegen de geldende statutaire tarieven per jurisdictie, en boekt dit in een aparte sub-administratie die aansluit op de IFRS-balans.

### REQ-007: Versiebeheer en effectiviteitsdatum bij standaard-wijzigingen

**GIVEN** RJ 271 wordt herzien per 1 januari 2027 met gewijzigde behandeling van VPL-regelingen
**WHEN** de group-accountant de nieuwe versie configureert
**THEN** kan zij de nieuwe waarderingsregels parallel naast de oude inrichten met effectiviteitsdatum, kan zij retrospectieve toepassing (full retrospective) of modified retrospective kiezen met automatische berekening van het cumulatief effect op opening retained earnings, en genereert het systeem de vereiste toelichting over de impact van de stelselwijziging.

### REQ-008: Drill-down van geconsolideerd cijfer naar bron-transactie

**GIVEN** een external-auditor controleert de IFRS-pensioenlast in de groepsjaarrekening
**WHEN** zij in het reconciliatie-rapport de regel "IAS 19 service cost €234.000" aanklikt
**THEN** toont het systeem de actuariële berekening met alle inputs, de onderliggende journaalpost in IFRS-ledger, de bijbehorende RJ-journaalpost ter vergelijking, het audit-evidence document (actuariële rapportage), de signoff van de actuaris en de boekingsdatum, alles binnen dezelfde audit-trail-weergave.

### REQ-009: Multi-entity consolidatie met gemengde frameworks

**GIVEN** een Nederlandse holding consolideert 7 dochters waarvan 3 IFRS-rapporterend en 4 RJ-rapporterend
**WHEN** een geconsolideerde IFRS-jaarrekening wordt gegenereerd
**THEN** converteert het systeem de RJ-cijfers van de 4 dochters automatisch naar IFRS via hun parallel-ledgers, elimineert intercompany-posities, en levert een consolidatieblad dat per dochter de RJ-IFRS conversie en vervolgens de consolidatie-eliminaties traceerbaar maakt.

### REQ-010: Comply-or-explain en framework-keuze documentatie

**GIVEN** een legal entity-administrator stelt voor een nieuwe BV de rapportagestelsel-keuze in
**WHEN** zij kiest voor RJk (Richtlijnen voor kleine rechtspersonen) in plaats van RJ-onverkort
**THEN** registreert het systeem de keuze met motivatie (kleine-rechtspersoon op basis van BW2 art 2:396), de AVA-besluit-referentie, de toepasselijke grootte-criteria-meting (balanstotaal, netto-omzet, gemiddeld aantal werknemers), waarschuwt automatisch bij overschrijding van de criteria gedurende twee opeenvolgende boekjaren, en blokkeert publicatie van een jaarrekening die niet aansluit op de vastgelegde framework-keuze.

## Standards & References

- **IFRS-EU**: IFRS 9 Financial Instruments, IFRS 15 Revenue from Contracts with Customers, IFRS 16 Leases, IAS 12 Income Taxes, IAS 19 Employee Benefits, IAS 36 Impairment of Assets
- **Nederlandse Richtlijnen voor de Jaarverslaggeving (RJ)**: RJ 100-serie algemeen, RJ 270 Omzetverantwoording, RJ 271 Personeelsbeloningen, RJ 290 Financiële instrumenten, RJ 292 Lease-overeenkomsten, RJk klein
- **BW2 Titel 9**: art 2:362 (getrouw beeld), art 2:384 (waardering), art 2:396 (kleine rechtspersonen), art 2:397 (middelgrote rechtspersonen)
- **EU-Verordening 1606/2002**: IAS-Verordening verplichte IFRS voor beursgenoteerde ondernemingen
- **SBR/NT 2026**: Nederlandse Taxonomie voor RJ-deponering bij KvK
- **ESEF**: European Single Electronic Format voor IFRS deponering bij AFM

## Cross-app dependencies

- **shillinq:bookkeeping-general-ledger**: levert de basis-grootboek-architectuur die wordt uitgebreid met parallel-ledger; vereist multi-ledger ondersteuning per transactie
- **shillinq:bookkeeping-financial-statements**: consumeert beide ledgers om RJ-jaarrekening (model A/B/C BW2) en IFRS-financial-statements (IAS 1 presentation) parallel te genereren
- **shillinq:bookkeeping-consolidation**: gebruikt framework-conversie bij multi-entity consolidatie
- **shillinq:bookkeeping-tax-deferred**: consumeert tijdelijke-verschillen-classificatie voor IAS 12 uitgestelde belastingen
- **docudesk**: bewaart actuariële rapportages, lease-contracten, ECL-modellen als audit-evidence met retentie conform NV COS

## Target users

- **Controller** (90% gebruik): dagelijkse boekingen, periode-afsluiting, reconciliatie-voorbereiding
- **Group-accountant** (concern-niveau): COA-mappings beheren, framework-keuzes per entity, consolidatie
- **External auditor** (Big-4 / mid-tier): drill-down en evidence-review tijdens jaarrekeningcontrole
- **CFO**: dashboards met RJ versus IFRS impact op kerncijfers, M&A-due-diligence ondersteuning
- **Actuaris** (extern): upload van IAS 19 berekeningen via gestructureerd template
- **AFM/KvK-deponeerder**: export van SBR-NT (RJ) en ESEF (IFRS) bestanden voor wettelijke publicatie
