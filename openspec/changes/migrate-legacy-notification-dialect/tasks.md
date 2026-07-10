# Tasks: migrate-legacy-notification-dialect

## 1. Add the shared role resolver

- [ ] 1.1 Create `lib/Notification/RoleFallbackResolver.php` implementing
      OpenRegister's `expression` recipient-resolver contract (see
      `openregister/lib/Service/Notification/AnnotationNotificationDispatcher.php`
      for the exact interface/DI-tag expectation at the time of
      implementation — confirm signature before coding).
- [ ] 1.2 Audit every distinct `resolver`/`fallback` role name across the 19
      files in task 2 (`finance-officer`, `subsidie-coordinator`,
      `administration-treasurer`, and any others found) and map each to a
      real Nextcloud group id that already exists in this shillinq
      deployment's admin settings — do not invent placeholder group ids.
- [ ] 1.3 Register `RoleFallbackResolver` in `lib/AppInfo/Application.php`
      under the DI tag/FQCN the canonical `recipients[].kind=expression`
      resolver expects.
- [ ] 1.4 Unit test `RoleFallbackResolver`: primary group has members →
      returns primary; primary empty → falls back; both empty → returns
      `[]` (rule fires with zero recipients, same fail-closed behaviour as
      today).

## 2. Migrate the 19 legacy-dialect register fragments (48 rules)

For each file below, replace every rule's `"trigger": "lifecycle.<x>"` (or
other bare-string trigger) with the canonical `{"type": "transition",
"action": "<x>"}` shape (or the correct non-`transition` type if the string
encodes a different concept — inspect the rule's `description` field),
and replace singular `"recipient": {"resolver": R, "fallback": F}` with
`"recipients": [{"kind": "expression", "resolver":
"OCA\\Shillinq\\Notification\\RoleFallbackResolver::R"}]` (only where a
`recipient` key is present — several files only need the trigger fix):

- [ ] 2.1 `lib/Settings/register.d/50-bookings-deposits.json` — `DepositPayment` (onAuthorized, onFailed, onVoided) — trigger only.
- [ ] 2.2 `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json` — `VatReturn.onSubmitted`, `BcfClaim.onSubmitted`, `SchatkistPosition.onAboveThreshold`, `Subsidie.onVerleend` — trigger only.
- [ ] 2.3 `lib/Settings/register.d/add-shillinq-detachering-payroll-administratie.json` — `IB47Record.onIb47BatchSubmitted` — trigger + recipient.
- [ ] 2.4 `lib/Settings/register.d/add-shillinq-sbr-xbrl-reporting.json` — `XbrlInstance` (onSubmitted, onRejected) — trigger only.
- [ ] 2.5 `lib/Settings/register.d/bookings-email-templates.json` — `BookingConfirmationTemplate.onBookingConfirmed`, `BookingReminderTemplate.onBookingReminderDue`, `BookingCancellationTemplate.onBookingCancelled` — trigger only.
- [ ] 2.6 `lib/Settings/register.d/bookings-notification-triggers.json` — `BookingNotificationTrigger.onTriggerEventFired` — trigger only.
- [ ] 2.7 `lib/Settings/register.d/bookings-sms-reminder-channel.json` — `BookingSmsReminderChannel.onBookingReminderDue` — trigger only.
- [ ] 2.8 `lib/Settings/register.d/bookkeeping-bado-controleprotocol.json` — `Controleprotocol.onAdopted` — trigger only.
- [ ] 2.9 `lib/Settings/register.d/bookkeeping-cbs-bestanden-extended.json` — `CBSSubmission` (onValidated, onRejected) — trigger only.
- [ ] 2.10 `lib/Settings/register.d/bookkeeping-ccm-rule-engine.json` — `CcmFinding` (onCreate, onAutoEscalate) — trigger only.
- [ ] 2.11 `lib/Settings/register.d/bookkeeping-emu-reporting.json` — `EMUReport` (onConcept, onIngediend), `DebtPosition.onSchatkistNegatief` — trigger only.
- [ ] 2.12 `lib/Settings/register.d/bookkeeping-market-government-separation.json` — `ACMReport` (signingStatusSigned, signingStatusDeclined, decisionOutcomeApproved, decisionOutcomeRejected) — trigger only.
- [ ] 2.13 `lib/Settings/register.d/bookkeeping-pension-ias19.json` — `ActuarialValuation` (decisionApproved, decisionRejected) — trigger only.
- [ ] 2.14 `lib/Settings/register.d/bookkeeping-rechtmatigheidsverantwoording.json` — `Rechtmatigheidsbevinding.onBudgetOvershoot`, `Rechtmatigheidsparagraaf.onConcept` — trigger only.
- [ ] 2.15 `lib/Settings/register.d/bookkeeping-sbr-xbrl-reporting.json` — `SBRDocumentType` (filingDeadlineApproaching, onRejected) — trigger only.
- [ ] 2.16 `lib/Settings/register.d/bookkeeping-single-audit-eu-fondsen.json` — `EuExpenditure.onSubmitted`, `IrregularityReport.onCreated` — trigger only.
- [ ] 2.17 `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json` — `SubsidieVerantwoording` (onSubmitted, onApproved, onFinal), `AuditorStatement` (onUnderReview, onApproved, onRejected, onConditional) — trigger + recipient (all 7 rules).
- [ ] 2.18 `lib/Settings/register.d/bookkeeping-titel-9-jaarrekening.json` — `AnnualReport` (adoptionApproved, adoptionRejected) — trigger only.
- [ ] 2.19 `lib/Settings/register.d/recurring-invoicing.json` — `RecurringInvoiceProfile` (onDraftGenerated, onGenerationFailed, onProfileEndingSoon, onIndexationApplied) — trigger only.

## 3. Add the declarative overdue rule for SubsidieVerantwoording

- [ ] 3.1 In `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`,
      add `x-openregister-calculations.isOverdue` to the
      `SubsidieVerantwoording` schema, porting the exact rule from
      `OverdueVerantwoordingJob::isOverdue()`: non-final status
      (`draft`/`submitted`/`approved`) AND `now - referenceDate > 90 days`,
      where `referenceDate` is the explicit award date or the
      `reportingPeriod` start.
- [ ] 3.2 Add a new `onOverdue` rule to the same schema's
      `x-openregister-notifications`: `trigger: {type: "scheduled",
      intervalSec: 86400, filter: {isOverdue: true}}`, `recipients:
      [{"kind": "field", "field": "approverUserId"}]`, `subject`/`message`
      per-locale (nl + en, ADR-007), `originApp: "shillinq"`.

## 4. Retire the imperative job + bespoke notifier

- [ ] 4.1 Delete `lib/BackgroundJob/OverdueVerantwoordingJob.php` and
      `tests/Unit/BackgroundJob/OverdueVerantwoordingJobTest.php`.
- [ ] 4.2 Delete `lib/Notification/Notifier.php` (grep-confirm no other
      test file references it before deleting).
- [ ] 4.3 In `lib/AppInfo/Application.php`, remove the `use
      OCA\Shillinq\Notification\Notifier;` import (line 39) and the
      `$context->registerNotifierService(Notifier::class);` call
      (line 497). Grep-confirm no cron/background-job registration for
      `OverdueVerantwoordingJob` remains (`appinfo/info.xml` background
      job list, if declared there).
- [ ] 4.4 Grep `src/` for `subsidie_verantwoording_overdue` — update or
      confirm no hardcoded client-side match on the old notification
      subject exists.

## 5. Verify

- [ ] 5.1 `php -l` every changed/added PHP file.
- [ ] 5.2 `python3 -m json.tool` every changed `register.d/*.json` file to
      confirm valid JSON after the edits.
- [ ] 5.3 Grep-confirm zero remaining bare-string `"trigger":` values and
      zero remaining singular `"recipient":` keys under
      `lib/Settings/register.d/`.
- [ ] 5.4 Run the 18 hydra mechanical gates (report); confirm
      `notification-dialect` still passes and the advisory warning count
      for `*NotificationService`/`INotifier` drops (Notifier.php removed).
- [ ] 5.5 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan).
- [ ] 5.6 `openspec validate migrate-legacy-notification-dialect --strict`
      passes.
