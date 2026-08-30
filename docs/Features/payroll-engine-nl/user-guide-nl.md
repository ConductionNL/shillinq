---
sidebar_position: 1
title: NL Loonadministratie — Gebruikershandleiding
description: Nederlandstalige handleiding voor de Shillinq NL-loonadministratie (loonheffingen, premies SV, ZVW, pensioen, vakantietoeslag, jaaropgave).
---

# Loonadministratie — Gebruikershandleiding

Deze handleiding beschrijft hoe je in Shillinq een volledige Nederlandse
loonadministratie voert: van werkgever-setup tot loonstrook, LH-afdracht en
jaaropgave. De gebruikte rekenregels volgen het Belastingdienst Handboek
Loonheffingen 2026, de Wfsv (premies werknemersverzekeringen), de ZVW
(zorgverzekeringswet art. 41), en BW 7:610–634.

## 1. Werkgever-setup

Je maakt eerst een **Werkgever** aan. Verplichte velden:

- `kvk` — KvK-nummer (8 cijfers)
- `naam` — bedrijfsnaam zoals geregistreerd bij de Belastingdienst
- `loonheffingsnummer` — door de Belastingdienst toegekend (formaat
  `123456789L01`)
- `sectorcode` — UWV-sectorindeling (1..69) — bepaalt WHK-tarief
- `awfTarief` — `LAAG` (overwegend onbepaalde-tijd) of `HOOG` (mix)
- `zvwTarief` — `LAAG` (5,32%) of `HOOG` (6,57%)
- `wkrBudget2026` — vrije ruimte WKR (2,47% over loonsom tot €400.000;
  1,18% daarboven)
- `vakantiegeldUitbetalingMaand` — meestal `5` (mei)

Je kunt meerdere werkgevers per administratie hebben — de engine scope alle
berekeningen per (administratie, werkgever).

## 2. Werknemers-master

Per werknemer:

- BSN (wordt versluierd weergegeven; alleen back-end zichtbaar voor de
  betaalrol)
- `voorletters`, `achternaam`, `geboortedatum`, `geslacht`,
  `burgerlijkeStaat`
- `inDienstSinds` (verplicht), `uitDienstPer` (optioneel)
- `loonheffingstabel` — `WIT_REGULIER`, `GROEN_REGULIER`, of `BIJZONDER`
- `loonheffingstabelKorting` — `true` als de werknemer
  loonheffingskorting kiest bij deze werkgever (slechts één werkgever
  tegelijk!)
- `sectorcode` (overerft van werkgever, kan per werknemer afwijken)
- `contractType` — `ONBEPAALDE_TIJD_SCHRIFTELIJK_GEEN_OPROEP`,
  `BEPAALDE_TIJD`, `OPROEP`, etc.
- `uurloon` en `contracturenPerWeek` (1–40)
- `jaarloonSV` — geschat jaarloon voor SV-grondslagen
- `vakantiegeldPct` — meestal `0.08` (8%, wettelijk minimum)
- `pensioenRegeling` — slug van de pensioenuitvoerder
  (`PME_DC`, `PFZW`, etc.)
- `pensioenPremiePctWerkgever`, `pensioenPremiePctWerknemer`
- `is_dga` — `true` voor directeur-grootaandeelhouder (zie §7)
- `expat30PctRegeling` — `true` als de 30%-regeling van toepassing is
  (REQ-PAY-015)

## 3. Loonperiode openen

Een **LoonPeriode** is de werkgever-brede batch (week / 4 weken / maand)
waarop alle werknemers worden uitbetaald.

1. Kies `periodeType` (vrijwel altijd `MAAND`)
2. Vul `periodeStart`, `periodeEind`, `betaaldatum` in
3. Selecteer (of laat automatisch resolven) de geldende
   `LoonheffingTabel2026` (versie 2025-W47 voor januari–december 2026,
   correctieversies krijgen `geldigVan` later in het jaar)
4. Status start op `OPEN`

## 4. Loonstrook berekenen

Voor elke werknemer binnen de open periode:

```
GET /api/payroll/loonstrook
    ?administration_id={uuid}
    &werknemer_id={uuid}
    &periode_id={uuid}
```

De engine berekent:

1. **Bruto-componenten** (basissalaris + thuiswerkvergoeding +
   kilometervergoeding + 30%-vergoeding indien expat)
2. **Fiscaal loon** = totaal bruto − belastingvrije toelagen
3. **Loonheffing** uit de immutable `LoonheffingTabel2026` (lineair geschaald
   per periodetype)
4. **SV-premies werkgever** (AWF + AOF + WHK + WKO) op `premieloon_SV`
   gecapt op de jaarmaxima (€74.480 in 2026), pro-rata per periode
5. **ZVW werkgever** (laag 5,32% / hoog 6,57%) op het ZVW-premieloon
   gecapt op €71.628 (2026), pro-rata
6. **Pensioenpremie** (werkgever + werknemer aandeel)
7. **Vakantietoeslag-opbouw** (8% van bruto, cumulatief in
   `vakantiegeld_reservering_ytd`)
8. **Netto betaald** = fiscaal − loonheffing − inhoudingen SV − pensioen
   werknemer + belastingvrije toelagen

## 5. Loonperiode sluiten — LH-afdracht en journaalpost

Wanneer alle loonstroken berekend en gepersisteerd zijn:

```
GET /api/payroll/lh-afdracht
    ?administration_id={uuid}
    &periode_id={uuid}
    &eindheffingen_wkr={bedrag van WKR-app}
```

De LHAfdracht aggregeert:

- `totaalLoonheffing` — som van alle loonstroken
- `totaalPremiesSV` — som van alle werkgever-SV
- `totaalZVW` — som van alle ZVW-werkgever
- `totaalEindheffingenWKR` — door de WKR-app aangereikt
- `vervaldagAfdracht` — laatste dag van de volgende maand
- Status: `VOORBEREID` → klaar voor SBR-conversie via
  `PayrollSbrConversionService` (zie hand-off)

En de Loonjournaalpost (REQ-PAY-012):

```
GET /api/payroll/journaalpost
    ?administration_id={uuid}
    &periode_id={uuid}
```

Genereert een gebalanceerde grootboekboeking met de canonieke RGS 3.5
rekeningen (4001 Brutolonen, 4002 Belastingvrije vergoedingen, 4010
Sociale lasten WG, 4012 ZVW WG, 4020 Pensioenpremie WG, 1610 Te betalen
netto loon, 1620 Af te dragen LH, 1630 Af te dragen premies SV+ZVW, 1640
Af te dragen pensioenpremie). De engine weigert een niet-gebalanceerde
boeking — `balanced=false` triggert een 500 zonder side-effects.

## 6. Gangbare mutaties

### Indienst halverwege periode

Vul `inDienstSinds` in op de echte indienstdatum. De engine herkent dit en
schaalt de bruto pro-rata over `werkdagen_dienst / werkdagen_periode`
(REQ-PAY-014).

### Uitdienst met vakantiedagen-uitbetaling

Vul `uitDienstPer` in en de openstaande
`vakantieDagenReservering.saldoEindPeriode`. De engine telt
`(vakantiedagen × jaarloon / 261)` op bij het bruto van de laatste periode.

### Contractwijziging mid-periode

Splits de LoonPeriode in twee sub-periodes (datums voor/na de wijziging) en
zet de juiste velden op de Werknemer voor elk subblok.

## 7. DGA-gebruikelijk-loon

Zet `is_dga=true` op de werknemer. De engine vlagt een **waarschuwing** in
het dashboard wanneer `jaarloonBruto < €56.000` (norm 2026) zonder dat een
`gebruikelijkLoonUitzondering` is ingevuld. De waarschuwing blokkeert niet —
de accountant en werkgever beslissen samen. Het bewijs voor een uitzondering
(startup, hardheidsclausule, vergelijkbare functie) noteer je in
`gebruikelijkLoonUitzondering` met een evidence-document.

## 8. Vakantietoeslag uitbetaling

In de configureerbare maand (default mei, instelbaar per werkgever via
`vakantiegeldUitbetalingMaand`):

1. Het cumulatief `vakantiegeld_reservering_ytd` van januari tot en met
   april wordt als `brutoComponenten.vakantietoeslag_uitbetaling`
   uitgekeerd
2. Loonheffing wordt op de **bijzondere-tarief-tabel** berekend
3. Na uitbetaling reset `vakantiegeld_reservering_ytd` naar nul

## 9. Jaaropgave

Aan het einde van het kalenderjaar (vaak januari–februari):

- `PayrollJaaropgaveService::bouwJaaropgave(adm, werknemer, jaar)`
  aggregeert alle 12 loonstroken
- De engine controleert of de cumulatieven (`fiscaalloon_ytd` in
  december) gelijk zijn aan de som van alle perioden — anders staat het
  veld `cumulatievenConsistent=false` en weigert
  `persistJaaropgave` te persisteren
- Status start op `CONCEPT` — PDF-render en SBR-export gebeuren in de
  bookkeeping-loonaangifte-sbr app

## 10. Foutmeldingen

| Fout | Oorzaak | Oplossing |
| --- | --- | --- |
| `Werkgever niet gevonden` | Werknemer wijst naar `werkgeverId` buiten administratie | Controleer scoping |
| `Loonperiode of werknemer niet gevonden` | ID's behoren niet tot deze administratie | Check de scope |
| `Loonjournaalpost is niet in balans` | Een loonstrook is na bouw aangepast en niet opnieuw doorgerekend | Re-run berekenLoonStrook voor alle werknemers |
| `Jaaropgave-cumulatieven matchen niet` | Een loonperiode is achteraf veranderd zonder her-stempelen | Re-run berekenLoonStrook voor de gewijzigde periodes |
| `LH-afdracht > 0 maar geen sbrInstanceRef` | LHAfdracht nog niet door SBR-converter heen | Roep `PayrollSbrConversionService::stampInstanceRef` aan |

## Standards-referentie

- Wet op de loonbelasting 1964 (Wet LB)
- Wet op de loonadministratie 1964 (Wet LA)
- Wet financiering sociale verzekeringen 2021 (Wfsv)
- Zorgverzekeringswet art. 41 (ZVW)
- BW art. 7:610–634 (arbeidsovereenkomst, loonbetaling, vakantie)
- Belastingdienst Handboek Loonheffingen 2026
- LH-tabellen 2026 versie 2025-W47 (december 2025-publicatie)
- UWV Werkhervattingskas-premies 2026

## Verwante hand-off services

- `PayrollSbrConversionService` — LHAfdracht → SBR/XBRL LA-XX-2026
- `PayrollApArHandoffService` — LHAfdracht → AP transacties
  (Belastingdienst, UWV)
- `PayrollUpaHandoffService` — LoonStrook.pensioen →
  UPA-monthly-submission per pensioenuitvoerder
- `PayrollWkrHandoffService` — LoonStrook → loonsom-totaal voor
  WKR-budget-tracking
- `PayrollLivLkvHandoffService` — Werknemer.inkomenniveau +
  fiscaalLoon → LIV/LKV eligibility
