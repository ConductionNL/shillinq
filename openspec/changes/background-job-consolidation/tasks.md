# Tasks: background-job-consolidation

## Implementation Tasks

### Task 1: Move the 9 classes out of `lib/Cron/` and `lib/Job/`
- **spec_ref**: `openspec/changes/background-job-consolidation/specs/background-job-consolidation/spec.md#req-001`
- **files**: `lib/BackgroundJob/BankfeedReconciliationJob.php`,
  `lib/BackgroundJob/CalibrationBatchJob.php`,
  `lib/BackgroundJob/CrisisModeRefreshJob.php`,
  `lib/BackgroundJob/DocumentArchiveCron.php`,
  `lib/BackgroundJob/LotExpiryAlertJob.php`,
  `lib/BackgroundJob/PipelinqTimelineRetryJob.php`,
  `lib/BackgroundJob/SoftCloseJob.php`,
  `lib/BackgroundJob/VendorPerformanceAggregationJob.php`,
  `lib/BackgroundJob/MandateDormancyExpiryJob.php` (all new; old `lib/Cron/*`
  and `lib/Job/*` paths deleted)
- **acceptance_criteria**:
  - GIVEN each of the 9 files WHEN moved THEN its `namespace` and `@package`
    docblock read `OCA\Shillinq\BackgroundJob`, and no other line changes
    (base class, logic, schedule unchanged)
  - GIVEN `lib/Cron/` and `lib/Job/` WHEN the move completes THEN both
    directories no longer exist
- [x] Implement
- [x] Test (`php -l` clean on all 9 moved files; `ls lib/Cron lib/Job` →
      both absent)

### Task 2: Update `appinfo/info.xml`'s `<background-jobs>` FQCNs
- **spec_ref**: `openspec/changes/background-job-consolidation/specs/background-job-consolidation/spec.md#req-002`
- **files**: `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the 9 moved classes WHEN their `<job>` entries are read THEN each
    names the `OCA\Shillinq\BackgroundJob\*` FQCN, not the old `Cron\`/`Job\`
    one
  - GIVEN the full `<background-jobs>` block WHEN
    `BackgroundJobRegistrationContractTest` runs THEN every declared FQCN
    resolves via `class_exists()`
- [x] Implement
- [x] Test (`BackgroundJobRegistrationContractTest` green; demonstrated red
      first against a deliberately reverted single entry, see design.md)

### Task 3: Update every other reference to a moved FQCN
- **spec_ref**: `openspec/changes/background-job-consolidation/specs/background-job-consolidation/spec.md#req-004`
- **files**: `lib/Controller/TimelineDeadLetterController.php`,
  `lib/Service/Pipelinq/PersistentTimelineRetryQueue.php`,
  `tests/Unit/Controller/TimelineDeadLetterControllerTest.php`,
  `tests/Unit/Service/Pipelinq/PersistentTimelineRetryQueueTest.php`,
  `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN `grep -rn "Shillinq\\Cron\\" lib/ tests/ appinfo/ openspec/` (openspec
    scoped to exclude `changes/archive/`) WHEN run after this task THEN it
    returns zero matches
  - GIVEN `grep -rn "Shillinq\\Job\\" lib/ tests/ appinfo/ openspec/` (same
    scoping) WHEN run after this task THEN it returns zero matches
- [x] Implement
- [x] Test (grep sweep, see verification report)

### Task 4: Move the 2 existing job unit tests
- **spec_ref**: `openspec/changes/background-job-consolidation/specs/background-job-consolidation/spec.md#req-001`
- **files**: `tests/Unit/BackgroundJob/LotExpiryAlertJobTest.php`,
  `tests/Unit/BackgroundJob/PipelinqTimelineRetryJobTest.php` (moved from
  `tests/Unit/Cron/`)
- **acceptance_criteria**:
  - GIVEN the 2 moved test files WHEN their `namespace` and `use` statement
    for the class under test are read THEN both name
    `OCA\Shillinq\Tests\Unit\BackgroundJob` and
    `OCA\Shillinq\BackgroundJob\*` respectively
  - GIVEN `tests/Unit/Cron/` WHEN the move completes THEN it no longer exists
- [x] Implement
- [x] Test (both moved test files run green as part of the full suite)

### Task 5: Add the info.xml registration-contract test
- **spec_ref**: `openspec/changes/background-job-consolidation/specs/background-job-consolidation/spec.md#req-002`, `#req-003`
- **files**: `tests/Unit/AppInfo/BackgroundJobRegistrationContractTest.php` (new)
- **acceptance_criteria**:
  - GIVEN every FQCN declared in `appinfo/info.xml`'s `<background-jobs>`
    block WHEN the test runs THEN it asserts `class_exists()`, that the class
    extends `TimedJob`/`QueuedJob`, and that its namespace is not
    `Cron\`/`Job\`
  - GIVEN a deliberately reintroduced stale FQCN WHEN the test runs THEN it
    fails, naming the offending FQCN
  - GIVEN the fix reverted WHEN the test runs again THEN it passes
- [x] Implement
- [x] Test (captured red-then-green, see final verification report)

### Task 6: Full-suite and gate verification
- **spec_ref**: `openspec/changes/background-job-consolidation/specs/background-job-consolidation/spec.md#req-004`
- **files**: N/A — verification pass
- **acceptance_criteria**:
  - GIVEN `/usr/bin/php8.2 vendor/bin/phpunit -c phpunit-unit.xml` (or the
    nearest available PHP 8.x CLI on this box) WHEN run against an unmodified
    `git archive HEAD` checkout and again against the working tree THEN both
    tallies are captured and compared, with zero failures in both and the
    "after" delta matching exactly the 3 new tests / 13 assertions this
    change adds
  - GIVEN `php -l` WHEN run against every changed/new file THEN all report
    "No syntax errors detected"
  - GIVEN `run-hydra-gates.sh --app-dir .` with
    `HYDRA_GATE_BASE_REF=origin/development` WHEN run THEN its output is
    captured in the final report
  - GIVEN `openspec validate background-job-consolidation --strict` WHEN run
    THEN it passes
- [x] Implement (N/A — verification-only task)
- [x] Test (see final verification report for full captured output)

## Quality checklist

<!-- Reminders for the builder, not tracked checkboxes. -->

- No job logic, schedule, or `TimedJob`/`QueuedJob` base class changed —
  this is a move, not a rewrite (verified: all 9 moved classes' base class
  and body are byte-identical apart from the `namespace`/`@package` lines).
- `openspec validate` passes.
- Archived `openspec/changes/archive/**` records are left untouched — they
  are historical record, not live references.
