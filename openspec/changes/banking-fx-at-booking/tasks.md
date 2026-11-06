# Tasks: banking-fx-at-booking

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Resolver

- [ ] 1.1 Add `lib/Service/Treasury/FxAtBookingResolver.php` with the precedence and age rule of design.md D1 and an `fx_max_rate_age_days` app config (REQ-BFX-001, REQ-BFX-002). Verify: PHPUnit for same day, weekend, scoped over shared, stale and missing.

## 2. Posting

- [ ] 2.1 Call the resolver from `MaterialiseGlTransactionAction` for each foreign-currency line, fill the snapshot fields, and refuse when rounding unbalances the entry (REQ-BFX-001, REQ-BFX-002). Verify: PHPUnit on the handler with a USD entry and a refused GBP entry.
- [ ] 2.2 Add optional `fxRate` and `fxRateReason` to `JournalEntry.lines[]` and honour them in the handler (REQ-BFX-003). Verify: `npm run check:registers`; PHPUnit for a typed rate with and without a reason.

## 3. Pages

- [ ] 3.1 Add the newest-rate aggregation on `FxRate` and the feed status line on `FXRates` (REQ-BFX-004). Verify: Playwright reads the status and the newest date on seed data.
- [ ] 3.2 Show rate, source and rate date on the line table of `GeneralLedgerDetail` and `JournalDetail` (REQ-BFX-001). Verify: Playwright on a posted USD entry.

## 4. End to end

- [ ] 4.1 Playwright `tests/e2e/banking-fx-at-booking.spec.ts`: post a USD entry on a Sunday, see the GBP refusal, post with a typed rate (REQ-BFX-001, REQ-BFX-002, REQ-BFX-003). Verify: the spec passes against a local instance with `ledger-posting-path` merged.

## 5. Docs

- [ ] 5.1 Release note: foreign-currency posts now need a rate, the default age, and the integriq dependency for a feed. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/banking-fx-at-booking/tasks.md#task-N` on every new method, Dutch and English strings for the refusal and the status line.
