# Design — Waterschappen BBV Variant (BBVW)

## Context

Dutch water boards (waterschappen) are required under municipal governance rules to publish annual budgets aligned with policy programmes (the BBV framework). Shillinq's core bookkeeping tracks financial transactions; the BBV variant adds the governance bridge — mapping budgets to programmes and surfacing compliance metrics for stakeholder reporting.

The change is **spec-only** for Phase 1. Implementation lands later through `opsx-apply` and the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express BBV compliance tracking as **declarative metadata** — programme registers + budget mappings + dashboard configuration — per ADR-031.
- Consume OpenRegister's schema validation and relation abstractions — per ADR-022. Zero app-local compliance engines.
- Make the spec **governance-officer readable** — budget-to-programme linking recognisable, compliance status transparent, audit trail immutable.
- Carry forward **original waterboard demand signal** (165 tender mentions) under the declarative T2 envelope.
- Enable **year-over-year compliance trending** through schema-driven historical snapshots.

## Non-Goals

- No custom compliance calculation service.
- No pre-built BBV XML export (submission to municipalities deferred to T3).
- No multi-year forecast modelling.
- No inter-organisation consolidation (single waterboard per instance).

## Decisions

### D1 — BBVProgramme is the governance structure anchor

`BBVProgramme` is a top-level entity representing a single fiscal-year policy programme code per the Dutch BBV standard (e.g., "1.1.1 Core Administration", "2.3.2 Water Quality Monitoring"). Every budget allocation maps to exactly one programme. Programme definitions are admin-maintained per fiscal year; they do not change mid-year except via audit-trailed amendments.

### D2 — Budget-to-programme mapping is a many-to-one relationship

`BudgetBBVMapping` is a junction entity linking a GL account (or account hierarchy roll-up) to one or more BBV programmes with an allocation percentage. Example: "GL 4100 (Personnel Expenses)" → 50% to programme "1.1.1", 30% to "1.2.1", 20% to "2.3.2". Percentages must sum to 100% per account per fiscal year.

### D3 — Compliance status is computed, not stored

`BBVProgramme.complianceStatus` is a **computed field** (aggregation) derived from actual GL spend and budget allocation at query time. No separate "compliance status" table. States: `on-track`, `at-risk` (approaching 80% utilization), `non-compliant` (exceeded), `unconfigured` (no mappings).

### D4 — Dashboard aggregates GL + programme data in real time

The BBV Compliance Dashboard queries GL transactions, applies programme mappings, and renders KPI cards (utilization %, variance, on-track count, at-risk count). Platform CnDashboardPage + CnChartWidget + CnStatsBlock handle rendering; no custom dashboard service.

### D5 — Fiscal-year scoping is implicit

All queries (compliance, mappings, programmes) are scoped to the administration's current fiscal year. Multi-year views are out of scope (Phase 2).

### D6 — Audit trail is automatic

Programme creation, mapping edits, compliance status changes — all captured in OR's immutable audit trail. No app-local audit service.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Programme master data | OR register + schema | `BBVProgramme` register with schema validation |
| Budget-to-programme mapping | OR relations + many-to-many | `BudgetBBVMapping` as a junction register, using OpenRegister relations to GL accounts |
| Compliance aggregation | OR `x-openregister-aggregations` | Aggregation: sum GL spend per programme, compare to budget allocation |
| Compliance dashboard | `@conduction/nextcloud-vue` CnDashboardPage | 4 widgets: KPI cards, utilization chart, status distribution pie, detailed programme table |
| Budget-mapping UI | `CnIndexPage` + `CnDetailPage` + `CnFormDialog` | No custom components; use platform list/detail/edit patterns |
| Fiscal-year context | Shillinq Administration model (existing T1) | Inherit current fiscal year from Shillinq's Administration context |
| Audit trail | Shillinq `bookkeeping-audit-trail` + OR immutable audit | Automatic; no additional service |
| GL account hierarchy | Shillinq T1 Chart of Accounts | Cross-reference via account number; picker in mapping detail page |

**Net new code in implementation cycle**: 2 schema declarations + 4 dashboard widget slots + 2 detail-page components. Zero custom services.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| BBV programme master | Declarative (register schema) | Static metadata, no computation |
| Budget-to-programme allocation | Declarative (register + many-to-many relations) | Pure data, no business logic |
| Compliance status computation | Aggregation query (OR `x-openregister-aggregations`) | SUM + COMPARE, not imperative |
| Dashboard rendering | Platform `CnDashboardPage` + widgets | Layout + configuration, not code |
| Fiscal-year scoping | Implicit in Administration context | Inherited from Shillinq T1, no new service |

No service class authored in this envelope.

## Seed Data

Minimal seed data for demonstration:

**BBVProgramme** (fiscal year 2026, example waterboard):
- `1.1.1` — Core Administration
- `1.2.1` — HR & Payroll
- `2.3.2` — Water Quality Monitoring
- `2.4.1` — Infrastructure Maintenance
- `3.1.0` — Strategic Planning

**BudgetBBVMapping** (example, GL 4100 → Core Administration 50% + HR 30% + Infrastructure 20%):
- GL 4100 (Personnel) → 50% Programme 1.1.1, 30% Programme 1.2.1, 20% Programme 2.4.1
- GL 5000 (Operations) → 25% Programme 2.3.2, 75% Programme 2.4.1

These serve **demo/dev only**. Production waterboards configure their own programmes and mappings via the admin UI.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Programme code standards vary by waterboard | Spec allows custom codes; validation per administration; documentation of standard formats |
| Budget allocation maintenance overhead | CnIndexPage + inline edit + bulk-import dialog (platform CnMassImportDialog) |
| Compliance aggregation performance at scale (1000s of GL lines) | OR aggregation caching; per-spec optimisation in implementing cycle if gates trip |
| Year-end fiscal close coordination with programme changes | Fiscal-year lock pattern (deferred to Phase 2); currently programmes are mutable; audit trail preserves history |
| Multi-organisation (multiple waterschappen) future growth | Scope document: single waterboard per instance; multi-tenancy at Nextcloud level, not in-app |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with 2 new schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 2 new menu entries (additive).
3. Seed data (example programmes + mappings) is loaded via `ConfigurationService::importFromApp()` during first install.
4. No database migrations — both registers use OpenRegister's schema validation.

**Down-direction:** Reverting removes the manifest entries and seeded data; existing GL transactions remain queryable.

## Open Questions

1. **GL account hierarchy depth** — should mappings target leaf accounts only, or allow roll-ups? → Resolved during UX design (allow both; roll-up allocation distributes to all children).
2. **Allocation rounding** — when percentages don't sum exactly to 100% due to rounding, how to handle? → Resolved in validation: require 99.9–100.1% tolerance per fiscal year.
3. **Multi-programme reporting** — should a single GL line contribute to multiple programmes' compliance metrics? → Yes, via percentage allocation (D2 above).
4. **Dashboard refresh cadence** — real-time or daily batch? → Real-time (aggregation query on dashboard load); caching per OR's extension if performance gates trip.
5. **Inter-BBV-programme dependencies** — should programmes A → B dependency tracking surface in compliance status? → Deferred to Phase 2 (workflow/dependency modelling).

## References

- ADR-022: Apps Consume OpenRegister Abstractions
- ADR-031: Schema-declarative business logic
- OpenRegister aggregations (`x-openregister-aggregations` extension)
- @conduction/nextcloud-vue components (CnDashboardPage, CnIndexPage, CnDetailPage, CnDataTable)
