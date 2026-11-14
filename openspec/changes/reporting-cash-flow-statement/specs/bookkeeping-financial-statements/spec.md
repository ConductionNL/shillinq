# bookkeeping-financial-statements Specification (delta)

## Purpose

A cash flow statement by the indirect method, computed from posted lines
for any period and reconciled to the change in cash. From shillinq matrix
row `rep-cashflow-statement`.

## ADDED Requirements

### Requirement: Balance accounts carry a cash flow category (REQ-RCF-001)

Every balance sheet account SHALL be classifiable as cash, working capital,
fixed assets, financing or equity, and the app SHALL list the balance
accounts that have no category yet.

#### Scenario: A bookkeeper finds an unclassified account

- GIVEN account 1300 Debiteuren of type assets without a cash flow category
- WHEN the bookkeeper opens the cash flow account check
- THEN 1300 Debiteuren is listed with a link to set its category

### Requirement: The cash flow statement reconciles to the change in cash (REQ-RCF-002)

The cash flow statement for a period SHALL show the operating, investing
and financing cash flows by the indirect method, and their sum SHALL equal
the change in the balances of cash accounts over the period; any
difference SHALL be shown as a separate unclassified line naming its
accounts.

#### Scenario: A director reads September year to date

- GIVEN the seed administration with result EUR 48,000, depreciation EUR 6,000, debtors up EUR 10,000, creditors up EUR 4,000, investments EUR 12,000 and a loan repayment EUR 8,000
- WHEN the director opens the cash flow statement for 2026-01-01 to 2026-09-30
- THEN operating shows EUR 48,000, investing EUR -12,000 and financing EUR -8,000
- AND the change in cash shows EUR 28,000 with no unclassified line

#### Scenario: An unclassified account shows as a difference

- GIVEN a balance account with a movement of EUR 500 in the period and no cash flow category
- WHEN the statement is shown
- THEN a line "Niet ingedeeld" shows EUR 500 and names that account

### Requirement: Every cash flow figure opens its lines (REQ-RCF-003)

Each figure on the cash flow statement page SHALL link to the posted ledger
lines of its accounts within the period.

#### Scenario: A director checks the investments

- GIVEN the cash flow statement showing investing EUR -12,000
- WHEN the director clicks that figure
- THEN the ledger lines page lists the posted fixed asset lines of the period adding up to EUR 12,000

### Requirement: The annual accounts use the same cash flow (REQ-RCF-004)

The annual accounts document SHALL contain a kasstroomoverzicht section
taken from the same calculator as the page, and SHALL store it as a
`CashFlowStatement` record with method indirect.

#### Scenario: Generating the annual accounts

- GIVEN an administration with a closed fiscal year 2025
- WHEN the bookkeeper generates the annual accounts for 2025
- THEN the document contains a kasstroomoverzicht whose totals equal the cash flow page for 2025-01-01 to 2025-12-31
- AND a `CashFlowStatement` record with method indirect is stored for that report
