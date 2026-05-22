# Design — Corporate Sustainability Reporting Directive (CSRD) / European Sustainability Reporting Standards (ESRS)

## Context

CSRD (Directive 2022/2464, in force January 2023) mandates sustainability reporting for large EU companies and non-EU groups with >EUR 150M EU turnover. ESRS (Commission Delegated Regulation 2023/2772) specifies 12 standards covering governance (ESRS-1, ESRS-2) and 10 topical areas (E1 Climate, E2 Pollution, E3 Water, E4 Biodiversity, E5 Circular Economy, S1 Own Workforce, S2 Value Chain Labour, S3 Communities, S4 Consumers, G1 Business Conduct).

The ESRS data model is unusually large: ~1,100 individual data points per period, each with configurable source (GL auto-link, external API, manual entry, supplier survey, proxy estimate), approval status (draft/in-review/approved/locked-for-assurance/assured), and audit trail. Double materiality assessment (company-impact-on-society × society-impact-on-company) determines which topical standards are in scope for the period; materiality matrix is signed off by the board and becomes governance record.

Scope 1/2/3 GHG emissions (E1 Climate) is the most data-intensive ESRS workload: emissions inventory with 15 Scope 3 categories, base-year recalculation rules, sensitivity analysis, factor versioning, uncertainty tagging. Supplier / customer value-chain data collection is mandatory. Audit-firm assurance is required (limited assurance year 1, reasonable assurance by FY2028).

Per ADR-031, the entire measurement model is declarative: schema metadata + lifecycle automation + aggregation formulas. No PHP calculation services (no GHGCalculator.php, no ImpactScorer.php). All quantitative logic is spreadsheet-style formula, computable at query time.

## Goals

- Express the entire CSRD/ESRS reporting surface as **declarative metadata** — schemas + lifecycle + aggregation formulas — per ADR-031.
- Make the spec a **CFO-readable contract** — Dutch RJ guidelines + ESRS regulation mapping recognisable end-to-end (materiality assessment, data collection, GHG calculation, assurance, disclosure).
- Enforce double materiality (qualitative + financial score) + Scope 1/2/3 inventory + limited-assurance audit readiness + XBRL submission without PHP audit logic.
- Support value-chain boundary extension (suppliers, customers, financial investees) with configurable data-collection methods (direct survey, industry average, supplier LCA, regulatory disclosure).
- Keep the GHG inventory calculation declarative so Scope 1/2/3 rolls are computable formulae, not hand-coded services.
- Make the system the lowest-cost CSRD/ESRS compliance route for Dutch MKB+ (avg. consulting cost EUR 100–500K → Shillinq all-in).

## Non-Goals

- No multi-scenario climate modelling (TCFD 4-scenario analysis, COSO frameworks). Those are optional downstream.
- No Scope 3 LCA granularity beyond spend-based EEIO. Detailed supplier-specific LCA is T4 phase.
- No governance workflow (board pre-approval, supervisory review). Those are `decidesk` integration, not this spec.
- No third-party ESG disclosure platform integration (Bloomberg, Sustainalytics, MSCI). Those are T4 connectors.
- No post-publication SFDR / EU Taxonomy detailed KPI alignment. Those are data-point values, not separate schemas.

## Decisions

### D1 — Ten registers: assessment, IRO, data point, inventory, emission source, value-chain actor, policy/action/target, assurance

The CSRD/ESRS model is decomposed into:
- **materiality-assessment**: root governance record per period. Attributes: reporting period, methodology version, scope (legal entity / consolidated group), stakeholder consultation evidence (array: group, method, date), impact-materiality threshold (1–5 scale), financial-materiality threshold (1–5 scale), double-materiality matrix snapshot (JSON: topic → impact/financial scores + material Y/N + rationale), approval date, approver, supporting documents, superseded-by (self-FK for restatement).
- **iro-record**: per-topic Impact/Risk/Opportunity. Attributes: assessment (FK), ESRS topic code (E1 / E1-1 / S1-1 / etc), type (impact-positive / impact-negative / risk / opportunity), description, value-chain location (own / upstream / downstream / financial), time horizon (short / medium / long), likelihood (1–5), severity (1–5), scope (1–5), irremediable character (boolean), financial-effect estimate (currency + range), impact score (computed), financial-materiality score (computed), material (boolean), mitigation actions (array: action / owner / due-date), rationale (text).
- **esrs-data-point**: one row per ESRS-mandated data point per period (~1,100 total across 12 standards). Attributes: period, ESRS data-point ID (e.g., `E1-6_GrossScope1GHGEmissions_tCO2eq`), value (typed: numeric / text / boolean / monetary / quantity), unit (UN/CEFACT code), value-source (manual / openconnector-pull / calculation / imported-from-bookkeeping / supplier-survey / proxy-estimate), source-reference (URI or doc reference), preparer (FK user), preparer-timestamp, reviewer (FK user), reviewer-timestamp, status (draft / in-review / approved / locked-for-assurance / assured), assurance-evidence (files), restated-from (self-FK if prior-period value restated), narrative-comment.
- **ghg-inventory**: GHG Protocol calculation engine. Attributes: period, methodology (GHG-Protocol-Corporate-Standard / GHG-Protocol-Value-Chain-Standard), consolidation-approach (equity-share / financial-control / operational-control), gases-included (array: CO2 / CH4 / N2O / HFCs / PFCs / SF6 / NF3), boundary-narrative, base-year, base-year-recalculation-policy, total-Scope-1, total-Scope-2-location-based, total-Scope-2-market-based, total-Scope-3 (object: 15 categories), intensity-metrics (object: tCO2e per FTE / per turnover / per m2 / per unit-produced), methodology-changes-vs-prior-period.
- **emission-source**: per-activity row feeding the inventory. Attributes: inventory (FK), scope (1 / 2 / 3-cat-N), category (stationary-combustion / mobile-combustion / fugitive / purchased-electricity / purchased-goods / capital-goods / fuel-and-energy / upstream-transport / waste / business-travel / employee-commuting / upstream-leased / processing-of-sold / use-of-sold / end-of-life / downstream-leased / franchises / investments), activity-data (numeric), activity-unit, activity-source-document (file or openconnector ref), emission-factor (numeric), emission-factor-source (DEFRA / EPA / IEA / supplier-specific / spend-based-EEIO), emission-factor-version, GWP-version (AR5 / AR6), CO2e-result, uncertainty-rating (high / medium / low), recalculation-flag.
- **value-chain-actor**: supplier / customer / portfolio-company. Attributes: counterparty (FK organisations), role (supplier / customer / financial-investee / franchisee), tier (1 / 2 / 3+), country, sector (NACE code), in-scope-for (array: ESRS standard codes), data-collection-method (direct-survey / industry-average / supplier-LCA / regulatory-disclosure), engagement-status (not-contacted / contacted / responded / partial-response / verified), last-engagement-date, data-quality-score (1–5).
- **esrs-policy**: policy declaration on a material topic. Attributes: owner (FK user), topic (ESRS code), scope (own / upstream / downstream / financial), time-horizon, measurement-metric (FK esrs-data-point), baseline-value, target-value, current-value, progress-narrative, links to IRO records.
- **esrs-action**: concrete time-bound intervention. Attributes: owner, topic, time-horizon, measurement-metric, baseline, target, current, progress-narrative, links to IRO records.
- **esrs-target**: measurable goal (preferably science-based). Attributes: owner, topic, time-horizon, measurement-metric, baseline, target, current, progress-narrative, scope, links to IRO records + esrs-action.
- **assurance-engagement**: auditor walkthrough. Attributes: period, audit-firm, lead-partner, engagement-level (limited / reasonable), scope-statement, materiality-threshold-quantitative, materiality-threshold-qualitative, walkthrough-records (files), test-of-controls-results (array), substantive-test-results (array), findings (array: ESRS-area / severity / description / management-response / status), assurance-report (file), opinion-date.

**Alternative considered**: Monolithic sustainability-report schema with all fields embedded. Rejected — multi-period roll-forward + per-topic sensitivity + value-chain engagement + GHG inventory require first-class records for drill-down, audit trail, and progressive approval.

### D2 — Double-materiality assessment mandatory before any topical disclosure

Every period MUST have a completed `materiality-assessment` record (status=approved, locked) before any topical disclosure is published. Assessment wizard enumerates ESRS topical taxonomy (pre-loaded as seed data), asks GIVEN/WHEN/THEN questions for impact materiality (scale × scope × likelihood × irremediable) and financial materiality (expected cash-flow / cost-of-capital effect), computes scores, maps material topics, and signs off matrix.

Stakeholder consultation is mandatory: at least one consultation method per consulted group (employees / customers / suppliers / investors / communities / civil society). Evidence is attached as document references (via docudesk).

Non-material topics require rationale (free-text justification exported in report).

**Alternative considered**: Materiality determination is optional; data points are collected for all topics. Rejected — ESRS mandate + EFRAG IG-1 guidance + Dutch RJ 271 explicitly require material determination + stakeholder consultation as governance checkpoint.

### D3 — Data-point source is declaratively configured; no magic sourcing logic

Each ESRS data point has an explicit **source configuration**:
- **manual**: user enters value + attaches source document (PDF, Excel screenshot, email).
- **openconnector-pull**: system auto-fetches from energy supplier, HRIS, fleet-management, CDP, Ecovadis API. Source URI logged.
- **calculation**: system computes from dependent data points (e.g., Scope 3 total = sum of 15 categories).
- **imported-from-bookkeeping**: GL line or invoice auto-links via `journal-entry-line.esrs-data-point` FK.
- **supplier-survey**: aggregated from supplier responses (CDP supply-chain, Ecovadis, Ulula).
- **proxy-estimate**: spend-based EEIO fallback when supplier-specific data unavailable. Uncertainty marked.

No automatic "smart sourcing" logic. Each point has explicit ownership (preparer FK, timestamp, status, reviewer FK + comment). Collection-progress dashboard shows % complete per ESRS standard + source-method breakdown.

**Alternative considered**: System auto-detects best-available source. Rejected — source choice is audit-critical; transparency + explicit decision log required.

### D4 — GHG calculation is declarative aggregation from emission-source rows

Scope 1/2/3 totals are computed on-demand from the active `emission-source` rows for the period + latest DEFRA/EPA/IEA emission factors (quarterly refresh via openconnector). Formula:
- Scope 1: sum(CO2e-result for scope=1 emission-source rows)
- Scope 2 location-based: sum(CO2e-result for scope=2 rows using grid-average factors)
- Scope 2 market-based: sum(CO2e-result for scope=2 rows using actual-contract factors)
- Scope 3: sum(CO2e-result for scope=3-cat-N rows, grouped by category 1–15)

Each row computes: CO2e-result = activity-data × emission-factor, with uncertainty rating (high / medium / low). Base-year recalculation is auto-triggered if ≥5% of base-year emissions added/removed (M&A, divestiture, methodology change, correction). Recalculation policy memo (rationale, scope change, factor version) is mandatory.

Scope 3 uses spend-based EEIO (Exiobase v3.8 or USEEIO) where supplier-specific LCA unavailable, with data-quality rating explicitly marked.

**Alternative considered**: PHP service (GHGCalculator) computes Scope 1/2/3. Rejected per ADR-031: calculation is formula-driven, not service logic.

### D5 — Assurance workflow: locked-for-assurance state (read-only to user, open to auditor)

Once a data point is `approved`, it can transition to `locked-for-assurance` (no further user edits, but auditor can annotate). Audit firm gets scoped role: read + annotation (add comments, flag issues, request evidence). Findings are tracked as array (ESRS-area / severity / description / management-response / status: open / resolved / accepted-risk).

Year-one: limited assurance (negative opinion: "nothing came to our attention suggests misstatement"). FY2028+: reasonable assurance (positive opinion: "material respects in accordance with ESRS").

Engagement-level enum: limited / reasonable. Audit firm must sign off all findings before opinion date. Assurance report (PDF) is attached; opinion date is locked.

**Alternative considered**: All data points stay editable during audit. Rejected — ISSA 5000 audit standard requires locked data for evidence footprint + ESRS mandate.

### D6 — Schema extensions to existing entities

Rather than new monolithic schemas, extend existing:
- **journal-entry-line** (from bookkeeping-general-ledger): add optional FK `esrs-data-point` so diesel-fuel invoices booked on account 6100 auto-populate mobile-combustion activity data.
- **fixed-asset** (from bookkeeping-fixed-assets-depreciation): add `is-biodiversity-sensitive-area-overlap` (boolean, computed from asset location vs Natura 2000 / Key Biodiversity Areas spatial overlay), `physical-climate-risk-rating` (1–5 scale, manual per site).
- **organisations** (entity master): add `nace-code` (classification), `country-of-operation` (array), `esrs-in-scope-for` (array of ESRS standard codes — materiality result per period).

**Alternative considered**: Create separate `asset-sustainability`, `gl-esrs-link` intermediate tables. Rejected — pollution + climate (E1/E2/E3/E4/E5) + labour (S1/S2) data naturally connect to existing GL, asset, and org records.

### D7 — XBRL submission: EFRAG ESRS taxonomy (2024 finalized, annual updates)

Final approved data points + narratives are mapped to EFRAG ESRS XBRL taxonomy (finalized 2024, updated annually by EFRAG / European Commission). System emits iXBRL-tagged PDF (Inline XBRL, embedded in annual-report PDF) for ESEF deposition to KvK + AFM.

Submission pipeline reuses `bookkeeping-sbr-xbrl-reporting` infrastructure, extended with EFRAG ESRS context + fact model. Validation against taxonomy schema before submission (fail on mandatory data points, warn on optional).

Taxonomy version is pinned in `ghg-inventory.esrs-taxonomy-version` (e.g., "EFRAG-ESRS-2024-01"). Backwards-compatible iXBRL generation.

**Alternative considered**: Proprietary CSV format. Rejected — ESRS / ESEF / European Single Electronic Format mandate XBRL, and auditor validation requires standards-compliant output.

### D8 — Restatement + immutable audit trail

Every change to an assured data point in a subsequent period is captured as a restatement:
- `esrs-data-point.restated-from` (self-FK to prior-period data point if value changed)
- Prior-period value, new value, rationale, approver, date logged
- Audit trail immutable (no deletion, only restatement)
- Exportable narrative for report notes (e.g., "FY2024 Scope 1 restated from 500 to 450 tCO2e due to Q4 fleet decommissioning; see amendment note 3.2").

### D9 — Lifecycle automation per x-openregister-lifecycle

- **materiality-assessment**: draft → in-review (stakeholder-consultation-complete) → approved (board-sign-off) → locked (published)
- **esrs-data-point**: draft → in-review (preparer-submitted) → approved (reviewer-OK) → locked-for-assurance (auditor-access, user-read-only) → assured (auditor-sign-off) → published
- **ghg-inventory**: draft → calculated (on-demand formula rollup) → approved (reviewer-OK) → locked-for-assurance → assured → published

State transition rules enforced by `x-openregister-lifecycle` + `bookkeeping-ccm-rule-engine` automated controls (e.g., no ESRS data point approved without source document).

## Reuse Analysis

| Entity | Prior Use | Reuse Rationale | Integration Points |
|---|---|---|---|
| journal-entry-line | GL posting | Diesel-fuel invoices → combustion emissions | FK `esrs-data-point` added |
| fixed-asset | Asset register | Location + climate risk | `is-biodiversity-sensitive-area-overlap`, `physical-climate-risk-rating` added |
| organisations | Entity master | Legal entity + scope | `nace-code`, `country-of-operation`, `esrs-in-scope-for` added |
| Account | GL chart | Invoice GL codes | FK link from `journal-entry-line` → emission-source |
| Person | User directory | Data preparer / reviewer / approver | FK links in workflow tables |

## Data Flow Diagram

```
1. Materiality Assessment (Board-governed)
   - Wizard: enumerate ESRS topics → impact/financial scores
   - Stakeholder consultation: collect evidence (docudesk)
   - Matrix sign-off → locked

2. Data Point Collection (Progressive approval)
   - Configure source per point: GL / openconnector / manual / survey / proxy
   - Preparer enters / auto-fetch → draft
   - Reviewer approves → approved
   - Lock for assurance (user read-only, auditor annotate-OK) → locked-for-assurance
   - Auditor sign-off → assured

3. GHG Calculation (On-demand aggregation)
   - Emission-source rows (GL-linked, manual, openconnector)
   - Emit factors (DEFRA/EPA/IEA quarterly refresh)
   - Compute Scope 1/2/3 = SUM(activity × factor) per category
   - Base-year recalc on ≥5% boundary change

4. Assurance Walkthrough (Auditor-driven)
   - Audit firm reads locked data points
   - Add findings (issue / severity / description)
   - Management response + status tracking
   - Assurance report + opinion sign-off

5. XBRL Submission (Auto-mapped)
   - Approved data points → EFRAG ESRS taxonomy facts
   - Generate iXBRL-tagged PDF
   - Validate against schema
   - Submit to KvK / AFM ESEF portal
```

## Seed Data

Pre-load (via openconnector + docudesk templates):
- ESRS topical taxonomy (12 standards + 35+ topic codes per EFRAG IG-1, updated annually)
- DEFRA UK conversion factors (annual release, ~500 emission factors)
- EPA eGRID (annual update, US regional factors)
- IEA emission factors database (annual update)
- IPCC AR5/AR6 GWP values
- Exiobase v3.8 EEIO matrix (Leiden University / CML)
- Natura 2000 + Key Biodiversity Areas spatial overlays (UNEP-WCMC WDPA)
- Sample materiality assessment template (Dutch + English)
- Sample policy/action/target templates per topical standard
- CDP supply-chain programme connectors (API credentials template)
- Ecovadis + Worldfavor adapter templates
