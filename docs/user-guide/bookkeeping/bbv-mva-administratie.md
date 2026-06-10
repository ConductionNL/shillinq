---
sidebar_position: 6
title: BBV MVA-administratie
description: Materiële Vaste Activa register — categorisation, activation threshold, depreciation method and start logic, componentenmethode, and the activeringsgrens guard.
---

# BBV MVA-administratie

The Materiële Vaste Activa (MVA) administratie is the asset
side of the BBV-balans. Every capitalised investment is one
`MaterieleVasteActiva` record with an aanschafwaarde,
ingebruikname-datum, depreciation method, term, restwaarde and a
gekoppeld taakveld.

This page walks through the workflow and the BBV-specific guards.

## Goal

By the end of this guide you can:

- declare a new MVA with the right BBV-category and
  depreciation parameters,
- understand when the activeringsgrens guard fires,
- enable componenten-methode for compound assets, and
- read the boekwaarde-ultimo that drives the onderhoud-paragraaf.

## Prerequisites

- The active administration is BBV-eligible.
- The Taakveld register has been seeded.
- The administration has a vastgestelde activeringsgrens — the
  default is EUR 50.000 (5.000.000 cents); override via the app
  config key `bbv_activeringsgrens_cents`.

## Open the MVA-register

1. Open **Shillinq → Overheid → BBV → MVA-register**.
2. The route is `/bbv/mva-register`.
3. The index lists every MVA for the administration.

## Section 1 — Index view

| Column | Source |
| ------ | ------ |
| Omschrijving | `MaterieleVasteActiva.omschrijving` |
| Categorie | `economisch-nut` / `economisch-nut-heffing` / `maatschappelijk-nut` |
| Aanschafwaarde | cents |
| Ingebruikname | date |
| Termijn | jaar |
| Methode | `lineair` / `annuitair` |
| Boekwaarde | computed `aanschafwaarde - cumulatieve afschrijving` |
| Volgende afschrijving | next scheduled run |

## Section 2 — Detail editor

The detail editor groups fields by intent:

1. **Identificatie** — omschrijving, mvaCategorie,
   kredietbesluit (raadsbesluit reference).
2. **Financieel** — aanschafwaarde, restwaarde, subsidieVanDerden
   (deducted from de basis for depreciation), boekwaardeBeginJaar.
3. **Afschrijving** — afschrijvingsmethode, afschrijvingstermijnJaar,
   ingebruiknameDatum, renteOmslagPercentage.
4. **Componenten** — componentenMethode boolean; when true a
   sub-grid lets you split the aanschafwaarde across components
   (each with its own termijn). Use it for buildings where the
   roof, installations and shell depreciate over different
   terms (Commissie BBV Notitie MVA 2023).
5. **Programma-koppeling** — taakveld (required so the asset
   surfaces in the onderhoud-paragraaf and the iv3-export).

## Section 3 — Activeringsgrens guard (REQ-BBV-005) {#activeringsgrens}

The `BbvComplianceGuard::mva_activering_ok` precondition prevents
a `maatschappelijk-nut` investment above the activeringsgrens
from being booked directly to the exploitatierekening:

```
if mvaCategorie == 'maatschappelijk-nut' and aanschafwaarde > activeringsgrens:
    if posting routes to a P&L account (debit on lasten):
        fail with BBVConstraintError("REQ-BBV-005: investering > activeringsgrens, moet worden geactiveerd")
```

The threshold is configurable per administration; for most
gemeenten the standard is EUR 50.000 but a council may set a
lower or higher bar. Use the app config key
`bbv_activeringsgrens_cents` to override.

When the guard fires, the fix is to:

1. Cancel the direct P&L posting.
2. Create the MVA record with `mvaCategorie = maatschappelijk-nut`.
3. Re-post against the activa-account; the resulting
   afschrijvingsreeks lands on the exploitatie in monthly chunks.

## Section 4 — Afschrijving (depreciation)

Depreciation **does not** accrue in the month of ingebruikname
(REQ-BBV-005 spec scenario). Example: `ingebruiknameDatum = 2026-09-15`.

| Month | Depreciation | Notes |
| ----- | ------------ | ----- |
| 2026-09 | 0 | month of ingebruikname |
| 2026-10 | first | start of straight-line schedule |
| 2026-11 .. | ongoing | one chunk per month |

For straight-line:

```
maandbedrag = (aanschafwaarde - restwaarde - subsidieVanDerden) / afschrijvingstermijnJaar / 12
```

For annuïtair the bedrag wijzigt per maand met dezelfde
totaalrente; gebruik annuïtair voor leningen-gefinancierde MVA
waar de exploitatie-impact constant moet blijven.

The schedule is stored in the `DepreciationSchedule` register
for audit purposes; the monthly afschrijvingspost wordt
automatisch geboekt via de `FixedAssets monthly depreciation`
ScheduledWorkflow geregistreerd door `InitializeSettings`.

## Section 5 — Componentenmethode

For samengestelde activa schakel **componentenMethode = true** in
de detail. Voer per component een eigen termijn in; Shillinq
splitst de aanschafwaarde proportioneel en boekt per component
een aparte regel in de DepreciationSchedule. Dit is verplicht
voor schoolgebouwen, sportcomplexen en andere assets waar
deelcomponenten significant afwijken in termijn (Notitie MVA 2023).

## Section 6 — Troubleshooting

### Boekwaarde ultimo is hoger dan ik verwacht

Verifieer dat de eerste afschrijving niet per ongeluk in de
maand van ingebruikname is geboekt — de scheduler slaat die
maand bewust over. Reken één maand minder af.

### De monthly depreciation workflow draait niet

Check **Settings → Background jobs**. De
`FixedAssetsMonthlyDepreciationWorkflow` wordt door
`InitializeSettings` geregistreerd; als deze ontbreekt, run
`occ maintenance:repair` om de workflow opnieuw te registreren.

### Activeringsgrens guard fires for an `economisch-nut` asset

De guard checkt alleen `maatschappelijk-nut`. Voor
`economisch-nut` (en `economisch-nut-heffing`) activeer je altijd,
maar de exploitatie-blok zit er niet op — de mismatch komt
hoogstwaarschijnlijk van een verkeerd ingestelde `mvaCategorie`.
Open het MVA-record en corrigeer de categorie.

## What you have now

Een complete MVA-administratie met correcte afschrijvingsreeks,
componenten-splitsing waar nodig en een gevulde
boekwaarde-ultimo voor de onderhoud-kapitaalgoederen paragraaf.

## See also

- [Reserves & Voorzieningen](./bbv-reserves-voorzieningen.md) —
  egalisatie-voorzieningen voor onderhoud aan deze MVA.
- [Paragrafen](./bbv-paragrafen.md) — Onderhoud kapitaalgoederen
  en de bijbehorende quantitatives.
- [Rechtmatigheidsverantwoording](./bbv-rechtmatigheid.md) —
  incidentele overschrijdingen op MVA-budgetten.
