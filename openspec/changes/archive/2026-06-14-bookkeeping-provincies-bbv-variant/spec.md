# Spec: bookkeeping-provincies-bbv-variant

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (reporting + compliance)
**Depends on:** `../add-shillinq-provincies-bbv-variant/spec.md` (BBV-variant
foundation), `../bookkeeping-chart-of-accounts/spec.md` (GL account base),
`../budget-planning-control/spec.md` (Budget register)

## ADDED Requirements

### REQ-BBC-001: BBV Compliance Dashboard SHALL display budget health by programme with traffic-light status

The BBV Compliance Dashboard page MUST render a `CnDashboardPage` widget
containing:

- **4 KPI cards** (per RFC 2119 requirement, not optional):
  1. **Total Budget** — sum of all `Budget.totalAmount` records matching
     the selected `programmaStructure` + fiscal year, displayed in EUR.
  2. **Committed** — sum of `GLLine.amount` where `status: "committed"` and
     `programmaStructure` matches; includes active contracts and purchase orders.
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

- **GIVEN** a province with FY 2026 budget €1M for programme `mobiliteit`
- **WHEN** €600k spent and €200k committed in GL as of 2026-05-21
- **THEN** dashboard MUST show:
  - Total Budget: €1,000,000
  - Committed: €200,000
  - Spent: €600,000
  - Remaining: €200,000 (20% — Green status)

#### Scenario: Overspend triggers red status

- **GIVEN** a programme `water` with €500k budget, €350k spent, €200k committed
- **WHEN** dashboard is rendered
- **THEN** status MUST be 🔴 Red (50k overspend); Remaining field MUST display
  as negative (-€50,000).

### REQ-BBC-002: Dashboard filters SHALL support programme, fiscal year, and compliance status

The dashboard `CnFilterBar` MUST include three filter controls:

| Filter | Type | Values | Default |
|---|---|---|---|
| **Programme** | multi-select enum | 7 values: ruimte, mobiliteit, water, milieu, cultuur, economie, bestuur | all selected |
| **Fiscal Year** | dropdown | years with existing Budget + GL data (2023–2026, auto-discovered) | current FY |
| **Budget Status** | multi-select enum | approved, provisional, amended | all selected |

Filters MUST be applied cumulatively (AND logic). Selecting no programme
MUST show no data (not all programmes).

#### Scenario: Filter by single programme and year

- **GIVEN** dashboard with Budget + GL data for 2025 and 2026
- **WHEN** user selects Filter: Programme = `cultuur`, Fiscal Year = 2026
- **THEN** KPI cards MUST show only 2026 `cultuur` budget vs. actuals; other
  programmes hidden.

### REQ-BBC-003: Dashboard SHALL surface exception alerts for overspent programmes

The dashboard MUST include an **Exceptions Alert** section below the charts:

- Red-background list of programmes with Remaining < 0 (overspend).
- Each item shows: Programme name, budget, spent, committed, overspent amount.
- Sorted by overspent amount (largest first).
- Empty state: "No overspends" message if all programmes in budget.

#### Scenario: Exception list highlights all overspent programmes

- **GIVEN** provinces with 3 programmes: 2 in budget, 1 overspent by €50k
- **WHEN** dashboard renders
- **THEN** Exceptions list MUST contain exactly 1 row (overspent programme);
  message MUST show overspent amount and link to Budget-to-Programme Linker
  for remediation.

### REQ-BBL-001: Budget-to-Programme Linker SHALL allow bulk assignment of GL lines to BBV programmes

The Budget-to-Programme Linker page (type: index+detail) MUST:

1. **Index page** (`CnIndexPage`):
   - List all `GLLine` records with columns: account number, description,
     amount, current `programmaStructure` (if assigned), associated budget.
   - Sortable, paginated (default 50 rows).
   - Filter bar: account type, programme, assignment status (mapped/unmapped).

2. **Bulk action**:
   - Multi-select rows → "Link to Programme" button (disabled if 0 rows selected).
   - Button opens modal dialog (`CnFormDialog`).

3. **Link modal**:
   - Form fields:
     - **Target Programme** (dropdown, 7 values: ruimte, mobiliteit, water, etc.)
     - **Effective Date** (date picker, default today)
   - "Link" button (submit) + "Cancel" (close without saving).
   - On submit: call `ObjectService.updateObject()` for each selected line,
     setting `programmaStructure` to selected value + `programmaAssignedAt` to
     effective date.

4. **Feedback**:
   - On save success: toast notification "Linked N GL lines to [Programme]".
   - On partial failure: toast "Linked N of M GL lines; M had errors" + details
     in side panel.

#### Scenario: Bulk link GL lines to a single programme

- **GIVEN** Linker page with 10 unmapped GL lines (personnel, utilities, contracts)
- **WHEN** user selects all 10, clicks "Link to Programme", chooses `mobiliteit`,
  clicks "Link"
- **THEN** all 10 lines MUST have `programmaStructure: "mobiliteit"` + `programmaAssignedAt: today` in OR; toast shows "Linked 10 GL lines to Mobiliteit"; page refreshes showing updated status

### REQ-BBL-002: Linker SHALL validate programme assignments before saving

On modal submit, the form MUST validate:

- **Target programme MUST NOT be null** (required field).
- **Effective date MUST NOT be in the future** (warn: "Date is in future; GL
  postings must not pre-date posting date").
- **Selected GL lines MUST all be in `posted` or `committed` state** (reject:
  "Cannot link draft GL lines").

If any validation fails, modal MUST remain open and display error message
inline.

#### Scenario: Validation rejects future effective date

- **GIVEN** Linker modal with 5 GL lines selected, user picks programme +
  sets date to 2026-06-30 (future)
- **WHEN** user clicks "Link"
- **THEN** validation MUST fail; modal MUST show error "Effective date cannot
  be in the future"; no GL lines MUST be updated.

### REQ-BBL-003: Programme assignments SHALL be auditable via OpenRegister audit trail

Every `ObjectService.updateObject()` call on a GL line (via Linker or direct edit)
MUST trigger an audit-trail entry capturing:

- Before state: `programmaStructure` value (or null if unassigned)
- After state: new `programmaStructure` value
- Actor: authenticated user UID
- Timestamp: when assignment occurred
- Source: "Budget-to-Programme Linker" (if via Linker) or "Manual Edit"

Audit trail MUST be queryable via standard `AuditTrailService` methods.

#### Scenario: Audit trail shows programme assignment history

- **GIVEN** GL line 4100-mobiliteit initially with `programmaStructure: null`
- **WHEN** user assigns it to `mobiliteit` via Linker, later to `water` via
  detail edit
- **THEN** audit trail MUST contain 2 entries:
  1. null → mobiliteit (Linker, timestamp T1)
  2. mobiliteit → water (Manual Edit, timestamp T2)

### REQ-BBL-004: Linker index MUST display summary of unmapped GL lines

Index page header MUST show **Mapping Status** badge:

```
Unmapped GL lines: N of M (P%)
```

Where:
- N = GL lines with `programmaStructure: null`
- M = total GL lines in fiscal year
- P = percentage unmapped (rounded to nearest 1%)

Badge color: 🔴 Red if P > 30%, 🟡 Yellow if 10–30%, 🟢 Green if < 10%.

#### Scenario: Status badge reflects mapping progress

- **GIVEN** 100 GL lines total, 15 unmapped
- **WHEN** Linker index loads
- **THEN** badge MUST show "Unmapped GL lines: 15 of 100 (15%)" in Yellow.

### REQ-BBL-005: Budget-to-Programme assignment MUST NOT permit circular or conflicting mappings

When assigning a GL line to a programme, validation MUST check:

- **No single GL line maps to multiple programmes** (rejected: "GL line is
  already assigned to [other programme]; unmap first").
- **Programme total commitment MUST NOT exceed budget limit** (warning:
  "After linking, programme commitment will be €Xk; budget is €Yk; Z% utilisation").

For the warning case, allow operator to override (checkbox: "Accept overspend warning").

#### Scenario: Prevent double-mapping of GL line

- **GIVEN** GL line with `programmaStructure: "mobiliteit"`
- **WHEN** operator selects it + tries to link to `water`
- **THEN** validation MUST reject with message "GL line already assigned to
  Mobiliteit; unmap first if reassignment intended".

### REQ-BBC-004: Dashboard data refresh cadence SHALL be configurable via admin settings

Admin Settings page MUST include:

- **Dashboard Refresh Interval** (dropdown: real-time, hourly, daily, weekly)
- **Default:** daily (nightly batch)

When refresh is triggered:
- Dashboard queries are re-run
- KPI card values, charts, and exception list are updated
- Page MUST NOT reload; update data in-place via API poll or WebSocket
  (deferred to T4 if real-time selected; daily is fire-and-forget batch job).

#### Scenario: Admin configures nightly refresh

- **GIVEN** admin settings page
- **WHEN** admin selects "Daily" for Dashboard Refresh Interval
- **THEN** dashboard data MUST refresh once per night (UTC 02:00 TBD); no
  manual refresh button required (silent background job).

## Verification

`openspec validate` must exit clean on the change folder.

Operator walkthrough:

1. Create 2–3 Budgets for different programmes (mobiliteit, water, cultuur).
2. Post 5–10 GL lines across programmes (mix of committed and spent).
3. Open BBV Compliance Dashboard — verify KPI cards match budget vs. GL totals.
4. Apply filters — verify chart updates correctly per filter selection.
5. Open Budget-to-Programme Linker — select unmapped GL lines.
6. Bulk link to a programme — verify assignment persists in GL and audit trail.
7. Change a GL line's programme in Linker — verify audit trail captures both
   assignments.

No source code changes outside `openspec/changes/bookkeeping-provincies-bbv-variant/`.
