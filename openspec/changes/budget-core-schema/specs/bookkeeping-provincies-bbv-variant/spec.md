# Spec: bookkeeping-provincies-bbv-variant (delta — budget-core-schema)

This delta MODIFIES REQ-BBC-001 and REQ-BBC-002 to reflect the `Budget` →
`BbvProgrammeBudget` rename (`budget-core-schema` design.md §1), and
corrects this spec's own "Depends on" line, which named a capability spec
that was never built.

## Why this delta exists

This spec's header names a dependency,
`` `../budget-planning-control/spec.md` (Budget register) ``, that does not
exist anywhere under `openspec/specs/` — verified while writing
`budget-core-schema`'s design.md. `budget-core-schema` is, in effect, the
capability that dependency line anticipated but that was never delivered,
under different names for the domain-specific pieces
(`BbvProgrammeBudget`/`CommitmentBudget` rather than one shared `Budget`).
The dependency line is corrected to point at the real capability.
REQ-BBC-001/002's prose refers to the schema by its old, colliding name
(`Budget`); both requirements' *substance* — the KPI cards, the traffic-light
rule, the filter table — is unchanged by this delta, only the schema name
in the requirement text.

## MODIFIED Requirements

### Requirement: REQ-BBC-001 — BBV Compliance Dashboard SHALL display budget health by programme with traffic-light status

The BBV Compliance Dashboard page MUST render a `CnDashboardPage` widget
containing:

- **4 KPI cards** (per RFC 2119 requirement, not optional):
  1. **Total Budget** — sum of all `BbvProgrammeBudget.totalAmount` records
     matching the selected `programmaStructure` + fiscal year, displayed in
     EUR.
  2. **Committed** — sum of `GLLine.amount` where `status: "committed"` and
     `programmaStructure` matches; includes active contracts and purchase
     orders.
  3. **Spent** — sum of `GLLine.amount` where `status: "posted"` and
     `programmaStructure` matches; GL transactions settled and booked.
  4. **Remaining** — Remaining = Total Budget − (Committed + Spent), shown
     as absolute EUR and percentage of budget.

- **Status Indicator** (traffic-light rule):
  - 🟢 Green: Remaining ≥ 15% of Total Budget (under 85% utilisation)
  - 🟡 Yellow: Remaining 0–15% (85–100% utilisation)
  - 🔴 Red: Remaining < 0 (overspend)

- **Budget vs. Actuals Chart** — horizontal bar chart, one bar per programme,
  showing Total Budget (grey) vs. Spent (blue) vs. Committed (orange). Bars
  MUST be normalized (0–100% stacked) if totals vary widely.

- **Trend Chart** — line chart of cumulative monthly spend (x-axis: month,
  y-axis: cumulative EUR) with budget reference line. Months with no GL
  postings MUST appear as zero, not omitted.

#### Scenario: Dashboard KPI cards reflect current fiscal year

- **GIVEN** a province with FY 2026 `BbvProgrammeBudget` €1M for programme
  `mobiliteit`
- **WHEN** €600k spent and €200k committed in GL as of 2026-05-21
- **THEN** dashboard MUST show:
  - Total Budget: €1,000,000
  - Committed: €200,000
  - Spent: €600,000
  - Remaining: €200,000 (20% — Green status)

@e2e exclude unbuilt UI: BBV variant pages not yet implemented (pre-existing
exclusion carried over from this spec's own file, unaffected by this delta)

#### Scenario: Overspend triggers red status

- **GIVEN** a programme `water` with €500k `BbvProgrammeBudget`, €350k
  spent, €200k committed
- **WHEN** dashboard is rendered
- **THEN** status MUST be 🔴 Red (50k overspend); Remaining field MUST
  display as negative (-€50,000).

@e2e exclude unbuilt UI: BBV variant pages not yet implemented

### Requirement: REQ-BBC-002 — Dashboard filters SHALL support programme, fiscal year, and compliance status

The dashboard `CnFilterBar` MUST include three filter controls:

| Filter | Type | Values | Default |
|---|---|---|---|
| **Programme** | multi-select enum | 7 values: ruimte, mobiliteit, water, milieu, cultuur, economie, bestuur | all selected |
| **Fiscal Year** | dropdown | years with existing `BbvProgrammeBudget` + GL data (2023–2026, auto-discovered) | current FY |
| **Budget Status** | multi-select enum | approved, provisional, amended | all selected |

Filters MUST be applied cumulatively (AND logic). Selecting no programme
MUST show no data (not all programmes).

#### Scenario: Fiscal-year filter discovers years from BbvProgrammeBudget data

- **GIVEN** `BbvProgrammeBudget` records exist for fiscal years 2023–2026
- **WHEN** the dashboard's Fiscal Year filter dropdown is opened
- **THEN** it lists 2023–2026 (auto-discovered from `BbvProgrammeBudget` +
  GL data), defaulting to the current fiscal year

@e2e exclude unbuilt UI: BBV variant pages not yet implemented
