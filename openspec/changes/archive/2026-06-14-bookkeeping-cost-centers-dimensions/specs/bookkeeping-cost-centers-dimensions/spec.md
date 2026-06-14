# Spec: bookkeeping-cost-centers-dimensions

**Status:** proposed
**Scope:** bookkeeping
**Tier:** T2 (advanced features)
**Depends on:** bookkeeping-general-ledger (T1)

## ADDED Requirements

### Requirement: REQ-CD-001 — The system SHALL store analytical dimensions as OpenRegister-managed registers declared in the app manifest

Cost centers, projects, and custom analytical dimensions MUST be declared as registers in the app configuration (e.g., `lib/Settings/shillinq_register.json`) and surfaced through `src/manifest.json` per ADR-024. The set of supported dimension types MUST be open: an administration MAY add a custom dimension register (e.g., "region", "product line", "department") by declaring it the same way, without code changes to the bookkeeping PHP layer (per ADR-022 — consume OR's register abstraction rather than write a parallel dimension table).

#### Scenario: Reviewer confirms no parallel dimension table

- **GIVEN** the bookkeeping codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `cost_center` / `dimension` / `cost_object`
- **THEN** no such classes SHALL exist; all dimensions are OR-managed registers.

#### Scenario: A custom analytical dimension register is consumable through the same path as the built-in ones

- **GIVEN** an operator-defined `Region` register declared in app configuration with `x-openregister-purpose: dimension`
- **WHEN** the manifest declares an index/detail page for it
- **THEN** the dimension MUST be selectable from the GL line entry form alongside the built-in cost-center and project dimensions, with no bookkeeping PHP edits.

### Requirement: REQ-CD-002 — The `CostCenter` schema SHALL declare a fixed minimum field set with hierarchy support

The `CostCenter` schema MUST declare the following fixed minimum field set, and the `Project` schema MUST declare an equivalent shape (code, name, parentCode for hierarchy, manager, budget, status, administrationId) for project-based analytical accounting.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `code` | string | Yes | Operator-assigned unique reference within the administration (e.g., `CC-001`) |
| `name` | string | Yes | Human-readable name (e.g., `Sales, Amsterdam`) |
| `description` | string | No | Detailed cost center description and responsibilities |
| `parentCode` | string | No | FK to parent `CostCenter.code` for hierarchy via `x-openregister-relations` self-relation |
| `manager` | string | No | User ID of the cost-center owner or manager |
| `budget` | number | No | Allocated annual or periodic budget amount in EUR |
| `status` | enum | Yes | One of `active`, `blocked`, `archived` |
| `administrationId` | string | Yes | FK to the administration |

The `Project` schema MUST declare an equivalent shape (code, name, parentCode for hierarchy, manager, budget, status, administrationId) for project-based analytical accounting.

#### Scenario: A cost-center hierarchy resolves via OR's relation engine

- **GIVEN** cost-center `CC-001 Administratie` and child `CC-010 Administratie, Utrecht`
- **WHEN** the child's `parentCode` is set to `CC-001`
- **THEN** OR's relation engine MUST resolve the parent on read; **AND** the segment P&L (per REQ-CD-004) MUST roll child amounts up to the parent.

### Requirement: REQ-CD-003 — The `GLLine` schema SHALL carry optional dimension references additively

The tier-1 `GLLine` schema MUST be extended additively with the following optional fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `costCenterCode` | string | No | FK to `CostCenter.code` for cost-center-based analysis |
| `projectCode` | string | No | FK to `Project.code` for project-based analysis |
| `dimensions` | object | No | Free-form key→value map for custom analytical dimensions, where each key matches a registered `AnalyticalDimension` register code and the value matches that dimension's code value |

The `dimensions` map MUST be validated per registered analytical dimension (each key MUST point at an existing `AnalyticalDimension` register, each value MUST resolve to an existing record's code in that dimension). Validation is declared via OR's relation engine, not written in PHP.

#### Scenario: A line referencing a non-existent custom dimension key fails validation

- **GIVEN** no `Region` analytical dimension register is registered
- **WHEN** a `GLLine` is saved with `dimensions: {"region": "NL"}`
- **THEN** the save MUST fail with an "unknown dimension key" error.

#### Scenario: A line referencing a missing dimension value fails validation

- **GIVEN** the `Region` analytical dimension register is registered but contains no record with code `"NL"`
- **WHEN** a `GLLine` is saved with `dimensions: {"region": "NL"}`
- **THEN** the save MUST fail with an "unknown dimension value" error.

### Requirement: REQ-CD-004 — The system SHALL expose a segment P&L derived from dimension-tagged GL lines via `x-openregister-aggregations`

Per ADR-031, segment P&L (P&L broken down by cost-center, project, or custom dimension) MUST be declared as `x-openregister-aggregations` on `GLLine` keyed by (`fiscalYearId`, `accountNumber`, dimension code). The aggregation MUST be consumable by:

- Dashboard widgets (per ADR-022 — dashboard reads aggregations via runtime GraphQL)
- Manifest detail pages on a cost-center or project record (which render that segment's roll-up)
- Reporting exports (P&L by cost center, by project, by region, etc.)

No PHP `SegmentReportService.getByDimension()` aggregates from ledger lines — the aggregation is declarative.

#### Scenario: Aggregation rolls dimension amounts up to a parent

- **GIVEN** posted lines tagged `CC-010 Sales, Utrecht` and `CC-020 Sales, Amsterdam` (both children of `CC-001 Sales`)
- **WHEN** the segment P&L aggregation is queried for `CC-001`
- **THEN** the result MUST include the sum of both children's amounts under `CC-001`.

#### Scenario: Multiple dimensions can be analyzed in parallel

- **GIVEN** posted lines tagged with both cost-center and project dimensions
- **WHEN** the segment P&L aggregation is queried
- **THEN** separate aggregations MUST be available for cost-center roll-up AND project roll-up, without data duplication or schema changes.

### Requirement: REQ-CD-005 — Analytical dimensions and cost centers SHALL be reachable through the manifest navigation

`src/manifest.json` MUST declare navigation entries (under `Bookkeeping > Dimensions`) with `type: index` + `type: detail` pages for `CostCenter`, `Project`, `AnalyticalDimension`, and any operator-registered custom dimension register. All pages MUST be rendered by the generic `@conduction/nextcloud-vue` `CnIndexPage` / `CnDetailPage` components — no bespoke Vue files (per ADR-024).

#### Scenario: A newly registered custom analytical dimension appears in the nav after manifest reload

- **GIVEN** the operator adds a `Department` analytical dimension register and a corresponding manifest entry
- **WHEN** the manifest is reloaded
- **THEN** the `Bookkeeping > Dimensions > Department` entry MUST appear with no PHP / Vue edits.

### Requirement: REQ-CD-006 — The `AnalyticalDimension` register SHALL define custom dimension shape and governance

The `AnalyticalDimension` register MUST declare the shape for operator-defined custom analytical dimensions:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `code` | string | Yes | Unique dimension identifier used in `GLLine.dimensions` map keys (e.g., `region`, `product-line`) |
| `name` | string | Yes | Human-readable dimension name (e.g., `Regio`, `Productlijn`) |
| `description` | string | No | Description of what this dimension captures |
| `dataType` | enum | Yes | One of `string`, `number`, `date` (determines value validation) |
| `isHierarchical` | boolean | No | Whether values in this dimension support parent-child relationships |
| `administrationId` | string | Yes | FK to the administration |

Each analytical dimension's **values** (e.g., regions: NL, BE, DE) are stored as a separate register instance (dynamically created or operator-managed). The `GLLine.dimensions` map references these values via the dimension's code.

#### Scenario: An operator defines a custom region dimension

- **GIVEN** an `AnalyticalDimension` record with `code: region`, `name: Regio`, `isHierarchical: false`
- **WHEN** an administration operator creates region value records (NL, BE, DE)
- **THEN** GL lines MAY reference them via `dimensions: {"region": "NL"}` with automatic validation.

### Requirement: REQ-CD-007 — Multi-dimensional hierarchies SHALL support roll-up and drill-down analysis

The segment P&L aggregation MUST support hierarchical roll-up — when a cost-center or project has a parent, aggregated amounts for the child MUST be automatically rolled up to the parent level without duplication or double-counting.

#### Scenario: Hierarchical roll-up sums child costs to parent

- **GIVEN** cost centers arranged: CC-001 (parent) → CC-010, CC-020 (children)
- **WHEN** GL lines are posted tagged to CC-010 (€100) and CC-020 (€200)
- **THEN** querying segment P&L for CC-001 MUST return €300 total, with child breakdowns visible on drill-down.

#### Scenario: Drill-down shows child dimensions under a parent

- **GIVEN** a user viewing segment P&L for CC-001
- **WHEN** they drill down or expand CC-001
- **THEN** child cost-center breakdowns (CC-010: €100, CC-020: €200) MUST appear inline or in a detail view.

### Requirement: REQ-CD-008 — The capability SHALL support multi-dimensional analysis (cost center AND project AND custom dimension simultaneously)

GL lines MAY be tagged with multiple dimensions simultaneously (e.g., cost-center + project + region). The aggregation engine MUST support independent roll-ups for each dimension without requiring separate data storage or cross-dimensional computation.

#### Scenario: A line tagged with three dimensions is aggregated correctly in each dimension

- **GIVEN** a GL line tagged: `costCenterCode: CC-001`, `projectCode: PROJ-100`, `dimensions: {region: NL}`
- **WHEN** segment P&L aggregations are queried for each dimension separately
- **THEN** the line's amount MUST appear in CC-001's roll-up, in PROJ-100's roll-up, AND in the NL region's roll-up.
