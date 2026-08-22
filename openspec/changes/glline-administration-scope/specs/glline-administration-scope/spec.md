# Spec: glline-administration-scope

## ADDED Requirements

### Requirement: REQ-GLS-001 — `GLLine` MUST carry its own `administrationId`

`GLLine` MUST declare an `administrationId` property, denormalised from its
parent `GLTransaction`. It MUST NOT be added to the schema's `required` list in
this change: a required property on a schema with existing rows fails validation
for every row not yet backfilled.

#### Scenario: A newly written GLLine carries its parent's administration

- **GIVEN** a `GLTransaction` with `administrationId` `adm-A`
- **WHEN** any writer creates a `GLLine` for that transaction
- **THEN** the stored line MUST carry `administrationId` `adm-A`

@e2e exclude storage-shape assertion with no browser-observable behaviour of its
own; enforced by one unit test per writer

### Requirement: REQ-GLS-002 — the backfill MUST abort rather than half-migrate

The backfill migrator MUST resolve each `GLLine`'s administration through its
`transactionId`, and MUST count-verify before committing: when the number of
lines resolved does not equal the number seen, it MUST abort the whole batch
untouched, following this repo's `assertCountsMatch()` precedent.

A `GLLine` whose parent `GLTransaction` cannot be found MUST be reported as
**unclassifiable**. It MUST NOT be assigned a default or guessed administration,
and the migrator MUST exit non-zero while any remain, so a partial result cannot
be read as success.

#### Scenario: A missing parent aborts rather than guesses

- **GIVEN** a `GLLine` whose `transactionId` matches no `GLTransaction`
- **WHEN** the backfill runs
- **THEN** it MUST report that line as unclassifiable, leave every row
  unchanged, and exit non-zero

#### Scenario: Re-running the backfill changes nothing

- **GIVEN** a register whose `GLLine` rows are already backfilled
- **WHEN** the backfill runs again
- **THEN** no row MUST be modified

@e2e exclude a data migration with no UI surface; enforced by unit tests over a
faithful in-memory store

### Requirement: REQ-GLS-003 — the filter MUST NOT be enabled before the backfill is proven complete

`SpendAnalyticsService`'s category, cost-centre and period aggregations MUST NOT
filter on `administrationId` until a check has confirmed that **zero** `GLLine`
rows lack one.

This ordering is normative, not advisory. OpenRegister filters address the
object's own JSON properties, so filtering on a property that some rows lack
matches nothing for those rows and yields a **silent zero** in a bookkeeping
total — a wrong number that looks like a real one, which is worse than the
cross-administration read it is meant to close.

#### Scenario: Each scoped view can still return rows

- **GIVEN** a backfilled register with GL activity in administration `adm-A`
- **WHEN** an `adm-A` member opens the category, cost-centre and period views
- **THEN** each MUST return at least one row

This is the positive control. A view returning zero because its filter matches
nothing is indistinguishable from a view with no data, and that is precisely the
failure this requirement exists to prevent.

#### Scenario: One administration's totals exclude another's

- **GIVEN** GL activity in both `adm-A` and `adm-B`
- **WHEN** an `adm-A` member opens the three views
- **THEN** no `adm-B` row MUST contribute to any total

@e2e exclude neither scenario's PRECONDITIONS can be established in the e2e
environment. `totals-exclude-another-administration` needs two administrations
that both carry GL activity plus a member of exactly one of them;
`scoped-views-still-return-rows` needs a register whose GLLine backfill has
already been proven complete, and the completeness gate (REQ-GLS-003) is shut
on every environment available to the suite — while it is shut the views raise
by design, so a Playwright assertion there would be measuring the gate, not the
scoping. Both scenarios are enforced instead by
`tests/Unit/Service/SpendAnalyticsServiceTest.php`
(`testGlBackedViewsExcludeOtherAdministrations` for the negative control,
`testScopedViewsStillReturnRowsAndRealTotals` for the positive one), each proven
to fail with the guard removed.

⚠️ This reason was REWRITTEN. It previously read "`/api/analytics/spend` has NO
frontend consumer", which was true when written and became FALSE the moment
`feat/spend-analytics-ui` (#1143) landed a page that calls this endpoint. The
exclusion is still warranted, but not for that reason — and an exclusion whose
stated reason has quietly expired is exactly how a gate stops meaning anything.
Re-tag as `@e2e glline-administration-scope::…` once a seeded, backfilled
two-administration fixture exists.

### Requirement: REQ-GLS-004 — the stale warning MUST be removed with the fix

When the filter is enabled, the `⚠️ ADMINISTRATION SCOPE IS NOT UNIFORM` section
of `SpendAnalyticsService`'s docblock — including its _"Do not 'fix' it by adding
the unmatched filter"_ instruction — MUST be deleted.

Leaving it makes the file lie in the opposite direction, telling the next reader
the views are unscoped when they are scoped, and warning them off a fix that has
already landed.

#### Scenario: The warning and the filter cannot both be present

- **GIVEN** `SpendAnalyticsService` passes `administrationId` into the category,
  cost-centre and period aggregations
- **WHEN** the file is inspected
- **THEN** it MUST NOT still contain the `ADMINISTRATION SCOPE IS NOT UNIFORM`
  section or the "Do not 'fix' it by adding the unmatched filter" instruction

This mirrors the symmetric shape used by `check:job-registration`: the state and
the documentation of that state are checked together, so fixing one without the
other fails rather than producing a new false statement.

@e2e exclude documentation accuracy with no browser-observable behaviour;
enforceable as a grep-level check in the same change
