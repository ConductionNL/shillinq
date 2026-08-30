# Proposal: bookkeeping-schatkistbankieren

`kind: monitoring` per ADR-032 — the centre of mass is declarative
governance schemas (`TreasuryAccount`, `ComplianceReport`, `BankingRule`)
+ `x-openregister-calculations` for compliance scoring and `x-openregister-aggregations`
for regulatory reporting. No PHP approval table, no PHP compliance-service classes
are authored (subject to ADR-031 exception: at most one single-method `ComplianceValidator`
if the engine cannot express the conditional precondition).

## Summary

Introduce the **schatkistbankieren (treasury banking) compliance** capability
for Shillinq as a T2 governance capability per `adr-001-bookkeeping-tier-roadmap.md`.
This change declares the `TreasuryAccount`, `BankingRule`, and `ComplianceReport`
registers; the treasury banking lifecycle consuming OR's approval-workflow per ADR-022
(no app-local approval table); the conditional compliance precondition (multi-criteria
checks); compliance scoring and regulatory reporting as `x-openregister-calculations`
per ADR-031; treasury account master with IBAN / compliance attributes; and aggregated
compliance metrics. The capability materialises compliance audit trails per the T2
`bookkeeping-audit-trail` specification.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md)
spec for app structure and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:** [`bookkeeping-chart-of-accounts`](../add-shillinq-bookkeeping-tier-roadmap)
(chart of accounts for treasury classification),
[`bookkeeping-audit-trail`](../add-shillinq-audit-trail)
(audit trail for compliance tracking).

## Motivation

Dutch government entities and public-sector organizations must comply with strict
treasury banking regulations (schatkistbankieren) mandated by Ministry of Finance.
These regulations require segregation of treasury-managed bank accounts, continuous
compliance monitoring, and regulatory reporting for central banking oversight.

The legacy governance features from intelligence-db (`competitor_features` with
`app_slug=shillinq`) identify compliance monitoring + treasury account management +
regulatory reporting as top-tier governance-asked features. Per ADR-022, approval
routing comes from OR, not from an app-local table; per ADR-031, compliance scoring
and regulatory export are declarative calculations, not a `ComplianceService`.

This is one of five T2 governance capability changes; this proposal scopes
only the schatkistbankieren compliance slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-schatkistbankieren`);
  declares 3 new registers (`TreasuryAccount`, `BankingRule`, `ComplianceReport`) with
  lifecycles, calculations, and aggregations; adds 3 manifest navigation entries
  (Treasury Accounts, Banking Rules, Compliance Reports).
- [ ] Project: openregister — no source changes; consumes existing approval-workflow
  (ADR-022), `x-openregister-lifecycle`, `x-openregister-calculations`,
  `x-openregister-aggregations`. If the lifecycle engine cannot express conditional
  compliance preconditions declaratively, ADR-031's exception path applies (one
  single-method `ComplianceValidator`).
- [ ] Project: audit-trail — no source changes; compliance events referenced by
  the T2 `bookkeeping-audit-trail` specification.

## Scope

### In Scope

- One new capability spec (`bookkeeping-schatkistbankieren`) — see the `specs/` folder.
- The `TreasuryAccount` register with IBAN, compliance classification, master-list status,
  and administration-scoped compliance attributes.
- The `BankingRule` register defining compliance criteria (bank-account segregation,
  IBAN pattern requirements, transaction-limit thresholds, periodic reporting obligations).
- The treasury banking lifecycle (`draft → configured → active → monitored → compliant`
  plus `suspended` / `archived`) consuming OR's approval-workflow per ADR-022.
- Conditional compliance precondition on `TreasuryAccount.activate` — multi-criteria
  checks against the `BankingRule` register (IBAN format, segregation, admin approval).
- The `ComplianceReport` register carrying periodic compliance snapshots, scoring metrics,
  and regulatory export status — declared as `x-openregister-calculations` field, no PHP service.
- Compliance scoring computed from BankingRule matches — automated calculation per
  `x-openregister-calculations`.
- Aggregated compliance metrics grouped by administration and reporting period,
  queryable as `x-openregister-aggregations`.
- Audit trail materialisation per T2 `bookkeeping-audit-trail` on lifecycle transitions.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components, controllers,
  tests, and CI changes are deliberately not in this proposal; the task list references
  them but the implementation lands via a separate `opsx-apply` cycle.
- **Live bank integration** — T3+; schatkistbankieren declares the reporting surface
  only; direct bank API initiation lives in future tiers.
- **Multi-currency compliance** — T5; treasury accounts carry currency but no FX
  revaluation in T2.
- **Custom compliance workflows** — operator-configured per administration; declarative
  baseline only.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-schatkistbankieren`** — declares the three registers, the lifecycle
(consuming OR approval-workflow), the conditional multi-criteria compliance precondition,
the compliance-scoring calculation, aggregated compliance metrics, and the audit-trail
materialisation path per T2 `bookkeeping-audit-trail`.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is
prefixed `REQ-SCHATKIST-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions, the T2 `bookkeeping-audit-trail`
specification, and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas (`TreasuryAccount`,
  `BankingRule`, `ComplianceReport`); declares lifecycle on `TreasuryAccount`,
  calculations on `ComplianceReport.complianceScore`, aggregations on compliance metrics.
- `src/manifest.json` — adds 3 navigation entries + their `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one single-method
  `ComplianceValidator` if the engine cannot express conditional preconditions).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on approval-workflow (ADR-022), `x-openregister-lifecycle`
  (ADR-031), `x-openregister-calculations` (ADR-031), `x-openregister-aggregations` (ADR-031).
  If the conditional compliance precondition shape is not yet expressible, ADR-031
  exception path applies.
- **T2 audit-trail** — depends on `bookkeeping-audit-trail` for lifecycle-transition
  audit logging.
- **T1 chart-of-accounts** — depends on Account classification for treasury-account
  segregation verification.

## Risks

### Risk 1: Multi-criteria compliance precondition requires declarative engine support

**Severity**: Medium
**Mitigation**: The treasury banking compliance checks (IBAN format, segregation,
admin approval) are declared as REQUIRED-IF-PRESENT preconditions in the lifecycle.
If OR's engine cannot express multi-criteria conditional logic, ADR-031's exception
path applies (one single-method `ComplianceValidator`). Remove when OR's engine
supports conditional clauses.

### Risk 2: Compliance scoring formula may require tuning per administration

**Severity**: Low
**Mitigation**: This capability declares the scoring as a declarative calculation
(automated weighting per BankingRule criteria match). Operator-configurable adjustments
(threshold tuning, criterion weights) are operator-driven per administration.
Resolved during implementing cycle's UX review.

### Risk 3: Regulatory reporting format versioning

**Severity**: Low
**Mitigation**: Spec pins the compliance-report export format and regulatory metadata
structure. Coordinate with government stakeholders on any future format uplift.

## Rollback

Spec-only — registers are non-destructive. Reverting removes the manifest entries
+ lifecycle bindings; treasury accounts and compliance reports remain queryable but
unreferenced. Compliance audit trail remains immutable per T2 specification.

## Open Questions

1. **Multi-criteria compliance precondition shape** — resolved in `opsx-ff` discovery;
   spec shape-neutral.
2. **Compliance scoring weights and thresholds** — resolved during implementing cycle's
   configuration review.
3. **Regulatory reporting format alignment** — coordinated with government stakeholders
   during spec implementation.
