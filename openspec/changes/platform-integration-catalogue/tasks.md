# Tasks: platform-integration-catalogue

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 5. -->

## 1. Page

- [x] 1.1 `Integrations` card page over integriq `catalog_item` with the category filter and the integriq requirement, and its menu entry (REQ-PIC-001, REQ-PIC-003). Verify: `npm run check:manifest`, nav reachability, the effective-manifest cross-reference gate.
  - Built: `src/manifest.d/platform-integration-catalogue.json`, an index page in cards over integriq `catalog_item` (the schema integriq's own Store page reads), opening on the six bookkeeping categories as removable default filters, `requiresApp` Integriq, the menu entry `Integrations` in the settings foldout beside External Connections, and the row action Open in Integriq (`openIntegriqStore`, integriq's `/store` searched on the item's name; integriq's `/catalog` now redirects there). Pinned by `tests/vitest/integrationCataloguePage.spec.js`.
- [x] 1.2 `catalogItem` slugs in `connections.json` and the not-available-yet section (REQ-PIC-002). Verify: the connections declaration gate; vitest for the section.
  - Built: the section `Not available yet` under the cards (`afterList` on the same index page), an object table over integriq's `app_connection` rows for `app=shillinq` with each family's title, description and the reason integriq reports, so no second page and no shillinq code. Pinned by `tests/vitest/integrationCataloguePage.spec.js`.
  - Slugs: none written, because integriq has no bookkeeping catalogue item yet, so every family is listed, which is what design D2 expects today. A slug also needs integriq's declaration schema to accept the field first (`connections.schema.json` refuses unknown fields), so the connections declaration gate stays green; that is integriq's part of the hand-off.
  - Also fixed: the `openIntegriqStore` row handler that 1.1 registered was missing from `src/utils/integriqConnections.js`; it now opens integriq's `/store` searched on the item's name, from the row dispatch's `{ item }` payload.

## 2. Hand-off, end to end and strings

- [ ] 2.1 List the bookkeeping connectors that need an integriq catalogue item in an integriq issue, linked from the PR. Verify: issue link in the PR body.
- [ ] 2.2 Playwright `tests/e2e/platform-integration-catalogue.spec.ts` with and without integriq. Verify: passes locally.
- [x] 2.3 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.
  - The page's English and Dutch strings; `test:l10n` and `test:l10n-parity` pass.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
