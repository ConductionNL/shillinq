# Design — Multi-Administratie

## Decisions

### D1 — Administratie is a first-class register, not a configuration field

Per ADR-031, each `Administratie` (juridisch-onafhankelijke boekhouding) is stored
as a governable register record with full RBAC, audit trail, and lifecycle state,
not as a string configuration parameter. This enables: type-safe FK references,
operator-controllable administratie creation/archival, per-administratie backup
scheduling, and administrative audit trail.

**Alternative considered**: Store administratie as a string in config, with separate
data partitioning. Rejected — loses audit trail, prevents operator CRUD via UI,
complicates backup scheduling.

### D2 — All financial schemas gain mandatory `administratie` FK, not optional

The `administratie` field is required (non-nullable) on every financial entity
(Journaalpost, Factuur, GrootboekRekening, Budget, etc.). This forces every query
to declare which administratie it operates on, preventing accidental data-leakage.

**Alternative considered**: Optional `administratie` field; single-administratie
installs leave it null. Rejected — creates a special case; queries must handle null;
risk of null-dereference bugs in production.

### D3 — User roles are per-administratie, stored in a join register

User access is modeled as `AdministratielidMaatschap` (user × administratie × role
+ permissions). This decouples user identity from administratie membership,
allowing one user to access multiple administraties with different roles per each.
Example: a controller has role `controller` in Werk-001 and role `inkijker` in
Beheer-001.

**Alternative considered**: Store roles directly on `Administratie.allowedUsers`.
Rejected — loses role-per-administratie granularity; harder to revoke access.

### D4 — Administratie-switcher is a lightweight UI component, not a modal

The administratie-switcher renders as a dropdown or pill-bar under the app header,
allowing instant switch without page reload. Session context updates; all subsequent
queries use the new administratie context.

**Alternative considered**: Full logout/login cycle on administratie switch. Rejected
— poor UX for holding-controllers toggling between administraties.

### D5 — Intercompany journaalposten are mirrored pairs with status tracking

A single conceptual transaction (e.g., "management fee from Werk to Beheer") is
stored as TWO Journaalpost records — one in each administratie — linked via
`intercompany_nummer` and `IntercompanyJournaalpost` tracking record. Status
transitions: concept (one side drafted) → gekoppeld (both sides linked) → bevestigd_beide
(both sides confirmed) → eliminatie_geboekt (consolidatie elimination applied).

**Alternative considered**: Single intercompany record with nested line-items.
Rejected — breaks the invariant that each administratie's journal is self-contained;
complicates queries; violates Dutch GAAP (each entity must have its own legal
journaal).

### D6 — Consolidatie-mapping is pre-positioned but not enforced here

The `ConsolidatieMapping` register captures rekening-mapping rules (dochter rekening →
moeder rekening) and eliminatie-accounting, but the actual consolidated-reporting
logic is deferred to the `bookkeeping-consolidatie` spec. This spec provides the
data structure; downstream specs enforce the rules.

**Alternative considered**: Implement full consolidation logic here. Rejected — out
of scope per proposal; deferred to specialized `bookkeeping-consolidatie` spec.

### D7 — Administratie-migratie records asset transfers with dual journaalpost references

When a fixed asset, contract, or employee moves from Administratie A to
Administratie B, an `AdministratieMigratie` record captures: source + destination
administraties, object references, boekwaarde + marktwaarde, fiscale-behandeling
(geruisloze-doorschuiving vs. met-realisatie), juridische grondslag, and paired
journaalpost IDs on both sides. This preserves audit trail and enables reversal if
needed.

**Alternative considered**: Store migration as a single journaalpost per side.
Rejected — loses the conceptual link between source + destination; harder to
reconcile; audit trail is fragmented.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Administratie storage | New register (`Administratie`) | Declared same way as `Account`; same RBAC + audit + lifecycle |
| User-to-administratie mapping | New `AdministratielidMaatschap` register | Join register pattern; reuses OR relations engine |
| Role-per-administratie | OR `x-openregister-rbac` | Context tracks active administratie + user's roles in that administratie |
| Per-administratie isolation on queries | OR data-isolation patterns | Standardized `FilterByAdministration` on every query layer |
| Intercompany journaalpost mirroring | New `IntercompanyJournaalpost` register | Tracks status + automatic dual-side post validation |
| Consolidatie-mapping storage | New `ConsolidatieMapping` register | Schema-declared; referenced by consolidatie spec |
| Administratie-migratie audit | New `AdministratieMigratio` register | Captures juridische grondslag + dual journaalpost refs |
| Mandatory FK on all financial schemas | Additive patches on existing schemas | Every schema gains `administratie: "uuid\|ref:Administratie"` field |
| Per-administratie backup | OR `x-openregister-lifecycle` | Administratie.backup_schema + incremental backup routine |
| Administratie-switcher UI | New Vue component | Lightweight dropdown; updates session context |
| Audit trail | OR audit-trail-immutable | Consumed automatically per register |

**Net new code in implementation cycle**: 5 schema declarations + `administratie` FK
patches on 60+ schemas + 1 Vue component + 1 repair step + query-layer filtering
pattern + RBAC context extension. No net new PHP service.

## Seed Data

Seeds live under `lib/Settings/seeds/administraties/` — default seed on fresh install:

| File | Purpose | Approximate row count |
|---|---|---|
| `administratie/default.json` | Default administratie for fresh install (absorbs initial data) | 1 |

On a fresh install, the repair step creates a single `Administratie` record with:
- `administrationCode`: "ADM-001"
- `naam`: "Standaard administratie" (localized)
- `rechtsvorm`: "bv" (default; operator overrides on first login)
- `kvk_nummer`: null (operator fills in)
- `status`: "actief"
- `boekjaar_start_maand`: 1 (calendar year; default)
- `presentatievaluta`: "EUR"
- `btw_regime`: "standaard"
- `backup_schema`: "dagelijks"

For existing single-administratie installs upgrading to this spec, the repair step
creates a default administratie and migrates all orphaned data (journaalposten
without `administratie` FK) to it automatically. No operator action required.

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "shillinq-default",
  "variant": "initial", "imported": "<iso-timestamp>" } }`).

## Schema-Design Highlights

### Administratie fields (key subset from context-brief)

```
administrationCode: string (unique, operator-facing identifier)
naam: string (display name)
rechtsvorm: enum (bv, nv, eenmanszaak, vof, stichting, gemeente, etc.)
kvk_nummer: string (KvK registration)
btw_nummer: string (VAT ID)
loonheffingsnummer: string (payroll/salary registration, if applicable)
boekjaar_start_maand: integer (1–12; Jan=1)
afwijkend_boekjaar: boolean (non-calendar year)
presentatievaluta: string (EUR default)
functionele_valuta: string (EUR default)
btw_regime: enum (standaard, kleine_ondernemers_regeling, landbouw, vrijgesteld, overheid)
btw_aangifte_frequentie: enum (maand, kwartaal, jaar)
chart_of_accounts: uuid (FK to Account-schema used by this administratie)
moederadministratie: uuid|null (FK: parent if this is a dochter)
dochters: [uuid] (array of child administraties)
consolidatie_mapping: uuid|null (FK to ConsolidatieMapping)
consolideren_in: uuid|null (FK: destination administratie if consolidating)
consolidatiemethode: enum (integraal, proportioneel, equity, niet_consolideren)
fiscale_eenheid_vpb: uuid|null (FK: administratie if in VPB-eenheid)
fiscale_eenheid_btw: uuid|null (FK: administratie if in BTW-eenheid)
actief_vanaf: date
actief_tot: date|null
status: enum (actief, gearchiveerd, in_liquidatie, opgeheven)
backup_schema: enum (dagelijks, wekelijks, aanvragen)
data_retentie_jaren: integer (default 7; wettelijke bewaartermijn)
default_taal: string (nl, en, de, etc.)
```

### AdministratielidMaatschap fields (user-administratie-role join)

```
gebruiker: uuid (FK to User)
administratie: uuid (FK to Administratie)
rol: enum (eigenaar, controller, boekhouder, inkijker, accountant_extern,
           salarisadministrateur, debiteurenadmin, crediteurenadmin)
toegangsbeperking_grootboek: [string] (account-range restrictions, e.g. ["4000-4999"])
mag_journaalposten_boeken: boolean
mag_jaarafsluiting_doen: boolean
geldig_van: date
geldig_tot: date|null
```

### IntercompanyJournaalpost fields (intercompany transaction tracking)

```
intercompany_nummer: string (unique identifier, e.g. "IC-2026-00042")
datum: date
soort: enum (doorbelasting, dividend, lening, aandelenkapitaal, rente, huur,
             management_fee, overig)
bron_administratie: uuid (FK: source administratie)
doel_administratie: uuid (FK: destination administratie)
bron_journaalpost: uuid (FK: journaalpost in source)
doel_journaalpost: uuid (FK: journaalpost in destination)
bedrag: decimal (transaction amount)
valuta: string (EUR, USD, etc.)
wisselkoers: decimal (FX rate if not EUR)
btw_behandeling: enum (verlegd, standaard, fiscale_eenheid_geen_btw)
geconsolideerd_elimineren: boolean (mark for consolidation elimination)
eliminatie_rekening: string (consolidation account number)
status: enum (concept, gekoppeld, bevestigd_beide, eliminatie_geboekt)
afwijking_bedrag: decimal (reconciliation variance, if any)
```

### ConsolidatieMapping fields (chart-of-accounts mapping for consolidation)

```
naam: string (mapping identifier, e.g. "WERK-001 → HOLDING-001")
bron_administratie: uuid (FK: source administratie)
doel_administratie: uuid (FK: destination administratie)
regels: [{bron_rekening: string, doel_rekening: string, omschrijving: string}]
  (list of account-mapping rules)
eliminatie_rekening_intercompany: string (GL account for IC elimination)
valutaomrekening_methode: enum (slotkoers, gemiddelde, historisch)
geldig_van: date
```

### AdministratieMigratie fields (asset/contract/employee transfer tracking)

```
migratienummer: string (unique identifier, e.g. "MIG-2026-007")
datum: date
bron_administratie: uuid (FK: source)
doel_administratie: uuid (FK: destination)
soort: enum (vaste_activa, debiteur, crediteur, werknemer, contract, overig)
objecten: [uuid] (list of migrated object IDs)
boekwaarde_overdracht: decimal
marktwaarde_overdracht: decimal
verschil_naar_resultaat: decimal (P&L impact)
fiscale_behandeling: enum (geruisloze_doorschuiving, met_realisatie, fiscale_eenheid)
juridische_grondslag: string (notariële akte reference, etc.)
bron_journaalpost: uuid (FK: deactivation post in source)
doel_journaalpost: uuid (FK: activation post in destination)
status: enum (voorbereid, uitgevoerd, geboekt_beide, teruggedraaid)
```

## Per-Administratie Independence

Each administratie is a complete, self-contained accounting entity:

- **Chart-of-accounts** (`Administratie.chart_of_accounts` → `Account`): Werk-001 may
  use RGS-standard; Beheer-001 may use a custom schema. No cross-administratie
  account reuse.
- **Boekjaar** (`Administratie.boekjaar_start_maand` + `afwijkend_boekjaar`): Werk-001
  may be calendar-year; Beheer-001 may be Jul–Jun. Each closes independently.
- **Valuta** (`Administratie.presentatievaluta` + `functionele_valuta`): Werk-001
  may be EUR-only; Werk-002 (if overseas) may be USD with EUR conversion.
- **BTW-regime** (`Administratie.btw_regime` + `btw_aangifte_frequentie`): Each
  administratie declares its own BTW status and filing frequency.
- **Fiscal unit** (`Administratie.fiscale_eenheid_vpb` + `fiscale_eenheid_btw`): Two
  administraties may declare themselves in a VPB-eenheid (consolidating VPB) or
  BTW-eenheid (no intercompany BTW), while remaining journal-separate.

## Administratie-Aware RBAC

On user login:

1. System queries `AdministratielidMaatschap` records where `gebruiker == currentUser`.
2. For each record, retrieve the linked `Administratie` + user's role in that
   administratie.
3. Store in session: `sessionAdministraties` = list of accessible administraties +
   roles.
4. Set `sessionActiveAdministratie` = first administratie (or last used, via
   preference).
5. All subsequent queries filter by `administratie == sessionActiveAdministratie`.

When user switches administratie (via switcher UI):

1. Validate that user has `AdministratielidMaatschap` record for the target
   administratie.
2. Update `sessionActiveAdministratie`.
3. All subsequent queries now filter by the new administratie.

Role-based access (e.g., "can user post journal entries?"):

```
if (user.roleInAdministratie(activeAdministratie) == "inkijker") {
  // read-only
} else if (user.roleInAdministratie(activeAdministratie) == "controller") {
  // can post journal entries, but not close year
} else if (user.roleInAdministratie(activeAdministratie) == "eigenaar") {
  // full access
}
```

## Intercompany-Journaalpost Semantics

Example: Werk B.V. (administratie A) pays management fee of €25,000 to Beheer B.V.
(administratie B).

1. User in Werk's administratie creates a journaalpost: Dr. 6300 (management fee
   expense) €25,000 / Cr. 2000 (payables to Beheer) €25,000.
2. System detects the `destinationAdministratie` reference and creates an
   `IntercompanyJournaalpost` record with status "concept".
3. System auto-creates a mirrored journaalpost in Beheer's administratie: Dr. 1500
   (receivables from Werk) €25,000 / Cr. 8200 (management fee income) €25,000.
4. Status remains "concept" until both sides confirm.
5. When both are confirmed, status → "bevestigd_beide".
6. During consolidation-export, if `geconsolideerd_elimineren=true`, the P&L lines
   (both 6300 and 8200) are eliminated at the consolidation level.

Reconciliation: If the mirrored posting doesn't balance (e.g., one side posts €24,900
instead of €25,000), the `afwijking_bedrag` tracks the variance; the system flags it
for manual review.

## Administratie-Migratie Audit Trail

Example: Fixed asset (equipment, boekwaarde €87,000) transfers from Werk-001 to
Werk-002.

1. User in Werk-001 initiates migration: select asset, enter marktwaarde (€92,000),
   select Werk-002 as destination, provide juridische grondslag (notariële akte dd
   2026-08-15).
2. System creates `AdministratieMigratie` record with status "voorbereid".
3. System drafts TWO journaalposten (not yet posted):
   - Werk-001: Dr. 9600 (gain on disposal) / Cr. 1xxx (fixed asset) for €87,000 +
     €5,000 gain.
   - Werk-002: Dr. 1xxx (fixed asset) / Cr. 2xxx (payables/equity source) for €92,000.
4. User reviews both sides, confirms.
5. Both journaalposten post simultaneously; status → "geboekt_beide".
6. `AdministratieMigratie` record preserves the juridische grondslag, boekwaarde,
   marktwaarde, and paired journaalpost IDs for future audit.
7. If later reversed (status → "teruggedraaid"), both journaalposten are reversed,
   and both administraties return to pre-migration state.
