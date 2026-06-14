---
kind: code
depends_on: [bookkeeping-waterschappen-bbv-variant-09-fiscal-audit]
chain:
  - bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed
  - bookkeeping-waterschappen-bbv-variant-02-aggregation-compliance
  - bookkeeping-waterschappen-bbv-variant-03-validation-rules
  - bookkeeping-waterschappen-bbv-variant-04-manifest-routes
  - bookkeeping-waterschappen-bbv-variant-05-dashboard-widgets
  - bookkeeping-waterschappen-bbv-variant-06-mapping-index
  - bookkeeping-waterschappen-bbv-variant-07-mapping-detail
  - bookkeeping-waterschappen-bbv-variant-08-compliance-service
  - bookkeeping-waterschappen-bbv-variant-09-fiscal-audit
  - bookkeeping-waterschappen-bbv-variant-10-i18n
  - bookkeeping-waterschappen-bbv-variant-11-testing
  - bookkeeping-waterschappen-bbv-variant-12-docs-quality
---

# Proposal: bookkeeping-waterschappen-bbv-variant-10-i18n

Member 10 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-09-fiscal-audit`. Successor:
`bookkeeping-waterschappen-bbv-variant-11-testing`.

This `kind: code` member extracts every BBV UI string into translation
keys and provides **English + Dutch** translations (giant REQ-BBVW-009,
ADR-007/ADR-025).

## Why

All Conduction apps require Dutch + English as a minimum. With the
dashboard (05), mapping UI (06/07), and service responses (08) now
built, this member replaces their hardcoded strings with `t()` /
`l10n->t()` keys and supplies the `en.json` + `nl.json` catalogues. Done
as a dedicated member, it keeps the translation pass coherent across all
components at once.

## What Changes

- Create `l10n/en.json` with all BBV UI strings as source keys.
- Create `l10n/nl.json` with Dutch translations (sentence case).
- Replace hardcoded strings in the BBV Vue components with
  `this.t('shillinq', 'key')`.
- Replace hardcoded strings in PHP responses with `$this->l10n->t('key')`.
- Verify translation-key consistency across all components.

## Out of Scope (this member)

Tests (11), docs (12).
