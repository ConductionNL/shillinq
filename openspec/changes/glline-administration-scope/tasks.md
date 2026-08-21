# Tasks: glline-administration-scope

**The order is the change.** Enabling the filter before the backfill is proven
complete silently zeroes every category / cost-centre / period total on live
bookkeeping data. Do not reorder these phases.

## Phase 1 — schema, additive only

- [ ] Add `administrationId` (`type: string`) to the `GLLine` schema.
      **NOT** in `required` — a required property on a schema with existing rows
      fails validation for every un-backfilled row.
- [ ] Add a short property description naming the parent `GLTransaction` as the
      source of truth, so a future reader knows it is denormalised and why.
- [ ] `node tests/validate-registers.js` and `node tests/validate-seeds.js` pass.

## Phase 2 — writers, before any backfill

Every path that creates a `GLLine` must set `administrationId` from the parent
`GLTransaction`, so the backfill has a fixed target rather than a moving one.

- [ ] `lib/Guard/ProgrammaLinkGuard.php`
- [ ] `lib/Repair/BackfillFiscalPeriods.php`
- [ ] `lib/Service/CogsPosterService.php`
- [ ] `lib/Service/InventoryGlAdjustmentPoster.php`
- [ ] `lib/Service/RuleTestDataSeeder.php`
- [ ] `lib/Service/VatSuppletieDetectionService.php`
- [ ] Re-grep for writers rather than trusting this list: it was produced by
      `git grep -ln "schema: 'GLLine'"` and a writer using a different idiom
      would not appear.
- [ ] One unit test per writer asserting the new line carries its parent's
      `administrationId`. Prove each can fail by dropping the assignment.

## Phase 3 — backfill migrator

Follow this repo's own precedent: `BudgetSchemaSplitMigrator`,
`RevenueContractRenameMigrator`, `SubsidieOrderConsolidationMigrator`.

- [ ] `lib/Service/Migration/GlLineAdministrationBackfillMigrator.php`.
- [ ] Resolve each `GLLine`'s administration via its `transactionId` →
      `GLTransaction.administrationId`.
- [ ] **Count-verify then abort**, per `assertCountsMatch()`: if the number of
      lines resolved does not equal the number of lines seen, abort the whole
      batch untouched rather than half-migrating a ledger.
- [ ] A `GLLine` whose parent `GLTransaction` is missing is **unclassifiable** —
      report it, do not guess, do not default to any administration.
- [ ] Report: total, backfilled, unclassifiable. Exit non-zero when
      unclassifiable > 0, so a partial result cannot read as success.
- [ ] Idempotent: re-running must not change an already-backfilled row.

## Phase 4 — prove the backfill is complete

- [ ] A check that counts `GLLine` rows with no `administrationId`. Not a spot
      check — the total.
- [ ] **This gate must be green before Phase 5 begins.** It is the only evidence
      that switching the filter on will not zero the totals.

## Phase 5 — enable the filter, last

- [ ] `SpendAnalyticsService`: pass `administrationId` into the category,
      cost-centre and period aggregations.
- [ ] Delete the `⚠️ ADMINISTRATION SCOPE IS NOT UNIFORM` docblock section and
      its "Do not 'fix' it by adding the unmatched filter" warning — leaving it
      once the fix lands makes the file lie in the other direction.
- [ ] **A positive control**: prove each of the three views can still return
      ROWS after scoping. A view that returns zero because the filter matches
      nothing looks identical to a view with no data, which is the exact failure
      this change exists to avoid.
- [ ] **A negative control**: administration A's totals must not include B's
      rows. Prove it can fail by reverting the filter.

## Phase 6 — e2e

- [ ] Cover the three views through the UI, asserting a scoped total rather than
      mere page render.
- [ ] Locators by `data-testid` / manifest id, never by label — a label-only
      locator has matched another feature's element three times in this repo.

## Explicitly out of scope

- `spendBySupplier()` — already scoped.
- OpenRegister's `_organisation` axis — a different concept.
- Making `administrationId` required on `GLLine`.
