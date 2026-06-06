---
title: Trial balance — design
description: How the Tier-2 trial balance is computed from the general ledger, the declarative aggregation shape, the PHP fallback, and the data flow.
---

# Trial balance — architecture

The Tier-2 trial balance (`bookkeeping-trial-balance`, REQ-TB-001..REQ-TB-021)
produces a per-account, per-period view of opening balance, debit/credit
movements, and closing balance, computed live from the general ledger.

## Schema declaration

The capability adds one read-only schema, `TrialBalanceLine`, as an
ADR-037 register fragment at
`lib/Settings/register.d/bookkeeping-trial-balance.json` — the monolith
`shillinq_register.json` is never edited. The fragment is deep-merged at load by
`SettingsService::deepMergeConfig()`, which unions `components.schemas` by key
and concatenates `components.objects[]`, so concurrent change fragments never
collide.

`TrialBalanceLine` carries: `periodId`, `accountNumber`, `accountName`,
`accountType`, `openingBalance`, `debitMovement`, `creditMovement`,
`closingBalance`, `currency`, `parentAccountNumber`, `administrationId`. It is
marked `readonly: true` (and `x-openregister.readonly: true`) — operators never
author rows; the rows are materialised on demand.

The monolith already ships a complementary **snapshot** `TrialBalance` schema
(period totals + `isBalanced`, from `add-shillinq-bookkeeping-operations`,
REQ-FS-005). `TrialBalanceLine` is the per-account breakdown the Tier-2 spec
requires; the two coexist, and the fragment merge leaves the snapshot schema
untouched.

## Declarative aggregation (ADR-031)

`TrialBalanceLine.x-openregister-aggregations.trialBalanceByAccountPeriod`
documents the canonical shape: group `GLLine` by `(periodId, accountNumber)`
within an administration, sum debit and credit movements, join `Account` for
name / type / currency / parent, and derive
`closingBalance = openingBalance + (debitMovement − creditMovement)`.

## PHP computation (the engine-side fallback)

Two parts of the report exceed what the declarative aggregation engine can
currently express: the **prior-period opening-balance carry** (REQ-TB-002) and
the cross-schema **GLLine → GLTransaction → Account** scoping. Per the ADR-031
exception path these are computed in PHP by `TrialBalanceService::compute()`
using only the real OpenRegister `ObjectService` API
(`setRegister()->setSchema()->findAll(['filters' => …])`):

1. Fetch the administration's `Account` chart, keyed by `accountNumber`.
2. Fetch the administration's `GLTransaction` rows for the period to get the
   in-scope transaction ids (administration + period scoping, REQ-TB-017).
3. Fetch `GLLine` rows for the period, keep only non-eliminated lines whose
   parent transaction is in scope, and sum debit/credit per account in integer
   cents.
4. If a prior period is supplied, compute its net per account and use it as the
   opening balance (REQ-TB-002); otherwise opening is zero.
5. Derive `closingBalance` and per-type totals via `TrialBalanceCalculator`.

`TrialBalanceCalculator` holds the side-effect-free arithmetic (cent conversion,
closing formula, opening-from-prior, parent roll-up, balanced check, totals) and
is unit-tested in isolation.

## Performance characteristics

The computation is O(accounts + lines) over two-to-three `findAll` reads scoped
to one administration + period. Typical administrations (10K accounts, 100K GL
lines per period) compute well under the REQ-TB-014 two-second budget. For very
large administrations a materialised view can be added in a later tier without
changing the API contract.

## Data flow

```
GLTransaction ─┐
GLLine ────────┼─▶ TrialBalanceService.compute() ─▶ TrialBalanceController
Account ───────┘        │  (per-account rows + totals)        │ GET /api/trial-balance
                        └─ TrialBalanceCalculator             ▼
                                                       manifest pages
                                                       (TrialBalanceLines)
```

## API contract

`GET /api/trial-balance?period_id=<id>&administration_id=<id>[&prior_period_id=<id>]`
returns HTTP 200 with `{ data: [...], total, totals, isBalanced }`, HTTP 400 on a
missing or malformed identifier (REQ-TB-015), and HTTP 500 (no stack trace) on a
GL fetch failure. The endpoint is `#[NoAdminRequired]`; administration scoping
plus OpenRegister multitenancy prevent cross-administration leakage
(REQ-TB-016/017). There is no write route — trial balance is read-only
(REQ-TB-007).
