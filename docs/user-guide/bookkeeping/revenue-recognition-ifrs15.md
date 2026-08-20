---
sidebar_position: 1
title: Revenue recognition under IFRS 15
description: Five-step revenue recognition model in Shillinq — identify the contract, identify performance obligations, determine the transaction price, allocate, recognise. Dutch GAAP (BW2 Title 9) alignment included.
---

# Revenue recognition under IFRS 15

Shillinq implements the **IFRS 15 / ASC 606** five-step revenue recognition
model as a tier-2 compliance + operations capability sitting on top of the
general ledger. Listed companies have applied IFRS 15 since 1 January 2018;
Dutch mid-market businesses publishing IFRS-aligned annual accounts under
**BW2 Title 9** are increasingly expected to do the same, particularly when
preparing for an IPO, supplying enterprise customers, or running SaaS /
project-services / construction-style revenue contracts.

This page is the entry point: it tells the bookkeeper which Shillinq screens
implement which IFRS 15 step, and where to read more.

## The five steps in Shillinq

| Step | IFRS 15 paragraph | Shillinq surface |
|---|---|---|
| 1. Identify the contract | IFRS 15.9-21 | **Bookkeeping → Revenue Recognition (IFRS 15) → Revenue Contracts** (`RevenueContract` register, lifecycle draft → signed → in-delivery → completed → cancelled). |
| 2. Identify performance obligations | IFRS 15.22-30 | **Performance Obligations** (one or more `PerformanceObligation` rows per contract, with satisfaction pattern and method). |
| 3. Determine the transaction price | IFRS 15.46-72 | `TransactionPrice` decomposed into fixed, variable, financing, non-cash, consideration payable to customer. Variable consideration is constrained per IFRS 15.56. |
| 4. Allocate to performance obligations | IFRS 15.73-90 | **Revenue Waterfall** allocation columns — relative SSP (default) or residual method (IFRS 15.79). |
| 5. Recognise revenue as / when obligations are satisfied | IFRS 15.31-45 | `RevenueRecognitionEvent` rows feed the **Revenue Waterfall** and the nightly contract-asset / contract-liability cut-off. |

## Dutch GAAP alignment (BW2 Title 9)

Shillinq's IFRS 15 layer co-exists with the Dutch RJ (Raad voor de
Jaarverslaggeving) bookkeeping rules baked into the
**`bookkeeping-ifrs-rj-dual-gaap`** capability. The same `RevenueContract` and
`PerformanceObligation` entities feed both treatments:

- **IFRS 15 view** — full performance-obligation recognition timing.
- **RJ 270 view** — the Dutch revenue standard (`Opbrengsten`) maps onto the
  same five-step structure for SMEs publishing under Title 9.

Choose the view per administration from the dual-GAAP module. Disclosures
required by IFRS 15.110-129 (contract balance reconciliation, RPO timing,
revenue disaggregation, significant judgements) are exported by the T4
disclosure pack from the same data model.

## Where to read next

- [Contracts and performance obligations](contracts-and-pos.md) — how to lay
  in a contract, add POs, and approve modifications.
- [Revenue waterfall](revenue-waterfall.md) — the 60-month forecast, segment
  filtering, and export.
- [Contract balances](contract-balances.md) — deferred / accrued reconciliation
  and the nightly cut-off log.
- [IFRS 15 disclosure pack](ifrs15-disclosure.md) — structure per IFRS
  15.110-129 with PDF / XBRL / JSON export.
- [Revenue Recognition API](../../api/revenue-recognition.md) — endpoint
  reference for downstream dashboards and audit tools.

## Personas

Four persona narratives live under `docs/journeys/`:

- **CFO** — `cfo-revenue-forecast-accuracy.md`
- **Revenue Accountant** — `revenue-accountant-ifrs15-entry.md`
- **Controller** — `controller-ifrs15-closeout.md`
- **Auditor** — `auditor-revenue-assertion.md`
