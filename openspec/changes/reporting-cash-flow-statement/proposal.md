---
kind: code
depends_on: [reporting-financial-statements]
---

# Proposal: reporting-cash-flow-statement

## Summary

A director wants to see where the money went: how much cash the business
made from its operations, how much it spent on investments and how much it
borrowed or paid back. Shillinq declares a `CashFlowStatement` schema but
nothing fills it and no page shows one; the cash export it has is a
forecast, not a statement. This change computes a cash flow statement by the
indirect method from posted ledger lines for any period, shows it next to
the profit and loss and balance sheet, and checks that it adds up to the
change in cash on the balance sheet.

## Motivation

One reporting row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the build-all pass of 2026-09-28: three competitors rate it yes.

**`rep-cashflow-statement`**, "Produce a cash flow statement." Rated no,
built state none. Matrix evidence: "CashFlowStatement schema is declared
(lib/Settings/register.d/bookkeeping-titel-9-jaarrekening.json) but no page
uses it, lib/Reporting/ReportCatalogue.php has no cash-flow report, and
AnnualAccountsReportGenerator.php has no kasstroom section.
CashflowExportService/CashflowPdfRenderer export the cash FORECAST, not a
statement." The matrix note adds that the archived
`2026-06-14-bookkeeping-titel-9-jaarrekening` marked its generation task
deferred and that `reporting-financial-statements` puts the cash flow
statement out of scope, so no change carries it.

Competitors rated yes: exact-online, moneybird and odoo (evidence URLs in
the matrix row).

## Affected Projects

- [ ] Project: `shillinq`: a cash flow classification on accounts, a cash flow calculator on top of the statement calculator, a page with a Reports card, and the annual accounts section.

## Scope

### In Scope

- A `cashFlowCategory` on every balance sheet account, with a check listing the accounts that still lack one.
- The indirect method: result, plus non-cash items (depreciation), plus changes in working capital, then investing and financing movements, reconciled to the change in cash.
- A cash flow statement page for a chosen period with a Reports card, each figure opening the ledger lines behind it.
- The kasstroomoverzicht section in the annual accounts document, filling the declared `CashFlowStatement` record.

### Out of Scope

- The direct method (REQ-T9-005 names it optional); rows can be added later.
- Consolidated cash flow over several administrations.
- The 13-week cash forecast, which stays where it is.

## Approach

The cash flow statement is the difference of two balance sheets plus the
period result, so it reads the `StatementCalculator` that
`reporting-financial-statements` introduces. Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/shillinq_register.json`: `Account.cashFlowCategory`.
- `lib/Reporting/`: a `CashFlowCalculator`; the annual accounts generator gains a section.
- `lib/Controller/`: one read endpoint.
- `src/manifest.json`: one statement page and a Reports card.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Unclassified accounts make the statement not add up
**Severity:** Medium. **Mitigation:** unclassified balance accounts are shown as a separate line "Niet ingedeeld" with a link to classify them, and the reconciliation line shows the difference instead of hiding it.

## Rollback Strategy

Remove the Reports card and the annual accounts section. The account
property is optional and harmless when unused.

## Open Questions

None.
