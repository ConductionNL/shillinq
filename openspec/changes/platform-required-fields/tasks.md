# Tasks: platform-required-fields

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Data and check

- [ ] 1.1 Add `FieldRequirement` in a `register.d` fragment, refusing unknown fields (REQ-PRF-001). Verify: `npm run check:registers`, `npm run check:seeds`.
- [ ] 1.2 `FieldRequirementListener` on both pre-save events with the system-write skip, registered in `Application.php` (REQ-PRF-002). Verify: PHPUnit for missing, present, other administration and system write.

## 2. Page and form

- [ ] 2.1 `RequiredFields` settings page with the form-field list per schema (REQ-PRF-001). Verify: `npm run check:manifest`, nav reachability.
- [ ] 2.2 Raise the runtime required-fields input on nextcloud-vue and show the server's field errors in forms meanwhile (REQ-PRF-002). Verify: issue link in the PR; vitest for the error display.

## 3. End to end and strings

- [ ] 3.1 Playwright `tests/e2e/platform-required-fields.spec.ts`. Verify: passes locally.
- [ ] 3.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
