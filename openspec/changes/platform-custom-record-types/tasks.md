# Tasks: platform-custom-record-types

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Backend

- [ ] 1.1 `CustomRecordTypeService` with `shippedSlugs`, `customTypes`, `shownTypes` (REQ-CRT-001). Verify: PHPUnit over the real register files.
- [ ] 1.2 `GET` and admin-only `PUT /api/custom-record-types`, refusing shipped or unknown slugs (REQ-CRT-001, REQ-CRT-003). Verify: PHPUnit with a non-admin caller; route-auth and semantic-auth gates.

## 2. Pages

- [ ] 2.1 The admin settings section with a switch per custom type (REQ-CRT-001). Verify: vitest.
- [ ] 2.2 `CustomRecordTypes`, `CustomRecords` and `CustomRecordDetail` with the menu entry and the empty state (REQ-CRT-002, REQ-CRT-003). Verify: `npm run check:manifest`; nav reachability.

## 3. End to end and strings

- [ ] 3.1 Playwright `tests/e2e/platform-custom-record-types.spec.ts`: define a schema, show it, add a record. Verify: passes locally.
- [ ] 3.2 Dutch and English strings. Verify: `npm run test:l10n`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
