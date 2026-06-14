# Proposal: bookkeeping-vpb-corporate-tax

Vpb voor Overheidsondernemingen (VPB for Government Enterprises) — comprehensive tax deadline management, reporting, and filing capability for municipal and state-owned enterprises subject to Dutch corporate income tax (vennootschapsbelasting).

## Summary

Introduce the **vennootschapsbelasting (Vpb) corporate tax administration** capability as a comprehensive bookkeeping suite focused on tax deadline management, payment tracking, and quarterly tax reporting for Dutch government enterprises.

Per Wet modernisering Vpb-plicht (2016), municipal ondernemingsactiviteiten and certain stichtingen/verenigingen are Vpb-pligtig (subject to corporate income tax). This change declares:

- A `TaxDeadline` register tracking Vpb filing deadlines, payment dates, and submission status
- A `TaxPaymentTracking` overlay for monitoring provisional and final tax payments
- A `TaxReport` aggregation providing quarterly income statement breakdowns for tax planning
- Search, filter, bulk-action, and notification capabilities for deadline management
- Tag-based tax transaction classification and reporting

The change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-chart-of-accounts`](../../specs/bookkeeping-chart-of-accounts/spec.md)
  — supplies `Account` entities for tax-related postings
- [`bookkeeping-general-ledger`](../../specs/bookkeeping-general-ledger/spec.md)
  — supplies `GLLine` entries for tax report aggregation

## Motivation

Dutch government enterprises must track Vpb filing deadlines, manage provisional and final tax payments, and prepare quarterly tax statements for planning and compliance. Without dedicated Vpb deadline/payment primitives, deadline management lives in external calendar systems or spreadsheets, tax payment tracking is manual, and quarterly reporting requires external extraction.

This change adds:
- **Tax deadline tracking** — deadlines with search, filter, bulk actions, and notifications
- **Payment tracking** — provisional and final Vpb payments with payment plan management
- **Tax reporting** — quarterly income statement breakdowns with actuals vs. plan variance
- **Transaction tagging** — classify postings by tax treatment (normal, deductible, non-deductible)
- **Filing preparation** — aggregate data for Vpb-aangifte (corporate tax return) submission

See the parent envelope's design for the canonical Vpb administrative structure.

## Affected Projects

- [x] Project: shillinq — adds `TaxDeadline`, `TaxPaymentTracking` registers; declares `TaxReport` aggregation; adds search/filter/bulk/notification capabilities; registers tag-based transaction classification
- [ ] Project: openregister — no source changes
- [ ] Project: docudesk — registers Vpb-aangifte voorbereiding template

## Scope

### In Scope

- One new capability spec (`bookkeeping-vpb-corporate-tax`) — see the `specs/` folder
- `TaxDeadline` register: deadline date, deadline type (provisional payment, final return, VAT filing), submission status, related fiscal year/period
- `TaxPaymentTracking` overlay: payment date, payment type (provisional, final, adjustment), amount, account reference
- `TaxReport` aggregation: quarterly income statement with revenue, expenses, tax adjustments, producing net taxable income
- Search, filter, bulk-action capabilities for tax deadline management
- Notification system for upcoming deadlines (7 days, 1 day before due date)
- Tag-based transaction classification (tax treatment: normal, deductible, non-deductible, special)
- Tax report generation in Excel/PDF format
- Manifest navigation entry behind `featureFlags.vpb` with index (deadlines), detail (payment tracking + quarterly reports), and settings pages

### Out of Scope

- **VPB return preparation** — owner by docudesk Vpb-aangifte template
- **SBR-XBRL transmission** — owned by `bookkeeping-sbr-xbrl-reporting`
- **Innovatiebox / investeringsaftrek** — owned by sibling changes
- **Multi-currency tax adjustments** — owned by `bookkeeping-multi-currency`
- **Advanced tax planning simulation** — deferred to future capability

## Approach

Three deltas:

1. **Core registers** — `TaxDeadline` and `TaxPaymentTracking` with required fields, status tracking, and relations to fiscal years/periods
2. **Aggregation & reporting** — `TaxReport` aggregation filtering `GLLine` by period and tax-tag classification, producing quarterly breakdowns
3. **Frontend capabilities** — search, filter, bulk action dialogs, notification dispatch, and tax report export

Each requirement is prefixed `REQ-VPB-*`. RFC 2119 keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN.

## New Dependencies

None beyond the shared `@conduction/nextcloud-vue` (already bumped). Consumes existing OpenRegister abstractions (ObjectService, AuditTrail, FileService for report export).

## Impact

- `lib/Settings/shillinq_register.json` — adds `TaxDeadline` and `TaxPaymentTracking` registers; declares `TaxReport` aggregation
- `src/manifest.json` — adds navigation entries for deadline management, payment tracking, and quarterly reports behind `featureFlags.vpb`
- `src/store/modules/` — Pinia store for deadline/payment list state (via `createObjectStore`)
- `src/views/` — Index page (deadline list with search/filter), detail pages (deadline detail, payment tracking, quarterly reports)
- No new PHP services; leverages `ObjectService`, `NotificationService`, `IndexService`, `ExportService`

## Cross-Project Dependencies

- **OpenRegister** — `ObjectService` CRUD, `IndexService` search/filter, `NotificationService` deadline reminders, `ExportService` report export
- **docudesk** — Vpb-aangifte voorbereiding template (future integration)
- **T4-base SBR-XBRL** — SBR endpoint transmission (future integration)

## Risks

### Risk 1: Tax deadline calendar varies by municipality and fiscal year

**Severity**: Low
**Mitigation**: Deadlines are operator-curated per fiscal year. An optional `TaxDeadlineTemplate` register can be pre-populated per municipality code. Updates handled via repair steps.

### Risk 2: Payment tracking divergence from GL postings

**Severity**: Low
**Mitigation**: `TaxPaymentTracking` records are linked to GL postings by account + amount + date. A reconciliation view warns on unmatched payments.

### Risk 3: Quarterly tax report accuracy dependent on correct transaction tagging

**Severity**: Medium
**Mitigation**: Tax report aggregation includes a warning count of untagged postings. Bookkeeper must ensure all tax-relevant postings carry appropriate tags.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder. Post-implementation rollback follows the standard additive-field pattern: retire the `vpb` feature flag and archive the registers without deleting historical records.

## Open Questions

1. **Tax deadline templates per municipality** — should `TaxDeadlineTemplate` be pre-loaded per KvK municipality code, or manually authored?
2. **Provisional payment schedules** — does the quarterly income statement include estimated tax liability and payment plan suggestion?
3. **Multi-entity consolidation** — should tax reports aggregate across multiple administrations for group filing?
