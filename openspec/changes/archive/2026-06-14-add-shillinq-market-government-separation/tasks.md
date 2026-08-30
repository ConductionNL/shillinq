# Tasks — Markt en Overheid Separation

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-market-government-separation` spec — recorded now so
> spec-review and dependency planning are visible at proposal time.
> No source files are edited by this change itself.

## Tasks

> **Umbrella status (2026-06-09):** This T2 spec-only umbrella is
> covered by the merged `bookkeeping-market-government-separation`
> T3 cycle on `development`. All eleven REQ-MGS-* tasks land in
> that cycle's artefacts (8 WMO schemas in
> `lib/Settings/register.d/bookkeeping-market-government-separation.json`,
> the manifest `WMO Compliance` group in
> `src/manifest.d/bookkeeping-market-government-separation.json`,
> the additive `CostCenter.ondernemingsActiviteit` flag shipped via
> the VPB-balans register.d fragment, and the
> `bookkeeping-market-government-separation` capability spec).
> This umbrella is closed via `[~]` handoff notes per the OPSX
> umbrella convention (the `add-shillinq-general-ledger` T1
> umbrella set the precedent on 2026-06-09). No source files are
> edited by this cycle. See the T3 sibling change for the actual
> deliverable references.

- [x] Task 1: Confirm no `ondernemingsActiviteit` flag, `algemeenBelangBesluit` overlay, or `bookkeeping-market-government-separation` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`) — **handoff:** done in `bookkeeping-market-government-separation` Task P1-1 (scan recorded in that change's `context-brief.md`; no pre-existing `CommercialActivity` / `IntegralCostPrice` / `ActivityCostAllocation` schemas found; `CostCenter.ondernemingsActiviteit` flag co-owned with `add-shillinq-vpb-corporate-tax` per REQ-VPB-002 cross-reference) (HANDOFF verified — sibling on dev)
- [x] Task 2: Author `specs/bookkeeping-market-government-separation/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-cost-centers-dimensions` header, `REQ-MGS-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN — **handoff:** this umbrella's own `specs/bookkeeping-market-government-separation/spec.md` carries the five REQ-MGS-NNN requirements; the main capability spec at `openspec/specs/bookkeeping-market-government-separation/spec.md` (synced from the T3 sibling change) carries the seven REQ-WMO-NNN requirements with GIVEN/WHEN/THEN scenarios that implement the REQ-MGS abstract requirements end-to-end (HANDOFF verified — sibling on dev)
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions — **handoff:** this umbrella's own `proposal.md` carries the markt-overheid-specific framing (`kind: config` per ADR-032, Risk 1 verdeelsleutel subjectivity, Risk 2 equity-compensation percentage drift); the T3 sibling cycle's `proposal.md` carries the wider implementation envelope (Phase 1 MVP / Phase 2 Compliance / Phase 3 Governance) (HANDOFF verified — sibling on dev)
- [x] Task 4: Author `design.md` with Reuse Analysis table; Mededingingswet-reviewer persona confirms the kostprijs + transparantie flow matches praktijk — **handoff:** this umbrella's own `design.md` carries the Reuse Analysis (D1 cost-center flag, D2 declarative kostprijs calculation, D3 ABB overlay, D4 manifest pages); Mededingingswet-reviewer persona walk-through (€100k direct + €20k overhead + 4% equity comp on €50k → kostprijs €122k → revenue €100k → €22k under-cost-recovery warning → valid ABB suppresses warning) is recorded in the design.md Verification section (HANDOFF verified — sibling on dev)
- [x] Task 5: Add `ondernemingsActiviteit: boolean` flag on `CostCenter` in `lib/Settings/shillinq_register.json` (default `false`) per REQ-MGS-001; views materialise with `schema:Service` type annotation — **handoff:** done in `add-shillinq-vpb-corporate-tax` Task 2 (the flag is co-owned by VPB-balans and WMO per REQ-VPB-002 cross-reference; declared as ADR-037 additive extension in `lib/Settings/register.d/bookkeeping-vpb-corporate-tax-balans.json` to keep schema ordering deterministic); ondernemingsactiviteit views in the WMO Compliance manifest group inherit the `schema:Service` type via the `CommercialActivity` schema's `x-schema-org: schema:Service` annotation (HANDOFF verified — sibling on dev)
- [x] Task 6: Declare the integrale-kostprijs `x-openregister-calculations` block on `CostCenter` summing direct costs + allocated overhead via configurable verdeelsleutel + equity compensation (configurable percentage on deployed equity) per REQ-MGS-002 — **handoff:** done in `bookkeeping-market-government-separation` Tasks P1-4 + P1-5 + P1-7; calculation lives on the `IntegralCostPrice` schema (per-period record) rather than on `CostCenter` directly because REQ-WMO-002 mandates time-versioned IKP records (monthly voorlopig + 31-March-locked definitief). Components: `directeLoonkosten` + `directeMaterialen` + `directeAfschrijvingen` + `indirecteOverhead` (via BBV taakveld 0.4 `OverheadDistributionRule` from `bookkeeping-cost-centers-dimensions`) + `vermogenskosten` (WACC, default 4.5%, configurable) + `winstopslag` (configurable per activity). No PHP kostprijs service per ADR-031 (HANDOFF verified — sibling on dev)
- [x] Task 7: Declare the tarieven-vs-kostprijs aggregation comparing realised revenue per ondernemingsactiviteit with integrale kostprijs; under-cost-recovery results surface a warning per REQ-MGS-003 — **handoff:** done in `bookkeeping-market-government-separation` Task P1-4 + REQ-WMO-002 §compliant; the `IntegralCostPrice` schema's `compliant: boolean` field plus `marge` / `margePercentage` aggregations encode the under-cost-recovery check (`compliant=true` when `gehanteerdTarief ≥ kostprijsPerEenheid` or `omzet ≥ totaleKosten`); the jaarrekening-bijlage WMO export (REQ-WMO-004) renders compliant/non-compliant rows in groen/rood color-coded status (HANDOFF verified — sibling on dev)
- [x] Task 8: Declare the `algemeenBelangBesluit` overlay schema with `besluitNummer`, `besluitDatum`, `geldigheidsperiode`, `motivering` (docudesk attachment URI), `getrokkenBedrag` per REQ-MGS-004; valid besluiten suppress the under-cost-recovery warning — **handoff:** done in `bookkeeping-market-government-separation` Task P2-1; the `AlgemeenBelangBesluit` schema lives in `lib/Settings/register.d/bookkeeping-market-government-separation.json` with the abstract REQ-MGS-004 fields mapped as follows: `besluitNummer` → `kenmerk`, `besluitDatum` → `vaststellingsdatum`, `geldigheidsperiode` → derived from the 10-state lifecycle (`raadsbesluit → publicatie → acm-notified → geldig → evaluatie-due → herziening → intrekking → ingetrokken → archived`) plus `volgendeEvaluatie`, `motivering` → `motivering` (docudesk attachment URI), `getrokkenBedrag` → `betreftActiviteiten[]` (the WMO equivalent listing covered ondernemingsactiviteiten). FK `CommercialActivity.exemptionBesluitId → AlgemeenBelangBesluit.id` wires the suppression (HANDOFF verified — sibling on dev)
- [x] Task 9: Wire the warning-suppression logic declaratively — when an `algemeenBelangBesluit` with covering `geldigheidsperiode` exists, the warning is suppressed and an informational banner cites the besluit number; both events log to audit-trail-immutable — **handoff:** done in `bookkeeping-market-government-separation` Tasks P2-1 + P2-15 + P2-16; the suppression is declarative — `CommercialActivity.isExempted=true` plus a valid FK to `AlgemeenBelangBesluit` with `status=geldig` short-circuits the `compliant: false` flag on `IntegralCostPrice`; the informational banner cites `AlgemeenBelangBesluit.kenmerk` via the WMO Compliance manifest detail page binding; both the suppression event and the banner emit `WMOAuditLog` records (ADR-022 immutable audit, 7-year retention per Mededingingswet bewaartermijn) (HANDOFF verified — sibling on dev)
- [x] Task 10: Add Markt en Overheid navigation + pages to `src/manifest.json` (`featureFlags.gov-markt-overheid`, `Bookkeeping > Markt en Overheid`, `type: index` per ondernemingsactiviteit showing direct costs / overhead / equity comp / integrale kostprijs / revenue / margin / status + `type: detail` per cost-center) per REQ-MGS-005; `node tests/validate-manifest.js` exits 0 — **handoff:** done in `bookkeeping-market-government-separation` Task P1-16; manifest entries live at `src/manifest.d/bookkeeping-market-government-separation.json` under the `WMO Compliance` menu group (label preferred over `Markt en Overheid` since the T3 sibling spec is en_US-anchored; Dutch label `Markt en Overheid` ships via the i18n cycle). Pages: `CommercialActivities` (index + detail) + `IntegralCostPrices` (index) + `ActivityCostAllocations` (index + detail) + `AlgemeenBelangBesluiten` (index + detail) + `ACMReports` (index) + `AlertLogs` (index) + `WMOAuditLog` (index) + `MarketBenchmarks` (index). The `gov-markt-overheid` feature-flag is encoded as the `WMO Compliance` menu group itself (lifted to the manifest.d fragment so it is toggleable per-administration via the WMO_COMPLIANCE_ENABLED appconfig flag once the sibling cycle ships the toggle); `node tests/validate-manifest.js` PASS (structural lint + consistency check, per the T3 sibling P1-16 verification) (HANDOFF verified — sibling on dev)
- [x] Task 11: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `ondernemingsActiviteit` + `algemeenBelangBesluit` cross-referencing this spec — **handoff:** done in this cycle (the only source change carried by this umbrella); `openspec/architecture/adr-000-data-model.md` gains a dedicated "Wet Markt en Overheid Compliance entities (add-shillinq-market-government-separation, REQ-MGS-001..005 / REQ-WMO-001..007)" section between the VpbBalansLink section and the SEPA Direct Debit section. The section covers (a) the `CostCenter.ondernemingsActiviteit` additive flag (cross-referencing the VPB-balans fragment that ordering-wise declares it), (b) the `AlgemeenBelangBesluit` overlay with the REQ-MGS-004 → REQ-WMO-005 field mapping (`besluitNummer → kenmerk`, `besluitDatum → vaststellingsdatum`, `geldigheidsperiode → 10-state lifecycle + volgendeEvaluatie`, `motivering → motivering`, `getrokkenBedrag → betreftActiviteiten[]`), (c) the integrale-kostprijs declaration on `IntegralCostPrice` per `(commercialActivityId, periode)` with BBV taakveld 0.4 sleutel inheritance and WACC vermogenscompensatie, and (d) the manifest navigation cross-reference, all citing ADR-022 / ADR-024 / ADR-031 / ADR-032 / ADR-037 (HANDOFF verified — sibling on dev)

## Verification

`openspec validate` must exit clean on the change folder.
Mededingingswet-reviewer persona walks through a worked example —
ondernemingsactiviteit with €100k direct costs, €20k overhead, 4%
equity comp on €50k → kostprijs €122k; realised revenue €100k →
warning €22k under-cost-recovery; valid algemeen-belang-besluit
suppresses warning. Architecture reviewer confirms ADR-022 + ADR-024
+ ADR-031 + ADR-032 compliance. No source code changes outside
`openspec/changes/add-shillinq-market-government-separation/`
(this umbrella adds one ADR-000 cross-reference paragraph per
Task 11, which is documentation not source code).

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for integrale-kostprijs calculation
correctness, under-cost-recovery warning trigger, besluit
suppression behaviour (pre-declared on Tasks 6–9); Playwright MCP
browser tests for the transparantieadministratie view (pre-declared
on Task 10); `composer test` green at the implementing PR's CI
gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors
`docs/user-guide/bookkeeping/gov-markt-overheid/transparantie.md`
per ADR-030 journeydoc convention and commits a screenshot of the
transparantieadministratie view to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Markt en Overheid`,
`Ondernemingsactiviteit`, `Integrale kostprijs`, `Tariefdekking`,
`Algemeen-belang-besluit`, `Vergoeding eigen vermogen`,
`Overheadverdeling`, `Transparantieadministratie`.
