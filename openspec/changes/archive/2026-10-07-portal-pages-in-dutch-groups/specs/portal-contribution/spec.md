## ADDED Requirements

### Requirement: Every portal page names its menu group in Dutch
The contribution MUST declare one page per listable collection for every audience, each with a `group`:
"Bestellingen en facturen" (customer), "Schoolbijdragen" (parent), "Opdrachten en facturen" (supplier),
"Administratie" (accountant). The contribution label MUST be that group, not the app name. Every collection,
column and action label MUST be Dutch, and no two pages of one audience MAY share a name.

#### Scenario: A supplier's menu
- **GIVEN** a supplier signed in on the site
- **WHEN** the site builds the menu
- **THEN** "Inkooporders" and "Mijn facturen" MUST sit under "Opdrachten en facturen"

#### Scenario: A parent declines a voluntary contribution
- **GIVEN** a parent with a voluntary contribution invoice
- **WHEN** they open "Mijn bijdragen"
- **THEN** the action MUST read "Ik betaal niet", the name the reminder gives
