---
kind: code
---

# Proposal: people-ib47-statement

## Summary

A business that pays people who are not on its payroll, such as
freelancers without a VAT number or volunteers above the allowance, reports
those payments to the Belastingdienst once a year on the IB47 statement.
Shillinq keeps `IB47Record` rows on an `IB47Jaarbatch` page, but they are
typed by hand: no PHP collects the year's payments to third parties into
records, and the report catalogue has no IB47 entry. This change fills the
annual batch from the books and produces the statement file.

## Motivation

Capability row `ppl-ib47` ("Produce the yearly IB47 statement of payments to
third parties") was marked built. The verify round of 12 Nov 2026 (decision
389) found that nothing produces the statement, so the row goes back to
specified with this change as its plan. Filing stays with integriq; its
IB47 connection is log-only today (`lib/Settings/connections.json`).

## What changes

- A payee can be marked as an IB47 recipient.
- Preparing the annual batch sums the year's paid supplier invoices of those
  payees into one `IB47Record` per payee, without duplicates on a rerun.
- The batch produces the IB47 statement file for download.

## Affected projects

- shillinq only.
