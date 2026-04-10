# Design: Core Platform

## Overview

This document describes the technical design for the Shillinq core platform: the OpenRegister schemas, frontend architecture, backend configuration, and seed data required to bootstrap the application.

## Reuse Analysis (ADR-012)

| Capability | Provider | Custom build? |
|---|---|---|
| CRUD operations | `ObjectService` + `CnIndexPage` + `CnDetailPage` | No |
| Schema-driven forms | `CnFormDialog` + `fieldsFromSchema()` | No |
| Pagination / search | `useListView` + `CnFilterBar` | No |
| Faceted filtering | `CnFacetSidebar` + `FacetBuilder` | No |
| CSV import | `CnMassImportDialog` + `ImportService` | No |
| CSV/Excel export | `CnMassExportDialog` + `ExportService` | No |
| Audit trail | `CnObjectSidebar` → `CnAuditTrailTab` (automatic) | No |
| File attachments | `CnObjectSidebar` → `CnFilesTab` | No |
| Notifications | `NotificationService` | Trigger logic only |
| Dashboard layout | `CnDashboardPage` + `CnStatsBlock` + `CnChartWidget` | Widget data fetch |
| Activity feed | `ActivityService` | No |
| OpenRegister register init | `ConfigurationService::importFromApp()` via `IRepairStep` | JSON template only |

No duplication found with existing OpenRegister services or `@conduction/nextcloud-vue` components.

---

## Data Model

All entities are OpenRegister schemas stored in `lib/Settings/shillinq_register.json`.
Schema.org vocabulary is used where an equivalent type exists.

### 1. Organization (`schema:Organization`)

Represents klanten (customers), leveranciers (suppliers), and internal legal entities.

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `naam` | string | yes | — | Officiële naam van de organisatie |
| `type` | string | yes | `klant` | `klant` \| `leverancier` \| `intern` |
| `kvkNummer` | string | no | — | KvK-nummer (8 cijfers) |
| `btwNummer` | string | no | — | BTW-identificatienummer (NL + 9 cijfers + B + 2 cijfers) |
| `ibanRekening` | string | no | — | IBAN van de primaire bankrekening |
| `email` | string | no | — | Primair e-mailadres |
| `telefoon` | string | no | — | Telefoonnummer |
| `straat` | string | no | — | Straatnaam en huisnummer |
| `postcode` | string | no | — | Postcode (bijv. 1234 AB) |
| `stad` | string | no | — | Plaatsnaam |
| `land` | string | no | `NL` | ISO 3166-1 alpha-2 landcode |
| `actief` | boolean | no | `true` | Of de organisatie actief is |
| `opmerkingen` | string | no | — | Vrije notitieruimte |

### 2. FiscalYear (`schema:Duration` — custom extension)

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `naam` | string | yes | — | Weergavenaam, bijv. "Boekjaar 2026" |
| `startdatum` | string (date) | yes | — | Eerste dag van het boekjaar (ISO 8601) |
| `einddatum` | string (date) | yes | — | Laatste dag van het boekjaar (ISO 8601) |
| `status` | string | yes | `open` | `open` \| `gesloten` \| `gearchiveerd` |
| `organisatieId` | string (relation) | no | — | OpenRegister-relatie naar Organization |
| `opmerkingen` | string | no | — | Toelichting |

### 3. Account (`schema:FinancialProduct` — chart of accounts)

One record per rekening in the chart of accounts. Supports hierarchical sub-accounts.

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `rekeningnummer` | string | yes | — | RGS-rekeningnummer (bijv. "1000", "4010") |
| `naam` | string | yes | — | Naam van de rekening |
| `type` | string | yes | — | `activa` \| `passiva` \| `opbrengsten` \| `kosten` \| `eigen-vermogen` |
| `subtype` | string | no | — | Verfijning, bijv. `vlottende-activa`, `langlopende-schulden` |
| `ouderRekeningId` | string (relation) | no | — | OpenRegister-relatie naar bovenliggende Account |
| `btwCode` | string | no | — | BTW-tarief dat op deze rekening van toepassing is (`hoog`, `laag`, `vrijgesteld`, `geen`) |
| `actief` | boolean | no | `true` | Of de rekening in gebruik is |
| `saldo` | number | no | `0` | Huidig saldo (berekend via JournalEntry-aggregatie) |

### 4. JournalEntry (`schema:MoneyTransfer`)

Double-entry bookkeeping journal entry. Each entry has debit and credit lines summing to zero.

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `datum` | string (date) | yes | — | Boekdatum (ISO 8601) |
| `omschrijving` | string | yes | — | Omschrijving van de boeking |
| `referentie` | string | no | — | Externe referentie (bijv. factuurnummer) |
| `boekjaarId` | string (relation) | yes | — | OpenRegister-relatie naar FiscalYear |
| `status` | string | yes | `concept` | `concept` \| `geboekt` \| `gearchiveerd` |
| `regels` | array | yes | — | Array van boekingsregels (zie hieronder) |
| `bronType` | string | no | — | `verkoopfactuur` \| `inkoopfactuur` \| `bankafschrift` \| `handmatig` |
| `bronId` | string (relation) | no | — | OpenRegister-relatie naar brondocument |

**Boekingsregel (inline object within `regels`):**

| Property | Type | Required | Description |
|---|---|---|---|
| `rekeningId` | string (relation) | yes | OpenRegister-relatie naar Account |
| `rekeningnummer` | string | yes | Redundante kopie voor snelle weergave |
| `omschrijving` | string | no | Regelomschrijving |
| `debetBedrag` | number | no | Debetbedrag (≥ 0) |
| `creditBedrag` | number | no | Creditbedrag (≥ 0) |
| `btwCode` | string | no | BTW-code voor deze regel |
| `btwBedrag` | number | no | BTW-bedrag voor deze regel |

### 5. SalesInvoice (`schema:Invoice`)

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `factuurnummer` | string | yes | — | Uniek factuurnummer (bijv. "2026-0042") |
| `klantId` | string (relation) | yes | — | OpenRegister-relatie naar Organization (type=klant) |
| `factuurdatum` | string (date) | yes | — | Factuurdatum (ISO 8601) |
| `vervaldatum` | string (date) | yes | — | Vervaldatum betaling (ISO 8601) |
| `status` | string | yes | `concept` | `concept` \| `verzonden` \| `gedeeltelijk-betaald` \| `betaald` \| `vervallen` \| `gecrediteerd` |
| `valuta` | string | no | `EUR` | ISO 4217 valutacode |
| `totaalExclBtw` | number | yes | — | Totaal excl. BTW |
| `totaalBtw` | number | yes | — | Totaal BTW-bedrag |
| `totaalInclBtw` | number | yes | — | Totaal incl. BTW |
| `regels` | array | yes | — | Factuurregels (omschrijving, aantal, prijs, BTW-tarief) |
| `boekjaarId` | string (relation) | yes | — | OpenRegister-relatie naar FiscalYear |
| `journaalboekingId` | string (relation) | no | — | OpenRegister-relatie naar JournalEntry na boeking |
| `opmerkingen` | string | no | — | Interne notities |
| `betalingsreferentie` | string | no | — | Kenmerk voor bankoverschrijving |

### 6. PurchaseInvoice (`schema:Invoice`)

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `leveranciersFactuurnummer` | string | yes | — | Factuurnummer van de leverancier |
| `internNummer` | string | no | — | Intern inkoopfactuurnummer |
| `leverancierId` | string (relation) | yes | — | OpenRegister-relatie naar Organization (type=leverancier) |
| `factuurdatum` | string (date) | yes | — | Factuurdatum |
| `ontvangstdatum` | string (date) | no | — | Datum ontvangst |
| `vervaldatum` | string (date) | yes | — | Vervaldatum betaling |
| `status` | string | yes | `ontvangen` | `ontvangen` \| `goedgekeurd` \| `betaald` \| `betwist` \| `geannuleerd` |
| `valuta` | string | no | `EUR` | ISO 4217 valutacode |
| `totaalExclBtw` | number | yes | — | Totaal excl. BTW |
| `totaalBtw` | number | yes | — | Totaal BTW-bedrag |
| `totaalInclBtw` | number | yes | — | Totaal incl. BTW |
| `regels` | array | yes | — | Factuurregels |
| `boekjaarId` | string (relation) | yes | — | OpenRegister-relatie naar FiscalYear |
| `inkooporderId` | string (relation) | no | — | OpenRegister-relatie naar PurchaseOrder |
| `journaalboekingId` | string (relation) | no | — | OpenRegister-relatie naar JournalEntry na boeking |
| `goedgekeurdDoor` | string | no | — | Nextcloud-gebruikersnaam van de goedkeurder |
| `goedgekeurdOp` | string (datetime) | no | — | Tijdstip van goedkeuring |

### 7. PurchaseOrder (`schema:Order`)

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `bestelnummer` | string | yes | — | Uniek bestelnummer (bijv. "INK-2026-0015") |
| `leverancierId` | string (relation) | yes | — | OpenRegister-relatie naar Organization (type=leverancier) |
| `besteldatum` | string (date) | yes | — | Datum van bestelling |
| `verwachteLeveringsdatum` | string (date) | no | — | Verwachte leverdatum |
| `status` | string | yes | `concept` | `concept` \| `verzonden` \| `bevestigd` \| `gedeeltelijk-ontvangen` \| `ontvangen` \| `gesloten` \| `geannuleerd` |
| `regels` | array | yes | — | Orderregels (artikel, hoeveelheid, prijs) |
| `totaalExclBtw` | number | yes | — | Totaal excl. BTW |
| `boekjaarId` | string (relation) | yes | — | OpenRegister-relatie naar FiscalYear |
| `opmerkingen` | string | no | — | Interne notities |
| `leveringsadres` | string | no | — | Afleveradres indien afwijkend |

### 8. Contract (`schema:Contract`)

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `referentie` | string | yes | — | Contractreferentie (bijv. "CNT-2024-007") |
| `titel` | string | yes | — | Korte titel van het contract |
| `tegenpartijId` | string (relation) | yes | — | OpenRegister-relatie naar Organization |
| `startdatum` | string (date) | yes | — | Ingangsdatum |
| `einddatum` | string (date) | no | — | Einddatum (leeg = onbepaald) |
| `opzegtermijnDagen` | integer | no | `30` | Opzegtermijn in dagen |
| `automatischVerlengen` | boolean | no | `false` | Automatisch verlengen bij afloop |
| `verlengingsperiodeMaanden` | integer | no | — | Periodelengte voor verlenging in maanden |
| `status` | string | yes | `concept` | `concept` \| `actief` \| `verlopen` \| `opgezegd` \| `onderhandeling` |
| `waarde` | number | no | — | Contractwaarde (totaal of per jaar) |
| `valuta` | string | no | `EUR` | ISO 4217 valutacode |
| `categorie` | string | no | — | Contractcategorie (bijv. `it`, `facilitair`, `personeel`) |
| `opmerkingen` | string | no | — | Interne notities |

### 9. BankStatement (`schema:BankAccount` — custom extension)

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `iban` | string | yes | — | IBAN van de bankrekening |
| `rekeninghouder` | string | yes | — | Naam van de rekeninghouder |
| `bank` | string | no | — | Naam van de bank |
| `periodeStart` | string (date) | yes | — | Eerste dag van de afschriftperiode |
| `periodeEinde` | string (date) | yes | — | Laatste dag van de afschriftperiode |
| `beginsaldo` | number | yes | — | Saldo bij aanvang van de periode |
| `eindsaldo` | number | yes | — | Saldo aan het einde van de periode |
| `transacties` | array | no | — | Lijst met transacties (datum, omschrijving, bedrag, tegenpartij) |
| `status` | string | yes | `te-verwerken` | `te-verwerken` \| `gedeeltelijk-verwerkt` \| `verwerkt` |
| `boekjaarId` | string (relation) | yes | — | OpenRegister-relatie naar FiscalYear |

### 10. Budget

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `naam` | string | yes | — | Naam van het budget |
| `boekjaarId` | string (relation) | yes | — | OpenRegister-relatie naar FiscalYear |
| `rekeningId` | string (relation) | no | — | OpenRegister-relatie naar Account |
| `periode` | string | yes | `jaar` | `jaar` \| `kwartaal` \| `maand` |
| `budgetBedrag` | number | yes | — | Gebudgetteerd bedrag |
| `werkelijkBedrag` | number | no | `0` | Werkelijk gerealiseerd bedrag (berekend) |
| `valuta` | string | no | `EUR` | ISO 4217 |
| `categorie` | string | no | — | Budgetcategorie |
| `opmerkingen` | string | no | — | Toelichting |

### 11. FinancialReport

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `type` | string | yes | — | `winst-verlies` \| `balans` \| `kasstroomoverzicht` \| `btw-aangifte` \| `iv3` \| `bbv-jaarrekening` |
| `boekjaarId` | string (relation) | yes | — | OpenRegister-relatie naar FiscalYear |
| `periodeStart` | string (date) | yes | — | Startdatum van de rapportageperiode |
| `periodeEinde` | string (date) | yes | — | Einddatum van de rapportageperiode |
| `generatiedatum` | string (datetime) | yes | — | Tijdstip van aanmaken |
| `gegenereerddoor` | string | yes | — | Nextcloud-gebruikersnaam van de aanmaker |
| `inhoud` | object | no | — | Gestructureerde rapportage-inhoud (schema-specifiek) |
| `status` | string | yes | `concept` | `concept` \| `definitief` \| `goedgekeurd` |

### 12. BBVAccount (Dutch government — Besluit Begroting en Verantwoording)

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `rekeningCode` | string | yes | — | BBV-rekeningcode |
| `omschrijving` | string | yes | — | Naam van de BBV-rekening |
| `functieCode` | string | yes | — | BBV-functiecode (bijv. "630" = Sociale werkvoorziening) |
| `programmaCode` | string | no | — | Gemeentelijk programmacode |
| `taakveldCode` | string | no | — | BBV-taakveldcode conform VNG-indeling |
| `soort` | string | yes | — | `lasten` \| `baten` |
| `boekjaarId` | string (relation) | yes | — | OpenRegister-relatie naar FiscalYear |
| `begrotingsBedrag` | number | no | `0` | Begrotingsbedrag |
| `realisatieBedrag` | number | no | `0` | Gerealiseerd bedrag |
| `accountId` | string (relation) | no | — | OpenRegister-relatie naar Account (mapping) |

### 13. SpendReport

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `naam` | string | yes | — | Naam van het uitgavenrapport |
| `boekjaarId` | string (relation) | yes | — | OpenRegister-relatie naar FiscalYear |
| `periodeStart` | string (date) | yes | — | Startdatum van de periode |
| `periodeEinde` | string (date) | yes | — | Einddatum van de periode |
| `totaalUitgaven` | number | yes | — | Totale uitgaven in de periode |
| `categorieën` | array | no | — | Uitsplitsing per categorie (naam, bedrag, percentage) |
| `generatiedatum` | string (datetime) | yes | — | Tijdstip van aanmaken |
| `status` | string | yes | `concept` | `concept` \| `definitief` |

### 14. AITask

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| `type` | string | yes | — | `obligatie-extractie` \| `redlining` \| `factuurherkenning` \| `categorie-suggestie` |
| `status` | string | yes | `wacht` | `wacht` \| `verwerken` \| `voltooid` \| `fout` |
| `entiteitType` | string | yes | — | Schema-slug van de bronentiteit (bijv. `contract`, `purchase-invoice`) |
| `entiteitId` | string (relation) | yes | — | OpenRegister-relatie naar bronobject |
| `invoer` | object | no | — | Taakinvoer (prompt, context, parameters) |
| `uitvoer` | object | no | — | Taakuitvoer (resultaat, vertrouwen, suggesties) |
| `aangemaaktOp` | string (datetime) | yes | — | Tijdstip aanmaken |
| `voltooidOp` | string (datetime) | no | — | Tijdstip voltooiing |
| `aangemaaktDoor` | string | yes | — | Nextcloud-gebruikersnaam |
| `foutmelding` | string | no | — | Foutbeschrijving bij status=fout |

---

## Frontend Architecture

### Directory Structure

```
src/
├── App.vue                          # App shell (nav + routing)
├── navigation/
│   └── MainMenu.vue                 # NcAppNavigation with domain sections
├── router/
│   └── index.js                     # Vue Router — flat named routes
├── store/
│   ├── store.js                     # initializeStores(), registerObjectType()
│   └── modules/
│       ├── settings.js              # Pinia settings store
│       └── object.js                # createObjectStore (all entities)
└── views/
    ├── Dashboard.vue                # CnDashboardPage + KPI cards
    ├── settings/
    │   ├── AdminRoot.vue
    │   └── Settings.vue             # CnVersionInfoCard + CnRegisterMapping
    ├── organizations/
    │   ├── OrganizationIndex.vue    # CnIndexPage
    │   └── OrganizationDetail.vue   # CnDetailPage
    ├── fiscal-years/
    ├── accounts/
    ├── journal-entries/
    ├── sales-invoices/
    ├── purchase-invoices/
    ├── purchase-orders/
    ├── contracts/
    ├── bank-statements/
    ├── budgets/
    └── financial-reports/
```

### Navigation Sections

```
Debiteuren
  └── Verkoopfacturen

Crediteuren
  ├── Inkoopfacturen
  └── Inkooporders

Boekhouding
  ├── Journaalboekingen
  ├── Rekeningschema
  └── Bankafschriften

Inkoop & Contracten
  ├── Contracten
  └── Inkooporders (alias)

Planning & Rapportages
  ├── Budgetten
  ├── Rapportages
  └── Boekjaren

Stamgegevens
  └── Organisaties
```

### Dashboard Design

Four KPI cards (`CnStatsBlock`):
1. **Open verkoopfacturen** — count + sum `totaalInclBtw` where `status` = `verzonden` or `vervallen`
2. **Openstaand crediteuren** — count + sum `totaalInclBtw` PurchaseInvoice where `status` = `ontvangen` or `goedgekeurd`
3. **Budget resterend** — sum `budgetBedrag - werkelijkBedrag` across active FiscalYear budgets
4. **Te verwerken bankafschriften** — count BankStatement where `status` = `te-verwerken`

Status distribution donut chart: SalesInvoice status breakdown.

Recent mutations table: last 10 JournalEntry objects sorted by `datum` desc.

### Store Initialization

```js
// store/store.js
export async function initializeStores() {
  await settingsStore.fetchSettings()
  const schemas = [
    { name: 'organization',       schema: 'organization',      register: 'shillinq' },
    { name: 'fiscalYear',         schema: 'fiscal-year',       register: 'shillinq' },
    { name: 'account',            schema: 'account',           register: 'shillinq' },
    { name: 'journalEntry',       schema: 'journal-entry',     register: 'shillinq' },
    { name: 'salesInvoice',       schema: 'sales-invoice',     register: 'shillinq' },
    { name: 'purchaseInvoice',    schema: 'purchase-invoice',  register: 'shillinq' },
    { name: 'purchaseOrder',      schema: 'purchase-order',    register: 'shillinq' },
    { name: 'contract',           schema: 'contract',          register: 'shillinq' },
    { name: 'bankStatement',      schema: 'bank-statement',    register: 'shillinq' },
    { name: 'budget',             schema: 'budget',            register: 'shillinq' },
    { name: 'financialReport',    schema: 'financial-report',  register: 'shillinq' },
    { name: 'bbvAccount',         schema: 'bbv-account',       register: 'shillinq' },
    { name: 'spendReport',        schema: 'spend-report',      register: 'shillinq' },
    { name: 'aiTask',             schema: 'ai-task',           register: 'shillinq' },
  ]
  for (const { name, schema, register } of schemas) {
    objectStore.registerObjectType(name, schema, register)
  }
}
```

---

## Backend Architecture

### Register Definition (`lib/Settings/shillinq_register.json`)

OpenAPI 3.0 file with `x-openregister` extension. All 14 schemas are defined here. Imported automatically on install/upgrade via `InitializeSettings` repair step using `ConfigurationService::importFromApp()`.

### Repair Step (`lib/Repair/InitializeSettings.php`)

- Implements `IRepairStep`
- Calls `SettingsService::initializeRegister()` which calls `ConfigurationService::importFromApp('shillinq')`
- Idempotent — safe to run on upgrade

### Settings API (`lib/Controller/SettingsController.php`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/settings` | Return current settings + `openRegisters` flag + `isAdmin` |
| POST | `/api/settings` | Save settings (admin only) |
| POST | `/api/settings/load` | Re-import register definition (admin only) |

### Notifications (`lib/Service/NotificationService.php`)

Custom service wrapping `IManager` (Nextcloud notifications). Triggers:

| Event | Trigger | Recipients |
|---|---|---|
| `invoice_overdue` | SalesInvoice `vervaldatum` < today AND `status` = `verzonden` | AR admins |
| `contract_expiring` | Contract `einddatum` within `opzegtermijnDagen` days | Contract managers |
| `budget_exceeded` | Budget `werkelijkBedrag` ≥ `budgetBedrag` | Controllers |
| `invoice_awaiting_approval` | PurchaseInvoice `status` changed to `ontvangen` | AP admins |

---

## Seed Data

Per ADR-016, each schema must have 3–5 realistic Dutch-language example objects.

### Organization

```json
[
  {
    "@self": { "register": "shillinq", "schema": "organization", "slug": "gemeente-westerkwartier" },
    "naam": "Gemeente Westerkwartier",
    "type": "klant",
    "kvkNummer": "69762178",
    "btwNummer": "NL857698341B01",
    "ibanRekening": "NL91 ABNA 0417 1643 00",
    "email": "financien@gemeentewesterkwartier.nl",
    "telefoon": "+31 594 590 000",
    "straat": "Hooiweg 9",
    "postcode": "9795 PA",
    "stad": "Bedum",
    "land": "NL",
    "actief": true
  },
  {
    "@self": { "register": "shillinq", "schema": "organization", "slug": "it-advies-de-vries" },
    "naam": "IT Advies De Vries B.V.",
    "type": "leverancier",
    "kvkNummer": "34271810",
    "btwNummer": "NL812345678B01",
    "ibanRekening": "NL20 INGB 0001 2345 67",
    "email": "administratie@itadviesdevries.nl",
    "telefoon": "+31 20 612 3456",
    "straat": "Keizersgracht 452",
    "postcode": "1017 EG",
    "stad": "Amsterdam",
    "land": "NL",
    "actief": true
  },
  {
    "@self": { "register": "shillinq", "schema": "organization", "slug": "conduction-bv" },
    "naam": "Conduction B.V.",
    "type": "intern",
    "kvkNummer": "65880928",
    "btwNummer": "NL856291398B01",
    "ibanRekening": "NL86 RABO 0123 4567 89",
    "email": "administratie@conduction.nl",
    "telefoon": "+31 85 303 6840",
    "straat": "Nieuwezijds Voorburgwal 147",
    "postcode": "1012 RJ",
    "stad": "Amsterdam",
    "land": "NL",
    "actief": true
  },
  {
    "@self": { "register": "shillinq", "schema": "organization", "slug": "drukkerij-van-den-berg" },
    "naam": "Drukkerij Van den Berg",
    "type": "leverancier",
    "kvkNummer": "12847653",
    "btwNummer": "NL823456789B01",
    "ibanRekening": "NL55 TRIO 0212 0639 00",
    "email": "info@drukkerijvandenberg.nl",
    "telefoon": "+31 30 234 5678",
    "straat": "Industrieweg 22",
    "postcode": "3401 MG",
    "stad": "IJsselstein",
    "land": "NL",
    "actief": true
  }
]
```

### FiscalYear

```json
[
  {
    "@self": { "register": "shillinq", "schema": "fiscal-year", "slug": "boekjaar-2024" },
    "naam": "Boekjaar 2024",
    "startdatum": "2024-01-01",
    "einddatum": "2024-12-31",
    "status": "gesloten"
  },
  {
    "@self": { "register": "shillinq", "schema": "fiscal-year", "slug": "boekjaar-2025" },
    "naam": "Boekjaar 2025",
    "startdatum": "2025-01-01",
    "einddatum": "2025-12-31",
    "status": "gesloten"
  },
  {
    "@self": { "register": "shillinq", "schema": "fiscal-year", "slug": "boekjaar-2026" },
    "naam": "Boekjaar 2026",
    "startdatum": "2026-01-01",
    "einddatum": "2026-12-31",
    "status": "open"
  }
]
```

### Account (chart of accounts — subset)

```json
[
  {
    "@self": { "register": "shillinq", "schema": "account", "slug": "rekening-1000" },
    "rekeningnummer": "1000",
    "naam": "Kas",
    "type": "activa",
    "subtype": "vlottende-activa",
    "btwCode": "geen",
    "actief": true,
    "saldo": 2450.00
  },
  {
    "@self": { "register": "shillinq", "schema": "account", "slug": "rekening-1300" },
    "rekeningnummer": "1300",
    "naam": "Debiteuren",
    "type": "activa",
    "subtype": "vlottende-activa",
    "btwCode": "geen",
    "actief": true,
    "saldo": 48750.00
  },
  {
    "@self": { "register": "shillinq", "schema": "account", "slug": "rekening-4000" },
    "rekeningnummer": "4000",
    "naam": "Personeelskosten",
    "type": "kosten",
    "btwCode": "geen",
    "actief": true,
    "saldo": 124300.00
  },
  {
    "@self": { "register": "shillinq", "schema": "account", "slug": "rekening-8000" },
    "rekeningnummer": "8000",
    "naam": "Omzet dienstverlening",
    "type": "opbrengsten",
    "btwCode": "hoog",
    "actief": true,
    "saldo": 215600.00
  },
  {
    "@self": { "register": "shillinq", "schema": "account", "slug": "rekening-1600" },
    "rekeningnummer": "1600",
    "naam": "Te betalen BTW",
    "type": "passiva",
    "subtype": "kortlopende-schulden",
    "btwCode": "geen",
    "actief": true,
    "saldo": 12500.00
  }
]
```

### SalesInvoice

```json
[
  {
    "@self": { "register": "shillinq", "schema": "sales-invoice", "slug": "vf-2026-0001" },
    "factuurnummer": "2026-0001",
    "factuurdatum": "2026-01-15",
    "vervaldatum": "2026-02-14",
    "status": "betaald",
    "valuta": "EUR",
    "totaalExclBtw": 8400.00,
    "totaalBtw": 1764.00,
    "totaalInclBtw": 10164.00,
    "regels": [
      { "omschrijving": "Adviesuren januari 2026", "aantal": 56, "eenheidsprijs": 150.00, "btwTarief": "hoog" }
    ]
  },
  {
    "@self": { "register": "shillinq", "schema": "sales-invoice", "slug": "vf-2026-0042" },
    "factuurnummer": "2026-0042",
    "factuurdatum": "2026-03-01",
    "vervaldatum": "2026-03-31",
    "status": "verzonden",
    "valuta": "EUR",
    "totaalExclBtw": 12600.00,
    "totaalBtw": 2646.00,
    "totaalInclBtw": 15246.00,
    "regels": [
      { "omschrijving": "Implementatie fase 2", "aantal": 84, "eenheidsprijs": 150.00, "btwTarief": "hoog" }
    ]
  },
  {
    "@self": { "register": "shillinq", "schema": "sales-invoice", "slug": "vf-2026-0043" },
    "factuurnummer": "2026-0043",
    "factuurdatum": "2026-02-28",
    "vervaldatum": "2026-03-29",
    "status": "vervallen",
    "valuta": "EUR",
    "totaalExclBtw": 3200.00,
    "totaalBtw": 672.00,
    "totaalInclBtw": 3872.00,
    "regels": [
      { "omschrijving": "Licentiekosten Q1 2026", "aantal": 1, "eenheidsprijs": 3200.00, "btwTarief": "hoog" }
    ]
  }
]
```

### PurchaseInvoice

```json
[
  {
    "@self": { "register": "shillinq", "schema": "purchase-invoice", "slug": "if-2026-0008" },
    "leveranciersFactuurnummer": "INV-2026-00341",
    "internNummer": "IF-2026-0008",
    "factuurdatum": "2026-03-05",
    "ontvangstdatum": "2026-03-07",
    "vervaldatum": "2026-04-04",
    "status": "goedgekeurd",
    "valuta": "EUR",
    "totaalExclBtw": 4200.00,
    "totaalBtw": 882.00,
    "totaalInclBtw": 5082.00,
    "regels": [
      { "omschrijving": "Serverhosting Q2 2026", "aantal": 1, "eenheidsprijs": 4200.00, "btwTarief": "hoog" }
    ]
  },
  {
    "@self": { "register": "shillinq", "schema": "purchase-invoice", "slug": "if-2026-0009" },
    "leveranciersFactuurnummer": "DvdB-2026-112",
    "internNummer": "IF-2026-0009",
    "factuurdatum": "2026-03-10",
    "ontvangstdatum": "2026-03-11",
    "vervaldatum": "2026-04-09",
    "status": "ontvangen",
    "valuta": "EUR",
    "totaalExclBtw": 890.00,
    "totaalBtw": 186.90,
    "totaalInclBtw": 1076.90,
    "regels": [
      { "omschrijving": "Drukwerk brochures 2026", "aantal": 500, "eenheidsprijs": 1.78, "btwTarief": "hoog" }
    ]
  }
]
```

### Contract

```json
[
  {
    "@self": { "register": "shillinq", "schema": "contract", "slug": "cnt-2024-007" },
    "referentie": "CNT-2024-007",
    "titel": "SLA Hosting en Beheer",
    "startdatum": "2024-04-01",
    "einddatum": "2026-03-31",
    "opzegtermijnDagen": 90,
    "automatischVerlengen": true,
    "verlengingsperiodeMaanden": 12,
    "status": "actief",
    "waarde": 50400.00,
    "valuta": "EUR",
    "categorie": "it"
  },
  {
    "@self": { "register": "shillinq", "schema": "contract", "slug": "cnt-2025-003" },
    "referentie": "CNT-2025-003",
    "titel": "Raamovereenkomst drukwerk",
    "startdatum": "2025-01-01",
    "einddatum": "2026-12-31",
    "opzegtermijnDagen": 60,
    "automatischVerlengen": false,
    "status": "actief",
    "waarde": 15000.00,
    "valuta": "EUR",
    "categorie": "facilitair"
  },
  {
    "@self": { "register": "shillinq", "schema": "contract", "slug": "cnt-2023-019" },
    "referentie": "CNT-2023-019",
    "titel": "Kantoorhuur Nieuwezijds Voorburgwal",
    "startdatum": "2023-07-01",
    "einddatum": "2025-06-30",
    "opzegtermijnDagen": 180,
    "automatischVerlengen": false,
    "status": "verlopen",
    "waarde": 28800.00,
    "valuta": "EUR",
    "categorie": "facilitair"
  }
]
```

### Budget

```json
[
  {
    "@self": { "register": "shillinq", "schema": "budget", "slug": "budget-personeel-2026" },
    "naam": "Personeelskosten 2026",
    "periode": "jaar",
    "budgetBedrag": 240000.00,
    "werkelijkBedrag": 62400.00,
    "valuta": "EUR",
    "categorie": "personeel"
  },
  {
    "@self": { "register": "shillinq", "schema": "budget", "slug": "budget-it-2026" },
    "naam": "IT & Software 2026",
    "periode": "jaar",
    "budgetBedrag": 60000.00,
    "werkelijkBedrag": 18900.00,
    "valuta": "EUR",
    "categorie": "it"
  },
  {
    "@self": { "register": "shillinq", "schema": "budget", "slug": "budget-marketing-2026" },
    "naam": "Marketing & Communicatie 2026",
    "periode": "jaar",
    "budgetBedrag": 25000.00,
    "werkelijkBedrag": 8200.00,
    "valuta": "EUR",
    "categorie": "marketing"
  }
]
```
