# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 07 — mapping detail)

## ADDED Requirements

### Requirement: The system SHALL provide a Budget Mapping detail page with CRUD

The system SHALL render a Budget Mapping detail page using
`CnDetailPage` with fields GL Account (picker), BBV Programme (picker),
Allocation %, Effective From, Effective To, and Status, plus Save,
Delete, and Cancel actions and a `CnObjectSidebar` audit-trail tab.
Saves SHALL go through the object store's `saveObject`; deletes SHALL be
confirm-gated and use `deleteObject`.

#### Scenario: Admin creates a new mapping

- **GIVEN** the admin opens the detail page in create mode
- **WHEN** the admin selects GL Account 4100, Programme 1.1.1,
  Allocation 50%, and saves
- **THEN** the GL account name and programme name SHALL be shown beneath
  the pickers
- **AND** on a valid save the record SHALL be created and the user
  returned to the index
- **AND** on a validation failure an inline error SHALL be shown without
  dismissing the form.

### Requirement: The detail page SHALL provide pickers and live allocation feedback

The GL Account picker SHALL autocomplete from the Chart of Accounts and
the Programme picker SHALL autocomplete from `BBVProgramme` for the
current fiscal year. As the user edits the allocation, the page SHALL
recompute the per-account total and warn when it would exceed 100%,
without itself being the sole enforcement point.

#### Scenario: Live over-allocation feedback

- **GIVEN** GL 4100 already totals 90% across existing mappings
- **WHEN** the admin enters an allocation of 15% for GL 4100
- **THEN** the page SHALL warn that the total would exceed 100%
- **AND** Save SHALL be blocked until corrected
- **AND** the server SHALL also reject the write if attempted.
