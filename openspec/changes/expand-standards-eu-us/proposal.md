---
kind: code
depends_on:
  - add-accounting-standards-policy
---

# Change: expand-standards-eu-us

## Why

`add-accounting-standards-policy` shipped a standards catalogue scoped to IFRS +
Dutch + US. For a product targeting the **EU + US market** that set has clear
gaps, surfaced by a verified June-2026 gap analysis:

- **EU**: IFRS is mandatory only for listed consolidated accounts — the other
  ~99% of EU entities report under **national GAAPs** (German HGB, French PCG,
  Italian OIC, Spanish PGC) derived from the **Accounting Directive 2013/34/EU**,
  and "IFRS" should distinguish the **EU-endorsed** variant from IASB IFRS.
- **US**: we ship IPSAS + Dutch BBV but not the US public-sector peer **GASB**
  (nor **FASAB**), and US SMBs commonly keep books on a **special-purpose basis**
  (income-tax / cash / modified-cash, FRF for SMEs) rather than full US GAAP.
- **Cross-cutting**: modern bookkeeping compliance includes **digital
  obligations** (e-invoicing EN 16931 / Peppol / **ViDA**, per-country mandates,
  **SAF-T**, **VAT**) — which are *additive*, not a ranked basis-of-accounting
  choice — and **output formats** (ESEF/XBRL, SEC, SSARS) layered on a basis.

The investigation also clarified that a single "precedence policy" mis-models the
domain: there are **three** kinds of standard (A bases of accounting — competing;
B compliance obligations — additive; C output formats — layered).

## What Changes

- **Category A — extend `StandardsPolicy`**: add framework keys `ifrs-eu`,
  `de-hgb`, `fr-pcg`, `it-oic`, `es-pgc`, `us-tax-basis`, `us-cash-basis`,
  `us-modified-cash`, `us-frf-smes`, `us-gasb`, `us-fasab` (enum + the editor's
  catalogue). The resolver is unchanged — more bases, same ranking.
- **Category B — NEW `ComplianceObligation` schema**: an *additive*,
  jurisdiction-keyed model (`jurisdiction`, `type`, `standard`, `status`,
  `effectiveDate`) for e-invoicing/ViDA/SAF-T/VAT. Deliberately **not** ranked and
  **not** wired to the precedence resolver.
- **Docs** (`docs/standards/`): new `eu-national-gaap`, `digital-compliance` and
  `output-formats` pages; `public-sector` gains GASB + FASAB; `us-gaap` gains the
  special-purpose/OCBOA section; the overview reframes the catalogue around the
  three categories.

## Out of scope

- An admin UI for `ComplianceObligation` (the StandardsPolicyEditor covers
  Category A; a Category-B surface is a follow-up).
- IFRS for SMEs — deliberately excluded (no EU member state has adopted it).
- Deep tooling for Category C output formats (documented only).
