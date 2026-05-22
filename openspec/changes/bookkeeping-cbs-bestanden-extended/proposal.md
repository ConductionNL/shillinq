# Proposal: bookkeeping-cbs-bestanden-extended

`kind: feature` — adds IV3 financial reporting export capability to Shillinq for Dutch CBS (Centraal Bureau voor de Statistiek) submission, extending beyond the mandatory IV3 base format with optional statistical fields.

## Summary

Introduce support for generating and submitting IV3 (Italian Corporate Income Tax report) statistical data to the Dutch CBS, extending the mandatory IV3 reporting format with optional aggregated financial metrics. This change adds a new `CBSSubmission` schema and export workflow that consumes existing general ledger, chart of accounts, and financial reporting data from Tier 1-2 bookkeeping capabilities, producing a CBS-compliant submission package with validation and delivery tracking.

## Motivation

Shillinq's bookkeeping foundation (T1-T2) now supports double-entry ledger, journals, and financial reporting. Dutch organizations with reporting obligations to the CBS (Centraal Bureau voor de Statistiek) require a standardized export mechanism that aggregates bookkeeping data into the IV3 format, validates structural conformance, and tracks submission status to the CBS statistical collection system.

This change extends the bookkeeping feature set to include regulatory statistical reporting, supporting Dutch government obligations under the Statistical Reporting Act (*Verordening Statistieken Bedrijven*, VSB) and CBS data collection directives.

## Affected Projects

- [x] Project: shillinq — adds 2 new registers/schemas (`CBSSubmission`, `CBSLine`) to `lib/Settings/shillinq_register.json`, adds export controller + service for IV3 generation, adds manifest navigation entry for submissions list
- [x] Project: openregister — consumes existing OR abstractions (audit, RBAC, `x-openregister-lifecycle`, file attachments). No OR source changes required.
- [ ] Project: docudesk — no source changes; CBS submissions may reference source documents by URI if audit trail linkage is desired

## Scope

### In Scope

- `CBSSubmission` schema — header tracking submission metadata (submission number, reporting period, organization details, status, submission date)
- `CBSLine` schema — aggregated financial line items per CBS line classification (revenue, operating costs, depreciation, etc.)
- IV3 export controller and service — generates IV3-format XML/JSON from general ledger transactions, validates against CBS structural rules
- Submission lifecycle — draft → validated → submitted → accepted/rejected
- File attachment support — CBS submission package stored as downloadable file in OpenRegister
- Audit trail integration — all submission status changes tracked per ADR-022
- Manifest navigation entry for submissions list and detail views
- Seed data — 2-3 example CBS submission records with realistic Dutch organizational data

### Out of Scope

- Custom CBS API integration — submission package is generated but manual delivery to CBS portal is operator responsibility
- Advanced CBS validation rules — basic structural validation only; operators responsible for completeness validation
- Multi-period aggregation — each submission covers one reporting period; future enhancements may add rolling submissions
- Intercompany elimination rules — consolidated reporting deferred to future tier
- Predecessor/successor entity tracking — single entity per submission assumed

## Approach

Three architectural components:

1. **Data Models** — `CBSSubmission` (header) and `CBSLine` (line items) schemas align with CBS IV3 structure, supporting Dutch financial entity reporting format.

2. **Export Workflow** — `CBSExportService` consumes Account + GLTransaction + GLLine data from T1-T2 bookkeeping, aggregates by CBS line classification, generates IV3-format output (XML initially, JSON on demand).

3. **Lifecycle & Workflow** — Submissions follow a simple state machine (draft → validated → submitted → accepted/rejected), with file attachment for the generated IV3 package, audit trail for all transitions.

## Risks

1. **CBS Format Drift** — IV3 format changes from CBS without advance notice. Mitigation: design the export service to be externally configurable via manifest data; format changes are declarative, not code changes.

2. **Mapping Complexity** — GL account codes may not map cleanly to CBS line items. Mitigation: provide configurable account → CBS line mapping table in design.md; operators can override.

3. **Validation Strictness** — Over-strict validation may reject valid submissions; under-strict may allow invalid ones. Mitigation: implement basic structural checks only; operators rely on CBS portal feedback for completeness.

## Rollback

If CBS changes reporting requirements mid-cycle:
- Mark old submission records as `deprecated_by_cbs_version: "X.Y.Z"`
- Extend the export service with version-aware logic
- Create new submission records using updated schemas
- No data loss; audit trail preserved

## Open Questions

1. **XML vs JSON export format** — which should be primary? Recommend JSON initially with XML as transformation.
2. **Mapping flexibility** — should the account → CBS line mapping be hard-coded in the service, or configurable per administration?
3. **Multi-organization submissions** — support consolidated submissions covering multiple Shillinq entities, or single-entity only?

## Related

- `bookkeeping-chart-of-accounts` (Tier 1 — provides Account data)
- `bookkeeping-general-ledger` (Tier 1 — provides GLTransaction / GLLine data)
- `financial-reporting-accountability` (Tier 2 — provides FinancialReport data used in aggregation)
