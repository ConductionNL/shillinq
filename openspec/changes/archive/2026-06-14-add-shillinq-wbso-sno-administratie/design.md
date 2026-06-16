# Design — WBSO / S&O Administratie

**status: pr-created**

## Context

Per Wet vermindering afdracht loonbelasting hoofdstuk VA, WBSO
requires per-project per-medewerker S&O-uren administratie + a
quarterly mededeling + kwartaalrapportage + jaarrapport to RvO.
The afdrachtvermindering loonheffing is computed from the
mededeling. Without dedicated primitives, S&O-administratie lives
in spreadsheets and the afdrachtvermindering is hand-computed.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Two registers (`SoProject`, `SoUrenStaat`), NOT a single hour-staff table

Per ADR-031 + the parent envelope's design D10, separating
`SoProject` (project metadata with RvO link) from `SoUrenStaat`
(per-medewerker-per-week-per-project hours) keeps the model normal
and lets per-project aggregations roll up cleanly. The alternative
(a denormalised hour-line table) was rejected — projects have
their own metadata + lifecycle that doesn't belong on hour-staat
rows.

### D2 — `SoUrenStaat.state` lifecycle with approval-workflow on `goedgekeurd`

Hours administration needs a goedkeurings-gate before mededeling
generation. `SoUrenStaat` declares
`x-openregister-lifecycle` with transitions `draft → goedgekeurd
→ afgesloten`. The `goedgekeurd` transition requires an
approval-workflow per ADR-022 (typically the project-leider
approves). No app-local approval table.

### D3 — Mededeling + rapportages as declarative aggregations

Each of the three RvO outputs (mededeling per quarter,
kwartaalrapportage per quarter, jaarrapport per year) is an
`x-openregister-aggregations` declaration + a docudesk template:

- **Mededeling**: sum `SoUrenStaat.aantalUren` per quarter per
  project (`state ≠ draft`).
- **Kwartaalrapportage**: per-project progress narrative + uren
  totals.
- **Jaarrapport**: annual close + per-project results.

No PHP renderer; no app-local templates.

### D4 — Afdrachtvermindering as a declarative calculation with side-by-side reconciliation

Per REQ-WBSO-006, the afdracht is computed as
`SoUrenStaat.aantalUren × medewerker.sEnOUurloon ×
actueelAfdrachtPercentage` (32% standard / 40% starters per RvO
2026 seed). This is the **projected** value. The RvO mededeling
returns the **authoritative** value for the loonaangifte. The WBSO
detail view shows both side-by-side with a delta-reconciliation
warning. No PHP afdrachts service.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Cost-center | T4-base `bookkeeping-cost-centers-dimensions` | `SoProject.costCenterId` FK. |
| Lifecycle engine | `x-openregister-lifecycle` (ADR-031) | `SoUrenStaat` declares `draft → goedgekeurd → afgesloten`. |
| Approval workflow | OR approval-workflow (ADR-022) | Required on the `goedgekeurd` transition. |
| Aggregation engine | `x-openregister-aggregations` | Quarterly + annual rollups. |
| Calculation engine | `x-openregister-calculations` (ADR-031) | Projected afdracht. |
| Document rendering | docudesk (ADR-022) | 4 templates registered. |
| External submission | openconnector (ADR-019) | RvO source row. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Submission events + access events. |
| RBAC on personnel data | OR authorization | Restricts `SoUrenStaat` read. |
| Detachering FK | Sibling `add-shillinq-detachering-payroll-administratie` | `SoUrenStaat.medewerkerId` may FK to Detachering. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.mkb-wbso` with 4 sub-pages. |

**Net new code in implementation cycle**: 2 schema declarations
(`SoProject`, `SoUrenStaat`) + 1 lifecycle declaration + 1
approval-workflow binding + 3 aggregation declarations + 1
calculation declaration + 4 docudesk templates + 1 openconnector
source + 1 manifest entry. No new PHP service.

## Seed Data

None. RvO afdrachtpercentages are referenced from a sibling/seeded
config; no per-administration WBSO seed.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| RvO afdrachtpercentages revise yearly | Percentages are config; future changes seed-update only. |
| Projected vs authoritative drift | Side-by-side display with delta reconciliation warning. |
| Hour-state lifecycle bypass | Lifecycle `requires` enforces approval-workflow on `goedgekeurd`; OR refuses `afgesloten` without prior `goedgekeurd`. |
| Privacy footprint of S&O-uren | RBAC + audit-trail-immutable per ADR-022; access events logged. |
