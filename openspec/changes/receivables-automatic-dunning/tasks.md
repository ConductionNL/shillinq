# Tasks: receivables-automatic-dunning

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 11. -->

## 1. Selection

- [x] 1.1 Change stage selection in `tickInvoice()` to the lowest unsent reached stage with the threshold gap since the previous stage, and add a dry-run mode that writes nothing (REQ-RAD-002, REQ-RAD-008). Verify: PHPUnit for a first run on a 120-day invoice, a second stage too early and on time, and a dry run. (5 Oct 2026: `DunningRunService::nextStage()` picks the lowest-numbered stage not yet sent whose threshold is reached, and holds a later stage until its threshold gap has passed since the previous stage's `executedOn`; a FAILED run does not count as sent, so the stage is tried again. `tickInvoice(dryRun: true)` answers `{dryRun, invoiceId, ladderId, stageNr, channel, templateId}` and writes nothing. `stageForOverdueDays()` stays for display. Tests: `testALongOverdueInvoiceStartsAtTheFriendlyReminder`, `testTheSecondStageWaitsForTheGapSinceTheFirst`, `testAFailedStageIsTriedAgain`, `testADryRunWritesNothing`.)
- [x] 1.2 Resolve the base ladder from `dunningPolicyRef`, else the administration's default ladder, before the existing override resolution (REQ-RAD-004). Verify: PHPUnit for a customer ladder, the default, an override and no ladder. (5 Oct 2026: `tickInvoice()` takes an empty `baseLadderId` and resolves it with `baseLadderFor()`: the customer's `dunningPolicyRef` when it names a ladder, else the administration's active `DEFAULT` ladder; `resolveLadderForKlant()` then applies an active override; no ladder returns null (the job counts it, task 3.1). Test: `testTheLadderComesFromTheCustomerThenTheAdministration`.)
- [x] 1.3 Re-read the invoice state and pauses at send time and skip paid, written-off, disputed and paused invoices (REQ-RAD-005). Verify: PHPUnit where the invoice is paid between listing and sending. (5 Oct 2026: before sending, `settledSinceListed()` reads the ARInvoice again and skips `paid`, `written-off` and `disputed`; the pause check runs at the start of the tick and again in `executeStage()`. Test: `testAnInvoiceSettledSinceItWasListedIsNotChased`.)

## 2. Sending

- [ ] 2.1 Add `subject` and `body` per language to ladder stages with seeded default texts, and `DunningStageRenderer` filling the merge fields of `DunningTemplateRegistry` (REQ-RAD-003). Verify: `npm run check:registers`; PHPUnit rendering each seeded stage for a Dutch and an English customer.
- [ ] 2.2 Add `MailDunningChannelAdapter` (IMailer, invoice PDF attached, outcome from the mailer) and bind it at `Application.php:439` (REQ-RAD-003). Verify: PHPUnit with a mailer double for accepted, refused and no email address.
- [ ] 2.3 Make `executeStage()` render, dispatch through the adapter and record `DELIVERED`, `FAILED` or `MANUAL`, with the notification on `MANUAL` (REQ-RAD-003, REQ-RAD-006). Verify: PHPUnit per channel; a test that no non-mail channel ever records delivered.
- [ ] 2.4 Hold collection costs back for a consumer until 15 days after a delivered 14-day letter (REQ-RAD-007). Verify: PHPUnit for 10 and 16 days after the letter and for a business debtor.

## 3. Job

- [ ] 3.1 Add `lib/BackgroundJob/DunningTickJob.php` (TimedJob, daily, per-administration lock, `mark-overdue` first, pages of 100, run report), register it in `appinfo/info.xml`, and add the `dunning.enabled` setting (REQ-RAD-001). Verify: `npm run check:job-registration`, the registration contract test of `background-job-consolidation`, and PHPUnit for a failing invoice not stopping the run.

## 4. Pages

- [ ] 4.1 Add the Next run preview panel and the job report to `DunningRuns`, show subject and body in the `DunningLadders` stage editor, and show the preview when enabling dunning (REQ-RAD-008). Verify: `npm run check:manifest`; Playwright on the seeded ladder and override.

## 5. End to end and docs

- [ ] 5.1 Live check: enable dunning on a seeded administration, run the job with `occ background-job:execute`, and confirm one delivered stage-1 mail and one manual stage-4 run. Verify: the run ids in the PR body.
- [ ] 5.2 User guide page on automatic reminders, per-customer ladders and manual stages, and a release note stating dunning ships disabled. Verify: the page in `docs/` and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/receivables-automatic-dunning/tasks.md#task-N` on every new method, English source strings with Dutch translations for every stage text.
