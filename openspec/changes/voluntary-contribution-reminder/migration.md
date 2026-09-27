# Migration: voluntary-contribution-reminder

## Current State

`ARInvoice` 0.14.0: `contribution` has kind, voluntary, chargeable, beneficiary,
raiseBatchId and revenueAccount. `lifecycleState` knows draft, issued, paid,
overdue, disputed and written-off.

## Target State

`ARInvoice` 0.15.0: `contribution` also has `language` and `declinedAt`;
`lifecycleState` also knows `declined`, reached by `decline` (from issued) and
`decline-overdue` (from overdue). `isOverdue` is false for a declined invoice;
`arAging` and `creditExposure` leave it out.

## Migration Class

```
None. The change is a register fragment overlay imported by the repair step
(SettingsService::loadConfiguration, version-gated on info.version plus the
fragment signature). No table or column changes.
```

## Migration Steps

1. The repair step reimports the register because the fragment signature changed.
2. OpenRegister adds the two nullable properties, the enum value and the state.

## Data Impact

No record is rewritten. Contribution invoices raised before this change have no
`contribution.language`; the reminder reads that as Dutch. No data loss.

## Rollback Procedure

Revert the fragment. An invoice already `declined` keeps the value; the reverted
code treats it as not payable and not dunnable.

## Validation

`SchoolContributionsFragmentTest` asserts ARInvoice 0.15.0 is the merged version,
the two fields, the enum value, the guarded transitions and the aggregation
filters.
