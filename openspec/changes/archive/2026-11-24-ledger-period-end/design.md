# Design: ledger-period-end

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **The checklist lives on the period.** `FiscalPeriod` (`lib/Settings/register.d/bookkeeping-period-close.json`) carries `taskChecklistItems`, an array of `{id, category, description, resolved, resolvedAt, resolvedBy}` with `category` in `ap`, `ar`, `bank`, `expense-claims`. Its transitions are `startClose`, `close`, `reopen`, `lockForAudit`. `PeriodCloseService::mandatoryChecklistResolved()` (`lib/Service/PeriodCloseService.php:324`) blocks `close` while an AP or AR item is unresolved.
- **Nothing writes an item.** `lib/Repair/PeriodCloseBackfill.php:158` and `lib/Repair/BackfillFiscalPeriods.php:276` initialise the list empty. `src/components/period-close/PeriodCloseDetail.vue` renders the table (from line 162) and has no button that changes an item. The page ids are `PeriodClose` and `PeriodCloseDetail` in `src/manifest.d/bookkeeping-period-close.json`.
- **Templates exist without a page.** `CloseChecklistTemplate` (`administrationTypeId`, `templateName`, `tasks`) and `CloseChecklistInstance` (`instanceState`, `periodId`, `tasks`, `templateId`) are declared in `register.d/bookkeeping-soft-close-flux.json`; no manifest page names them.
- **Accruals are computed, not booked.** `lib/BackgroundJob/SoftCloseJob.php` runs `SoftCloseExecutor` nightly per administration. `SoftCloseExecutor::computeAccrualCents()` (line 233) computes an amount per `AutoAccrualRule` and saves an `AutoAccrualPosting` (line 353) whose `journalEntryId` is a formatted string from `journalEntryId()` (line 377): no `JournalEntry` with that id is ever written.
- **No deferral.** Nothing in `lib/Service` spreads a prepaid cost or deferred revenue (matrix evidence, re-read 2026-09-27). `AutoAccrualRule.calculationMethod` has `straight-line-from-contract`, which spreads a contract amount but not an invoice line.
- **Posting.** A `JournalEntry` posted with `postDirect` materialises its `GLTransaction` through `materialise-gl-transaction`, registered by `ledger-posting-path`.

## Goals / Non-Goals

**Goals**
- A period that starts closing shows the administration's checklist, and a person can resolve, reopen and add items.
- A prepaid cost or deferred revenue on an invoice line is released month by month without anyone booking it.
- An accrual the nightly close computes exists in the ledger.

**Non-Goals**
- Checks that resolve items on their own.
- Flux analysis.

## Decisions

### D1. The checklist is copied from a template on `startClose`

A lifecycle action handler `lib/Lifecycle/Action/InstantiateCloseChecklistAction.php`,
referenced by FQCN on `FiscalPeriod.startClose` (the way
`AppendReopenHistoryAction` is referenced on `reopen`), copies the tasks of
the administration's active `CloseChecklistTemplate` into
`taskChecklistItems` when the list is empty. The `category` enum gains
`vat`, `accruals` and `other`.

`CloseChecklistInstance` is not used: the items already live on the period,
and `PeriodCloseService` reads them there. A second copy would be two
records of one list. The instance schema is marked deprecated in its
description.

Alternative considered: `set-fields` with a template literal. Rejected
because the items come from another object.

### D2. Item actions write through the period, with who and when

`PeriodCloseDetail.vue` gains per-row Resolve and Reopen actions and an Add
item action, each saving `taskChecklistItems` through the object store
with `resolvedBy` set to the current user and `resolvedAt` to now. Items
cannot change once the period is `closed` or `auditLocked`.

### D3. Deferrals are a schedule with one release per period

A new `DeferralSchedule` schema: `administrationId`, `sourceReference`
(semantic reference to the invoice line, ADR-048), `kind`
(`prepaid-cost`, `deferred-revenue`), `resultAccount`, `balanceAccount`,
`totalCents`, `periodFrom`, `periodTo`, `releases` (array of
`{periodId, amountCents, journalEntryId}`) and a lifecycle `active`,
`completed`, `cancelled`. It is created from an AP or AR invoice line that
carries a service period spanning more than one fiscal period, from the
invoice detail page's line action "Spread over periods".

On creation, one `JournalEntry` moves `totalCents` from the result account to
the balance account. Each nightly `SoftCloseExecutor` run posts the release
of every period that has ended and has no `journalEntryId`, straight line by
day count, the last release taking the rounding remainder.

Alternative considered: extend `AutoAccrualRule` with an invoice-line
method. Rejected: a rule is a standing policy, a deferral is a one-off
schedule with a known end.

### D4. Accruals write the journal entry they already name

`SoftCloseExecutor` writes a `JournalEntry` (`postDirect`) with the id it
already formats, debit `targetGLAccount`, credit `contraGLAccount`, and
posts it. `reversalPattern` `next-period-start` writes the mirror entry on
the first run of the next period and sets `AutoAccrualPosting.reversalId`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Checklist copied on startClose | Declarative trigger (`x-openregister-lifecycle` action) with a handler | The transition is the moment; copying from another object needs code. |
| Close blocked on unresolved AP/AR items | Unchanged guard | Already built. |
| Releasing deferrals and booking accruals monthly | Imperative, inside the existing nightly `SoftCloseJob` (ADR-031 exception: scheduled bulk work) | The job already runs per administration; this makes it book what it computes. |
| Deferral schedule lifecycle | Declarative: `x-openregister-lifecycle` on `DeferralSchedule` | `completed` when the last release posts. |

## Seed Data

Adviesbureau Van Dijk:

- `CloseChecklistTemplate` "Maandafsluiting": bank (bankmutaties volledig verwerkt), ar (openstaande debiteuren gecontroleerd), ap (inkoopfacturen van de maand geboekt), vat (btw-rekening aangesloten), accruals (vaste lasten nog niet gefactureerd toegerekend).
- `DeferralSchedule`: annual software licence invoice of EUR 1,200 from Leverancier Softwarehuis B.V., service period 2026-01-01 to 2026-12-31, prepaid cost, result account 4300 Automatiseringskosten, balance account 1900 Vooruitbetaalde kosten, twelve releases of EUR 100.
- `AutoAccrualRule` "Energie": fixed amount EUR 350 per month, target 4010 Energie, contra 1920 Nog te betalen kosten, reversal next-period-start.

## Risks / Trade-offs

- [Day-count spreading gives uneven months] → the release amounts are shown on the schedule before the first release, and the last release absorbs rounding so the total is exact.
- [A deferral on a cancelled invoice keeps releasing] → cancelling the schedule is a transition; cancelling it writes one entry returning the unreleased balance to the result account.

## Migration Plan

No migration. Existing `AutoAccrualPosting` records keep their string ids;
from the release on, the id names a real entry.

## Open Questions

None.
