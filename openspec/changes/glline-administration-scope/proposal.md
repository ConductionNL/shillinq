# Change: glline-administration-scope

## Why

Three of the four SpendAnalytics views aggregate **every administration in the
register**. A member of administration A can read administration B's category,
cost-centre and period totals.

This is not a suspicion. `lib/Service/SpendAnalyticsService.php:36-70` documents
it at length, and the measurement holds today on `development`: `GLLine`
declares no administration or organisation property at all. Its properties are
`transactionId`, `lineNumber`, `accountNumber`, `side`, `amount`, `currency`,
`description`, `costCenter(Code)`, `costCarrierCode`, `projectCode`, `periodId`,
`ozbCategory`, `dimensions`, `subLedgerType`, `subLedgerRef`, `accountRef`,
`eliminationFlag`. The administration lives one hop away, on the parent
`GLTransaction`.

`spendBySupplier()` IS correctly scoped, because `APTransaction` carries
`administrationId` and the aggregation filter can use it. The asymmetry between
that view and the other three is the whole problem: the service looks scoped.

`AdministrationContextService::canAccess()` on the controller reduces the
audience from "any authenticated Nextcloud user" to "a member of some
administration". It does not stop a member of A reading B's totals.

## What the naive fix does, and why it is worse than the bug

Adding `administrationId` to the GLLine aggregation filter **today** would
address a property that does not exist. OpenRegister filters address the
object's own JSON properties; an unmatched key matches nothing for every value.
Every category, cost-centre and period total would silently read **zero**.

The service's own docblock says exactly this, and says *"Do not 'fix' it by
adding the unmatched filter."* A silent zero in a bookkeeping total is worse
than the exposure it pretends to close, because the exposure is at least
visible to whoever looks.

Also already ruled out there, verified rather than assumed:
- OpenRegister's `AggregationRunner` applies a `_organisation = ?` predicate.
  `_organisation` is **OpenRegister's** tenancy axis, not shillinq's
  administration — many administrations live inside one organisation, which is
  the entire point of `AdministrationMembership`.
- `x-openregister-rbac` on `APTransaction` is read by **zero** PHP.

## What changes

`administrationId` is denormalised onto `GLLine`, every writer sets it, existing
rows are backfilled from their parent `GLTransaction`, and **only then** is the
filter switched on.

The ordering is the change. Enabling the filter before the backfill is complete
produces the silent-zero failure above, on live bookkeeping data.

## Scope

- **57 files** reference `GLLine`.
- At least **6** write it directly: `ProgrammaLinkGuard`,
  `BackfillFiscalPeriods`, `CogsPosterService`, `InventoryGlAdjustmentPoster`,
  `RuleTestDataSeeder`, `VatSuppletieDetectionService`.
- Three consumer views change behaviour once the filter lands.

## Non-goals

- Changing `spendBySupplier()`, which is already scoped correctly.
- Touching OpenRegister's `_organisation` axis. It is a different concept and
  conflating the two is how this gets "fixed" wrongly a second time.
- Making `administrationId` `required` on `GLLine` in the same change — a
  required property on a schema with existing rows fails validation for every
  un-backfilled row. Required comes after the backfill is proven complete, if
  at all.
