# Design: reporting-custom-analysis

Read at shillinq development `79f438f33` and launchpad development on
2026-09-27.

## Context

- **Fixed dashboards.** `src/manifest.json` declares `Dashboard` and several `*Dashboard` pages with fixed widget lists (ADR-036 `widgets[]`). The main dashboard reads `/api/dashboard/financial-summary` (`appinfo/routes.php:90`), served by `FinancialDashboardService::summary()` and `series()` (`lib/Service/FinancialDashboardService.php:118`, `:167`) with turnover, margin and cash figures.
- **No Nextcloud widget.** No class in `lib/` implements `OCP\Dashboard\IWidget` or registers one.
- **Launchpad.** Users build their own dashboards in launchpad and can add any widget another Nextcloud app offers (launchpad matrix `build-nc-widgets`, built); widgets that depend on another app declare it (hydra ADR-113).
- **Signed lines.** `reporting-segment-results` declares `GLLine.signedAmount` and stamps `accountClass` and `countsInResult`.

## Goals / Non-Goals

**Goals**
- A user puts shillinq figures on a dashboard they arrange themselves.
- A user pivots posted ledger lines on two chosen axes.

**Non-Goals**
- A builder in shillinq, spreadsheets.

## Decisions

### D1. Five Nextcloud dashboard widgets

Classes under `lib/Dashboard/` implementing `IAPIWidgetV2`, registered with
`$context->registerDashboardWidget()` by `DashboardWidgetRegistration` (called
from `ReportingRegistration`, because `Application.php` sits at its length
limit). Built API-only: the Nextcloud dashboard and launchpad both render a
widget's API items, so no widget ships a script. Result by month is a list
of the last six months, not a bar chart, for the same reason. Each reads the user's active
administration from `AdministrationContextService` and its figures from
`FinancialDashboardService`, so the numbers match the shillinq dashboard.
Widgets without a readable administration say so instead of showing zero.

Alternative considered: user-editable manifest dashboards in shillinq.
Rejected: it duplicates launchpad, which already does this.

### D2. A pivot endpoint with a fixed vocabulary

`GET /api/analysis/pivot?rows=<axis>&columns=<axis>&from&to` with axes
`account`, `accountGroup`, `period` (month), `quarter`, `costCenter`,
`project`, `customer`, served by `LedgerPivotService` (`lib/Service/Pivot/`;
the directory avoids `.gitignore`'s `*Analysis*` pattern). It sums
`signedAmount` of lines with `accountClass` `pnl` and `countsInResult`, dated
by their transaction's `postingDate`, scoped to the active administration.
An account group is the parent account, or the account type when there is
none. A line's customer is the `subLedgerRef` of the `ar` line in the same
transaction, which is how a revenue line is attributed. It returns cells and
totals, caps each axis at the 200 largest groups (totals still count every
line) and says when it did, and reads at most 50,000 lines and 36 months.

### D3. A pivot page with export

`FinancialPivot` custom page (`/reports/pivot`, with a `_note`: gate 69's
custom-page ratchet goes up by one, because a user-chosen cross table has no
typed archetype), a card on the Reports page (ADR-112): two axis pickers, a
period, the table with totals, and CSV and Excel export of what is shown.
Excel is written as SpreadsheetML in the browser, so no library is added.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Widgets | Imperative, Nextcloud dashboard API classes | The platform's widget contract is PHP. |
| Pivot | Imperative, one read endpoint (ADR-031 exception: report generation) | Two chosen axes per request. |
| Page and card | Declarative: manifest | Page configuration. |

## Seed Data

No schema is added. Adviesbureau Van Dijk, 2026: the pivot with rows
account group and columns quarter shows Omzet EUR 60,000, 58,000 and 62,000
for Q1 to Q3 and Personeelskosten minus EUR 30,000 per quarter; a director
adds "Omzet deze maand" and "Openstaande debiteuren" to their launchpad
dashboard.

## Risks / Trade-offs

- [Widgets on the Nextcloud dashboard for users without an administration] → the widget shows a set-up state, per ADR-113's spirit.

## Migration Plan

None.

## Open Questions

None.
