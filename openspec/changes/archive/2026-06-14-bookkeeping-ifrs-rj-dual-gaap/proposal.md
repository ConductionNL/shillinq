# Proposal: bookkeeping-ifrs-rj-dual-gaap

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(`AccountingFramework`, `ChartOfAccountsMapping`, `DualTransaction`,
`ReconciliationBridge`, `StandardSpecificCalculation`, `FrameworkElection`) +
`x-openregister-lifecycle` consuming OR declarative transitions + parallel-ledger journal materialisation. No PHP dual-ledger
service, no PHP reconciliation-engine classes are authored (subject to ADR-031 exception:
at most one single-method guard if OR's transaction-parallel extension is not yet stable).

## Summary

Introduce **dual GAAP reporting** (IFRS and RJ simultaneously) as a T3 financial-reporting
capability for Shillinq. Nederlandse mid-cap en grote ondernemingen (listed on EU exchanges)
are obligated to report consolidated financials under IFRS (EU-Verordening 1606/2002);
dochterondernemingen, joint ventures, and many family businesses choose dual reporting for
cross-border benchmarking, M&A due diligence, and international financing. This capability
enables parallel-ledger journal posting (every transaction booked under BOTH stelsels
automatically), with a reconciliation engine that explodes RJ-to-IFRS differences
(lease treatment, pension obligations, expected credit loss models, revenue recognition,
impairments) at the line level, traceable from consolidated IFRS number back to
source transaction.

The change declares six new registers: `AccountingFramework`, `ChartOfAccountsMapping`,
`DualTransaction`, `ReconciliationBridge`, `StandardSpecificCalculation`, `FrameworkElection`.
It adds parallel-journal materialisation on every GL posting, multi-ledger tracking via
`GlLine.accountingFramework` + `GlLine.frameworkJournalEntry` cross-refs, and bridge calculations
for IAS 12 deferred taxes. Per ADR-022, dunning and approval workflows are consumed from
OR; per ADR-031, reconciliation is declarative aggregation + lifecycle hooks, not PHP
reconciliation service.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and integrates with the T1 general ledger and T4 financial-statements export.

**Depends on:** [`bookkeeping-general-ledger`](../bookkeeping-general-ledger/proposal.md)
(materialises dual GL transactions per framework),
[`bookkeeping-financial-statements`](../bookkeeping-financial-statements/proposal.md)
(consumes both ledgers to generate RJ + IFRS statements),
[`bookkeeping-consolidation`](../bookkeeping-consolidation/proposal.md) (multi-entity framework conversion).

## Motivation

Nederlandse regulations (BW2 Titel 9, EU-Verordening 1606/2002) and audit standards require
listed groups to maintain IFRS consolidated statements alongside local GAAP. The gap between
RJ and IFRS is material: IFRS 16 leases, IAS 19 pension obligations, IFRS 9 expected credit loss,
IFRS 15 revenue recognition, and IAS 36 impairments drive 5–20% swing in reported equity and net result.
Controllers currently reconcile manually in Excel (80–120 hours per quarter, error-prone).

Shillinq's parallel-ledger architecture automates the dual posting, explodes the delta per
standard reference, and surfaces the RJ-to-IFRS bridge in the financial statements and audit toelichting
(footnotes). Per ADR-022, framework configuration and election is consumed from OR; per ADR-031,
reconciliation is declarative aggregation, not a PHP service.

This is one of four T3 financial-statement capabilities; this proposal scopes the dual GAAP
reporting infrastructure.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-ifrs-rj-dual-gaap`); declares 6 new registers
  (`AccountingFramework`, `ChartOfAccountsMapping`, `DualTransaction`,
  `ReconciliationBridge`, `StandardSpecificCalculation`, `FrameworkElection`)
  with lifecycle and aggregations; extends T1 GL materialisation to support
  dual posting per framework.
- [ ] Project: openregister — no source changes; consumes existing
  OR abstractions for workflow, lifecycle, and configuration (per ADR-022).
- [ ] Project: docudesk — no source changes; audit-evidence attachments
  (actuariële rapportages, lease contracts, ECL models) referenced by FK per
  compliance-audit capability.

## Scope

### In Scope

- One new capability spec (`bookkeeping-ifrs-rj-dual-gaap`).
- The `AccountingFramework` register with identifier (IFRS-EU, NL-GAAP-RJ, US-GAAP),
  version, effective date, jurisdictions, regulator, base currency, and statement templates.
- The `ChartOfAccountsMapping` register mapping source (RJ) accounts to target (IFRS) accounts
  with one-to-many cardinality, allocation rules (percentage, formula, ratio driver),
  effective dates, and audit trail.
- The `DualTransaction` register linking base GL transaction to both RJ and IFRS journal entries,
  with divergence classification (permanent, temporary, reclassification) and reason codes
  (LEASE_IFRS16, PENSION_IAS19, ECL_IFRS9, REVENUE_IFRS15, IMPAIRMENT_IAS36, etc.).
- The `ReconciliationBridge` register capturing the RJ-to-IFRS conversion per period with
  opening balance, adjustments per standard, tax effect, and approver signoff.
- The `StandardSpecificCalculation` register storing standard-by-standard supporting calculations
  (IFRS-16 IBR, IAS-19 PUC, IFRS-9 ECL staging, IFRS-15 CINCO model) with inputs, outputs,
  revaluation frequency, and actuary signoff (IAS 19).
- The `FrameworkElection` register recording per-legal-entity framework choice, comply-or-explain
  motivation, RJ variant (RJ-onverkort, RJk, IFRS), and AVA-besluit reference.
- Parallel-journal materialisation: every GL posting booked to RJ-ledger ALSO booked to IFRS-ledger
  with automated divergence classification and bridging adjustments.
- Drill-down from consolidated IFRS number to source transaction via reconciliation bridge.
- Multi-entity consolidation with automatic RJ-to-IFRS conversion per subsidiary framework election.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components, controllers, tests,
  and CI changes are deliberately not in this proposal; the task list references them but
  the implementation lands via a separate `opsx-apply` cycle.
- **Detailed IAS 12 deferred-tax computation** — declared in scope but delegated to T3's
  `bookkeeping-tax-deferred` capability spec (IAS 12, RJ 277).
- **XBRL-NT export** — T4. Spec declares the bridge structure; XBRL serialisation lands in
  tax-compliance export capability.
- **Real-time ECL staging re-calculation** — monthly aggregation OK; intra-month re-staging deferred.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-ifrs-rj-dual-gaap`** — declares the six registers, the parallel-posting lifecycle
(consuming OR abstractions), the divergence classification, the reconciliation-bridge aggregation,
the multi-entity consolidation pattern, and the drill-down audit trail.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-DGAAP-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions, T1 GL materialisation extension,
and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 6 new schemas
  (`AccountingFramework`, `ChartOfAccountsMapping`, `DualTransaction`,
  `ReconciliationBridge`, `StandardSpecificCalculation`, `FrameworkElection`);
  declares lifecycle on `DualTransaction` and `FrameworkElection`;
  extends GL materialisation to dual-post per framework.
- `src/manifest.json` — adds navigation entries for Framework Management,
  COA Mapping, Reconciliation Bridge, Dual Ledger Explorer.
- T1 GL materialisation extension patched to support dual-posting logic
  (materialise to RJ-ledger, then IFRS-ledger with divergence classification).
- No new PHP services (subject to ADR-031 exception: at most one single-method
  guard if OR transaction-parallel is not stable).

## Cross-Project Dependencies

- **OpenRegister** — depends on OR abstractions for workflow, lifecycle,
  and framework-configuration management per ADR-022.
- **T1 general ledger** — depends on GL materialisation extension to
  support dual-posting per framework.
- **T2 accounts payable/receivable** — both depend on dual GAAP for
  parallel subledger posting (AP/AR invoices materialise to both ledgers).
- **T3 consolidation** — depends on dual GAAP for multi-entity framework
  conversion and elimination logic.
- **T4 financial-statements export** — consumes RJ + IFRS ledgers to generate
  side-by-side statements and reconciliation-bridge toelichting.

## Risks

### Risk 1: Divergence classification requires domain expertise

**Severity**: Medium
**Mitigation**: Standard-specific reason codes (LEASE_IFRS16, PENSION_IAS19, etc.)
are pre-baked. On REQ-DGAAP-003, the GL posting hook classifies the divergence
automatically; manual override by group-accountant is allowed with audit trail.
Spec is declarative; no PHP classifier service.

### Risk 2: Real-time recalculation of IAS 19 pension obligations is computationally expensive

**Severity**: Medium
**Mitigation**: IAS 19 inputs (discount rate, demographic assumptions, plan-asset returns)
are updated monthly (or per actuary report). Quarterly re-valuations are the norm; full
daily revaluation is NOT required. Standard-specific-calculation lifecycle declares
revaluation frequency per standard (monthly, quarterly, annual). Per REQ-DGAAP-005.

### Risk 3: Audit-trail explosion with dual-posting

**Severity**: Low
**Mitigation**: OR's audit-trail abstraction (per ADR-022) captures one audit event per
GL posting, with a sub-ledger cross-ref. Dual posting is ONE event with two sub-ledger
entries, not two events. Drill-down via `GLLine.frameworkJournalEntry` FK.

### Risk 4: Framework election scope-creep (US-GAAP, HGB, etc.)

**Severity**: Low
**Mitigation**: T3 MVP scopes IFRS-EU + NL-GAAP-RJ only. `AccountingFramework.identifier`
enum is open but implementation is gated per `FrameworkElection.rjVariant` enum
(RJ-onverkort, RJk, IFRS-volledig). US-GAAP and other frameworks deferred to T5
cross-border reporting phase.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder;
no runtime impact. After implementation (separate cycle), rollback follows the
standard pattern: revert the implementing PR; GL entries remain dual-posted
(no destructive side effects).

## Open Questions

1. **OR transaction-parallel extension stability** — see Risk 1; resolved in
   `opsx-ff` discovery. If not stable, ADR-031 exception path: single-method
   guard for divergence classification.
2. **Default COA mapping strategy** — RJ accounts 1–9 → IFRS asset/liability accounts;
   detailed mapping uploaded per group-accountant. Resolved during implementing cycle's
   COA-mapping wizard UX.
3. **Monthly vs. quarterly recalculation** — IAS 19 (actuarial) monthly, IFRS 9 (ECL)
   monthly, IFRS 16 (lease IBR) annual unless refinanced. Per standard; defaults
   resolved in implementing cycle.
4. **Consolidation-elimination order** — RJ eliminations first, then IFRS
   conversion, or vice versa? Architecture decision resolved in `bookkeeping-consolidation`
   proposal (this spec is framework-agnostic).
