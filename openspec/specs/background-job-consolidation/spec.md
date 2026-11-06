# background-job-consolidation Specification

## Purpose
Implements ADR-069 Decision 1 for shillinq: a single canonical background-job
directory, `lib/BackgroundJob/`. Moves every class out of the deprecated
`lib/Cron/` and `lib/Job/` directories, updates every reference to their old
FQCNs (`appinfo/info.xml`, consumer `use` statements, test doubles, the one
living-architecture-doc annotation that names a moved class), and adds a
mechanical guard proving every `info.xml`-declared background job resolves to
a real, correctly-based class. Does not fix ADR-069 D5 (seed-guarding) — see
Notes.

## Requirements

### Requirement: REQ-001: `lib/BackgroundJob/` SHALL be the only job directory

shillinq MUST NOT ship `lib/Cron/` or `lib/Job/` directories. Every class that
implements a Nextcloud background job MUST live under `lib/BackgroundJob/`
with namespace `OCA\Shillinq\BackgroundJob`.

#### Scenario: The deprecated job directories no longer exist

- **GIVEN** the repository root
- **WHEN** `lib/Cron/` and `lib/Job/` are checked for existence
- **THEN** neither directory exists

@e2e exclude filesystem-layout check, verified by `ls lib/Cron lib/Job`
returning "No such file or directory"; not a browser flow.

#### Scenario: Every moved class carries the new namespace

- **GIVEN** the 9 classes formerly under `lib/Cron/` (8 classes) and
  `lib/Job/` (1 class: `MandateDormancyExpiryJob`)
- **WHEN** each moved file's `namespace` declaration is read
- **THEN** it reads `namespace OCA\Shillinq\BackgroundJob;`

@e2e exclude verified by `php -l` + `class_exists()` against the moved files
via `BackgroundJobRegistrationContractTest`, a PHPUnit-level static check,
not a browser flow.

### Requirement: REQ-002: Every `info.xml`-declared background job SHALL resolve to a real class

Every FQCN listed in `appinfo/info.xml`'s `<background-jobs>` block MUST
`class_exists()` under Composer's PSR-4 autoloader. This is the mechanical
guard against a stale registration: a job whose FQCN no longer matches its
file's actual location does not error at build time or at runtime — it
simply never executes, silently, forever.

#### Scenario: Every declared job FQCN autoloads

- **GIVEN** the full `<background-jobs>` block in `appinfo/info.xml`
- **WHEN** `class_exists($fqcn)` is checked for every declared `<job>` entry
- **THEN** every entry returns `true`

@e2e background-job-consolidation::registration-contract

#### Scenario: A stale FQCN is caught, not silently ignored

- **GIVEN** an `info.xml` `<job>` entry temporarily reverted to name a class
  under the deprecated `Cron\` namespace (the class file itself has already
  moved to `BackgroundJob\`)
- **WHEN** `BackgroundJobRegistrationContractTest` is run
- **THEN** `testEveryDeclaredBackgroundJobFqcnResolvesToARealClass` fails,
  naming the stale FQCN in its failure message

@e2e background-job-consolidation::registration-contract

### Requirement: REQ-003: Every declared background job SHALL extend `TimedJob` or `QueuedJob`

Every class declared in `appinfo/info.xml`'s `<background-jobs>` block MUST
extend `OCP\BackgroundJob\TimedJob` or `OCP\BackgroundJob\QueuedJob`. Per
ADR-069 D3, a raw `\OCP\BackgroundJob\Job` subclass MUST NOT be declared —
raw `Job` subclasses skip interval throttling, the fleet's poison-job
defence.

#### Scenario: Every declared job extends the correct base class

- **GIVEN** every FQCN declared in `appinfo/info.xml`'s `<background-jobs>`
  block that resolves to a real class
- **WHEN** each class's parent-class chain is reflected
- **THEN** it extends `OCP\BackgroundJob\TimedJob` or
  `OCP\BackgroundJob\QueuedJob`

@e2e background-job-consolidation::registration-contract

### Requirement: REQ-004: No consumer SHALL reference a moved class by its old FQCN

Every file that imports or references one of the 9 moved classes by FQCN
(controller/service `use` statements, unit-test `use` statements and test
doubles, and the one living-architecture-doc annotation that names a moved
class's FQCN directly) MUST be updated to the new
`OCA\Shillinq\BackgroundJob\*` namespace. Historical records under
`openspec/changes/archive/**` are exempt — they document what a past change
actually shipped at the time and are not live references.

#### Scenario: PipelinqTimelineRetryJob's consumers import the new namespace

- **GIVEN** `lib/Controller/TimelineDeadLetterController.php` and
  `lib/Service/Pipelinq/PersistentTimelineRetryQueue.php`, both of which
  dispatch `PipelinqTimelineRetryJob` via `IJobList::add()`
- **WHEN** each file's `use` statement for `PipelinqTimelineRetryJob` is read
- **THEN** it reads `use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;`

@e2e exclude verified by `grep -rn "Shillinq\\Cron\\" lib/ tests/` returning
zero matches outside `openspec/changes/archive/`; a source-scan check, not a
browser flow.

#### Scenario: The full unit suite has no regression from the move

- **GIVEN** the full `phpunit-unit.xml` suite run against an unmodified `git
  archive HEAD` checkout (before this change) and again against this
  change's working tree (after)
- **WHEN** the two tallies are compared
- **THEN** the "after" tally shows zero failures and exactly the delta
  contributed by the new `BackgroundJobRegistrationContractTest` (3 tests /
  13 assertions), with no other test count regression

@e2e exclude full-suite PHPUnit run, captured in the final verification
report; not a browser flow.
