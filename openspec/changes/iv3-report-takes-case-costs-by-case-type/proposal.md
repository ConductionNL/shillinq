---
kind: code
depends_on: []
---

# Proposal: iv3-report-takes-case-costs-by-case-type

## Why

Decision 261 (Ruben, 30 Oct 2026), under hydra ADR-107 (money and effort ownership): Shillinq is the only Iv3 reporter, and a domain app classifies its cases but never sums money. Dossiq row 10.20, "report case costs per Iv3 taakveld, with 2023 refinement codes rolled up to their parent", therefore lands here. Dossiq's change `case-costs-reported-per-iv3-taakveld` is rescoped to classify (`caseType.iv3TaskField`) and link out to this report. Decision 262 (Ruben, 30 Oct 2026) adds a second grouping on the same cost source: Shillinq also reports the case costs per case type (kosten per zaaktype), so dossiq sums no money for that either.

Two things are missing in Shillinq today:

1. `Iv3ReportGenerator` sums GL lines per taakveld. Nothing in Shillinq reads the taakveld of a case type, so the cost of the work done on cases (hours booked on a case in humaniq, costed by `SubjectCostService`) cannot be shown per taakveld. No spec describes that source.
2. The `BbvTaakveld` catalogue (`lib/Settings/seeds/bbv-taakvelden-*.json`) has no 2023 Wmo/Jeugd refinement codes (`6.71a` to `6.82b`) and no way to say a code rolls up under a parent. Dossiq's own list has both. ADR-107 says that roll-up "MUST be preserved by Shillinq's catalogue or explicitly dropped with a reason"; dossiq can only delete its list after Shillinq carries it.

## What changes

- Catalogue: `BbvTaakveld` gets `aggregatesUnder` and `deprecated`; the municipal seed gets the 18 refinement codes under their 4 pre-2023 parents, with authored English labels (ADR-107 decision 8).
- Report: a generic cost source for "cost of hours on objects, classified by the object's type". A source is configuration: the app, register and schema of the object, the property that points at its type, and the property on the type that holds the taakveld. Dossiq's case is the first configured source (decision 182: nothing in the code is specific to dossiq or to one kind of case).
- A report "Zaakkosten per zaaktype" groups the same source rows per object type for a month, quarter or year, with incomplete costs listed and not summed, a named message when dossiq or humaniq is missing, and a CSV export (REQ-IV3-012). Over a period its total equals the Iv3 case cost section.
- The Iv3 report shows these costs per taakveld per quarter as their own section and CSV, rolled up through `aggregatesUnder`. They are NOT added to the GL lasten: wages are already booked in the ledger, and adding the case cost on top would count them twice. Moving the cost between taakvelden in the ledger is an allocation booking, owned by `public-sector-task-field-allocation`.

## Impact

- `lib/Settings/register.d/` overlay for `BbvTaakveld` (`aggregatesUnder`, `deprecated`), `lib/Settings/seeds/bbv-taakvelden-gemeente-2025.json` (18 codes).
- `lib/Reporting/` a classified-subject cost source read by `Iv3ReportGenerator` and by a new `SubjectCostsPerTypeReportGenerator` registered in `ReportCatalogue`, `lib/Settings/` source configuration with the dossiq entry; reuses `SubjectCostService` / `SubjectCostAggregator` (subject-cost-aggregation).
- Screens: board ShOverheid (Iv3 Q3 tile and report), the target of dossiq's link card on DqRapportages. No board shows the per case type grouping yet; a board lane draws it.
- Depends on `hours-to-humaniq` (hours read from humaniq `TimeEntry`), which the subject cost already reads.
