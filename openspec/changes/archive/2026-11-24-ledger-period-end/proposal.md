---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: ledger-period-end

## Summary

At month end a bookkeeper works down a list of what still has to happen,
and the books spread prepaid costs and deferred revenue over the months they
belong to. Shillinq shows a checklist panel that is always empty and
computes accruals it never books. This change fills the checklist from a
template when a period starts closing, lets a person resolve and add items,
and books deferral and accrual entries period by period.

## Motivation

Two ledger rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), both decided
`build` by the OpenSpec pass of 2026-09-27.

**`led-close-checklist`**, "Work through a period-end close checklist and
see what is still open." Rated partial, built. Matrix evidence:
"every writer initialises the list empty (lib/Repair/PeriodCloseBackfill.php:158,
lib/Repair/BackfillFiscalPeriods.php:276) and no code or button adds or
resolves an item; CloseChecklistTemplate/Instance schemas
(bookkeeping-soft-close-flux.json) have no page." Note: "The checklist panel
always shows 'No checklist items yet.'" Two competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Smart Closing is een geintegreerd checklist die over meerdere administraties heen automatische controles uitvoert ... voor je maandafsluiting".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/461701-takenlijsten, task lists per month, quarter or year with a deadline and templates.

**`led-deferrals`**, "Spread deferred revenue and prepaid costs over their
periods automatically." Rated partial, built. Matrix evidence:
"lib/Service/SoftCloseExecutor.php:233 computeAccrualCents ... saves
AutoAccrualPosting records (line ~352), not GLTransactions; no service
spreads prepaid costs or deferred revenue balances over periods." All five
competitors rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-generaljournal-fingen-defrevanddefcostt?language=en_GB, "The cost will be spread out over the number of periods".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207998-periodeselectie-op-je-facturen, "wordt het bedrag evenredig verdeeld over deze maanden".
- snelstart: https://www.snelstart.nl/nieuwinsnelstart/nog-makkelijker-boekhouden-met-snelstart-12-39, "een inkoopboeking te verdelen over een aantal maanden: transitorisch boeken".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/hoe-je-een-inko-3041124, "Factuur spreiden".
- odoo: https://www.odoo.com/documentation/19.0/applications/finance/accounting/customer_invoices/deferred_revenues.html, "Odoo can automatically create deferral entries upon invoice validation".

## Affected Projects

- [ ] Project: `shillinq`: checklist instantiation and actions on the period close page, a template page, a deferral schedule, and real journal entries from the nightly close.

## Scope

### In Scope

- Filling `FiscalPeriod.taskChecklistItems` from the administration's `CloseChecklistTemplate` when `startClose` runs.
- Resolving, reopening and adding checklist items on the period close detail page, with who and when.
- A page to keep close checklist templates.
- A `DeferralSchedule` created from an invoice line whose service period spans more than one period, and its monthly release.
- The nightly soft close writing a real journal entry for each accrual it computes, and reversing it per the rule's `reversalPattern`.

### Out of Scope

- Automatic checks that resolve an item on their own (Exact's "automatische controles"). A later change can attach a check key to a template task.
- The flux swing analysis (`led-soft-close`, deferred).

## Approach

The checklist is instantiated by a lifecycle action on `startClose`, the
same handler shape as `AppendReopenHistoryAction`. Deferrals and accruals
are booked as `JournalEntry` records posted with `postDirect`, which
`ledger-posting-path` makes work. Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/bookkeeping-period-close.json` and `bookkeeping-soft-close-flux.json`: the startClose action, the item category list, the `DeferralSchedule` schema.
- `lib/Service/SoftCloseExecutor.php`: accrual and deferral booking.
- `src/components/period-close/PeriodCloseDetail.vue` and `src/manifest.d/bookkeeping-period-close.json`: item actions and the template page.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: The nightly job starts writing ledger entries it only recorded before
**Severity:** Medium. **Mitigation:** entries are keyed on rule, period and version so a rerun writes nothing twice, and the first run after release is listed per administration in the close metrics.

### Risk 2: A template change alters open periods
**Severity:** Low. **Mitigation:** a period copies its items when it starts closing; later template edits apply to later periods only.

## Rollback Strategy

Remove the startClose action and restore the accrual record-only path. Posted
deferral and accrual entries stay; each can be reversed through the normal
reverse transition.

## Open Questions

None.
