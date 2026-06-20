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

## Three kinds of "standard"

A bookkeeping product juggles three different kinds of standard, and they behave
differently — which is why they are modelled separately:

| Category | Behaves like | Examples |
|---|---|---|
| **A. Bases of accounting** | *Competing* — when several apply, **one wins** (the [Standards policy](#how-shillinq-uses-this-the-standards-policy) ranks them) | IFRS, national GAAPs, tax bases, GASB/IPSAS/BBV |
| **B. Compliance obligations** | *Additive* — meet **all** that apply (tracked per jurisdiction, not ranked) | [e-invoicing, ViDA, SAF-T, VAT](./digital-compliance.md) |
| **C. Output / filing formats** | *Layered* on top of a chosen basis | [ESEF/XBRL, SEC, SSARS](./output-formats.md) |

Beneath all three sits the **[rule corpus](./rule-corpus.md)** — ~1,300 granular,
machine-readable rules (invoice content, VAT, retention, recognition, …) that the
bookkeeping engine actually applies. The corpus and the compliance catalogue
**define behaviour and ship as versioned static code — they add no menu or
pages**; the only standards UI is the per-tenant apply/order screen below.

## Frameworks covered

### A — Bases of accounting (the precedence catalogue)

| Framework | Issued by | Who applies it | Page |
|---|---|---|---|
| **IFRS / IAS** (IASB) | IASB | EU-listed groups (consolidated); optional for many others | [IFRS / IAS](./ifrs.md) |
| **IFRS (EU-endorsed)** | European Commission (EFRAG) | the legally binding EU variant (may lag IASB) | [EU national GAAP](./eu-national-gaap.md#eu-endorsed-ifrs) |
| **Dutch GAAP** — BW2 Title 9 + RJ | Wetgever + RvdJ | Dutch legal entities | [Dutch GAAP](./dutch-gaap.md) |
| **German HGB** (+ SKR / DRS) | HGB law + DRSC | German non-listed entities | [EU national GAAP](./eu-national-gaap.md#german-hgb) |
| **French PCG** | ANC | French entities (mandatory chart) | [EU national GAAP](./eu-national-gaap.md#french-pcg) |
| **Italian OIC** / **Spanish PGC** | OIC / ICAC | Italian / Spanish entities | [EU national GAAP](./eu-national-gaap.md#italian-oic) |
| **Dutch tax** — goed koopmansgebruik | Wet IB/Vpb + case law | Dutch taxpayers | [Dutch GAAP → tax](./dutch-gaap.md#deep-dive--goed-koopmansgebruik-fiscal-accounting) |
| **US GAAP** — FASB ASC | FASB | US entities under US GAAP | [US GAAP](./us-gaap.md) |
| **US special-purpose** — tax / cash / modified-cash / FRF for SMEs | IRC / AICPA | US private entities not on full GAAP | [US GAAP → OCBOA](./us-gaap.md#special-purpose-frameworks-ocboa) |
| **IPSAS** | IPSASB (IFAC) | Public-sector accrual reporting | [Public sector](./public-sector.md) |
| **Dutch BBV** | Wetgever + Commissie BBV | Dutch municipalities & provinces | [Public sector](./public-sector.md#dutch-bbv) |
| **US GASB** / **FASAB** | GASB / FASAB | US state-&-local / federal government | [Public sector](./public-sector.md#us-gasb) |
| **ESRS (CSRD)** | EFRAG → EU Commission | In-scope EU undertakings (sustainability) | [Sustainability](./sustainability.md) |
| **IFRS S1 / S2** | ISSB | Investor-focused sustainability | [Sustainability](./sustainability.md#ifrs-sustainability-issb) |

### B — Digital compliance obligations

EN 16931, Peppol, **ViDA** (mandatory intra-EU e-invoicing from 2030), per-country
mandates (IT, PL, ES, FR, DE, BE), **SAF-T**, and EU **VAT** — see
**[Digital compliance](./digital-compliance.md)**.

### C — Output & filing formats

ESEF & US-GAAP XBRL, SEC Reg S-X/S-K, AICPA SSARS — see
**[Output & filing formats](./output-formats.md)**.

See also the **[cross-framework comparison](./comparisons.md)** — where the bases
of accounting disagree (revenue, leases, inventories, development costs).

> **Deliberately excluded:** **IFRS for SMEs**. Despite being the IASB's
> purpose-built private-entity standard, no EU member state has adopted it (it
> conflicts with the Accounting Directive), so for an EU+US product the national
> GAAPs are the correct SME layer. Revisit only if targeting LatAm/Africa.

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
