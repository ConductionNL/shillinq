# budget-grid-view Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- planning-budget-editing (2026-09-29, archived)

## Purpose

The budget grid is typed into, a yearly amount is spread over the months,
and several years sit side by side. The read-only grid itself (ledger group
tree, any period range, actuals against budget) is specified by the open
change `budget-grid-view`; its requirements join this spec when it is
archived. From shillinq matrix rows `pln-budget-grid` and
`pln-multi-year-budget`.

## Requirements

### Requirement: A controller types manual amounts into the grid (REQ-PBE-001)

The budget grid SHALL let a person edit the month amount of a ledger group
whose budget line is manual or absent, saving it to the `BudgetLine`, and
SHALL show lines of another source read-only with their source. Cells SHALL
be operable by keyboard. A save based on a stale version MUST be refused.

#### Scenario: A controller budgets personnel for January

- GIVEN a controller on the budget grid for the 2027 draft budget of Gemeente Voorbeeld
- WHEN they type 206000 in the January cell of Personeel and press Enter
- THEN the cell shows EUR 206,000 and the budget line holds it after a reload

#### Scenario: A projected line cannot be typed over

- GIVEN a ledger group whose line has source projected
- WHEN the controller focuses its cell
- THEN the cell is read-only and names its source

### Requirement: A yearly amount is spread over the months (REQ-PBE-002)

A grid row SHALL offer Spread over months, writing twelve equal amounts whose
total equals the yearly amount.

#### Scenario: Spreading a year

- GIVEN the Personeel row
- WHEN the controller spreads EUR 2,472,000
- THEN each month shows EUR 206,000

### Requirement: Several years are budgeted side by side (REQ-PBE-003)

A multi-year page SHALL show ledger groups against every fiscal year with an
annual budget, from the current year up to four ahead, and SHALL start next
year's budget from a chosen one with a percentage.

#### Scenario: Starting 2027 at three percent more

- GIVEN the 2026 budget with Personeel at EUR 2,400,000
- WHEN the controller chooses Start next year with 3 percent on the multi-year page
- THEN a 2027 draft budget exists and the page shows Personeel at EUR 2,472,000 for 2027
