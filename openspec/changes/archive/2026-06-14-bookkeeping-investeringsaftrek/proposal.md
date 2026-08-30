# Proposal: bookkeeping-investeringsaftrek

`kind: spec` per ADR-032 — the centre of mass is six new entities
(`InvestmentAsset`, `EnergielijstCode`, `MilieulijstCode`,
`InvesteringsaftrekClaim`, `VamilDepreciation`, `KIATier`) + RVO
aanvraag lifecycle + deadline monitoring + desinvesteringsbijtelling
tracking + ex-ante calculator for acquisition planning. Declarative
aftrek calculations + year-versioned tarieven seed + RvO openconnector
integration for aanvraag & mededeling feeds. Conditional ~50-LOC PHP
service for KIA tier-lookup only if calculation engine is limited
per ADR-031.

## Summary

Introduce the **investeringsaftrek (KIA / EIA / MIA / Vamil)**
capability for Shillinq as one slice of the Tier 4-specialized MKB
rollout per `adr-001-bookkeeping-tier-roadmap.md`. The four
investeringsaftrek schemes allow Dutch entrepreneurs and companies to
deduct **extra** amounts from taxable income (winst uit onderneming /
Vpb-grondslag) on top of normal depreciation. KIA (kleinschaligheid)
uses a tiered flat-rate based on annual investment total; EIA (energie)
claims 40% on Energielijst-codes; MIA (milieu) claims 27/36/45% per
Milieulijst category; Vamil (vrije afschrijving) allows 75% direct
depreciation in year of ingebruikname.

This spec introduces six new entities, declares the eligibility
classification logic at asset capitalisation, generates the RVO digital
aanvraag within the 3-month deadline, tracks 5-year disposal windows
for desinvesteringsbijtelling reversals, rolls totals into the
Vpb-aangifte, and provides an ex-ante calculator for acquisition
go/no-go decisions. This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and OpenAPI 3.0 register format.

**Depends on:**
- [`bookkeeping-fixed-assets-depreciation`](../../specs/bookkeeping-fixed-assets-depreciation/spec.md)
  — the T4 FixedAsset register the InvestmentAsset overlays.
- [`bookkeeping-vpb-corporate-tax`](../../specs/bookkeeping-vpb-corporate-tax/spec.md)
  — consumes the Bijlage Investeringsaftrek report for Vpb-aangifte assembly.

## Priority & Demand

- **Priority:** P1-high (fiscal impact: EUR 30k–80k annually for typical
  MKB with green investments; missing RVO 3-month deadline forfeits
  entire aftrek).
- **Demand evidence:** 100% of Dutch MKB with capital expenditure
  (Wet IB 2001 art. 3.40–3.47); current market gap — no competitor
  offers KIA tier-calculation + 5-year disposal tracking + RVO deadline
  automation.
- **RVO compliance:** Mandatory RVO aanvraag within 3 months of
  opdrachtverlening (art. 3.42 Uitvoeringsregeling); RVO Energielijst
  + Milieulijst updated 1 januari yearly; Vpb-aangifte integration
  requires aftrek-totals reporting.

## Motivation

Without dedicated investeringsaftrek primitives, entrepreneurs resort to
spreadsheets or separate tax software. Misclassification (asset on
Milieulijst but claimed as EIA-only; or vice versa) is frequent — when
an RVO rejection arrives (often after the 3-month window), the entire
aftrek is lost and cannot be recovered. The second-biggest error: missing
the 3-month RVO meldingstermijn entirely. Per Wet IB 2001 art. 3.42 lid 4
and art. 3.47, early disposal (within 5 jaar na aanvang kalenderjaar)
triggers a desinvesteringsbijtelling (reversal of the aftrek) — yet most
entrepreneurs are unaware and never post the required correction.

This spec automates all four: (a) eligibility classification at
capitalisation (with boekhouder override), (b) RVO aanvraag generation &
deadline tracking (with 14- and 3-day reminder emails), (c) KIA tier
lookup (with marginal-effect transparency), and (d) 5-year disposal
watch (auto-posting desinvesteringsbijtelling on fixed-asset disposal
event).

A typical MKB-onderneming with EUR 200k annual turnover in green
investments (heat pump, e-van, LED, solar) leaves EUR 30k–80k fiscal
aftrek on the table if meldingen are late or miscategorised. The
operational pain is acute: the boekhouder must manually track multiple
schemes, validate against annual RVO lists, and watch disposal dates
across years. This spec removes the manual burden entirely.

## Competitor Evidence

From market intelligence (Feb 2026):

- Moneybird :: Project-level asset tracking; no EIA/MIA/KIA
  classification; no RVO aanvraag generation; no aftrek reporting.
- Yuki :: Fixed asset depreciation only; no investeringsaftrek schemes;
  no RVO integration.
- Exact Online :: KIA tier-lookup only; no EIA/MIA/Vamil; no 5-year
  disposal tracking; no deadline monitoring.

**Unique to Shillinq:** integrated eligibility + RVO deadline + KIA
tier-effect transparency + 5-year disposal watch + ex-ante calculator.

## Affected Projects

- [x] Project: shillinq — adds 6 new entities (`InvestmentAsset`,
  `EnergielijstCode`, `MilieulijstCode`, `InvesteringsaftrekClaim`,
  `VamilDepreciation`, `KIATier`); declares aftrek calculation logic;
  ships `lib/Settings/seeds/investeringsaftrek-*-2026.json` (Energielijst,
  Milieulijst, KIA-tiers); registers RvO aanvraagdossier docudesk
  template; registers 2 RvO openconnector sources (aanvraag + mededeling);
  adds manifest navigation entries; implements ex-ante calculator UI.
- [ ] Project: openregister — no source changes (conditional ~50-LOC PHP
  KiaSchalenLookup guard for tier lookup if engine-limited per ADR-031).
- [ ] Project: docudesk — registers RvO aanvraagdossier template.
- [ ] Project: openconnector — registers RvO aanvraag + mededeling
  sources.

## Scope

### In Scope

- One new capability spec (`bookkeeping-investeringsaftrek`) — see
  `specs/` folder.
- Six new entities in ADR-000 data model:
  - `InvestmentAsset` — capitalised asset eligible for one or more
    schemes; 1-to-1 with `FixedAsset`.
  - `EnergielijstCode` — RvO Energielijst 2026 (~170 codes); updated
    yearly.
  - `MilieulijstCode` — RvO Milieulijst 2026 (~250 codes); updated
    yearly.
  - `InvesteringsaftrekClaim` — one row per (asset, scheme, boekjaar)
    combination.
  - `VamilDepreciation` — willekeurige afschrijving schedule (75% direct
    + 25% regular depreciation).
  - `KIATier` — 2026 tiered lookup table (art. 3.41 Wet IB 2001,
    geïndexeerd).
- Eligibility classification at asset capitalisation: KIA (EUR 450–392k
  per-asset, no exclusions), EIA (Energielijst-code + EUR 2.5k
  per-asset), MIA (Milieulijst-code + EUR 2.5k per-asset), Vamil
  (Milieulijst + `vamilToegestaan: true` + EUR 2.5k per-asset).
- Cumulation matrix: KIA stacks with EIA/MIA/Vamil; EIA + MIA forbidden
  (art. 3.42 lid 7); EIA + Vamil forbidden.
- KIA tier calculation at boekjaar level (running total + 2026 tier
  lookup).
- RvO aanvraag generation (EIA/MIA/Vamil only; KIA requires no aanvraag)
  with 3-month deadline tracking + 14- and 3-day reminder emails.
- RvO beschikking asynchronous population from mededeling feed
  (openconnector).
- Vamil depreciation schedule modification (Afhankelijk van regelgeving:
  75% direct afschrijfbaar in year of ingebruikname, 25% via regular
  depreciation).
- 5-year disposal watch: on fixed-asset disposal event, auto-compute
  desinvesteringsbijtelling (aftrek % × min(opbrengst, aanschafwaarde))
  and post draft journal entry to account 8120.
- Jaaraangifte Bijlage Investeringsaftrek report (grouping by scheme,
  showing KIA/EIA/MIA totals and Vamil effect).
- Vrijwillige verlaging support (manual aftrek reduction with rationale).
- Ex-ante calculator: "what-if" mode for acquisition decisions
  (omschrijving + geschatte waarde → auto-lookup Energielijst/Milieulijst
  codes → scenario comparison: regular depreciation vs. EIA/MIA vs.
  MIA+Vamil).
- Audit trail & RvO correspondentie-archief (immutable logging of claims,
  meldingen, beschikkingen, bezwaren).

### Out of Scope

- **Implementation code** — spec-only change.
- **Fixed-asset depreciation itself** — owned by T4-base
  `bookkeeping-fixed-assets-depreciation`.
- **Vpb-aangifte assembly itself** — owned by T4-base
  `bookkeeping-vpb-corporate-tax`.
- **RvO portal API upload** — manual upload / review loop only; future
  enhancement.
- **R&D subsidies (WBSO, RDA)** — out of scope; lives in
  `bookkeeping-r-d-subsidies-mkb`.
- **Energy/environmental *grants* (ISDE, SDE++)** — out of scope; lives
  in grant-subsidy-management.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-investeringsaftrek`). Each requirement is prefixed
`REQ-INV-*`. RFC 2119 keywords; `#### Scenario:` with GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `openspec/architecture/adr-000-data-model.md` — adds 6 new entities
  with full property definitions and relations.
- `lib/Settings/shillinq_register.json` — declares the 6 entities;
  declares aftrek calculations on `InvestmentAsset` + `InvesteringsaftrekClaim`;
  declares lifecycle for RvO aanvraag tracking.
- `lib/Settings/seeds/investeringsaftrek-energielijst-2026.json` —
  ~170 records, SPDX in docblock, `_meta` block with source + year.
- `lib/Settings/seeds/investeringsaftrek-milieulijst-2026.json` —
  ~250 records, SPDX in docblock, `_meta` block with source + year.
- `lib/Settings/seeds/investeringsaftrek-kia-tiers-2026.json` — 5 tier
  records (the 2026 Wet IB 2001 art. 3.41 tiers, geïndexeerd), SPDX,
  `_meta`.
- `src/manifest.json` — adds 4 navigation entries (InvestmentAssets,
  RvO Aanvragen, Desinvesteringsbijtelling Watch, Ex-ante Calculator)
  behind `featureFlags.mkb-investeringsaftrek`.
- `lib/Settings/docudesk-templates.json` — registers RvO aanvraagdossier
  template.
- `lib/Settings/openconnector-sources.json` — 2 RvO source rows (aanvraag
  submission, mededeling feed).
- Conditional ~50-LOC PHP `KiaSchalenLookup` service if OR's calculation
  engine cannot express threshold/rampup/maximum (per ADR-031 exception).

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-calculations` for aftrek
  formulas. If engine cannot express KIA tier-lookup, single-method
  `KiaSchalenLookup` service + documented exception path.
- **docudesk** — aanvraagdossier template registration.
- **openconnector** — RvO aanvraag + mededeling sources per ADR-019.
- **fixed-assets-depreciation** — `InvestmentAsset` 1-to-1 FK to
  `FixedAsset`; Vamil depreciation schedule modifies the FixedAsset
  schedule.
- **vpb-corporate-tax** — consumes Bijlage Investeringsaftrek report for
  Vpb-aangifte integration.
- **general-ledger** — posts desinvesteringsbijtelling journal entries
  to account 8120.

## Risks

### Risk 1: RvO Energielijst / Milieulijst codes revise yearly

**Severity**: Low
**Mitigation**: Seed filenames version-pinned (`*-2026.json` → `-2027.json`);
spec references regulation, not hardcoded values. Operator switches active
seed per fiscal year via administration settings.

### Risk 2: KIA-tiers lookup engine-limited

**Severity**: Low
**Mitigation**: Single-method ~50-LOC `KiaSchalenLookup` per ADR-031
exception path; documented in the implementing cycle's design doc.

### Risk 3: RvO beschikking async population may lag

**Severity**: Medium
**Mitigation**: Claim status tracks two states: `ingediend` (pending RvO
response) and `definitief` (post-award). Boekhouder can override manually
if mededeling arrives out-of-band.

### Risk 4: 5-year disposal watch clock ambiguity

**Severity**: Low
**Mitigation**: Art. 3.47 Wet IB 2001 specifies "5 jaar na aanvang
kalenderjaar van investering" — clock starts 1 januari of the year
in which the asset was acquired, not the opdrachtverleningDatum.
Spec clarifies this; system computes the disposal-watch expiry date
unambiguously.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder. Post-implementation rollback follows the standard additive-register
pattern: registers remain (no destructive changes); RvO claims are
immutable audit records.

## Open Questions

1. **Cumulative KIA + EIA / MIA / Vamil rule clarity** — RvO permits some
   combinations cumulatively; per REQ-INV-004 the system allows multiple
   claims per asset. Confirm with RvO reviewer persona (Annemarie) which
   combinations are valid per 2026 schalen before implementation.

2. **Desinvesteringsbijtelling timing** — art. 3.47 specifies the bijtelling
   amount but not the fiscal year for posting (disposal year vs. filing
   year). Confirm with Annemarie whether desinvesteringsbijtelling posts
   to disposal year or to filing year; typically disposal year per tax
   convention.

3. **Vamil-and-MIA interaction on early disposal** — Vamil allows 75%
   direct depreciation; if asset is disposed before the 25% gespreid-deel
   is exhausted, does terugneming apply to the full 75% or only the
   unexhausted portion? Confirm with Annemarie (likely the unexhausted
   portion only, per Vamil jurisprudentie).

4. **Vrijwillige verlaging carry-forward** — art. 3.42a lid 4 permits
   reduction in current year, but reduced amount is NOT carry-forwardable.
   Confirm that system forbids post-filing amendments to previously reduced
   claims.
