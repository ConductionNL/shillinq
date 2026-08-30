# Spec: bookkeeping-programmabegroting

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `bookkeeping-bbv-compliance` (BBVTaakveldCatalogus lookup), `bookkeeping-budget-forecast` (forecast cijfers), `bookkeeping-general-ledger` (budget-overrun validation)

## ADDED Requirements

### Requirement: REQ-001: Programmabegroting SHALL be declared as a master register with version, organisationType, status, and sluitend-flags

The `Programmabegroting` register MUST exist in `lib/Settings/shillinq_register.json` with the following 
minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `version` | integer | Yes | Begroting version number (1 for initial vaststelling, incremented on wijziging) |
| `organisationId` | string | Yes | FK to the gemeente/provincie/waterschap |
| `organisationType` | enum | Yes | One of `gemeente`, `provincie`, `waterschap` (determines toezichthouder) |
| `begrotingsjaar` | integer | Yes | Fiscal year (e.g. 2026) |
| `meerjarenHorizon` | integer | Yes | Default 4 years (T+1..T+4); configurable per administration |
| `status` | enum | Yes | One of `draft`, `in-behandeling`, `vastgesteld`, `superseded` |
| `vaststellingsBesluit` | string | No | FK to raadsbesluit / statenbesluit / AB-besluit for audit traceability |
| `vaststellingsDatum` | date | No | Date the raad adopted the begroting |
| `sluitendStructureel` | boolean | Yes | Computed flag: recurring lasten ≤ recurring baten for all years T+1..T+4 |
| `sluitendReëel` | boolean | Yes | Computed flag: saldo ≥ 0 after nominale-ontwikkeling correction for all years T+1..T+4 |
| `toezichtRegime` | enum | No | Computed: one of `repressief`, `preventief`, `artikel-12` (set during vaststelling) |
| `nominaleOntwikkeling` | number | No | Loon- en prijsindexatie percentage for reëel-sluitend correction (user-configured annually) |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Plan` or `schema:Report`.

#### Scenario: Programmabegroting tracks status from draft through vaststelling

- **GIVEN** a gemeente with administration adm-1
- **WHEN** the controller creates a Programmabegroting for begrotingsjaar 2027
- **THEN** the record MUST be created with status `draft`, version 1, and empty sluitend-flags.

#### Scenario: Sluitend-flags are computed on in-behandeling → vastgesteld transition

- **GIVEN** a Programmabegroting in status `in-behandeling` with meerjarenraming populated for T+1..T+4
- **WHEN** the raad adopts the begroting (vaststellingsBesluit is set, status → vastgesteld)
- **THEN** the system MUST compute sluitendStructureel and sluitendReëel based on the 
  meerjarenraming data and set both flags; **AND** toezichtRegime MUST be determined and set.

### Requirement: REQ-002: Programma registers SHALL declare locally-chosen political structure with aggregated baten/lasten

The `Programma` register MUST exist with the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `begrotingId` | string | Yes | FK to Programmabegroting |
| `nummer` | string | Yes | Sequential program number (e.g. "1", "2", ...) |
| `naam` | string | Yes | Program name (e.g. "Veiligheid & Handhaving") |
| `portefeuillehouder` | string | No | Assigned alderman/official responsible |
| `doelstellingen` | string | No | Rich-text narrative of program objectives and outcomes |
| `batenTotaal` | number | Yes | Aggregated sum of child Taakveld.baten (computed) |
| `lastenTotaal` | number | Yes | Aggregated sum of child Taakveld.lasten (computed) |
| `saldoVoorMutaties` | number | Yes | batenTotaal - lastenTotaal (computed) |
| `mutatiesReserves` | number | No | Reserve mutations (positive = toevoeging, negative = onttrekking) |
| `saldoNaMutaties` | number | Yes | saldoVoorMutaties + mutatiesReserves (computed) |
| `administrationId` | string | Yes | FK to administration |

#### Scenario: Programma aggregates child Taakvelden without rounding error

- **GIVEN** a Programma with two child Taakvelden: T1 (baten 100, lasten 500) and T2 (baten 50, lasten 450)
- **WHEN** the Programma is saved
- **THEN** batenTotaal MUST equal 150, lastenTotaal MUST equal 950 (no rounding drift).

#### Scenario: Programma mutation test for saldoNaMutaties

- **GIVEN** a Programma with saldoVoorMutaties = -500 and mutatiesReserves = 200
- **WHEN** the record is saved
- **THEN** saldoNaMutaties MUST equal -300.

### Requirement: REQ-003: Taakveld registers SHALL declare BBV-mandated field indeling with baten/lasten per taakveldCode

The `Taakveld` register MUST exist with the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `programmaId` | string | Yes | FK to Programma |
| `taakveldCode` | string | Yes | BBV taakveld code (e.g. "6.71" = Brandweer); must match BBVTaakveldCatalogus |
| `taakveldNaam` | string | Yes | Display name (looked up from catalogue; user-overrideable for clarity) |
| `baten` | number ≥ 0 | Yes | Revenue/income for this taakveld |
| `lasten` | number ≥ 0 | Yes | Expenses/expenditure for this taakveld |
| `administrationId` | string | Yes | FK to administration |

A Taakveld MUST NOT span multiple Programma's within one Begroting (uniqueness constraint on 
(begrotingId, taakveldCode)).

Schema.org annotation: `schema:DefinedTerm`.

#### Scenario: Taakveld code validated against catalogue

- **GIVEN** the BBVTaakveldCatalogus for 2027 loaded (from `bookkeeping-bbv-compliance`)
- **WHEN** a Taakveld is created with taakveldCode "6.71"
- **THEN** the system MUST validate that "6.71" exists in the catalogue; if not, save MUST fail 
  with a "invalid taakveld code" error.

#### Scenario: Taakveld uniqueness prevents splitting

- **GIVEN** Programmabegroting B1 with Programma P1 and P2
- **WHEN** P1 has Taakveld T1 (code "6.71", baten 100, lasten 500)
- **AND** an attempt is made to create Taakveld T2 in P2 with the same code "6.71"
- **THEN** the save MUST fail with "taakveld code already assigned to another programma in this 
  begroting".

### Requirement: REQ-004: Indicator registers SHALL capture beleidsindicatoren per Programma per jaar with realisatie tracking

The `Indicator` register MUST exist with the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `programmaId` | string | Yes | FK to Programma |
| `code` | string | Yes | Indicator code (e.g. "VEI-001") |
| `omschrijving` | string | Yes | Description of what the indicator measures |
| `eenheid` | string | Yes | Unit (e.g. "aantal", "percentage", "euro", "respondenten") |
| `nulwaarde` | number | No | Baseline value for comparison |
| `streefwaarde` | number | No | Target value for the indicator |
| `realisatie` | number | No | Actual achieved value (updated post-jaarrekening) |
| `bron` | string | No | Data source reference or dataset URL (required per ADR-102 governance) |
| `administrationId` | string | Yes | FK to administration |

Per BBV Article 25, the system MUST include the verplichte beleidsindicatoren from the Commissie BBV 
catalogue. Local indicators may be added at administration discretion.

#### Scenario: Indicator with bron reference

- **GIVEN** a Programma "Veiligheid"
- **WHEN** an Indicator is created with code "VEI-001", omschrijving "Aantal misdrijven", 
  eenheid "aantal", and bron "https://www.cbs.nl/misdrijven-gemeente"
- **THEN** the Indicator MUST be saved and the bron MUST be stored for audit.

#### Scenario: Realisatie updated post-jaarrekening

- **GIVEN** an Indicator with streefwaarde 500 and realisatie initially null
- **WHEN** the jaarrekening is approved, realisatie is set to 487
- **THEN** the system MUST allow the update and record the change in audit trail.

### Requirement: REQ-005: Investering registers SHALL declare capital investments with dekking-type and afschrijvingstermijn

The `Investering` register MUST exist with the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `programmaId` | string | Yes | FK to Programma |
| `omschrijving` | string | Yes | Description of the investment (e.g. "Renovatie gemeentehuis") |
| `bruto` | number | Yes | Gross investment amount |
| `dekking` | enum | Yes | Funding source: one of `eigen-middelen`, `lening`, `bijdragen-derden`, `subsidie` |
| `afschrijvingstermijn` | integer | Yes | Depreciation period in years (per materiaalwaarden notitie) |
| `eersteAfschrijvingsjaar` | integer | Yes | First year depreciation is recognized |
| `kapitaallastenSchedule` | object | Yes | Per-year depreciation/capital cost breakdown (computed) |
| `administrationId` | string | Yes | FK to administration |

#### Scenario: Kapitaallastenschedule computed from afschrijvingstermijn

- **GIVEN** an Investering with bruto 400000, eersteAfschrijvingsjaar 2027, afschrijvingstermijn 20
- **WHEN** the record is saved
- **THEN** kapitaallastenSchedule MUST be computed as {2027: 20000, 2028: 20000, ..., 2046: 20000}.

### Requirement: REQ-006: Reserve and Voorziening registers SHALL track balances and mutations per BBV Article 44

The `Reserve` register MUST exist with the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `begrotingId` | string | Yes | FK to Programmabegroting |
| `type` | enum | Yes | One of `algemene-reserve`, `bestemmingsreserve` |
| `naam` | string | Yes | Name of the reserve (e.g. "Stabilisatiereserve") |
| `beginsaldo` | number | Yes | Opening balance from prior year |
| `toevoegingen` | number | No | Additions to the reserve in this year |
| `onttrekkingen` | number | No | Withdrawals from the reserve in this year |
| `eindsaldo` | number | Yes | Closing balance (beginsaldo + toevoegingen - onttrekkingen) |
| `bestemmingsdoel` | string | No | Purpose statement (required for bestemmingsreserve) |
| `administrationId` | string | Yes | FK to administration |

The `Voorziening` register MUST exist with the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `begrotingId` | string | Yes | FK to Programmabegroting |
| `naam` | string | Yes | Name of the provision (e.g. "Werknemersverzekeringen") |
| `grondslag` | enum | Yes | Legal basis per BBV Article 44: one of `a`, `b`, `c`, `d` |
| `beginsaldo` | number | Yes | Opening balance |
| `dotaties` | number | No | Additions in this year |
| `vrijval` | number | No | Release (when obligation ends) |
| `aanwendingen` | number | No | Usage of the provision |
| `eindsaldo` | number | Yes | Closing balance (beginsaldo + dotaties - vrijval - aanwendingen) |
| `administrationId` | string | Yes | FK to administration |

#### Scenario: Reserve balance arithmetic

- **GIVEN** a Reserve with beginsaldo 50000, toevoegingen 10000, onttrekkingen 5000
- **WHEN** the record is saved
- **THEN** eindsaldo MUST equal 55000.

#### Scenario: Voorziening with BBV Article 44 grondslag

- **GIVEN** a Voorziening for employee insurances with grondslag "a" (verplichte dotatie)
- **WHEN** the record is saved
- **THEN** the system MUST accept the record and track it for BBV compliance reporting.

### Requirement: REQ-007: Paragraaf registers SHALL declare the seven mandated sections with narrative and kerncijfers

The `Paragraaf` register MUST exist with the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `begrotingId` | string | Yes | FK to Programmabegroting |
| `type` | enum | Yes | One of: `lokaleHeffingen`, `weerstandsvermogenRisicobeheersing`, `onderhoudKapitaalgoederen`, `financiering`, `bedrijfsvoering`, `verbondenPartijen`, `grondbeleid` |
| `narrative` | string | No | Rich-text narrative content (required on vastgesteld status) |
| `kerncijfers` | object | Yes | Structured key metrics (schema depends on paragraaftype — see sub-tables below) |
| `administrationId` | string | Yes | FK to administration |

**Kerncijfers schema per paragraaftype:**

- **lokaleHeffingen:** {totaalOpbrengsten, tariefen_toegepast, % bijdrage_totale_inkomsten}
- **weerstandsvermogenRisicobeheersing:** {weerstandsverhouding, algemene_reserve_as_% lasten, risicoregister_link}
- **onderhoudKapitaalgoederen:** {totaalOnderhoudsbudget, achterstanden_grofschatting, normaalonderhouds_%.}
- **financiering:** {schuldenstandopening, leningen_aangegaan, aflossingen, schuldenstandsluiting, rente_gemiddeld_%}
- **bedrijfsvoering:** {personeelssterkte, gemiddelde_dienstjaren, P&O_beleidsmaatregelen}
- **verbondenPartijen:** {aantalPartijen, total_equity, waarschuwingssignalen_detected}
- **grondbeleid:** {grondvoorraden, grondverkopen, grondaankopen}

All seven Paragraaf records MUST be created automatically when a Programmabegroting is first drafted. 
The narrative MUST be non-empty before the begroting can transition to `vastgesteld` status.

#### Scenario: All seven paragrafen created on begroting draft

- **GIVEN** a new Programmabegroting is created
- **WHEN** the record is saved with status `draft`
- **THEN** seven Paragraaf records MUST be auto-created (one per type), each with empty narrative 
  and placeholder kerncijfers.

#### Scenario: Vaststelling blocked if paragraaf narrative empty

- **GIVEN** a Programmabegroting in status `in-behandeling` with one empty paragraaf narrative
- **WHEN** the operator attempts to transition to status `vastgesteld`
- **THEN** the transition MUST fail with a validation error: "paragraaf [type] narrative is required 
  before vaststelling".

### Requirement: REQ-008: Meerjarenraming registers SHALL declare 4-year outlook with sluitend-criteria per year

The `Meerjarenraming` register MUST exist with the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `begrotingId` | string | Yes | FK to Programmabegroting |
| `jaar` | integer | Yes | Year within meerjarenraming (T+1, T+2, T+3, T+4 relative to begrotingsjaar) |
| `batenStructureel` | number ≥ 0 | Yes | Recurring revenue/income for the year |
| `batenIncidenteel` | number ≥ 0 | No | One-time/extraordinary revenue |
| `lastenStructureel` | number ≥ 0 | Yes | Recurring expenditure |
| `lastenIncidenteel` | number ≥ 0 | No | One-time/extraordinary expenditure |
| `saldoStructureel` | number | Yes | batenStructureel - lastenStructureel (computed) |
| `saldoIncidenteel` | number | No | batenIncidenteel - lastenIncidenteel (computed) |
| `saldoReëel` | number | Yes | (saldoStructureel + saldoIncidenteel) corrected for nominale-ontwikkeling (computed) |
| `sluitend` | boolean | Yes | Computed: true iff struktureel AND reëel balance holds for this year |
| `administrationId` | string | Yes | FK to administration |

The system MUST compute:
- **sluitendStructureel (per year):** lastenStructureel ≤ batenStructureel
- **sluitendReëel (per year):** saldoReëel ≥ 0 (after correction by nominale-ontwikkeling %)
- **Overall sluitend-flags on Programmabegroting:** sluitendStructureel = TRUE for all jaren T+1..T+4; 
  sluitendReëel = TRUE for all jaren T+1..T+4.

#### Scenario: Meerjarenraming seeded from forecast

- **GIVEN** `bookkeeping-budget-forecast` provides forecast cijfers for jaren T+1..T+4
- **WHEN** a Programmabegroting is created for begrotingsjaar B
- **THEN** the system MUST automatically create four Meerjarenraming records (one per jaar), seeded 
  with forecast batenStructureel and lastenStructureel values; operator may override.

#### Scenario: Sluitend-criteria evaluated correctly

- **GIVEN** Meerjarenraming for T+1 with batenStructureel 1000, lastenStructureel 900, saldoReëel 100 
  (after nominale correction)
- **WHEN** the record is saved
- **THEN** saldoStructureel MUST equal 100, sluitend MUST be TRUE.

#### Scenario: Sluitend-criteria fails for early year

- **GIVEN** Meerjarenraming for T+1 with batenStructureel 1000, lastenStructureel 1100, saldoReëel -50 
  (after nominale correction)
- **WHEN** the record is saved
- **THEN** saldoStructureel MUST equal -100, sluitend MUST be FALSE; and the parent Programmabegroting 
  sluitendStructureel and sluitendReëel MUST be set to FALSE immediately.

### Requirement: REQ-009: Begrotingswijziging registers SHALL implement event-sourced delta workflow with raadsbesluit requirement

The `Begrotingswijziging` register MUST exist with the following minimum field set:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `begrotingId` | string | Yes | FK to Programmabegroting (must reference a vastgestelde begroting) |
| `wijzigingsnummer` | string | Yes | Sequential identifier (e.g. "W-001", "W-002") per begroting |
| `omschrijving` | string | Yes | Description of the change (e.g. "Crisisbesluit COVID-19 aanpassing") |
| `mutaties` | object | Yes | Delta: per-programma per-taakveld {baten_delta, lasten_delta} |
| `raadsbesluit` | string | No | FK to raadsbesluit / statenbesluit for audit (required before vastgesteld) |
| `vaststellingsDatum` | date | No | Date the raad approved the wijziging |
| `effectiefVanaf` | date | Yes | Effective date of the change (may be retroactive) |
| `status` | enum | Yes | One of `draft`, `vastgesteld` |
| `administrationId` | string | Yes | FK to administration |

**Workflow constraint:** The vastgestelde Programmabegroting is **immutable.** The current stand of the 
begroting = `vastgestelde basis + Σ(vastgestelde wijzigingen)`. No mutaties may take effect without a 
vastgestelde wijziging.

#### Scenario: Wijziging blocks without raadsbesluit

- **GIVEN** a vastgestelde Programmabegroting B1 and a draft Begrotingswijziging W1
- **WHEN** the operator attempts to transition W1 from `draft` to `vastgesteld` without setting 
  raadsbesluit
- **THEN** the transition MUST fail with "raadsbesluit reference required for vaststelling".

#### Scenario: Wijziging delta stacks correctly

- **GIVEN** vastgestelde begroting B1 with Programma P1, Taakveld T1 (baten 100, lasten 500)
- **AND** vastgestelde wijziging W1 with mutatie T1 (baten_delta +50, lasten_delta -100)
- **WHEN** the current stand is queried
- **THEN** effective T1 baten MUST equal 150, effective lasten MUST equal 400.

### Requirement: REQ-010: Budget-overrun detection SHALL prevent GL postings exceeding authorized lasten per programma

When a `JournalEntry` is materialised (boekstuk posted to GL), the system MUST validate:
- Sum of lasten-boekstukken on this Programma/Taakveld ≤ authorized lasten (from vastgestelde begroting 
  + vastgestelde wijzigingen).
- If exceeded, the booking MUST fail with "budgetoverschrijding: programma [X] lasten authorized [Y], 
  attempted booking would exceed to [Z]".

The error MUST list any draft Begrotingswijziging's that could resolve the overrun once vastgesteld.

#### Scenario: GL posting within budget succeeds

- **GIVEN** Programmabegroting B1 vastgesteld with Programma P1, Taakveld T1 (authorized lasten 500)
- **WHEN** a GL posting of 400 is booked to T1
- **THEN** the posting MUST succeed and audit trail MUST record the budget consumption.

#### Scenario: GL posting exceeding budget fails

- **GIVEN** same setup with authorized lasten 500 and prior postings totalling 450
- **WHEN** a GL posting of 100 is attempted (would total 550)
- **THEN** the posting MUST fail with budgetoverschrijding error; error MUST suggest draft wijzigingen 
  if any exist.

### Requirement: REQ-011: Programmabegroting lifecycle SHALL enforce sluitend-evaluation and paragraaf-validation on vaststelling

The `Programmabegroting` lifecycle MUST declare transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `in-behandeling` | Operator action | all 7 paragrafen created (auto-check) |
| `in-behandeling` | `vastgesteld` | Raad adopts (via raadsbesluit) | sluitend-evaluation complete; all 7 paragrafen have non-empty narrative; raadsbesluit FK set |
| `vastgesteld` | `superseded` | New begroting for same begrotingsjaar adopted | prior begroting marked superseded (audit trail) |

On transition to `vastgesteld`, the system MUST:
1. Compute sluitendStructureel and sluitendReëel from meerjarenraming.
2. Determine toezichtRegime based on sluitend-flags + 4-year history.
3. Emit an event if toezichtRegime shifts from `repressief` to `preventief`.
4. Lock the vastgestelde begroting against future direct edits (wijzigingen only).

#### Scenario: Transition to vastgesteld computes sluitend-flags and regime

- **GIVEN** a Programmabegroting in status `in-behandeling` with meerjarenraming completed
- **WHEN** raadsbesluit is set and status transitions to `vastgesteld`
- **THEN** sluitendStructureel, sluitendReëel, and toezichtRegime MUST be computed and persisted; 
  **AND** the record MUST be locked against direct edits.

### Requirement: REQ-012: Export integrations SHALL produce iv3-aanlevering (CBS), EMU-saldo (Wet Hof), and JSON (OpenCatalogi)

The system MUST support three export modes:

**iv3-aanlevering (CBS):** Per `Regeling vaststelling iv3-informatievoorschrift`. The export MUST 
aggregate baten and lasten per taakveld per economische categorie, based on the vastgestelde 
Programmabegroting (or its stand as of a specified peildatum including wijzigingen). The export 
MUST conform to CBS XSD schemas and be submittable via the CBS-portaal.

**EMU-saldo (Wet Hof):** Per Wet Hof / SNA-2010 definitions. The export MUST compute saldo = 
Σ(baten) - Σ(lasten) with corrections for investeringen (capitalized), reserve mutations, and 
voorziening mutations. The export MUST list the organisatie's macro-economische referentiewaarde.

**JSON export (OpenCatalogi):** The vastgestelde Programmabegroting (taakveld-first view, with 
Programma narratives) exported as machine-readable JSON for hergebruik by researchers, 
waarstaatjegemeente, openspending, and other third parties.

#### Scenario: iv3-export aggregated by taakveld

- **GIVEN** a vastgestelde Programmabegroting with two Taakvelden T1 and T2
- **WHEN** iv3-export is generated
- **THEN** the export MUST contain one row per taakveld (T1 and T2) with aggregated baten and lasten 
  (summing any Programma's that contain that taakveld).

#### Scenario: JSON export conforms to OpenCatalogi schema

- **GIVEN** a vastgestelde Programmabegroting
- **WHEN** JSON export is generated
- **THEN** the JSON MUST include programmabegroting metadata (begrotingsjaar, vaststellingsDatum, 
  sluitend-flags), all Programma's with narratives, all Taakvelden, and all seven Paragrafen.

## Verification

`openspec validate` must exit clean on the change folder. 

Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB or gemeente) confirms the 
begrotingsproces matches Dutch BBV practice (opstellen → behandeling → vaststelling → wijzigingen → 
vastgestelde mutaties). 

Architecture reviewer confirms ADR-022 + ADR-031 compliance (all behaviour declarative; no app-local 
dunning / payroll / budgeting service; manifest carries navigation).

No source code changes outside `openspec/changes/bookkeeping-programmabegroting/`.
