# Proposal: bookkeeping-payroll-engine-nl

`kind: feature` — Full NL Loonadministratie Engine

## Summary

Introduce a **complete Dutch payroll administration engine** for Shillinq as the foundation for all payroll-dependent features (loonheffingen, premies SV, pensioen, ZVW, vakantiegeld-reservering, jaaropgaven). This change adds five new entities to `openspec/architecture/adr-000-data-model.md` — `Werknemer`, `LoonPeriode`, `LoonStrook`, `LoonheffingTabel2026`, `LHAfdracht`, `Loonjournaalpost`, and `Werkgever` — with complete bruto→netto-berekening logic, 2026 tax tables, social insurance premiums (AWF, AOF, WHK, WKO), ZVW-bijdrage, vakantietoeslag opbouw, and automatic general-ledger posting.

The engine integrates with the existing `bookkeeping-pension-ias19`, `bookkeeping-upa-pensioen`, `bookkeeping-wkr`, `bookkeeping-liv-lkv`, and `bookkeeping-loonaangifte-sbr` specs, and feeds LH-afdracht into `bookkeeping-ap-ar` for Belastingdienst payment orchestration.

## Motivation

Dutch payroll administration is a **legal obligation** under the Wet op de Loonadministratie 1964, and every MKB-werkgever (1–50 werknemers) must maintain a complete loonberekening machine-verifieerbaar und reproduceerbaar to comply with:

- **Loonheffingen** (LH) afdracht aan Belastingdienst — monthly, based on bruto→netto calculation against Belastingdienst's published tax tables
- **Premies werknemersverzekeringen** (AWF, AOF, WKO, WHK, Werkhervattingskas) — sector-dependent, franchises, maximum premieloon
- **ZVW-bijdrage werkgever** — 5,32 procent (laag) or 6,57 procent (hoog) tot €71.628 per werknemer per jaar
- **Vakantietoeslag** — 8 procent opbouwing monthly, uitbetaling in mei (art. 7:634 BW)
- **Pensioenpremies** — werkgever + werknemer aandeel, doorgerekend naar pensioenuitvoerder
- **Loonstrook** (art. 626 BW) — per werknemer per periode, met alle verplichte vermeldingen
- **Jaaropgave** — cumulatieve opgave per werknemer voor Belastingdienst en werknemer
- **Loonjournaalpost** — automatische boekstukken naar het grootboek (4xxx loonkosten, 16xx schulden)

Today, many MKB-werkgevers use external payroll bureaus (€8–15/werknemer/maand), DGA-only werkgevers use handmatige Excel (high error risk), and Shillinq has geen native payroll. This spec fills that gap.

## Affected Projects

- [x] Project: shillinq — adds 7 new entities to `openspec/architecture/adr-000-data-model.md`, wired into payroll-app manifest entries, consumes `bookkeeping-chart-of-accounts` (Account, Werkgever setup), `bookkeeping-ap-ar` (LH/SV payment orchestration)
- [x] Project: bookkeeping-pension-ias19, bookkeeping-upa-pensioen, bookkeeping-wkr, bookkeeping-liv-lkv, bookkeeping-loonaangifte-sbr — downstream consumers of `Werknemer`, `LoonPeriode`, `LoonStrook`, `LHAfdracht` data
- [x] Project: hrmq — optional integration for `Werknemer` master (BSN, contract, sector, pensioenregeling) to reduce data-entry burden
- [ ] Project: openregister — no source changes; payroll app uses OR's RBAC, audit trail, attachments for loonstroken + jaaropgaven

## Scope

### In Scope

- Complete `Werknemer` entity with fiscale attributes (BSN, loonheffingstabel, jaarloon, sektor, pensioenregeling, thuiswerkdagen, auto, etc.)
- `LoonPeriode` — week / 4-weken / maand periode definition per werkgever
- `LoonStrook` — per werknemer per periode, bruto componenten, fiscaal loon, premie loon SV, LH, inhoudingenSV, premies SV werkgever, ZVW, pensioen, netto betaald, cumulatieven, vakantie-reservering
- **2026 loonheffingstabellen** (wit/groen, regulier/bijzonder, week/4-weken/maand/jaar, met/zonder korting) — versie-gebonden, immutable
- **Premies werknemersverzekeringen** 2026 — AWF-laag/hoog, AOF, AOF-uniforme opslag kinderopvang, WHK + sectorale opslagen, WKO, Werkhervattingskas (sector-specifiek)
- **ZVW-bijdrage werkgever** — laag/hoog tarief tot maximum premieloon
- **Vakantietoeslag opbouw** — 8 procent monthly, uitbetaling mei (instelbaar per werkgever)
- **Eindejaarsuitkering en 13e maand** — optioneel, per werknemer configureerbaar
- **Belastingvrije toelagen** — kilometervergoeding €0,23/km, thuiswerkvergoeding €2,40/dag, 30%-regeling expat
- **DGA-gebruikelijk-loon-controle** — minimaal €56.000 in 2026 (of hoger vergelijkbaar inkomen)
- **Pensioen** — werkgever + werknemer aandeel doorgerekend naar UPA-interface
- **LH-afdracht** — per maand een samengesteld aggregaat (totaal LH, eindheffingen WKR, premies SV, ZVW) klaar voor SBR/XBRL en Digipoort
- **Loonjournaalpost** — automatische, gebalanceerde GL-boeking per loonperiode (4001 loonkosten, 4010 sociale lasten WG, 4020 pensioenpremie WG, 1610 netto te betalen, etc.)
- **Loonstrook PDF** — generatie conform art. 626 BW met alle verplichte vermeldingen
- **Jaaropgave** — per werknemer, digitaal + PDF, fiscaal loon, LH, ZVW, pensioenpremie, vakantietoeslag
- **Pro-rata mutaties** — indienst halverwege periode, uitdienst met vakantiegeld uitbetaling
- **Reproduceerbaarheid** — alle tabellen, premies, algoritmes versiegebonden en immutable opgeslagen met audit-trail per berekening

### Out of Scope

- **Uitzendkrachten via uitzendbureau** — de uitlener heeft de administratieplicht
- **Sector-specifieke CAO-regelingen** (meer dan 600 CAO's) — framework voorzien via `Werknemer.sectorSpecifiekeAttributen` JSON-object, zonder de engine te overladen
- **Horeca fooienregeling gedetailleerd** — basis-support voor fooieninname, detail-verdeling per shift = T2 uitbreiding
- **Webservices liveprotocol naar Belastingdienst** — LH-afdracht-voorbereiding (VOORBEREID status) sluittijd naar SBR-app
- **Multi-currency payroll** — op termijn (via T5 treasury), nu EUR-only

## Design Principles

1. **Reproduceerbaarheid**: Alle berekeningen over 5 jaar reproduceerbaar met dezelfde inputs + originele tabellen.
2. **Wet-compliantie**: Elk algoritme traceerbaar naar Wet LB, Wet LA, Wfsv, ZVW, Pensioenwet, art. 626-634 BW.
3. **Jaarlijkse flexibiliteit**: Tabellen, franchises, tariefen kunnen 1 januari per jaar worden bijgewerkt zonder applicatie-redeployment.
4. **Integratie zonder dualisering**: LH/SV-afdracht rijdt via bestaande AP-payment-orchestration; pensioenpremies via UPA; WKR-bijzonderheden via WKR-app.
5. **Data-model-fidelity**: Alle entiteiten exact ADR-000 (adr-000-data-model.md) volgen; geen lokale schema-uitbreidingen zonder ADR-wijziging.
