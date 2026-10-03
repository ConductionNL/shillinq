# bookkeeping-financial-statements Specification (delta)

## Purpose

Statements are computed from posted lines on screen, compare periods, honour
their date and lead to the lines behind each figure. From shillinq matrix
rows `rep-pnl`, `rep-balance-sheet` and `rep-drilldown`.

## ADDED Requirements

### Requirement: Profit and loss compares periods (REQ-RFS-001)

The profit and loss page SHALL show the result per account for a chosen date
range and, on request, for the previous period and the same period last
year, each with the difference.

#### Scenario: A director compares September year to date

- GIVEN a director on the profit and loss page with range 2026-01-01 to 2026-09-30 and comparison same period last year
- WHEN the page loads
- THEN it shows revenue EUR 180,000 against EUR 150,000 and a difference of EUR 30,000
- AND the result EUR 48,000 against EUR 30,000

### Requirement: The balance sheet honours its date and includes the running result (REQ-RFS-002)

The balance sheet page SHALL show balances of posted lines up to the chosen
date, and SHALL show the current fiscal year's result to that date as an
equity line while the year is open, so that assets equal liabilities plus
equity.

#### Scenario: A balance sheet at the end of September

- GIVEN an open fiscal year 2026 with a result to 2026-09-30 of EUR 48,000
- WHEN the bookkeeper opens the balance sheet as of 2026-09-30
- THEN equity shows "Resultaat lopend boekjaar" EUR 48,000
- AND total assets equal total liabilities plus equity

#### Scenario: A posting after the date is left out

- GIVEN a transaction posted with posting date 2026-10-02
- WHEN the balance sheet as of 2026-09-30 is shown
- THEN that transaction's amounts are not in it

### Requirement: Every figure opens the lines behind it (REQ-RFS-003)

Each account figure on both statement pages and each trial balance line
SHALL link to a list of the posted ledger lines of that account within the
figure's date range, each of which opens its transaction.

#### Scenario: A bookkeeper checks the housing costs

- GIVEN the profit and loss page showing EUR 36,000 on 4100 Huisvesting for 2026-01-01 to 2026-09-30
- WHEN they click that figure
- THEN the ledger lines page lists the posted 4100 lines of that range adding up to EUR 36,000
- AND each line opens its general ledger transaction

### Requirement: Documents show the screen's figures (REQ-RFS-004)

The profit and loss and balance sheet document generators SHALL take their
figures from the same calculator as the pages.

#### Scenario: The PDF matches the page

- GIVEN the balance sheet page as of 2026-09-30
- WHEN the bookkeeper generates the balance sheet document for that date
- THEN every total in the document equals the page's
