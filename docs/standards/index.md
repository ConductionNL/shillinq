---
sidebar_position: 1
title: Overview
description: The accounting, reporting and sustainability standards Shillinq supports, and how the Standards policy resolves conflicts between them.
---

# Accounting & reporting standards

Bookkeeping never happens in a vacuum — every set of books is kept on the basis
of one or more **standards** (the law, a body of authoritative guidance, or a tax
regime). Which standard applies depends on *what* entity you are, *where* it is
based, and *who* reads the accounts. When more than one applies, they can
**conflict** — a lease that goes on the balance sheet under one framework stays
off it under another.

This section is a reference for the frameworks Shillinq supports. Each framework
has its own page with an overview table of its standards (and, for the standards
most relevant to bookkeeping, a deeper table of key requirements), every row
linking to the authoritative source so you can read up on it.

:::note Verified June 2026
Standard titles, numbers and effective dates were verified against the official
sources (ifrs.org, wetten.overheid.nl, rjnet.nl, fasb.org, ipsasb.org, EUR-Lex)
as of June 2026. Standards and their endorsement/transposition status evolve —
always confirm a specific paragraph against the linked authoritative source
before relying on it for a compliance decision. Items still subject to change
(e.g. the EU CSRD/ESRS reform) are flagged on their page.
:::

## Frameworks covered

| Framework | Issued by | Who applies it | Page |
|---|---|---|---|
| **IFRS / IAS** | IASB (IFRS Foundation) | EU-listed groups (consolidated); optional for many others | [IFRS / IAS](./ifrs.md) |
| **Dutch GAAP** — BW2 Title 9 + RJ | Wetgever + Raad voor de Jaarverslaggeving | Dutch legal entities (NV, BV, …) | [Dutch GAAP](./dutch-gaap.md) |
| **Dutch tax** — goed koopmansgebruik | Wetgever (Wet IB/Vpb) + case law | Dutch taxpayers (the *fiscale* jaarrekening) | [Dutch GAAP → tax](./dutch-gaap.md#deep-dive--goed-koopmansgebruik-fiscal-accounting) |
| **US GAAP** — FASB ASC | FASB | US entities reporting under US GAAP | [US GAAP](./us-gaap.md) |
| **IPSAS** | IPSASB (IFAC) | Public-sector entities on accrual accounting | [Public sector](./public-sector.md) |
| **Dutch BBV** | Wetgever + Commissie BBV | Dutch municipalities & provinces | [Public sector](./public-sector.md#dutch-bbv) |
| **ESRS (CSRD)** | EFRAG → European Commission | In-scope EU undertakings (sustainability) | [Sustainability](./sustainability.md) |
| **IFRS S1 / S2** | ISSB (IFRS Foundation) | Investor-focused sustainability reporting | [Sustainability](./sustainability.md#ifrs-sustainability-issb) |

See also the **[cross-framework comparison](./comparisons.md)** — side-by-side
treatment of revenue, leases, inventories and development costs, highlighting
exactly where the frameworks disagree.

## How Shillinq uses this: the Standards policy

A bookkeeping entity is frequently subject to **several** frameworks at once — a
Dutch BV keeps commercial books on BW2/RJ *and* a tax computation on *goed
koopmansgebruik*; an EU-listed group adds IFRS; a municipality adds BBV. Where
two frameworks prescribe a different treatment for the same transaction, the
business logic needs to know **which one wins**.

Shillinq lets an administrator declare this in **Settings → Accounting
standards**:

1. **Enable** the frameworks that apply to the administration.
2. **Rank** them in order of precedence (drag to reorder) — e.g. *IFRS* above
   *Dutch GAAP* for a listed group, or *Dutch GAAP* above *IFRS* for a BV that
   reports on Title 9.

The ranked policy is saved as a `StandardsPolicy` object. Business logic can then
ask the policy, for any topic where frameworks conflict (revenue, leases,
inventory, …), **which enabled framework has the highest precedence** — so the
resolution is a deliberate, auditable configuration choice rather than a hidden
default. See the [comparison page](./comparisons.md) for the conflicts this is
designed to resolve.

> The policy and its precedence ordering are configured today; the per-topic
> resolution is exposed to business logic through a single resolver
> (`StandardsPolicyService.resolve(topic)`) so future posting/valuation logic can
> consult it consistently.
