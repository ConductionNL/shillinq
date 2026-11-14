# Tasks: receivables-payment-plans

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Schemas

- [x] 1.1 Add `PaymentPlan` (lifecycle, broken notification, the guard `PaymentPlanGuard::requireBalancedSchedule`), `PaymentPlanInstalment` and `ARInvoice.paymentPlanId` in `lib/Settings/register.d/receivables-payment-plans.json` (REQ-RPPL-001). Verify: `npm run check:registers`; PHPUnit for the guard with a balanced and an unbalanced schedule.

## 2. Service

- [x] 2.1 Add `PaymentPlanService::draft()` and `activate()`: schedule generation to the cent, pauses with reason `PAYMENT_PLAN` and a deadline at the last due date plus grace, `paymentPlanId` on invoices, the confirmation mail (REQ-RPPL-001, REQ-RPPL-002). Verify: PHPUnit for count-based and amount-based schedules, the pauses written, and the mail content with a mailer double.
- [x] 2.2 Add `PaymentPlanAllocator` (oldest invoice first, overpayment to next instalments, invoice to paid at zero due) and the settle action (REQ-RPPL-003). Verify: PHPUnit for an exact, a partial and an overpaying instalment.
- [x] 2.3 Add the plan candidate to bank matching: reference match high confidence, amount-and-IBAN match medium (REQ-RPPL-003). Verify: PHPUnit on both matches and a non-matching line.
- [ ] 2.4 (Deferred to receivables-payment-links: at HEAD no code creates a payment link, the payment port is a log-only adapter, so a PaymentRequest per instalment could not be paid. A capture hook for a request nobody creates would be a guard without a caller. Build this with that change.) When `receivables-payment-links` is present, create a `PaymentRequest` per instalment on activation and let its capture pay the instalment (REQ-RPPL-003). Verify: PHPUnit with a capture event for an instalment request.

## 3. Monitor

- [x] 3.1 Add `lib/BackgroundJob/PaymentPlanMonitorJob.php` (TimedJob, daily) registered in `appinfo/info.xml`: due, missed, break with pauses resumed and mails, complete (REQ-RPPL-004, REQ-RPPL-005). Verify: `npm run check:job-registration`; PHPUnit for a missed instalment within and after grace, and a completed plan.

## 4. Pages

- [x] 4.1 Add `PaymentPlans` index and detail with the due-this-month filter, and the Agree a payment plan action on `CustomerDetail` and on `ARInvoiceDetail` of an overdue invoice (REQ-RPPL-001, REQ-RPPL-006). Verify: `npm run check:manifest`, `npm run check:nav-reachability`; Playwright for the Café De Zwaan example.

## 5. Docs

- [x] 5.1 User guide page on payment plans and a release note stating that pauses of active plans need resuming by hand on rollback. Verify: the page in `docs/` and the note in the PR body.
- [x] 5.2 Record in the PR body that `hardDeadlineEindigt` of dunning pauses is written but not enforced, for the owner of `bookkeeping-credit-control-dunning`. Verify: the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/receivables-payment-plans/tasks.md#task-N` on every new method, English source strings with Dutch translations.

## Evidence (2026-09-29)

- 1.1 `lib/Settings/register.d/receivables-payment-plans.json`; `tests/Unit/PaymentPlan/PaymentPlanGuardTest.php` (balanced, a cent short, no id, the schema names the guard).
- 2.1 `PaymentPlanServiceTest::testACafeAgreesSixMonthlyInstalments`, `testActivationPausesDunningAndMailsTheSchedule` (pauses validated against the register, deadline 2027-04-15), `PaymentPlanScheduleTest` (count, amount, month ends).
- 2.2 `PaymentPlanServiceTest::testTheFirstInstalmentPaysTheOldestInvoice`, `testAPartialPaymentLeavesTheInstalmentOpen`, `testAnOverpaymentPaysTheNextInstalments`, `testTheLastInstalmentCompletesThePlan`; the settle action in `PaymentPlanControllerTest::testSettleByHandAndRefusals`.
- 2.3 `PaymentPlanBankMatcherTest` (reference, amount and IBAN, no fit, the feed booker), `PaymentPlanControllerTest::testABankLineIsOfferedAndConfirmed`.
- 3.1 `PaymentPlanServiceTest::testAMissedInstalmentBreaksThePlan` (due on day 14, broken on day 15 after grace), `PaymentPlanMonitorJobTest`; `npm run check:job-registration` 0.
- 4.1 `src/manifest.d/receivables-payment-plans.json`, `src/manifest.json` (CustomerDetail, ARInvoiceDetail), `tests/vitest/receivablesPaymentPlans.spec.js`; `check:manifest` and `check:nav-reachability` 0. Playwright for Café De Zwaan not written: the e2e suite runs nightly on development (CLAUDE.md verification order).
- 5.1 `docs/user-guide/bookkeeping/payment-plans.md`; the rollback note is in the PR body.
- 5.2 In the PR body.
