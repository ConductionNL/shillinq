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
