# Proposal: bookkeeping-soft-close-flux

`kind: spec` per ADR-032 — the centre of mass is period-close workflow
automation and variance analysis. One capability spec
(`bookkeeping-continuous-close`) declares requirements for
nightly soft-close execution (accruals, FX revaluation,
depreciation, cut-off), period-status lifecycle, close checklists
with task dependencies, flux analysis with materiality-driven
severity routing, and variance-explanation narratives.

## Summary

Introduce the **continuous-close and flux analysis** capability for
Shillinq as a core T2 compliance + operations feature per
`adr-001-bookkeeping-tier-roadmap.md`. This change declares one
capability spec `bookkeeping-continuous-close` that defines:

- **Period Lifecycle**: Period status register with stages
  (open → soft-closed → hard-closed → audited → locked) and
  stage-specific posting restrictions.
- **Auto-Accrual Engine**: Configurable rules (fixed amount,
  percentage, contract-lookup) that post accruals nightly and
  reverse on trigger (first-of-month, invoice receipt).
- **Close-Checklist Workflow**: Reusable checklist templates with
  task dependencies, SLA tracking, and escalation on overdue
  tasks.
- **Soft-Close Job**: Nightly automation that executes accrual
  rules, FX revaluation, depreciation, IFRS revenue cut-off
  (delegated), IFRS lease postings, and intercompany matching
  to produce a complete trial balance by 07:00 local.
- **Flux Analysis**: Post-soft-close variance computation against
  budget, prior period, prior year, and rolling forecast;
  materiality thresholds per account group; rule-based auto-
  explanation via driver decomposition.
- **Flux Narrative**: Aggregated variance explanations ranked by
  absolute variance, exportable to PDF/Markdown/JSON for board
  pack embedding.
- **Close Quality KPIs**: Time-to-close, post-close adjustment
  count, audit-correction ratio, unexplained-item SLA
  compliance, 12-period trend.

This change conforms to the shared `nextcloud-app` spec for app
structure and OpenRegister register declarations.

**Depends on:** `bookkeeping-period-close` (period entity),
`bookkeeping-general-ledger` (GL line source + target for
accruals + flux), `bookkeeping-ifrs15-revenue` (revenue cut-off
delegation), `bookkeeping-accounts-payable` +
`bookkeeping-accounts-receivable` (AP/AR ageing + cutoff),
`bookkeeping-treasury-ihb` (FX + interest accruals).

## Motivation

The shift from periodic 10-15 working day month-end close to
continuous close is a well-documented finance-modernisation
pattern (Hackett Group, APQC, McKinsey "Finance 2030") and is
the operating model behind every fast-close benchmark: Cisco
closes in 3 days, Coca-Cola in 4, Microsoft in 2. Even mid-market
SMEs achieve a 5-day close once the core building blocks are in
place: automated accruals, continuous reconciliation, period-
locked transactions, exception-driven review, and flux analysis
with explanations attached to material variances.

The legacy AP/AR draft cluster from intelligence-db calls out
continuous close and variance explanation as high-demand features.
This module delivers those building blocks, enabling Dutch SMB
finance teams to spend less time chasing accruals and more time
investigating outliers — and reporting numbers within 1-3 business
days instead of 10-15.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-continuous-close`); declares 8 new registers
  (`PeriodStatus`, `CloseChecklistTemplate`, `CloseChecklistInstance`,
  `AutoAccrualRule`, `AutoAccrualPosting`, `FluxRun`, `FluxItem`,
  `FluxAttribution`, `MaterialityPolicy`, `ContinuousCloseAlert`,
  `CloseMetrics`); adds 3 manifest navigation entries
  (Continuous Close, Accrual Rules, Flux Analysis).
- [ ] Project: n8n — orchestrates the nightly soft-close pipeline
  (sequencing modules, handling retries, routing alerts). Separate
  change.
- [ ] Project: launchpad — tiles for close countdown, flux heatmap,
  time-to-close trend. Separate change.
- [ ] Project: docudesk — archives period-end flux narrative and
  signed-off board pack (via `bookkeeping-document-attachment-
  integration`).

## Scope

### In Scope

- One capability spec (`bookkeeping-continuous-close`).
- 11 new registers: `PeriodStatus`, `CloseChecklistTemplate`,
  `CloseChecklistInstance`, `AutoAccrualRule`, `AutoAccrualPosting`,
  `FluxRun`, `FluxItem`, `FluxAttribution`, `MaterialityPolicy`,
  `ContinuousCloseAlert`, `CloseMetrics`.
- Period lifecycle: open → soft-closed → hard-closed → audited →
  locked, with posting-restriction enforcement per stage.
- Auto-accrual rule definition with 5 calculation methods
  (fixed, percentage, straight-line, days-elapsed, external-lookup)
  and 3 reversal patterns (first-of-month, on-receipt, on-settlement).
- Nightly soft-close job orchestration per administratie:
  execute accrual rules, FX revaluation, depreciation, IFRS 15
  revenue cut-off, IFRS 16 lease, intercompany matching.
- Flux analysis: variance computation vs budget/PY/PP/forecast,
  materiality thresholds per account group, rule-based auto-
  explanation (volume, price, mix, FX, one-off drivers).
- Close-checklist template with task dependencies, SLA tracking,
  evidence attachment, and escalation on breach.
- Flux narrative: ranked by variance size, exportable to
  PDF/Markdown/JSON.
- Close-quality KPIs tracked over 12 periods.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests are deliberately not in this
  proposal; the task list references them but implementation
  lands via `opsx-apply` cycle.
- **n8n orchestration** — workflow definition belongs in a
  separate n8n change.
- **launchpad tiles** — analytics and dashboards belong in a separate
  launchpad change.
- **Rolling forecast** — assumes budget/forecast module already
  defined or will be defined separately.
- **Audit adjustments post-lock** — audit-period extensions are
  handled by period-close module, not this spec.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-continuous-close`** — declares the 11 registers,
the soft-close execution contract, the accrual-rule
configuration shape, the flux-analysis algorithm, the close-
checklist workflow, and the KPI-tracking model.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-CLS-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions,
`bookkeeping-general-ledger`, and the period-close module.

## Impact

- `lib/Settings/shillinq_register.json` — adds 11 new schemas;
  declares `x-openregister-lifecycle` on `PeriodStatus`;
  declares `x-openregister-aggregations` for flux materiality
  classification; declares `x-openregister-calculations` for
  accrual computation and flux analysis.
- `src/manifest.json` — adds 3 navigation entries (Continuous
  Close, Accrual Rules, Flux Analysis) + their `type: index` +
  `type: detail` pages.
- `appinfo/routes.php` — POST route for trigger soft-close job
  (on-demand execution for testing).
- One PHP service `OCA\Shillinq\Service\SoftCloseExecutor` to
  orchestrate accrual rules, FX, depreciation, delegation to
  IFRS modules, and intercompany matching; per ADR-031 exception
  this is allowed because orchestration is non-policy code.

## Cross-Project Dependencies

- **T1 bookkeeping-period-close** — depends on `Period` entity
  for lifecycle and posting restrictions.
- **T1 bookkeeping-general-ledger** — depends on GL lines as
  source for flux analysis and target for accrual postings.
- **T2 bookkeeping-ifrs15-revenue** — soft-close job delegates
  revenue cut-off recognition to this module.
- **T2 bookkeeping-treasury-ihb** — soft-close job consumes
  treasury postings (FX revaluation, interest accruals) from this
  module.
- **T2 bookkeeping-accounts-payable** — ageing reports feed
  AP cut-off checklist task; unposted GR/IR drives auto-accrual.
- **T2 bookkeeping-accounts-receivable** — ageing reports feed
  AR cut-off checklist task.

## Risks

### Risk 1: Soft-close job timing window

**Severity**: Medium
**Mitigation**: Job is nightly off-peak; target completion by
07:00 local time allows morning review before board
presentation. If computation exceeds window, flux analysis runs
on-demand during business hours separately.

### Risk 2: Accrual reversal race with actual invoice

**Severity**: Low
**Mitigation**: REQ-CLS-003 specifies three reversal patterns;
operator chooses per rule. On-receipt pattern ensures reversal
only when actual invoice posts; no orphaned accruals.

### Risk 3: Flux materiality thresholds not tuned for Dutch SMEs

**Severity**: Medium
**Mitigation**: REQ-CLS-005 defines per-administratie +
account-group thresholds; defaults from APQC / Hackett benchmarks;
controller tunes per org risk tolerance.

### Risk 4: Auto-explanation attribution not covering all drivers

**Severity**: Low
**Mitigation**: Rule-based attribution covers 80% of variances
(volume, price, mix, FX, one-off); remainder escalates to owner
with 24-hour SLA per REQ-CLS-006. Future ML-driven decomposition
is a roadmap item.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder; no runtime impact. After implementation (separate
cycle), rollback follows the standard pattern: revert the
implementing PR; registers are non-destructive — periods + accruals
+ flux items remain queryable.

## Open Questions

1. **Soft-close window timing** — 07:00 local is the target for
   full trial balance; if computation exceeds window, what is the
   acceptable SLA for flux-analysis availability? Confirm during
   `opsx-ff` discovery.
2. **Flux auto-explanation coverage** — what percentage of
   variances should be auto-explained to avoid excessive owner
   escalation? Current assumption: 80%; confirm during discovery.
3. **Rolling forecast availability** — does this spec assume a
   rolling forecast module already exists or will be defined later?
   Current assumption: future T3 module; flux comparison defers to
   budget/prior-period/prior-year for T2.
4. **Post-close adjustment visibility** — should post-close
   adjustments be visible in the next month's flux narrative with a
   "PY/PP adjustment" flag? Current decision: yes per REQ-CLS
   scenario; confirm during discovery.
