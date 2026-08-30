---
sidebar_position: 5
title: IFRS 15 disclosure pack
description: Structure of the IFRS 15.110-129 disclosure pack — revenue disaggregation, contract balance reconciliation, remaining performance obligations, significant judgements — exportable to PDF, XBRL, and JSON.
---

# IFRS 15 disclosure pack

The IFRS 15 disclosure pack is the regulator-mandated narrative + tables that
accompany the annual accounts under **IFRS 15.110-129**. Shillinq's tier-2
revenue capability declares the disclosure **structure** (fields, sections,
aggregation rules); the actual outbound formatting to PDF / XBRL / JSON ships
in T4 once the report scheduler is in place.

## Structure

The disclosure pack has five sections:

### 1. Revenue disaggregation (IFRS 15.114-115, B87-B89)

A breakdown of revenue by primary geographical market, major product / service
line, and customer / customer-group. Sourced from `RevenueWaterfall` rows
filtered by segment dimensions (`segmentCustomer`, `segmentGeography`,
`segmentProduct`).

### 2. Contract balance reconciliation (IFRS 15.116-118)

Per period:

- opening contract-asset / contract-liability balances,
- additions from new recognition events,
- transfers (recognition reclassifying a liability to revenue, billing
  reclassifying an asset to receivable),
- closing balances,
- impairment recognised on contract assets.

### 3. Remaining performance obligations (IFRS 15.120-122)

The IFRS 15.120 RPO disclosure: the aggregate amount of transaction price
allocated to performance obligations that are unsatisfied (or partially
unsatisfied) as of the period end, with the expected timing.

Shillinq's waterfall provides a 60-month minimum projection; contracts
extending beyond 60 months aggregate into a "60+" tail bucket.

### 4. Significant judgements (IFRS 15.123-126)

A narrative section covering:

- The timing of satisfaction of performance obligations (over time vs. point
  in time).
- The transaction-price determination — variable consideration with
  constraints, significant financing components, non-cash consideration.
- The allocation of the transaction price — method (relative SSP vs.
  residual) and the basis for estimating SSP.

Sourced from `Contract.modificationHistory`, `TransactionPrice.constraintReason`,
and the constraint trail captured on `VariableConsiderationAdjustment`.

### 5. Accounting policies (IFRS 15.119)

Boilerplate revenue-recognition policy text, customisable per administration
to reflect the contract types in scope (SaaS, project services, construction,
licences, telecom bundles, etc.).

## Export (T4)

The disclosure-pack viewer in **Bookkeeping → Revenue Recognition (IFRS 15)
→ Disclosure** lets the controller:

- Toggle sections on/off for the annual-accounts copy.
- Edit the significant-judgement narrative.
- **Export PDF** — formatted per the Dutch annual-accounts template.
- **Export XBRL** — tagged per the IFRS Taxonomy (delivery in T4).
- **Export JSON** — the structured data for downstream auditor-tooling.

The PDF / XBRL exporters live in the T4 disclosure-export module; this T2
capability declares the schema fields and aggregation rules they consume.

## Where to read next

- [Revenue waterfall](revenue-waterfall.md) for the RPO disclosure data.
- [Contract balances](contract-balances.md) for the contract-balance
  reconciliation data.
- The **Auditor** persona walkthrough at
  `docs/journeys/auditor-revenue-assertion.md`.
