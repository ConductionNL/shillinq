# Design — Cost Centers & Analytical Dimensions

**Status:** pr-created

## Decisions

### D1 — Dimensions are first-class registers, not free-form strings on `GLLine`

Per ADR-022, each dimension type (cost center, project) gets its own register so values are governable (typo-resistant, versioned, lifecycle-managed) and queryable via standard OR abstractions. Custom analytical dimensions follow the same pattern: operators declare a new `AnalyticalDimension` register and the `GLLine.dimensions` free-form map validates against registered dimensions via the relations engine.

**Alternative considered**: Store dimension values as free-form strings on `GLLine`. Rejected — no typo prevention, no governance, no hierarchy navigation, no audit trail.

### D2 — Dimension hierarchies are self-relations for parent-child navigation

Cost centers and projects often organize hierarchically (e.g., company → region → branch → department). Using `x-openregister-relations` self-relations on `CostCenter.parentCode` and `Project.parentCode` enables transparent parent lookup and rollup aggregation without custom code.

**Alternative considered**: Separate parent/child lookup tables. Rejected — OR relations already handle this generically.

### D3 — Segment P&L as single-schema aggregation, not a service

Segment P&L roll-up (P&L broken down by cost center, project, dimension) is a single-schema aggregation on `GLLine` keyed by dimension; per ADR-031 single-schema aggregations are declarative default. No PHP `SegmentReportService`. Consumed by dashboard widgets via runtime GraphQL and by manifest detail pages.

**Alternative considered**: Build a dedicated service. Rejected per ADR-031 — declarative aggregations are preferred.

### D4 — Custom analytical dimensions are definition records, not hardcoded enums

The `AnalyticalDimension` register allows operators to define new dimension types (region, product line, channel) without code changes. The `GLLine.dimensions` object validates each key against registered `AnalyticalDimension` records and each value against that dimension's code set.

**Alternative considered**: Hardcode supported dimension types (enum). Rejected — limits flexibility and extensibility.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Cost-center and project storage | New registers (`CostCenter`, `Project`) | Declared same way as `Account`; same RBAC + audit + lifecycle |
| Dimension definitions | New `AnalyticalDimension` register | Operator-declared; validates keys in `GLLine.dimensions` |
| Hierarchy navigation | `x-openregister-relations` self-relation | Standard relation shape on `parentCode` FK |
| Custom dimensions | OR register abstraction (ADR-022) | `GLLine.dimensions` free-form map validates against registered dimension records via relations engine |
| Segment P&L aggregation | `x-openregister-aggregations` on `GLLine` (ADR-031) | Keyed by dimension; consumed by launchpad + manifest pages |
| Audit trail | OR audit-trail-immutable | Consumed automatically |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-1 generic) | Adds navigation entries + matching index/detail page pairs |

**Net new code in implementation cycle**: 3 schema declarations + additive patches on `GLLine` + 3 manifest entry pairs + seed data (3-5 example cost centers and dimensions). No new PHP service.

## Seed Data

Seeds ship as part of the registration template and include realistic, distinguishable Dutch entity values:

| Register | Seed records | Purpose |
|---|---|---|
| `CostCenter` | 3-5 example cost centers | Default organizational structure (HQ, Branches, Departments) |
| `Project` | 2-3 example projects | Representative project codes |
| `AnalyticalDimension` | Example dimension definitions (Region, Product Line) | Demonstrate custom dimension workflow |

Example `CostCenter` seeds:
- Code: `CC-001`, Name: `Administratie, Amsterdam` (Accounting, main office)
- Code: `CC-010`, Name: `Verkoop, Utrecht` (Sales, regional branch)
- Code: `CC-020`, Name: `Logistiek, Rotterdam` (Logistics, distribution center)

Example `AnalyticalDimension` seeds (defined, not populated):
- Code: `region`, Name: `Regio` (Geography-based analysis)
- Code: `product-line`, Name: `Productlijn` (Product category tracking)

Seeds are loaded idempotently via the repair step and ship with:
- SPDX header (EUPL-1.2 + Copyright Conduction B.V.)
- `_meta` block with source, variant, imported timestamp

**Limitations**: OpenRegister's `ImportHandler` currently supports only flat seed objects. Related items (files, notes, tasks) linked through the relation system are tracked in OR's pending `seed-related-items` change. Until that lands, seed data is limited to object properties defined in schemas.
