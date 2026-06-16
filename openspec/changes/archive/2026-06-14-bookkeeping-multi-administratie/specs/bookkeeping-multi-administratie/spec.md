# Spec: bookkeeping-multi-administratie

**Status:** proposed
**Scope:** shillinq
**Tier:** T1 (foundational refactor)
**Depends on:** none (foundational; blocks all T1–T4 downstream specs)

## ADDED Requirements

### Requirement: REQ-MA-001: Administratie-isolatie van alle financiële data

Geen enkele financiële entiteit mag zonder `administratie`-veld bestaan; queries
respecteren altijd de actieve administratie-context.

#### Scenario: User in context WERK-001 queries journaalposten

- **GIVEN** een gebruiker met toegang tot administraties WERK-001 en BEHEER-001
- **WHEN** deze in context WERK-001 een journaalpost-query doet (filter by
  administrationId=WERK-001)
- **THEN** ziet deze uitsluitend journaalposten van WERK-001 en geen enkele van
  BEHEER-001; query result set is empty if administrationId is not in the active
  user's accessible administraties.

#### Scenario: Masked 404 on unauthorized administratie access

- **GIVEN** een gebruiker zonder toegang tot administratie PRIVAAT-001
- **WHEN** deze een ID van een journaalpost van PRIVAAT-001 probeert te benaderen
  (e.g., GET /journaalposten/uuid-from-PRIVAAT-001)
- **THEN** ontvangt deze een 404 Not Found (geen 403 Forbidden, om bestaan
  administratie en data te maskeren).

#### Scenario: Validation failure without administratie FK

- **GIVEN** een poging een journaalpost te boeken zonder geldig `administratie`-veld
  (null, empty, or non-existent UUID)
- **WHEN** opgeslagen via API
- **THEN** faalt validatie met duidelijke foutmelding: "Administratie is required"
  (or localized equivalent).

### Requirement: REQ-MA-002: Per-administratie rekeningschema en boekjaar

Iedere administratie heeft een eigen chart-of-accounts en eigen boekjaar-cyclus,
onafhankelijk van andere administraties.

#### Scenario: Independent year-end closings

- **GIVEN** administratie WERK-001 met boekjaar jan-dec en BEHEER-001 met afwijkend
  boekjaar jul-jun
- **WHEN** beide hun jaarafsluiting plannen (WERK-001: 2026-12-31, BEHEER-001:
  2026-06-30)
- **THEN** voert elk dit op het eigen tijdstip uit zonder elkaar te raken; closing
  transactions (afsluitingsboeken) do not interact; GL balances are computed
  per-administratie.

#### Scenario: Schema validation per administratie

- **GIVEN** administratie A op chart-of-accounts "RGS 3.5" (Account 1000–9999) en
  administratie B op een custom schema (Account 1000–7999)
- **WHEN** journaalposten worden geboekt in A (referencing account 8500) en in B
  (same account number)
- **THEN** A accepts 8500 (exists in RGS), B rejects 8500 (does not exist in custom
  schema) with validation error.

#### Scenario: Template-based administratie creation

- **GIVEN** een organisatie wil een nieuw rekeningschema toevoegen voor een nieuwe
  BV
- **WHEN** de administratie wordt aangemaakt en template "RGS-standaard" wordt
  geselecteerd
- **THEN** kan een klaar-gemaakt RGS-schema (with standard GL accounts 1000–9999)
  worden toegewezen of een bestaande administratie als template worden gebruikt
  (schema copied).

### Requirement: REQ-MA-003: Multi-tenant gebruikersrechten met administratie-switcher

Een gebruiker kan tot meerdere administraties toegang hebben met verschillende rollen
per administratie. In-session switching is mogelijk zonder re-login.

#### Scenario: Administratie-switcher UI available to multi-access user

- **GIVEN** een controller met rol `controller` in WERK-001 en `inkijker` in
  BEHEER-001
- **WHEN** deze inlogt
- **THEN** ziet deze in de UI een switcher (dropdown or pill-bar) met beide
  administraties en kan binnen één sessie wisselen zonder opnieuw in te loggen;
  session context updates; all subsequent queries filter by new administratie.

#### Scenario: Role-based access control per administratie

- **GIVEN** dezelfde controller actief in WERK-001 met rol `controller`
- **WHEN** deze een journaalpost wil boeken
- **THEN** lukt dit (permission mag_journaalposten_boeken=true in this administratie)
- **WHEN** deze in BEHEER-001 (rol `inkijker`) een journaalpost wil boeken
- **THEN** faalt dit met "Onvoldoende rechten in deze administratie" (permission
  mag_journaalposten_boeken=false for role inkijker).

#### Scenario: Bulk user assignment for accounting firm

- **GIVEN** een accountantskantoor met 50 klanten
- **WHEN** een nieuwe medewerker toegang krijgt tot 12 specifieke klanten
- **THEN** worden 12 `AdministratielidMaatschap`-records aangemaakt met de
  bijbehorende rol (e.g. `boekhouder`); user can now access all 12 in one session
  and switch between them.

### Requirement: REQ-MA-004: Intercompany-journaalpost met sluitende boeking aan beide kanten

Een intercompany-boeking moet automatisch in beide administraties dezelfde mutatie
spiegelen.

#### Scenario: Automatic mirrored posting on intercompany creation

- **GIVEN** administratie WERK boekt een management fee van 25.000 EUR aan BEHEER
  (journaalpost in WERK: Dr. 6300 / Cr. 2000)
- **WHEN** de intercompany-journaalpost wordt aangemaakt (marked
  `doel_administratie=BEHEER`)
- **THEN** ontstaan twee journaalposten — één in WERK (kosten + crediteur BEHEER)
  en één in BEHEER (omzet + debiteur WERK) — gekoppeld via `intercompany_nummer`;
  both posts created with status `concept`.

#### Scenario: Status tracking and reconciliation

- **GIVEN** de tegenkant nog niet bevestigd is (status=`concept`)
- **WHEN** één van de twee partijen de boeking wijzigt (amount, account)
- **THEN** gaat de status terug naar `concept` en moet de tegenkant opnieuw
  bevestigen (or the receiving side rejects the reconciliation).
- **WHEN** een intercompany-stand waarbij de saldi tussen WERK en BEHEER per
  balansdatum 100 EUR afwijken
- **THEN** toont het reconciliatie-rapport het verschil met onderliggende posten
  (`afwijking_bedrag` field) en biedt een correctievoorstel (manual review workflow).

### Requirement: REQ-MA-005: Consolidatie-mapping en eliminatie-hooks

Iedere dochteradministratie kan haar grootboekrekeningen mappen naar de
moederrekeningen voor consolidatiedoeleinden, met eliminatie van intercompany-mutaties.

#### Scenario: Account mapping for consolidation export

- **GIVEN** een mapping waarin rekeningen 4310 en 4311 van WERK beide naar 4300 in
  BEHEER worden geconsolideerd (ConsolidatieMapping rule: 4310→4300, 4311→4300)
- **WHEN** een consolidatie-export draait (consumed by future `bookkeeping-consolidatie`
  spec)
- **THEN** worden alle journaalposten op 4310/4311 in WERK opgeteld op 4300 in de
  geconsolideerde rapportage.

#### Scenario: Intercompany elimination on consolidation

- **GIVEN** een intercompany-stroom van 25.000 EUR met `geconsolideerd_elimineren=true`
  (both income in BEHEER 8200 and expense in WERK 6300)
- **WHEN** consolidatie wordt gegenereerd
- **THEN** worden zowel de omzet bij BEHEER (8200) als de kosten bij WERK (6300) uit
  de geconsolideerde resultatenrekening verwijderd (zero-ed out via
  `eliminatie_rekening`).

#### Scenario: Multi-currency consolidation with FX reserve

- **GIVEN** een dochter in vreemde valuta (USD met EUR-functionele valuta)
- **WHEN** consolidatie wordt gegenereerd
- **THEN** worden balansposten tegen slotkoers (end-of-period rate) omgerekend,
  P&L tegen gemiddelde koers (per ConsolidatieMapping.valutaomrekening_methode),
  en wordt het wisselkoersverschil (unrealized FX gain/loss) op een aparte reserve
  gepresenteerd.

### Requirement: REQ-MA-006: Migratie tussen administraties (asset transfer)

Een vast actief, een contract of een werknemer moet van administratie A naar
administratie B kunnen worden overgedragen met behoud van historie.

#### Scenario: Fixed asset transfer with P&L impact

- **GIVEN** een vast actief in WERK-001 met boekwaarde 87.000 EUR
- **WHEN** deze wordt overgedragen aan WERK-002 met overdrachtswaarde 92.000 EUR
- **THEN** ontstaan: (1) een desinvesteringsboeking in WERK-001 met 5.000 EUR
  boekwinst (Dr. cash / Cr. fixed asset + gain), (2) een nieuwe activering in
  WERK-002 voor 92.000 EUR (Dr. fixed asset / Cr. equity), (3) een
  `AdministratieMigratie`-record dat beide journaalposten koppelt en de juridische
  grondslag (notariële akte) vastlegt.

#### Scenario: Employee transfer with accrued reserves

- **GIVEN** een werknemer wordt per 1 september overgedragen van WERK-001 naar
  WERK-002
- **WHEN** de migratie wordt verwerkt (status voorbereid → geboekt_beide)
- **THEN** wordt het arbeidscontract afgesloten in WERK-001 (met vertrekboeking van
  accrued vakantiegeld/13e-maand reserves), aangemaakt in WERK-002 (met
  instapboeking voor opgebouwde reserveringen vakantiegeld/13e maand van WERK-001),
  en wordt de payroll-verplichting overgenomen; both administraties show consistent
  employee GL balances.

#### Scenario: Migration rollback

- **GIVEN** een migratie wordt geannuleerd voordat beide kanten zijn geboekt (status
  = voorbereid)
- **WHEN** teruggedraaid (status → teruggedraaid)
- **THEN** wordt mutatie ongedaan gemaakt en blijven beide administraties in
  oorspronkelijke staat; draft journaalposten are discarded.

### Requirement: REQ-MA-007: Per-administratie backup en data-export

Iedere administratie moet onafhankelijk geback-upt en geëxporteerd kunnen worden.

#### Scenario: Full administratie export in Auditfile XAF

- **GIVEN** een accountant wil de jaarcijfers van klant WERK-001 over 2026 archiveren
- **WHEN** deze een full-export aanvraagt (via UI or API: administratie-scoped)
- **THEN** ontvangt deze een ZIP met alle journaalposten, balansen, jaarrekening en
  attached documents van uitsluitend WERK-001 in gestandaardiseerd Auditfile
  XAF-formaat; data isolation is enforced (no cross-administratie data in export).

#### Scenario: Administratie archival and data retention

- **GIVEN** een klant zegt het contract op
- **WHEN** diens administratie wordt geëxporteerd en gearchiveerd
- **THEN** gaat de administratie naar status `gearchiveerd` (transitioned from
  `actief`) en zijn alle data nog 7 jaar (wettelijke bewaartermijn per
  Algemene Wet inzake Rijksbelastingen art. 52) raadpleegbaar voor inzage maar niet
  meer muteerbaar; queries on archived administratie return read-only mode; writes
  fail.

#### Scenario: Per-administratie incremental backup

- **GIVEN** backup-schema `dagelijks` voor administratie WERK-001
- **WHEN** het backup-tijdvenster wordt bereikt
- **THEN** wordt slechts WERK-001 geback-upt zonder andere administraties te raken;
  backup is incremental (delta from last backup); other administraties' backup
  schedules are independent.

### Requirement: REQ-MA-008: Fiscale eenheid VPB en BTW

Administraties die fiscaal in één eenheid zitten moeten dit kunnen aanduiden voor
correcte BTW- en VPB-rapportage.

#### Scenario: VPB fiscal unit consolidation

- **GIVEN** administraties WERK en BEHEER zitten in fiscale eenheid VPB (linked via
  Administratie.fiscale_eenheid_vpb = BEHEER-uuid)
- **WHEN** de VPB-aangifte wordt gegenereerd (by future `bookkeeping-tax-filing` spec)
- **THEN** wordt slechts één aangifte gemaakt op naam van BEHEER met geconsolideerd
  resultaat; this spec provides the data structure (FK references); downstream specs
  implement the consolidation logic.

#### Scenario: BTW fiscal unit (no intercompany VAT)

- **GIVEN** administraties WERK en BEHEER in fiscale eenheid BTW (linked via
  Administratie.fiscale_eenheid_btw = BEHEER-uuid)
- **WHEN** intercompany-facturen worden gemaakt
- **THEN** wordt automatisch BTW-behandeling `fiscale_eenheid_geen_btw` toegepast op
  IntercompanyJournaalpost (marked in btw_behandeling field); slechts één
  BTW-aangifte wordt ingediend (logic deferred to `bookkeeping-vat-return` spec).

#### Scenario: Fiscal unit change mid-year

- **GIVEN** een administratie verlaat de fiscale eenheid per 1 juli
- **WHEN** de wijziging wordt geregistreerd (Administratie.fiscale_eenheid_vpb set
  to null, effective date = 2026-07-01)
- **THEN** wordt vanaf die datum normale BTW toegepast op intercompany-stromen (prior
  to 2026-07-01: no VAT; after: standard VAT); downstream tax-filing spec generates
  a correction proposal for the partial year.

### Requirement: REQ-MA-009: Per-administratie audit-trail met cross-administratie viewer

Audit-logs zijn per administratie, maar gebruikers met multi-administratie-toegang
kunnen cross-administratie rapportages opvragen.

#### Scenario: Audit query filtered by active administratie

- **GIVEN** een audit-vraag "alle journaalposten geboekt door gebruiker X over Q1 2026"
- **WHEN** deze in administratie WERK-001 wordt gesteld (filtered by
  administrationId=WERK-001)
- **THEN** levert het systeem alleen posten uit WERK-001; cross-administratie results
  are excluded even if user has access elsewhere.

#### Scenario: Consolidated audit query for holding-controller

- **GIVEN** dezelfde vraag door een holding-controller met toegang tot WERK-001,
  WERK-002 en BEHEER-001
- **WHEN** deze opvraagt vanuit "consolidatie-view" (unified query across all
  accessible administraties)
- **THEN** levert het systeem gecombineerde resultaten met expliciete administratie-
  kolom (Administratie | Date | User | Journal Entry | Amount); results are grouped
  or flagged per administratie for clarity.

### Requirement: REQ-MA-010: Administratie-aanmaak via wizard met template-overname

Het aanmaken van een nieuwe administratie moet binnen 5 minuten kunnen met sensibele
defaults via wizard.

#### Scenario: KvK pre-fill on new administratie

- **GIVEN** een accountant maakt een nieuwe BV-administratie aan via wizard
- **WHEN** deze KvK-nummer invult (e.g., "12345678")
- **THEN** haalt het systeem via KvK-koppeling (OpenConnector, deferred to impl)
  rechtsvorm, naam, adres en RSIN op en pre-vult deze velden; user confirms and
  adjusts as needed.

#### Scenario: Template selection for boekklaar setup

- **GIVEN** de wizard biedt template-keuze (e.g. "BV met loonadministratie en BTW
  maandaangifte")
- **WHEN** dit wordt gekozen
- **THEN** worden standaard chart-of-accounts (RGS 3.5), boekjaar (kalenderjaar),
  BTW-frequentie (maand), loonheffingsnummer-veld, and default backup-schema
  (dagelijks) aangezet, en is de administratie direct boekklaar; wizard completes
  in ~3 minutes.

#### Scenario: Holding-werkmij template chain

- **GIVEN** een holding wordt aangemaakt met al een bestaande werkmaatschappij in het
  systeem
- **WHEN** de holding wordt aangemaakt (wizard: rechtsvorm "bv", naam "Beheer B.V.")
- **THEN** biedt de wizard direct de koppeling (select existing WERK-administratie
  as dochter; Administratie.moederadministratie is set to holding; Administratie.dochters
  array on holding is updated); default consolidatie-mapping (1:1 chart-of-accounts
  copy) is suggested; user confirms or customizes.

## Excluded from T1 (Deferred)

The following are explicitly **not** in scope of this spec:

- **Full consolidated P&L/balance-sheet rendering** — REQ-MA-005 provides the schema
  and pre-positioning; the actual rendering is in `bookkeeping-consolidatie` spec.
- **VPB/BTW consolidation logic** — REQ-MA-008 provides the data structure
  (FK references); the rules are in `bookkeeping-vat-return` and
  `bookkeeping-tax-filing` specs.
- **KvK pre-fill automation via OpenConnector** — REQ-MA-010 anticipates it; the
  integration is deferred to implementation discovery or a separate connector spec.
- **Migration of existing single-administratie installs** — The repair step auto-
  creates a default administratie for existing data; full migration tooling is a
  separate `opsx` change.
- **Advanced fiscal unit rules** — The schema supports `fiscale_eenheid_vpb` and
  `fiscale_eenheid_btw` references; the rules (e.g., "no intercompany VAT for
  BTW-eenheid") are delegated to downstream tax-filing specs.

## Conformance

- All Administratie-family schemas are declared in `lib/Settings/shillinq_register.json`
  per ADR-024.
- All financial schemas (Journaalpost, Factuur, GrootboekRekening, Budget,
  Verplichting, VastActief, etc.) have `administratie: "uuid|ref:Administratie"`
  as a required field per ADR-031.
- All queries filter by active `administratie` context per ADR-018 data-isolation
  pattern.
- RBAC context tracks `sessionActiveAdministratie` and user's roles per administratie.
- Administratie-switcher UI component renders for users with multi-administratie
  access.
- Backup and export routines execute per-administratie, not per-installation.
