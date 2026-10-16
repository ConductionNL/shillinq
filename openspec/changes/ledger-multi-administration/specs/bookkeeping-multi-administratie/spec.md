# bookkeeping-multi-administratie Specification (delta)

## Purpose

The chosen administration sticks and scopes the lists, an office starts
clients from its own template, and followers stay in step with it. From
shillinq matrix rows `led-multi-admin`, `led-master-data-sync` and
`plt-client-templates`.

## ADDED Requirements

### Requirement: The chosen administration sticks (REQ-LMA-001)

Switching administration SHALL store the choice per user, and the context
endpoint SHALL return it as `activeAdministrationId` for as long as the user
is a member of it. When the user is no longer a member, it SHALL return the
first membership.

#### Scenario: A bookkeeper switches and reloads

- GIVEN a bookkeeper who is a member of Bakkerij Jansen and Kapsalon Mooi
- WHEN they choose Kapsalon Mooi in the administration switcher and the page reloads
- THEN the switcher shows Kapsalon Mooi as active
- AND the next login still shows Kapsalon Mooi

### Requirement: Lists show the active administration only (REQ-LMA-002)

Index pages over schemas that carry `administrationId` SHALL show only the
records of the active administration.

#### Scenario: The general ledger list follows the switch

- GIVEN posted transactions in both administrations
- WHEN the bookkeeper opens the general ledger page with Kapsalon Mooi active
- THEN only Kapsalon Mooi's transactions are listed

### Requirement: An office saves an administration as a template (REQ-LMA-003)

The administration detail page SHALL offer Save as template, which writes an
`AdministrationTemplate` with the administration's accounts and analytical
dimensions and no postings, relations or balances.

#### Scenario: The office saves its bakery chart

- GIVEN an accountant on the administration detail page of Bakkerij Jansen
- WHEN they choose Save as template and name it Kantoor De Boer MKB
- THEN the template lists Bakkerij Jansen's accounts and cost centres at version 1

### Requirement: A new administration starts from an office template (REQ-LMA-004)

The setup wizard's template step SHALL list office templates beside the
shipped RGS templates and SHALL seed the new administration from the one
chosen.

#### Scenario: A new client starts from the office template

- GIVEN the template Kantoor De Boer MKB
- WHEN an accountant creates Lunchroom Het Plein and picks that template in the setup wizard
- THEN Lunchroom Het Plein has account 8050 Omzet catering and the cost centres Winkel and Bakkerij

### Requirement: Followers receive template changes (REQ-LMA-005)

An administration that follows a template SHALL receive every account and
dimension the template adds and every name, type or description it changes,
when the template's version rises. An account the follower changed itself
MUST NOT be overwritten, and the run SHALL record what it added, updated and
skipped.

#### Scenario: Version 2 adds an account to both clients

- GIVEN Bakkerij Jansen and Kapsalon Mooi follow Kantoor De Boer MKB and Kapsalon Mooi renamed 8050
- WHEN the office saves version 2 with 4160 Scholingskosten
- THEN both administrations have 4160
- AND Kapsalon Mooi keeps its own name for 8050 and the run lists it as skipped
