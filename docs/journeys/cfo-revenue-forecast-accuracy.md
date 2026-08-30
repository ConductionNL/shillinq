<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# Journey: CFO forecasts ARR/MRR from the contract waterfall

**Persona:** CFO of a growth-stage Dutch SaaS company publishing IFRS-aligned
annual accounts (BW2 Title 9), preparing for an IPO.
**Spec:** `bookkeeping-ifrs15-revenue` (REQ-IFRS15-008, REQ-IFRS15-010, REQ-IFRS15-011)

## Goal

Understand how much revenue is already contracted and *when* it will be
recognised, so ARR/MRR forecasts rest on the IFRS 15 remaining-performance-
obligation amount rather than on billing timing.

## Steps

1. Open **Bookkeeping → Revenue Recognition (IFRS 15) → Revenue Waterfall**.
2. The waterfall lists every contract with its allocated transaction price,
   cumulative recognised revenue, period recognised, and **remaining amount**
   (the IFRS 15.120 RPO). For the 36-month SaaS contract C-2026-001 the CFO sees
   EUR 89,143 recognised, EUR 270,857 remaining, and 30 months of forward
   visibility.
3. Filter by **Customer** or **Product** to disaggregate the forecast by segment
   (REQ-IFRS15-008).
4. For contracts negotiated as a package, the **Contract Group** column links the
   combined contracts (IFRS 15.17) so the group-level waterfall is read as one
   commercial arrangement (REQ-IFRS15-011).
5. Read the **Deferred Revenue** and **Accrued Revenue** columns to see the
   contract-asset / contract-liability split feeding the balance sheet.

## Outcome

The CFO derives ARR/MRR from contracted-and-not-yet-recognised revenue, and can
defend the forecast to investors because every figure traces back to a
performance obligation and its recognition events. The on-screen figures match
the `GET /api/revenue-cutoff` output used by any downstream dashboard.
