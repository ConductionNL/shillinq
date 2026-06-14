# Proposal: bookkeeping-detachering-payroll-administratie

`kind: config` per ADR-032 — the centre of mass is declarative schemas (`Employee`, `Payroll`, `Deduction`, `DeterminationLetter`) + payroll lifecycle + tax/social-security-calculation aggregations + manifest entries. No PHP payroll-engine service classes (subject to ADR-031 exception: at most a single-method `PayrollCalculationGuard` if OR's calculation-workflow extension is not yet stable).

## Summary

Introduce the **payroll + detachering bridge** capability for Shillinq as one of the T2 compliance + operations capabilities (per `adr-001-bookkeeping-tier-roadmap.md`). This capability bridges the gap between HR/detachering systems and financial administration, enabling:

- Employee master data (name, BSN, contract type, tax classification, salary/wage scales)
- Payroll periods with automated wage/salary and deduction calculation
- Tax (loonbelasting), social security (SV), and pension deductions with compliance rates
- Determination letters (werkgeversverklaring, loonstrookje) for payroll documentation
- Payroll lifecycle (`draft → calculated → issued → paid`) with audit trail
- Integration with external payroll software via Peppol BIS 30 (UBL Invoice format for payroll disbursement)
- Detachering-specific handling: employee classification, onboarding/exit processing, placement fees

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md) (materialises GL transactions for salary expense postings), [`add-shillinq-accounts-payable-core`](../add-shillinq-accounts-payable-core-t2/proposal.md) (vendor master, supplier invoicing for placement fees).

## Motivation

Payroll management was identified as the **highest-demand feature** across Shillinq's market research (1592 demand score, 530 tender mentions). Dutch SMBs and staffing agencies require integrated payroll with tax/SV compliance, determination letters, and detachering support.

The legacy AP/AR/Payroll draft cluster from intelligence-db (`competitor_features` with `app_slug=shillinq`) calls out payroll + detachering + tax compliance as the cornerstone operational feature set.

This is one of eight T2 capability changes; this proposal scopes only the payroll + detachering core slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-payroll-detachering`); declares 4 new registers (`Employee`, `Payroll`, `Deduction`, `DeterminationLetter`) with lifecycles and aggregations; adds 6 manifest navigation entries (Employees, Payroll, Payroll Calendar, Deductions, Determination Letters, Tax/SV Reports).
- [ ] Project: openregister — no source changes; consumes existing lifecycle and aggregation extensions per ADR-022.
- [ ] Project: external-payroll-integrations — detachering/payroll software bridges consume the `Payroll` + `Deduction` schemas via the OpenRegister REST API.

## Scope

### In Scope

- One new capability spec (`bookkeeping-payroll-detachering`) — see the `specs/` folder.
- The `Employee` register with legal name, BSN, contract type (employee/freelancer/detached), salary classification, tax number, onboarding/exit dates, contact details.
- The `Payroll` register with employee FK, period, gross salary/wage, all deduction line items, net amount, payroll status, pay date.
- The `Deduction` register tracking individual deduction lines (loonbelasting, SV contributions, pension, garnishments) with rates and amounts per Payroll period.
- The `DeterminationLetter` register storing generated determination letters (werkgeversverklaring, loonstrookje PDF, salary certificate) with archival metadata.
- Payroll lifecycle (`draft → calculated → issued → paid`) consuming OR's lifecycle extension per ADR-022.
- Tax/SV deduction aggregations (per-employee annual totals, rate validation against statutory tables) declared via `x-openregister-aggregations`.
- Integration with external payroll software via OpenRegister REST API (apps read `Payroll` + `Deduction` + `Employee` schemas, write back via webhook).
- Detachering-specific fields: employee classification (detached/employee/freelancer), placement agency, placement fee, onboarding/exit processing.
- Payroll-to-GL materialisation: salary expense posting on payroll issue.
- UBL Invoice field shape declared for T4 payroll disbursement (NOT computed in T2).

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components, controllers, tests, and CI changes are deliberately not in this proposal; the task list references them but the implementation lands via a separate `opsx-apply` cycle.
- **UBL Peppol BIS 30 outbound** — T4. T2 declares the field shape but does NOT compute / emit.
- **Multi-currency translation** — T5.
- **Pension fund administration** — delegated to external pension providers via integration.
- **Real-time payroll sync** — T3. T2 supports webhook inbound from external payroll software; real-time bidirectional sync deferred.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-payroll-detachering`** — declares the four registers, the lifecycle (consuming OR lifecycle extension), the tax/SV aggregations, the GL materialisation pattern, payroll-to-disbursement integration, and the UBL field shape (for T4).

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-PAY-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 4 new schemas (`Employee`, `Payroll`, `Deduction`, `DeterminationLetter`); declares lifecycle on `Payroll`, aggregations on tax/SV rate validation + annual deduction totals.
- `src/manifest.json` — adds 6 navigation entries + their `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one single-method `PayrollCalculationGuard` if OR's calculation extension is not yet stable).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on lifecycle extension (ADR-022 — if stable; else ADR-031 exception path), `x-openregister-aggregations` for tax/SV validation and annual totals.
- **T1 general ledger** — depends on `add-shillinq-general-ledger` for the materialised `GLTransaction` pattern (on payroll issue: salary expense posting).
- **T2 accounts-payable-core** — depends on `add-shillinq-accounts-payable-core-t2` for supplier master (placement agencies) and placement-fee vendor invoicing.
- **External payroll software** — integration via OpenRegister REST API; webhooks inbound on employee/payroll changes.

## Risks

### Risk 1: Payroll calculation logic not yet abstracted in OR

**Severity**: Medium
**Mitigation**: If OR's calculation-workflow extension is still draft at T2 implementation time, the spec captures the gap, files an OR issue, and the implementing cycle MAY ship a single-method `OCA\Shillinq\Payroll\PayrollCalculationGuard` per ADR-031 §"PHP guards remain a legitimate seam". The guard is removed once OR's extension lands. Spec is shape-neutral.

### Risk 2: Tax/SV rates require annual statutory update

**Severity**: Low
**Mitigation**: Deduction schema includes `taxYear` and `rateSource` (statutory table reference). Annual maintenance task documented in tasks.md; no code change required — update register seed data with new rates each January.

### Risk 3: BSN privacy (PII) in audit trail

**Severity**: Medium
**Mitigation**: BSN is searchable but NOT audited (ADR-005 PII rule — audit logs use `employeeId`, not BSN). Configuration option to mask BSN on display. Compliance review per data-governance team.

### Risk 4: Payroll-to-GL posting requires ledger account configuration

**Severity**: Low
**Mitigation**: REQ-PAY-008 declares salary-expense GL account reference per `Employee` contract type. Configuration task in tasks.md; no new service required.

### Risk 5: Detachering placement-fee reconciliation with vendor invoices

**Severity**: Low
**Mitigation**: `Payroll.placementFeeAmount` materialises an AP transaction (vendor invoice) to the placement agency per `Payroll.placementAgencyId`. Reconciliation via bank-rec (T2 bookkeeping-bank-reconciliation).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR; registers are non-destructive — payroll records remain queryable.

## Open Questions

1. **Payroll-calculation stability on OR** — see Risk 1; resolved in `opsx-ff` discovery; OR issue filed if needed.
2. **Tax/SV rate data source** — statutory tables (Belastingdienst) vs. third-party tax-software API integration. Resolved in implementing cycle.
3. **Detachering classification rules** — employee vs. contractor vs. freelancer; tax treatment differs. Industry-standard classification resolved during the implementing cycle's UX review.
4. **Payroll archival compliance** — 7-year retention rule (BTW/loonbelasting); automated retention policy. Resolved per ADR on data archival.
5. **Real-time sync with external payroll** — T3 vs. T2 boundary. Current scope: T2 accepts inbound webhooks; real-time bidirectional deferred to T3.

