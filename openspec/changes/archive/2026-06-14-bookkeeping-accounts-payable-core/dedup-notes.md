# Dedup Notes — bookkeeping-accounts-payable-core

**Date:** 2026-06-09
**Author:** opsx-ff (solo build)
**Per:** Task 1 in `tasks.md`, ADR-031 anti-pattern enumeration

## Scan Methodology

Searched the worktree for the canonical AP names declared by REQ-AP-001 /
REQ-AP-002 / REQ-AP-003 / REQ-AP-005 of this spec, plus their alternate
spellings, plus the `lib/Db/` mapper anti-pattern listed in REQ-AP-001's
"Reviewer confirms no parallel AP table" scenario.

```bash
# canonical T2 names
grep -rE 'class (APTransaction|Payee|DunningNotice)' lib/
grep -nE '"(Payee|APTransaction|DunningNotice)"' lib/Settings/shillinq_register.json
grep -nE '"(Payee|APTransaction|DunningNotice)"' lib/Settings/register.d/*.json

# anti-patterns per ADR-031
find lib/Db -iname '*Payee*' -o -iname '*APTransaction*' -o -iname '*Dunning*'
find lib -iname 'AP*Service.php' -o -iname 'Dunning*Service.php'

# alternate pre-T2 names already in baseline
grep -nE '"(VendorMaster|APInvoice|PaymentRun|DunningRecord)"' lib/Settings/shillinq_register.json
```

## Findings

### Canonical T2 names — none present

- **No** schema named `Payee`, `APTransaction`, or `DunningNotice` exists in
  `lib/Settings/shillinq_register.json` or in any
  `lib/Settings/register.d/*.json` fragment.
- **No** PHP class named `APTransaction`, `Payee`, `DunningNotice`, or
  `AccountsPayable*` exists under `lib/`.
- **No** `lib/Db/` Mapper class names `ap_transaction`, `payee`, `dunning_*`,
  or `accounts_payable_*` — the REQ-AP-001 "Reviewer confirms no parallel AP
  table" scenario is satisfied for the canonical names.

### Pre-T2 AP flavour (intentionally untouched)

Pre-existing baseline carries an alternate AP shape from
`add-shillinq-bookkeeping-compliance`:

- `VendorMaster` (line 11262 of `shillinq_register.json`) — vendor party
- `APInvoice` (line 11452) — sub-ledger invoice
- `PaymentRun` (declared by `bookkeeping-purchase-order-3way` family)

This baseline is the historical AP flavour and is **kept untouched** in
this change. The T2 canonical names (`Payee`, `APTransaction`,
`DunningNotice`) are added **alongside** via a new
`lib/Settings/register.d/bookkeeping-accounts-payable-core.json` fragment
per ADR-037. The implementing cycle's migration plan (see `design.md`
"Migration Plan") will reconcile the two flavours; this spec change does
not require deleting the pre-T2 schemas.

This is documented as deliberate overlap per the spec author's intent:
the canonical T2 names mirror the AR side (`CustomerMaster` /
`ARInvoice` / `DunningRecord`) and the legacy AP names will be deprecated
when the implementing cycle migrates fixtures.

### Existing dunning machinery — different concept

`lib/Controller/DunningController.php`, `lib/Service/DunningRunService.php`,
`lib/Lifecycle/DunningRunExecuteGuard.php`, and
`lib/Service/Dunning/*` belong to the `bookkeeping-credit-control-dunning`
capability (REQ-CCD-002 .. REQ-CCD-010). They implement a **dunning ladder
run orchestrator** — a periodic batch that walks AR receivables and emits
escalation notices for a *credit-control ladder*.

The `DunningNotice` register declared here is a **per-AP-invoice timeline
record** (REQ-AP-005): one row per reminder dispatched against one
APTransaction. The two concepts share the word "dunning" but operate on
different ledger sides (AR ladder vs. AP per-invoice timeline) and via
different mechanisms (PHP orchestrator with ADR-031 exception vs. OR
dunning-workflow consumption via `x-openregister-lifecycle.requires`).

No collision; no overlap to migrate.

### AR mirror — no overlap

`add-shillinq-accounts-receivable-core` (sibling change) declares
`CustomerMaster` + `ARInvoice` + `DunningRecord`. Distinct register names
and distinct ledger side. The two specs deliberately mirror each other
(see this spec's REQ-AP-001 and the AR spec's REQ-AR-001) without
overlapping.

## Conclusion

REQ-AP-001's deduplication scenario passes: no parallel AP table for the
canonical T2 names. The implementation lands additively per ADR-037
without touching the pre-T2 baseline. Architecture reviewer is asked to
confirm in Task 20 that the dual-flavour interim state is acceptable for
T2.
