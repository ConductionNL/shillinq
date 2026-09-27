# Tasks: ledger-open-item-clearing

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Schema

- [ ] 1.1 Add `Account.openItemManaged`, `GLLine.clearingGroupId` and the `ClearingGroup` schema with its calculation and lifecycle in a `register.d` fragment (REQ-LOIC-001, REQ-LOIC-003). Verify: `npm run check:registers`, `npm run check:seeds`.

## 2. Service

- [ ] 2.1 `OpenItemClearingService` clear and undo behind two routes, refusing unposted lines, mixed accounts and lines already grouped (REQ-LOIC-002). Verify: PHPUnit per refusal; route auth and IDOR gates.
- [ ] 2.2 Suggestions (REQ-LOIC-004). Verify: PHPUnit for exact pairs and non-pairs.
- [ ] 2.3 Reopen on reversal in the reversal listener (design D4). Verify: PHPUnit.

## 3. Page

- [ ] 3.1 Open items page in `src/manifest.d/` under the ledger menu with uncleared, groups not netting to zero, and suggestions (REQ-LOIC-001, REQ-LOIC-003, REQ-LOIC-004). Verify: `npm run check:manifest`, nav reachability.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/ledger-open-item-clearing.spec.ts`: clear a pair, see a short group. Verify: passes locally.
- [ ] 4.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
