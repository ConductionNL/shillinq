# bookkeeping-period-close Specification (delta)

## Purpose

A period that starts closing carries the administration's checklist, and a
person works it down. From shillinq matrix row `led-close-checklist`.

## ADDED Requirements

### Requirement: A period that starts closing copies the checklist template (REQ-LPE-001)

When `FiscalPeriod.startClose` runs and `taskChecklistItems` is empty,
shillinq SHALL copy the tasks of the administration's active
`CloseChecklistTemplate` into it, each unresolved. Later template edits
SHALL NOT change a period's items.

#### Scenario: A controller starts closing September

- GIVEN the template Maandafsluiting with five tasks
- WHEN the controller presses Start close on the period close page for September 2026
- THEN the close checklist on that page lists the five tasks as open

### Requirement: A person resolves, reopens and adds checklist items (REQ-LPE-002)

The period close detail page SHALL offer Resolve and Reopen on each item and
Add item on the list. Resolving SHALL record the user and the time. Items
MUST NOT change once the period is closed or locked for audit.

#### Scenario: A bookkeeper resolves the bank item

- GIVEN an open item "bankmutaties volledig verwerkt"
- WHEN the bookkeeper presses Resolve on it
- THEN the row shows resolved with their name and the time

#### Scenario: A closed period's items are read-only

- GIVEN a closed period
- WHEN a bookkeeper opens its close page
- THEN no Resolve, Reopen or Add item action is offered

### Requirement: Close checklist templates have a page (REQ-LPE-003)

Shillinq SHALL provide a settings page to list, create and edit
`CloseChecklistTemplate` records and their tasks.

#### Scenario: An administrator adds a task to the template

- GIVEN an administrator on the close checklist templates page
- WHEN they add the task "loonjournaal geboekt" in category other to Maandafsluiting and save
- THEN the template lists six tasks
