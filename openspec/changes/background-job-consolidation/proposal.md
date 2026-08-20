---
kind: code
depends_on: []
---

# Proposal: background-job-consolidation

## Summary

ADR-069 (`background-job-conventions`, `hydra/openspec/architecture/`) Decision 1
states: **"One directory: `lib/BackgroundJob/`. `lib/Cron/` and `lib/Job/` are
deprecated; new jobs never land there, existing ones move opportunistically."**
shillinq ships all three at once: `lib/BackgroundJob/` (11 classes),
`lib/Cron/` (8 classes), `lib/Job/` (1 class, `MandateDormancyExpiryJob`, which
IS registered in `appinfo/info.xml`). This change moves every class out of
`lib/Cron/` and `lib/Job/` into `lib/BackgroundJob/` — namespace, `use`
statements, and the `appinfo/info.xml` `<background-jobs>` FQCNs all updated
together — and adds the mechanical guard ADR-069's "Known violations" section
implies was always missing: a test asserting every FQCN named in
`<background-jobs>` actually `class_exists()`. A stale FQCN there is not a
build error and not a runtime exception — the job silently never runs, which
is exactly the failure class this consolidation exists to close off for good.

## Motivation

ADR-069's own "Known violations at HEAD (2026-07-26)" section names shillinq
twice: once for the double-registration defect (already fixed on this branch
— `appinfo/info.xml` now registers `HorizonRollingJob` and
`CancelUnconfirmedAppointments` exactly once each, with a comment recording
the fix), and once for the three-directory drift this change addresses:
**"shillinq uses all three (`BackgroundJob/` 10, `Cron/` 10, `Job/` 1)."**
(Current counts on this branch, pre-move: `BackgroundJob/` 11, `Cron/` 8,
`Job/` 1 — the numbers have drifted since the ADR was written but the
three-directory violation is unchanged.)

Nothing about the split is cosmetic. `lib/Cron/PipelinqTimelineRetryJob.php`
is imported by `use` statement from `lib/Controller/TimelineDeadLetterController.php`
and `lib/Service/Pipelinq/PersistentTimelineRetryQueue.php`, and named again in
three test files — every one of those references silently keeps working
today only because nobody has touched the class's location yet. The next
person to "clean up" `lib/Cron/` by hand, without grepping every string
reference (container resolution, test doubles, docblocks, the living
`openspec/architecture/adr-000-data-model.md` annotation that names the FQCN
directly), reproduces the exact defect class ADR-069 was written to close:
a directory move that updates the file but not every string that named its
old path.

## Affected Projects

- [x] Project: `shillinq` — 9 class moves (`lib/Cron/` × 8, `lib/Job/` × 1),
      their `appinfo/info.xml` registrations, 2 consumer `use` statements + 2
      test-file `use` statements, 2 test-file moves, 1 living-doc FQCN
      reference, and a new PHPUnit registration-contract test. No other
      ConductionNL app is touched.

## Scope

### In Scope

- Move all 8 `lib/Cron/*.php` classes and the 1 `lib/Job/MandateDormancyExpiryJob.php`
  class into `lib/BackgroundJob/`, updating each file's `namespace` and
  `@package` docblock only — no logic, schedule, or base-class change.
- Update `appinfo/info.xml`'s `<background-jobs>` block so every moved
  class's FQCN matches its new location.
- Update every other string reference to a moved FQCN: the two consumers of
  `PipelinqTimelineRetryJob` (`TimelineDeadLetterController`,
  `PersistentTimelineRetryQueue`), their and its unit tests' `use` statements,
  and the one living-architecture-doc annotation
  (`openspec/architecture/adr-000-data-model.md`) that names
  `LotExpiryAlertJob`'s FQCN directly. Archived `openspec/changes/archive/**`
  records are left untouched — they are historical record of what a past
  change actually shipped, not a live reference.
- Move the two existing job unit-test files
  (`tests/Unit/Cron/LotExpiryAlertJobTest.php`,
  `tests/Unit/Cron/PipelinqTimelineRetryJobTest.php`) to
  `tests/Unit/BackgroundJob/`, updating their namespace and `use` statement.
  The other 7 moved jobs have no pre-existing unit test — none is created by
  this change (see Out of Scope).
- Add `tests/Unit/AppInfo/BackgroundJobRegistrationContractTest.php`: parses
  `appinfo/info.xml`'s `<background-jobs>` block and asserts every declared
  FQCN resolves via `class_exists()`, extends `TimedJob`/`QueuedJob` per
  ADR-069 D3, and does not live under the now-deprecated `Cron\`/`Job\`
  namespaces — locking the D1 outcome in mechanically so a future addition
  cannot silently reintroduce a third directory.
- Report (not fix) shillinq's status against ADR-069 D2, D4, D5, D6 — see
  Design doc for the per-decision audit; only D1 required a code change on
  this branch.

### Out of Scope

- Creating new unit tests for the 7 moved jobs that currently have none
  (`BankfeedReconciliationJob`, `CalibrationBatchJob`, `CrisisModeRefreshJob`,
  `DocumentArchiveCron`, `SoftCloseJob`, `VendorPerformanceAggregationJob`,
  `MandateDormancyExpiryJob`) or for the 3 already-`BackgroundJob/`-resident
  jobs that also lack one (`BookingReminderJob` and `TaxDeadlineReminderJob`
  and `ViesOutageRetryJob` DO have tests already — see design.md; the
  coverage gap is among jobs that never had a test file regardless of
  directory). Adding coverage where none exists is a real gap but a
  different, larger change than "move a file and update every reference to
  it" — flagged in design.md as a follow-up candidate, not silently expanded
  into this branch's scope.
- Registering `BookingReminderJob`, `TaxDeadlineReminderJob`, or
  `ViesOutageRetryJob` in `appinfo/info.xml` — all three already live in
  `lib/BackgroundJob/` (not moved by this change) but are NOT named in
  `<background-jobs>` today, so they never run in production despite having
  tests and, in `TaxDeadlineReminderJob`'s case, user-facing documentation
  that claims they do (`docs/user-guide/bookkeeping/tax/vpb-administration.md`).
  Registering a previously-dormant job for the first time changes production
  behaviour — new cron executions that have never happened before — which
  is explicitly outside this change's "move, not a rewrite, behaviour stays
  identical" contract. Flagged as a finding for a separate, deliberately
  scoped follow-up.
- ADR-069 D2 (info.xml as the sole registration point vs. runtime
  registration), D5 (dev-seed guarding), D7 (log-cleanup consolidation) —
  audited and reported in design.md, but shillinq's status on each is either
  already-conformant or belongs to a different change's scope (see design.md
  for the reasoning per decision).
- Any job's business logic, schedule/interval, or `TimedJob`/`QueuedJob` base
  class. Every moved job already extends `TimedJob` or `QueuedJob` (verified
  per-file before this change started) — ADR-069 D3 requires no fix here.

## Approach

1. Move each of the 9 files with a plain filesystem move (no content rewrite
   beyond the `namespace`/`@package` lines) into `lib/BackgroundJob/`.
2. Grep exhaustively (`grep -rn 'Shillinq\\\\Cron\\\\'`, `grep -rn
   'Shillinq\\\\Job\\\\'`) across `lib/`, `tests/`, `appinfo/`, and
   `openspec/` (excluding `openspec/changes/archive/`) for every remaining
   string reference to the old FQCNs, and update each one.
3. Update `appinfo/info.xml`'s 9 affected `<job>` entries in place, adding a
   one-line comment noting the ADR-069 D1 move so a future reader does not
   need to `git blame` to understand why the FQCN differs from the
   surrounding entries' history.
4. Move the 2 existing job unit tests to mirror the new namespace.
5. Add `BackgroundJobRegistrationContractTest` and demonstrate it red (a
   deliberately reintroduced stale FQCN) then green (reverted), proving the
   guard actually catches the defect class it exists for.
6. Run `php -l` on every changed file, the full PHPUnit unit suite before and
   after (tallies compared, not just "green"), and the hydra gate suite.

## New Dependencies

None.

## Impact

- `lib/BackgroundJob/` — gains 9 classes (20 total, up from 11).
- `lib/Cron/`, `lib/Job/` — deleted (both now empty and removed).
- `appinfo/info.xml` — 9 `<job>` FQCNs updated.
- `lib/Controller/TimelineDeadLetterController.php`,
  `lib/Service/Pipelinq/PersistentTimelineRetryQueue.php` — `use` statement
  updated.
- `tests/Unit/Controller/TimelineDeadLetterControllerTest.php`,
  `tests/Unit/Service/Pipelinq/PersistentTimelineRetryQueueTest.php` — `use`
  statement updated.
- `tests/Unit/Cron/` — deleted; its 2 files moved to `tests/Unit/BackgroundJob/`.
- `tests/Unit/AppInfo/BackgroundJobRegistrationContractTest.php` — new.
- `openspec/architecture/adr-000-data-model.md` — one FQCN annotation updated.

## Cross-Project Dependencies

None — this is a self-contained shillinq change. It implements company-wide
ADR-069 (already accepted, no changes requested to it) for this one app; no
other app's `lib/Cron/`/`lib/Job/` drift is touched.

## Risks

### Risk 1: A missed string reference silently breaks a working feature

**Severity:** Medium — **Mitigation:** the grep sweep in Approach step 2 is
exhaustive by construction (searches for the literal old namespace string,
not a curated file list), and the new
`BackgroundJobRegistrationContractTest` independently catches the one
highest-consequence category (a stale `info.xml` FQCN) even if a docblock or
comment reference is missed elsewhere. The full PHPUnit suite run before and
after (tallies compared) catches any test file left pointing at a
now-nonexistent class.

### Risk 2: The registration-contract test is satisfied vacuously

**Severity:** Low — **Mitigation:** the test asserts a sanity floor (at
least 10 `<job>` entries found) before asserting the empty-violations case,
so a parser that silently matched nothing cannot read as "all good" — the
same pattern used by this app's existing `ContainerResolvableConstructorsTest`
and `CanonicalRouteMethodContractTest`. It is also demonstrated red (see
Verification) before being demonstrated green.

## Rollback Strategy

Every change in this set is a straight filesystem move plus mechanical
string updates — no data migration, no schema change, no behaviour change.
Revert is a straight `git revert` of the merged PR; nothing to unwind at
runtime.

## Open Questions

None — ADR-069 D1 is unambiguous about the target state, and the moved jobs'
base classes already satisfy D3 so no design decision about a base-class
migration is needed.
