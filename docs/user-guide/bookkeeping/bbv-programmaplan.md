---
sidebar_position: 2
title: BBV Programmaplan
description: CRUD workflow for the Programma register — programmanummer, doelstellingen, beleidsindicatoren — and how it ties into the BBV-mapping approval queue.
---

# BBV Programmaplan

The programmaplan is the council-approved indeling of an
administration's programmes. Per BBV art. 8 it carries, for each
programme: a number, a name, the responsible portefeuillehouder,
the taakvelden it covers, the doelstellingen (wat, wanneer, kpi)
and a set of beleidsindicatoren.

Shillinq stores every programme as one `Programma` record per
`(administrationId, nummer, boekjaar)`. The Programmaplan
workspace lets the controller create, edit, version and publish
each programme.

## Goal

By the end of this guide you can:

- create a Programma record with at least one doelstelling and
  one beleidsindicator,
- attach the taakvelden that fall under the programme,
- review the BBV-mapping approval queue produced by the
  `RgsAccountMapper` backfill service, and
- publish the programmaplan when the meerjarenraming is sluitend.

## Prerequisites

- The active administration is BBV-eligible (see
  [overview](./bbv-compliance-overview.md)).
- The `Taakveld` register has been seeded — confirm by visiting
  **Shillinq → Overheid → BBV → Taakveld register**. The seed
  ships 53 gemeente / 14 provincie / 10–12 waterschap taakvelden;
  if the page is empty the post-migration repair step has not run
  yet.
- The current `boekjaar` matches the `FiscalYearContextService`
  configuration. Without an active fiscal year the Programma form
  blocks publication.

## Open the programmaplan

1. Open **Shillinq → Overheid → BBV → Programmaplan**.
2. The route is `/bbv/programmaplan`.
3. The index lists every Programma for the active administration
   in the current boekjaar, sorted by `nummer`.

## Section 1 — Index view

The index is a sortable table with the columns:

| Column | Source |
| ------ | ------ |
| Nummer | `Programma.nummer` |
| Naam | `Programma.naam` |
| Portefeuillehouder | `Programma.portefeuillehouder` |
| Taakvelden | count of `Programma.taakvelden` |
| Doelstellingen | count of `Programma.doelstellingen` |
| Beleidsindicatoren | count of `Programma.beleidsindicatoren` |
| Versie | `Programma.versie` (begroting / jaarrekening / burap-1 / burap-2 / marap / tussenrapportage) |

Use the **+ Programma toevoegen** action to open the detail form.

## Section 2 — Detail form

The detail form is split into four sections:

1. **Programmagegevens** — nummer (int 0-99), naam, omschrijving,
   portefeuillehouder, boekjaar (defaults to current).
2. **Taakvelden** — a multi-select against the `Taakveld`
   register. The list is automatically filtered to the
   administration's overheidslaag.
3. **Doelstellingen** — a nested array editor; each row carries
   `wat` (free text), `wanneer` (date) and `kpi` (free text).
   Add as many as needed; the BBV does not prescribe a count.
4. **Beleidsindicatoren** — references the `BeleidsIndicator`
   register. The 39 fixed indicatoren are pre-loaded; you can
   add custom indicatoren by creating BeleidsIndicator rows
   first (see "RGS-mapping" below for the same pattern).

Saving the form persists the Programma in `draft` versie. The
**Publiceren** action moves it to `begroting` and triggers the
sluitend-check on the underlying `MeerjarenBudget` rows — see
[Meerjarenraming](./bbv-meerjarenraming.md).

## Section 3 — RGS-mapping {#rgs-mapping}

The Programmaplan workspace embeds a **BBV-mapping approval**
panel sourced from `OCA\Shillinq\Service\BbvMigration\RgsAccountMapper`.
For every Account in the administration with no
`rgsDecentraalCode`, the mapper produces a candidate suggestion
scored 0–100:

| Score | Reason |
| ----- | ------ |
| 100 | exact `referentienummer` match |
| 95 | exact `rgsDecentraalCode` match against Account.code |
| 80 | exact `rgsCode` match against Account.code |
| ≤ 70 | fuzzy name match (`omschrijvingKort` ↔ `Account.name`) |

The default confidence threshold is 70; suggestions below the
threshold are not shown. Override the threshold via the app config
key `bbv_rgs_mapper_confidence_threshold` (0-100). Approve or
reject each suggestion; approval mutates the Account record's
`rgsDecentraalCode`, `taakveld` and `economischeCategorie` fields
through the regular OR PUT, and audit-trails the operator.

## Section 4 — Versie en publicatie

The `versie` enum captures the BBV reporting cycle:

- `begroting` — initial budget (T-1, before October).
- `burap-1` / `burap-2` — bestuursrapportages in T.
- `marap` — managementrapportage (optional, per administration).
- `tussenrapportage` — ad-hoc tussenrapportage.
- `jaarrekening` — annual account (T+1, before July).

Each publication produces an immutable snapshot via the OR audit
trail; subsequent edits create a new row under the same
`(nummer, boekjaar)` with the next versie.

## Section 5 — Troubleshooting

### Publish blocked: "REQ-BBV-003: jaar 2028 niet sluitend"

The `MeerjarenBudget` rows for this programma have a negative
saldo in at least one of T..T+3. Open
[Meerjarenraming](./bbv-meerjarenraming.md), filter on this
programma and fix the underlying rows; alternatively, provide a
`raadsbesluit_nummer` + `raadsbesluit_datum` to override.

### I see no doelstellingen on a published programma

The doelstellingen editor only saves rows with a non-empty `wat`
field. Re-open the programma detail and add the rows.

### The mapping panel is empty

Either every Account already carries an `rgsDecentraalCode`
(the panel is empty because there is nothing left to approve) or
no Account candidates score above the confidence threshold. Try
lowering the threshold temporarily, or seed extra RGS-decentraal
rows via the openconnector import.

## What you have now

A complete Programma record with doelstellingen, beleidsindicatoren
and taakveld coverage, plus an empty approval queue ready for the
next migration wave. Continue with the meerjarenraming workflow.

## See also

- [Meerjarenraming](./bbv-meerjarenraming.md) — multi-year
  budgeting and the sluitend-validation that gates publication.
- [Paragrafen](./bbv-paragrafen.md) — the seven obligatory
  paragrafen for the jaarrekening.
- [BBV Compliance Dashboard](./bbv-compliance-dashboard.md) —
  real-time KPI cards and exception alerts.
