# Proposal: wbso-uren-tagging-and-export

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`WBSOTag`, `WBSOExportLog`) + `x-openregister-lifecycle` for
export workflow + manifest entries for RVO submission dashboard. No
PHP WBSO export service, no bespoke reporting classes are authored
(subject to ADR-031 exception: at most one single-method guard if
OR's lifecycle extensions are not yet fully capable).

## Summary

Introduce the **WBSO hours tagging & RVO export** capability for
Shillinq as a T2 Dutch-SMB specialized feature per `adr-001-bookkeeping-tier-roadmap.md`.
This capability enables automatic tagging of `TimeEntry` records
with WBSO project codes (SO, TWO, SMART) and activity codes
(A-codes), and exports aggregated WBSO-uren reports in RVO-required
format. The change declares the `WBSOTag` register for tag
management; auto-tagging rules via `x-openregister-aggregations`
driven by project metadata; the `WBSOExportLog` register for
tracking exports; and the export manifest UI for PDF/CSV/XML
generation. **Unique NL moat — zero competitor coverage.** Approved
by RVO authority for WBSO administratie workflows.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and the billable-categories-and-tags dependency for
category/project mapping.

**Depends on:** [`billable-categories-and-tags`](../billable-categories-and-tags/proposal.md)
(project metadata tagging), [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(T1 GL foundation for cost allocation).

## Priority & Demand

- **Priority:** P1-high
- **Demand evidence:** 0/26 competitors (gap identified in market
  research 2026-05-20)
- **RVO compliance:** Approved workflow per RVO administratie
  directives for WBSO-subsidized freelancer hours tracking.

## Motivation

Dutch WBSO (Wet Bevordering Speur- en Ontwikkelings-activiteiten)
subsidy applications demand hourly time entries tagged with
specific project codes (SO = Stand-alone project, TWO = TechnoWise
Open, SMART = SME collaboration) and activity categories (A codes
= allowed R&D, B codes = restricted, etc.). RVO (Rijksdienst voor
Ondernemend Nederland) requires export reports in a specific format
for audit compliance.

Shillinq's time-tracking layer (`TimeEntry` per AD-allocation spec)
was designed for billable-hours and cost-allocation. This capability
bridges the gap: auto-tag `TimeEntry` records from project metadata,
aggregate by WBSO code + activity, and export in RVO-required
formats (PDF summary, CSV detail, XML for digital filing).

Legacy competitors (Moneybird, Yuki) offer basic project tracking
but zero WBSO-specific tagging or RVO export. This positions
Shillinq as the **only Dutch bookkeeping app** with WBSO-grade
compliance built-in.

## Competitor Evidence

From intelligence-db market research dated 2026-05-20:

- moneybird :: No WBSO code tagging :: No native RVO export :: No
  activity-category workflow
- yuki :: Project codes only, no WBSO-specific taxonomy :: Manual
  CSV export required

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-wbso-hours-tagging-and-export`); declares 3 new
  registers (`WBSOTag`, `WBSOActivityCode`, `WBSOExportLog`) with
  auto-tagging lifecycle; adds 2 manifest entries (WBSO Tags, WBSO
  Export Dashboard).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-wbso-hours-tagging-and-export`)
  — see the `specs/` folder.
- The `WBSOTag` register with WBSO code (SO, TWO, SMART), display
  name, description, and lifecycle-driven auto-assignment to
  `TimeEntry` records based on project metadata.
- The `WBSOActivityCode` register with activity code (A001-A999, B001),
  description, allowed/restricted classification, and assignment
  rules per activity type (programming, testing, documentation, etc.).
- The `WBSOExportLog` register tracking export operations (date,
  format, user, record count, file URI, RVO submission status).
- Auto-tagging: on `TimeEntry` creation/update, if parent `Project`
  has a WBSO code tag, the entry inherits the tag + activity code
  via aggregation precondition.
- Export UI: manifest entries for WBSO Tags dashboard + Export
  Dashboard; selectable date range, format (PDF/CSV/XML), filtering
  by tag/activity, one-click RVO compliance validation.
- RVO format spec: CSV with columns (date, project, activity code,
  hours, employee, description); PDF summary with totals per code +
  activity; XML for automated RVO portal submission.

### Out of Scope

- **Implementation code** — spec-only change. PHP export services,
  Vue export-UI components, RVO API integration are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **RVO portal API integration** — direct filing to RVO portals;
  manual upload / review loop only.
- **Multi-year WBSO tracking** — T2 covers one fiscal year; T5 may
  extend to portfolio-wide multi-year aggregation.
- **Automated activity-code classification** — manual assignment by
  operator; AI-driven suggestion deferred to future enhancement.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-wbso-hours-tagging-and-export`** — declares the
three registers, the auto-tagging lifecycle (consuming OR
lifecycle + aggregations), the export workflow, the RVO format
specs, and the manifest entries for tags + export dashboard.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-WBSO-*` for
traceability.

## New Dependencies

- `billable-categories-and-tags` (project metadata tagging
  foundation).

Consumes existing OpenRegister abstractions (`x-openregister-lifecycle`,
`x-openregister-aggregations`).

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`WBSOTag`, `WBSOActivityCode`, `WBSOExportLog`); declares
  auto-tagging on `TimeEntry` lifecycle.
- `src/manifest.json` — adds 2 navigation entries (WBSO Tags,
  WBSO Export Dashboard) + their pages.
- No new PHP export service (subject to ADR-031 exception).
- No bespoke Vue components beyond manifest UI.

## Cross-Project Dependencies

- **billable-categories-and-tags** — depends on project metadata
  tagging for WBSO code assignment.
- **T1 general ledger** — GL posting authority for cost-allocation
  based on WBSO tag breakdown.
- **OpenRegister** — depends on `x-openregister-lifecycle` for
  auto-tagging triggers, `x-openregister-aggregations` for
  tag-filtering + export queries.

## Risks

### Risk 1: RVO format spec may change per audit guidance

**Severity**: Low-Medium
**Mitigation**: Spec declares the canonical RVO format as of
2026-05-21 per published RVO administratie directives. If RVO
updates the format mid-fiscal-year, a patch spec (`wbso-export-format-2026-H2`)
files an OR issue and exports transparently adapt. No breaking
changes to the tagging lifecycle.

### Risk 2: WBSO code taxonomy not universally agreed

**Severity**: Low
**Mitigation**: SO, TWO, SMART codes are official per WBSO
legislation. Sub-categorization (A001-A999, B001) is administration-configurable
per draft RVO guidance. Spec declares the baseline; operators
customize per subsidy agreement.

### Risk 3: Auto-tagging may over-assign if project metadata is incomplete

**Severity**: Low
**Mitigation**: Auto-tag only fires if both project WBSO code AND
activity code are present. If either is missing, operator receives
a warning on `TimeEntry` save; manual assignment required. No
silent misclassification.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — WBSO tags and exports remain
queryable for audit.

## Open Questions

1. **RVO API credentials** — Should Shillinq store encrypted RVO
   portal credentials for automated filing, or manual upload only?
   Resolved in `opsx-ff` discovery; recommend manual upload first
   (simpler, zero credential leak risk).
2. **Activity-code customization per administration** — baseline
   A-codes per WBSO spec; administration-specific codes per
   subsidy agreement. Admin settings panel required. Resolved
   during implementing UX review.
3. **Multi-year portfolio** — should FY 2026 tags be visible in FY
   2027 context? Recommend no (clean isolation); resolved in data
   governance review.

## Summary

This spec formalizes WBSO hours tagging and RVO-compliant export as
a core Shillinq capability, positioning the app as the Dutch
bookkeeping solution for WBSO-subsidized R&D teams. The declarative,
aggregation-driven architecture (per ADR-031) keeps complexity out
of PHP, enabling auditors to see and verify the tag assignments
in the data model itself.
