---
sidebar_position: 3
title: BBV Meerjarenraming
description: Multi-year budgeting workflow for the MeerjarenBudget register — inflation handling, stelselwijziging, the sluitend-validation, and how begrotingswijzigingen amend a published meerjarenraming.
---

# BBV Meerjarenraming

The meerjarenraming is the four-year (T, T+1, T+2, T+3) projection
of baten and lasten per programma × taakveld × economische_categorie
that every BBV-tenant must publish along with the begroting (BBV
art. 17). Shillinq stores it as one `MeerjarenBudget` row per
`(programma, taakveld, economische_categorie, boekjaar, meerjaren_horizon, versie)`.

## Goal

By the end of this guide you can:

- enter or import a four-year budget for a programma,
- apply an inflation step to T+1..T+3,
- pass the sluitend-validation (REQ-BBV-003) or, where it fails,
  document a raadsbesluit override, and
- amend a published meerjarenraming via a `Begrotingswijziging`.

## Prerequisites

- A `Programma` record exists for the active administration and
  boekjaar (see [Programmaplan](./bbv-programmaplan.md)).
- The Taakveld + EconomischeCategorie catalogues are seeded.
- Reserves and Voorzieningen used as funding sources are declared
  (see [Reserves & Voorzieningen](./bbv-reserves-voorzieningen.md))
  — without them the saldo computation cannot net resultaatbestemming.

## Open the meerjarenraming

1. Open **Shillinq → Overheid → BBV → Meerjarenraming**.
2. The route is `/bbv/meerjarenraming`.
3. The index lists every MeerjarenBudget row for the active
   administration in the current cyclus.

## Section 1 — Index view

The index pivots on programma × taakveld × economische_categorie
across the four jaren. Each cell shows `baten − lasten` as a
single signed number, with a sub-line breaking out baten and
lasten separately on hover.

| Column | Source |
| ------ | ------ |
| Programma | `MeerjarenBudget.programma` |
| Taakveld | `MeerjarenBudget.taakveld` |
| Econ. categorie | `MeerjarenBudget.economischeCategorie` |
| T | rows where `meerjarenHorizon = 0` |
| T+1 | rows where `meerjarenHorizon = 1` |
| T+2 | rows where `meerjarenHorizon = 2` |
| T+3 | rows where `meerjarenHorizon = 3` |
| Versie | `primitief` / `na-wijziging` / `realisatie` |

Use the **Bulkbewerking** action to apply inflation: enter an
inflatie-percentage and a starting horizon; Shillinq multiplies
every row's `bedrag_lasten` (not `bedrag_baten` — index salaris-
prijscompensatie is on the cost side only) by `(1 + perc/100)`
per horizon step.

## Section 2 — Detail editor

The detail editor is a per-row form with:

- `programma`, `taakveld`, `economischeCategorie` — references.
- `bedragBaten`, `bedragLasten` — integer-cent decimals.
- `versie` — `primitief` (initial begroting), `na-wijziging`
  (after a begrotingswijziging) or `realisatie` (jaarrekening).
- `meerjarenHorizon` — 0 (T), 1, 2, 3.
- `toelichting` — free-text explanation per row.
- `stelselwijziging` — boolean; flip when this row reflects a
  fundamental policy or accounting-method change, so the
  jaarrekening narrative can highlight it.

Saving creates an audit-trail entry; the previous version stays
queryable through the OR history endpoint.

## Section 3 — Sluitend-validation (REQ-BBV-003) {#sluitend}

When a controller invokes **Publiceren** on a Programma the
`BbvComplianceGuard::meerjarenraming_sluitend_ok` precondition
runs:

```
for each horizon in [T, T+1, T+2, T+3]:
    saldo = sum(bedragBaten) - sum(bedragLasten) + sum(reserveMutaties)
    if saldo < 0 and no raadsbesluitOverride:
        fail with BBVConstraintError("REQ-BBV-003: jaar {year} niet sluitend, tekort {saldo} EUR")
```

The arithmetic uses **integer cents** throughout — there is no
floating-point rounding. The override is signalled by setting
both `raadsbesluit_nummer` and `raadsbesluit_datum` on the
Programma; the audit trail captures the override and the
jaarrekening narrative reproduces it.

## Section 4 — Begrotingswijziging

A published meerjarenraming is immutable. Amendments flow through
the `Begrotingswijziging` register:

1. Open **Shillinq → Overheid → BBV → Begrotingswijzigingen**.
2. **+ Begrotingswijziging toevoegen** opens a wizard. Pick the
   target Programma, Taakveld and EconomischeCategorie.
3. Enter the wijziging in cents (positive = lasten omhoog or
   baten omlaag, negative = inverse). Reference the raadsbesluit.
4. **Status:** draft → vastgesteld (after raadsbesluit) →
   verwerkt (after Shillinq stacks the new `MeerjarenBudget`
   rows with versie `na-wijziging`).

The stacking is performed by the `BegrotingswijzigingStacker`
service; the resulting rows preserve the old `primitief` row
alongside the new `na-wijziging` row so the jaarrekening can
reconcile against either.

## Section 5 — Seed data {#seed-data}

The post-migration repair step (`BbvSeedService`) imports four
catalogues:

| File | Schema | Rows |
| ---- | ------ | ---- |
| `bbv-taakvelden-gemeente-2025.json` | `Taakveld` | 53 gemeente |
| `bbv-taakvelden-provincia-2025.json` | `Taakveld` | 14 provincie |
| `bbv-taakvelden-waterschap-2025.json` | `Taakveld` | 10–12 waterschap |
| `economische-categorieen-2025.json` | `EconomischeCategorie` | ~150 |
| `beleidsindicatoren-bbv-2025.json` | `BeleidsIndicator` | 39 |
| `rgs-decentraal-2025.json` | `BbvAccountMapping` | ~200 |

Each file carries an `_meta.iv3Version` + `_meta.effectiveDate`
header; the seed service dedupes on `(code, overheidslaag)` for
taakvelden and on `code` (resp. `rgsDecentraalCode`) for the
other catalogues. Operator edits are preserved on re-run.

## Section 6 — Troubleshooting

### Sluitend-check fails for T+1 only

Inflation defaults to 0%. Apply a realistic
prijscompensatie-percentage in **Bulkbewerking** (typically the
CPB index), or recompute the funding from reserves. The detail
editor shows the cumulative saldo per horizon next to each row
so you can spot the dip without leaving the page.

### Begrotingswijziging stays in `vastgesteld` and is not stacked

`BegrotingswijzigingStacker` runs in the background-job queue. If
the run did not happen within a minute, check the Nextcloud job
queue — the worker may have been paused.

### The taakveld dropdown is empty

The Taakveld register filter is `overheidslaag`. Confirm the
administration's type matches the seed file you expect to see.
A `gemeente` administration cannot pick a `provincie` taakveld.

## What you have now

A four-year budget that passes the sluitend-check, with the
inflation steps applied and any overrides documented. The
Programma can now be published, which unlocks the
[Paragrafen](./bbv-paragrafen.md) workspace.

## See also

- [Programmaplan](./bbv-programmaplan.md) — Programma CRUD that
  drives this workspace.
- [Reserves & Voorzieningen](./bbv-reserves-voorzieningen.md) —
  funding sources for the saldo computation.
- [BBV Compliance Dashboard](./bbv-compliance-dashboard.md) —
  budget-vs-actuals at a glance.
