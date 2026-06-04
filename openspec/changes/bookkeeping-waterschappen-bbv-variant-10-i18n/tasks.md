# Tasks — Member 10: i18n

Sourced from the giant's Phase 5 (Internationalization) and
REQ-BBVW-009.

## English catalogue

- [ ] Create `l10n/en.json`
- [ ] Add all UI strings as English source keys ("BBV Compliance Dashboard", "Budget Mapping", "Programme Code", "Allocation Percentage", etc.)

## Dutch catalogue

- [ ] Create `l10n/nl.json`
- [ ] Add Dutch translations for all keys (sentence case, not title case)

## String replacement

- [ ] Replace hardcoded strings in Vue components with `this.t('shillinq', 'key')`
- [ ] Replace hardcoded strings in PHP responses with `$this->l10n->t('key')`
- [ ] Verify translation keys are consistent across components
