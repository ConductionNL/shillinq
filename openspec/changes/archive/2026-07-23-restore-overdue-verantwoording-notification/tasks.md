# Tasks: restore-overdue-verantwoording-notification

## 1. Schema: add the honest awardDate field

- [x] 1.1 Add `SubsidieVerantwoording.awardDate` (nullable `string`/`format:
      date`) to `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`,
      following the same "snapshot copied from the Subsidie grant... without
      a cross-schema join" pattern already used by `awardedAmount`. Not
      added to `required` — existing rows are not backfilled.
- [x] 1.2 Populate the schema's sample seed object's `awardDate` for
      realism (`2026-01-01`, matching its existing `reportingPeriod` start).

## 2. Declarative calc: port isOverdue exactly

- [x] 2.1 Verified `CalculationAnnotationValidator::VALID_OPS` (openregister
      `lib/Service/Calculation/CalculationAnnotationValidator.php:61-94`)
      contains every operator this change uses: `prop`, `lit`, `now`,
      `diffDays`, `gt`, `ne`, `and`. No string-split/substring op needed —
      `awardDate` (task 1) removes that requirement entirely.
- [x] 2.2 Added `x-openregister-calculations.daysSinceAward` (materialised,
      integer): `{"diffDays": [{"now": []}, {"prop": "awardDate"}]}`.
      Confirmed against `CalculationEvaluator::diffDays()` that it returns
      `null` (not an exception) when `awardDate` is null/unparseable.
- [x] 2.3 Added `x-openregister-calculations.isOverdue` (materialised,
      boolean): `{"and": [{"ne": [status, "final"]}, {"gt": [daysSinceAward,
      90]}]}` — the 1:1 port of the retired `OverdueVerantwoordingJob::isOverdue()`'s
      two conditions (non-final status; >90 elapsed days). Confirmed
      against `CalculationEvaluator::compare()` that `gt` is null-safe
      (`$a !== null && $b !== null && $a > $b`), so a null `daysSinceAward`
      resolves `isOverdue` to `false` — the documented null-awardDate
      fallback (issue #505 point 4), not a guessed reportingPeriod-split.
- [x] 2.4 Both calcs set `materialise: true` — required for (a) the
      `onOverdue` rule's `filter: {isOverdue: true}` to query stored data,
      and (b) `TemporalCalculationSweepService::hasTemporalCalculation()`
      to detect the `now` reference and keep the schema's non-final
      objects live via the hourly sweep.
- [x] 2.5 Added `x-openregister-lifecycle.final: ["final"]` so the sweep
      skips already-finalised records (`TemporalCalculationSweepService::lifecycleTerminals()`)
      instead of rewriting them every pass.

## 3. Declarative notification: restore the daily reminder

- [x] 3.1 Added `x-openregister-notifications.onOverdue`: `trigger:
      {type: scheduled, intervalSec: 86400, filter: {isOverdue: true}}` —
      matches the retired job's 24h re-check cadence exactly
      (`OverdueVerantwoordingJob::INTERVAL_SECONDS = 86400`).
- [x] 3.2 Recipient `{"kind": "field", "field": "approverUserId"}` — the
      declarative equivalent of the job's `record['approverUserId']`
      lookup, including the same fail-closed behaviour (empty field value
      → zero recipients, no notification for unassigned reports).
- [x] 3.3 Verified `intervalSec: 86400 >= 60` and `filter` is a plain
      object (`NotificationAnnotationValidator`'s scheduled-trigger
      validation requirements) and `"field"` is in `VALID_RECIPIENT_KINDS`.

## 4. Fix the pre-existing "in-app" channel bug (encountered in-file)

- [x] 4.1 Discovered while adding `onOverdue`: `NotificationAnnotationValidator::VALID_CHANNELS`
      and `AnnotationNotificationDispatcher` both only recognise the
      literal `"nc-notification"` — `"in-app"` is not a valid/recognised
      channel anywhere in OpenRegister. All 7 `channels: ["in-app", ...]`
      occurrences in this file (`SubsidieVerantwoording`'s onSubmitted/
      onApproved/onFinal + `AuditorStatement`'s onUnderReview/onApproved/
      onRejected/onConditional) never actually dispatched an in-app NC
      notification.
- [x] 4.2 Fixed all 7 occurrences to `"nc-notification"` in this file
      (directly in scope — same annotation family this change edits).
      Two other register.d files (`add-shillinq-detachering-payroll-administratie.json`,
      `bookkeeping-ccm-rule-engine.json`) carry the same bug — out of
      scope here, follow-up issue filed.

## 5. Tests

- [x] 5.1 `tests/Unit/Service/OverdueVerantwoordingNotificationTest.php`
      (new, 11 tests): schema-shape assertions (awardDate shape, lifecycle
      `final`, calc `materialise`+type+op-vocabulary+prop-ref validity,
      `onOverdue` trigger/recipient/channel shape, the in-app channel
      regression lock) + a functional mirror-evaluator (implementing the
      documented `CalculationEvaluator` semantics for prop/lit/now/diffDays/
      gt/ne/and) run directly against the declared expression trees,
      covering the retired job's original test matrix: draft >90d overdue,
      submitted ≤90d not overdue, final never overdue regardless of age,
      the 90-day boundary (90=false, 91=true), null awardDate never
      overdue, approved >90d overdue.
- [x] 5.2 `python3 -m json.tool` — fragment is valid JSON after edits.
- [x] 5.3 `vendor/bin/phpcs` on the new test file — clean (0 errors after
      `phpcbf` autofix + 3 manual fixes: doc-comment capitalisation, 2
      inline-ternary → if/else per house style).
- [x] 5.4 `vendor/bin/phpunit -c phpunit-unit.xml --no-coverage` — full
      suite green: 3904 tests (3893 baseline + 11 new), 0 failures, same 4
      pre-existing deprecations as baseline.
- [x] 5.5 `vendor/bin/phpstan analyse` — 0 errors (unaffected: this change
      has no `lib/` PHP changes, only a register.d JSON fragment + a new
      `tests/` file; phpstan/psalm/phpmd all scope `lib/` only per
      `phpstan.neon`/`psalm.xml`/`composer.json`).

## 6. Backfill note (no repair step added)

- [x] 6.1 Documented in design.md: pre-existing `SubsidieVerantwoording`
      records self-heal via `TemporalCalculationSweepJob`'s hourly sweep
      (default `temporal_calculation_sweep_interval` 3600s) the first time
      it runs after deploy — the generic OR mechanism built for exactly
      this "now-dependent materialised calc on an untouched object" case.
      No bespoke repair step added: `RematerialiseConvertedCalculations`
      is scoped by name/docblock to the `revive-declarative-calc-layer`
      migration specifically and was deliberately not repurposed here to
      avoid blurring that class's identity/scope.
