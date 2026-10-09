# Design: background-job-consolidation

## Context

`hydra/openspec/architecture/adr-069-background-job-conventions.md` sets six
decisions for background-job, repair-step, and listener layout across the
fleet. This change implements D1 for shillinq specifically (the one decision
that requires a code change here) and audits the app's status against D2–D6
so the audit is on record rather than assumed.

## ADR-069's Decisions, quoted verbatim

> 1. **One directory: `lib/BackgroundJob/`.** `lib/Cron/` and `lib/Job/` are
>    deprecated; new jobs never land there, existing ones move opportunistically.
> 2. **One registration point: `appinfo/info.xml` `<background-jobs>`.**
>    Runtime registration is reserved for genuinely dynamic jobs (QueuedJob
>    dispatch), not for TimedJob schedules.
> 3. **Every scheduled job extends `TimedJob` or `QueuedJob`** — raw `Job`
>    subclasses are review-blocking (interval throttling is the fleet's
>    poison-job defence).
> 4. **A behaviour is registered exactly once.** Two registered jobs with the
>    same responsibility is a defect (shillinq case above must be fixed by
>    deleting one implementation).
> 5. **Dev/demo seed data never ships in an unguarded production path.** Seeds
>    live in repair steps gated on an explicit dev-mode config flag (single
>    canonical check, from ADR-076's settings plane) — never in
>    SettingsService, never unguarded.
> 6. **One listener dir: `lib/Listener/`.**

(D7, "Generic log-cleanup graduates to an AppHost-provided job," names no
shillinq-specific violation and is not audited here — shillinq has no
log-cleanup job.)

## shillinq's status per decision

| # | Decision | Status at branch start | This change |
|---|---|---|---|
| D1 | One directory | **VIOLATED** — `BackgroundJob/` (11), `Cron/` (8), `Job/` (1) | **FIXED** — all 9 non-canonical classes moved; `lib/Cron/` and `lib/Job/` deleted |
| D2 | info.xml is the sole registration point | **CONFORMANT** — the only runtime `IJobList->add()` calls target `PipelinqTimelineRetryJob`, a `QueuedJob`, which D2 explicitly carves out ("reserved for genuinely dynamic jobs"); no `TimedJob` is registered anywhere outside `info.xml` | Not touched (nothing to fix) |
| D3 | Every job extends TimedJob/QueuedJob | **CONFORMANT** — verified all 20 classes now in `lib/BackgroundJob/` (the 9 moved + the 11 already there): every one extends `TimedJob` or `QueuedJob`; zero raw `\OCP\BackgroundJob\Job` subclasses | Not touched (nothing to fix); moved files kept their existing base class unchanged per the "move, not a rewrite" contract |
| D4 | Registered exactly once | **Already fixed before this branch** — `appinfo/info.xml`'s own comments record the `HorizonRollingJob` / `CancelUnconfirmedAppointments` double-registration (the case ADR-069 names by number) already resolved, duplicate `Cron\` entries already deleted | Not touched (already fixed) |
| D5 | Dev/demo seeds guarded | **VIOLATED, pre-existing, out of scope for this change** — `lib/Repair/LoadCbsSeedsStep.php`, `LoadDbaSeedsStep.php`, `LoadSbrXbrlSeedsStep.php` seed "example records"/fixtures unconditionally in `<post-migration>` with no dev-mode gate visible in their docblocks or bodies (matches ADR-069's own "Known violations" list, which names shillinq for this too, separately from the D1 finding) | **Not fixed** — ADR-069 itself assigns this to "ADR-076's settings plane," a separate change; ADR-076 is not depended on by this change and reworking three repair steps' seed-guarding is a different, larger surface than a directory move |
| D6 | One listener dir | **CONFORMANT** — only `lib/Listener/` exists; no `lib/EventListener/` | Not touched (nothing to fix) |

## Goals / Non-Goals

**Goals:**
- Close ADR-069 D1 for shillinq: one job directory, `lib/BackgroundJob/`.
- Leave a mechanical guard (`BackgroundJobRegistrationContractTest`) that
  catches a stale `info.xml` FQCN — the failure mode that makes a botched
  version of exactly this kind of move invisible until a job silently stops
  running.
- Document shillinq's D2–D6 status so the audit is a matter of record, not
  something the next person re-derives from scratch.

**Non-Goals:**
- Fixing D5 (seed-guarding) — assigned by ADR-069 itself to ADR-076's
  settings plane.
- Adding unit tests for jobs that have never had one, regardless of which
  directory they were in.
- Registering the three currently-dormant `lib/BackgroundJob/` jobs
  (`BookingReminderJob`, `TaxDeadlineReminderJob`, `ViesOutageRetryJob`) that
  are not named in `info.xml` today — see proposal.md's Out of Scope section.
  This is a genuine finding (one of them, `TaxDeadlineReminderJob`, is
  documented in `docs/user-guide/bookkeeping/tax/vpb-administration.md` as
  if it already ran) but registering a dormant job for the first time is a
  behaviour change, not a move.

## The moved classes

| Class | Old namespace | Base class | Existing unit test moved? |
|---|---|---|---|
| `BankfeedReconciliationJob` | `Cron` | `TimedJob` | No — none existed |
| `CalibrationBatchJob` | `Cron` | `TimedJob` | No — none existed |
| `CrisisModeRefreshJob` | `Cron` | `TimedJob` | No — none existed |
| `DocumentArchiveCron` | `Cron` | `TimedJob` | No — none existed |
| `LotExpiryAlertJob` | `Cron` | `TimedJob` | **Yes** — `LotExpiryAlertJobTest.php` |
| `PipelinqTimelineRetryJob` | `Cron` | `QueuedJob` | **Yes** — `PipelinqTimelineRetryJobTest.php` |
| `SoftCloseJob` | `Cron` | `TimedJob` | No — none existed |
| `VendorPerformanceAggregationJob` | `Cron` | `TimedJob` | No — none existed |
| `MandateDormancyExpiryJob` | `Job` | `TimedJob` | No — none existed |

`PipelinqTimelineRetryJob` is the one class with external consumers beyond
`info.xml`: `lib/Controller/TimelineDeadLetterController.php` and
`lib/Service/Pipelinq/PersistentTimelineRetryQueue.php` both `use` it by FQCN
to call `$this->jobList->add(PipelinqTimelineRetryJob::class, ...)` — the
D2-permitted dynamic QueuedJob dispatch pattern. Both `use` statements are
updated; the dispatch call sites themselves (`::class` references) needed no
change since they resolve the constant at the updated `use` line.

## The registration-contract test

`tests/Unit/AppInfo/BackgroundJobRegistrationContractTest.php` parses
`appinfo/info.xml`'s `<background-jobs>` block directly (a source scan, not a
hand-mirrored list — see `CanonicalRouteMethodContractTest` and
`ContainerResolvableConstructorsTest` for the established pattern this
mirrors) and asserts, per declared FQCN:

1. `class_exists($fqcn)` — the mechanical guard this change exists to add.
2. The class extends `TimedJob` or `QueuedJob` (ADR-069 D3).
3. The FQCN does not start with `OCA\Shillinq\Cron\` or `OCA\Shillinq\Job\`
   (locks in the D1 outcome so a future addition cannot silently reintroduce
   a third directory).

Each assertion carries a sanity floor (at least 10 `<job>` entries found)
before asserting the empty-violations case, so a parser that silently
matched nothing cannot read as "all good."

Demonstrated red-then-green during implementation: reverting
`HorizonRollingJob`'s `info.xml` entry to its old `Cron\` FQCN fails both
`testEveryDeclaredBackgroundJobFqcnResolvesToARealClass` (class does not
autoload under that name) and
`testNoDeclaredBackgroundJobUsesTheDeprecatedCronOrJobNamespace`
simultaneously; reverting the edit returns both to green. See the final
verification report for the captured output.

## Verification

- `ls lib/Cron lib/Job` — both directories gone (`No such file or directory`).
- `php -l` on every changed/new PHP file (15 files) — all clean.
- `BackgroundJobRegistrationContractTest` — captured red (deliberately
  reintroduced stale FQCN) then green (reverted).
- Full `phpunit-unit.xml` suite run twice: once against an unmodified `git
  archive HEAD` checkout (before), once against this branch's working tree
  (after) — both using a real (non-symlinked) copy of `vendor/` so
  Composer's classmap resolves within each tree rather than through a
  symlink back to the live worktree.
- `openspec validate background-job-consolidation --strict`.
- Hydra gate suite (`run-hydra-gates.sh --app-dir .`,
  `HYDRA_GATE_BASE_REF=origin/development`).
