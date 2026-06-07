# Specification — Waterschappen BBV Variant (BBVW)

**Spec Category:** governance / budget compliance  
**Spec ID:** bookkeeping-waterschappen-bbv-variant  
**Depends on:** add-shillinq-chart-of-accounts, add-shillinq-general-ledger

---

## REQ-BBVW-001: BBV Programme Master Register

Create a `BBVProgramme` register to store the policy programme taxonomy per fiscal year.

**Description:**  
The `BBVProgramme` register holds the official BBV programme codes and descriptions used by a waterboard to classify budget and spending. Each programme is tied to a fiscal year and cannot be deleted (only marked inactive).

### Schema

```json
{
  "schema": "BBVProgramme",
  "properties": {
    "programmeName": {
      "type": "string",
      "title": "Programme name",
      "description": "Human-readable name of the BBV programme (e.g., 'Core Administration')",
      "example": "Core Administration"
    },
    "programmeCode": {
      "type": "string",
      "title": "BBV programme code",
      "description": "Unique code per Dutch BBV standard (e.g., '1.1.1', '2.3.2')",
      "example": "1.1.1",
      "pattern": "^\\d+\\.\\d+(\\.\\d+)?$"
    },
    "description": {
      "type": "string",
      "title": "Programme description",
      "description": "Detailed explanation of the programme scope and responsibilities"
    },
    "fiscalYear": {
      "type": "integer",
      "title": "Fiscal year",
      "description": "Fiscal year for which this programme applies (e.g., 2026)",
      "example": 2026
    },
    "status": {
      "type": "string",
      "enum": ["active", "archived"],
      "title": "Lifecycle status",
      "description": "Active programmes are in use; archived are historical"
    }
  },
  "required": ["programmeName", "programmeCode", "fiscalYear"],
  "relations": [
    {
      "name": "administrations",
      "type": "many-to-one",
      "target": "Administration",
      "description": "Waterboard administration owning this programme set"
    }
  ]
}
```

#### Scenario: Admin creates a new BBV programme

**GIVEN** a logged-in Nextcloud admin for waterboard "Rijn & IJssel"  
**WHEN** the admin opens the BBV Compliance settings and clicks "Add Programme"  
**THEN**
- A form dialog appears with fields: Programme Code, Name, Description, Fiscal Year
- The admin enters code "1.1.1", name "Core Administration", fiscal year 2026
- On save, the `BBVProgramme` record is created with `status = "active"`
- The programme appears in the programme master list

---

## REQ-BBVW-002: Budget-to-BBV-Programme Mapping Register

Create a `BudgetBBVMapping` register to link GL accounts to BBV programmes with allocation percentages.

**Description:**  
The `BudgetBBVMapping` register establishes the many-to-many relationship between a GL account and one or more BBV programmes. An allocation percentage specifies what portion of that account's budget/spend is attributed to each programme. Percentages must sum to 100% per account per fiscal year.

### Schema

```json
{
  "schema": "BudgetBBVMapping",
  "properties": {
    "glAccountNumber": {
      "type": "string",
      "title": "GL account number",
      "description": "The GL account code being mapped (e.g., '4100', '5000')",
      "example": "4100"
    },
    "allocationPercentage": {
      "type": "number",
      "title": "Allocation percentage",
      "description": "Percentage of this account's budget/spend attributed to the linked programme (0–100)",
      "minimum": 0,
      "maximum": 100,
      "example": 50
    },
    "effectiveFrom": {
      "type": "string",
      "format": "date",
      "title": "Effective date",
      "description": "Date when this mapping becomes active",
      "example": "2026-01-01"
    },
    "effectiveTo": {
      "type": "string",
      "format": "date",
      "title": "End date",
      "description": "Date when this mapping expires (null = no end date)"
    }
  },
  "required": ["glAccountNumber", "allocationPercentage", "effectiveFrom"],
  "relations": [
    {
      "name": "bbvProgramme",
      "type": "many-to-one",
      "target": "BBVProgramme",
      "description": "The BBV programme this GL account is allocated to"
    },
    {
      "name": "glAccount",
      "type": "many-to-one",
      "target": "Account",
      "description": "Cross-reference to the Chart of Accounts register (T1)"
    },
    {
      "name": "administrations",
      "type": "many-to-one",
      "target": "Administration",
      "description": "The waterboard administration owning this mapping"
    }
  ]
}
```

#### Scenario: Admin maps GL account to multiple programmes

**GIVEN** a logged-in admin and fiscal year 2026 with programmes "1.1.1" and "1.2.1" created  
**WHEN** the admin opens "Budget Mapping" and creates a new mapping for GL account 4100 (Personnel)  
**THEN**
- A detail page appears with fields: GL Account, Programme, Allocation %, Effective From, Effective To
- Admin selects GL 4100, Programme "1.1.1", Allocation 50%, Effective From 2026-01-01
- On save, the mapping is created; validation confirms the total for GL 4100 is ≤ 100%
- Admin then creates a second mapping: GL 4100, Programme "1.2.1", Allocation 30%
- Admin creates a third: GL 4100, Programme "2.4.1", Allocation 20%
- Total = 100% → validation passes
- The mapping list shows all three records for GL 4100

#### Scenario: Validation prevents over-allocation

**GIVEN** GL account 4100 already has mappings totaling 90% to existing programmes  
**WHEN** the admin tries to create a new mapping: GL 4100 → Programme X, Allocation 15%  
**THEN**
- Validation error: "Total allocation for GL 4100 would be 105%. Maximum is 100%."
- Mapping is not saved
- Admin must reduce an existing mapping or leave the new allocation at ≤ 10%

---

## REQ-BBVW-003: BBV Compliance Dashboard

Implement a dashboard displaying BBV compliance status by programme.

**Description:**  
The BBV Compliance Dashboard provides a real-time view of budget utilization per programme, compliance status, and variance metrics. It aggregates GL spend data against BBV programme allocations and renders status indicators.

### Dashboard Composition

The dashboard uses `CnDashboardPage` with the following widgets:

1. **KPI Cards** (CnStatsBlock):
   - Total Programmes (count)
   - On-Track Programmes (count)
   - At-Risk Programmes (count)
   - Non-Compliant Programmes (count)

2. **Compliance Status Distribution** (CnChartWidget — pie chart):
   - Slices: On-Track (green), At-Risk (amber), Non-Compliant (red), Unconfigured (grey)

3. **Programme Utilization Table** (CnDataTable):
   - Columns: Programme Code, Programme Name, Budgeted Amount, YTD Spend, Utilization %, Status
   - Sortable by any column
   - Inline status badge: 🟢 On-Track (0–75%), 🟡 At-Risk (75–90%), 🔴 Non-Compliant (>90%), ⚪ Unconfigured

4. **YTD Spend Trend** (CnChartWidget — line chart):
   - X-axis: months of fiscal year
   - Y-axis: cumulative spend as % of budget per programme

#### Scenario: Finance officer views compliance status

**GIVEN** a logged-in finance officer for waterboard "Rijn & IJssel"  
**AND** fiscal year 2026 with programmes and GL allocations configured  
**AND** GL spend recorded through January–May 2026  
**WHEN** the officer opens "BBV Compliance Dashboard"  
**THEN**
- Dashboard loads showing 5 programmes, 3 on-track, 1 at-risk, 1 unconfigured
- Pie chart shows the distribution: 60% green, 20% amber, 20% grey
- Table shows each programme with budgeted, YTD, and utilization %
- Programme "1.1.1" shows 45% utilization (on-track)
- Programme "2.3.2" shows 82% utilization (at-risk, amber badge)
- Programme "3.1.0" shows 0% utilization (unconfigured — no GL mappings)
- Line chart shows cumulative spend trending below budget for all programmes

#### Scenario: At-risk programme requires attention

**GIVEN** Programme "2.3.2" is at 82% utilization with 3 months remaining in fiscal year  
**WHEN** the officer hovers over the "At-Risk" status badge on the dashboard  
**THEN**
- A tooltip appears: "Projected to exceed budget if current spend rate continues. Review allocations."
- The officer can click the programme row to open its detail page with options to amend allocations or view GL transactions

---

## REQ-BBVW-004: Budget-to-Programme Mapping UI

Implement index and detail pages for managing budget-to-programme mappings.

**Description:**  
Users with admin privileges can view, create, edit, and delete `BudgetBBVMapping` records through a schema-driven interface.

### Index Page (CnIndexPage)

- **Title:** Budget Mapping
- **Columns:** GL Account, Programme, Allocation %, Effective From, Effective To, Status
- **Actions bar:** Add, Search, Filter
- **Search:** By GL account number or programme code
- **Filter:** By fiscal year, allocation range, effective date range

#### Scenario: Admin views current-year mappings

**GIVEN** a logged-in admin opening "Budget Mapping"  
**WHEN** the page loads (fiscal year 2026 implicit from current Administration context)  
**THEN**
- Index table shows all `BudgetBBVMapping` records for fiscal 2026
- 8 rows visible: GL 4100 → 3 programmes, GL 5000 → 2 programmes, GL 6200 → 2 programmes, GL 7100 → 1 programme
- Search box allows filtering by "4100" → shows only the 3 mappings for that account
- Status column shows all "active" (effectiveFrom ≤ today ≤ effectiveTo or effectiveTo is null)

### Detail Page (CnDetailPage)

- **Title:** Edit Budget Mapping (or "New Budget Mapping" for create)
- **Form fields:**
  - GL Account (dropdown picker, auto-complete by account number/name)
  - BBV Programme (dropdown picker, auto-complete by code/name)
  - Allocation Percentage (number input, 0–100)
  - Effective From (date picker)
  - Effective To (date picker, optional)
  - Status (radio: active / archived)
- **Actions:** Save, Delete (if existing), Cancel
- **Sidebar:** Audit trail (CnObjectSidebar with audit tab)

#### Scenario: Admin creates a new mapping

**GIVEN** the admin opens Budget Mapping → Add button  
**WHEN** a new detail page opens  
**THEN**
- Form fields are empty except Status (defaults to "active") and Effective From (defaults to today)
- Admin selects GL Account 4100 → name "Personnel Expenses" appears below
- Admin selects Programme 1.1.1 → name "Core Administration" appears below
- Admin enters Allocation % 50
- Admin clicks Save
- Validation checks: GL 4100 total allocation ≤ 100%, dates are valid
- If OK, record is created and user returns to index
- If validation fails, error message appears inline (e.g., "Allocation would exceed 100% for this account")

---

## REQ-BBVW-005: Aggregation — Compliance Status Computation

Implement aggregation logic to compute each BBV programme's compliance status in real time.

**Description:**  
Compliance status is derived from GL spend and budget allocation data via an aggregation query (per ADR-031 — declarative, not imperative). No stored compliance-status field.

### Computation Rules

```
For each BBVProgramme P in fiscal year FY:

  TotalBudget(P) = SUM(
    GLAmount where GLAccountNumber appears in BudgetBBVMapping.glAccountNumber
    and BudgetBBVMapping.bbvProgramme = P
    and BudgetBBVMapping.allocationPercentage = X
  ) × (X / 100)
  
  YTDSpend(P) = SUM(
    GLTransaction.amount where GLTransaction.date ≤ TODAY
    and GLTransaction.glAccountNumber appears in mappings for P
  ) × allocation percentage per mapping
  
  Utilization(P) = YTDSpend(P) / TotalBudget(P)
  
  ComplianceStatus(P) =
    - "unconfigured" if no mappings exist for any GL account
    - "on-track" if Utilization ≤ 75%
    - "at-risk" if 75% < Utilization ≤ 90%
    - "non-compliant" if Utilization > 90%
```

#### Scenario: Compliance status updates as GL transactions are recorded

**GIVEN** Programme "2.3.2" with:
- Budgeted €100,000 (GL 5000 fully allocated)
- 0 GL transactions recorded yet

**WHEN** the admin views the dashboard  
**THEN** Programme "2.3.2" shows Utilization 0%, Status "on-track", Spend €0

**WHEN** GL transactions totaling €65,000 are recorded to GL 5000 in fiscal 2026  
**THEN** the dashboard updates (on page refresh or real-time refresh): Utilization 65%, Status "on-track"

**WHEN** additional GL transactions totaling €20,000 are recorded (total €85,000)  
**THEN** dashboard updates: Utilization 85%, Status "at-risk" (amber badge)

**WHEN** additional transactions total €96,000  
**THEN** dashboard updates: Utilization 96%, Status "non-compliant" (red badge)

---

## REQ-BBVW-006: Fiscal Year Scoping

All BBV compliance queries and UI views are automatically scoped to the current administration's fiscal year.

**Description:**  
The BBV variant inherits the fiscal-year context from Shillinq's Administration entity (created in T1). All programme, mapping, and dashboard queries filter by the current fiscal year of the active administration.

#### Scenario: Fiscal year context is implicit

**GIVEN** a logged-in user viewing the BBV Compliance Dashboard for waterboard "Rijn & IJssel"  
**AND** the administration's current fiscal year is 2026  
**WHEN** the dashboard loads  
**THEN**
- All widgets display data scoped to fiscal year 2026 only
- GL transactions from prior fiscal years (2024, 2025) are excluded
- The user sees a label or breadcrumb indicating "FY 2026"
- If the user switches to a different administration or fiscal year, data updates automatically

---

## REQ-BBVW-007: Audit Trail Integration

All changes to BBV programmes and mappings are automatically captured in the immutable audit trail.

**Description:**  
Per Shillinq's bookkeeping-audit-trail integration, every create, read, update, delete action on `BBVProgramme` and `BudgetBBVMapping` registers is logged by OpenRegister's audit service. No app-local audit service.

#### Scenario: Changes are audited

**GIVEN** an admin creates a new BBV programme "4.2.0" – "Strategic Communications"  
**WHEN** the record is saved  
**THEN** OpenRegister's audit trail captures:
- Timestamp
- User ID (from Nextcloud session)
- Action: "create"
- Object: BBVProgramme, id = <uuid>
- Before state: (none)
- After state: { programmeName: "Strategic Communications", programmeCode: "4.2.0", ... }

**WHEN** the admin later edits the programme name to "Communications & Strategy"  
**THEN** audit captures:
- Action: "update"
- Before state: { ..., programmeName: "Strategic Communications", ... }
- After state: { ..., programmeName: "Communications & Strategy", ... }

---

## REQ-BBVW-008: Validation Rules

Implement schema-level validation for BBV programme and mapping data.

**Description:**  
Validation occurs at the schema level (OpenRegister), preventing invalid data from being persisted.

### Programme Validation

- **programmeName**: required, non-empty string, max 255 characters
- **programmeCode**: required, matches regex `^\\d+\\.\\d+(\\.\\d+)?$` (e.g., "1.1", "1.1.1", "2.3.2.1"), must be unique per administration per fiscal year
- **fiscalYear**: required, integer ≥ 1900, ≤ 2100
- **status**: required, enum: active | archived

### Mapping Validation

- **glAccountNumber**: required, must exist in Chart of Accounts (FK reference validation)
- **allocationPercentage**: required, number 0–100, precision 0.01 (two decimal places)
- **effectiveFrom**: required, valid ISO 8601 date
- **effectiveTo**: optional, valid ISO 8601 date, must be ≥ effectiveFrom if present
- **Per-account sum**: total allocation percentages for a single GL account in a single fiscal year must equal 100% (±0.1% tolerance for rounding)

#### Scenario: Validation rejects invalid data

**GIVEN** an admin enters a new BBV programme with:
- programmeCode: "1-2-3" (invalid format, uses hyphens)

**WHEN** the admin clicks Save  
**THEN** validation fails with error: "Programme code must match format (e.g., '1.1' or '1.1.1')"

**GIVEN** an admin creates mappings for GL 4100:
- GL 4100 → Programme 1.1.1, 45%
- GL 4100 → Programme 1.2.1, 35%
- GL 4100 → Programme 2.4.1, 25%
- Total = 105%

**WHEN** the admin tries to save the 3rd mapping  
**THEN** validation fails: "Total allocation for GL 4100 is 105%. Must be ≤ 100%."

---

## REQ-BBVW-009: i18n — Translations

All user-facing strings use translation keys (English) with Dutch (nl) translations.

**Description:**  
Per ADR-007, all UI text is translated via `t(appName, 'key')` in Vue and `$this->l10n->t('key')` in PHP.

### Translation Keys

| Key | English | Dutch (nl) |
|---|---|---|
| `BBV Compliance Dashboard` | BBV Compliance Dashboard | BBV-conformiteitsoverzicht |
| `Budget Mapping` | Budget Mapping | Budgetopbrengstoewijzing |
| `Programme Code` | Programme Code | Programmacode |
| `Allocation Percentage` | Allocation Percentage | Toewijzingspercentage |
| `Effective From` | Effective From | Van kracht vanaf |
| `Compliance Status` | Compliance Status | Nalevingsstatus |
| `On-Track` | On-Track | Op schema |
| `At-Risk` | At-Risk | Risico |
| `Non-Compliant` | Non-Compliant | Niet-conform |
| `Unconfigured` | Unconfigured | Niet geconfigureerd |

---

## Acceptance Criteria Summary

✓ REQ-BBVW-001: BBVProgramme register created with schema validation  
✓ REQ-BBVW-002: BudgetBBVMapping register created with many-to-one relations  
✓ REQ-BBVW-003: BBV Compliance Dashboard implemented with 4 widgets  
✓ REQ-BBVW-004: Budget Mapping index and detail pages with CRUD operations  
✓ REQ-BBVW-005: Compliance status computed via aggregation query  
✓ REQ-BBVW-006: Fiscal year scoping applied to all queries and views  
✓ REQ-BBVW-007: Audit trail integration enabled automatically  
✓ REQ-BBVW-008: Validation rules enforced at schema level  
✓ REQ-BBVW-009: All strings translated (English primary, Dutch secondary)
