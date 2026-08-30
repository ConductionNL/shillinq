# Design — Extended CBS-bestanden

**status: pr-created**

## Context

T3's `bookkeeping-iv3-reporting` already extracts the BBV programma
totals for the base IV3 CBS submission. The CBS expects additional
periodic bestanden (Iv3-detail per-categorie, Kerngegevens jaarstaten
annual ratios, Iv3-OZB per heffingstijdvak, the EMU-bestand) — each
is a transformation atop the same GL data.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Every extended CBS-bestand is a declarative transformation, NOT a new ledger register

Per ADR-031 and the parent envelope's design D4, each bestand is:

1. An `x-openregister-aggregations` declaration rolling up existing
   GL data.
2. A `docudesk` template producing the output format (CSV / XML /
   SBR per CBS spec).
3. An `openconnector` source row pointing at the CBS endpoint.

No PHP transformation service. No new ledger postings. The
alternative (a thin `CbsExporterService`) was rejected — every
transformation is shape-fixed; service-side code adds drift risk
without expressivity.

### D2 — Iv3-detail rolls up by `(periodId, taakveld, categorie)`

The Iv3-detail bestand is per-taakveld-per-categorie detail
beyond the base IV3 rollup. The aggregation groups `GLLine` records
by `(periodId, taakveld, categorie)` summing `(debit - credit)` in
EUR. Sum across categorieën per taakveld MUST equal the base IV3
total for that taakveld — this invariant is testable in the
implementing cycle.

### D3 — Kerngegevens needs administration-level denominators via a small `kernGegevensConfig` schema

Kerngegevens jaarstaten produces ratios (e.g. lasten per inwoner)
that require denominators not present in the ledger. A small
`kernGegevensConfig` schema is declared per administration with
`inwonerAantal`, `oppervlak`, `gewogenOppervlak`, `bestuursOmvang`,
etc. The aggregation reads these denominators at compute time. No
external feed required at T4-specialized — adopters set the values
operationally.

### D4 — Iv3-OZB needs an `ozbCategorie` flag on `GLLine`

The Iv3-OZB bestand reports OZB-inkomsten + WOZ-waarden per
heffingstijdvak split by eigenaars-deel / gebruikers-deel /
woning / niet-woning. A `GLLine.ozbCategorie` array flag (with
values like `eigenaars-woning`, `gebruikers-niet-woning`) lets the
aggregation split the rollup. No new register; the flag fits on
the existing line.

### D5 — EMU-bestand consumes the sibling EMU-reporting computation

The EMU-bestand is the CBS-facing serialisation of the EMU-saldo /
EMU-schuld computed by sibling `add-shillinq-emu-reporting`. The
aggregation consumes the ESA-2010 classifier + inclusion/exclusion
rules declared there, then renders the CBS EMU XML layout. The
EMU-bestand value MUST equal the EMU-reporting value to the cent.

### D6 — All submissions ride openconnector

Per ADR-019, every CBS submission MUST be an openconnector source
row. Shillinq references the source by id from the aggregation's
output-channel declaration. No `lib/Service/CbsClient.php`.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Base IV3 aggregation | T3 `bookkeeping-iv3-reporting` | Extended bestanden roll up the same GL data with finer-grained groupings. |
| Trial balance for Kerngegevens | T2 `bookkeeping-financial-statements` | Kerngegevens consumes the closed-year jaarrekening. |
| EMU computation | Sibling `add-shillinq-emu-reporting` | EMU-bestand renders the sibling's computation. |
| Document rendering | docudesk (ADR-022) | 4 templates registered. |
| External submission | openconnector (ADR-019) | 4 CBS source rows. |
| Scheduled triggers | OR scheduled-workflow + n8n adapter | Quarterly + annual triggers ride the scheduled-workflow primitive. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.gov-cbs-extended`. |

**Net new code in implementation cycle**: 1 small admin-config
schema (`kernGegevensConfig`) + 1 line flag (`ozbCategorie`) + 4
aggregation declarations + 4 docudesk templates + 4 openconnector
source rows + 1 manifest entry. No new PHP service.

## Seed Data

None for the bestand surface itself. Denominators in
`kernGegevensConfig` are operator-authored per administration.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| CBS layout evolves | Per-template `_meta.cbsSpec` version; multiple templates coexist (`*-2026.csv` → `-2027.csv`). |
| Iv3-detail sum drift vs. base IV3 | Implementing cycle adds a PHPUnit invariant test: sum across categorieën per taakveld equals base IV3 total. |
| Kerngegevens denominators stale | Operator must update `kernGegevensConfig` yearly; aggregation surfaces "denominator last updated" warning when >12 months old. |
| EMU-bestand drift vs. EMU-reporting | Test invariant: EMU-bestand saldo equals EMU-reporting saldo (€0 tolerance). |
