# Specification: bookkeeping-csrd-esrs

| Property | Value |
|----------|-------|
| **Status** | proposed |
| **Scope** | shillinq |
| **Tier** | T3 (regulatory + compliance) |
| **App** | shillinq |
| **Depends on** | bookkeeping-general-ledger, bookkeeping-fixed-assets-depreciation, bookkeeping-sbr-xbrl-reporting, bookkeeping-ccm-rule-engine, organisations |
| **Depends on (external)** | openconnector (dozen+ adapters), docudesk, decidesk (nice-to-have v1) |
| **Kind** | config (10 new registers, 3 schema extensions, lifecycle automation) |

## Overview

This specification defines the **Corporate Sustainability Reporting Directive (CSRD) / European Sustainability Reporting Standards (ESRS)** capability for Shillinq. It covers:

1. **Double-materiality assessment** — GIVEN/WHEN/THEN wizard to determine which ESRS topical standards (E1–E5, S1–S4, G1) are material for the reporting period.
2. **Data-point collection** — ~1,100 ESRS-mandated data points per period, each with configurable source (GL auto-link, openconnector API, manual entry, supplier survey, proxy estimate) and progressive approval workflow (draft → in-review → approved → locked-for-assurance → assured).
3. **GHG Scope 1/2/3 inventory** — Emissions calculation engine using DEFRA/EPA/IEA factors, with 15 Scope 3 categories, spend-based EEIO fallback, base-year recalculation rules.
4. **Assurance engagement** — Limited-assurance audit workflow (year 1) escalating to reasonable assurance (FY2028+), with findings tracking + management response.
5. **XBRL submission** — Auto-mapping to EFRAG ESRS taxonomy and iXBRL-tagged PDF generation for KvK/AFM deposition.
6. **Restatement + audit trail** — Immutable change log with restated-value tracking and exportable narrative.

## Requirements

### REQ-CSR-001: Materiality Assessment Governance

**Title:** Double-materiality determination (impact materiality × financial materiality)

**Description:**
Every CSRD-in-scope entity MUST complete a `materiality-assessment` record per fiscal period before publishing any topical disclosure. The assessment wizard enumerates the ESRS topical taxonomy (pre-loaded as seed data: E1 Climate, E2 Pollution, E3 Water, E4 Biodiversity, E5 Circular Economy, S1 Own Workforce, S2 Value Chain Labour, S3 Affected Communities, S4 Consumers, G1 Business Conduct, plus sub-topics per EFRAG IG-1). For each topic, the system MUST ask:

- **Impact materiality questions**: Does the company's activity materially impact the topic's environment/society dimension? (Scored: scale 1–5 × scope 1–5 × likelihood 1–5 × irremediable-character binary = composite impact score)
- **Financial materiality questions**: Does the topic materially impact the company's cash flow, cost of capital, or development? (Scored: 1–5 per financial dimension)

**Stakeholder consultation is mandatory**: The entity MUST document consultation with at least one of: employees, customers, suppliers, investors, adjacent communities, civil-society organizations. Evidence (date, method, participants, outcomes) MUST be attached.

A `materiality-assessment` record MUST NOT transition to `approved` until:
1. All ESRS topics have been assessed (impact + financial scores recorded).
2. Stakeholder consultation evidence is attached for at least one group.
3. Non-material topics include a written rationale (free text, exported in report).
4. The double-materiality matrix (JSON: topic → impact-score / financial-score / material Y/N / rationale) is locked.
5. Board-level approver (CFO or chair) has signed off (approver FK + approvedAt timestamp).

#### Scenario: Materiality Assessment Workflow

**GIVEN:**
- A Dutch BV (250+ employees, EUR 50M turnover) is in-scope for CSRD wave 2, FY2025.
- The entity has no prior ESRS materiality assessment.

**WHEN:**
- CFO initiates a `materiality-assessment` record (status=draft, period=FY2025).
- The wizard displays all 12 ESRS topics.
- For E1 Climate Change: CFO answers impact questions (Scope 1 emissions ~500 tCO2e → score=3; Scope 3 from supply chain ~2000 tCO2e → score=4; scope of suppliers ~30% → 4; likelihood high → 5; irremediable=yes). Impact composite = 4.
- For E1 Climate: CFO answers financial questions (transition risk moderate → 3; physical risk low → 2; cost-of-capital effect medium → 3). Financial composite = 3.
- E1 Climate is marked material (both impact ≥3 and financial ≥2).
- For S4 Consumers: CFO answers impact questions (product safety incidents: none → 1) + financial (no market impact → 1). S4 marked non-material; rationale: "Niche B2B chemical distribution; no end-consumer health/safety events in past 3 years; customer contracts include SDS/safety data."
- CFO logs stakeholder consultation: employees (survey Sept 2025, 150 respondents, output: water/waste top concerns), suppliers (email outreach Oct 2025, 8 responses, output: pay-on-time priority).
- Board approves assessment (approver=CFO, approvedAt=2025-10-15). Status → approved.
- Matrix is locked (status=locked, published to ESRS report).

**THEN:**
- Topical-standard modules activate for E1, E2 (water from suppliers), E5 (waste), S1 (employee data), S2 (supplier labour), G1 (pay-on-time metric).
- S3, S4 remain deactivated (out of scope).
- Data-point collection dashboard shows only active topical modules.

### REQ-CSR-002: ESRS Data-Point Collection Engine

**Title:** Configurable source collection for ~1,100 ESRS-mandated data points per period

**Description:**
An `esrs-data-point` record MUST exist for every ESRS-mandated data point per reporting period. Per EFRAG IG-3, there are ~1,100 data points across 12 standards. Each data point MUST have:

- **ESRS ID** (immutable): e.g., `E1-6_GrossScope1GHGEmissions_tCO2eq` (Directive reference + metric code).
- **Value** (typed): numeric / text / boolean / monetary / quantity, depending on the standard.
- **Unit** (UN/CEFACT code): e.g., `ton CO2 equivalent` (tCO2e), `headcount` (H87), `EUR` (E12).
- **Source configuration** (one per point):
  - `manual`: user enters + mandatory source document (PDF, Excel, email). Preparer & timestamp recorded.
  - `openconnector-pull`: system fetches from configured API (energy supplier, HRIS, fleet system, CDP, Ecovadis). Source URI + pull timestamp recorded.
  - `calculation`: system computes from formula (e.g., Scope 3 total = SUM of 15 categories). Dependent-point FKs recorded.
  - `imported-from-bookkeeping`: GL line auto-linked via `journal-entry-line.esrs-data-point` FK. GL account + line-item FK recorded.
  - `supplier-survey`: aggregated from supplier responses (CDP supply-chain programme, Ecovadis, Worldfavor, Ulula). Response-count + data-quality-score recorded.
  - `proxy-estimate`: spend-based EEIO when supplier-specific data unavailable. Uncertainty-rating (high/medium/low) mandatory.

Each data point MUST progress through the workflow:
1. **draft**: preparer enters / auto-fetches value.
2. **in-review**: preparer submits for review; reviewer assigned.
3. **approved**: reviewer confirms (signature + comment optional). Source-doc attachment mandatory if status is to advance.
4. **locked-for-assurance**: user can no longer edit; read-only to entity; open to audit firm (annotation OK).
5. **assured**: auditor has signed off (no findings, or findings resolved). Data point is locked.

A data point MUST NOT transition from draft to in-review unless `value` is populated. MUST NOT transition to approved unless source-reference (document, URI, GL link) is present.

Collection-progress dashboard MUST display:
- Percent-complete per ESRS topic (E1–E5, S1–S4, G1).
- Source-method breakdown (GL, openconnector, manual, survey, proxy).
- At-risk flags (overdue items, missing source docs).

#### Scenario: E1 Climate — Scope 1 GHG Emissions Data Point

**GIVEN:**
- Entity has completed materiality assessment; E1 Climate is material.
- FY2025 reporting period is active.
- Entity's GL has account 6150 (fuel for company vehicles, EUR 45,000 invoice total FY2025).

**WHEN:**
- Prepare creates `esrs-data-point` with ID `E1-6_GrossScope1GHGEmissions_tCO2eq` (status=draft).
- Preparer chooses source=`imported-from-bookkeeping` (GL auto-link).
- System queries GL account 6150; finds 12 monthly invoices from Shell, BP, total 45,000 EUR.
- Preparer configures conversion: GL account 6150 maps to `Scope 1 - mobile combustion`, uses DEFRA factor 2.31 kg CO2e per litre.
- System auto-calculates: 45,000 EUR ÷ avg price EUR 1.40/L ≈ 32,143 L → 32,143 × 2.31 = 74,250 kg = 74.25 tCO2e.
- Preparer enters this value, adds comment "Shell + BP fleet fuel, FY2025 invoices; factor: DEFRA 2025 version."
- Preparer submits (status=in-review, assigned-to=Controller).
- Controller reviews, sees GL line items, confirms value. Approves (status=approved, reviewed-by=Controller, reviewed-at=2026-02-15).
- On 2026-03-01, value is locked-for-assurance (user read-only, auditor can access).
- Auditor retrieves GL lines, spot-checks invoice details (3 of 12 sampled), confirms fuel prices within historical range. Adds annotation: "Tested 3 invoices; vehicle odometer records not reviewed (scope limited)." Approves (status=assured, auditor-sign-off=Partner, opinion-date=2026-04-15).

**THEN:**
- Scope 1 emissions value is 74.25 tCO2e, locked, assured.
- GHG inventory aggregate includes this value in Scope 1 total.
- Audit trail shows: draft → approved (Controller) → locked-for-assurance → assured (Partner).
- Restatement path: if FY2026 data shows additional Q4 fleet decommissioning, preparer can restate FY2025 to 70 tCO2e (restated-from link, rationale, approver).

### REQ-CSR-003: GHG Scope 1/2/3 Inventory Calculation

**Title:** On-demand emissions aggregation from emission-source rows using standard factors

**Description:**
A `ghg-inventory` record per reporting period MUST aggregate Scope 1, Scope 2 (location-based + market-based), and Scope 3 (15 categories) emissions from all active `emission-source` rows for the period.

Each `emission-source` row MUST include:
- **Scope** (enum): 1 / 2 / 3-cat-01 to 3-cat-15 (Scope 3 categories: purchased-goods, capital-goods, fuel-and-energy, upstream-transport, waste, business-travel, employee-commuting, upstream-leased, processing-of-sold, use-of-sold, end-of-life, downstream-leased, franchises, investments).
- **Category** (string): e.g., "mobile-combustion", "purchased-electricity", "employee-commuting".
- **Activity data** (numeric): quantity in standard units (litres, kWh, km, headcount-days, EUR spend).
- **Activity unit** (UN/CEFACT code): L / kWh / km / H87 / E12 (EUR) / etc.
- **Emission factor** (numeric): tCO2e per unit (e.g., 2.31 kg CO2e per litre diesel, 0.385 kg CO2e per kWh grid-average, etc.).
- **Emission factor source** (enum): DEFRA / EPA / IEA / supplier-specific / spend-based-EEIO.
- **Emission factor version** (string): e.g., "DEFRA-2025-Q1", "EPA-eGRID-2023", "Exiobase-3.8".
- **GWP version** (enum): AR5 / AR6 (IPCC Global Warming Potential standard; required for comparability).
- **Uncertainty rating** (enum): high / medium / low (for Scope 3 proxy estimates, typically "high").
- **Recalculation flag** (boolean): true if this row is a restatement of prior-period base-year emissions (M&A, divestiture, methodology change, correction).

`ghg-inventory` record MUST compute:

**Scope 1 (direct emissions):**
- `total-Scope-1` = SUM(activity × emission-factor) for all scope=1 rows
- Categories: stationary-combustion, mobile-combustion, fugitive emissions

**Scope 2 (purchased energy):**
- `total-Scope-2-location-based` = SUM(activity × grid-average-factor) for scope=2 rows using regional grid averages
- `total-Scope-2-market-based` = SUM(activity × contract-specific-factor) for scope=2 rows using actual contract residual-mix / RE certificates
- Mandatory disclosure: both location-based + market-based per GHG Protocol Standard

**Scope 3 (value-chain emissions):**
- `total-Scope-3` object with 15 categories:
  - 01: Purchased Goods & Services
  - 02: Capital Goods
  - 03: Fuel & Energy Related Activities
  - 04: Upstream Transportation & Distribution
  - 05: Waste Generated in Operations
  - 06: Business Travel
  - 07: Employee Commuting
  - 08: Upstream Leased Assets
  - 09: Downstream Transportation & Distribution (NOT INCLUDED in Dutch CSRD scope)
  - 10: Processing of Sold Products (NOT INCLUDED)
  - 11: Use of Sold Products (NOT INCLUDED)
  - 12: End-of-Life of Sold Products (NOT INCLUDED)
  - 13: Downstream Leased Assets
  - 14: Franchises
  - 15: Investments

For Scope 3 data unavailable at supplier level, system MUST compute using spend-based EEIO (Exiobase v3.8 or USEEIO):
- `activity-data` = supplier spend (EUR)
- `emission-factor` = EEIO intensity (kg CO2e per EUR spend, by sector / NACE code)
- `uncertainty-rating` = "high" (±50% typical range)

**Intensity metrics** (per `ghg-inventory.intensity-metrics` object):
- tCO2e per FTE (headcount from HR system or fixed headcount)
- tCO2e per EUR turnover
- tCO2e per m² of floor space (facility data)
- tCO2e per unit of production (e.g., per tonne of goods produced)

**Base-year recalculation rule:**
If ≥5% of base-year Scope 1 + Scope 2 emissions is added/removed in the current period due to M&A, divestiture, scope change (e.g., moved manufacturing), or methodology change, system MUST trigger base-year recalculation:
- Prior base-year Scope 1 + Scope 2 value must be restated
- Recalculation policy memo (scope change, reason, factor version, calculation method) MUST be attached
- Audit trail MUST document: original base-year value, restated value, rationale, approver

#### Scenario: GHG Inventory Aggregation (E1 Climate)

**GIVEN:**
- Entity has 250 employees, EUR 75M annual turnover, 3 office locations (Amsterdam 500 m², Eindhoven 300 m², warehouse 2000 m²).
- FY2025 emission sources entered: Scope 1 (fuel), Scope 2 (grid electricity + renewable certs), Scope 3 (supplier goods, employee commuting, business travel).

**WHEN:**
- System rolls `ghg-inventory` record for FY2025 on 2026-01-31 (draft status).
- Queries all emission-source rows for FY2025, groups by scope.
- **Scope 1**: 32 sources (monthly fuel purchases, 800 L/month). Factor: DEFRA 2.31 kg CO2e/L. Computes: 12 × 800 × 2.31 / 1000 = 22.32 tCO2e.
- **Scope 2 location-based**: 85,000 kWh grid electricity. Factor: Dutch grid average 0.385 kg CO2e/kWh (2025 DEFRA). Computes: 85,000 × 0.385 / 1000 = 32.73 tCO2e. (Additional 12,000 kWh solar self-generation deducted: 0 tCO2e.)
- **Scope 2 market-based**: 85,000 kWh grid electricity − 20,000 kWh renewable certs purchased. Market-based factor (residual mix) 0.620 kg CO2e/kWh. Computes: 65,000 × 0.620 / 1000 = 40.30 tCO2e.
- **Scope 3-01 (Purchased Goods)**: Supplier spend EUR 8.2M (office supplies, chemicals, packaging). No supplier-specific data available. Uses Exiobase spend-based EEIO: EUR 8.2M × 0.18 kg CO2e/EUR (weighted sector average) = 1,476 tCO2e. Uncertainty: high.
- **Scope 3-04 (Upstream Transport)**: Supplier invoices for incoming freight EUR 450K. Factor: 0.25 kg CO2e/EUR (transport sector). Computes: 450K × 0.25 / 1000 = 112.5 tCO2e.
- **Scope 3-06 (Business Travel)**: 450 flights booked (avg 1,200 km, domestic 0.255 kg CO2e/km, intl 0.127 kg/km). Computes: 300 domestic × 1,200 × 0.255 / 1000 + 150 intl × 1,200 × 0.127 / 1000 = 91.8 + 22.9 = 114.7 tCO2e.
- **Scope 3-07 (Employee Commuting)**: 250 employees, avg 40 km round-trip, 225 working days/year, mixed transport (60% car 0.17 kg CO2e/km, 30% transit 0.04, 10% bike 0). Computes: 250 × 225 × 40 × (0.6×0.17 + 0.3×0.04) / 1000 = 308 tCO2e.
- **Total Scope 3** = 1476 + 112.5 + 114.7 + 308 + others = ~2,100 tCO2e (preliminary, pending supplier survey responses for categories 01–02).
- **Intensity metrics**: 
  - tCO2e per FTE: (22.32 + 32.73 + 40.3 + 2100) / 250 = 8.6 tCO2e/FTE
  - tCO2e per EUR turnover: (22.32 + 32.73 + 40.3 + 2100) / 75M = 0.0281 kg CO2e per EUR
- System sets status=draft, assigned-to=Sustainability Manager for review.
- Manager reviews: notes high uncertainty on Scope 3-01 (1,476 tCO2e); decides to launch supplier survey (CDP supply-chain programme) to reduce uncertainty. Adds comment: "Survey invitations sent to top 10 suppliers (80% of spend); target responses by 2026-02-28."
- On 2026-03-15, survey responses received (6 of 10). System re-rolls Scope 3-01 using supplier-specific data where available, proxy for non-responders. Scope 3-01 revised: 980 tCO2e (data-quality-score: medium). Scope 3 total = 1,604 tCO2e.
- Manager approves inventory (status=approved, reviewed-by=Manager, reviewed-at=2026-03-15).
- On 2026-04-01, inventory is locked-for-assurance. Auditor accesses, spot-checks sample emission sources (Scope 1 fuel invoices, Scope 2 meter readings). Adds: "Tested 3 fuel invoices (match GL); meter readings not independently verified (scope limited to limited assurance)." Approves (status=assured).

**THEN:**
- GHG inventory finalized: Scope 1: 22.32, Scope 2 LB: 32.73, Scope 2 MB: 40.30, Scope 3: 1,604 total tCO2e.
- Intensity metrics: 8.6 per FTE, 0.0281 per EUR turnover.
- Disclosure in ESRS report: "Total GHG emissions FY2025 2,195 tCO2e (Scope 1 + 2 market-based + 3 estimated). Scope 3 includes supplier survey responses (6 of 10 top suppliers) and spend-based EEIO proxy for non-respondents (data-quality: medium). Uncertainty primarily in Scope 3 Purchased Goods (spend-based proxy; ±50% typical range)."

### REQ-CSR-004: Assurance Engagement Workflow (Limited → Reasonable)

**Title:** Auditor access to locked-for-assurance data points; findings tracking; opinion formation

**Description:**
An `assurance-engagement` record per reporting period MUST document the external auditor's limited-assurance (year 1) or reasonable-assurance (FY2028+) engagement.

Attributes required:
- **period** (FK): reporting period (FY2025, FY2026, etc.)
- **audit-firm** (name): e.g., "Deloitte Netherlands"
- **lead-partner** (name): e.g., "Jan de Vries"
- **engagement-level** (enum): limited / reasonable
- **scope-statement** (text): e.g., "Limited assurance of ESRS data points E1-6 (Scope 1/2/3 GHG), S1-1 (headcount), E3-5 (water withdrawal) per ISSA 5000 limited-assurance level."
- **materiality-threshold-quantitative** (EUR or tCO2e): e.g., "EUR 1M or 5% of turnover for ESRS-2 governance disclosures; 5% of total Scope 1+2 GHG for E1 Climate"
- **materiality-threshold-qualitative** (text): e.g., "Any data-quality change from prior period; any methodology change; any new material topic; board-governance changes"
- **walkthrough-records** (files): meeting notes, documentation requests, response memos (docudesk links)
- **test-of-controls-results** (array): [{description, objective, control-tested, result, finding-if-any}, …]
- **substantive-test-results** (array): [{esrs-data-point-id, objective, sample-size, sample-method, result, finding-if-any}, …]
- **findings** (array): [{esrs-area (E1/E2/etc), severity (critical/high/medium/low), description, management-response, status (open/resolved/accepted-risk)}, …]
- **assurance-report** (file): PDF signed by lead partner
- **opinion-date** (date): date auditor signs opinion

Workflow:
1. System creates `assurance-engagement` record (status=draft) on audit engagement start date.
2. Once entity's last `esrs-data-point` for the period is in status=approved, entity MUST explicitly lock all points for assurance (status→locked-for-assurance). At this point, user edits are forbidden; auditor gets read access + annotation capability.
3. Auditor firm (scoped role: `assurance-auditor`) accesses locked data points via dedicated UI:
   - View data point value, source reference, preparer comment, reviewer approval.
   - Add findings (issue / description / severity / expected-management-response).
   - Request additional evidence (GL lines, supplier survey responses, board minutes) via docudesk.
4. Entity management responds to findings (add comment, provide evidence, propose corrective action). Finding status: open → resolved (accepted) or accepted-risk (noted but not corrected, with rationale).
5. Auditor reviews management responses. If satisfied, finding status → resolved. If not, escalates to partner for decision.
6. Once all findings are resolved/accepted, partner signs off assurance report + opinion date.
7. Status → assured. Report is locked; no further edits permitted by either entity or auditor.

**Limited assurance (year 1, ISSA 5000 level 1-2):** Procedures designed to obtain moderate assurance; negative opinion: "Nothing has come to our attention that causes us to believe the ESRS data points are not presented fairly, in all material respects, in accordance with ESRS." Focused on key controls + substantive testing of material points.

**Reasonable assurance (FY2028+, ISSA 5000 level 3):** Procedures designed to obtain high assurance; positive opinion: "We are of the opinion that the ESRS data points and disclosures are presented fairly, in all material respects, in accordance with ESRS." More extensive substantive testing, control testing, and analytics.

#### Scenario: Limited-Assurance Walkthrough (E1 Climate)

**GIVEN:**
- Entity FY2025 ESRS data points are all status=approved (Feb 2026).
- Audit firm (Deloitte) is appointed for limited assurance (contract signed Jan 2026).
- GHG inventory shows Scope 1+2 = 55.05 tCO2e, Scope 3 = 1,604 tCO2e.

**WHEN:**
- System creates `assurance-engagement` record (status=draft). Deloitte provides scope statement: "Limited assurance of ESRS E1 (GHG Scope 1/2/3) and S1 (headcount) per ISSA 5000 limited level." Materiality: EUR 1M (5% turnover) or 5% Scope 1+2 (2.75 tCO2e).
- Entity locks all FY2025 data points (status→locked-for-assurance, 2026-03-01).
- Deloitte partner assigns senior manager to audit. Walkthrough starts (2026-03-15):
  - **Test of controls**: Deloitte checks approval workflow (reviewer signature, source-document attachment). Tests 10 GL-linked points (fuel): confirm GL line matches esrs-data-point value. Tests 5 manual-entry points (water withdrawal): confirm source documents attached. Result: no exceptions.
  - **Substantive testing - Scope 1 Fuel**: Deloitte samples 3 of 12 monthly invoices (Jan, May, Oct). Verifies Invoice amount matches GL entry; fuel type (diesel) matches emission-factor assumption (2.31 kg CO2e/L); conversion from EUR to litres reasonable (price ~EUR 1.40/L consistent with market). Computes: if Jan sampled = 68L @ 2.31 = 157 kg, total year extrapolation = 1,884 kg ≈ 1.9 tCO2e. Recorded detail = 22.32 tCO2e. Deloitte raises finding: "Fuel quantity discrepancy between sample projection (1.9 tCO2e) and recorded value (22.32 tCO2e) suggests either low sample or data-entry error. Request reconciliation."
  - **Substantive testing - Scope 2 Electricity**: Deloitte requests meter readings (Q1–Q4 2025) and compares to grid invoices. Readings match. Renewable certificate purchase agreement reviewed (20,000 kWh, registry ID #xxx). Finds no exception.
  - **Substantive testing - Scope 3 Purchased Goods**: Deloitte reviews supplier-survey responses (6 suppliers, data-quality: medium per entity disclosure). Computes spot-check: top 3 suppliers' Scope 3 LCA data (ton CO2e) weighted by spend. Finds: "Top 3 suppliers' specific data shows lower intensity (0.12 kg CO2e/EUR) vs. entity's proxy (0.18). Entity acknowledged non-response from lower-performing suppliers; potential bias toward higher estimates. Recommend survey all suppliers by FY2026; uncertainty acceptable for FY2025 given limited-assurance scope."
- Entity responds (2026-04-01):
  - **Fuel discrepancy**: Investigation reveals: 3 monthly invoices in sample are shipping invoices (2–4 L per invoice), not 68 L. Auditor misread invoice detail. Recalculates sample: 3 + 3 + 2 = 8 L sampled → 8 × 2.31 = 18.5 kg extrapolated vs. recorded ~186 kg (12× monthly avg ~15.5 L/month). Entity correct. Finding status → **resolved** (misunderstanding).
  - **Scope 3 supplier bias**: Entity accepts risk (not correctable in FY2025; action: complete supplier survey FY2026). Finding status → **accepted-risk**.
- Deloitte partner reviews responses (2026-04-10). Agrees: fuel finding resolved; Scope 3 risk accepted. Issues assurance report with negative opinion: "Nothing has come to our attention that causes us to believe the ESRS E1 and S1 data points for FY2025 are not presented fairly, in all material respects, in accordance with ESRS. [Note: Scope 3 is subject to inherent limitation due to reliance on proxy estimates and partial supplier responses; materiality threshold applied accordingly.]"
- Status → assured (opinion-date=2026-04-10).

**THEN:**
- GHG inventory and all E1 / S1 data points are locked, assured, ready for XBRL submission.
- Management-response memo (fuel reconciliation + Scope 3 action plan) is attached to assurance-engagement record for disclosure-table context.

### REQ-CSR-005: XBRL Submission Pipeline (EFRAG ESRS Taxonomy)

**Title:** Auto-map approved/assured data points to EFRAG ESRS XBRL taxonomy; generate iXBRL-tagged PDF

**Description:**
Once all data points for the reporting period are status=assured, system MUST auto-map each data point to the EFRAG ESRS XBRL taxonomy (finalized 2024, updated annually).

Mapping rules:
- Each `esrs-data-point` (ESRS ID, e.g., `E1-6_GrossScope1GHGEmissions_tCO2eq`) maps to a unique XBRL fact tag in the EFRAG ESRS context.
- Value is extracted from the locked, assured data point.
- Narrative comments (preparer + auditor annotation) are embedded as audit evidence contextRef.
- Unit of measure (tCO2e, headcount, EUR, %) is translated to XBRL dimension (standardized UN/CEFACT codes).
- Metadata (preparer-timestamp, reviewer-timestamp, auditor-sign-off-date, source-reference) is captured in iXBRL.

System MUST validate all mandatory data points per taxonomy schema before generation. If mandatory point missing:
- Status=pending-submission
- Error list: "E1-6 Scope 1 GHG missing" / "S1-1 headcount missing" / etc.
- Submission blocked until all mandatory points present.

System MUST generate iXBRL-tagged PDF:
- Annual report PDF (e.g., `FY2025_AnnualReport_iXBRL.pdf`)
- ESRS-specific context block (6–10 pages) inserted before notes
- Context block contains:
  - Double-materiality matrix (summary table)
  - GHG inventory summary (Scope 1/2/3 totals, intensity metrics, recalculation notes if any)
  - Policy/action/target summary (per material topic)
  - Assurance statement (auditor opinion + findings summary)
  - Data-quality notes (source methods, proxy estimates, uncertainty ratings)
- All quantitative facts are tagged with XBRL meta-data (concept ID, context ref, unit, audit evidence links)

Submission to KvK / AFM:
- Entity / CFO exports iXBRL PDF
- Submits to KvK via ESEF portal (https://www.kvk.nl/bijzonderheden/esef/) + optional AFM filing
- System stores submission confirmation (date, receipt ID) in `assurance-engagement.submission-confirmation`

#### Scenario: XBRL Submission

**GIVEN:**
- All FY2025 ESRS data points are assured (status=assured, opinion-date=2026-04-10).
- Annual report draft is prepared (PDF, 80 pages).

**WHEN:**
- System runs validation check: scans all mandatory ESRS data points per EFRAG IG-3 (1,100-point list). Finds:
  - E1-6 (Scope 1 GHG) ✓ present (22.32 tCO2e)
  - E1-7 (Scope 2 GHG location-based) ✓ present (32.73 tCO2e)
  - E1-8 (Scope 2 GHG market-based) ✓ present (40.30 tCO2e)
  - E1-9 (Scope 3 GHG, 15 categories) ✓ present (1,604 tCO2e)
  - S1-1 (Headcount, gender + age breakdown) ✓ present (250 total; 120 M / 130 F; 80 age <30, 140 age 30–50, 30 age >50)
  - G1-2 (Pay-on-time KPI, % invoices paid within terms) ✓ present (96.2%, median 28 days)
  - ... all 48 mandatory topical points for E1, S1, G1 present
  - (E2, E3, S2, S3, S4 not mandatory; not in-scope per materiality assessment)
- Validation passes. Status=ready-for-submission.
- System maps each point to EFRAG ESRS taxonomy fact ID (provided by EFRAG XML schema, e.g., `esrs-e_GrossScope1GHGEmissions`).
- iXBRL generator creates PDF:
  - Original 80 pages + 8-page ESRS context block inserted (after annual report main body, before notes)
  - Context block includes:
    - Materiality matrix summary (6 material topics: E1 Climate, E3 Water, E5 Circular Economy, S1 Own Workforce, G1 Business Conduct, plus non-material explanation for E2, E4, S2, etc.)
    - GHG Inventory summary (Scope 1: 22.32 tCO2e, Scope 2 LB: 32.73, Scope 2 MB: 40.30, Scope 3: 1,604; total 2,195 tCO2e; intensity: 8.6 per FTE, 0.0281 per EUR revenue)
    - Policy/action/target extracts (e.g., "Energy transition policy adopted 2025; target: 50% renewable energy by 2030; current: 23% via green contracts")
    - Assurance statement: "Limited assurance per ISSA 5000 by Deloitte Netherlands (Partner: J. Smith, opinion-date 2026-04-10). No findings except non-compliance risk on Scope 3 supplier-data completeness (action plan: complete survey by FY2026)."
    - Data-quality notes: "Scope 1/2 based on GL records (high assurance). Scope 3 uses supplier survey (6 of 10 responses, 80% coverage) + spend-based EEIO proxy (uncertainty: ±50%, data-quality: medium)."
  - All quantitative facts are XBRL-tagged (iXBRL markup embedded in PDF):
    ```xml
    <span class="ix:nonfraction" ix:name="esrs-e_GrossScope1GHGEmissions" unitref="tco2e" contextref="instant_2025-12-31" decimals="2">22.32</span>
    ```
- System exports PDF as `FY2025-CompanyName-iXBRL.pdf` (size ~2.5 MB)
- System stores submission metadata (PDF location, validation status, fact count, generation timestamp)

**THEN:**
- CFO downloads PDF, verifies appearance, approves for submission.
- Submits to KvK ESEF portal (username/password auth). KvK returns: submission-receipt-ID, timestamp, filename hash.
- System logs: `assurance-engagement.submission-confirmation` = {receipt-id, kv-url, date=2026-04-25, status=accepted}
- ESRS reporting cycle complete. Next annual report (FY2026) can begin materiality assessment.

---

## Regulatory References

| Standard | Section | Topic | Link |
|----------|---------|-------|------|
| CSRD | Article 8(1) | Double materiality assessment | Directive 2022/2464 |
| ESRS-1 | §3 | Materiality methodology | Commission Delegated Regulation 2023/2772 |
| ESRS-2 | §2.1 | General disclosures (governance, strategy) | CDR 2023/2772 |
| E1 Climate | §E1-1 to E1-7 | GHG emissions, transition plan, climate risk | CDR 2023/2772 |
| GHG Protocol | Ch. 4–8 | Scope 1/2/3 calculation | https://ghgprotocol.org |
| ISSA 5000 | A3–A8 | Limited assurance procedures | IAASB 2024 |
| EFRAG IG-1 | - | Materiality assessment guidance | https://www.efrag.org |
| EFRAG IG-3 | - | ESRS data-point list (~1,100 points) | https://www.efrag.org |
| ESEF / iXBRL | - | Single Electronic Format, Inline XBRL | European Commission |
| Dutch implementation | Titel 9 Boek 2 BW | CSRD transposition (in force Q1 2025) | https://wetten.overheid.nl |
