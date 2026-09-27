# Design: planning-budget-editing

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Budget model** (`lib/Settings/register.d/budget-core-schema.json`): `AnnualBudget` (`administrationId`, `fiscalYear`, `isDefault`, `name`, lifecycle `draft`, `active`, `closed`), `BudgetLine` (`annualBudgetId`, `ledgerGroupId`, `month01Amount` to `month12Amount`, `source`, `notes`), `LedgerGroup`. `source` is `manual`, `contract`, `recurring`, `projected` or `scenario`.
- **Grid.** `src/views/BudgetGrid.vue` loads `GET /api/budget-grid` (`BudgetGridController::index()`, `lib/Controller/BudgetGridController.php:151`, route at `appinfo/routes.php:641`). Its inputs are period filters (lines 47-73). The open change `budget-grid-view` built it read-only and lists as non-goals projection, contract derivation, scenarios, charts, multi-administration views and schema changes; editing is not among them.
- **Pages.** `AnnualBudgets` and `BudgetLines` in `src/manifest.d/budget-core-schema.json`; `BudgetGrid` in `src/manifest.d/budget-grid-view.json`.
- **Multi-year schemas without a page.** `MeerjarenBudget` (`lib/Settings/shillinq_register.json`: `financialYear`, `meerjarenHorizon`, `programme`, `taskField`, `revenueCents`, `expensesCents`, `version`) and `Meerjarenraming` (`register.d/bookkeeping-programmabegroting.json`: `year`, `budgetId`, structural and incidental revenue, expenses and balance, `sluitend`).
- **Amendments without a page.** `Begrotingswijziging` is declared twice and merged: `shillinq_register.json` (`number`, `reason`, `amountChangeCents`, `originalAmountCents`, `newAmountCents`, `councilResolutionNumber`, `councilResolutionDate`, `status`, `programme`, `taskField`) and `register.d/bookkeeping-programmabegroting.json` (`changeNumber`, `description`, `movements`, `councilResolution`, `determinationDate`, lifecycle `draft` to `determined` by `vaststellen`). `lib/Lifecycle/BegrotingswijzigingGuard.php:43` guards it.

## Goals / Non-Goals

**Goals**
- Type a budget into the grid; see and seed several years; record amendments.

**Non-Goals**
- Editing derived lines, charts, schema redesign.

## Decisions

### D1. Grid cells write manual lines through OpenRegister

A cell is editable when the ledger group's line for that budget has
`source: manual` or does not exist yet; derived lines show read-only with
their source. Saving a cell writes the month amount on the `BudgetLine`
through the object store (creating the line with `source: manual` when
absent), sending the object's version for an optimistic check. Cells move
with arrow keys and Enter, per ADR-059. The backend grid endpoint stays
read-only; no new write endpoint.

### D2. Spread a yearly amount

A row action "Spread over months" takes a yearly amount and writes twelve
equal months, the last taking the rounding remainder.

### D3. The multi-year page reads annual budgets

A page `MultiYearBudget` shows ledger groups against the fiscal years that
have an `AnnualBudget` (current year and up to four ahead), each cell the
sum of the twelve months. "Start next year" copies the chosen budget's
manual lines into a new `draft` `AnnualBudget` for the next fiscal year,
multiplied by a percentage given by the user.

### D4. Public-sector multi-year schemas get plain pages

Index and detail pages for `MeerjarenBudget` and `Meerjarenraming`, shown
in the public-sector menu only for administrations with a BBV variant.

### D5. Amendments get plain pages with files and history

Index and detail pages for `Begrotingswijziging` showing number, reason,
amounts, council resolution, status and the `vaststellen` button; the
detail page carries OpenRegister's files leaf for attachments and its audit
trail leaf for history (ADR-066), so no new storage is added.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Amendment lifecycle | Declarative, unchanged | Already declared. |
| Multi-year sums | Imperative, in the grid controller's read | Sums across budgets per year for one view. |
| Pages, attachments, history | Declarative: manifest pages and leaves | Page configuration. |

## Seed Data

Gemeente Voorbeeld, ledger group "Personeel" budget 2026 EUR 2,400,000
(EUR 200,000 a month) and a started 2027 budget at plus 3 percent
(EUR 2,472,000). Amendment BW-2026-03, reason "Extra formatie Wmo-consulenten
na raadsbesluit", EUR 180,000 on programme Sociaal Domein, council
resolution 2026-114 of 2026-06-26, with the council letter attached.

## Risks / Trade-offs

- [Editing many cells writes many objects] → saves are per cell on blur, never a whole-grid save.

## Migration Plan

None.

## Open Questions

None.
