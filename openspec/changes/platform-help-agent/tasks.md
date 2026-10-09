# Tasks: platform-help-agent

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Hermiq contract

- [ ] 1.1 Read hermiq's `AgentTemplateService` import path and package format (development) and record whether another app can offer a package; choose the listener or the admin-notification route accordingly (REQ-PHA-002). Verify: file and line in the PR body.

## 2. Articles

- [ ] 2.1 Add the `HelpArticle` schema fragment (REQ-PHA-001). Verify: `npm run check:registers`.
- [ ] 2.2 Repair step seeding articles from `docs/` with page ids from manifest `documentationUrl`s and hash-based skipping (REQ-PHA-001). Verify: PHPUnit for new, changed and unchanged files.
- [ ] 2.3 Help articles index under Documentation (design D3). Verify: `npm run check:manifest`.

## 3. Agent

- [ ] 3.1 The `shillinq-helper` template file and its offer to hermiq (REQ-PHA-002, REQ-PHA-003). Verify: PHPUnit that the package carries no secret and restricts `views` to `HelpArticle`.
- [ ] 3.2 Live check on an instance with hermiq: instantiate the helper and ask the period close question (REQ-PHA-003). Verify: the answer and its link in the PR body.
- [ ] 3.3 Dutch and English strings for the template's description and the articles index. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
