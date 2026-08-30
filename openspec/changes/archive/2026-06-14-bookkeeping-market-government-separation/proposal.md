# Proposal: bookkeeping-market-government-separation

`kind: spec` — foundational WMO (Wet Markt en Overheid) compliance capability for Dutch decentral government, with automated cost-activity register, integral cost price calculation, transaction splitting, ABB lifecycle, ACM reporting, and audit trail.

## Summary

Introduce the **Wet Markt en Overheid (WMO) compliance and commercial activity bookkeeping** capability for Shillinq as a foundation for any Dutch decentral government (gemeente, provincie, waterschap, gemeenschappelijke regeling, omgevingsdienst) that operates a market-facing activity. This spec declares six new registers (`CommercialActivity`, `IntegralCostPrice`, `OverheadDistributionRule`, `AlgemeenBelangBesluit`, `ActivityCostAllocation`, `ACMReport`), automatic transaction splitting by commercial-activity dimension, monthly/quarterly integral cost price calculation, cross-subsidy-detection alerts, ABB lifecycle workflow with governance integration, and an immutable audit trail meeting ACM-onderzoek standards. The implementation spans three phases: Phase 1 (MVP, Q3 2026) covers requirements 1–4 (register, IKP calc, splits, jaarrekening export); Phase 2 (Compliance, Q4 2026) adds requirements 5–7, 10 (ABB lifecycle, ACM reporting, cross-subsidy alerts, audit trail); Phase 3 (Governance & ecosystem, Q1–Q2 2027) adds requirements 8, 9, 11, 12 (activity transitions, raadsvoorstel coupling, multi-bestuursorgaan, market benchmarks).

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure, OpenAPI 3.0 register format, and integrates with `bookkeeping-bbv-compliance`, `bookkeeping-cost-centers-dimensions`, `bookkeeping-general-ledger`, and `bookkeeping-governance`.

## Motivation

The WMO (Mededingingswet, hoofdstuk 4b, in force 1 juli 2012, gewijzigd 1 juli 2014) is a Dutch state-aid law requiring every `bestuursorgaan` that offers goods/services on a market to follow four **gedragsregels** (conduct rules): integrale kostprijs (Art. 25i), bevoordelingsverbod (Art. 25j), gegevensgebruik (Art. 25k), functiescheiding (Art. 25l). Enforcement is by the **Autoriteit Consument en Markt (ACM)**, which can open investigations and impose fines up to €900k or 10% annual turnover. Known cases include Veendam (parkeergarages 2014), Hilversum (haven Crailo 2015), Eindhoven (parkeerexploitatie 2017), Reigersbos (Amsterdam Zuidoost 2020) — all turned on insufficient cost-price pass-through or ground-value bevoordeling. 

A gemeente/waterschap can exempt an activity by passing an **algemeen-belang-besluit (ABB)** in the raad/Provinciale Staten, motiveren the public interest, notify ACM, publish in gemeenteblad. But ACM and the ATRR report annually that ABB motiveringen are "too thin" — lack rigour.

The economic scale is significant: 342 gemeenten, 12 provincies, 21 waterschappen, ~600 GR's in NL (2026). Per VNG (2023), 84% of gemeenten perform at least one commercial activity, average 4.2/gemeente. Top categories: sportaccommodaties (78%), cultuurgebouwen (61%), parkeerexploitatie (44%), kringloop/retail (29%), haven exploitation (18%), bedrijventerrein-uitgifte (39%), schoollunches-catering (22%), reclame-exploitatie (31%), datacenter-warmte-as-a-service (7%, rapidly growing). WMO touches not a niche, but the core budget and jaarrekening cycle of decentral government.

Today, controllers fall back on Excel + ad-hoc grootboek-rekeningen ("8xxx markt") + hand-berekende overhead-sleutels — exactly the schaduwadministratie that triggers ACM investigations. This spec adds first-class WMO support: automatic commercial-activity register, IKP calculation monthly with year-end finalization, automatic transaction splits (energy, HR, facility cost) over publieke vs. commerciële sub-administraties per ABB status, cross-subsidy-detection alerts (loss-financing, overhead under-allocation), ABB-workflow with raad-besluit coupling, quarterly ACM reporting with one-click export of audit-indexed handhavings-pakketten, and a 7-year immutable audit log meeting Mededingingswet retention.

## Affected Projects

- [x] **Project: shillinq** — adds 6 new registers (`CommercialActivity`, `IntegralCostPrice`, `OverheadDistributionRule`, `AlgemeenBelangBesluit`, `ActivityCostAllocation`, `ACMReport`) to `lib/Settings/shillinq_register.json`, automatic transaction-splitting logic coupled to journal-entry post (via `bookkeeping-general-ledger`'s event-bus per ADR-008), monthly scheduled workflow for IKP calculation, cross-subsidy detector runner, ABB governance integration, ACM export templates, manifest navigation entries under `Bookkeeping > WMO Compliance`.
- [ ] **Project: openregister** — no source changes; this change consumes `x-openregister-relations`, `x-openregister-lifecycle`, `x-openregister-aggregations`, audit-trail-immutable, RBAC, scheduled workflows, and event-bus (ADR-008).
- [ ] **Project: bookkeeping-governance** — optional secondary integration for ABB raads-voorstel coupling (Phase 3); this spec works standalone without it (Phase 1 ABB's reference raad-besluit by freeform ID).

## Scope

### In Scope

- One new capability spec (`bookkeeping-market-government-separation`) — see `specs/` folder.
- **Phase 1 (MVP, Q3 2026):**
  - `CommercialActivity` register (REQ-WMO-001) with code, naam, bestuursorgaan, organisatieonderdeel, beschrijving, marktsegment, concurrenten, afnemers, startDatum, kostprijsMethode, kostenplaats/kostendrager coupling, ACM meldingstatus, ABB exemption link.
  - `IntegralCostPrice` register (REQ-WMO-002) with monthly/annual cadence, time-versioned records, components (directe loonkosten, directe materialen, afschrijvingen, indirecte overhead via BBV-sleutel, vermogenskosten, winstopslag), status (`voorlopig`, `definitief`), compliant flag, audit trail.
  - Automatic `ActivityCostAllocation` split (REQ-WMO-003) on journal-entry post, using `OverheadDistributionRule` (existing from `bookkeeping-cost-centers-dimensions`), with handmatige override option (2-eyes sign-off).
  - Jaarrekening-bijlage WMO export (REQ-WMO-004) per comercial activity, kostendekkingsoverzicht, machine-leesbaar SBR/XBRL.
- **Phase 2 (Compliance, Q4 2026):**
  - `AlgemeenBelangBesluit` register (REQ-WMO-005) with workflow (concept → raadsvoorstel → raadsbesluit → publicatie gemeenteblad → kennisgeving ACM → bezwaartermijn → geldig → evaluatie), automatic evaluation-task trigger on `volgendeEvaluatie`, DROP-API publication verification.
  - ACM quarterly/annual reporting (REQ-WMO-006) in ACM-standaardformulier 2024, digital signature + timestamp, write-once archive, 7-year retention.
  - Cross-subsidy detector (REQ-WMO-007) with monthly runner, alerts on loss-financing, overhead under-allocation, high omzetgroei without IKP update, ABB-stale, high manual-override ratio, potentiële overhead-onderschatting.
  - Immutable audit trail (REQ-WMO-010) for all WMO-mutations, CSV export, ACM-handhavings-pakket (zip with manifest.json + indexed JSON + PDF's).
- **Phase 3 (Governance & ecosystem, Q1–Q2 2027):**
  - Activity-transition workflow (REQ-WMO-008) (publiek ↔ commercieel, commercieel → ABB-exempt), openingsbalans with marktwaarde-transfer, first IKP as `voorlopig-transitie`.
  - Governance integration (REQ-WMO-009) with raads-voorstel / raads-besluit linking, signature + griffier-handtekening.
  - Multi-bestuursorgaan support (REQ-WMO-011) for gemeenschappelijke regelingen, shared-service-centra, RUD's, GGD's, with per-deelnemer ABB requirement.
  - Market-benchmark register (REQ-WMO-012) for tariff validation, concurrent pricing, bevoordeling-risk flagging.

### Out of Scope

- **Other Mededingingswet chapters** — this spec focuses on hoofdstuk 4b (market-activity conduct rules). Hoofdstuk 6 (state-aid pre-notification) is outside this scope.
- **Non-Dutch government entities** — shillinq is focused on Dutch decentral entities; this spec assumes Dutch GAAP, gemeenteblad publication, ACM jurisdiction.
- **Frontend Vue components beyond generic rendering** — manifest navigation uses standard `CnIndexPage` / `CnDetailPage` from `@conduction/nextcloud-vue`.
- **Real-time ACM portal integration** — Phase 1–2 export formats support future direct portal submission; actual portal API integration is deferred.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-market-government-separation`** — declares the six WMO-specific registers, automatic transaction-splitting logic, monthly IKP calculation, cross-subsidy detection, ABB lifecycle management, ACM reporting, and immutable audit trail.

The spec follows the conduction-schema format (RFC 2119, `### REQ-WMO-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Requirements are grouped by phase and by functional area (register, calculation, splitting, reporting, governance, audit).

## New Dependencies

- **bookkeeping-bbv-compliance** — reuses BBV overhead-toerekening (taakveld 0.4) as the foundation for WMO IKP overhead component. Consistency between WMO and BBV sleutels is a control point.
- **bookkeeping-cost-centers-dimensions** — uses `OverheadDistributionRule` and kostenplaats/kostendrager dimension infrastructure to split transactions.
- **bookkeeping-general-ledger** — hooks into journal-entry post event-bus (ADR-008) for automatic `ActivityCostAllocation` split triggering.
- **bookkeeping-governance** (optional, Phase 3) — secondary integration for raads-voorstel / raads-besluit linking; works standalone without it.
- **openregister** — leverages relations engine, lifecycle actions, scheduled workflows, event-bus, audit-trail-immutable, RBAC.

## Impact

- `lib/Settings/shillinq_register.json` — adds 6 new schemas with cross-references, RBAC role definitions (concerncontroller, BBV-specialist, juridisch-beleidsmedewerker, griffier, gemeentesecretaris), `x-openregister-lifecycle` actions on CommercialActivity-save and AlgemeenBelangBesluit-save, `x-openregister-aggregations` for cost-price components.
- `lib/Migration/` — new migration step to seed example commercial activities (if present in org's historical data, seeded in `lifecycle: archived` for historical-only reference).
- `lib/Service/` (Phase 1 only, minimal) — `CommercialActivityService` (getter/lookup), `IntegralCostPriceCalculator` (scheduled workflow executor), `ActivityCostAllocationSplitter` (event-listener on JournalEntry.post), `CrossSubsidyDetector` (scheduled monthly runner).
- `src/manifest.json` — navigation entries under `Bookkeeping > WMO Compliance` for all 6 registers, with index + detail pages.
- No new bespoke Vue components; all pages render via generic `CnIndexPage` / `CnDetailPage` (per ADR-024).
- Performance: large gemeente (90 commercial activities, >2M journal posts/year, ~18% WMO-relevant = 360k ActivityCostAllocation rows/year) requires async queue-based split execution (SLA 5 min; per ADR-008 event-bus async pattern).

## Cross-Project Dependencies

- **bookkeeping-bbv-compliance** — WMO IKP inherits BBV-overhead methodology (taakveld 0.4 split). Inconsistency would fail both WMO and BBV controls.
- **bookkeeping-cost-centers-dimensions** — WMO's commercial-activity dimension and OverheadDistributionRule reuse the cost-center framework and allocation-rule engine.
- **bookkeeping-general-ledger (T1)** — automatic splitting hooks into T1's journal-entry post event (per ADR-008). Requires T1 event-bus already wired.
- **bookkeeping-governance** (Phase 3 optional) — ABB raads-voorstel linking uses governance's decision-tracking if available; otherwise freeform raad-besluit ID stored.
- **openconnector** — DROP-API verification for gemeenteblad publication (Phase 2) runs through OC-sources for centralized auth/throttling.

## Risks

### Risk 1: Cost-price calculation scope (component definitions, allocation basis)

**Severity**: Medium

**Mitigation**: REQ-WMO-002 specifies components exactly (directe loonkosten, directe materialen, afschrijvingen, indirecte overhead via BBV-sleutel, vermogenskosten, winstopslag). Allocation basis is inherited from `bookkeeping-cost-centers-dimensions` (fixed-percentage, volume, headcount drivers). If an operator needs a custom component (e.g. customer-acquisition cost for tech startups), that is out of scope; ACM doesn't permit custom components in IKP — the law names these six. Custom drivers are handled per ADR-032 (operator files an OR issue for enum extension).

### Risk 2: ABB workflow and raad-besluit coupling (governance-system variability)

**Severity**: Medium

**Mitigation**: Phase 1 ABB stores `raadsBesluitId` as a freeform string (gemeente Apeldoorn might say "2025-184", another "RB-2025-001"). Phase 3 optionally integrates with `bookkeeping-governance` if available; otherwise freeform ID + manual link verification (2-eyes sign-off required to flip ABB to `geldig`). The spec does not assume a specific raadsinformatiesysteem (iBabs, NotuBiz, GO).

### Risk 3: Cross-subsidy detection false-positive rate (alert fatigue, remediation burden)

**Severity**: Low

**Mitigation**: REQ-WMO-007 detector runs monthly and flags specific scenarios (loss-financing 2+ consecutive periods, omzetgroei >25% yoy without IKP update, etc.). Alerts route to concerncontroller with 4-week escalation to gemeentesecretaris if unaddressed. Not all alerts require action (e.g. seasonal activities may spike in one quarter). Operator can mark alerts "reviewed, no action needed" with motivation logged to audit trail. The goal is *detection*, not automatic correction.

### Risk 4: ACM handhavings-pakket export completeness (audit trail indexing, consistency)

**Severity**: Medium

**Mitigation**: REQ-WMO-010 defines exact export format (manifest.json + indexed JSON + PDF's). The implementing cycle's code-review gate includes ACM-case-sample testing: export from a test gemeente, index it, cross-check against source data. Prior ACM cases (Eindhoven, Hilversum) took 22 months partly due to 8600 unsorted PDF's; this export takes seconds with machine-leesbare manifest.

### Risk 5: Multi-bestuursorgaan ABB requirement complexity (shared activity, asymmetric exemptions)

**Severity**: Low

**Mitigation**: REQ-WMO-011 specifies that a multi-deelnemer activity needs an ABB per deelnemer (if any deelnemer wants exemption). This is a known compliance trap (VNG 2023 found 41% of GR-ABB's miss it). The spec makes it explicit: the system SHALL flag if a multi-deelnemer activity has an ABB on only 1 deelnemer (alert: "exemption incomplete for n other deelnemers"). Remediating this is the gemeente's responsibility, but the system makes the gap visible.

## Rollback Strategy

Phase 1 is a **spec + minimal service change** (register declarations + 2–3 thin service classes for calculation/splitting). To roll back Phase 1: revert commit; manually unwind any seeded historical commercial activities (archived records can be left in place; queries filter on `lifecycleState`). After Phase 2/3, rollback is standard: revert PR, run migration rollback (if any data-migration was used). The registers are optional — if disabled in manifest, no UI entry appears and no background jobs fire.

## Open Questions

1. **BBV overhead-sleutel versioning** — WMO IKP inherits the BBV sleutel (taakveld 0.4) valid for the period. If the sleutel changes mid-year, does the IKP recalculate retroactively for prior months (yes, per GAAP, but implementation cost)? Settled in Phase 1 implementation-cycle planning.

2. **Governance system integration scope** — Which raadsinformatiesysteem(en) should Phase 3 prioritize? (iBabs most common in gemeenten; NotuBiz in some; GO in others.) Settled in Phase 3 requirement-refinement cycle.

3. **ACM portal direct-submission API timeline** — ACM is developing a submission API (2026/2027 horizon). Should Phase 2 ACM-export be designed to pre-stage for future direct API push? Yes, per requirement-refinement; export format SHALL be JSON/XML compatible with anticipated API schema.

4. **Market-benchmark sourcing** — REQ-WMO-012 names potential sources (offerte, prijslijst, branche-rapport, BDO Benchmark, COELO). Should shillinq integrate with any of these as a source (yes, but deferred to Phase 3 option). Phase 1–2 uses manual benchmark entry.

5. **Multi-administration segregation** — A waterschap housing 3 gemeenten (gemeenschappelijke regeling) operates commercial activities. Data MUST be segregated per deelnemer for ABB, IKP, ACM reporting. Confirmed in Phase 1: each deelnemer has own `administrationId`; splits and reporting are per `administrationId`. Multi-deelnemer is tracked at the CommercialActivity level with `deelnemers[]` array.
