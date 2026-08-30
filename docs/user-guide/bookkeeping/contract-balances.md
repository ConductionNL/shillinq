---
sidebar_position: 4
title: Contract balances and the nightly cut-off
description: Inspect the contract asset / contract liability split per IFRS 15.116-119, audit the nightly cut-off run, and recover from a closed-period failure.
---

# Contract balances and the nightly cut-off

Contract balances express the gap between **billed** and **recognised**
revenue per contract per period. Shillinq derives these balances per IFRS
15.116-119:

| Condition | Balance | GL control account |
|---|---|---|
| Recognised > billed | `ContractAsset` (right to consideration) | accrued-revenue |
| Billed > recognised | `ContractLiability` (deferred revenue) | deferred-revenue |

Exactly one of the two is non-zero for a given contract / period.

## Open the dashboard

**Bookkeeping → Revenue Recognition (IFRS 15) → Contract Balances** lists one
row per contract per period:

- `assetAmount`, `accrualAmount` — when recognised > billed.
- `liabilityAmount`, `deferredRevenueAmount` — when billed > recognised.
- `priorPeriodBalance`, `currentPeriodMovement` — for trend analysis.

Filter by customer for receivables-style cash-flow planning.

## The nightly cut-off

Every night a scheduled job runs the IFRS 15.116-119 derivation:

1. Read all `RevenueRecognitionEvent` rows up to the period end.
2. Read the cumulative billed amount per contract from the AR module.
3. Compute the contract asset / contract liability split per contract.
4. REVERSE all prior-period GL lines on the deferred-revenue / accrued-
   revenue control accounts.
5. POST fresh GL lines for the current snapshot.

The reverse-then-post pattern is **idempotent** — re-running on the same
snapshot yields identical rows and identical GL — so a retry after a transient
failure is safe.

## Fiscal-period open check

The cut-off respects the `bookkeeping-period-close` REQ-PC-004 fiscal-period
open flag. If the period is **closed**, the cut-off job:

- still computes the read-only snapshot (so a controller can inspect what
  *would* post),
- but does **not** write GL lines — instead it logs a
  `period_closed_no_post` event and exits zero.

This is the "fails gracefully if period closed" behaviour the integration
test in `Ifrs15RevenueIntegrationTest::testNightlyCutoffFailsGracefullyWhenPeriodClosed`
locks down.

## Inspect the audit trail

Every cut-off run logs:

- run timestamp + operator (system user for the cron job),
- the period end the run covered,
- the count of contracts processed,
- the prior-period and current-period balances per contract,
- the GL line ids reversed and posted.

The log is queryable from the bookkeeping audit-trail dashboard.

## Recover from a failed run

If the cut-off fails mid-run (e.g. the database is briefly offline):

1. The reverse-then-post pattern means there is no half-written state.
2. Re-run the job by triggering `GET /api/revenue/cutoff` with the same
   `period_end` parameter — see the
   [Revenue Recognition API](../../api/revenue-recognition.md).
3. Confirm the audit-trail log shows two runs for the period; the second
   run produced identical rows.

## Where to read next

- [IFRS 15 disclosure pack](ifrs15-disclosure.md) for the IFRS 15.110-129
  contract-balance reconciliation note that consumes these balances.
- The **Controller** persona walkthrough at
  `docs/journeys/controller-ifrs15-closeout.md`.
