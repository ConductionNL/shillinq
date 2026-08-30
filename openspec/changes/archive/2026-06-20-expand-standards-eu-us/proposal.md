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
- **Category B — NEW versioned static `ComplianceCatalogue` (code, not OR)**:
  digital-compliance obligations (e-invoicing/ViDA/SAF-T/VAT) are *facts about the
  world*, identical for every tenant and changing only with regulation — so they
  ship as versioned static code (`lib/Standards/ComplianceCatalogue.php`, with a
  `VERSION`/`asOf` stamp), **not** an OpenRegister schema. The catalogue is
  *additive* (meet all that apply), read-only, queried via `applicableTo(country)`
  / `byType()`, and is the machine-readable source for the future
  compliance-rules→specs pipeline. The only per-tenant input (which jurisdictions
  an administration operates in) is derived from existing data, so no new
  per-tenant schema is introduced.
- **Operative rule corpus — NEW versioned static `RuleCatalogue`**: the granular
  "what must I do/check" rules derived from standards and laws (invoicing/EN 16931,
  VAT, retention, ledger integrity, chart of accounts, reporting, and the
  IFRS/US-GAAP/national recognition-measurement-presentation requirements), stored
  as per-domain JSON under `lib/Standards/rules/` and loaded by `RuleCatalogue`.
  Wave 1 ships **700+ sourced rules**; the corpus is the machine-readable source
  for turning rules into validations/specs, and grows in later waves.
- **Behaviour, not navigation (REQ-ASP-006)**: the rule corpus, the compliance
  catalogue and policy resolution are **business logic + reference data** — they
  add **no menu or pages**. The only standards UI is the existing apply/order
  settings screen; any rule/compliance *status* surfacing would be report-only.
- **Docs** (`docs/standards/`): new `eu-national-gaap`, `digital-compliance`,
  `output-formats` and `rule-corpus` pages; `public-sector` gains GASB + FASAB;
  `us-gaap` gains the special-purpose/OCBOA section; the overview reframes the
  catalogue around the three categories.

## Out of scope

- A read-only **compliance-status view** per administration (deriving applicable
  obligations from `ComplianceCatalogue::applicableTo()` + the administration's
  jurisdictions). The catalogue itself is static code, not a user-editable
  surface; the StandardsPolicyEditor remains the only Category-A admin screen.
- IFRS for SMEs — deliberately excluded (no EU member state has adopted it).
- Deep tooling for Category C output formats (documented only).
