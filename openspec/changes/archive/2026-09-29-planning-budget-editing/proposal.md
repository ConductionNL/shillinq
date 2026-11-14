---
kind: code
depends_on: [budget-grid-view]
---

# Proposal: planning-budget-editing

## Summary

A controller builds next year's budget by typing into a grid of ledger
groups and months, looks several years ahead in yearly columns, and records
every budget amendment with its status, reason and attachments. Shillinq's
budget grid only reads, budgets are entered one line at a time, the
multi-year schemas have no page, and budget amendments cannot be recorded by
anyone. This change makes the grid editable, adds a multi-year view, and
gives amendments a page.

## Motivation

Three planning rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`pln-budget-grid`**, "Build an annual budget per ledger group in a grid."
Rated partial, built. Matrix evidence: "BudgetLine holds ledgerGroupId +
month01Amount..month12Amount (lib/Settings/register.d/budget-core-schema.json),
entered one record at a time on the BudgetLines index form;
src/views/BudgetGrid.vue only GETs /api/budget-grid ... so the grid is
read-only." Three competitors rate it yes: exact-online
(https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-budget-finbdgt-crtbdgtt),
twinfield (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/budgetteren-3041182)
and odoo (https://www.odoo.com/documentation/19.0/applications/finance/accounting/reporting/budget.html,
"financial budgets on general ledger accounts").

**`pln-multi-year-budget`**, "Budget several years ahead in yearly slices."
Rated partial, built: "One budget per year, no multi-year view." Evidence:
"the Meerjarenraming and MeerjarenBudget schemas that hold the multi-year
horizon have no page." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. Three competitors
rate it yes: exact-online, twinfield
(https://api.accounting.twinfield.com/Api/swagger/docs/open-v1, budget lines
per year) and odoo ("generate multiple periodic budgets").

**`pln-budget-amendments`**, "Record budget amendments with a status, a
reason and attachments, and see their history." Rated no, built state none.
Matrix note: "Begrotingswijziging with a draft->vastgesteld lifecycle and
BegrotingswijzigingGuard (lib/Lifecycle/BegrotingswijzigingGuard.php:43)
exists and BudgetOverrunGuard stacks it, but no manifest page names
Begrotingswijziging, so a user cannot record one." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. odoo is partial
(revisions "marking originals as Revised").

## Affected Projects

- [ ] Project: `shillinq`: grid editing, a multi-year view, pages for the multi-year schemas and for budget amendments.

## Scope

### In Scope

- Editing manual budget amounts per ledger group and month in the budget grid, with keyboard operation.
- Spreading a yearly amount over twelve months from the grid.
- A multi-year page: ledger groups against fiscal years, from the `AnnualBudget` records, and a way to start next year's budget from this one with a percentage.
- Index and detail pages for `Meerjarenraming` and `MeerjarenBudget` for public-sector administrations.
- Index and detail pages for `Begrotingswijziging` with status, reason, council resolution, attachments and history.

### Out of Scope

- Editing projected, contract or scenario lines; those are written by `budget-projection-engine`, `budget-known-costs` and `budget-scenarios`.
- Charts (`budget-charts`).

## Approach

The grid writes `BudgetLine` records through OpenRegister, like the index
form does today. The multi-year view reads `AnnualBudget` and `BudgetLine`
per year. The amendment pages are manifest pages over the schema that
already exists. Details are in design.md.

## New Dependencies

None.

## Impact

- `src/views/BudgetGrid.vue`, `lib/Controller/BudgetGridController.php`.
- `src/manifest.d/budget-core-schema.json`, `budget-grid-view.json`, `bookkeeping-programmabegroting.json`: pages and menu entries.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Two people edit the same cell
**Severity:** Low. **Mitigation:** each save sends the line's version; a stale save is refused and the cell reloads with the other person's value.

## Rollback Strategy

Return the grid to read-only and hide the new pages. Data entered stays.

## Open Questions

None.
