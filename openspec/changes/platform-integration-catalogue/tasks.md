# Tasks: platform-integration-catalogue

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 5. -->

## 1. Page

- [ ] 1.1 `Integrations` card page over integriq `catalog_item` with the category filter and the integriq requirement, and its menu entry (REQ-PIC-001, REQ-PIC-003). Verify: `npm run check:manifest`, nav reachability, the effective-manifest cross-reference gate.
- [ ] 1.2 `catalogItem` slugs in `connections.json` and the not-available-yet section (REQ-PIC-002). Verify: the connections declaration gate; vitest for the section.

## 2. Hand-off, end to end and strings

- [ ] 2.1 List the bookkeeping connectors that need an integriq catalogue item in an integriq issue, linked from the PR. Verify: issue link in the PR body.
- [ ] 2.2 Playwright `tests/e2e/platform-integration-catalogue.spec.ts` with and without integriq. Verify: passes locally.
- [ ] 2.3 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
