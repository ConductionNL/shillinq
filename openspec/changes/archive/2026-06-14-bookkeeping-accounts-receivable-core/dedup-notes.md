# Dedup Notes — bookkeeping-accounts-receivable-core

**Date:** 2026-06-10
**Author:** opsx-ff (solo build, AR-core T3 / mirror of just-completed AP-core)
**Per:** Task 7.1 in `tasks.md`, ADR-031 anti-pattern enumeration, and the
mirror relationship to `bookkeeping-accounts-payable-core/dedup-notes.md`
(2026-06-09).

## Scan Methodology

Searched the worktree for the canonical AR names declared by REQ-AR-001 /
REQ-AR-002 / REQ-AR-003 / REQ-AR-005 of this spec, plus their alternate
spellings, plus the `lib/Db/` mapper anti-pattern listed in REQ-AR-001's
"Reviewer confirms no parallel AR table" scenario.

```bash
# canonical T2 names
grep -rE 'class (ARInvoice|CustomerMaster|DunningRecord)' lib/
grep -nE '"(CustomerMaster|ARInvoice|DunningRecord)"' lib/Settings/shillinq_register.json
grep -nE '"(CustomerMaster|ARInvoice|DunningRecord)"' lib/Settings/register.d/*.json

# anti-patterns per ADR-031
find lib/Db -iname '*Customer*' -o -iname '*ARInvoice*' -o -iname '*Dunning*'
find lib -iname 'AR*Service.php' -o -iname 'Dunning*Service.php' -o -iname 'Customer*Service.php'

# parallel report-services that aggregations should replace
find lib -iname 'ARAging*' -o -iname 'AccountsReceivable*Report*'

# alternate pre-T2 / fleet-overlap names
grep -nE '"(Debiteur|Klant|Klantfactuur)"' lib/Settings/shillinq_register.json
grep -nE '"contact"' lib/Settings/shillinq_register.json
```

## Findings

### Canonical T2 names — declared via the modular register

- `CustomerMaster`, `ARInvoice`, `DunningRecord` are declared in
  `lib/Settings/shillinq_register.json` at lines 12597, 12792, 13438 —
  loaded via `ConfigurationService::importFromApp('shillinq', ...)` per
  ADR-024 / ADR-037.
- **No** PHP class named `ARInvoice`, `CustomerMaster`, `DunningRecord`,
  or `AccountsReceivable*` exists under `lib/` beyond the one ADR-031-
  exception PHP seam (`lib/Guard/CreditLimitGuard.php` — REQ-AR-006).
- **No** `lib/Db/` Mapper class names `ar_invoice`, `customer_master`,
  `dunning_record`, or `accounts_receivable_*` — the REQ-AR-001
  "Reviewer confirms no parallel AR table" scenario is satisfied for the
  canonical names.

### Single PHP seam justified — `CreditLimitGuard`

`lib/Guard/CreditLimitGuard.php` carries one method (`check(arInvoice):
GuardResult`) implementing the REQ-AR-006 cross-object precondition
(`sum(open ARInvoice.totalAmountCents per customer) +
this.totalAmountCents ≤ customer.creditLimitCents`). This is the
ADR-031 Risk-3 precondition the aggregation engine cannot enforce at
transition time and is wired into the `draft → issued` transition via
`x-openregister-lifecycle.transitions[draft→issued].guard`. It is NOT a
generic billing/AR service.

The proposal's `Implementation note` ("The single PHP seam is
`lib/Guard/CreditLimitGuard.php`, the ADR-031 Risk-3 cross-object
precondition the aggregation engine cannot enforce at transition time")
is honoured verbatim.

### Aggregations, not report services

- AR aging (REQ-AR-007) is declared as an `x-openregister-aggregations`
  query on `ARInvoice`, NOT as a PHP `ARAgingReportService`. No such
  class is present in `lib/`.
- Aged-receivables export to CSV is wired through the standard manifest
  CSV export hook (`x-openregister-aggregations.export.csv = true`), not
  a custom PHP exporter.

### Dunning consumed from OR, not from app-local table

- `DunningRecord` is the timeline-row register (REQ-AR-005); the
  reminder cadence / template / escalation policy is consumed via
  `x-openregister-lifecycle.requires` from OR's dunning-workflow
  extension (ADR-022).
- No `lib/Service/DunningService.php` or `lib/Cron/DunningEscalationJob.php`
  is authored — escalation runs through OR's `ScheduledWorkflow`
  primitive (ADR-031 path 2). If OR's dunning-workflow extension is not
  yet stable, the spec's ADR-031 exception allows a single-method
  `OCA\Shillinq\Lifecycle\ARGuard` — none is required at T3 build time
  because OR's dunning-workflow extension is consumable per the
  reconciliation reports/AP-core precedent.

### Manifest navigation — declarative

The four navigation entries required by REQ-AR-010 (Customers / Accounts
Receivable / AR Aging / Dunning Log) are present in `src/manifest.json`
(lines 164, 178 and downstream). The associated detail pages
(`CustomerDetail`, `ARInvoiceDetail`, etc.) and the `ARAging` aggregate
are all declared. No PHP controller or app-local routing is needed.

### Seed data — idempotent

`lib/Settings/seeds/ar-demo.json` is present, gated by the `ar_demo_seed`
admin setting, and loaded by `SettingsService::seedArDemo()` from the
repair step. Each seeded object carries a stable `slug` per the
`@self` envelope, so re-running the repair step does NOT create
duplicates (REQ-AR-011 scenario).

### Mirror to AP-core

AR is fully symmetric to AP-core (`Payee` ↔ `CustomerMaster`,
`APTransaction` ↔ `ARInvoice`, `DunningNotice` ↔ `DunningRecord`):

- Same field philosophy (schema.org annotations, integer-cent Money
  per CLAUDE.md tech rule).
- Same lifecycle shape (`draft → issued → paid` with overdue / disputed
  / written-off branches; scheduled-workflow-driven overdue transition;
  reason-required write-off path).
- Same dunning sourcing pattern (OR's dunning-workflow consumed via
  `x-openregister-lifecycle.requires`, no app-local dunning table).
- Same modular fragment pattern under `lib/Settings/register.d/` per
  ADR-037 — the AR schemas live in `shillinq_register.json` (not yet
  fragmented because they predate the modular split; out of scope for
  this T3 closure).

The mirror confirms: the AR build adds **zero** duplicate PHP services
and **zero** duplicate registers. The single PHP seam (`CreditLimitGuard`)
is the documented and AR-unique ADR-031 exception (AP carries no
analogous credit-limit guard because vendor onboarding does not impose a
forward credit ceiling).

## Conclusion

No duplication found between this change and pre-existing shillinq code
or the AP-core mirror. All platform services (`ObjectService`,
`RegisterService`, `SchemaService`, `ConfigurationService`,
`AuditTrailService`, `CnIndexPage`, `CnDetailPage`, `CnDashboardPage`,
`@conduction/nextcloud-vue`) are reused. The only PHP shillinq code added
beyond the declarative envelope is `lib/Guard/CreditLimitGuard.php`,
documented as the REQ-AR-006 / ADR-031-exception cross-object guard.
