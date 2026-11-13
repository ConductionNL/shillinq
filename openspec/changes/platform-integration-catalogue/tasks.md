# Tasks: platform-integration-catalogue

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 5. -->

## 1. Page

- [x] 1.1 `Integrations` card page over integriq `catalog_item` with the category filter and the integriq requirement, and its menu entry (REQ-PIC-001, REQ-PIC-003). Verify: `npm run check:manifest`, nav reachability, the effective-manifest cross-reference gate.
  - Built: `src/manifest.d/platform-integration-catalogue.json`, an index page in cards over integriq `catalog_item` (the schema integriq's own Store page reads), opening on the six bookkeeping categories as removable default filters, `requiresApp` Integriq, the menu entry `Integrations` in the settings foldout beside External Connections, and the row action Open in Integriq (`openIntegriqStore`, integriq's `/store` searched on the item's name; integriq's `/catalog` now redirects there). Pinned by `tests/vitest/integrationCataloguePage.spec.js`.
- [ ] 1.2 `catalogItem` slugs in `connections.json` and the not-available-yet section (REQ-PIC-002). Verify: the connections declaration gate; vitest for the section.
  - Not built: integriq has no bookkeeping catalogue item yet, so there is no slug to write, and an index page draws one list; the section needs a second list over the families (integriq's `app_connection` rows carry the declaration) on the same page.

## 2. Hand-off, end to end and strings

- [ ] 2.1 List the bookkeeping connectors that need an integriq catalogue item in an integriq issue, linked from the PR. Verify: issue link in the PR body.
- [ ] 2.2 Playwright `tests/e2e/platform-integration-catalogue.spec.ts` with and without integriq. Verify: passes locally.
- [x] 2.3 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.
  - The page's English and Dutch strings; `test:l10n` and `test:l10n-parity` pass.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
