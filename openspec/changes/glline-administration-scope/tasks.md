# Tasks: glline-administration-scope

**The order is the change.** Enabling the filter before the backfill is proven
complete silently zeroes every category / cost-centre / period total on live
bookkeeping data. Do not reorder these phases.

## Phase 1 — schema, additive only

- [x] Add `administrationId` (`type: string`) to the `GLLine` schema.
      **NOT** in `required` — a required property on a schema with existing rows
      fails validation for every un-backfilled row.
      → `lib/Settings/register.d/glline-administration-scope.json`, an ADR-037
      additive overlay (properties only, no `type`/`required`), so the monolith
      `shillinq_register.json` is never edited.
- [x] Add a short property description naming the parent `GLTransaction` as the
      source of truth, so a future reader knows it is denormalised and why.
- [x] `node tests/validate-registers.js` and `node tests/validate-seeds.js` pass.

## Phase 2 — writers, before any backfill

Every path that creates a `GLLine` must set `administrationId` from the parent
`GLTransaction`, so the backfill has a fixed target rather than a moving one.

- [x] Re-grepped for writers rather than trusting the authored list. **The list
      in the original tasks.md was wrong in both directions.** `git grep -n
      "schema: 'GLLine'|setSchema('GLLine')|saveObject(... 'GLLine')"` over
      `lib/` finds 25 call sites, of which only **4 files** WRITE; the rest read.
      - `lib/Guard/ProgrammaLinkGuard.php` — **reader**, not a writer
        (`fetchStoredProgramme()` does `->setSchema('GLLine')->find($id)`).
      - `lib/Repair/BackfillFiscalPeriods.php` — **reader**; it writes
        `FiscalPeriod`, never `GLLine`.
- [x] `lib/Service/CogsPosterService.php` — both COGS legs.
- [x] `lib/Service/InventoryGlAdjustmentPoster.php` — both adjustment legs.
- [x] `lib/Service/RuleTestDataSeeder.php` — both seeded balance legs.
- [x] `lib/Service/VatSuppletieDetectionService.php` — the per-rubriek delta
      lines and the clearing line.
- [x] Every one of the four already had the parent's `administrationId` in
      scope at the write site (it is set on the `GLTransaction` header a few
      lines above), so no new plumbing was needed.
- [ ] One unit test per writer asserting the new line carries its parent's
      `administrationId`. **NOT DONE** — see "Known gaps" below.

## Phase 3 — backfill migrator

- [x] `lib/Service/Migration/GlLineAdministrationBackfillMigrator.php`, following
      `BudgetSchemaSplitMigrator`'s shape (pure, unit-testable, no OR deps).
- [x] Resolve each `GLLine`'s administration via its `transactionId` →
      `GLTransaction.administrationId`. Indexes the parent under its object id,
      `@self.id`, `uuid` AND `transactionNumber`, because `RuleTestDataSeeder`
      writes the business key into `transactionId` while every other writer
      writes the UUID — an index over UUIDs alone would have called all those
      lines unclassifiable and aborted every batch.
- [x] **Count-verify then abort**, per `assertCountsMatch()`: one unclassifiable
      row aborts the whole batch untouched, including the rows that resolved.
- [x] A `GLLine` whose parent is missing — or whose parent is itself unscoped —
      is **unclassifiable**: reported, never guessed, never defaulted.
- [x] Report: total, backfilled, unchanged, unclassifiable, conflicting. The
      repair step leaves the gate CLOSED whenever any remain, which is the
      stronger form of "cannot read as success" for an in-process step.
- [x] Idempotent: an already-scoped row is returned byte-identical and proposes
      no write. An already-scoped row that DISAGREES with its parent is
      reported as `conflicting` and deliberately not rewritten.

## Phase 4 — prove the backfill is complete

- [x] `countMissingAdministrationId()` counts rows with no `administrationId` —
      a TOTAL over every row handed to it, never a sample.
- [x] `lib/Repair/BackfillGlLineAdministration.php` (registered in
      `appinfo/info.xml`, ahead of `BackfillFiscalPeriods`) **RE-READS every
      `GLLine` from the store after writing** and opens the gate only on a
      count of exactly zero. The migrator's own return value is a report, not
      proof; the gate is a measurement of the store taken after the fact.
- [x] The gate is **cleared at the START of the step**, so every intermediate
      state — a crash mid-write, an aborted batch, an OR outage, an upgrade
      that never reached the step — reads as shut and fails closed.

## Phase 5 — enable the filter, last

- [x] `SpendAnalyticsService`: `administrationId` passed into the category,
      cost-centre and period aggregations; all three now take the caller's
      administration as a parameter, and `SpendAnalyticsController::dispatch()`
      hands them the value it already proved membership for.
- [x] Gated: `assertGlScopeIsEnforceable()` RAISES unless the app-config gate
      holds `GATE_CONTRACT_VERSION`. A **version, not a boolean**, so that
      adding a new `GLLine` writer can invalidate every deployment's stored
      proof by bumping one constant — completeness is a claim about the code as
      well as the data.
- [x] An empty `administrationId` is refused too: `administrationId = ''`
      matches nothing, which is the same silent zero by another route.
- [x] Deleted the `⚠️ ADMINISTRATION SCOPE IS NOT UNIFORM` docblock section and
      its "Do not 'fix' it by adding the unmatched filter" instruction, plus the
      matching stale paragraph in `SpendAnalyticsController`'s docblock and the
      now-false `REQ-SPA-005` in `openspec/specs/spend-analytics/spec.md` (whose
      scenario asserted the filter must NOT carry an administration term).
- [x] **Positive control**: `testScopedViewsStillReturnRowsAndRealTotals` —
      proven to fail ("category returned no rows at all") when the fixture rows
      lack `administrationId`, i.e. in exactly the state the forbidden naive fix
      would have produced.
- [x] **Negative control**: `testGlBackedViewsExcludeOtherAdministrations` —
      proven to fail (9100.0 vs 100.0) with the filter term removed.

## Phase 6 — e2e

- [ ] **Not applicable as written.** `/api/analytics/spend` has NO frontend
      consumer: `grep -rn "analytics/spend" src/` returns nothing and no
      `src/manifest.d/` page declares it. The four spend views are an API-only
      surface, so there is no UI to cover and no `data-testid` to locate. Both
      scenarios now carry `@e2e exclude` with that reason. Re-tag them as
      `@e2e glline-administration-scope::…` when a UI consumer lands.

## Known gaps

- **No per-writer unit test.** The four writers are covered indirectly (the
  service tests prove the filter, the migrator tests prove the backfill), but
  nothing asserts that e.g. `CogsPosterService` stamps the parent's scope onto
  the line it writes. A writer that regressed would not fail the suite; it
  would surface as the completeness gate flipping shut on the next repair run,
  which fails closed but only after the fact.

## Explicitly out of scope

- `spendBySupplier()` — already scoped.
- OpenRegister's `_organisation` axis — a different concept.
- Making `administrationId` required on `GLLine`.
