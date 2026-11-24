# Tasks: ledger-retention-due-records

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Retention end date

- [x] 1.1 `RetentionDueService` in `lib/Service/` that resolves each retention-bound record's rule and reference date and returns the records past their end date, skipping legal holds (REQ-RET-015). Verify: PHPUnit red first for the three scenarios.

## 2. Page

- [x] 2.1 A "Due for destruction" page in `src/manifest.d/` as a card on Reporting & compliance, fed by a route on the service; point the BewaartermijnenDashboard count at records instead of rules (REQ-RET-015). Verify: `npm run check:manifest`, route-auth and IDOR gates.

## 3. Strings and live check

- [x] 3.1 English and Dutch strings. Verify: `npm run test:l10n`.
- [x] 3.2 Live: seed a 2018 transaction and see it on the list; set a legal hold and see it leave.
  - **Written, runs in nightly (24 Nov 2026):** `tests/e2e/ledger-retention-due-records.spec.ts` seeds a 2018 and a 2022 GLTransaction under a 7-year destroy rule, reads `GET /api/retention/due` and the Due for destruction page (2018 listed with end date 2025-03-15, 2022 not), then sets a `DocumentRetention` legal hold and sees the 2018 one leave (REQ-RET-015). Found while writing it: `RetentionDueService::BINDINGS` covers GLTransaction and APInvoice only, so the ARInvoice legal-hold scenario was `test.fixme`; fixed the same day: ARInvoice is bound on `invoiceDate` under 5.1.2 and the spec tests it. In the CI Playwright testDir, matching no testIgnore; not verified here, no live instance in the build sandbox.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
