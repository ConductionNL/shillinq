# Proposal: bookkeeping-fixed-assets-depreciation

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`FixedAsset`, `DepreciationSchedule`) +
`x-openregister-lifecycle` consuming OR's materialization patterns
+ aggregations for depreciation tracking + manifest entries. No PHP
asset-management table, no PHP depreciation-calculation service
classes are authored (subject to ADR-031 exception: at most one
single-method `DepreciationCalculator` if OR's declarative business
logic extension is not yet stable).

## Summary

Introduce the **fixed assets & depreciation** capability for Shillinq
as one of the T2 compliance + operations capabilities. This capability
establishes the **asset register and depreciation schedule** tracking
for fixed assets used in business operations. The change declares the
`FixedAsset` and `DepreciationSchedule` registers; the depreciation
lifecycle (active → retired) with automatic yearly depreciation
calculations; depreciation-rate precision rules; handling of internal
transfer invoices with depreciation impact; and the GL posting
patterns for asset acquisition and depreciation expense.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md)
(materialises GL transactions for asset acquisition and depreciation
expense), [`add-shillinq-chart-of-accounts`](../add-shillinq-bookkeeping-foundation/specs/bookkeeping-chart-of-accounts/spec.md)
(fixed asset and depreciation expense accounts).

## Motivation

Asset management is fundamental to Dutch small/medium business (SMB)
accounting and Dutch tax compliance. The Dutch tax system (Vennootschapsbelasting)
requires precise asset-depreciation tracking for each business year.
The core features (asset registration, depreciation-rate precision,
yearly auto-calculation, internal transfer handling) align with market
demand (demand scores 63–134 across four features) and the original
Shillinq scope of comprehensive bookkeeping.

Per ADR-022, depreciation calculations come from OR's declarative
business logic extension, not from an app-local depreciation service;
per ADR-031, asset aging and depreciation analytics are declarative
aggregations, not a `DepreciationReportService`.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-fixed-assets-depreciation`); declares 2 new registers
  (`FixedAsset`, `DepreciationSchedule`) with lifecycles and
  aggregations; adds 3 manifest navigation entries (Fixed Assets,
  Depreciation Schedules, Depreciation Expense).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations`.
- [ ] Project: docudesk — no source changes; asset acquisition
  documents referenced by FK URI per future `bookkeeping-document-attachment-integration`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-fixed-assets-depreciation`) —
  see the `specs/` folder.
- The `FixedAsset` register with name, type (equipment, vehicle,
  property, building, other), purchase date, acquisition cost,
  location, depreciation method (linear, declining-balance,
  units-of-production), useful life, residual value, current status
  (active, inactive, retired), and GL account mapping for
  capitalization.
- The `DepreciationSchedule` register tracking yearly depreciation
  calculations for each asset: method, annual rate (as percentage or
  fixed amount), period (starting/ending dates), total depreciation
  amount, and current accumulated depreciation.
- The asset lifecycle (active → retired) with automatic yearly
  depreciation-expense GL postings per administration fiscal year.
- Depreciation-rate precision rules: rates MUST respect the Float
  Precision setting configured in System Settings for consistent
  display and calculation rounding.
- Asset depreciation handling for internal transfer invoices: when an
  asset is transferred between cost centers or departments, the
  depreciation schedule adjusts proportionally.
- GL materialization: asset acquisition materialises a balanced GL
  posting (debit asset account, credit cash/liability); yearly
  depreciation materialises expense posting (debit depreciation
  expense, credit accumulated depreciation).
- Depreciation aggregations: asset summary by depreciation method, by
  cost center, by fiscal year; accumulated depreciation by asset.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Multi-currency asset valuation** — T5.
- **Depreciation-method switching mid-lifecycle** — deferred; requires
  retroactive tax adjustment and audit trail of method changes.
- **Impairment testing** — T3 (asset revaluation for market changes).
- **Asset lease accounting** — IFRS 16 / Dutch lease standards deferred
  to future phase.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-fixed-assets-depreciation`** — declares the two
registers, the lifecycle (consuming OR's declarative business logic),
the float-precision rules, the internal-transfer handling, the GL
materialization pattern, and the depreciation aggregations.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-FA-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 new schemas
  (`FixedAsset`, `DepreciationSchedule`); declares lifecycle on
  `FixedAsset` and depreciation calculations.
- `src/manifest.json` — adds 3 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one
  single-method `DepreciationCalculator` if OR's declarative
  calculation extension is not yet stable).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- **T1 general ledger** — depends on `bookkeeping-general-ledger`
  for the materialised `GLTransaction` pattern (on acquisition, on
  depreciation-expense posting).
- **T1 chart of accounts** — depends on asset and depreciation-expense
  account definitions.

## Risks

### Risk 1: Float Precision configuration unavailable at calculation time

**Severity**: Medium
**Mitigation**: System Settings Float Precision is queried at
depreciation-calculation time via `IAppConfig` or Nextcloud settings
API. The spec declares the dependency explicitly; implementing cycle
verifies the configuration is available at runtime and documents the
fallback (default 2 decimal places, Dutch standard).

### Risk 2: Depreciation-method calculation complexity

**Severity**: Medium
**Mitigation**: If OR's declarative calculation extension is still draft
at T2 implementation time, the spec captures the gap, files an OR
issue, and the implementing cycle MAY ship a single-method
`OCA\Shillinq\Lifecycle\DepreciationCalculator` per ADR-031 §"PHP
guards remain a legitimate seam". The calculator is removed once OR's
extension lands. Spec is shape-neutral.

### Risk 3: Internal transfer handling requires GL adjustment

**Severity**: Low-Medium
**Mitigation**: REQ-FA-006 declares internal transfer as a lifecycle
action that adjusts the depreciation schedule proportionally. The spec
captures the pattern; implementing cycle ensures GL postings balance
and audit trail is clear.

### Risk 4: Useful-life estimates may be challenged in tax audit

**Severity**: Low
**Mitigation**: Depreciation-schedule tables are immutable (append-only)
via OR's audit trail. Changes to useful life or depreciation method
are logged with reason and timestamp per ADR-014 (audit governance).
Implementing cycle ensures audit trail is visible in the asset detail
page.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — asset records remain queryable.

## Open Questions

1. **Float Precision configuration source** — does System Settings
   provide a public API, or does the app read from Nextcloud's
   configuration database? Resolved in `opsx-ff` discovery.
2. **Depreciation-method switching** — are mid-lifecycle method changes
   permitted (with retroactive adjustment) or deferred to T3
   revaluation? Resolved per ADR-022 review and tax-compliance
   consultation.
3. **Useful-life defaults** — does Shillinq ship industry-standard
   defaults for common asset types (e.g. 5 years for vehicles, 10 years
   for buildings)? Defaults resolved during the implementing cycle's UX
   review with CFO/accountant persona.
4. **Impairment and revaluation** — is impairment-test workflow required
   for T2, or deferred to T3? Resolved per product roadmap and market
   feedback.
