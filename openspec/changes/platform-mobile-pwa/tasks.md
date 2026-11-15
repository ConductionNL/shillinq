# Tasks: platform-mobile-pwa

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. App shell

- [ ] 1.1 `public/mobile.webmanifest`, `public/mobileServiceWorker.js` and their registration on the mobile scope (REQ-PMP-001). Verify: Lighthouse installability check in the PR; vitest for the registration helper.

## 2. Screens

- [ ] 2.1 `MobileHome`, `MobileReceipt` and `MobileInvoice` pages (REQ-PMP-001, REQ-PMP-002, REQ-PMP-004). Verify: `npm run check:manifest`; vitest per screen.

## 3. Offline

- [ ] 3.1 `useOfflineQueue.js` with ordered sync and image-first receipts (REQ-PMP-003). Verify: vitest with a mocked IndexedDB and connectivity events.
- [ ] 3.2 `clientId` on `Receipt` and `ARInvoice` with the repeat-returns-existing rule (REQ-PMP-003). Verify: `npm run check:registers`; PHPUnit for a repeated `clientId`.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/platform-mobile-pwa.spec.ts` in a mobile viewport with the network switched off and on. Verify: passes locally.
- [ ] 4.2 Accessibility at phone width (touch targets, labels) (ADR-059, WCAG 2.2 AA). Verify: the axe gate on the three pages.
- [ ] 4.3 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
