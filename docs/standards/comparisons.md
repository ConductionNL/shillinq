---
sidebar_position: 11
title: Cross-framework comparison
description: Where IFRS, US GAAP, Dutch GAAP (RJ) and Dutch tax disagree — revenue, leases, inventories and development costs — the conflicts the Standards policy resolves.
---

# Cross-framework comparison

These tables flag where the major financial-reporting frameworks **conflict** for
the same transaction. When an administration is subject to more than one, the
[Standards policy](./index.md#how-shillinq-uses-this-the-standards-policy) decides
which framework wins, per the precedence order you set.

Legend: **IFRS** = [IASB standards](./ifrs.md); **US GAAP** =
[FASB ASC](./us-gaap.md); **RJ** = [Dutch GAAP guidelines](./dutch-gaap.md);
**tax** = *goed koopmansgebruik* under the Wet IB/Vpb.

:::note
Comparison rows are professional summaries for orientation; confirm the specific
treatment against the linked standard before relying on it for a posting or
valuation decision.
:::

## Revenue recognition

| Framework | Treatment | Key ref |
|---|---|---|
| IFRS | Single 5-step model; recognise revenue as control of goods/services transfers to the customer. | [IFRS 15](./ifrs.md#key-requirements--ifrs-15-revenue-from-contracts-with-customers) |
| US GAAP | Substantively converged with IFRS 15 (joint FASB/IASB project); same 5-step model. | [ASC 606](./us-gaap.md#deep-dive--asc-606-revenue-from-contracts-with-customers) |
| Dutch GAAP (RJ) | Broadly similar; risks-and-rewards / realisable-and-earned recognition. RJ 270 aligns conceptually with IFRS 15 but is less prescriptive (IFRS 15 may be elected in full). | [RJ 270](./dutch-gaap.md#deep-dive--rj-270-opbrengstverantwoording-revenue) |
| Dutch tax | Driven by *realisatiebeginsel* + prudence; profit taken when realised/practically certain — timing can differ from commercial accounts. | [goed koopmansgebruik](./dutch-gaap.md#deep-dive--goed-koopmansgebruik-fiscal-accounting) |

> **Conflict:** IFRS and US GAAP are converged; RJ is broadly aligned. The real
> divergence is **tax** — *goed koopmansgebruik* sets its own realisation/prudence
> timing, so commercial revenue ≠ taxable revenue.

## Leases

| Framework | Treatment | Key ref |
|---|---|---|
| IFRS | **Single lessee model**: nearly all leases on-balance-sheet as an ROU asset + lease liability (limited short-term/low-value exemptions). | [IFRS 16](./ifrs.md#key-requirements--ifrs-16-leases) |
| US GAAP | **Dual model**: finance vs operating classification for lessees; both on balance sheet, but operating leases keep straight-line P&L expense. | [ASC 842](./us-gaap.md#deep-dive--asc-842-leases) |
| Dutch GAAP (RJ) | Classic **operational vs financial** lease split; only financial leases are capitalised, operating leases stay off-balance-sheet. | RJ 292 |
| Dutch tax | Follows economic ownership; classification determines capitalisation and who depreciates — generally tracks the legacy split, not IFRS 16. | goed koopmansgebruik |

> **Conflict (classic):** IFRS 16 puts (almost) all leases on balance sheet; US
> GAAP (ASC 842) and RJ 292 keep the operating/financial distinction with
> operating leases largely off-balance-sheet — materially different gross assets,
> liabilities and EBITDA.

## Inventories

| Framework | Treatment | Key ref |
|---|---|---|
| IFRS | Lower of cost and NRV; FIFO or weighted-average. **LIFO prohibited.** | [IAS 2](./ifrs.md#key-requirements--ias-2-inventories) |
| US GAAP | Lower of cost and NRV (or market for LIFO/retail); FIFO, weighted-average **and LIFO permitted.** | [ASC 330](./us-gaap.md) |
| Dutch GAAP (RJ) | Lower of cost and NRV; FIFO/weighted-average standard. LIFO effectively not accepted under RJ practice. | [RJ 220](./dutch-gaap.md#deep-dive--rj-220-voorraden-inventories) |
| Dutch tax | Cost or lower market value; *ijzeren-voorraadstelsel* / lifo-stelsel historically permitted for tax, subject to conditions. | goed koopmansgebruik |

> **Conflict:** **LIFO.** US GAAP permits LIFO; IFRS (IAS 2) and RJ prohibit/reject
> it. Dutch *tax* has historically allowed LIFO-like stock systems, so book vs tax
> inventory valuation can diverge.

## Development costs

| Framework | Treatment | Key ref |
|---|---|---|
| IFRS | Research expensed; **development costs capitalised** when the six IAS 38 criteria are met. | IAS 38 |
| US GAAP | R&D **generally expensed as incurred** (narrow exceptions, e.g. internal-use & capitalisable software). | ASC 730; ASC 350-40 / 985-20 |
| Dutch GAAP (RJ) | Development costs **may be capitalised** when IAS 38-style criteria are met (a legal reserve is required for capitalised amounts). | RJ 210 |
| Dutch tax | Capitalisation/expensing follows good-business-practice; immediate expensing is often permitted. | goed koopmansgebruik |

> **Conflict (classic):** IFRS (and RJ) *capitalise* qualifying development costs;
> US GAAP *expenses* most R&D immediately. Net assets and the timing of expense
> recognition differ significantly.

## How the Standards policy resolves these

For each topic above, business logic can ask the policy resolver which **enabled**
framework has the **highest precedence**, and apply that framework's treatment:

```text
StandardsPolicyService.resolve("leases")
  → with policy [IFRS (1), Dutch GAAP (2)]  ⇒ "ifrs"      (single on-balance model)
  → with policy [Dutch GAAP (1), IFRS (2)]  ⇒ "dutch-gaap" (operating/financial split)
```

Configure the policy in **Settings → Accounting standards**. See the
[overview](./index.md#how-shillinq-uses-this-the-standards-policy).
