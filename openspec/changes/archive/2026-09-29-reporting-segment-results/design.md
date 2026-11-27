# Design: reporting-segment-results

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Aggregations.** `register.d/bookkeeping-cost-centers-dimensions.json` (from line 383) declares on `GLLine` the aggregations `byCostCenter`, `byCostCenterHierarchy`, `byCostObject`, `byProject` and `byAnalyticalDimension`, each `metric: sum`, `field: amount`, `filter: {}`, joined to `AnalyticalDimension` for names.
- **Dashboard.** `src/views/bookkeeping/dimensions/SegmentPnLDashboard.vue` (page `SegmentPnLDashboard` in `src/manifest.d/bookkeeping-cost-centers-dimensions.json:94`) maps a segment to one aggregation (lines 160-167) and calls `/apps/openregister/api/objects/aggregations/<register>/GLLine/<name>` with `filter[administrationId]` (from line 314). It refuses to run without an active administration.
- **Line data.** `GLLine` has `amount` (unsigned), `side` (`debit` or `credit`), `accountNumber` and the dimension codes. It does not know its account's type or whether its transaction is posted. `GLTransaction.state` is `draft`, `posted` or `reversed`, and its `reversed` state is described as "Excluded from balance aggregations". The `administrationId` on `GLLine` comes from the open change `glline-administration-scope`.
- **Calculations.** This repo's `declarative-calc-layer` spec lists the JSON-AST operators OpenRegister evaluates, including `if` and `eq`.

## Goals / Non-Goals

**Goals**
- Per segment: revenue, costs and result, from posted profit and loss lines only, for a period.

**Non-Goals**
- Segment balance sheets, overhead allocation.

## Decisions

### D1. The sign is declared

`GLLine.signedAmount` is an `x-openregister-calculations` field:
`{"if":[{"eq":[{"prop":"side"},{"lit":"credit"}]},{"prop":"amount"},{"-":[{"lit":0},{"prop":"amount"}]}]}`.
Revenue (credit) is positive, costs (debit) negative, so the sum is the
result.

### D2. Account class and inclusion are stamped at posting

A line cannot see its account type or its transaction state through a
calculation, so `lib/Listener/GLLineResultStampListener.php` handles
`ObjectTransitionedEvent` for `GLTransaction`:

- to `posted`: sets on every line `accountClass` (`pnl` when the account's `accountType` is a revenue or expense type, else `balance`) and `countsInResult: true`;
- to `reversed`: sets `countsInResult: false` on its lines and on the lines of the transaction named in its `reversesTransactionId`, so an original and its reversal leave the result together rather than one of them.

### D3. The aggregations filter and split

Each segment aggregation gets `filter: {"accountClass": "pnl",
"countsInResult": true}`, `field: signedAmount`, and a companion
aggregation per segment with an added filter on `side` for revenue and
costs, or a `groupBy` on `side` where the endpoint supports two group keys.
The period comes from the dashboard as `filter[periodId]`.

Alternative considered: a join to `Account` in each aggregation. Rejected:
each aggregation already joins `AnalyticalDimension`, and the posting state
still would not be reachable.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Signed amount | Declarative: `x-openregister-calculations` | A derived field on the line itself. |
| Revenue, costs, result per segment | Declarative: `x-openregister-aggregations` | Sums over lines. |
| Account class and inclusion | Imperative, an object-event listener (ADR-078) | Reads another object's state at the moment it changes. |

## Seed Data

Gemeente Voorbeeld, September 2026, cost centre KP-300 Sociaal Domein:
subsidy income EUR 40,000 credit on 8200, personnel costs EUR 25,000 debit
on 4000, housing EUR 3,000 debit on 4100, and a bank line EUR 40,000 debit
on 1100 (balance sheet, excluded). Expected: revenue 40,000, costs 28,000,
result 12,000. A draft transaction of EUR 5,000 on 4000 for KP-300 must not
count.

## Risks / Trade-offs

- [A second group key is not supported by the aggregation endpoint] → then two aggregations per segment (revenue and costs) are declared, and the result is their sum in the dashboard.
- [Account type changes after posting] → the stamp records the class at posting, which is what the books said then.

## Migration Plan

A repair step stamps `accountClass` and `countsInResult` on lines of
existing posted and reversed transactions, in batches, logging counts.

## Open Questions

None.
