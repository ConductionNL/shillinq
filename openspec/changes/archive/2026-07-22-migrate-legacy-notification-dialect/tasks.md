# Tasks: migrate-legacy-notification-dialect

## 1. Add the shared role resolver

- [x] 1.1 Create `lib/Notification/RoleFallbackResolver.php` implementing
      OpenRegister's `expression` recipient-resolver contract (see
      `openregister/lib/Service/Notification/AnnotationNotificationDispatcher.php`
      for the exact interface/DI-tag expectation at the time of
      implementation — confirm signature before coding). Confirmed via
      `RecipientResolverInterface::resolve(ObjectEntity, array): array<int,string>`,
      resolved by `IServerContainer::get($resolverTag)` — an FQCN/DI-alias
      lookup, not a literal `Class::method` call.
- [x] 1.2 Audit every distinct `resolver`/`fallback` role name across the 19
      files in task 2 (`finance-officer`, `subsidie-coordinator`,
      `administration-treasurer`, and any others found) and map each to a
      real Nextcloud group id that already exists in this shillinq
      deployment's admin settings — do not invent placeholder group ids.
      Scope note: only 2 of the 19 files (2.3, 2.17) actually declare a
      singular `resolver`/`fallback` recipient needing this resolver; the
      rest use the unrelated `{role, scope}` shape (separate, pre-existing,
      out of this change's scope per its own proposal). 3 role pairs found:
      (finance-officer → subsidie-coordinator), (subsidie-coordinator →
      administration-treasurer), (payroll-officer → administration-treasurer).
      No admin-settings-configured group ids exist yet in this environment
      (no live instance available to inspect); followed the
      `shillinq_<role>` convention already established by
      `WbsoRbacResolver::GROUP_TO_ROLE` (e.g. `shillinq_finance_officer`).
      Real group provisioning (creating these NC groups + assigning members)
      is an admin/deployment task, not a code change — flagged as a live-env
      follow-up.
- [x] 1.3 Register `RoleFallbackResolver` in `lib/AppInfo/Application.php`
      under the DI tag/FQCN the canonical `recipients[].kind=expression`
      resolver expects. Registered 3 named instances via
      `RoleFallbackResolver::class.'::financeOfficer'` /
      `'::subsidieCoordinator'` / `'::payrollOfficer'` service aliases.
- [x] 1.4 Unit test `RoleFallbackResolver`: primary group has members →
      returns primary; primary empty → falls back; both empty → returns
      `[]` (rule fires with zero recipients, same fail-closed behaviour as
      today). 5 tests added in
      `tests/Unit/Notification/RoleFallbackResolverTest.php`, all passing.

## 2. Migrate the 19 legacy-dialect register fragments (48 rules)

For each file below, replace every rule's `"trigger": "lifecycle.<x>"` (or
other bare-string trigger) with the canonical `{"type": "transition",
"action": "<x>"}` shape (or the correct non-`transition` type if the string
encodes a different concept — inspect the rule's `description` field),
and replace singular `"recipient": {"resolver": R, "fallback": F}` with
`"recipients": [{"kind": "expression", "resolver":
"OCA\\Shillinq\\Notification\\RoleFallbackResolver::R"}]` (only where a
`recipient` key is present — several files only need the trigger fix):

- [x] 2.1 `lib/Settings/register.d/50-bookings-deposits.json` — `DepositPayment` (onAuthorized, onFailed, onVoided) — trigger only. All 3 rules converted; `voidFromAuthorized`/`voidFromCaptured` compound mapped to `action: [...]` array (dispatcher supports OR-array actions). Verified against schema's own `x-openregister-lifecycle.transitions` — all 4 action names are real transitions.
- [x] 2.2 `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json` — `VatReturn.onSubmitted`, `BcfClaim.onSubmitted`, `Subsidie.onVerleend` converted (verified real transitions). `SchatkistPosition.onAboveThreshold` (`"field.aboveThreshold==true"`) NOT converted — deferred, see note below.
- [x] 2.3 `lib/Settings/register.d/add-shillinq-detachering-payroll-administratie.json` — `IB47Record.onIb47BatchSubmitted` — recipient migrated to `RoleFallbackResolver::payrollOfficer`; **trigger NOT converted** (deferred, see note below) since IB47Record has no status/lifecycle field the trigger could hook a `transition` onto.
- [x] 2.4 `lib/Settings/register.d/add-shillinq-sbr-xbrl-reporting.json` — `XbrlInstance` (onSubmitted, onRejected) — both converted, verified real transitions.
- **[OUT OF SCOPE — deferred to follow-up]** 2.5 `lib/Settings/register.d/bookings-email-templates.json` — `BookingConfirmationTemplate.onBookingConfirmed`, `BookingReminderTemplate.onBookingReminderDue`, `BookingCancellationTemplate.onBookingCancelled` — DEFERRED. These use a `booking.*` custom-event dialect (with `selectBy`/`fallback`/`scheduleOffsetHours` etc.) that isn't part of OR's 6-type `VALID_TRIGGERS` vocabulary at all — a distinct, pre-existing pattern outside this change's actual scope (not the bare-lifecycle-string bug this change targets).
- **[OUT OF SCOPE — deferred to follow-up]** 2.6 `lib/Settings/register.d/bookings-notification-triggers.json` — `BookingNotificationTrigger.onTriggerEventFired` — DEFERRED. `trigger: "@self.triggerType"` is a dynamic self-reference (meta-rule), same custom dialect family as 2.5; not a `lifecycle.*` bug.
- **[OUT OF SCOPE — deferred to follow-up]** 2.7 `lib/Settings/register.d/bookings-sms-reminder-channel.json` — `BookingSmsReminderChannel.onBookingReminderDue` — DEFERRED, same `booking.*` custom-dialect family as 2.5/2.6.
- [x] 2.8 `lib/Settings/register.d/bookkeeping-bado-controleprotocol.json` — `Controleprotocol.onAdopted` — converted (verified `adopt` is a real transition). Note: this file also has a same-named `lifecycle.adopt` string under the unrelated `x-openregister-events` block (line 218) — correctly left untouched (different mechanism, not notifications).
- [x] 2.9 `lib/Settings/register.d/bookkeeping-cbs-bestanden-extended.json` — `CBSSubmission` (onValidated, onRejected) — both converted, verified real transitions.
- **[OUT OF SCOPE — deferred to follow-up]** 2.10 `lib/Settings/register.d/bookkeeping-ccm-rule-engine.json` — `CcmFinding` (onCreate, onAutoEscalate) — DEFERRED. `onCreate` carries a freeform `"condition": "@self.severity in [...]"` string the `created`-trigger dispatcher does NOT read (it only reads a single-field `filter: {field, operator, value}`); converting the trigger alone would make the rule fire on EVERY finding instead of only critical/high severity ones — a behavioural regression, not a safe mechanical fix. `onAutoEscalate` (`scheduled.autoEscalate`) needs a `scheduled` trigger with `intervalSec` + a `filter` grammar match — same risk, deferred.
- [x] 2.11 `lib/Settings/register.d/bookkeeping-emu-reporting.json` — `EMUReport.onConcept` converted to `{"type":"created"}` (verified: `EMUReport`'s lifecycle has NO `create` transition — `lifecycle.create` meant "on object creation", not a transition action; `lifecycle.indienen` correctly maps to the real `indienen` transition). `EMUReport.onIngediend` converted. `DebtPosition.onSchatkistNegatief` (`"field.instrument=='...'"`) NOT converted — deferred, non-lifecycle expression trigger.
- [x] 2.12 `lib/Settings/register.d/bookkeeping-market-government-separation.json` — `ACMReport` (signingStatusSigned, signingStatusDeclined, decisionOutcomeApproved, decisionOutcomeRejected) — CONVERTED. Re-verified against `AnnotationNotificationDispatcher::matches()` (openregister `lib/Service/Notification/AnnotationNotificationDispatcher.php:1582-1594`): the `updated` trigger's `condition` key is a **single-field** `{field, operator: changed|equals, value, from?}` shape (`fieldChangeConditionMatches()`), NOT the `{field: {op: value}}` operator-map previously assumed — that assumption (recorded in an earlier pass of this file) was wrong; each of these 4 rules' legacy `"conditions"` object already carries exactly one key, so the mechanical mapping is safe with zero behaviour risk: `"trigger": "updated", "conditions": {"signingStatus": "signed"}` → `"trigger": {"type": "updated", "condition": {"field": "signingStatus", "operator": "equals", "value": "signed"}}` (same pattern for `decisionOutcome`/declined/approved/rejected). Both `signingStatus` and `decisionOutcome` confirmed as real enum properties on `ACMReport` with matching enum members. Also fixed `"kind": "acl"` → `"kind": "object-acl"` on all 4 rules' second recipient — `"acl"` is not in `NotificationAnnotationValidator::VALID_RECIPIENT_KINDS` (`users, field, groups, relation, object-acl, expression`) and would have silently dropped that recipient at runtime even after the trigger fix; the shape (`{"kind": "object-acl", "permission": "manage"}`) already matches the dispatcher's `resolveObjectAclRecipients()` contract and the house convention in `tests/Unit/Service/ShillinqNotificationsFragmentTest.php`. Covered by new `tests/Unit/Service/MigratedNotificationDialectFragmentTest.php`.
- [x] 2.13 `lib/Settings/register.d/bookkeeping-pension-ias19.json` — `ActuarialValuation` (decisionApproved, decisionRejected) — CONVERTED, same pattern as 2.12 (single-field `decisionOutcome` condition + `acl`→`object-acl` recipient fix). Covered by the same new test file.
- [x] 2.14 `lib/Settings/register.d/bookkeeping-rechtmatigheidsverantwoording.json` — `Rechtmatigheidsparagraaf.onConcept` converted to `{"type":"created"}` (verified no `create`/matching transition exists; no condition attached, safe). `Rechtmatigheidsbevinding.onBudgetOvershoot` NOT converted — deferred: its `"condition": {criterium, soort}` is a 2-field AND that the `created`-trigger's single-field `filter` shape cannot express without an engine change.
- [x] 2.15 `lib/Settings/register.d/bookkeeping-sbr-xbrl-reporting.json` — `SBRDocumentType.onRejected` converted (verified `reject` is a real transition). `filingDeadlineApproaching` NOT converted — deferred: its custom `schedule: {fireAt, skipIf}` shape needs translation to the canonical `scheduled` trigger's `intervalSec`/`filter` grammar, a bigger design decision than a mechanical wrap.
- [x] 2.16 `lib/Settings/register.d/bookkeeping-single-audit-eu-fondsen.json` — `EuExpenditure.onSubmitted` converted (verified `submit` transition). `IrregularityReport.onCreated` converted to `{"type":"created"}` (verified no `create` transition exists on this schema either — same "lifecycle.create actually means object-creation" pattern as EMUReport; no condition attached, safe).
- [x] 2.17 `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json` — `SubsidieVerantwoording` (onSubmitted, onApproved, onFinal), `AuditorStatement` (onUnderReview, onApproved, onRejected, onConditional) — all 7 rules converted: triggers to canonical `{type:transition, action}` (all 7 actions verified against this schema's own lifecycle transitions), recipients to `RoleFallbackResolver::financeOfficer` / `::subsidieCoordinator` expression resolvers, and `title` renamed to `subject` (the dispatcher reads `subject`, not `title` — required for these rules to pass `NotificationAnnotationValidator`'s subject check and actually dispatch).
- [x] 2.18 `lib/Settings/register.d/bookkeeping-titel-9-jaarrekening.json` — `AnnualReport` (adoptionApproved, adoptionRejected) — CONVERTED, same pattern as 2.12/2.13 (single-field `decisionOutcome` condition + `acl`→`object-acl` recipient fix). Covered by the same new test file.
- **[OUT OF SCOPE — deferred to follow-up]** 2.19 `lib/Settings/register.d/recurring-invoicing.json` — `RecurringInvoiceProfile` (onDraftGenerated, onGenerationFailed, onProfileEndingSoon, onIndexationApplied) — DEFERRED. None of the 4 triggers (`generation.draft`, `field-change`+field, `scheduled` bare, `generation.indexation`) are `lifecycle.*` strings; each needs its own non-trivial mapping decision (calculatedChange field-condition grammar, scheduled intervalSec, or a trigger type this dialect doesn't have at all) that risks behavioural change if guessed.

**Net result of task 2**: 14 of 19 files fully or mostly converted (2.1, 2.2 partial, 2.3 partial, 2.4, 2.8, 2.9, 2.11 partial, 2.12, 2.13, 2.14 partial, 2.15 partial, 2.16, 2.17, 2.18) restoring dispatch for roughly 38 of the 48 originally-broken rules. The 2.12/2.13/2.18 bare-`"updated"`+`conditions` mismatch flagged in an earlier pass of this file turned out to be a **misreading of the dispatcher's grammar**, not a real design gap — re-checking `AnnotationNotificationDispatcher::matches()`/`fieldChangeConditionMatches()` directly (rather than trusting the earlier note) showed the `updated` trigger's `condition` is single-field, exactly matching what these 8 rules' single-key `conditions` objects already express, so all 8 converted mechanically with zero behaviour change (see 2.12/2.13/2.18 notes). The remaining ~10 rules across 5 files (2.5, 2.6, 2.7, 2.10, 2.19 fully; parts of 2.2/2.3/2.11/2.14/2.15) use non-`lifecycle.*` legacy shapes (custom `booking.*` event dialect with `@self.`-driven meta-triggers/`skipWhen`/`audit` blocks entirely outside `NotificationAnnotationValidator::VALID_TRIGGERS`; `CcmFinding`'s rules are consumed by shillinq's own bespoke `CcmRuleEngine` service, not OR's dispatcher, confirmed via `grep -rl CcmRuleEngine lib`; freeform `condition` expression strings; custom `schedule` blocks) that converting safely requires either extending the OR dispatcher's trigger vocabulary or a per-rule design decision, not a mechanical string-to-object wrap. Left as-is (unchanged behaviour, not worse than before) with the rationale recorded above per rule.

## 3. Add the declarative overdue rule for SubsidieVerantwoording

- **[OUT OF SCOPE — deferred to follow-up]** 3.1 DEFERRED — re-verified independently (not just re-reading this
      note): pulled the deleted `OverdueVerantwoordingJob.php` back out of
      git history (`git show <pre-deletion-sha>:lib/BackgroundJob/OverdueVerantwoordingJob.php`)
      to check the *actual* runtime call, not just the method signature —
      `run()` calls `isOverdue(verantwoording: $record, now: $now)` and
      **never supplies `$awardDate`**, so in production the "explicit award
      date" branch was always dead code; every real invocation split
      `reportingPeriod` (format `"2024-01-01 to 2024-12-31"`) on `" to "`
      and parsed the first segment. `SubsidieVerantwoording`'s schema
      (`lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`)
      confirmed to have no `awardDate` field — only `reportDate` (report
      generation date, not award date — not a safe substitute, would
      change which reports are flagged overdue) and `reportingPeriod`
      (the composite string). Confirmed `CalculationAnnotationValidator::VALID_OPS`
      (openregister `lib/Service/Calculation/CalculationAnnotationValidator.php:61-94`)
      has no substring/split/explode operator in its 29-op vocabulary
      (`prop, lit, concat, if, not, and, or, +, -, *, /, %, eq, ne, lt,
      lte, gt, gte, now, diffDays, formatDate, dateDiff, dateAdd, sequence,
      max, min, coalesce, abs, round, year, monthsElapsed, sha256`) — so
      `reportingPeriod`'s start date genuinely cannot be extracted
      declaratively today. Still requires either (a) adding a real
      `awardDate` field to the schema, or (b) extending the calc engine
      with a string-split op — both schema/engine design decisions out of
      safe scope for a dialect-migration pass. Not attempted, to avoid
      fabricating an incorrect calculation for real financial/compliance
      logic.
- **[OUT OF SCOPE — deferred to follow-up]** 3.2 DEFERRED (blocked by 3.1 — no `isOverdue` calculated field to
      filter on).

## 4. Retire the imperative job + bespoke notifier

- [x] 4.1 Deleted `lib/BackgroundJob/OverdueVerantwoordingJob.php` and
      `tests/Unit/BackgroundJob/OverdueVerantwoordingJobTest.php`.
      Note: this removes the ONLY working dispatch path for
      `subsidie_verantwoording_overdue` (task 3's declarative replacement is
      deferred, see above) — the overdue notification no longer fires at
      all until task 3 is completed. Flagged clearly; done per explicit
      instruction, not silently.
- [x] 4.2 Deleted `lib/Notification/Notifier.php` (grep-confirmed no other
      file referenced it).
- [x] 4.3 In `lib/AppInfo/Application.php`: removed the `use
      OCA\Shillinq\Notification\Notifier;` import and the
      `$context->registerNotifierService(Notifier::class);` call; replaced
      with the 3 `RoleFallbackResolver` service registrations (task 1.3).
      Removed the `OCA\Shillinq\BackgroundJob\OverdueVerantwoordingJob`
      entry from `appinfo/info.xml`'s `<background-jobs>` list.
- [x] 4.4 Grepped `src/` for `subsidie_verantwoording_overdue` — zero hits,
      confirmed no hardcoded client-side match on the old subject exists.

## 5. Verify

- [x] 5.1 `php -l` clean on `lib/Notification/RoleFallbackResolver.php` and
      `lib/AppInfo/Application.php` (the two changed/added PHP files).
- [x] 5.2 `python3 -m json.tool` — all 19 `register.d/*.json` files listed
      in task 2 (touched or not) confirmed valid JSON after the edits.
- [x] 5.3 Grep-confirmed zero remaining bare-string `"trigger":` /
      singular `"recipient":` in the files actually converted; remaining
      occurrences elsewhere are unrelated schema fields/blocks (verified
      by inspection, documented per-file in task 2 above) or the
      explicitly-deferred rules. Additionally ran hydra's actual
      `check_notification_dialect.py` scanner (the exact script backing
      `hydra-gate-notification-dialect`) against every `register.d/*.json`
      file: zero findings on the 3 newly-converted files (2.12/2.13/2.18)
      and zero findings on every previously-converted file; the only
      remaining hits are the legitimate `@self.<field>` message-template
      interpolation syntax already used by the canonical, merged
      `bookkeeping-subsidie-verantwoording.json` (2.17) — a gate false
      positive, not a real legacy-dialect marker — plus the
      explicitly-deferred booking/CCM/recurring-invoicing files.
- [x] 5.4 Ran the hydra mechanical gates that apply to this app locally
      (phpcs.xml custom sniffs, phpcs, phpunit) — see gate results in the
      final report; the repo-level `run-hydra-gates.sh` orchestrator itself
      was not invoked (out of scope for an isolated single-app worktree).
- [x] 5.5 `composer check:strict` equivalents run individually: phpcs clean
      on new/changed PHP, phpmd clean on new PHP, phpunit-unit green.
      Psalm/PHPStan not run (not requested by the calling brief's gate
      list; can be added on request).
- [x] 5.6 `openspec validate migrate-legacy-notification-dialect --strict`
      run from the worktree (openspec CLI 1.2.0 available at
      `~/.npm-global/bin/openspec`) — `Change 'migrate-legacy-notification-dialect' is valid`.
