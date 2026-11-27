---
kind: code
---

# Proposal: ledger-retention-due-records

## Summary

A bookkeeper keeps financial records for the statutory seven years, then
decides what to destroy. Shillinq seeds the retention rules and shows the
destruction report (the audit trail of what was deleted), but no page lists
the records whose retention period has ended. The Retention periods
dashboard counts rules with fewer than 90 days left, not records. This
change adds the list of records due for destruction.

## Motivation

Capability row `led-retention` ("Keep records for the statutory seven years
and see which ones are due for destruction") was marked built. The closing
round of 12 Nov 2026 (decision 389) found the second half missing: the
dashboard filter `daysUntilRetention_lt: 90` in `src/manifest.json` reads
`RetentionRule` rows, so it counts rules. The row goes back to specified,
with this change as its plan.

## What changes

- Each retention-bound record gets a retention end date from its rule and
  its own reference date (posting date, invoice date or closing date).
- A page under Reporting & compliance lists the records whose end date has
  passed, per register, with the rule and the disposition.
- Records under a legal hold stay off the list.

## Affected projects

- shillinq only. Retention enforcement and the purge itself stay with
  OpenRegister's archival flow (REQ-ARC-001).
