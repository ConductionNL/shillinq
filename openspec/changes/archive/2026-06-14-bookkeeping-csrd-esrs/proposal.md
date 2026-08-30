# Proposal: bookkeeping-csrd-esrs

`kind: config` per ADR-032 — the centre of mass is declarative schemas (`materiality-assessment`, `iro-record`, `esrs-data-point`, `ghg-inventory`, `emission-source`, `value-chain-actor`, `esrs-policy`, `esrs-action`, `esrs-target`, `assurance-engagement`) + extensions to existing schemas + `x-openregister-lifecycle` for CSRD/ESRS reporting workflows. No PHP calculation services for impact scoring or GHG computation; all quantitative logic is declarative aggregation + formula-driven.

## Summary

Introduce the **Corporate Sustainability Reporting Directive (CSRD) / European Sustainability Reporting Standards (ESRS)** capability for Shillinq, enabling Dutch MKB+ entities (wave 2: 250+ employees, EUR 50M+ turnover; wave 3: listed SMEs) to conduct their first ESRS report fully within the platform — from double-materiality assessment through data collection, GHG calculation (Scope 1/2/3), audit walkthrough, and XBRL submission — without external consultants beyond the statutory auditor.

This change introduces ten new schemas in a new `sustainability` register:

- `materiality-assessment` — root governance record for double-materiality determination
- `iro-record` — Impact / Risk / Opportunity entry per ESRS topic (E1–E5, S1–S4, G1)
- `esrs-data-point` — one row per ESRS-mandated data point (~1,100 per period)
- `ghg-inventory` — GHG Protocol calculation engine (Scope 1/2/3)
- `emission-source` — per-activity data row feeding the inventory
- `value-chain-actor` — supplier / customer / portfolio-company in reporting boundary
- `esrs-policy`, `esrs-action`, `esrs-target` — ESRS-2 governance triad
- `assurance-engagement` — auditor walkthrough and findings tracking

Extensions to existing schemas: `journal-entry-line` (FK to `esrs-data-point`), `fixed-asset` (`is-biodiversity-sensitive-area-overlap`, `physical-climate-risk-rating`), `organisations` (`nace-code`, `country-of-operation`, `esrs-in-scope-for` array).

The ESRS reporting flow is a declarative `x-openregister-lifecycle` on both `materiality-assessment` (one per period, multi-stakeholder consultation) and `esrs-data-point` (per-point approval + assurance lock). Materiality determination is mandatory before any topical disclosure. All data points route through a source-configuration engine (GL, openconnector API, manual upload, supplier survey, proxy estimate); collection progress is dashboarded. GHG calculation auto-computes from active `emission-source` rows using DEFRA/EPA/IEA factors, with base-year recalculation triggered by ≥5% boundary change. Assurance workflow locks data points from edit but permits auditor annotation. XBRL submission is auto-mapped to EFRAG taxonomy and emitted as iXBRL-tagged PDF for KvK/AFM deposition.

This change conforms to the shared `nextcloud-app` spec for app structure and `ConfigurationService::importFromApp()` seed-data pattern.

**Depends on:**
- [`bookkeeping-general-ledger`](../add-shillinq-general-ledger/proposal.md) — GL posting via `journal-entry-line.esrs-data-point` FK
- [`bookkeeping-fixed-assets-depreciation`](../add-shillinq-fixed-assets/proposal.md) — asset register for biodiversity-overlap and climate-risk tagging
- [`bookkeeping-sbr-xbrl-reporting`](../bookkeeping-sbr-xbrl-reporting/proposal.md) — XBRL pipeline reuse (extend with EFRAG ESRS taxonomy)
- [`bookkeeping-ccm-rule-engine`](../bookkeeping-ccm-rule-engine/proposal.md) — automated control flow (e.g., no data point without source doc)
- [`organisations`](../add-shillinq-organisations/proposal.md) — entity master with NACE / country / in-scope flags
- `openconnector` (hard dependency) — minimum dozen external adapters (energy suppliers, HRIS, fleet systems, CDP, Ecovadis)
- `docudesk` — evidence storage for supplier surveys, board minutes, policies
- `decidesk` — materiality assessment governance routing

## Motivation

CSRD (Directive 2022/2464) entered force January 2023; transposed to Dutch law (Titel 9 Boek 2 BW) in late 2024. In-scope entities rise from ~150 (prior NFRD) to >4,500 in Netherlands. Wave 2 (250+ employees, EUR 50M+ turnover) reports FY2025 (deadline 2026); wave 3 (listed SMEs) report FY2026 (deadline 2027, opt-out to 2028 possible).

ESRS is operationally severe: double materiality assessment (company impact on environment/society + impact on company), ~1,100 individual data points across 12 standards, mandatory limited-assurance audit (year 1) escalating to reasonable assurance (FY2028+), digital XBRL taxonomy in European Single Electronic Format (ESEF), value-chain boundary extension (supplier emissions, customer usage, financial investee portfolios).

Most MKB+ entities today discover required data (Scope 3 emissions, supplier diversity, water consumption by site, biodiversity-area overlap) does not exist in current systems. Compliance projects cost EUR 100K–500K in consulting fees. By bundling CSRD/ESRS into Shillinq's bookkeeping engine (which already holds invoices, asset registers, GL), we create the lowest-cost compliant route for the entire Dutch MKB+ — a defensible moat for the next decade.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-csrd-esrs`); declares 10 new registers in new `sustainability` register collection; extends 3 existing schemas (`journal-entry-line`, `fixed-asset`, `organisations`); adds 2–3 manifest navigation entries (Materiality Assessment, ESRS Data Points, GHG Inventory, Assurance Engagement); integration with existing GL, asset, and organisations registries.
- [ ] Project: openregister — no source changes; consumes existing `x-openregister-lifecycle`, `x-openregister-aggregations`, `x-openregister-calculations` for GHG roll-up, sensitivity deltas, base-year recalculation.
- [ ] Project: bookkeeping-sbr-xbrl-reporting — extends XBRL pipeline to consume EFRAG ESRS taxonomy (2024 finalized version + annual updates); adds iXBRL-tagged-PDF emission.
- [ ] Project: openconnector — no scope change (existing integrations); document minimum required adapters (energy supplier APIs, HRIS exports, fleet-management, CDP/Ecovadis supplier data, Natura 2000 / KBA spatial overlays).

## Scope

### In Scope

- One new capability spec (`bookkeeping-csrd-esrs`) — see `specs/` folder.
- 10 new registers: `materiality-assessment` (double-materiality determination per period + stakeholder consultation evidence), `iro-record` (Impact/Risk/Opportunity per topic), `esrs-data-point` (~1,100 per period, source-configurable), `ghg-inventory` (Scope 1/2/3 aggregation), `emission-source` (activity row + factor), `value-chain-actor` (supplier/customer in scope), `esrs-policy`, `esrs-action`, `esrs-target` (ESRS-2 triad), `assurance-engagement` (auditor walkthrough + findings).
- Double-materiality workflow: wizard enumerates ESRS topical taxonomy, asks impact + financial materiality questions, computes scores (scale × scope × likelihood × irremediable), signs off matrix, locks, routes to board approval per `decidesk`.
- ESRS-1 + ESRS-2 auto-population from tenant master data + prior-period values.
- Topical module activation (E1–E5, S1–S4, G1) conditional on materiality determination.
- Data-point source configuration: GL auto-link, openconnector API pull, manual entry (with mandatory doc), imported-from-supplier-survey, proxy-estimate. Collection-progress dashboard.
- GHG calculation: per-period inventory rollup from `emission-source` rows + DEFRA/EPA/IEA factors (quarterly refresh via openconnector). Location-based + market-based Scope 2. Scope 3 with 15 categories + spend-based EEIO fallback (Exiobase/USEEIO). Base-year recalculation auto-trigger on ≥5% boundary change. Uncertainty tagging.
- Assurance workflow: locked-for-assurance state (no edit, auditor annotation OK). Limited assurance (negative opinion) year 1; reasonable assurance (positive) year 2028+. Findings auto-tracked to management response.
- XBRL submission: EFRAG ESRS taxonomy mapping + iXBRL-tagged PDF + validation before submission.
- Restatement + audit trail: immutable trail + exportable.
- Integration: GL posting via `journal-entry-line.esrs-data-point` FK; asset register for biodiversity-overlap + climate-risk; organisations master for NACE / country / in-scope flags.
- Extensions: `fixed-asset.is-biodiversity-sensitive-area-overlap` (boolean), `fixed-asset.physical-climate-risk-rating` (1-5), `organisations.nace-code`, `organisations.country-of-operation`, `organisations.esrs-in-scope-for` (array).

### Out of Scope

- Supply-chain emissions modelling (Scope 3 category-specific EEIO models remain spend-based proxy; detailed supplier-specific LCA deferred to T4).
- Multi-currency FX revaluation within ESRS (single-currency scope).
- Biodiversity-impact modelling (spatial overlap computed; species-impact narrative is manual per topic).
- Real-time asset-management connectors (Bloomberg, FactSet).
- Post-publication CSRD governance workflows (board approval, supervisor notification). Those are owned by `decidesk` / `publication-platform`.
- SFDR / EU Taxonomy KPI alignment detail (stored as data points; no separate SFDR schema).
- Climate scenario analysis (TCFD / COSO frameworks deferred).

## Risks

1. **Actuarial input quality** — Scope 3 calculations depend on supplier data (CDPResponse, Ecovadis ratings, regulatory disclosures). Incomplete or late supplier responses delay reporting.
   - *Mitigation*: Fallback to spend-based EEIO with data-quality rating. Supplier survey reminders + escalation.

2. **Materiality determination divergence** — Different materiality thresholds across peer group or time lead to restatement + audit pushback.
   - *Mitigation*: Pre-loaded ESRS topical taxonomy + guidance tooltips. Stakeholder consultation evidence mandatory. Board sign-off locks matrix.

3. **Base-year recalculation complexity** — M&A, divestiture, or methodology change triggers recalculation; boundary definition ambiguity (equity vs operational control) causes disputes.
   - *Mitigation*: Recalculation policy auto-enforced at ≥5% threshold. Policy memo + change log mandatory.

4. **Assurance-readiness delays** — Late data collection or quality issues surface in audit walkthrough, requiring rework.
   - *Mitigation*: Collection-progress dashboard, lock-for-assurance date explicit, auditor early access to draft data points.

5. **Taxonomy version churn** — EFRAG updates ESRS XBRL taxonomy annually; iXBRL emission must track latest version.
   - *Mitigation*: Taxonomy version pin in spec. Quarterly openconnector update cycle. Backwards-compatible iXBRL generation.

## Rollback

- **Non-reversible once disclosed.** CSRD/ESRS reports are filed with KvK / AFM. Restatement in a later period is required if material errors detected; deletion is not an option.
- Rollback to spec-as-proposed: remove all `sustainability` registers, revert schema extensions, deactivate navigation entries.
- Rollback **post-disclosure**: none. Restatement workflow (next period) is the only path.

## Open Questions

1. **Actuarial source for GHG factors** — Should quarterly factor refresh come via dedicated openconnector adapter (scheduled job), or manual upload? Recommend: dedicated adapter + fallback to last-known-good if API outage.

2. **Scope 3 proxy fallback confidence** — When supplier-specific LCA unavailable, spend-based EEIO uncertainty can be >50%. Should system warn or auto-escalate to CFO? Recommend: warn + data-quality rating (high/medium/low).

3. **Multi-entity consolidation boundary** — Equity share vs financial control vs operational control. Define auto-detection rules or require manual scope selection? Recommend: manual scope declaration per materiality-assessment, with control check against GL consolidation rules.

4. **Audit readiness threshold** — What percent-complete triggers "ready for assurance"? 95% data-point approval? 100% board sign-off of disclosure narratives? Recommend: 100% locked-for-assurance + auditor walkthrough plan signed.

5. **XBRL validation strictness** — Reject submission on any schema violation, or warn-and-continue? Recommend: fail on mandatory data points, warn on optional.

## Dependencies

| Dependency | Type | Status | Notes |
|---|---|---|---|
| bookkeeping-general-ledger | Hard | Required | GL posting via `journal-entry-line.esrs-data-point` FK |
| bookkeeping-fixed-assets-depreciation | Hard | Required | Asset register for biodiversity-overlap + climate-risk tagging |
| bookkeeping-sbr-xbrl-reporting | Hard | Required | XBRL pipeline (extend with EFRAG ESRS taxonomy) |
| bookkeeping-ccm-rule-engine | Hard | Required | Automated control flow (data-point validation) |
| organisations | Hard | Required | Entity master (NACE, country, in-scope flags) |
| openconnector | Hard | Required | External data adapters (energy, HRIS, fleet, supplier platforms) |
| docudesk | Soft | Required | Evidence storage (surveys, board minutes, policies) |
| decidesk | Soft | Recommended | Materiality assessment governance routing (nice-to-have v1) |

## Success Criteria

1. A shillinq tenant in scope for CSRD wave 2 (FY2025) can complete a full ESRS report inside the platform without external consultants (auditor excepted).
2. Materiality assessment (double-materiality matrix) can be performed, signed off, and locked within the system.
3. All ~1,100 ESRS data points can be collected, approved, and audited within the system.
4. GHG Scope 1/2/3 inventory is auto-calculated from GL, supplier data, and activity logs with <5% manual re-entry.
5. Limited-assurance auditor can access data points in locked-for-assurance state, add findings, and track management response.
6. XBRL submission file is generated, validated, and ready for KvK/AFM deposition without external tools.
