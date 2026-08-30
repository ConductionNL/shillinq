# Proposal: bookkeeping-ccm-rule-engine

`kind: feature` per ADR-032 — a complex, multi-schema capability
with rule evaluation, forensic detection, and audit-committee
reporting at its core. Six new register schemas, a rule DSL compiler,
synchronous + asynchronous evaluation pipelines, and a four-state
finding workflow.

## Summary

Introduce a **Continuous Controls Monitoring (CCM) rule engine** for
Shillinq that evaluates every journal entry, vendor change, and
payment instruction against a curated library of ~60 forensic and
SOX-style controls — at the point of posting (synchronous) and on a
nightly sweep (asynchronous) — flagging, quarantining, or escalating
anomalies in real time. Findings are triaged through a four-state
workflow (open / under-investigation / dismissed-with-rationale /
confirmed-issue-escalated), trend-analyzed, and published to the
audit committee quarterly with population-level evidence rather than
sample-based testing.

This capability closes the gap between after-the-fact external audit
(once per year, sample-based) and the detection speed required for
fraud prevention and SOX 404 compliance in mid-market+ Dutch entities
subject to parent-company controls, DNB oversight, or AFM regulation.

**Depends on:**
- `bookkeeping-journal-entries` (synchronous evaluation on every posting)
- `bookkeeping-audit-trail` (audit trail immutability for finding evidence)
- `bookkeeping-general-ledger` (GL-account baselines for anomaly detection)
- `bookkeeping-vendor-master` (master-data integrity rules)
- `bookkeeping-payments` (payment-instruction interception for block-mode rules)

## Motivation

Most fraud in MKB+ entities is committed by trusted insiders using
the accounting system the company already trusts. Classic patterns are
well documented: fictitious vendors, manual journal entries at
period-end to smooth revenue, approval-chain bypasses, last-minute
vendor bank-account changes before large payments, credit notes
minutes before month-end. Each pattern can be expressed as a rule.

Today's MKB+ accounting systems are control-poor and rely on
external auditors' sample tests once per year — typically catching
only the largest anomalies. CCM changes that calculus: rules fire on
every transaction in real time, population-based rather than
sample-based, with defensible evidence captured for every finding.

For the enterprise target customer — a Dutch MKB+ at the upper end
of the segment, often a foreign-owned subsidiary subject to
parent-company SOX 404 controls, a financial-services entity under
DNB / AFM oversight, or a fast-growing company whose audit firm has
flagged "tone-at-the-top" risk — CCM is the difference between a
clean audit and a long, expensive findings list. The capability is
also a hard differentiator: competitors (Exact Globe+, Twinfield,
AFAS Profit) have no equivalent, and the alternative is a separate
EUR 50–150K/year tool (ACL/Galvanize, CaseWare, MindBridge).

## Affected Projects

- [x] Project: shillinq — six new register schemas (ccm-rule,
  ccm-finding, ccm-segregation-matrix, ccm-user-function-assignment,
  ccm-baseline, ccm-audit-committee-report); rule DSL compiler; sync
  + async evaluation pipelines; findings triage UI and SoD report
  generator.
- [x] Project: bookkeeping-audit-trail — findings reference audit-trail
  immutable events as evidence; the audit-trail dependency is hard.
- [x] Project: openconnector — sanctions-list / PEP-list / adverse-media
  screening for value-chain integrity rules (VC-01 through VC-05).
- [x] Project: organisations — vendor bank account history, master-data
  change-log, PEP/sanctions matching on create/update.
- [ ] Project: decidesk — escalated findings route through board-governance
  workflows; optional soft dependency.

## Scope

### In Scope

- **Six register schemas** in the `bookkeeping` register: `ccm-rule`
  (rule definition, with control family, objective, assertion, DSL
  logic, parameters); `ccm-finding` (single rule firing, with evidence,
  investigation notes, resolution workflow); `ccm-segregation-matrix`
  (SoD function-code reference data); `ccm-user-function-assignment`
  (materialised SoD role assignments); `ccm-baseline` (statistical
  baselines for anomalous-amount rules); `ccm-audit-committee-report`
  (period-end deliverable).
- **Rule DSL compiler** — a constrained JSON expression language
  (not arbitrary code) with operators: `event-matches`, `field-equals`,
  `field-in-set`, `field-greater-than`, `field-between`,
  `field-matches-regex`, `time-is-within`, `user-has-function`,
  `user-also-has-function`, `value-deviates-from-baseline`,
  `value-violates-benford`, `count-of-similar-in-period-exceeds`,
  `duplicate-of-existing`, `approval-chain-bypassed`, etc.; compound
  rules with `all-of` / `any-of` / `none-of`. Compiled once on
  rule-instance load, cached for performance.
- **Synchronous evaluation** — rules fire during journal posting (or
  vendor save, or payment release). Evaluation mode: synchronous-block
  (prevents posting, returns finding) or synchronous-warn (allows
  posting, flags it). Block-mode has mandatory override + rationale.
- **Asynchronous evaluation** — nightly sweep evaluates population-based
  rules (Benford's Law, peer-group comparisons, trend deviations) over
  new transactions since last sweep. Completes within overnight window
  for 1M+ journal lines/year.
- **60 default rules** shipped as seed data, grouped into eight families:
  segregation-of-duties (7 rules), duplicate-detection (5), anomalous-amount
  (6), timing-anomalies (6), master-data-integrity (6), approval-bypass (5),
  manual-JE-forensics (6), value-chain-integrity (5) + 12 customizable.
- **Finding workflow** — four-state triage (open → under-investigation /
  dismissed-with-rationale / confirmed-control-deficiency); auto-escalation
  of critical findings after 24h without response; clustering by vendor/user
  for pattern detection.
- **Audit committee report generator** — monthly/quarterly scheduled report
  auto-drafted (LLM-assisted) with executive summary, rule firings by family
  + severity, findings by status, trend analysis, SoD compliance scorecard,
  manual-JE summary, approver-bypass attempts, CFO review and sign-off.
- **SOX mode toggle** — activates additional metadata (control owner,
  frequency, evidence, deficiency rating, remediation plan) and a quarterly
  attestation workflow (management certification document).
- **Trend analysis layer** — fire-rate, false-positive rate, dismissal rate,
  time-to-resolution tracked per rule; drift detection (rule firing 10x more
  than baseline) triggers re-tuning review.
- **Performance constraints** — synchronous rules add ≤100ms latency at 95th
  percentile; async sweeps complete within overnight window; DSL compiled
  once + cached; baselines materialised, not computed per evaluation.
- **Extensions to existing entities** — `journal-entry` gains `ccm-evaluation-status`
  + `ccm-evaluation-timestamp`; `journal-entry-line` gains `posting-channel`;
  `organisations` gains `master-data-change-log` + `bank-account-history`;
  `user` gains `risk-rating` (1–5).

### Out of Scope

- **Email/Slack notifications** beyond in-app — wiring to n8n for downstream
  push (Slack, Teams, email) is noted as integration but not in the base
  capability.
- **AI-driven anomaly detection** — CCM is the foundation for the next layer
  (ML-based clustering); not included here.
- **Export to external SIEM/log management** — Nextcloud / cluster ops owns
  that channel.
- **Training platform integration** beyond capability registration — larpingapp
  hosting the control-awareness training is noted; course content and tracking
  are out of scope.

## Approach

One delta, adding SIX register schemas + THREE major services + the rule
DSL compiler to T2 bookkeeping:

1. **`ccm-rule`** — configuration schema for rule definition, with DSL
   expression, parameter templates, suppression rules, effective-from/to,
   rule owner / reviewer, SoD matrix reference.
2. **`ccm-finding`** — transactional schema for findings, with evidence
   snapshot, status workflow, investigation trail, escalation path.
3. **`ccm-segregation-matrix`** — reference data for SoD function codes
   (VENDOR-CREATE, INVOICE-POST, PAYMENT-RELEASE, etc.) and their
   conflicts (critical-role-held-by-single-user, etc.).
4. **`ccm-user-function-assignment`** — materialised nightly (computed
   from roles + direct grants + temp elevations) mapping users to function
   codes for SoD rule evaluation.
5. **`ccm-baseline`** — statistical baselines (mean, median, Benford
   distribution) per scope (vendor, GL-account, cost-centre, tenant-wide),
   with refresh cadence + stability flag.
6. **`ccm-audit-committee-report`** — period-end deliverable with
   executive summary (LLM-drafted, editable), rule firings by family,
   trend analysis, top findings, SoD violations, manual-JE summary,
   SOX 404 deficiencies (if enabled), approver sign-off.

Services:
- **RuleEngine** — compiles DSL, evaluates sync/async rules, manages caching.
- **FindingService** — triage workflow (open/under-investigation/dismissed/escalated),
  clustering, auto-escalation, investigation-note threading.
- **AuditCommitteeReportGenerator** — period-end report assembly, LLM-drafted
  summary, trend analysis, SOX deficiency rollup.

Seed data:
- Rule library (60 rules, 8 families) with control objective, assertion,
  severity, DSL logic, parameter defaults grounded in forensic-accounting
  research (AICPA SAS 99, ISACA CCM guide, Nigrini Benford's Law).
- SoD function-code matrix (50-function SAP/Oracle library, Dutch-customized).
- Baseline templates for vendor spike, GL-account deviation, leading-digit.

Specs:
- One spec file: `bookkeeping-ccm-rule-engine` with Requirements for rule
  registration, evaluation, finding triage, AC report generation, and
  performance SLAs.

## New Dependencies

- **openconnector** (soft) — for sanctions-list, PEP-list, adverse-media
  screening via the screening provider integration (for VC-01 through VC-05
  rules). If openconnector unavailable, value-chain rules gracefully degrade
  to local business-rule only (no screening). ✓ Already a Shillinq dependency.
- **Cloud Compute for async sweep** — nightly rule evaluation at scale
  requires sufficient CPU/memory; cluster autoscaling ensures overnight
  window is met. (Infrastructure / not a code dependency.)

## Impact

- `lib/Settings/shillinq_register.json` — adds 6 new schemas (ccm-rule,
  ccm-finding, ccm-segregation-matrix, ccm-user-function-assignment,
  ccm-baseline, ccm-audit-committee-report) with audit-trail enabled on
  each.
- `lib/Service/RuleEngine.php` — DSL compiler, sync/async evaluation,
  caching logic.
- `lib/Service/FindingService.php` — triage workflow, clustering,
  escalation.
- `lib/Service/AuditCommitteeReportGenerator.php` — report assembly,
  LLM-drafted summary.
- `lib/Db/` — Mappers for the six register schemas.
- `lib/Db/Statement/` — query builders for baseline computation, rule
  firing, trend analysis.
- `src/views/CCMDashboard.vue`, `src/components/FindingsTriage.vue`,
  `src/components/RuleLibraryAdmin.vue`, `src/components/SOXConfiguration.vue`
  — frontend for findings dashboard, rule management, SoD report, audit
  committee report download.
- `tests/Unit/Service/RuleEngineTest.php` — DSL compilation, rule firing,
  caching; parametric tests for each rule family.
- `tests/Integration/FindingWorkflowTest.php` — end-to-end finding triage,
  escalation, notification.
- `src/manifest.json` — adds CCM Dashboard navigation, findings detail page,
  rule library admin, audit committee report download, SoD report.
- Database migrations — 6 new register tables + 1 index per fact table.

## Cross-Project Dependencies

- **bookkeeping-journal-entries** — hard dependency. Synchronous rules
  must hook into the journal-posting transaction pipeline. REQ-JE-XXX
  already reserves hook points for pre-post validation; CCM consumes them.
- **bookkeeping-audit-trail** — hard dependency. Findings reference
  audit-trail immutable events (before/after, actor, timestamp) as evidence.
- **bookkeeping-vendor-master** — soft. Master-data integrity rules
  (MD-01 through MD-06) apply constraints on vendor create/update events.
- **bookkeeping-payments** — soft. Payment-release rules (AB-01 through
  AB-05, VC-01 through VC-05) hook into the payment instruction pipeline;
  block-mode rules can quarantine a payment before release.
- **organisations** — soft. Vendor bank-account history, master-data
  change-log, PEP/sanctions matching require extensions to the
  organisations schema.
- **openconnector** — soft. Value-chain integrity rules (VC-01 through
  VC-05) screen against sanctions lists, PEP databases, adverse-media feeds.
- **bookkeeping-csrd-esrs**, **bookkeeping-ifrs-16-lease** — soft consumer.
  Both use CCM to enforce their own control objectives (materiality
  thresholds for ESRS, board-review triggers for lease reassessments).
- **docudesk** — soft. Evidence attached to findings (invoices, contracts,
  screening reports) stored via docudesk file API.
- **decidesk** — soft. Escalated findings route into board-governance
  workflows (if enabled).
- **launchpad** — soft consumer. CCM dashboard published to launchpad with
  findings by severity, SoD scorecard, top rules, trend chart.
- **opencatalogi** — soft consumer. CCM capability published with COSO +
  SOX tags.

## Risks

### Risk 1: Rule false-positive rate high; tuning burden on tenant

**Severity**: Medium
**Mitigation**:
- Seed rules ship with parameter defaults grounded in forensic-accounting
  research (Nigrini Benford's Law, AICPA SAS 99, ISACA CCM guide). Defaults
  are conservative (low false-positive thresholds).
- Per-rule false-positive rate + dismissal rate are tracked in the trend
  layer; rules drifting >10% above baseline trigger automatic re-tuning
  review flag.
- Suppress-rules mechanism allows tenant to exclude known-good transactions
  (e.g., "month-end consolidation JE from parent always exempted").
- Tenant-configurable parameters on every rule; rules ship enabled but can
  be disabled per tenant.

### Risk 2: Synchronous evaluation latency > 100ms at 95th percentile

**Severity**: Medium
**Mitigation**:
- DSL compiled once on rule-instance load, not per evaluation.
- Baselines materialised nightly, not computed per rule fire.
- Synchronous-block rules run only on high-confidence thresholds (typically
  SoD + duplicate-invoice + approval-bypass); low-confidence rules relegated
  to async.
- Latency SLA is explicit (REQ-CCM-006); CI gate measures 95th percentile on
  a representative 100K journal-line load test.

### Risk 3: Async sweep doesn't complete within overnight window for large tenants

**Severity**: Low
**Mitigation**:
- Async sweep is incremental (only new transactions since last sweep);
  Benford + peer-comparison rules run over rolling 12-month cohorts
  (batch compute, not per-line).
- Nightly window is 23:00–06:00 (7 hours standard); cluster autoscaling
  provisions compute as needed.
- SLA documented: "1M journal lines/year; 100 rules; ≤500 rule fires/month
  expected; async sweep completes by 06:00 local time or escalates to ops."

### Risk 4: DSL expression injection; tenant crafts a rule that breaks the compiler

**Severity**: Low
**Mitigation**:
- DSL is schema-validated (JSON Schema) before compilation. Invalid
  expressions fail validation, not the compiler.
- DSL is compiled to an AST (abstract syntax tree), not evaluated as code.
  No `eval()` / `shell_exec()` / `exec()`.
- Rule authorship is role-gated (admin / internal-audit / CFO only).

### Risk 5: SOX attestation workflow isn't legally sufficient; audit firm questions scope

**Severity**: Medium
**Mitigation**:
- SOX mode is optional (tenant toggle). Attestation template is based on
  PCAOB AS 2201 + SEC management-certification language; CCM generates the
  evidence (control test results, deficiency log), not the opinion.
- External auditor gets scoped read access to findings + trend dashboard for
  testing per ISA 315 / ISA 330 (mandatory audit procedures on ICFR).
- Engagement with big-4 audit firm pre-release to validate the evidence
  shape and SOX-compliance narrative.

## Rollback Strategy

**During implementation cycle (before merge):**
- If a dependency (journal-entries, audit-trail) is not yet landed, rollback
  is the revert of the implementing PR; Shillinq ships without CCM.

**Post-merge, before production deployment:**
- CCM is feature-flagged (`ccm_enabled: false` by default in tenant config).
- Rollback is a tenant-config toggle; no data loss (registers stay; no data
  is deleted).

**Production, after adoption:**
- Rules and findings are immutable (backed by audit-trail). Rollback means
  flagging all active findings as "system-disabled" and disabling the engine.
- Finding evidence remains queryable through OR's audit-trail API even after
  CCM is disabled.

## Open Questions

1. **What is the SOX-attestation target language?** US English only, or
   Dutch AFEP-MEDEF / Corporate Governance Code? Resolved post-release with
   reference customer (expected Q3 2026).
2. **Who generates the LLM-drafted audit committee summary?** OpenAI /
   Claude API / Ollama local? Resolved in `opsx-ff` discovery.
3. **How are SoD violations weighted in a multi-policy compliance scenario?**
   E.g., a user holds two functions that violate policy A but not policy B.
   Resolved during the detailed specification of REQ-CCM-003.
4. **Is there a "cold start" baseline-computation job for new tenants?**
   Rules need 30–90 days of transaction history to compute stable baselines;
   how is that backfill handled? Resolved during implementation of baseline
   materialisation (Task 5).
