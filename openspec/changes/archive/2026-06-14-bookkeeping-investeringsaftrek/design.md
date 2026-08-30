# Design — Investeringsaftrek (KIA / EIA / MIA / Vamil)

## Context

Dutch tax law (Wet IB 2001, art. 3.40–3.45) allows entrepreneurs and
companies to deduct **extra** amounts from taxable income on top of normal
depreciation. Four schemes exist: KIA (kleinschaligheid), EIA (energie),
MIA (milieu), Vamil (willekeurige afschrijving). Each has thresholds,
percentage rates, and cumulation rules. The operational complexity is
high: eligibility depends on asset codes from annually-updated RvO lists,
thresholds trigger tiered calculations, and a missed 3-month RvO
meldingstermijn forfeits the entire aftrek. Disposing of an asset within
5 years triggers a mandatory tax reversal (desinvesteringsbijtelling).

This spec introduces six new entities to model the schemes end-to-end:
eligibility classification at capitalisation, claim calculation at
boekjaar-end, RvO aanvraag lifecycle with deadline tracking, description
of disposal-window monitoring, and integration with the Vpb-aangifte
assembly. The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline.

## Goals

- Express the entire investeringsaftrek surface as **declarative metadata**
  — entities + calculations + lifecycle — per ADR-031. No PHP aftrek
  calculation service; all formulas live in schema + aggregations.
- Model the **four schemes as a cumulation matrix**: classify at
  capitalisation; detect which schemes apply; forbid illegal combinations
  (EIA + MIA); allow stacks (KIA + EIA, KIA + MIA, KIA + MIA + Vamil).
- **Automate the RvO meldingstermijn** (3 months from opdrachtverlening).
  Capture `opdrachtverleningDatum` as mandatory; compute deadline;
  surface in deadline-monitoring widget; send 14- and 3-day reminder
  emails; block submission if deadline passed.
- Implement **KIA tier-lookup at boekjaar level** with marginal-effect
  transparency (show what the current asset contributes to the tier
  calculation).
- **Track 5-year disposal windows** per art. 3.47 Wet IB 2001. On
  fixed-asset disposal, auto-compute desinvesteringsbijtelling and post
  a draft GL entry; boekhouder reviews before finalising.
- Declare the **RvO Energielijst + Milieulijst as versioned seed data**
  (2026, 2027, etc.) so operators can switch active lists per fiscal
  year; no hardcoded enumerations.
- Integrate with **Vpb-aangifte** assembly: produce Bijlage Investeringsaftrek
  report with KIA/EIA/MIA/Vamil totals, exportable as PDF + XBRL fragments.

## Non-Goals

- No PHP aftrek calculation service — all formulas in schema + aggregations
  or single-method `KiaSchalenLookup` guard (per ADR-031 exception).
- No RvO portal API upload — manual upload or future enhancement only.
- No multi-entity consolidation of aftrek totals — single administration
  only (T5 may extend).
- No AI-driven asset code suggestion — manual classification by boekhouder
  or code search only.

## Decisions

### D1 — Six new entities (not overlays on FixedAsset)

`InvestmentAsset`, `EnergielijstCode`, `MilieulijstCode`,
`InvesteringsaftrekClaim`, `VamilDepreciation`, `KIATier` are full
entities in ADR-000. `InvestmentAsset` has a 1-to-1 FK to `FixedAsset`;
the others are reference data or derived records. This keeps the schema
clean and allows independent versioning of RvO lists.

### D2 — Eligibility classification at capitalisation is a checklist, not autocorrect

When an asset is created/modified, the system displays a checklist:
"KIA: eligible (in tier 2 at current total of EUR 65k), EIA: code 251701
matches, MIA: no code match, Vamil: not applicable." The boekhouder can
**override** any classification and record a rationale (e.g., "not claiming
EIA despite code match; asset is leased, not owned"). This prevents
silent miscalculation and is auditable.

### D3 — Cumulation matrix is strict and declarative

The system declares the legal combination matrix per art. 3.42 Wet IB 2001:

```
KIA + EIA: allowed
KIA + MIA: allowed
KIA + Vamil: allowed (Vamil is depreciation method, not aftrek)
EIA + MIA: FORBIDDEN (art. 3.42 lid 7, samenloop verboden)
EIA + Vamil: FORBIDDEN (Vamil only on Milieulijst)
MIA + Vamil: allowed (common combination)
KIA + EIA + Vamil: FORBIDDEN (via EIA + Vamil rule)
KIA + MIA + Vamil: allowed (triple stack)
```

The system enforces this: if boekhouder tries to claim both EIA and MIA
on the same asset, the UI refuses and shows the rule.

### D4 — RvO meldingstermijn is deadline-critical

`opdrachtverleningDatum` is mandatory for EIA/MIA/Vamil assets.
`rvoMeldingDeadline = opdrachtverleningDatum + 3 months` is computed at
asset creation. The system:
- Surfaces a deadline-monitoring widget on the dashboard.
- Sends reminder emails at deadline minus 14 days, minus 3 days.
- Forbids marking the melding `definitief` after deadline (the aftrek is
  irrevocably forfeited; the system MUST NOT silently proceed).
- Audits all deadline events (created, reminder-1 sent, reminder-2 sent,
  warning shown, submitted early, submitted on-time, submitted late,
  forfeited).

### D5 — KIA is boekjaar-level aggregation, not per-asset

KIA aftrek is calculated on the running **total** annual investment across
all KIA-eligible assets in the boekjaar, not on each asset individually.
The system maintains `kiaJaartotaal` and recomputes the KIA-aftrek tier
every time an asset is added/removed/revalued. The UI shows the marginal
effect: "This EUR 50k asset is tier-2 at your current total of EUR 65k,
contributing EUR 14k to KIA; if you add another EUR 10k asset, you'll move
to EUR 3.92k additional KIA."

### D6 — Vamil modifies the FixedAsset depreciation schedule

When a `VamilDepreciation` is created for an asset (MIA + Vamil claim),
the system returns a modified depreciation schedule: 75% direct in year of
ingebruikname (or earlier if paid), 25% via regular depreciation over
useful life. The `FixedAsset` consumes this via the `InvestmentAsset`
relation (1-to-1), so the T4 depreciation calculation automatically applies
the Vamil schedule.

### D7 — 5-year disposal watch is a separate background process

When a `FixedAsset` is disposed, the system checks if its `InvestmentAsset`
counterpart has any active claims (KIA/EIA/MIA) from the past 5 years
(measured from **aanvang kalenderjaar** of the asset's acquisition year).
If yes, a draft `InvesteringsaftrekClaim` reversing the aftrek is posted to
a journal entry (GL account 8120, Desinvesteringsbijtelling) with a comment
linking to the original claim. The boekhouder reviews and finalises. This
is entirely separate from the asset's depreciation reversal (which T4 handles).

### D8 — RvO beschikking is async + manually-overridable

The `InvesteringsaftrekClaim` has a lifecycle: `ingediend` (pending RvO
response) → `definitief` (RvO award received). The RvO mededeling feed
(openconnector) asynchronously populates `rvoBeschikking` with the awarded
amount and decision date. If the mededeling is delayed or missing, the
boekhouder can manually override the `rvoBeschikking` field with a
rationale (e.g., "RvO awarded via phone; pending written confirmation").
All overrides are audited.

### D9 — Energielijst / Milieulijst are versioned seeds, not hardcoded enums

The system maintains year-stamped seed files (`investeringsaftrek-energielijst-2026.json`,
`investeringsaftrek-milieulijst-2027.json`, etc.). When a claim is filed,
the code is resolved against the list of the `opdrachtverleningDatum`'s
year — NOT today's list. This handles the fact that codes are added,
removed, and renumbered annually. The UI provides a search interface keyed
on omschrijving + category and surfaces the most recent 3 years of lists
for late filings.

### D10 — Ex-ante calculator is a "what-if" mode, not a live claim

The calculator is a separate section: operator enters omschrijving +
geschatte aanschafwaarde + vermoede categorie, WITHOUT creating an
`InvestmentAsset`. The system looks up likely Energielijst/Milieulijst
codes via text match and shows three scenarios:
1. Only regular depreciation (baseline).
2. With EIA OR MIA claim (best single-scheme option).
3. With MIA + Vamil stack (if applicable, with 75% immediate depreciation
   benefit).

For each scenario, the system shows the net present value (NPV) of the
tax benefit over 5 years, given the administration's IB or Vpb tariff.
This supports go/no-go acquisition decisions before the opdracht is placed.
The calculator does NOT create data; it's purely advisory.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Asset capitalisation trigger | `FixedAsset.create` per fixed-assets-depreciation spec | `InvestmentAsset` created in same transaction or as post-hook from FixedAsset lifecycle |
| Eligibility lookup | OR `x-openregister-calculations` for formulas | EIA/MIA/Vamil threshold checks on `InvestmentAsset.create`; KIA tier-lookup via aggregation or `KiaSchalenLookup` guard |
| RvO meldingstermijn monitoring | OR `x-openregister-lifecycle` | `InvesteringsaftrekClaim` lifecycle (ingediend → definitief); deadline computed at creation; lifecycle guards block post-deadline submission |
| Disposal event detection | `FixedAsset.delete` / disposal workflow | `InvestmentAsset` listens to FixedAsset disposal event; auto-computes desinvesteringsbijtelling; posts draft GL entry |
| Journal posting | T1 general-ledger spec | Desinvesteringsbijtelling posted to account 8120 via GL API |
| Vpb-aangifte integration | T4 bookkeeping-vpb-corporate-tax | `InvesteringsaftrekClaim` aggregation produces Bijlage Investeringsaftrek; exported as PDF + XBRL |
| Audit trail | T2 bookkeeping-audit-trail | Immutable logging on all claim lifecycle transitions, RvO beschikking updates, deadline events, disposal events |
| Vamil depreciation | `FixedAsset.depreciation` | `FixedAsset` consumes `VamilDepreciation.regulierAfschrijfschema` if MIA+Vamil claim exists |
| Manifest navigation | T1 manifest pattern | 4 entries (InvestmentAssets, RvO Aanvragen, Desinvesteringsbijtelling Watch, Ex-ante Calculator) + their pages |

## Seed Data

Baseline Energielijst, Milieulijst, and KIA tiers (2026, non-exhaustive):

### EnergielijstCode (sample)

| code | categorie | omschrijving | deelpercentage | ingangsdatum |
|---|---|---|---|---|
| 251701 | Duurzame energie | Zonnepaneelsysteem > 15 kWp | 100 | 2026-01-01 |
| 261601 | Energie-efficiëntie | Warmtepomp lucht-water | 100 | 2026-01-01 |
| 441705 | Mobiliteit | Elektrische bedrijfsbus | 100 | 2026-01-01 |

### MilieulijstCode (sample)

| code | categorie | omschrijving | miaPercentage | vamilToegestaan | ingangsdatum |
|---|---|---|---|---|---|
| G3110 | Circulaire economie | Recyclemiddel textiel | 45 | true | 2026-01-01 |
| L4220 | Duurzame energie | Zonnepaneel-installatie | 36 | true | 2026-01-01 |

### KIATier (2026, per Wet IB 2001 art. 3.41 geïndexeerd)

| tier | vanaf | tot | percentage | vastBedrag | regel |
|---|---|---|---|---|---|
| 1 | 0 | 2800 | 0 | 0 | Onder drempel, geen aftrek |
| 2 | 2800 | 70602 | 28 | null | 28% over investering |
| 3 | 70602 | 130744 | null | 19769 | Vast maximumbedrag |
| 4 | 130744 | 392230 | -7.56 | 19769 | Vast bedrag minus 7,56% |
| 5 | 392230+ | ∞ | 0 | 0 | Boven plafond |

## Architectural Alignment

- **ADR-031 (Declarative Business Logic)**: Investeringsaftrek aftrek is
  pure schema + calculations; no PHP aftrek service (except single-method
  `KiaSchalenLookup` guard if needed).
- **ADR-022 (Consume OR Abstractions)**: Lifecycle + aggregations consumed
  from OR; deadline tracking, cumulation validation, and RvO status all
  declarative.
- **ADR-024 (Register Declarations)**: Six new entities registered in
  ADR-000 data model; not ad-hoc config tables.
- **ADR-030 (JourneyDoc)**: Investeringsaftrek admin flow documented as
  user journey (asset creation → eligibility check → RvO aanvraag →
  deadline monitoring → award → disposal watch).

## Migration Path

For existing Shillinq deployments with `FixedAsset` records:

1. Operator (or system batch) optionally back-tags existing assets with
   `InvestmentAsset` records if prior aftrek was claimed (to establish
   the 5-year disposal window).
2. New assets created after the spec lands auto-generate `InvestmentAsset`
   with eligibility classification.
3. RvO lists switch to 2026 seeds on 1 januari 2026; 2027 seeds ship before
   1 januari 2027, etc.
4. No breaking changes to `FixedAsset` schema; `InvestmentAsset` FK is
   optional (null-safe).

## Rollback Path

If RvO schalen or regulations change mid-fiscal-year, rollback is
dataless:

1. Revert the spec commit; entities remain (no destructive changes).
2. Post-implementation rollback: revert the code PR; claims are immutable
   audit records.
3. A patch spec (`investeringsaftrek-2026-H2-update`) files an OR issue
   if mid-year RvO directive changes; operators use the new spec for
   new assets created post-update.
