# Revenue Recognition API

**Endpoint:** `GET /index.php/apps/shillinq/api/revenue/cutoff`
**Spec:** [`bookkeeping-ifrs15-revenue`](../../openspec/changes/bookkeeping-ifrs15-revenue/specs/bookkeeping-ifrs15-revenue/spec.md) — REQ-IFRS15-007, REQ-IFRS15-008.
**Service:** `lib/Service/RevenueCutoffService.php` (+ pure-logic
`lib/Service/RevenueRecognitionCalculator.php`).
**Controller:** `lib/Controller/RevenueController.php#cutoff`.

## Purpose

Read-only **IFRS 15.116-119 contract-balance** + **IFRS 15.120 RPO** computation
for a single administration at a given period end. Used by:

- the **Revenue Waterfall** dashboard,
- the **Contract Balances** dashboard,
- the nightly cut-off scheduled-job wrapper that writes the GL reverse-then-post
  entries on `RevenueRecognitionEvent`,
- downstream CFO dashboards.

The endpoint is **read-only**: it does NOT post GL transactions. The GL
materialisation is the responsibility of the scheduled job wrapping the
service (REQ-IFRS15-007) — that wrapper consumes this endpoint's output,
honours the `bookkeeping-period-close` REQ-PC-004 open-period check, and posts
the compensating GL lines.

## Authentication

`#[NoAdminRequired]` controller — any authenticated Nextcloud user may call.
The service is **administration-scoped** per ADR-005: the `administration_id`
query parameter MUST refer to an administration the caller has access to. The
controller resolves the caller from `IUserSession` and authorises the
administration access before dispatching to the service.

## Request

```
GET /index.php/apps/shillinq/api/revenue/cutoff?administration_id=adm-1&period_end=2026-06-30
```

| Query parameter      | Required | Description                                              |
|----------------------|----------|----------------------------------------------------------|
| `administration_id`  | yes      | Administration to scope the cut-off to (ADR-005 IDOR-safety). |
| `period_end`         | yes      | ISO date the cut-off covers (YYYY-MM-DD). Events after this date are excluded. |

## Response

`200 OK`:

```json
{
  "data": [
    {
      "contractId": "C-2026-001",
      "periodEnd": "2026-06-30",
      "transactionPriceAllocated": 360000.0,
      "cumulativeRecognised": 89143.0,
      "periodRecognised": 7143.0,
      "cumulativeBilled": 90000.0,
      "remainingAmount": 270857.0,
      "contractAsset": 0.0,
      "contractLiability": 857.0,
      "administrationId": "adm-1"
    }
  ],
  "total": 1
}
```

The numbers above match the **Example 1** scenario in
`openspec/changes/bookkeeping-ifrs15-revenue/design.md` and the integration test
`Ifrs15RevenueIntegrationTest::testContractGroupCombination` cell totals.

`400 Bad Request` — `administration_id` or `period_end` missing or malformed.
`500 Internal Server Error` — an unexpected fetch failure; the controller
returns no stack trace (operator-safe).

## Contract lifecycle state machine

The `RevenueContract` register declares an `x-openregister-lifecycle` with these
states and transitions:

```
draft  --sign-->        signed
signed --beginDelivery--> in-delivery
in-delivery --complete--> completed
in-delivery --cancel--> cancelled
any    --cancel-->      cancelled  (with operator confirmation)
```

Each transition fires the materialisation on `RevenueRecognitionEvent`
declared in `lib/Settings/register.d/bookkeeping-ifrs15-revenue.json` so the
GL captures the recognition timing.

## PO satisfaction events

For an over-time PO the cumulative recognised revenue is:

```
cumulativeRecognised = SUM(recognisedAmount) WHERE periodEnd <= period_end
                                              AND contractId = ?
                                              AND administrationId = ?
```

Each `RevenueRecognitionEvent` carries an `evidenceReference` pointing at a
timesheet, milestone, delivery note, or sign-off so the auditor can trace the
recognition to a supporting document (REQ-IFRS15-005, REQ-IFRS15-007).

## Variable-consideration re-estimation

A re-estimation creates a `VariableConsiderationAdjustment` row carrying the
prior estimate, new estimate, delta, constraint reason, and operator. The
delta is applied via a compensating GL transaction (debit accrued-revenue /
credit revenue on an estimate increase; reverse on a decrease). The constraint
is applied per IFRS 15.56 — the amount entering the transaction price is
limited to the amount highly probable not to reverse. See
`RevenueRecognitionCalculator::constrainedVariable()` for the deterministic
arithmetic.

## GL posting patterns

The implementing cycle wires the GL writes as follows:

- **Recognition event** (debit accrued-revenue / credit revenue) — fires on
  `RevenueRecognitionEvent` create via the schema-level materialisation.
- **Nightly cut-off** (debit/credit deferred-revenue or accrued-revenue
  control accounts) — runs idempotently, REVERSE then POST.
- **Variable-consideration re-estimation** — compensating GL transaction
  with the delta amount.
- **Contract-cost impairment** — write-down GL transaction reducing the
  carried `ContractCostAsset` amount.

All postings respect the T1 `BalanceGuard` invariant (debits = credits).
