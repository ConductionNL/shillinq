# Tasks — Member 10: i18n

Sourced from the giant's Phase 5 (Internationalization) and
REQ-BBVW-009.

## English catalogue

- [x] Create `l10n/en.json`
- [x] Add all UI strings as English source keys ("BBV Compliance Dashboard", "Budget Mapping", "Programme Code", "Allocation Percentage", etc.)

## Dutch catalogue

- [x] Create `l10n/nl.json`
- [x] Add Dutch translations for all keys (sentence case, not title case)

## String replacement

- [x] Replace hardcoded strings in Vue components with `this.t('shillinq', 'key')`
- [x] Replace hardcoded strings in PHP responses with `$this->l10n->t('key')`
- [x] Verify translation keys are consistent across components
