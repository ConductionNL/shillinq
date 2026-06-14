# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 06 — mapping index)

## ADDED Requirements

### Requirement: The system SHALL provide a Budget Mapping index page

The system SHALL render a Budget Mapping index using `CnIndexPage`,
listing `BudgetBBVMapping` records with columns GL Account, Programme,
Allocation %, Effective From, Effective To, and Status. The page SHALL
support search by GL account number or programme code and filters by
fiscal year, allocation range, and effective-date range. The list data
SHALL be served by an object store created with `createObjectStore`.

#### Scenario: Admin views current-year mappings

- **GIVEN** a logged-in admin opening Budget Mapping for fiscal 2026
- **WHEN** the page loads
- **THEN** the index SHALL list the fiscal-2026 `BudgetBBVMapping`
  records
- **AND** searching "4100" SHALL show only the mappings for GL 4100.

### Requirement: The index SHALL navigate to create and detail flows

The index SHALL provide an Add action that opens the detail page in
create mode (`id=new`) and SHALL navigate to the detail page
(`id=<uuid>`) on row click.

#### Scenario: Admin opens a mapping for editing

- **GIVEN** the Budget Mapping index with seeded rows
- **WHEN** the admin clicks a row
- **THEN** the detail page SHALL open with that mapping's data
- **WHEN** the admin clicks Add
- **THEN** the detail page SHALL open in create mode.
