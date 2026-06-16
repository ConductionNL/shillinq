# Design — Full NL Loonadministratie Engine

## Context

Dutch payroll administration combines three pillars: **bruto→netto-berekening** (personal tax, premies SV, pensioen), **afdracht orchestratie** (Belastingdienst, UWV, pensioenuitvoerder, zorgverzekeraar), en **audit trail** (loonstrook, jaaropgave, cumulatieven-tracking). The engine must be reproduceerbaar over 5+ jaren en comply met Wet LB, Wet LA, Wfsv, ZVW-art.41, Pensioenwet, en BW art.626-634.

Today's SMB-werkgevers rely on external payroll bureaus (€8–15/per/maand) or handmatige Excel. A native Shillinq payroll engine eliminates external dependency, lowers cost, en improves compliance.

## Goals

- **Volledige NL-compliant bruto→netto-berekening** voor werknemers per periode
- **Jaarlijkse tabel-updates** (loonheffing, premies) zonder code-redeployment
- **Reproduceerbaarheid**: Gegeven inputs van jaar X en 2026-tabellen, identieke uitkomsten ook in jaar 2031
- **Integratie met downstream apps** — pensioen (UPA), wkr (eindheffing), liv/lkv, sbr-loonaangifte, ap/ar (betalingen)
- **Audit trail en jaarrecht** — loonstroken + jaaropgaven opgeslagen, recupereerbaar
- **MKB-focus** — 1–50 werknemers per werkgever, geen SAP/AFAS/ADP-schaal

## Non-Goals

- Sector-specifieke CAO-regelingen (600+ varianten) — framework voorzien via `sectorSpecifiekeAttributen` JSON, niet gecodeerd
- Real-time webservice naar Belastingdienst — LH-afdracht wordt VOORBEREID voor SBR-app, niet verzonden
- Multi-currency payroll (T5 treasury-scope)
- Horeca fooienregeling-detail per shift (T2-extension)

## Decisions

### D1 — Entity Hierarchy: Werkgever ← LoonPeriode ← Werknemer ← LoonStrook

```
Werkgever (loonheffingsnummer, sectorcode, awfTarief, wkrBudget)
  ├─ LoonPeriode (periodeType: WEEK|4WEKEN|MAAND, periodeStart/Eind, status: GESLOTEN|OPEN)
  │   └─ LoonStrook (per werknemer per periode, bruto→netto volledig)
  │       ├─ fiscaalLoon (inkomstenbelasting)
  │       ├─ premieloon_SV (met maximum €74.480)
  │       ├─ inhoudingenSV (werknemers-aandeel AWF/AOF/WKO)
  │       ├─ premiesSVWerkgever (werkgever-aandeel)
  │       ├─ zvw (laag/hoog tarief tot €71.628)
  │       ├─ pensioen (werkgever + werknemer aandeel)
  │       ├─ vakantiegeld_reservering_ytd (cumulatief 8%)
  │       └─ nettoBetaald (bruto - loonheffing - inhoudingenSV - pensioen + toelagen)
  ├─ LHAfdracht (per periode, totaal LH + premies SV + ZVW, status: VOORBEREID→VERZONDEN)
  └─ Loonjournaalpost (automatisch gebalanceerd naar GL)
```

Each `LoonStrook` contains **complete line-item detail** (basissalaris, vakantietoeslag, ploegentoeslag, overuren, thuiswerkvergoeding, kilometervergoeding, fooi) with calculated `brutoComponenten.totaal_bruto` fed into the tax-table lookup.

**Why this hierarchy?** Werkgevers run payroll in batches (weekly, 4-weekly, monthly), not per-werknemer. The `LoonPeriode` owns the fiscal period; all werknemers in that periode share the same loonheffingstabel version and premium rates. This prevents inconsistencies (e.g., one werknemer on old tariff, another on new).

### D2 — Versioning of Tax Tables and Premium Rates

`LoonheffingTabel2026`, `PremiesTabel2026` (implied but enumerated as properties of entities), and `ZVWTabel2026` are **immutable snapshots** per loonheffingstabel-versie.

```json
{
  "id": "lht-2026-wit-maand-met-korting",
  "jaar": 2026,
  "kleur": "WIT",
  "periode": "MAAND",
  "metKorting": true,
  "versienummer": "2025-W47",
  "tabelRegels": [
    {"vanaf": 0, "tot": 269, "lh": 0, "korting": 30.00},
    {"vanaf": 270, "tot": 538, "lh": 0, "korting": 75.20},
    ...
  ],
  "bron": "Belastingdienst LH-tabel 2026 januari, versienr 2025-W47",
  "geldigVan": "2026-01-01",
  "geldigTot": null
}
```

When Belastingdienst publiceert een correctietabel per 1 juli 2026, a new `LoonheffingTabel2026` record is created with `geldigVan: 2026-07-01`. Berekeningen voor juni gebruiken de oude tabel; berekeningen voor juli+ gebruiken de nieuwe.

**Why immutable?** Auditors en Belastingdienst kunnen in 2031 vragen "Wat was de LH-tabel mei 2026?" en het systeem geeft het antwoord, niet "we hebben de tabel al upgedate, sorry".

### D3 — Cumulatieven Tracking (fiscaalloon_ytd, vakantiegeld_ytd)

Each `LoonStrook` carries `cumulatieven` object:

```json
"cumulatieven": {
  "fiscaalloon_ytd": 24796.00,
  "vakantiegeld_reservering_ytd": 1983.68
}
```

These are **read-only snapshots at the moment of posting**. The system computes them as `sum(prior_periods_this_year.fiscaalLoon)` + current period, stored to prevent floating-point recalculation errors and to maintain audit trail.

The jaaropgave and loonstrook PDF both reference these cumulatieven; they are never recalculated mid-year.

### D4 — Vakantietoeslag Opbouw vs. Uitbetaling

**Opbouw** (monthly, 8% of bruto):
- `LoonStrook.vakantieDagenReservering.opgebouwdPeriode` = current month's 8% amount
- `cumulatieven.vakantiegeld_reservering_ytd` accumulates all months YTD
- GL credit: 17xx "Te betalen vakantiegeld"

**Uitbetaling** (mei, instelbaar per werkgever):
- `brutoComponenten.vakantietoeslag_uitbetaling` = cumulatief saldo (e.g., €4.180 in mei)
- LH berekend op **bijzondere-tarief-tabel** (groen tabel of "bijzondere bestanddelen")
- `cumulatieven.vakantiegeld_reservering_ytd` reset to zero na uitbetaling

Why separate? Dutch law (Wet ML) mandates vakantietoeslag as part of loon; it cannot remain unpaid at year-end. Opbouw-per-maand + concentratie-in-mei is the standard pattern.

### D5 — DGA-gebruikelijk-loon-controle

Voor werknemers met `is_dga=true`:

```json
{
  "id": "wn-2024-0001",
  "is_dga": true,
  "jaarloonBruto": 48000,
  "gebruikelijkLoonNormJaar": 2026,
  "gebruikelijkLoonNormBedrag": 56000,
  "gebruikelijkLoonUitzondering": null
}
```

**Check**: Jaarloon ≥ €56.000 (2026 norm, wijzigt per 1 januari).

If `jaarloonBruto < 56000` and `gebruikelijkLoonUitzondering == null`, the system flags a **warning** ("DGA-loon onder norm 2026 €56.000") in the payroll dashboard. Exceptions (startup, hardship, comparable function evidence) are recorded in `gebruikelijkLoonUitzondering` field + evidence document ref.

Why? Artikel 12a Wet LB — if a DGA's loon is artificially low, the Belastingdienst can deem the "excess profit" as hidden dividend. Shillinq must warn but not block (the accountant + werkgever make the final decision).

### D6 — Pensioen: Werkgever vs. Werknemer Aandeel

Each `Werknemer` carries:

```json
{
  "pensioenRegeling": "PME_DC",
  "pensioenPremiePctWerkgever": 0.182,
  "pensioenPremiePctWerknemer": 0.072
}
```

`LoonStrook.pensioen`:
```json
{
  "premie_wn_aandeel": 355.68,
  "premie_wg_aandeel": 898.88
}
```

- Werknemer-aandeel is ingehouden op netto (reduces `nettoBetaald`)
- Werkgever-aandeel is a cost (debet 4020 Pensioenpremie WG, credit 1640 Te betalen pensioenpremie)
- Beide worden maandelijks doorgerekend naar UPA-interface (downstream spec `bookkeeping-upa-pensioen`)

### D7 — ZVW Laag/Hoog Tarief

Per werkgever:

```json
{
  "id": "wg-conduction-bv",
  "zvwTarief": "LAAG",  // or "HOOG"
  "zvwMaximumPremieloon2026": 71628
}
```

- **LAAG**: 5,32% (voor ~90% Dutch employers)
- **HOOG**: 6,57% (smaller risk pools, older populations)

Berekening: `min(SV-premieloon, 71628) * tarief_percentage`.

Werknemersverzekering: ZVW is **uitsluitend werkgever-afdracht** (no werknemer-inhouding in Shillinq's scope). Werknemer betaalt ZVW via zorgverzekering-buitenstaanders (niet relevant hier).

### D8 — AWF Laag/Hoog Tarief

Per werkgever, gebaseerd op contracttype-verdeling:

```json
{
  "id": "wg-conduction-bv",
  "awfTarief": "LAAG",  // or "HOOG"
  "premieGroupWW": "SECTORFONDS"  // or "REGULIER"
}
```

- **AWF-LAAG**: Overwegend onbepaalde-tijd schriftelijk contracten → 2,64% 2026
- **AWF-HOOG**: Mix of oproep, bepaalde tijd, etc. → 3,55% 2026

Werkgever kiest bij setup; geen auto-migration mid-year.

### D9 — Sector-specifieke Premies (WHK, Werkhervattingskas)

Each `Werknemer.sectorcode` (32 = Overige zakelijke dienstverlening, etc.) maps to UWV Werkhervattingskas-premie-tarief.

Werkhervattingskas 2026 publiceert per sector, per 1 januari (UWV media).

```json
{
  "sectorcode": 32,
  "sectorOmschrijving": "Overige zakelijke dienstverlening II",
  "whk_tarief_2026": 0.13,  // 0,13% example (varies per sector)
  "wko_tarief_2026": 0.0,   // WKO (kinderopvang) laag sector
  "aof_klein_tarief_2026": 0.0538
}
```

Systeem linkt `Werknemer.sectorcode` → tariff table at berekening-time, fetch 2026-versie.

### D10 — LoonStrook PDF en Jaaropgave

**LoonStrook PDF** (per werknemer, per periode):
- Gegenereerd na `LoonPeriode.status == GESLOTEN`
- Conform art. 626 BW: naam, adres, periode, brutoloon, toelagen, inhoudingenLH, inhoudingenSV, nettoloon, cumulatieven
- Opgeslagen in `openregister` (bewaarplicht 7 jaren)
- Gegenereerd via manifest renderer (template-based PDF, niet hardcoded)

**Jaaropgave** (per werknemer, per jaarrekening):
- Gegenereerd in januari-februari na jaarslot
- Bevat: fiscaalloon JTD, loonheffing JTD, ingehouden ZVW JTD, pensioenpremie JTD, uitgekeerde vakantietoeslag JTD
- Cumulatieven moeten 100% matchen met som van alle 12 maanden `LoonStrook`
- Archief-versie opgeslagen in openregister

### D11 — Automatische GL-Boeking

Per `LoonPeriode.status == GESLOTEN`, automatisch:

```json
{
  "id": "jp-2026-05-wg-conduction-bv",
  "periodeId": "lp-2026-05-wg-conduction-bv",
  "datum": "2026-05-31",
  "regels": [
    {"rekening": "4001", "naam": "Brutolonen", "debet": 87420.00, "credit": 0},
    {"rekening": "4010", "naam": "Sociale lasten WG", "debet": 11213.40, "credit": 0},
    {"rekening": "4020", "naam": "Pensioenpremie WG", "debet": 15880.16, "credit": 0},
    {"rekening": "1610", "naam": "Te betalen netto loon", "debet": 0, "credit": 61240.50},
    {"rekening": "1620", "naam": "Af te dragen LH", "debet": 0, "credit": 18620.10},
    {"rekening": "1630", "naam": "Af te dragen premies SV+ZVW", "debet": 0, "credit": 11213.40},
    {"rekening": "1640", "naam": "Af te dragen pensioenpremie", "debet": 0, "credit": 23439.56}
  ],
  "balanced": true
}
```

Accounts from RGS 3.5 (Referentie Grootboek Schema):
- 4001–4099: Loonkosten
- 1610–1699: Schulden werknemers en publieke instanties

Each regel is a `GLLine` (via `bookkeeping-chart-of-accounts` integration).

---

## Seed Data Examples

### Werknemer (Dutch SMB, mei 2026)

```json
{
  "id": "wn-2024-0042",
  "werkgeverId": "wg-conduction-bv",
  "bsn": "123456789",
  "voorletters": "J.M.",
  "achternaam": "Jansen",
  "geboortedatum": "1985-03-12",
  "geslacht": "M",
  "inDienstSinds": "2024-04-01",
  "uitDienstPer": null,
  "burgerlijkeStaat": "GEHUWD",
  "fiscaalPartnerBsn": "987654321",
  "loonheffingstabel": "WIT_REGULIER",
  "loonheffingstabelKorting": true,
  "loonheffingstabelKortingIngangsdatum": "2024-04-01",
  "sectorcode": 32,
  "sectorOmschrijving": "Overige zakelijke dienstverlening II",
  "premieGroupWW": "SECTORFONDS",
  "premieGroupWGF": "AWF_LAAG",
  "contractType": "ONBEPAALDE_TIJD_SCHRIFTELIJK_GEEN_OPROEP",
  "uurloon": 28.50,
  "contracturenPerWeek": 40,
  "jaarloonSV": 59280.00,
  "vakantiegeldPct": 0.08,
  "eindejaarsuitkeringPct": 0,
  "dertiendeMaand": false,
  "pensioenRegeling": "PME_DC",
  "pensioenPremiePctWerkgever": 0.182,
  "pensioenPremiePctWerknemer": 0.072,
  "auto": null,
  "thuiswerkdagenPerWeek": 2,
  "is_dga": false,
  "gebruikelijkLoonUitzondering": null
}
```

### LoonPeriode (May 2026)

```json
{
  "id": "lp-2026-05-wg-conduction-bv",
  "werkgeverId": "wg-conduction-bv",
  "periodeType": "MAAND",
  "jaar": 2026,
  "periodeNr": 5,
  "periodeStart": "2026-05-01",
  "periodeEind": "2026-05-31",
  "betaaldatum": "2026-05-27",
  "status": "GESLOTEN",
  "loonheffingstabelId": "lht-2026-wit-maand-met-korting",
  "loonheffingstabelVersie": "2025-W47",
  "totaalBrutoloon": 87420.00,
  "totaalNettoBetaald": 61240.50,
  "totaalLHAfdracht": 18620.10,
  "totaalPremiesSVAfdracht": 7559.40,
  "totaalZVWAfdracht": 3654.00
}
```

### LoonStrook (Full Detail)

```json
{
  "id": "ls-wn-2024-0042-2026-05",
  "werknemerId": "wn-2024-0042",
  "periodeId": "lp-2026-05-wg-conduction-bv",
  "brutoComponenten": {
    "basissalaris": 4940.00,
    "vakantietoeslag_uitbetaling": 0,
    "ploegentoeslag": 0,
    "overuren_125pct": 0,
    "thuiswerkvergoeding": 19.20,
    "kilometervergoeding_belastingvrij": 0,
    "fooi": 0,
    "totaal_bruto": 4959.20
  },
  "fiscaalLoon": 4959.20,
  "premieloon_SV": 4940.00,
  "loonheffing": 1083.40,
  "inhoudingenSV": {
    "ww_wn_aandeel": 0,
    "wia_wn_aandeel": 0,
    "totaal_sv_wn": 0
  },
  "premiesSVWerkgever": {
    "awf": 130.85,
    "aof_basis": 360.62,
    "uniforme_opslag_kinderopvang": 2.47,
    "wko": 0,
    "whk": 6.92,
    "totaal_werkgever": 500.86
  },
  "zvw": {
    "ingehouden_wn": 0,
    "afgedragen_wg_5_32pct": 262.80
  },
  "pensioen": {
    "premie_wn_aandeel": 355.68,
    "premie_wg_aandeel": 898.88
  },
  "nettoBetaald": 3520.12,
  "cumulatieven": {
    "fiscaalloon_ytd": 24796.00,
    "vakantiegeld_reservering_ytd": 1983.68
  },
  "vakantieDagenReservering": {
    "opgebouwdPeriode": 2.0,
    "saldoEindPeriode": 12.5
  }
}
```

### Werkgever (SMB Setup)

```json
{
  "id": "wg-conduction-bv",
  "kvk": "12345678",
  "naam": "Conduction B.V.",
  "loonheffingsnummer": "851234567L01",
  "sectorcode": 32,
  "sectorOmschrijving": "Overige zakelijke dienstverlening II",
  "awfTarief": "LAAG",
  "zvwTarief": "LAAG",
  "wkrBudget2026": 8742.00,
  "wkrBudgetVerbruikt2026": 220.00,
  "wkrBudget2026_tot_400k": 8742.00,
  "wkrBudget2026_boven_400k": 0,
  "loonsom2026_tot_400k_pct": 2.47,
  "loonsom2026_boven_400k_pct": 1.18,
  "ploegendienst": false,
  "horeca": false,
  "vakantiegeldUitbetalingMaand": 5
}
```

---

## Integration Points

1. **bookkeeping-chart-of-accounts** — `Account.accountNumber` FK for GL postings (4001–4099 wages, 1610–1699 liabilities)
2. **bookkeeping-ap-ar** — `LHAfdracht` creates AP debt records for Belastingdienst + SV-premies payment
3. **bookkeeping-upa-pensioen** — `LoonStrook.pensioen` fed to UPA monthly submission
4. **bookkeeping-wkr** — `LoonStrook` premium totals feed into WKR-ceiling-tracking
5. **bookkeeping-liv-lkv** — `Werknemer.inkomenniveau` + `LoonStrook.fiscaalLoon` determine LIV/LKV eligibility
6. **bookkeeping-loonaangifte-sbr** — `LHAfdracht` + loonstrook details feed SBR/XBRL monthly submission
7. **openregister** (audit trail, RBAC, attachments) — loonstroken + jaaropgaven archived as documents

---

## Standards & Sources

- Wet op de loonbelasting 1964 — art. 12a (DGA-gebruikelijk loon), art. 13 (loon in natura), art. 31 (eindheffingsbestanddelen)
- Wet op de loonadministratie 1964 — complete record-keeping, loonstrook art. 626 BW
- Wet financiering sociale verzekeringen (Wfsv) 2021 — premies werknemersverzekeringen, franchises 2026
- Zorgverzekeringswet art. 41 — werkgeversbijdrage ZVW tariefen
- Pensioenwet 2007 — wettelijk minimum pensioenpremie
- BW art. 7:610–634 — arbeidsovereenkomst, vakantiedagen opbouw (4× contracturen/week), loonbetaling voorwaarden
- Wet minimumloon en minimumvakantiebijslag (WML) 2026 — minimale vakantiebijslag 8%
- Belastingdienst Handboek Loonheffingen 2026 — LH-tabellen wit/groen/bijzonder
- LH-tabellen 2026 (december 2025 publicatie) — versienr 2025-W47 en volgende
- SBR Nederland Loonaangifte-taxonomie LA-XX-2026 — XBRL-mapping voor Digipoort
- UWV-handleiding sectorindeling Werkhervattingskas 2026 — sector-specifieke premies
- Werkhervattingskas-premies 2026 (UWV september 2025) — per-sector tarieven
- Belastingplan 2025–2026 — aanpassingen heffingskortingen, afbouw ouderenkortingen
- UPA-standaard (Pensioenfederatie) — Uniforme Pensioen Aanlevering interface
- ISO 20022 voor SEPA-betalingen — payroll transfer formatting
- AVG (GDPR) — BSN verwerking, gevoelige werknemersdata
