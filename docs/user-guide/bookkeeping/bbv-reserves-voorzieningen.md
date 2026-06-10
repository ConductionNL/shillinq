---
sidebar_position: 5
title: BBV Reserves & Voorzieningen
description: The accounting distinction, mutation routing, and BBV-conformant treatment of reserves and voorzieningen — including the taakveld 0.10 guard and the BBV art. 44 categorisation.
---

# BBV Reserves & Voorzieningen

A reserve is eigen vermogen earmark; a voorziening is a
verplichting earmark. The distinction matters because reserves
sit on the credit side of the balans (eigen vermogen), are at
the council's discretion, and mutate through taakveld `0.10`,
whereas voorzieningen sit between the eigen vermogen and the
schulden, are required by an external obligation, and mutate
through the gekoppelde taakveld of the underlying activity.

The Reserves & Voorzieningen workspace surfaces both registers
side by side with the right accounting controls baked in.

## Goal

By the end of this guide you can:

- declare a new reserve (algemeen or bestemming) with the right
  raadsbesluit references and rentetoerekening setting,
- declare a voorziening with its BBV art. 44 category and the
  required onderbouwingsdocument link,
- post mutations correctly so the taakveld guards pass, and
- read the paragraaf-weerstandsvermogen quantitatives that
  derive from this workspace.

## Prerequisites

- The active administration is BBV-eligible.
- The `Document` register is available (for the voorziening
  onderbouwingsdocument link).
- An `algemene reserve` row already exists — created
  automatically by the `InitializeBbvAdministration` repair
  step on first BBV-tenant bootstrap. The repair step also
  guarantees taakveld `0.10` exists for every overheidslaag.

## Open the workspace

1. Open **Shillinq → Overheid → BBV → Reserves & Voorzieningen**.
2. The route is `/bbv/reserves-voorzieningen`.
3. The view has two tabs: **Reserves** and **Voorzieningen**.

## Section 1 — Reserves tab

The reserves table lists every `Reserve` for the administration:

| Column | Source |
| ------ | ------ |
| Naam | `Reserve.naam` |
| Soort | `algemeen` / `bestemming` |
| Doel | `Reserve.doel` (required if bestemming) |
| Raadsbesluit instelling | `Reserve.raadsbesluitInstelling` |
| Plafond | `Reserve.plafond` (cents) |
| Bodem | `Reserve.bodem` (cents) |
| Saldo begin | `Reserve.saldoBeginJaar` |
| Saldo eind | `Reserve.saldoEindJaar` (computed) |
| Rentetoerekening | boolean — drives the rente-omslag posting |
| Looptijd einde | `Reserve.looptijdEinde` (date) |

**Mutation routing (REQ-BBV-004).** Any GLLine that touches an
account with `bbvClassificatie = reserve` is required to carry
`taakveld = 0.10`. The `BbvComplianceGuard::reserve_routing_ok`
precondition blocks postings that violate this rule:

```
if account.bbvClassificatie == 'reserve' and gllLine.taakveld != '0.10':
    fail with BBVConstraintError("REQ-BBV-004: reservemutatie moet op taakveld 0.10")
```

A common slip is to book a dotatie aan een bestemmingsreserve
op het programma-taakveld (e.g. 5.3 Cultuurpresentatie). The
guard fires; re-post on taakveld 0.10 and stamp the programme
context in the toelichting instead.

## Section 2 — Voorzieningen tab

The voorzieningen table lists every `Voorziening`:

| Column | Source |
| ------ | ------ |
| Naam | `Voorziening.naam` |
| Categorie (art. 44) | `a` / `b` / `c` / `d` |
| Onderbouwingsdocument | `Voorziening.onderbouwingDocument` |
| Frequentie actualisatie | `Voorziening.actualisatieFrequentieJaar` |
| Volgende actualisatie | `Voorziening.volgendeActualisatie` |
| Taakveld | `Voorziening.taakveld` (the gekoppelde taakveld) |
| Saldo begin | `Voorziening.saldoBeginJaar` |
| Dotaties jaar | `Voorziening.dotatiesJaar` |
| Vrijvallen jaar | `Voorziening.vrijvallenJaar` |
| Saldo eind | `Voorziening.saldoEindJaar` (computed) |

The BBV art. 44 categories are:

- **a** — voorzieningen voor verplichtingen, verliezen en risico's.
- **b** — voorzieningen ter egalisatie van kosten.
- **c** — voorzieningen voor door derden beklemde middelen.
- **d** — voorzieningen voor onderhoud (kapitaalgoederen).

**Mutation routing (REQ-BBV-004).** Postings to a voorziening
account must carry `taakveld = Voorziening.taakveld` (the
gekoppelde taakveld of the underlying activity). The guard fires
on mismatches:

```
if account.bbvClassificatie == 'voorziening' and gllLine.taakveld != voorziening.taakveld:
    fail with BBVConstraintError("REQ-BBV-004: voorzieningmutatie moet op taakveld {expected}")
```

## Section 3 — Paragraaf-koppelingen

The Weerstandsvermogen-paragraaf auto-populates from this
workspace:

- `algemeneReserveSaldo = sum(Reserve.saldoEindJaar where soort = algemeen)`
- `bestemmingsreservesSaldo = sum(Reserve.saldoEindJaar where soort = bestemming)`
- `weerstandsratio = (algemene + bestemming) / risicoTotaal`

The Onderhoud-kapitaalgoederen-paragraaf reads
`voorzieningenOnderhoud = sum(Voorziening.saldoEindJaar where artikel44Categorie = d)`.

The Grondbeleid-paragraaf reads
`voorzieningenGrondexploitatie` filtered on a sibling-spec
`grondexploitatieMarker` flag.

## Section 4 — Troubleshooting

### Reserves tab shows no algemene reserve on a brand-new tenant

The `InitializeBbvAdministration` post-migration repair step
seeds it; if the seed did not run, check the Nextcloud repair
log and re-trigger the step via `occ maintenance:repair`.

### Voorziening creation blocked: onderbouwingsdocument required

Per BBV art. 44 every voorziening needs a current
onderbouwingsdocument. Upload the document to Nextcloud Files
first, then link it on the voorziening form.

### Mutatie geweigerd: "REQ-BBV-004: reservemutatie moet op taakveld 0.10"

Re-post on taakveld 0.10. If the mutatie is conceptueel een
programma-uitgave, you book the programma-lasten on the
programma-taakveld and the resultaatbestemming op 0.10 — twee
journaalposten, niet één.

## What you have now

A complete reserves- en voorzieningenadministratie that
satisfies BBV art. 43 / 44 and drives the
weerstandsvermogen- and onderhoud-paragrafen quantitatives.

## See also

- [MVA-administratie](./bbv-mva-administratie.md) — the asset
  side of the balans and the onderhoud-paragraaf.
- [Paragrafen](./bbv-paragrafen.md) — where the quantitatives
  surface to the auditor.
- [BBV-overzicht](./bbv-compliance-overview.md) — orientation.
