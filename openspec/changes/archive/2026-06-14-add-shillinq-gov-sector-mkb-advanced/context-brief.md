# Proposal: add-shillinq-gov-sector-mkb-advanced

## Summary

Add the **Tier 4 — specialized** capability layer to Shillinq: 14
narrowly-scoped capability specs covering Dutch government **sector
variants** (waterschappen, provincies, GR consolidation, rekenkamer
audit pack, extended CBS-bestanden, EMU reporting, SiSa, Markt &
Overheid separation), **vennootschapsbelasting + innovation regimes**
(Vpb, innovatiebox, KIA/EIA/MIA/Vamil, WBSO/S&O), and **MKB +
detachering** (R&D project subsidies, salarisbureau / IB47 / Wet DBA
bridge).

All 14 specs are **delivered as declarative additions** to existing
T1–T3 + T4-base registers and lifecycle blocks per ADR-031, wired
into `src/manifest.json` per ADR-024, and consume OpenRegister
abstractions (audit, RBAC, aggregations, approval-workflow) plus the
existing `openconnector` and `docudesk` integrations per ADR-022. No
new PHP service classes. No new database tables. This change is the
**largest of the rollout** by spec count; each individual spec is kept
intentionally narrow (5–8 REQs) so the chain stays buildable under
the ADR-032 sizing taxonomy.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

The earlier tiers gave Shillinq a balanced general ledger (T1),
sub-ledgers (T2), period close / trial balance / financial statements
(T3), and the T4-base set (RGS-base / iv3-base / VAT-BTW / Archiefwet
/ KOR / BCF / ZZP-tax / schatkistbankieren / subsidies / consultancy
project accounting / fixed assets / multi-currency / bank
reconciliation / SBR-XBRL / cost-centers + dimensions). What is still
missing is the **specialized surface** that turns shillinq from a
generic SMB ledger into a credible product for two distinct buyer
groups:

1. **Dutch government adopters** — waterschappen, provincies,
   gemeenschappelijke regelingen, plus the audit + statistics
   surfaces (rekenkamer audit pack, extended CBS-bestanden, EMU
   reporting, SiSa, Markt & Overheid separation). Without these,
   every BBV / IV3-compliant municipality has to bolt on a separate
   product for sector-specific reporting.
2. **MKB + innovation buyers** — Vpb administration for muni
   ondernemingsactiviteiten and private MKB, innovatiebox, WBSO/S&O,
   KIA/EIA/MIA/Vamil, R&D subsidies (MIT/SBIR/EU Horizon/EFRO),
   detachering/payroll bridge (ADP/Loket/Visma/Nmbrs + Wet DBA +
   IB47). Without these, shillinq cannot cover the innovation-and-
   personnel tax surface that drives the bookkeeping work in real MKB
   workflows.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown. This change delivers **Tier 4-specialized**:
`add-shillinq-gov-sector-mkb-advanced`. T1
(`add-shillinq-bookkeeping-foundation`), T2
(`add-shillinq-bookkeeping-compliance`), T3
(`add-shillinq-bookkeeping-operations`), and T4-base
(`add-shillinq-bookkeeping-advanced`) are siblings in the same PR.
T5 cross-cutting is deferred — see "Out of Scope".

T4-specialized is intentionally the **largest by spec count** but the
narrowest per spec: most capabilities are presentation manifests,
variant overlays, or classification flags on top of T1–T4-base
schemas. The 14-spec footprint reflects the regulatory surface area
of the Dutch government + MKB tax landscape, not implementation
complexity.

## Affected Projects

- [x] Project: shillinq — adds 0 new registers and ~10 schema
  additions/variants to `lib/Settings/shillinq_register.json`;
  extends `src/manifest.json` with up to 14 navigation entries
  (admin-tooled — most adopters enable only a subset); ships per-
  sector seed templates in `lib/Settings/seeds/` (BBV-waterschappen,
  BBV-provincies, GR-deelnemers, ESA-2010 classifier, Vpb-flag
  scaffolding).
- [ ] Project: openregister — no source changes. Consumes existing
  abstractions (aggregations, lifecycle, approval-workflow,
  scheduled-workflow, audit-trail-immutable). If a needed extension
  is missing, the gap is filed as an OR issue and recorded in
  `design.md`.
- [ ] Project: docudesk — no source changes. SiSa-bijlage, controle-
  bestanden, IB47-formulier, RvO mededeling, kwartaalrapportage, and
  jaarrapport are generated as documents by docudesk — shillinq
  declares the template and field bindings.
- [ ] Project: openconnector — no source changes. ADR-022 consumer
  pattern: salarisbureau imports (ADP / Loket / Visma / Nmbrs) and
  CBS / BZK submissions ride existing connectors.

## Scope

### In Scope

Fourteen capability specs, grouped:

**A. NL Gov Sector Variants (8)**

1. `bookkeeping-waterschappen-bbv-variant` — BBVW (BBV-Waterschappen)
   programma-indeling + EMU-saldo aangepaste berekening +
   Waterschapsbelastingen administratie (watersysteem-, zuiverings-,
   verontreinigingsheffing).
2. `bookkeeping-provincies-bbv-variant` — Provinciale BBV-variant
   met kerntaken-indeling (mobiliteit / economie / ruimte / etc.) +
   opcenten MRB + provinciale-fonds boekingen.
3. `bookkeeping-gr-consolidation` — Gemeenschappelijke regeling
   per-deelnemer toerekening + separate GR-jaarrekening + inter-GR
   elimination postings.
4. `bookkeeping-rekenkamer-audit-pack` — Rekenkamer +
   accountantscontrole audit-trail export (NIVRA-bestand),
   steekproef, ledenraadpleging-export. Presentation manifest on
   existing audit-trail.
5. `bookkeeping-cbs-bestanden-extended` — Aanvullende CBS-bestanden
   (Iv3-detail, Kerngegevens, Iv3-OZB, EMU-bestand, periodieke
   statistiek). Transformation specs on top of GL aggregation.
6. `bookkeeping-emu-reporting` — EMU-saldo + EMU-schuld berekening
   per ESA 2010 (quarterly via IV3, annual via jaarrekening).
   ESA-conforme classifier op elke posting.
7. `bookkeeping-sisa-reporting` — Single Information Single Audit
   bijlage at jaarrekening + controleprotocol + BZK submission.
8. `bookkeeping-market-government-separation` — Wet Markt en
   Overheid: integrale-kostprijs eis, separate kostprijscalculatie,
   transparantieadministratie.

**B. Vpb + Innovation (4)**

9. `bookkeeping-vpb-corporate-tax` — Vennootschapsbelasting per Wet
   modernisering Vpb-plicht (2016): Vpb-pligtig tagging, separate
   Vpb-balans, aangifte voorbereiding.
10. `bookkeeping-innovatiebox-administratie` — Innovatiebox per Wet
    Vpb art. 12b: IP-asset valuation, winsttoerekening, 5%-tarief
    administratie.
11. `bookkeeping-investeringsaftrek` — KIA, EIA, MIA, Vamil
    computation + onderbouwingsadministratie + RvO aanvraagdossier.
12. `bookkeeping-wbso-sno-administratie` — S&O-uren per project +
    medewerker, loonkostenadministratie, RvO mededelingen +
    kwartaalrapportage + jaarrapport, afdrachtvermindering
    loonheffing.

**C. R&D Subsidies + Payroll Bridge (2)**

13. `bookkeeping-r-d-subsidies-mkb` — MIT, SBIR, EU Horizon,
    EFRO/REACT-EU projectadministratie + voortgangsrapportage +
    kostendossiers + audit-trail per regeling.
14. `bookkeeping-detachering-payroll-administratie` —
    Salarisbureau import (ADP/Loket/Visma/Nmbrs), ZZP-detachering,
    opdrachtgeversverklaring (Wet DBA), IB47-formulier.

### Out of Scope

- **T5 cross-cutting** — intercompany eliminations, group consolidation
  (multi-administration), full e-invoicing / Peppol BIS3 conformance,
  international VAT one-stop-shop. Future change family.
- **Bespoke Vue components** beyond what `CnIndexPage` / `CnDetailPage`
  from `@conduction/nextcloud-vue` (Tier-4 manifest renderer) already
  render. Each capability gets a manifest navigation entry; no custom
  views unless a sector-specific reporting page is unavoidable (none
  are in this envelope).
- **Implementation code** — this is a spec-only change. Schema patches,
  seed files, and manifest entries land via a separate `opsx-apply`
  cycle per spec (ADR-032 chained-spec routing).
- **Sector variants beyond NL** — Belgian, German, Spanish municipal
  accounting are entirely deferred.

## Approach

Per ADR-032, this is a `kind: config` change — every spec adds
schema metadata, seed data, manifest entries, or thin transformation
declarations. No PHP service classes are authored. Each of the 14
specs is independently small enough to land as its own opsx-apply
cycle once the change envelope merges.

The dependency graph is explicit (per spec frontmatter `Depends on:`):

```
bbv-compliance (T3) ─────┬─→ waterschappen-bbv-variant
                          ├─→ provincies-bbv-variant
                          ├─→ gr-consolidation (also dep: bookkeeping-financial-statements)
                          ├─→ emu-reporting (also dep: iv3-reporting)
                          └─→ vpb-corporate-tax (also dep: market-government-separation)

iv3-reporting (T3) ──────┬─→ cbs-bestanden-extended
                          └─→ emu-reporting

audit-trail (T2) ────────→ rekenkamer-audit-pack
bookkeeping-financial-statements (T2)→ gr-consolidation + rekenkamer-audit-pack
subsidie-verantwoording  ─┬─→ sisa-reporting
  (T3)                    └─→ r-d-subsidies-mkb
cost-centers-dimensions  ─┬─→ market-government-separation
  (T4-base)               ├─→ innovatiebox-administratie
                          └─→ wbso-sno-administratie
fixed-assets-depreciation→ investeringsaftrek
  (T4-base)
bookkeeping-accounts-payable-core ───→ detachering-payroll-administratie
  (T2)
```

Per-spec REQ prefixes (for traceability across the chain):

| Spec | Prefix |
|---|---|
| waterschappen-bbv-variant | `REQ-WSB-NNN` |
| provincies-bbv-variant | `REQ-PRB-NNN` |
| gr-consolidation | `REQ-GRC-NNN` |
| rekenkamer-audit-pack | `REQ-REK-NNN` |
| cbs-bestanden-extended | `REQ-CBSE-NNN` |
| emu-reporting | `REQ-EMU-NNN` |
| sisa-reporting | `REQ-SISA-NNN` |
| market-government-separation | `REQ-MGS-NNN` |
| vpb-corporate-tax | `REQ-VPB-NNN` |
| innovatiebox-administratie | `REQ-IBA-NNN` |
| investeringsaftrek | `REQ-INV-NNN` |
| wbso-sno-administratie | `REQ-WBSO-NNN` |
| r-d-subsidies-mkb | `REQ-RDS-NNN` |
| detachering-payroll-administratie | `REQ-DPA-NNN` |

## New Dependencies

None. Consumes existing OR abstractions, the bumped
`@conduction/nextcloud-vue@^1.0.0-beta.35`, and existing openconnector
+ docudesk integrations.

## Impact

- `lib/Settings/shillinq_register.json` — additive changes only:
  - New schemas: `WaterschapHeffingPosting`, `ProvincialeFondsPosting`,
    `GRDeelnemer`, `GRVerdeelsleutel`, `EsaClassifier` (overlay),
    `SisaRegelingIndicator`, `VpbBalansLink`, `IPAssetValuation`,
    `WinstToerekening`, `InvesteringClassifier`, `SoProject`,
    `SoUrenStaat`, `RDSubsidieRegeling`, `IB47Record`,
    `OpdrachtgeversVerklaring`.
  - Variant overlays + flag fields on existing schemas
    (`Account`, `GLLine`, `JournalEntry`,
    `RGSBaseAccount`, `BBVProgramma`, `IV3Aggregation`,
    `FixedAsset`, `CostCenter`).
- `lib/Settings/seeds/` — new seed files:
  `bbv-waterschappen-programmas.json`,
  `bbv-provincies-kerntaken.json`,
  `esa-2010-classifier.json`,
  `sisa-controleprotocol.json`,
  `investeringsaftrek-tarieven-2026.json`.
- `src/manifest.json` — up to 14 new navigation entries, all flag-
  controlled by `manifest.featureFlags` so adopters enable only the
  sectors they need.
- `docudesk` document templates: SiSa-bijlage, NIVRA-bestand,
  IB47-formulier, RvO mededeling, kwartaalrapportage, jaarrapport,
  opdrachtgeversverklaring. Template wiring is config in shillinq;
  rendering happens in docudesk.
- `openconnector` source rows: ADP / Loket / Visma / Nmbrs feed
  endpoints, CBS submission endpoint, BZK SiSa upload endpoint. Source
  declarations are config; protocol mapping is openconnector-side.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-aggregations`,
  `x-openregister-lifecycle`, `x-openregister-calculations`, the
  approval-workflow extension, scheduled-workflow, and the integration
  registry (ADR-019) being stable. If any spec hits a missing engine
  feature, the gap lives in `design.md` under "Declarative-vs-
  imperative decision" and lands as an OR issue.
- **openconnector** — salarisbureau feeds (ADP / Loket / Visma /
  Nmbrs) consumed via existing OAuth2 + REST source patterns.
  Shillinq declares the source mapping in seed data; no app-local
  HTTP client. SiSa BZK upload + CBS periodieke leveringen ride the
  same path.
- **docudesk** — document templates for the regulatory outputs
  (SiSa-bijlage, NIVRA-bestand, IB47, RvO mededeling, etc.). Shillinq
  references templates by URI; docudesk owns rendering, signing, and
  archival.

## Risks

### Risk 1: Regulatory churn across 14 surfaces

**Severity**: Medium
**Mitigation**: Most surfaces (BBV, IV3, SiSa, EMU, WBSO,
investeringsaftrek schalen) revise yearly. Seed files are version-
pinned in filename (`bbv-waterschappen-programmas-2026.json`,
`investeringsaftrek-tarieven-2026.json`) so a `2027` update is
side-by-side without schema change. Specs reference the regulation,
not the year-specific values.

### Risk 2: Cross-schema aggregation depth (EMU computation, SiSa rollups, Vpb-balans filter)

**Severity**: Medium
**Mitigation**: Same shape as T1 Risk 1 — if `x-openregister-
aggregations` can't express a multi-schema filter inside the
required transformation, the implementing cycle uses a thin PHP
calculation via the documented ADR-031 §"PHP guards remain a
legitimate seam" exception path. Specs are shape-neutral.

### Risk 3: Scope creep — "while we're at it" pressure to add intercompany / consolidation

**Severity**: Low
**Mitigation**: Out-of-scope list pins T5 cross-cutting deferred.
Any tier-bumping request lands as a new change envelope, not as a
spec inflation here.

### Risk 4: 14 specs in one change envelope is large; per-spec opsx-apply scheduling needs discipline

**Severity**: Low
**Mitigation**: Each spec is independently buildable once the
envelope merges; `tasks.md` orders the implementing tasks
topologically by `Depends on:`; Hydra's `depends_on` enforcement
prevents premature dispatch (per hydra/CLAUDE.md → dependency
enforcement).

### Risk 5: Privacy footprint of WBSO / IB47 / detachering data

**Severity**: Medium
**Mitigation**: All personnel-linked records (`SoUrenStaat`,
`IB47Record`, `OpdrachtgeversVerklaring`) declare RBAC restricting
read to roles `bookkeeper`, `payroll-officer`, `auditor`. Audit-
trail-immutable per ADR-022 logs every access. Source docs in
docudesk carry the same retention class as personnel records under
the existing Archiefwet T3 spec.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact (no implementation lands until per-spec
`opsx-apply`). Post-implementation rollback follows the per-spec
pattern — revert the implementing PR; the additive schema entries
remain queryable but unreferenced (no destructive migration).

## Open Questions

1. **EMU computation scope** — quarterly only via IV3, or also an
   intra-period rolling view? `REQ-EMU-003` proposes quarterly +
   annual only; intra-period is deferred. Confirm with the BBV
   reviewer persona.
2. **GR consolidation cardinality** — does a single shillinq
   administration represent one GR plus its deelnemers, or do
   deelnemers each run their own administration with cross-admin
   aggregation? `REQ-GRC-002` proposes per-deelnemer `administrationId`
   with FK rollup into the GR record; revisit during opsx-ff.
3. **WBSO afdrachtvermindering computation locus** — calculation in
   shillinq vs read from RvO mededeling? `REQ-WBSO-006` proposes
   shillinq computes the projected afdracht, RvO mededeling is the
   authoritative number used in the loonaangifte. Confirm with the
   WBSO consultant persona.
4. **IB47 reporting cadence** — monthly batch vs annual? `REQ-DPA-005`
   proposes annual batch with monthly dry-run; finalize in
   implementation cycle.



## Design

# Design — Gov Sector Variants + MKB / Innovation (T4-specialized)

## Context

T1 (foundation), T2 (sub-ledgers + audit + close + statements),
T3 (operations: RGS, IV3, VAT-BTW, Archiefwet, KOR, BCF, ZZP-tax,
schatkistbankieren, subsidies, consultancy projects), and T4-base
(multi-currency, reconciliation, bank connectors, year-end, fixed
assets, SBR-XBRL, cost-centers + dimensions) have given Shillinq a
generic, compliant bookkeeping engine. **T4-specialized** is the
layer where shillinq becomes a real product for two well-defined
buyer groups: **Dutch government adopters** (waterschappen,
provincies, GR, with audit + statistics + market-vs-government +
SiSa + EMU surfaces) and **MKB + innovation buyers** (Vpb +
innovatiebox + investeringsaftrek + WBSO + R&D subsidies + payroll
bridge).

The change is **spec-only**. Implementation lands later per spec
through `opsx-apply` and the standard Hydra pipeline; this doc
explains *why* the shape is what it is. With 14 specs in flight, the
design rationale is grouped by **theme**, not per-spec, to keep this
doc readable.

## Goals

- Express every T4-specialized capability as **declarative metadata** —
  schema additions, `x-openregister-*` extension blocks, manifest
  entries, seed data — per ADR-031. No new PHP service classes.
- **Consume** every regulatory surface from the abstraction that
  already provides it: audit-trail-immutable (ADR-022) for
  rekenkamer / SiSa, integration registry (ADR-019) for openconnector
  feeds (ADP / Loket / Visma / Nmbrs / CBS / BZK), docudesk for every
  regulatory document output.
- Make each spec **independently buildable** under ADR-032's
  `kind: config` budget — 5–8 REQs each, no PHP code authoring per
  spec (any declarative-engine gap is documented as an OR issue).
- Keep the per-sector adopter footprint **flag-controlled** — a
  gemeente that doesn't run a GR doesn't pay UX tax for the GR
  navigation, etc.

## Non-Goals

- No T5 cross-cutting (intercompany eliminations, group
  consolidation, full Peppol BIS3, international VAT one-stop-shop).
- No bespoke Vue components beyond what
  `@conduction/nextcloud-vue`'s Tier-4 manifest renderer already
  provides. Every spec ships a manifest entry; if a sector-specific
  reporting page genuinely needs a custom view, that decision is
  deferred to the implementing cycle.
- No PHP code authored in this change (spec-only). Specs are
  shape-neutral on whether a declarative-engine gap forces a thin
  PHP guard.
- No retrofit of existing T1–T4-base schemas beyond additive flag
  fields and overlay schemas.

## Decisions (grouped by theme)

### Theme A: NL Gov Sector Variants

#### D1 — BBV-Waterschappen and BBV-Provincies are *variants* on the existing BBV-compliance spec, not separate compliance frameworks

T3's `bookkeeping-bbv-compliance` already declares the BBV programma-
indeling shape for gemeenten. Waterschappen and provincies use the
**same regulatory framework** with sector-specific:

- A different programma / kostentoedeling structure (waterschappen
  cluster postings by `kostentoedeling`; provincies by `kerntaak`).
- A different EMU-saldo computation rule (waterschappen exclude
  certain heffingen from the EMU-saldo per the EMU-bijlage
  waterschappen handleiding).
- A different set of sector-specific belastingen / fondsen
  (watersysteemheffing, zuiveringsheffing, verontreinigingsheffing
  for waterschappen; opcenten MRB, provinciefonds, decentralisatie-
  uitkering for provincies).

Each variant is declared as a **CoA / programma overlay** on top of
the BBV-base register — a seed-data + flag pattern, not a new
register. `Account` gains an optional `bbvVariant: 'gemeente' |
'waterschap' | 'provincie'` field; `BBVProgramma` gains a
`variantStructure` discriminator. Net new schemas: 0 for variants;
1 each for the sector-specific heffing/fonds postings
(`WaterschapHeffingPosting`, `ProvincialeFondsPosting`) that don't
exist on the gemeente template.

**Alternative considered**: Fork three BBV specs (gemeente,
waterschap, provincie) with full schema duplication. Rejected — 80%
overlap, drift risk, three-times the review surface for one
regulatory framework. The variant flag fits cleanly under ADR-031's
"declare the variation as schema metadata" rule.

#### D2 — GR consolidation is per-deelnemer attribution + an eliminations layer

A gemeenschappelijke regeling (GR) is a separate juridical entity with
its own jaarrekening, funded by deelnemers (member gemeenten /
provincies / waterschappen) via a `verdeelsleutel`. The GR's
bookkeeping has two surfaces:

1. The **GR's own jaarrekening** — a complete BBV jaarrekening for
   the GR.
2. **Per-deelnemer doorbelasting** — each deelnemer's share of GR
   costs, computed from a `GRVerdeelsleutel` record, posted into the
   deelnemer's own administration as a doorbelasting.

T4-specialized adds:

- `GRDeelnemer` register — one record per deelnemer (administrationId,
  share percentage, quotum).
- `GRVerdeelsleutel` register — the per-cost-cluster apportionment
  rule (e.g. "60% inwoners-aantal, 40% gewogen oppervlak").
- An `eliminationFlag` field on `GLLine` so inter-GR transactions
  (the GR paying a deelnemer, or vice versa) can be excluded from
  consolidated views.

The eliminations are **declarative** — an `x-openregister-aggregations`
view on `GLLine` with `WHERE eliminationFlag = false` produces the
consolidated trial balance. No PHP elimination service.

**Alternative considered**: A separate `ConsolidatedTrialBalance`
register populated by a service. Rejected — the elimination view is
trivially expressible as an aggregation filter; materialising a
consolidated table introduces a sync problem.

#### D3 — Rekenkamer audit-pack is a **presentation manifest**, not a separate audit system

T2's `bookkeeping-audit-trail` already provides the immutable
audit-trail-immutable surface per ADR-022. Rekenkamer +
accountantscontrole need three things on top:

1. An **export in NIVRA-bestand format** (the standardised audit
   file the Dutch accountancy profession expects).
2. **Steekproef** support — random sampling of postings within a
   period for substantive testing.
3. **Ledenraadpleging-export** — a redacted slice for raadsleden
   review.

All three are **transformations** on the existing audit-trail data.
The audit-pack is a `manifest.json` entry pointing at three
`x-openregister-aggregations` declarations + three docudesk
templates. **No new audit register** — that would duplicate the
audit-trail-immutable surface and violate ADR-022.

#### D4 — Extended CBS-bestanden and EMU reporting are transformation specs over GL aggregations

IV3 (T3) already extracts the BBV programma totals. The extended
CBS-bestanden (Iv3-detail, Kerngegevens jaarstaten, Iv3-OZB, EMU-
bestand, periodieke statistiekleveringen) are **additional
aggregations + transformations** of the same GL data, each producing
a CSV / XML / SBR payload uploaded to CBS via openconnector.

Each spec adds:

- An `x-openregister-aggregations` declaration (the rollup query).
- A `docudesk` template (the output format).
- An `openconnector` source row (the CBS endpoint).

No bespoke transformation service. **EMU reporting** layers on top
by adding an **ESA-2010 classifier** as a CoA flag (`esaClassifier:
'S.1311' | 'S.1313' | …`) — each account is tagged with its ESA
sector, and EMU-saldo is the aggregation summing `(debit - credit)`
grouped by sector and filtered by the EMU inclusion/exclusion rules
declared as calculation metadata.

#### D5 — SiSa reporting is a per-regeling indicator register + an annual rollup

SiSa (Single Information Single Audit) is the BZK reporting framework
for **specifieke uitkeringen** — government grants tied to specific
performance indicators. Each `Subsidie` (T3 register) of subtype
`specifieke uitkering` has zero or more `SisaRegelingIndicator` records
declaring the indicators (e.g. "aantal gerealiseerde woningen",
"aantal deelnemers re-integratietraject"). At jaarrekening, the
SiSa-bijlage is a docudesk-rendered table aggregating indicators per
controleprotocol.

The indicators are **schema-declared per regeling**, seeded from the
annual SiSa-controleprotocol. The bijlage rendering is declarative.
**No SiSa service.**

#### D6 — Markt & Overheid separation = ondernemingsactiviteit flag + integrale-kostprijs aggregation

Wet Markt en Overheid requires gemeenten / provincies / waterschappen
running market activities to:

1. Identify the **ondernemingsactiviteit** as a distinct cluster.
2. Compute the **integrale kostprijs** including a fair share of
   overhead.
3. Maintain a **transparantieadministratie** showing the
   ondernemingsactiviteit cannot be cross-subsidised by public funds.

T4-specialized adds:

- An `ondernemingsActiviteit: boolean` flag on `CostCenter` (from
  T4-base `cost-centers-dimensions`).
- A declarative integrale-kostprijs `x-openregister-calculations`
  block per ondernemingsactiviteit cost-center, rolling up overhead
  via a configurable verdeelsleutel.
- A transparantieadministratie view: a manifest navigation entry
  pointing at an aggregation that proves the ondernemingsactiviteit
  is self-funding.

**No new register**; the existing `CostCenter` carries the flag.

### Theme B: Vpb + Innovation

#### D7 — Vpb tagging is account-level + a filtered "Vpb-balans" aggregation

Per Wet modernisering Vpb-plicht (2016), muni
ondernemingsactiviteiten are Vpb-pligtig. The Vpb-balans is the same
GL data filtered to Vpb-pligtig accounts only. T4-specialized adds:

- A `vpbPligtig: boolean` flag on `Account` (default `false`).
- A `VpbBalansLink` overlay schema linking ondernemingsactiviteit
  cost-centers to their Vpb-pligtig accounts (per-ondernemingsactiviteit
  Vpb-balans).
- An `x-openregister-aggregations` declaration producing the Vpb-
  balans per ondernemingsactiviteit.

The Vpb-aangifte voorbereiding output is a docudesk template; the
actual aangifte indiening rides openconnector to the Belastingdienst
SBR endpoint (which T3's `bookkeeping-sbr-xbrl-reporting` already
wires).

#### D8 — Innovatiebox: IP-asset register + winsttoerekening per pre-defined sleutel

Wet Vpb art. 12b's innovatiebox lets companies tax the
IP-attributable winst at 5%. T4-specialized adds:

- `IPAssetValuation` register — one record per IP-asset (S&O-
  certificaat, octrooi, kwekersrecht), with `valuation`,
  `valuationMethod` (forfaitair / afpelmethode), `applicableTariff:
  5%`.
- `WinstToerekening` register — per-period mapping from omzet /
  winst to one or more IP-assets via a configurable verdeelsleutel.
- A docudesk template producing the Vpb-aangifte innovatiebox-
  sectie.

The 5%-tariefadministratie is the aggregation summing winst-
toerekening per IP-asset per period. Materialisation is the OR
calculation engine.

#### D9 — Investeringsaftrek: per-investering classifier on fixed assets + RvO aanvraagdossier

KIA (kleinschaligheid), EIA (energie), MIA (milieu), Vamil (vrije
afschrijving) are computed from the existing `FixedAsset` register
(T4-base `fixed-assets-depreciation`). T4-specialized adds:

- An `InvesteringClassifier` overlay — `aftrekType: 'kia' | 'eia' |
  'mia' | 'vamil'`, `bedrijfsmiddelCode`, `aanvraagDate`.
- A seed file with **annual schalen** (`investeringsaftrek-
  tarieven-2026.json`) — versioned in filename for yearly updates.
- A docudesk template producing the RvO aanvraagdossier.

Computation (e.g. KIA's drempel / oploop / maximum) is a declarative
`x-openregister-calculations` block on `FixedAsset` reading the
seeded tarieven.

#### D10 — WBSO/S&O administratie: project-medewerker uren register + declarative afdracht calc

S&O (speur & ontwikkelingswerk) requires per-project per-medewerker
uren administratie + a quarterly mededeling to RvO and an annual
jaarrapport. Afdrachtvermindering loonheffing is computed from the
mededeling. T4-specialized adds:

- `SoProject` register — one record per S&O-project (project-naam,
  RvO-projectnummer, S&O-certificaat, looptijd).
- `SoUrenStaat` register — per-medewerker per-week per-project hour
  staff, lifecycle `draft → goedgekeurd → afgesloten`.
- A docudesk template per output (mededeling, kwartaalrapportage,
  jaarrapport).

The afdracht computation is an `x-openregister-calculations` block
reading the uren + the medewerker's S&O-uurloon (from the
detachering / payroll bridge or static seed).

### Theme C: R&D Subsidies + Payroll Bridge

#### D11 — R&D subsidies (MIT / SBIR / EU Horizon / EFRO) are variants on the existing subsidie register

T3's `bookkeeping-subsidie-verantwoording` already provides the
generic subsidie register with budget bewaking, voortgangsrapportage,
kostendossiers. Each regeling (MIT, SBIR, EU Horizon, EFRO/REACT-EU)
has its own **kostencategorieën** (e.g. EU Horizon's
"personnel costs", "subcontracting", "other direct costs", "indirect
costs") and audit-trail eisen. T4-specialized declares each as a
**variant overlay** on the subsidie register:

- A `subsidieRegeling: 'mit' | 'sbir' | 'eu-horizon' | 'efro' |
  'react-eu'` field.
- A per-regeling kostencategorieën enum constrained by the
  regeling.
- A per-regeling audit-pack template in docudesk (each regeling's
  auditor expects a specific layout).

No new register; the existing subsidie register absorbs the
extension.

#### D12 — Salarisbureau import via openconnector; IB47 + Wet DBA via docudesk

Salarisbureau imports (ADP, Loket, Visma, Nmbrs) ride existing
openconnector OAuth2 + REST patterns. T4-specialized declares:

- `openconnector` source rows for each salarisbureau (the API
  endpoint, the OAuth config).
- A mapping into the `AccountsPayable` and `JournalEntry` registers
  (the salaris-feed materialises a journal entry of subtype
  `loonkosten` per medewerker per period).

For **ZZP-detachering + Wet DBA**:

- An `OpdrachtgeversVerklaring` register — one record per
  opdracht / ZZP-er, with `verklaringStatus` (per Wet DBA / DBA-
  uitleg), `looptijd`, `werkzaamheden`.
- An `IB47Record` register — annual freelance-aangifte payload per
  opdracht (T3 already provides the underlying betalingen).
- Docudesk templates for IB47-formulier (Belastingdienst format)
  and de standaard opdrachtgeversverklaring.

No new payroll service. **No app-local API client** — openconnector
owns every external HTTP call per ADR-019.

## Reuse Analysis

| Capability needed | What already exists | T4-specialized reuse strategy |
|---|---|---|
| BBV programma-indeling | T3 `bbv-compliance` | Variants declared as overlay seed data + `bbvVariant` flag on `Account` / `BBVProgramma`. No fork. |
| EMU computation | None (this change ships it) | `EsaClassifier` overlay on `Account` + aggregation declaration. EMU-saldo = sum grouped by sector with inclusion rules in calculation metadata. |
| Audit-trail surface | T2 `bookkeeping-audit-trail` (ADR-022) | Rekenkamer audit-pack is a presentation manifest + 3 transformation aggregations + 3 docudesk templates. No new audit register. |
| IV3 base aggregations | T3 `iv3-reporting` | Extended CBS-bestanden add additional aggregation declarations + docudesk templates on the same data. |
| Subsidie register | T3 `subsidie-verantwoording` | SiSa + R&D MKB are variant overlays via `subsidieRegeling` enum. Per-regeling indicator register attaches by FK. |
| Cost-center / dimensie | T4-base `cost-centers-dimensions` | Markt & Overheid adds an `ondernemingsActiviteit` flag + integrale-kostprijs calculation block. Innovatiebox adds `WinstToerekening` linking cost-centers to IP-assets. |
| Fixed-asset register | T4-base `fixed-assets-depreciation` | Investeringsaftrek adds an `InvesteringClassifier` overlay + annual tarieven seed + RvO aanvraagdossier template. |
| Approval workflow | OR approval-workflow (ADR-022) | SiSa-bijlage + Vpb-aangifte + WBSO mededeling consume the approval-workflow gate. |
| Scheduled background jobs | OR scheduled-workflow + n8n adapter (per ADR-031 §"Background jobs") | WBSO kwartaalrapportage + CBS periodieke leveringen + EMU quarterly run materialise on schedule. |
| External feeds (salarisbureau, CBS, BZK) | openconnector (ADR-019) | Declared as source rows in seed data; no app-local HTTP client. |
| Document generation (NIVRA, SiSa, IB47, RvO outputs) | docudesk | Templates referenced by URI; rendering, signing, archival handled docudesk-side. |
| RBAC on personnel data | OR authorization | Per-schema role declarations restricting read on `SoUrenStaat`, `IB47Record`, `OpdrachtgeversVerklaring` to `bookkeeper` / `payroll-officer` / `auditor`. |
| Retention class for personnel data | T3 `archiefwet-retention` | Personnel records inherit the existing retention class (7 jaar belastingadministratie); no new class needed. |
| SBR-XBRL transmission | T4-base `sbr-xbrl-reporting` | Vpb-aangifte SBR submission rides the existing SBR transmission path. |

**Net new code in T4-specialized implementation** (across all 14
specs): ~10 schema additions, ~15 manifest navigation entries (flag-
controlled), ~5 seed files, ~7 docudesk template references, ~6
openconnector source rows. **Zero PHP services**. Possibly 1–2 thin
PHP guards if `x-openregister-aggregations` cannot express a multi-
sector EMU filter (ADR-031 exception path, single method each,
documented in implementing cycle).

## Declarative-vs-imperative decision (per ADR-031)

Per ADR-031 enforcement, every T4-specialized behaviour was classified:

| Behaviour | Decision | Why |
|---|---|---|
| BBV variant overlay (waterschappen / provincies) | Declarative — seed data + flag field | Variation expressible as schema metadata |
| GR consolidation + eliminations | Declarative — aggregation with `WHERE eliminationFlag = false` | Pure filter |
| Rekenkamer audit-pack | Declarative — aggregations + docudesk templates | Read-only views over existing audit-trail |
| Extended CBS-bestanden | Declarative — aggregation + docudesk + openconnector source | No transformation logic outside the aggregation engine |
| EMU computation | Declarative if engine supports multi-sector filter with classifier; otherwise thin PHP `EmuCalculator` per ADR-031 exception | Resolution lives in opsx-ff discovery |
| SiSa-bijlage | Declarative — per-regeling indicator register + rollup | Pure aggregation per controleprotocol |
| Markt & Overheid integrale kostprijs | Declarative — `x-openregister-calculations` per cost-center | Pure calculation |
| Vpb tagging + Vpb-balans | Declarative — flag + filtered aggregation | Pure filter |
| Innovatiebox 5%-tariefadministratie | Declarative — winsttoerekening register + calculation | Pure calculation |
| Investeringsaftrek (KIA/EIA/MIA/Vamil) | Declarative — classifier overlay + calculation reading seeded tarieven | Pure calculation |
| WBSO afdrachtvermindering | Declarative — uren register + calculation | Pure calculation |
| R&D subsidies | Declarative — variant on existing subsidie register + per-regeling kostencategorieën | Schema extension |
| Salarisbureau import | Declarative — openconnector source + mapping into JournalEntry | Existing ADR-019 path |
| IB47 + opdrachtgeversverklaring | Declarative — registers + docudesk templates | No service logic |

No service class authored in this envelope. If EMU multi-sector
filter requires a PHP calculator, it is single-method (~20 LOC) and
explicitly cited as an ADR-031 exception in the implementing cycle's
design doc.

## Seed Data

T4-specialized ships five seed files under `lib/Settings/seeds/`:

| File | Purpose | Approximate row count |
|---|---|---|
| `bbv-waterschappen-programmas-2026.json` | BBVW programma-indeling per kostentoedeling (2026 release) | ~30 |
| `bbv-provincies-kerntaken-2026.json` | Provinciale BBV kerntaken-indeling (2026 release — mobiliteit / economie / ruimte / cultuur / etc.) | ~15 |
| `esa-2010-classifier.json` | ESA-2010 sector classifier (S.1311, S.1312, S.1313, S.1314 + sub-codes) | ~25 |
| `sisa-controleprotocol-2026.json` | SiSa-controleprotocol indicatoren per regeling (2026 BZK release) | ~200 |
| `investeringsaftrek-tarieven-2026.json` | KIA drempels + tarieven, EIA / MIA percentages, Vamil bedrijfsmiddel-codes (2026 RvO release) | ~100 |

Each file:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md` — inside the docblock, not as line
  comments.
- `_meta` block (`{ "_meta": { "source": "<reg-naam>", "year": 2026,
  "imported": "<iso-ts>" } }`) so a 2027 swap is filename-only.
- Loaded via `ConfigurationService::importFromApp()` in the repair
  step.

No seed data for the registers that accumulate operationally
(`GRDeelnemer`, `IPAssetValuation`, `SoProject`, `IB47Record`, etc.).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| 14 specs in one envelope is administratively heavy | Per-spec opsx-apply cycles after envelope merge; Hydra `depends_on` enforces topological order; each spec is independently small (5–8 REQs) per ADR-032 budgeting. |
| Annual regulatory churn (BBV, IV3, SiSa, EMU, WBSO, investeringsaftrek) | Seed files version-pinned in filename (`*-2026.json` → `*-2027.json`); specs reference regulations, not year-specific values. |
| Cross-schema aggregation gaps (EMU multi-sector filter, SiSa per-controleprotocol rollup, Vpb-balans filter, integrale-kostprijs verdeelsleutel) | Each gap surfaces in opsx-ff per spec; ADR-031 exception path documented as fallback. Specs are shape-neutral. |
| Per-adopter UX overload (a small gemeente seeing 14 navigation entries) | All 14 manifest entries are `featureFlags`-controlled; default enables only the BBV-base + IV3 set. Each sector is opt-in. |
| Privacy footprint on personnel + S&O data | RBAC + audit-trail-immutable + Archiefwet retention all reused; no app-local privacy logic. |
| Sector overlap (waterschap that also runs a GR) | Variants are flags, not exclusive — a single administration can carry `bbvVariant: waterschap` and participate in a GR via `GRDeelnemer`. No exclusive constraint. |
| Future tier (T5 cross-cutting) needs fields T4-specialized didn't anticipate | All schema changes are additive per OR's schema versioning. Risk accepted. |

## Migration Plan

Spec-only — no runtime migration in this change. Per-spec
implementation cycle pattern (repeats 14 times):

1. `lib/Settings/shillinq_register.json` is patched additively with
   the spec's schema fields / overlay registers.
2. `lib/Settings/seeds/` gains the spec's seed file (if any).
3. `src/manifest.json` gains the spec's `featureFlags`-controlled
   navigation entry.
4. `docudesk` template references and `openconnector` source rows
   are added as config.
5. ADR-000 may gain a one-paragraph annotation for newly named
   registers; reconciliation note added in the implementing cycle.

Down-direction: additive registers are non-destructive; reverting
the implementing PR leaves stranded but queryable records.

## Open Questions

1. **EMU computation scope** — quarterly + annual only, or
   also rolling intra-period view? `REQ-EMU-003` proposes
   quarterly + annual only. Confirm with BBV reviewer persona.
2. **GR consolidation cardinality** — one shillinq administration
   per GR (with deelnemers as FK records) vs one administration per
   deelnemer with cross-admin rollup? `REQ-GRC-002` proposes the
   first; revisit in opsx-ff.
3. **WBSO afdrachtvermindering computation locus** — shillinq
   computes projected afdracht; RvO mededeling is authoritative for
   loonaangifte. Confirm with WBSO consultant persona.
4. **IB47 reporting cadence** — annual batch with monthly dry-run
   (`REQ-DPA-005`)? Finalize in implementation cycle.
5. **Innovatiebox forfaitaire vs afpelmethode** — both supported
   per `REQ-IBA-002`; default ondersteuning of forfaitair (cleaner
   audit trail) confirmed with the bookkeeper persona before
   implementing cycle.



## Tasks

# Tasks — Gov Sector Variants + MKB / Innovation (T4-specialized)

> **Spec-only change.** Per `proposal.md` Scope, implementation code
> is deliberately out of scope. The tasks below describe the work
> per-spec `opsx-apply` cycles will execute against the 14 spec deltas
> — recorded now so spec-review, dependency planning, and the chain
> footprint are visible at proposal time. No source files are edited
> by this change itself.

## 0. Deduplication Check

### Task 0.1: Confirm no T4-specialized schema or capability already exists

- **spec_ref**: all fourteen specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/changes/**`,
  `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of
    `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no schema named `WaterschapHeffingPosting`,
    `ProvincialeFondsPosting`, `GRDeelnemer`, `GRVerdeelsleutel`,
    `EsaClassifier`, `SisaRegelingIndicator`, `VpbBalansLink`,
    `IPAssetValuation`, `WinstToerekening`, `InvesteringClassifier`,
    `SoProject`, `SoUrenStaat`, `RDSubsidieRegeling`, `IB47Record`,
    or `OpdrachtgeversVerklaring` is already declared.
  - GIVEN `openspec/changes/` WHEN scanned THEN no other in-flight
    change envelope (foundation / compliance / operations / advanced)
    declares one of the 14 capability slugs in this change.
  - GIVEN `adr-000-data-model.md` WHEN scanned THEN any existing
    entry overlapping a T4-specialized register is catalogued and a
    reconciliation note is appended in the implementing cycle (not
    in this spec).
- [ ] Implement
- [ ] Test

## 1. Spec authoring (this change's own deliverables)

### Task 1.1: Author bookkeeping-waterschappen-bbv-variant spec

- **spec_ref**: `openspec/changes/add-shillinq-gov-sector-mkb-advanced/specs/bookkeeping-waterschappen-bbv-variant/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Status: proposed` / `Scope: shillinq` /
    `Tier: T4-specialized (NL gov sector)` /
    `Depends on: bbv-compliance` in the header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-WSB-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.2: Author bookkeeping-provincies-bbv-variant spec

- **spec_ref**: `.../bookkeeping-provincies-bbv-variant/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-PRB-NNN` prefix;
  `Depends on: bbv-compliance`; kerntaken-indeling, opcenten MRB,
  provinciefonds boekingen declared.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.3: Author bookkeeping-gr-consolidation spec

- **spec_ref**: `.../bookkeeping-gr-consolidation/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-GRC-NNN` prefix;
  `Depends on: bbv-compliance, bookkeeping-financial-statements`;
  per-deelnemer toerekening + inter-GR elimination + quotum
  declared.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.4: Author bookkeeping-rekenkamer-audit-pack spec

- **spec_ref**: `.../bookkeeping-rekenkamer-audit-pack/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-REK-NNN` prefix;
  `Depends on: audit-trail, bookkeeping-financial-statements`; presentation
  manifest pattern (NIVRA-bestand, steekproef,
  ledenraadpleging-export).
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.5: Author bookkeeping-cbs-bestanden-extended spec

- **spec_ref**: `.../bookkeeping-cbs-bestanden-extended/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-CBSE-NNN` prefix;
  `Depends on: iv3-reporting`; aggregation + docudesk template +
  openconnector source pattern for each CBS-bestand.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.6: Author bookkeeping-emu-reporting spec

- **spec_ref**: `.../bookkeeping-emu-reporting/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-EMU-NNN` prefix;
  `Depends on: bbv-compliance, iv3-reporting`; ESA-2010
  classifier overlay + quarterly/annual rollup declared.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.7: Author bookkeeping-sisa-reporting spec

- **spec_ref**: `.../bookkeeping-sisa-reporting/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-SISA-NNN` prefix;
  `Depends on: subsidie-verantwoording`; per-regeling indicator
  register + annual SiSa-bijlage rollup + BZK submission via
  openconnector.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.8: Author bookkeeping-market-government-separation spec

- **spec_ref**: `.../bookkeeping-market-government-separation/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-MGS-NNN` prefix;
  `Depends on: cost-centers-dimensions`; ondernemingsactiviteit
  flag + integrale-kostprijs calculation + transparantieadministratie
  view.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.9: Author bookkeeping-vpb-corporate-tax spec

- **spec_ref**: `.../bookkeeping-vpb-corporate-tax/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-VPB-NNN` prefix;
  `Depends on: bbv-compliance, market-government-separation`;
  Vpb-pligtig flag + Vpb-balans aggregation + aangifte voorbereiding.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.10: Author bookkeeping-innovatiebox-administratie spec

- **spec_ref**: `.../bookkeeping-innovatiebox-administratie/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-IBA-NNN` prefix;
  `Depends on: cost-centers-dimensions, vpb-corporate-tax`;
  IP-asset valuation + winsttoerekening + 5%-tarief calc.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.11: Author bookkeeping-investeringsaftrek spec

- **spec_ref**: `.../bookkeeping-investeringsaftrek/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-INV-NNN` prefix;
  `Depends on: fixed-assets-depreciation`; KIA/EIA/MIA/Vamil
  classifier + annual schalen seed + RvO aanvraagdossier.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.12: Author bookkeeping-wbso-sno-administratie spec

- **spec_ref**: `.../bookkeeping-wbso-sno-administratie/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-WBSO-NNN` prefix;
  `Depends on: cost-centers-dimensions`; S&O-uren register +
  mededeling / kwartaalrapportage / jaarrapport + afdrachtvermindering.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.13: Author bookkeeping-r-d-subsidies-mkb spec

- **spec_ref**: `.../bookkeeping-r-d-subsidies-mkb/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-RDS-NNN` prefix;
  `Depends on: subsidie-verantwoording`; per-regeling
  kostencategorieën + audit-pack template per regeling.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.14: Author bookkeeping-detachering-payroll-administratie spec

- **spec_ref**: `.../bookkeeping-detachering-payroll-administratie/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-DPA-NNN` prefix;
  `Depends on: bookkeeping-accounts-payable-core`; salarisbureau feed +
  opdrachtgeversverklaring + IB47.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.15: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the
    shared `nextcloud-app` spec per shillinq config.yaml and
    includes Affected Projects / Scope / Risks / Rollback / Open
    Questions.
  - GIVEN `design.md` WHEN inspected THEN it includes a Reuse
    Analysis table and a Seed Data section per hydra
    `rules.design`.
- [x] Implement
- [ ] Test (architecture reviewer + sector personas confirm shape)

---

## (The following tasks are recorded for the per-spec `opsx-apply` cycles, not for this spec-only change. Ordered by dependency.)

## 2. Schema additions — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare `WaterschapHeffingPosting` schema + `bbvVariant` flag on Account

- **spec_ref**: `bookkeeping-waterschappen-bbv-variant` REQ-WSB-001..005
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**: schema validates; `bbvVariant` enum
  includes `waterschap`; lifecycle declared for posting state.
- [ ] Implement
- [ ] Test (PHPUnit: variant overlay round-trips; reject unknown
  variant)

### Task 2.2: Declare `ProvincialeFondsPosting` schema + `bbvVariant` flag carries `provincie`

- **spec_ref**: `bookkeeping-provincies-bbv-variant` REQ-PRB-001..004
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.3: Declare `GRDeelnemer` + `GRVerdeelsleutel` + `eliminationFlag` on GLLine

- **spec_ref**: `bookkeeping-gr-consolidation` REQ-GRC-001..006
- **files**: same
- [ ] Implement
- [ ] Test (PHPUnit: eliminations-filtered aggregation matches
  worked-example)

### Task 2.4: Declare `EsaClassifier` overlay + `esaClassifier` enum on Account

- **spec_ref**: `bookkeeping-emu-reporting` REQ-EMU-001..002
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.5: Declare `SisaRegelingIndicator` + variant flag on Subsidie

- **spec_ref**: `bookkeeping-sisa-reporting` REQ-SISA-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.6: Declare `ondernemingsActiviteit` flag on CostCenter + integrale-kostprijs calc

- **spec_ref**: `bookkeeping-market-government-separation` REQ-MGS-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.7: Declare `vpbPligtig` flag on Account + `VpbBalansLink` overlay

- **spec_ref**: `bookkeeping-vpb-corporate-tax` REQ-VPB-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.8: Declare `IPAssetValuation` + `WinstToerekening` registers

- **spec_ref**: `bookkeeping-innovatiebox-administratie` REQ-IBA-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.9: Declare `InvesteringClassifier` overlay on FixedAsset

- **spec_ref**: `bookkeeping-investeringsaftrek` REQ-INV-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.10: Declare `SoProject` + `SoUrenStaat` + afdracht calc

- **spec_ref**: `bookkeeping-wbso-sno-administratie` REQ-WBSO-001..006
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.11: Declare `subsidieRegeling` enum + per-regeling kostencategorieën on Subsidie

- **spec_ref**: `bookkeeping-r-d-subsidies-mkb` REQ-RDS-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.12: Declare `OpdrachtgeversVerklaring` + `IB47Record` registers

- **spec_ref**: `bookkeeping-detachering-payroll-administratie` REQ-DPA-001..006
- **files**: same
- [ ] Implement
- [ ] Test

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1: Ship BBV-Waterschappen programma seed (2026 release)

- **spec_ref**: `bookkeeping-waterschappen-bbv-variant` REQ-WSB-003
- **files**: `lib/Settings/seeds/bbv-waterschappen-programmas-2026.json`
- **acceptance_criteria**: JSON validates against BBVProgramma
  schema; SPDX + `_meta` block present; `_meta.source` references
  the BBVW handleiding.
- [ ] Implement
- [ ] Test

### Task 3.2: Ship BBV-Provincies kerntaken seed (2026 release)

- **spec_ref**: `bookkeeping-provincies-bbv-variant` REQ-PRB-003
- **files**: `lib/Settings/seeds/bbv-provincies-kerntaken-2026.json`
- [ ] Implement
- [ ] Test

### Task 3.3: Ship ESA-2010 classifier seed

- **spec_ref**: `bookkeeping-emu-reporting` REQ-EMU-002
- **files**: `lib/Settings/seeds/esa-2010-classifier.json`
- [ ] Implement
- [ ] Test

### Task 3.4: Ship SiSa-controleprotocol seed (2026 release)

- **spec_ref**: `bookkeeping-sisa-reporting` REQ-SISA-002
- **files**: `lib/Settings/seeds/sisa-controleprotocol-2026.json`
- [ ] Implement
- [ ] Test

### Task 3.5: Ship investeringsaftrek tarieven seed (2026 release)

- **spec_ref**: `bookkeeping-investeringsaftrek` REQ-INV-003
- **files**: `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json`
- [ ] Implement
- [ ] Test

### Task 3.6: Extend repair step to import selected sector seeds

- **spec_ref**: all 14 specs (cross-cutting)
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh install with `waterschap` feature flag enabled
    WHEN the repair step runs
    THEN the BBV-waterschappen programmas appear; idempotent on
    re-run; per-administration overrides preserved.
- [ ] Implement
- [ ] Test (PHPUnit + browser smoke per sector)

## 4. Manifest navigation — `src/manifest.json`

### Tasks 4.1 – 4.14: Add navigation entries per spec, all `featureFlags`-controlled

- **spec_ref**: each spec's "manifest reachable" REQ
- **files**: `src/manifest.json`
- **acceptance_criteria** (apply per task):
  - GIVEN the manifest WHEN scanned THEN the spec's navigation entry
    is declared under `featureFlags` keyed on the sector slug
    (e.g. `featureFlags.gov-waterschap`).
  - GIVEN the feature flag is off WHEN the UI renders THEN the
    entry MUST NOT appear in the menu.
  - GIVEN `node tests/validate-manifest.js` WHEN run THEN it exits 0.
- [ ] Implement (×14)
- [ ] Test (validate-manifest + browser smoke per enabled flag)

## 5. Docudesk templates

### Tasks 5.1 – 5.7: Register docudesk templates per spec

Templates: SiSa-bijlage, NIVRA-bestand, IB47-formulier, RvO
mededeling, RvO kwartaalrapportage, RvO jaarrapport,
opdrachtgeversverklaring, Vpb-aangifte voorbereiding,
innovatiebox-sectie. Each is a `docudesk` template reference + field
binding declared in shillinq.

- **spec_ref**: per-spec REQ
- **files**: `lib/Settings/docudesk-templates.json` (new) +
  docudesk-side template registration via openconnector source
- **acceptance_criteria**: template URI resolvable; field bindings
  match the spec's data shape; sample render produces expected
  document.
- [ ] Implement
- [ ] Test (PHPUnit + docudesk integration test)

## 6. Openconnector source rows

### Tasks 6.1 – 6.4: Register external feed/submission sources

- ADP / Loket / Visma / Nmbrs salarisbureau OAuth2 + REST
- CBS periodieke leveringen + EMU-bestand
- BZK SiSa upload
- RvO WBSO mededeling + jaarrapport

- **spec_ref**: per-spec REQ
- **files**: openconnector-side source declarations referenced from
  `lib/Settings/openconnector-sources.json`
- **acceptance_criteria**: source row creates cleanly; OAuth flow
  in dev container succeeds (with mock IdP); mapping into shillinq
  registers validates.
- [ ] Implement
- [ ] Test

## 7. ADR-000 reconciliation note (deferred — per-spec)

### Task 7.1: Update adr-000-data-model.md with the new T4-specialized entries

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**: each new register (`GRDeelnemer`,
  `IPAssetValuation`, `SoProject`, `IB47Record`,
  `OpdrachtgeversVerklaring`, etc.) gains a one-paragraph entry
  cross-referencing its T4-specialized spec.
- [ ] Implement (incremental — as each spec lands)
- [ ] Test (peer review)

## 8. Lifecycle / calculation guards (conditional — only if engine gap confirms)

### Task 8.1 (conditional): Author EmuCalculator or similar thin guard

- **spec_ref**: `bookkeeping-emu-reporting` REQ-EMU-003
- **files**: `lib/Lifecycle/EmuCalculator.php` (conditional, single
  method, ~20 LOC)
- **acceptance_criteria**: only authored if opsx-ff discovery
  confirms the engine cannot express the multi-sector EMU filter
  inside `x-openregister-aggregations`; carries ADR-031 exception
  annotation linking back to design.md.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: worked example matches the CBS published
  benchmark)

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 +
      ADR-032 compliance (no app-local services; manifest carries the
      navigation; chain frontmatter declared; no `kind: mixed`)
- [ ] Domain reviewers (BBV-expert / WBSO-consultant / Vpb-belasting-
      adviseur) confirm the model matches real Dutch government +
      MKB tax practice
- [ ] No source code changes outside
      `openspec/changes/add-shillinq-gov-sector-mkb-advanced/`

## Tests (company-wide ADR-009)

<!-- T4-specialized spec-only change. Per-spec opsx-apply cycles ship implementation tests on the tasks above. -->

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic
      (`tests/Unit/`) — declared on tasks 2.1–2.12, 3.6, 8.1; lands
      per implementing cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new
      endpoints in T4-specialized (OR exposes register CRUD
      generically; tests cover the register HTTP surface per sector)
- [ ] Browser tests (Playwright MCP) for UI changes — declared on
      tasks 4.1–4.14; lands per implementing cycle
- [ ] All tests pass (`composer test`) — enforced at implementing
      PR's CI gate per sector

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with each implementing cycle, not the spec. -->

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/` — per-sector pages
      under `docs/user-guide/bookkeeping/gov-{waterschap,provincie,
      gr,rekenkamer,cbs,emu,sisa,markt-overheid}/` and
      `docs/user-guide/bookkeeping/mkb/{vpb,innovatiebox,
      investeringsaftrek,wbso,r-d-subsidies,detachering}/` per
      ADR-030 journeydoc convention
- [ ] Screenshot captured and committed to `docs/images/` — per spec
      during implementing cycle (1 screenshot minimum per sector)

## i18n (company-wide ADR-005)

<!-- No user-facing strings in the spec; translation work lands per implementing cycle. -->

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings
      added per implementing cycle — required terms include:
      `Waterschap`, `Provincie`, `Gemeenschappelijke regeling`,
      `Rekenkamer`, `CBS-bestand`, `EMU-saldo`, `EMU-schuld`,
      `Single information single audit`, `Markt en Overheid`,
      `Vennootschapsbelasting`, `Innovatiebox`,
      `Investeringsaftrek`, `WBSO`, `S&O-uren`,
      `Afdrachtvermindering loonheffing`, `Mededeling`,
      `Kwartaalrapportage`, `Jaarrapport`,
      `Opdrachtgeversverklaring`, `IB47-formulier`,
      `Salarisbureau`, `Detachering`