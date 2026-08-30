# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 10 — i18n)

## ADDED Requirements

### Requirement: All BBV user-facing strings SHALL be translated in English and Dutch

The system SHALL render every BBV user-facing string through a
translation key, with English as the source catalogue (`l10n/en.json`)
and a complete Dutch catalogue (`l10n/nl.json`). Vue components SHALL
use `t('shillinq', 'key')` and PHP responses SHALL use
`$this->l10n->t('key')`. No hardcoded user-facing string SHALL remain in
the BBV components.

#### Scenario: BBV UI renders in Dutch

- **GIVEN** a user with the Nextcloud locale set to Dutch
- **WHEN** the user opens the BBV Compliance Dashboard
- **THEN** "BBV Compliance Dashboard" SHALL render as
  "BBV-conformiteitsoverzicht"
- **AND** status labels SHALL render as "Op schema", "Risico",
  "Niet-conform", and "Niet geconfigureerd".

#### Scenario: Translation keys are consistent

- **GIVEN** the BBV components and the `en.json` / `nl.json` catalogues
- **WHEN** the translation keys are compared
- **THEN** every key used in a component SHALL exist in both catalogues
- **AND** no hardcoded user-facing string SHALL remain.
