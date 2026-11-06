---
kind: code
depends_on: [reporting-segment-results]
---

# Proposal: reporting-financial-statements

## Summary

A bookkeeper reads the profit and loss next to earlier periods, asks for a
balance sheet on any date, and clicks a figure to see the postings behind
it. Shillinq's statements are generated files: the P&L has one column, the
balance sheet ignores its date and leaves the current year's result out of
equity, and no figure leads anywhere. This change computes both statements
on screen from posted lines, with comparative columns, a real as-of date and
a click-through to the ledger lines, and makes the generated documents use
the same figures.

## Motivation

Three reporting rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27. All five competitors rate each of them
yes.

**`rep-pnl`**, "See profit and loss compared with earlier periods." Rated
partial, built: "A P&L statement exists but has no comparative columns;
only headline KPIs compare periods." Evidence:
`lib/Reporting/Generator/ProfitLossReportGenerator.php` "renders one
period's revenue minus expenses (no prior-period column)". Competitors:
moneybird (https://helpcenter.moneybird.nl/nl/articles/207262-resultatenrekening,
"voor elke willekeurige periode en je kunt meerdere periodes"), odoo
(https://www.odoo.com/documentation/19.0/applications/finance/accounting/reporting.html,
"a % Comparison filter, to compare reporting periods"), exact-online,
snelstart and twinfield.

**`rep-balance-sheet`**, "Produce a balance sheet at any date." Rated
partial, built: "No as-of date filter, and the unclosed year result is left
out of equity." Evidence: `BalanceSheetReportGenerator.php:232 groupByType
uses the account's stored balance or ALL GLLine movements (deriveMovements,
no date filter), so 'per peildatum' is only a cover label; P&L accounts are
skipped and the current result is not added to equity." Competitors: odoo
("Shows assets, liabilities, and equity at a specific date"), moneybird
(https://helpcenter.moneybird.nl/nl/articles/207261-balans), exact-online,
snelstart and twinfield.

**`rep-drilldown`**, "Click a report figure through to the transactions
behind it." Rated no, built state none: "A GL transaction detail shows its
own lines, but no figure-to-transactions path exists." Competitors:
twinfield (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/acties-in-rappo-3726914,
"rapporten bieden de optie om in te zoomen op een bedrag"), moneybird
(https://helpcenter.moneybird.nl/nl/articles/223935-doorklikken-van-ene-naar-andere-categorie),
snelstart ("je kunt dubbelklikken op een" amount), exact-online and odoo.

## Affected Projects

- [ ] Project: `shillinq`: a statement calculator, two on-screen statement pages with a drilldown, and the document generators reading the calculator.

## Scope

### In Scope

- One statement calculator over posted lines: balances as of a date, results over a date range.
- A profit and loss page with the chosen period and up to two comparison periods (previous period, same period last year), with differences.
- A balance sheet page as of any date, with the current year's result in equity until the year is closed.
- Every account figure on both pages opens the ledger lines behind it.
- The P&L and balance sheet document generators reading the same calculator.

### Out of Scope

- A cash flow statement; the annual accounts (`rep-annual-accounts`, deferred).
- Segment statements (`reporting-segment-results`).

## Approach

Screen and document share one calculator. The pages are cards on the
existing Reports page (ADR-112). The drilldown is a ledger line list whose
filters come from route parameters, which `CnPageRenderer` already resolves.
Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Reporting/`: a `StatementCalculator` and the two generators.
- `lib/Controller/`: one read endpoint per statement.
- `src/manifest.json`: two statement pages, a ledger-lines page, two cards on `Reports`.

## Cross-Project Dependencies

None. The generators render through docudesk as the open change
`reports-via-docudesk` arranges; this change only changes the figures they
are given.

## Risks

### Risk 1: Statements disagree with the stored account balances
**Severity:** Medium. **Mitigation:** the calculator reads posted lines only and ignores stored balances; a check compares its closing trial balance with `TrialBalanceService` output in the test suite.

## Rollback Strategy

Remove the two cards. The generators keep working from the calculator,
which is correct in both cases.

## Open Questions

None.
