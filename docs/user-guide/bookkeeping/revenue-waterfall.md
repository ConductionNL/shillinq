---
sidebar_position: 3
title: Revenue waterfall
description: Read the IFRS 15.120 remaining-performance-obligation amount as a 60-month waterfall, drill into a single contract, filter by segment, and export.
---

# Revenue waterfall

The revenue waterfall is the single source of truth for **"what revenue will
we recognise when?"**. It implements IFRS 15.120's remaining-performance-
obligation (RPO) disclosure for the bookkeeper and the CFO.

## Open the waterfall

Navigate to **Bookkeeping → Revenue Recognition (IFRS 15) → Revenue
Waterfall**. The index lists one row per contract per period, with columns:

- `contractId`,
- `periodEnd`,
- `transactionPriceAllocated` — total allocated price for the contract,
- `periodRecognised` — revenue recognised in this period,
- `cumulativeRecognised` — recognised so far,
- `remainingAmount` — the IFRS 15.120 RPO,
- `remainingMonths` — months of forward visibility (60-month minimum per
  IFRS 15.120).

## 60-month forecast

For each contract the waterfall projects the remaining transaction price
forward over the contract's remaining months — or 60 months, whichever is
larger. Contracts extending beyond 60 months aggregate the residual into a
"60+" tail bucket.

## Segment filter

Filter the chart by:

- **Customer** — drives customer-concentration analysis.
- **Geography** — feeds the IFRS 8 segment disaggregation.
- **Product / PO description** — drives product-mix analysis.

The segment fields originate on the `RevenueWaterfall` schema; segment
*reporting rules* (which dimensions enter which segment) are T4-deferred.

## Drill into a contract

Click any row to open the contract detail. The detail panel shows:

- Each PO with allocated price and cumulative recognised,
- The recognition timeline (one row per `RevenueRecognitionEvent`),
- The contract-modification history,
- The contract-asset / contract-liability split feeding the balance sheet.

## Export

From the waterfall index, *Export*:

- **CSV** — the on-screen rows for spreadsheet analysis.
- **PDF / XBRL** — these flow through the T4 disclosure pack
  (see [IFRS 15 disclosure](ifrs15-disclosure.md)).

## Tie the waterfall to the API

The on-screen totals come from the same data model exposed at
`GET /api/revenue/cutoff?administration_id=...&period_end=YYYY-MM-DD`.
See the [Revenue Recognition API](../../api/revenue-recognition.md) for the
exact response shape downstream dashboards consume.
