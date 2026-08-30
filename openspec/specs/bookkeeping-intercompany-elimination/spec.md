---
status: done
---

# Spec: bookkeeping-intercompany-elimination

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `bookkeeping-consolidation-commercial` (consolidatie-group context),
`bookkeeping-multi-administratie` (bron-administratie GL-access),
`bookkeeping-grootboek` (transactie-query)

## Purpose

This specification defines the requirements for bookkeeping intercompany elimination in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: intercompany elimination — not browser-testable


### REQ-ICE-001: Intercompany-relatie definitie en onderhoud

The system SHALL satisfy this requirement: Intercompany-relatie definitie en onderhoud.

Het systeem MOET een gebruiker (consolidatie-controller of accountant) in staat stellen intercompany-relaties expliciet te definiëren tussen entiteiten in een consolidatie-groep, inclusief de relevante grootboekrekeningen aan beide zijden, relatie-type, en tolerantie-instellingen, zodat de matching-engine weet welke transacties bij elkaar horen.

`IntercompanyRelation` MOET als persistente register in `lib/Settings/shillinq_register.json` gedeclareerd worden met de volgende velden:

| Veld | Type | Vereist | Doel |
|---|---|---|---|
| `relationId` | string | Ja | Unieke identifier per consolidatie-groep |
| `groupId` | string | Ja | FK naar `ConsolidationGroup` |
| `entityAId` | string | Ja | FK naar eerste entiteit |
| `entityBId` | string | Ja | FK naar tweede entiteit |
| `relationType` | enum | Ja | sales-of-goods, sales-of-services, royalty, licensing, management-fee, interest-on-loan, dividend, capital-contribution, expense-recharge |
| `defaultAccountA` | string | Ja | Standaard GL-rekening entiteit A (bijv. "8200 - IC-omzet") |
| `defaultAccountB` | string | Ja | Standaard GL-rekening entiteit B (bijv. "4400 - IC-inkopen") |
| `toleranceAbsolute` | number | Nee (default €10) | Absoluut verschil-drempel in EUR |
| `toleranceRelative` | number | Nee (default 0.5%) | Relatief verschil-drempel in % |
| `toleranceFallbackAccount` | string | Nee | Restpost-rekening voor binnen-tolerantie afwijkingen (bijv. "9999 - Eliminatie-restpost") |
| `activeFrom` | date | Ja | Start-datum relatie |
| `activeTo` | date | Nee | Eind-datum relatie (null = actueel) |
| `administrationId` | string | Ja | FK naar Administration |

Schema.org annotation: `schema:FinancialProduct` (IC-relatie als transactie-type).

#### Scenario: IC-relatie aanmaken en koppelen

- **GIVEN** consolidatie-groep "Acme Group" met entiteiten Holding BV, Werk BV, Vastgoed BV
- **WHEN** ik IntercompanyRelation aanmaak "Werk BV verkoopt diensten aan Holding BV" met type "sales-of-services", defaultAccountA = "8200 (Werk BV IC-omzet)", defaultAccountB = "4400 (Holding BV IC-inkopen)", toleranceAbsolute = €10, toleranceRelative = 0.5%
- **THEN** de relatie wordt opgeslagen en gekoppeld aan de consolidatie-groep; toekomstige consolidatie-runs gebruiken deze als matching-instructie.

#### Scenario: Dubbele relatie detectie

- **GIVEN** er bestaat al een IntercompanyRelation voor hetzelfde paar entiteiten + relatie-type
- **WHEN** gebruiker opslaat
- **THEN** het systeem detecteert het dubbel, weigert de tweede aan te maken, en verwijst naar de bestaande relatie ter wijziging.

### REQ-ICE-002: Auto-detectie intercompany-transacties

The system SHALL satisfy this requirement: Auto-detectie intercompany-transacties.

Het systeem MOET in alle entiteit-administraties van een consolidatie-groep periodiek scannen op transacties die intercompany zijn — op basis van geregistreerde IC-grootboekrekeningen, op basis van counterparty-naam-matching, of op basis van transactie-label — en deze als `IntercompanyTransaction` registreren met counterparty-aanduiding en detectie-confidence.

`IntercompanyTransaction` MOET als persistente register gedeclareerd worden met:

| Veld | Type | Vereist | Doel |
|---|---|---|---|
| `sourceAdministrationId` | string | Ja | FK naar bron-administratie |
| `sourceJournalEntryId` | string | Ja | FK naar bron-journaalpost (T1) |
| `sourceLineNumber` | integer | Ja | Regel-nummer in journaalpost |
| `bookingDate` | date | Ja | Boekingsdatum |
| `glAccount` | string | Ja | Grootboekrekening in bron |
| `debitAmount` | number | Nee | Debet-bedrag |
| `creditAmount` | number | Nee | Credit-bedrag |
| `currency` | string | Ja | Valuta (ISO 4217) |
| `description` | string | Nee | Omschrijving uit journaalpost |
| `counterpartyEntityId` | string | Nee | FK naar gedetecteerde/aangeduide counterparty-entiteit |
| `relationId` | string | Nee | FK naar bijbehorende `IntercompanyRelation` |
| `detectionMethod` | enum | Ja | account-based, label-based, explicitly-marked |
| `detectionConfidence` | enum | Ja | high, medium, low |
| `isMatched` | boolean | Nee (default false) | Of deze transactie gematched is |
| `matchId` | string | Nee | FK naar `IntercompanyMatch` als gematched |
| `administrationId` | string | Ja | FK naar Administration |

Schema.org annotation: `schema:FinancialProduct` (transactie).

#### Scenario: Account-based detectie (hoge zekerheid)

- **GIVEN** Werk BV boekt een verkoopfactuur €100.000 op rekening "8200 - IC-omzet" met debiteur "Holding BV"
- **WHEN** de scan loopt
- **THEN** het systeem detecteert deze als intercompany-transactie, identificeert Holding BV als counterparty (op basis van debiteur-naam-match), koppelt aan de relevante `IntercompanyRelation`, en registreert een `IntercompanyTransaction` met confidence **high** en detectionMethod **account-based**.

#### Scenario: Label-based detectie (gemiddelde zekerheid)

- **GIVEN** een transactie zit op een gewone debiteur-rekening (1300) maar de debiteur-naam is "Holding BV"
- **WHEN** de scan loopt
- **WHEN** geen IC-rekening-match gevonden maar naam-match naar groep-entiteit
- **THEN** het systeem markeert detectionConfidence **medium**, detectionMethod **label-based**, en plaatst deze in een review-queue voor expliciete bevestiging door de gebruiker (zonder bevestiging wordt deze niet gebruikt voor matching).

#### Scenario: Expliciete markering (hoge zekerheid)

- **GIVEN** gebruiker markeert een transactie expliciet als "intercompany" in de boeking-interface
- **WHEN** scan loopt
- **THEN** het systeem registreert detectionMethod **explicitly-marked**, detectionConfidence **high**, en bepaalt counterpartyEntityId + relationId indien aanwezig.

### REQ-ICE-003: Periodieke matching auto-run

The system SHALL satisfy this requirement: Periodieke matching auto-run.

Het systeem MOET periodiek (per maand, kwartaal of jaareinde, configureerbaar) automatisch alle intercompany-transacties van een periode matchen door per `IntercompanyRelation` de A-zijde te aggregeren, de B-zijde te aggregeren, en het netto-saldo te bepalen via `x-openregister-aggregations`.

`IntercompanyMatch` MOET als persistente register gedeclareerd worden met:

| Veld | Type | Vereist | Doel |
|---|---|---|---|
| `matchId` | string | Ja | Unieke match-identifier |
| `periodId` | string | Ja | FK naar consolidatie-periode |
| `relationId` | string | Ja | FK naar `IntercompanyRelation` |
| `entityATransactionIds` | array | Ja | Lijst van `IntercompanyTransaction` UUID's aan A-zijde |
| `entityBTransactionIds` | array | Ja | Lijst van `IntercompanyTransaction` UUID's aan B-zijde |
| `totalAmountA` | number | Ja | Totaal-bedrag A-zijde |
| `totalAmountB` | number | Ja | Totaal-bedrag B-zijde |
| `mismatchAmount` | number | Nee | Delta (bedragA − bedragB) |
| `mismatchPercentage` | number | Nee | Relatief verschil in % |
| `matchStatus` | enum | Ja | perfect-match, within-tolerance, outside-tolerance, one-sided-A, one-sided-B |
| `generatedEliminationId` | string | Nee | FK naar `EliminationJournal` als eliminatie gegenereerd |
| `administrationId` | string | Ja | FK naar Administration |

Schema.org annotation: `schema:Thing` (match-record).

#### Scenario: Perfecte match (delta = €0)

- **GIVEN** periode januari-2025, IC-relatie "Werk BV → Holding BV sales-of-services" met aan Werk BV-zijde 3 verkoopfacturen totaal €100.000 en aan Holding BV-zijde 3 inkoopfacturen totaal €100.000
- **WHEN** matching loopt
- **THEN** het systeem aggregeert beide kanten, detecteert perfecte match (delta = €0), maakt een `IntercompanyMatch` met status **perfect-match** aan, en markeert alle 6 transacties als gematched.

#### Scenario: Mismatch binnen tolerantie

- **GIVEN** dezelfde periode maar Holding BV heeft slechts €75.000 ingeboekt (€25.000 nog niet door vakantie-achterstand)
- **WHEN** matching loopt
- **WHEN** mismatchAmount = €25.000, mismatchPercentage = 25%
- **THEN** het systeem detecteert mismatch, maakt een `IntercompanyMatch` aan, evalueert tolerantie (zie REQ-ICE-004), en bepaalt matchStatus.

#### Scenario: Re-match na correctie

- **GIVEN** gebruiker voert een correctie door in een bron-administratie
- **WHEN** matching opnieuw (re-match) voor dezelfde periode
- **THEN** het systeem ongedaan maakt eerder gegenereerde matches (mits niet definitief goedgekeurd), her-matched op basis van actuele bron-data, en update alle exception-queue items.

### REQ-ICE-004: Tolerantie-gebaseerde auto-resolve

The system SHALL satisfy this requirement: Tolerantie-gebaseerde auto-resolve.

Het systeem MOET configureerbare tolerantie-regels toepassen op gedetecteerde mismatches via `x-openregister-lifecycle.requires` guards. Mismatches binnen tolerantie worden automatisch geaccepteerd, mismatches buiten tolerantie blijven in de exception-queue voor handmatige resolutie.

`ToleranceRule` MOET als persistente register gedeclareerd worden met:

| Veld | Type | Vereist | Doel |
|---|---|---|---|
| `ruleId` | string | Ja | Unieke rule-identifier |
| `groupId` | string | Ja | FK naar `ConsolidationGroup` (global rule) |
| `relationTypeFilter` | enum | Nee | Relatie-type (null = all types) |
| `toleranceAbsolute` | number | Ja | Absoluut verschil-drempel in EUR |
| `toleranceRelative` | number | Ja | Relatief verschil-drempel in % |
| `toleranceMethod` | enum | Ja | max-of-absolute-relative (één moet passen), min-of-absolute-relative (beide moeten passen), absolute-only |
| `fallbackAccount` | string | Ja | GL-rekening voor restpost (bijv. "9999 - Eliminatie-rounding-restpost") |
| `autoResolve` | boolean | Ja (default true) | Of binnen-tolerantie automatisch geaccepteerd wordt |
| `administrationId` | string | Ja | FK naar Administration |

Schema.org annotation: `schema:Thing` (rule).

#### Scenario: Binnen-tolerantie auto-resolve

- **GIVEN** IC-relatie met toleranceAbsolute €10, toleranceRelative 0.5%, en een match met mismatchAmount €7 op totaal €100.000 (0.007%)
- **WHEN** matching loopt
- **WHEN** beide tolerantie-checks passen
- **THEN** match wordt gestempeld **within-tolerance**, eliminatie wordt gegenereerd voor het gemeenschappelijke deel (€99.993), en de €7 mismatch wordt geboekt naar de fallback-rekening (restpost).

#### Scenario: Buiten-tolerantie (manual queue)

- **GIVEN** dezelfde relatie maar mismatchAmount is €15 (>€10 absoluut)
- **WHEN** matching loopt
- **THEN** match wordt gestempeld **outside-tolerance**, eliminatie wordt NIET automatisch gegenereerd, matchStatus = **outside-tolerance**, en de mismatch komt in de exception-queue voor handmatige resolutie.

#### Scenario: Periode-specifieke tolerantie-override

- **GIVEN** gebruiker wil een tolerantie-regel tijdelijk verlagen voor jaareinde (strenger)
- **WHEN** ze een override-rule aanmaken voor periode december met toleranceAbsolute €1, toleranceRelative 0.1%
- **THEN** periode-specifieke rule overschrijft de default; matching voor december gebruikt de strengere tolerantie.

### REQ-ICE-005: Mismatch-classificatie en resolutie

The system SHALL satisfy this requirement: Mismatch-classificatie en resolutie.

Het systeem MOET een gebruiker in staat stellen mismatches te classificeren op oorzaak, en per classificatie een semi-geautomatiseerde resolutie-pad aan te bieden.

`IntercompanyMismatch` MOET als persistente register gedeclareerd worden met:

| Veld | Type | Vereist | Doel |
|---|---|---|---|
| `mismatchId` | string | Ja | Unieke identifier |
| `periodId` | string | Ja | FK naar consolidatie-periode |
| `relationId` | string | Ja | FK naar `IntercompanyRelation` |
| `matchId` | string | Nee | FK naar `IntercompanyMatch` (indien onderdeel van match) |
| `causeClassification` | enum | Ja | timing-difference, fx-translation, transfer-pricing-adjustment, missing-booking, classification-error, unknown |
| `amount` | number | Ja | Mismatch-bedrag in EUR of bron-valuta |
| `currency` | string | Ja | Valuta van het mismatch-bedrag |
| `description` | string | Ja | Samenvatting van de discrepantie |
| `status` | enum | Ja (default "open") | open, investigating, resolved, accepted-as-difference |
| `assigneeId` | string | Nee | FK naar User (wie onderzoekt) |
| `resolutionAction` | enum | Nee | manual-gl-correction, interim-elimination-with-reversal, post-to-cta, source-correction-booking-wizard, accept-as-difference |
| `resolutionNotes` | string | Nee | Toelichting op gekozen resolutie |
| `administrationId` | string | Ja | FK naar Administration |

Schema.org annotation: `schema:Thing` (exception-record).

#### Scenario: Timing-difference resolutie

- **GIVEN** mismatchAmount €25.000, oorzaak "timing-difference" (Werk BV factureerde per 31 dec, Holding BV ontving pas 5 jan)
- **WHEN** gebruiker classificeert als timing-difference en kiest resolutie-actie **interim-elimination-with-reversal**
- **THEN** het systeem genereert een eliminatie-journaalpost voor €25.000 in december (debet IC-omzet, credit transitorische post), en plant een tegenboeking voor januari (debet transitorische post, credit IC-inkopen), zodat het over twee periodes consistent verloopt. Status → **resolved**.

#### Scenario: FX-translation resolutie

- **GIVEN** mismatchAmount €1.200 (USD 1.293 vs EUR 1.200), oorzaak "fx-translation"
- **WHEN** gebruiker classificeert als fx-translation
- **THEN** het systeem boekt het verschil naar de FX-translation-restpost (CTA-component in eigen vermogen) in plaats van naar resultaat, documenteert de koersen, en markeert als **resolved**.

#### Scenario: Transfer-pricing correctie-wizard

- **GIVEN** mismatchAmount €500, oorzaak "transfer-pricing-adjustment" (correctie nog niet doorgevoerd in bron)
- **WHEN** gebruiker kiest resolutie-actie **source-correction-booking-wizard**
- **THEN** het systeem opent wizard om de ontbrekende correctie-boeking in de bron-administratie te genereren, toont voorgestelde journaal ter bevestiging, en na bevestiging wordt de boeking in de bron geplaatst. Re-match loopt daarna automatisch.

### REQ-ICE-006: Eliminatie-journaalpost generatie

The system SHALL satisfy this requirement: Eliminatie-journaalpost generatie.

Het systeem MOET voor elke geslaagde match (perfecte match of binnen-tolerantie) automatisch een eliminatie-journaalpost genereren in de consolidatie-laag — niet in de bron-administraties — met de juiste debet/credit-regels per grootboekrekening en verwijzing naar de match en bron-transacties.

`EliminationJournal` MOET als persistente register gedeclareerd worden met:

| Veld | Type | Vereist | Doel |
|---|---|---|---|
| `eliminationId` | string | Ja | Unieke journaal-identifier |
| `consolidationPeriodId` | string | Ja | FK naar consolidatie-periode |
| `matchId` | string | Ja | FK naar triggerende `IntercompanyMatch` |
| `bookingDate` | date | Ja | Journaal-post-datum |
| `description` | string | Ja | Omschrijving (bijv. "IC-eliminatie Werk BV - Holding BV sales-of-services") |
| `lines` | array | Ja | Array van {glAccount, debitAmount, creditAmount, description} |
| `totalDebit` | number | Ja | Totaal debet (moet gelijk zijn aan totalCredit) |
| `totalCredit` | number | Ja | Totaal credit |
| `generatedBy` | enum | Ja | system (auto-generated), operator (manual) |
| `approvedBy` | string | Nee | FK naar User (goedgekeurd door) |
| `approvedAt` | datetime | Nee | Wanneer goedgekeurd |
| `administrationId` | string | Ja | FK naar Administration |

Schema.org annotation: `schema:Thing` (journal-entry).

#### Scenario: Omzet-eliminatie

- **GIVEN** match Werk BV-omzet €100.000 ↔ Holding BV-inkopen €100.000, beide high-confidence
- **WHEN** eliminatie wordt gegenereerd
- **THEN** het systeem maakt eliminatie-journaalpost: debet "8200 - IC-omzet Werk BV" €100k, credit "4400 - IC-inkopen Holding BV" €100k. Journaal gelinkt aan match, regels traceerbaar naar bron-transacties.

#### Scenario: Vorderingen/schulden-eliminatie

- **GIVEN** match van intercompany-vorderingen en -schulden €25.000
- **WHEN** eliminatie wordt gegenereerd
- **THEN** journaalpost: debet "1610 - Schuld aan Werk BV (Holding BV)" €25k, credit "1310 - Vordering op Holding BV (Werk BV)" €25k. Geconsolideerde balans toont na eliminatie geen intercompany-saldo.

#### Scenario: Lening met rente-eliminatie

- **GIVEN** match van intercompany-lening (hoofdsom €500k, rente €25k)
- **WHEN** eliminatie wordt gegenereerd
- **THEN** twee separate journaalposten: één voor hoofdsom (vordering vs schuld), één voor rente (rentebaten vs rentelasten). Beide gelinkt aan dezelfde match.

### REQ-ICE-007: Counterparty-saldo overzicht

The system SHALL satisfy this requirement: Counterparty-saldo overzicht.

Het systeem MOET per intercompany-paartje (twee groepsentiteiten) per consolidatie-periode een geaggregeerd overzicht bieden van alle openstaande saldi, stromen, en mismatches.

`CounterpartyBalance` MOET als persistente aggregatie-register gedeclareerd worden met:

| Veld | Type | Vereist | Doel |
|---|---|---|---|
| `balanceId` | string | Ja | Unieke identifier per pairtje + periode |
| `groupId` | string | Ja | FK naar `ConsolidationGroup` |
| `entityAId` | string | Ja | FK naar entiteit A |
| `entityBId` | string | Ja | FK naar entiteit B |
| `periodId` | string | Ja | FK naar consolidatie-periode |
| `totalReceivablesAonB` | number | Ja | Totaal vorderingen A op B (uitstaand) |
| `totalPayablesAtoB` | number | Ja | Totaal schulden A aan B (uitstaand) |
| `netPositionAtoB` | number | Ja | Netto-positie A t.o.v. B (receivables − payables) |
| `totalSalesAtoB` | number | Nee | Totale omzet A → B in periode |
| `totalPurchasesAtoB` | number | Nee | Totale inkopen A van B in periode |
| `transactionCount` | integer | Nee | Aantal IC-transacties in periode |
| `mismatchCount` | integer | Nee | Aantal gemelde mismatches in periode |
| `lastUpdated` | datetime | Ja | Laatste update-timestamp |
| `administrationId` | string | Ja | FK naar Administration |

Schema.org annotation: `schema:FinancialProduct` (balance-view).

#### Scenario: Counterparty-view openen

- **GIVEN** consolidatie-groep Acme Group met IC-relaties Werk BV ↔ Holding BV, Vastgoed BV ↔ Holding BV
- **WHEN** ik de "Counterparty View" voor Werk BV ↔ Holding BV open
- **THEN** het systeem toont: huidige openstaande vorderingen €X, schulden €Y, netto-positie €Z, totale omzet-stroom in periode €A, totale inkoop-stroom €B, aantal mismatches N. Per zijde drilldown naar individuele bron-transacties.

#### Scenario: Collaborative exception-resolution

- **GIVEN** beide controllers (van Werk BV en Holding BV) bekijken dezelfde counterparty-view
- **WHEN** er zich een mismatch voordoet
- **THEN** beide zien hetzelfde scherm, kunnen comments achterlaten direct in-context (per mismatch een discussiethread), zien wie wat wanneer ge-edit heeft (audit-trail).

### REQ-ICE-008: Cross-period roll-forward en historische audit

The system SHALL satisfy this requirement: Cross-period roll-forward en historische audit.

Het systeem MOET intercompany-saldi van periode tot periode roll-forward consistentie bewaken. Bij wijziging van vorige periodes MOET het systeem backdated-impact detecteren en cascading-impact wizard aanbieden.

#### Scenario: Roll-forward verificatie

- **GIVEN** matching voor Q1-2025 is definitief, eindstand intercompany-vordering Werk BV op Holding BV = €15.000
- **WHEN** Q2-2025 matching start
- **THEN** beginstand Q2 = €15.000 wordt geverifieerd tegen de openingsbalans van Werk BV in Q2; bij afwijking wordt een alert gegenereerd.

#### Scenario: Cascading impact detectie

- **GIVEN** er wordt een correctie geboekt op een Q1-transactie nadat Q2 al gematched is
- **WHEN** het systeem deze backdated wijziging detecteert
- **THEN** het systeem alarmeert dat Q1 en Q2 herberekend moeten worden, blokkeert verdere matching, en biedt wizard om cascade-impact te beheren (re-run Q1, propagate to Q2, etc.).

### REQ-ICE-009: Multi-currency intercompany matching

The system SHALL satisfy this requirement: Multi-currency intercompany matching.

Het systeem MOET intercompany-transacties tussen entiteiten met verschillende functionele valuta correct matchen door beide zijden naar gemeenschappelijke rapportage-valuta te converteren, en translatie-verschillen als CTA (niet als P&L-verschil) boeken.

#### Scenario: EUR-USD matching op transactie-datum-koers

- **GIVEN** Werk BV (EUR) heeft €100.000 verkocht aan US-Dochter (USD); US-Dochter heeft USD 108.500 ingeboekt
- **WHEN** matching loopt met EUR als rapportage-valuta en transactie-datum-koers USD/EUR = 0,921
- **THEN** het systeem vertaalt USD 108.500 naar EUR (€99.928), vergelijkt met €100.000, mismatchAmount €72 wordt geclassificeerd als **fx-translation** (te klein voor transfer-pricing), en geboekt naar CTA-restpost.

#### Scenario: Balansdatum-koers-update

- **GIVEN** intercompany-saldo op balansdatum: Werk BV heeft EUR-vordering €25.000 op US-Dochter, US-Dochter heeft USD-schuld geboekt USD 27.100 (oude koers)
- **WHEN** matching op balansdatum loopt met slotkoers USD/EUR = 0,925
- **THEN** US-zijde wordt vertaald naar EUR (USD 27.100 × 0,925 = €25.067), mismatchAmount €67 wordt als **fx-translation** gevangen en naar CTA-EV-restpost geboekt per IFRS 21.

### REQ-ICE-010: Performance en schaalbaarheid

The system SHALL satisfy this requirement: Performance en schaalbaarheid.

Het systeem MOET matching kunnen uitvoeren voor groepen tot 50 entiteiten en 100.000 intercompany-transacties per periode binnen acceptabele tijd (target: <5 minuten voor typische maand-matching van 10-entiteiten-groep met 5.000 IC-transacties).

#### Scenario: Maand-matching performance

- **GIVEN** consolidatie-groep met 12 entiteiten en gemiddeld 4.000 IC-transacties per maand
- **WHEN** ik full matching voor januari draai
- **THEN** het systeem voltooit in minder dan 5 minuten op standaard productie-hardware (4 vCPU, 8GB RAM).

#### Scenario: Incremental re-match na correctie

- **GIVEN** januari-matching is gedaan, nu correctie in één transactie
- **WHEN** incremental re-match aanvraag
- **THEN** het systeem detecteert wijzigde transacties, herberekent alleen relevante matches, voltooit in <30 seconden voor typische delta van 50-100 transacties.

#### Scenario: Large-group parallel matching

- **GIVEN** consolidatie-groep met 40 entiteiten, 60.000 transacties per maand
- **WHEN** full matching aanvraag
- **THEN** systeem partitioneert werk per IC-relatie, draait parallel waar mogelijk; voltooit in <30 minuten met real-time voortgang.

## Standards & Sources

- **Burgerlijk Wetboek Boek 2 art. 2:406** — verplichting tot consolidatie en eliminatie interne transacties
- **RJ 217.301-307** — eliminatie van intercompany sales, vorderingen/schulden, dividenden, ongerealiseerde tussenwinsten
- **IAS 27.18** + **IFRS 10.B86** — IFRS-eisen eliminatie IC-balansen, transacties, baten, lasten
- **OESO Transfer Pricing Guidelines** — IC-pricing conformiteit tussen entiteiten
- **NBA-handreiking 1118** — accountantsvereisten controle IC-eliminaties
- **IFRS 21 / NL-BW** — FX-translation-verschillen naar CTA (eigen vermogen)
