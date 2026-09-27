# financial-dashboard-graphs Specification (delta)

## Purpose

Users put shillinq figures on dashboards they arrange themselves, and pivot
the ledger. From shillinq matrix row `rep-bi`.

## ADDED Requirements

### Requirement: Shillinq offers its figures as dashboard widgets (REQ-RCA-001)

Shillinq SHALL register Nextcloud dashboard widgets for revenue this month,
open receivables, cash position, result by month and top customers, each for
the user's active administration and with the same figures as the shillinq
dashboard. A widget without an active administration SHALL say so.

#### Scenario: A director builds their own dashboard

- GIVEN a director with launchpad installed
- WHEN they open launchpad's widget picker and add "Openstaande debiteuren" from shillinq
- THEN the widget shows the open and overdue receivables of their active administration

### Requirement: A user pivots posted ledger lines (REQ-RCA-002)

A pivot page SHALL sum the signed amounts of posted lines that count in
results over two axes chosen from account, account group, period, cost
centre, project and customer, for a date range, with totals, and SHALL
export what it shows to CSV and Excel.

#### Scenario: Revenue by quarter

- GIVEN a director on the financial pivot page
- WHEN they choose rows account group, columns quarter and the year 2026
- THEN the Omzet row shows EUR 60,000, EUR 58,000 and EUR 62,000 for Q1 to Q3 and a total of EUR 180,000

#### Scenario: A large pivot is capped visibly

- GIVEN more than 200 customers with revenue
- WHEN the director chooses rows customer
- THEN the page shows 200 rows and says the list was capped
