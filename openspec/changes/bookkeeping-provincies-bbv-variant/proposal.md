# Proposal: bookkeeping-provincies-bbv-variant

`kind: feature` per ADR-032 — the centre of mass is compliance dashboard
UI + programme structure mapping. Two manifest pages (BBV compliance
dashboard, budget-to-programme linking), schema extensions for
programmaStructure overlay, no new PHP services beyond OR lifecycle
bindings.

## Summary

Introduce the **BBV compliance dashboard and programme structure
mapping** capability for Shillinq as a T3 reporting feature aligned
with Dutch BBV (Begrotings- en Verantwoordingsstelsel) governance
framework. Provincies use BBV with seven canonical programme
structures (ruimte, mobiliteit, water, milieu, cultuur, economie,
bestuur) instead of gemeente-taakvelden. This change declares two
manifest pages: (1) a compliance dashboard showing budget vs. actuals
by programme, with trend analysis and exception alerts, and (2) a
budget-to-programme linker enabling operators to map budget lines to
the official BBV programme taxonomy. Both pages consume existing
OpenRegister abstractions (`CnDashboardPage`, `CnDataTable`,
`CnFilterBar`).

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md)
spec for app structure and Conduction's component library standards.

**Depends on:**
- [`add-shillinq-provincies-bbv-variant`](../../specs/add-shillinq-provincies-bbv-variant/spec.md)
  — the BBV-base register and variant enum that this feature builds upon.

## Priority & Demand

- **Priority:** P1-high
- **Market demand:** 165 tender mentions (demand score 165)
- **Regional focus:** Dutch provinces, BBV governance authorities

## Motivation

Provinces operating under BBV framework require real-time visibility
into spending against programme budgets. Legacy tools (Excel, SAP)
force manual reconciliation between GL postings and programme
structures, creating audit risk and limiting insight into budget
health. Shillinq's BBV foundation (`add-shillinq-provincies-bbv-variant`)
already supports the variant, but lacks operator-facing tools to:
1. View compliance status (spend vs. budget by programme)
2. Map budget lines to programmes (required for GL posting alignment)

Without these, a province must maintain a separate administrative
layer for BBV compliance tracking.

## Affected Projects

- [x] Project: shillinq — adds 2 manifest pages (BBV Compliance Dashboard,
  Budget-to-Programme Linker); no new PHP services; uses `CnDashboardPage`,
  `CnDataTable`, `CnFormDialog` from shared library.
- [ ] Project: openregister — no source changes.

## Scope

### In Scope

- Two new manifest pages:
  1. **BBV Compliance Dashboard** (type: dashboard) — displays 4 KPI cards
     (total budget, committed, spent, remaining), budget vs. actuals chart
     by programme, compliance traffic-light (green <85%, yellow 85-100%, red >100%),
     top exceptions (budget lines in overspend).
  2. **Budget-to-Programme Linker** (type: index+detail) — CnIndexPage with
     bulk-link dialog; operators select budget lines and map to official BBV
     programmes; assigns `programmaStructure` value to GL account posting rules.
- Dashboard data sourced from `Budget` + `GLLine` + `GLTransaction` (existing
  registers); no new schema needed.
- Budget-to-programme mapping stored as `programmaStructure` assignment on
  `GLLine` records (extends existing field via overlay).
- Manifest entries behind `featureFlags.gov-provincie` (requires BBV variant active).

### Out of Scope

- **Forecast models** — dashboard shows actuals only; forecast logic deferred to T4.
- **RFQ / procurement integration** — budget tracking only; no purchase-order coupling.
- **Multi-year rolling budgets** — fiscal-year scoped only.
- **Custom programme hierarchies** — hardcoded 7 canonical programmes; no admin
  customization (aligns with BBV legislation).

## Approach

Two deltas:

**`bookkeeping-bbv-compliance-dashboard`** — dashboard spec declaring the KPI
layout, chart dimensions, filter schema, and data queries consumed from existing
registers.

**`bookkeeping-budget-to-programme-linker`** — capability spec for bulk budget-line
mapping with `programmaStructure` assignment logic.

Both specs follow conduction-schema format (RFC 2119, `REQ-BBC-*` and `REQ-BBL-*`
prefixes, `#### Scenario:` with GIVEN/WHEN/THEN).

## New Dependencies

None. Consumes existing OpenRegister abstractions and
`@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `src/manifest.json` — adds 2 pages with routes, icons, feature-flag guards.
- `lib/Settings/shillinq_register.json` — no new schemas; extends `GLLine`
  `programmaStructure` field if not yet present.
- Dashboard data queries via existing `ObjectService` + `IndexService` (no
  new controller).
- Budget-to-programme mapping applied via standard `ObjectService.updateObject()`
  (no new service).

## Cross-Project Dependencies

- **OpenRegister** — consumes `CnDashboardPage`, `CnDataTable`, form dialogs
  from shared library; no new backend methods.

## Risks

### Risk 1: Programme structure variations across provinces

**Severity**: Low
**Mitigation**: Spec locks to the 7 official BBV programmes per legislation.
Provinces with local variations must map to official taxonomy (not supported
by this change).

### Risk 2: Budget GL account mapping complex in legacy deployments

**Severity**: Medium
**Mitigation**: Bulk-link UI includes preview + validation before save; operators
can review and correct assignments batch by batch.

## Rollback Strategy

Manifest-only change. To roll back: remove 2 pages from `src/manifest.json`.
`programmaStructure` assignments on `GLLine` records persist (non-destructive).

## Open Questions

1. **Dashboard refresh cadence** — real-time vs. nightly batch? Defer to UX review;
   recommend nightly for large datasets (provinces with 10k+ GL lines).
2. **Budget-to-programme automation** — should the linker suggest mappings based
   on GL account naming convention? Defer to ML/NLP phase (T4+).
