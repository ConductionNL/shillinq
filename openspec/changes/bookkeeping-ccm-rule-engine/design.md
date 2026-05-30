# Design — Continuous Controls Monitoring (CCM) Rule Engine

## Context

MKB+ Dutch entities subject to parent-company SOX 404 controls, DNB
oversight, or AFM regulation today rely on external auditors' sample-based,
once-per-year testing to detect fraud and control anomalies. That cadence
leaves a year-long window for insider fraud to accumulate undetected.

Classic fraud patterns in bookkeeping are well-documented: fictitious vendors,
manual journal entries at period-end to smooth revenue, approval-chain
bypasses, last-minute vendor bank-account changes before large payments.
Each pattern can be expressed as a rule. If rules fire on every transaction
in real time — not on a sample — the window shrinks from a year to
milliseconds.

This change introduces six register schemas (rule definition, finding, SoD
matrix, function assignment, baseline, audit-committee report) + three
major services (RuleEngine, FindingService, AuditCommitteeReportGenerator)
to evaluate rules synchronously (at posting time) and asynchronously
(nightly sweep). The result is a defensible, population-based evidence base
for audit-committee sign-off and SOX 404 attestation.

## Goals

- **Real-time anomaly detection** — rules fire synchronously on every
  journal entry, vendor change, and payment instruction, with latency
  ≤100ms at 95th percentile.
- **Population-based evidence** — nightly async sweep evaluates Benford's
  Law, peer-comparison, trend-analysis rules over all new transactions
  (not a sample).
- **Defensible findings** — every finding captures evidence (before/after,
  actor, approver chain, GL context) from audit-trail, immutable and
  queryable for years.
- **Four-state triage** — findings flow through investigation (open →
  under-investigation / dismissed-with-rationale / confirmed-deficiency)
  with mandatory notes + rationale on resolution.
- **Audit committee reporting** — monthly/quarterly auto-generated reports
  with executive summary (LLM-assisted), trend analysis, SoD compliance
  scorecard, SOX 404 deficiencies (optional).
- **SOX 404 readiness** — optional mode activates control-owner, control-
  frequency, control-evidence, and deficiency-rating metadata; a quarterly
  attestation workflow produces management's certification document.

## Non-Goals

- **Arbitrary code execution** — the DSL is not Turing-complete; no
  `eval()` or `shell_exec()`.
- **AI-driven anomaly detection** — ML-based clustering is the next layer,
  not included here. CCM is the deterministic rule layer.
- **External SIEM shipping** — Nextcloud / cluster ops owns log export.
- **Training platform** — larpingapp hosts the control-awareness training;
  course content, enrollment tracking, completion attestation are out of
  scope.
- **Real-time dashboard replication** — findings publish to launchpad
  dashboard asynchronously (no sub-second UI update requirement).

## Decisions

### D1 — Rule definition is schema-validated JSON, not code

Rules are stored as `ccm_rule` register entries with a `rule_logic` field
containing a constrained JSON expression (operators like `event-matches`,
`field-equals`, `value-deviates-from-baseline`, etc.). Compiled to an AST
on first load, cached, and evaluated deterministically. No `eval()` or
dynamic code.

**Alternative considered:** PHP-authored rule classes. Rejected — PHP classes
can't be tenanted (can't ship 60+ rules as seed data per tenant without
multi-branch management); can't be version-controlled with the finding
evidence (rule + finding evidence must be co-auditable).

### D2 — Synchronous rules run at posting time; asynchronous rules at night

Synchronous rules (SoD, high-confidence duplicate, approval-bypass) run
during journal posting, with latency SLA ≤100ms. Evaluated inline, prevent
posting (block mode) or allow + flag (warn mode). Used for high-severity,
high-confidence patterns where time-to-detection is critical.

Asynchronous rules (Benford's Law, peer-group spike, trend deviation) run
on a nightly sweep over all new transactions since the last sweep. No
latency constraint; population-based analysis. Completes within the 7-hour
overnight window (23:00–06:00).

**Alternative considered:** All rules synchronous. Rejected — Benford's Law
over rolling 12-month windows is O(n log n) per new transaction; that's
prohibitive latency. Async sweep is necessary.

### D3 — Findings are immutable; investigation notes are append-only

Every finding captures evidence at fire time (before/after, actor, approver
chain, GL balances). Investigation notes are append-only (no edits, just
additions with timestamp + author). Resolution (dismissal / escalation)
requires mandatory free-text rationale.

**Alternative considered:** Editable investigation notes. Rejected — auditors
need to see the full history of how the finding was investigated; edits
break the audit trail.

### D4 — Finding triage is role-gated; auto-escalation after 24h

Findings start in `open` status. Assignment defaults to the rule owner
(typically CFO or internal-audit lead). Status transitions (→
under-investigation / → dismissed-with-rationale / → confirmed-escalated)
are allowed only for users with `internal_audit` or `finance_director`
roles. Critical findings (severity: critical) auto-escalate after 24h
without response to the CFO.

**Alternative considered:** Any user can dismiss a finding. Rejected — fraud
prevention requires strong SoD on who can close findings. Only finance/audit
leadership can close.

### D5 — SoD function-code matrix is pre-loaded reference data, tenant-customizable

Segregation-of-duties is expressed as a function-code matrix: e.g.,
{VENDOR-CREATE, VENDOR-PAY} is a critical conflict (same user cannot do
both). The matrix ships with a 50-function SAP/Oracle library, curated for
Dutch mid-market (VENDOR-CREATE, INVOICE-POST, PAYMENT-RELEASE,
BANK-RECONCILIATION, etc.). Tenants can add custom functions and conflicts.

**Alternative considered:** Hard-code SoD rules as individual DSL rules.
Rejected — SoD is inherently a matrix (N² conflict pairs); expressing it as
individual rules is error-prone and uninterpretable.

### D6 — Baselines are materialised nightly, not computed per evaluation

Anomalous-amount rules compare against statistical baselines (vendor mean,
GL-account median, Benford distribution, typical-hour-of-day). Rather than
compute baselines on every rule fire, a nightly batch job computes and
stores them in the `ccm_baseline` register. Rules evaluate against cached
baselines.

**Alternative considered:** Compute baselines on-demand. Rejected — for large
tenants (1M+ journal lines/year), per-evaluation computation is prohibitive
latency.

### D7 — LLM-drafted audit committee summary, human-reviewed before publication

The audit committee report includes an executive summary auto-drafted by an
LLM (OpenAI / Claude API, resolved in `opsx-ff`). The CFO edits the summary,
adds context, and approves it before the report is generated. No report is
auto-published; human sign-off is mandatory.

**Alternative considered:** Fully automated report. Rejected — audit committees
require human judgment and narrative context; LLM output is a draft starting
point, not a final product.

### D8 — SOX mode is tenant-toggled, not mandatory

The CCM capability ships without SOX mode enabled. A tenant with SOX 404
compliance mandate toggles `sox_mode: true` in their settings. That activates
additional schema fields (control-owner, control-frequency, control-evidence,
deficiency-rating) and the quarterly attestation workflow.

**Alternative considered:** SOX mode always on. Rejected — non-regulated
tenants (sole traders, small SMEs) find that metadata overhead. Feature
flag keeps it optional.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Immutable audit trail for findings | `bookkeeping-audit-trail` (from OR) | Finding evidence references audit-trail events by UUID |
| Journal-posting interception | `bookkeeping-journal-entries` REQ-JE-XXX hook points | Synchronous rule evaluation hooks into posting transaction |
| GL-account baselines | `bookkeeping-general-ledger` account balance history | GLLine records queried for baseline computation |
| Vendor master-data history | `bookkeeping-vendor-master` + extensions (master-data-change-log) | MD rules query vendor change log + bank-account history |
| Payment instruction pipeline | `bookkeeping-payments` release endpoint | Block-mode payment rules hook into release transaction |
| User roles + permissions | Nextcloud `OC\User\User` + shillinq role system | SoD function codes mapped nightly from user roles |
| Sanctions / PEP screening | `openconnector` screening-provider integration | Value-chain rules call openconnector screening API (optional) |
| Finding notifications | OR notification system + n8n (soft) | In-app notification on finding fire; n8n can push to Slack/Teams |
| Report storage | Nextcloud file API (`docudesk` wrapper) | Audit committee report stored as DigitalDocument |
| Dashboard | `launchpad` publication API | Findings summary + SoD scorecard published to launchpad |

**Net new code in implementation cycle:**
- 3 major services (RuleEngine, FindingService, AuditCommitteeReportGenerator)
- ~800 LOC PHP for DSL compilation + evaluation
- ~600 LOC Vue for findings dashboard, rule admin, audit-committee report UI
- 2 batch jobs (nightly baseline materialisation, nightly async-rule sweep)
- Database migrations (6 register tables + 3 indices)

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Rule definition (DSL + parameters) | Declarative (JSON schema) | Rules must be auditable, version-controlled, and tenant-configurable; not code |
| Rule evaluation | Imperative (PHP services) | Performance-critical; DSL compilation + evaluation needs tight control |
| Finding triage workflow | Declarative (state machine in `ccm_finding.status` enum) + imperative handlers | Workflow is a standard 4-state machine; handlers (auto-escalation, notifications) are imperative |
| Baseline computation | Declarative (batch-job schedule in config) | When to recompute is declarative; computation itself is imperative |
| SoD matrix definition | Declarative (ccm-segregation-matrix register entries) | Conflicts are reference data, not business logic |
| Audit committee report generation | Imperative (PHP service + Twig template) | Report generation is stateful and complex; assembly logic is imperative |
| SOX mode activation | Declarative (tenant config toggle) | Activation is a simple boolean flag |

## Seed Data

**Rule library (~60 rules):**
- Segregation-of-duties (7 rules): SoD-01 through SoD-07
- Duplicate detection (5 rules): DUP-01 through DUP-05
- Anomalous amounts (6 rules): AMT-01 through AMT-06
- Timing anomalies (6 rules): TIM-01 through TIM-06
- Master-data integrity (6 rules): MD-01 through MD-06
- Approval bypass (5 rules): AB-01 through AB-05
- Manual JE forensics (6 rules): MJ-01 through MJ-06
- Value-chain integrity (5 rules): VC-01 through VC-05
- Custom pool (12 rules reserved for tenant customization)

Each rule ships with:
- DSL logic expression (in `rule_logic` JSON)
- Parameter defaults (severity threshold, lookback window, sample size,
  Benford chi-square cutoff, etc.)
- Control family, objective, COSO assertion
- Evaluation mode (sync-block, sync-warn, async-detect)
- Trigger events
- Rule owner + reviewer (pre-assigned to internal-audit role)

**SoD function-code matrix:**
- 50 function codes (SAP / Oracle library, Dutch-customized)
- ~300 conflict pairs, with severity (low / medium / high / critical)
- Rationale + compensating-controls-allowed per conflict
- Seed data pre-loaded on tenant creation

**Example seed data records (Dutch):**

```json
{
  "ccm_rule": {
    "id": "CCM-002",
    "name": "Dezelfde gebruiker maakte en keurde factuur goed",
    "description": "Detecteert gevallen waarin één gebruiker zowel een leveranciersfactuur heeft aangemaakt als goedgekeurd",
    "control_family": "segregation-of-duties",
    "severity": "high",
    "evaluation_mode": "synchronous-warn",
    "trigger_events": ["invoice-approved"],
    "rule_logic": {
      "all-of": [
        { "event-matches": "invoice-approval" },
        { "user-is": "same-as-invoice-creator" },
        { "not": { "user-also-has-function": "INVOICE-REVIEW" } }
      ]
    }
  },
  "ccm_baseline": {
    "scope": "vendor",
    "metric": "amount-mean",
    "period": "2026-Q1",
    "sample_size": 450,
    "computed_value": 8750.50,
    "confidence_interval": [8200, 9300],
    "stable": true
  },
  "ccm_segregation_matrix": {
    "function_code": "PAYMENT-RELEASE",
    "conflicting_functions": ["INVOICE-POST", "BANK-RECONCILIATION"],
    "conflict_severity": "critical",
    "rationale": "Preventie van fiktieve facturen; geen vier-ogen-regel mogelijk"
  }
}
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Rule parameter tuning burden on tenant | Seed rules ship with conservative defaults grounded in research. Trend layer detects false-positive drift. Suppress-rules allow exceptions. Tenant can disable rules per risk appetite. |
| Synchronous latency > 100ms | DSL compiled once + cached. Baselines materialised. High-latency rules excluded from sync (relegated to async). CI latency gate (95th percentile test). |
| Async sweep doesn't finish by 06:00 | Incremental sweep (delta since last run). Benford + peer-comparison batched. Cluster autoscaling. SLA documented for ops escalation. |
| DSL injection / compiler breakage | JSON Schema validation before compilation. Compiled to AST, not `eval()`. Role-gated authorship. |
| SOX attestation scope creep; audit firm challenges sufficiency | SOX mode is optional (tenant toggle). Evidence shape validated with big-4 audit firm pre-release. Template based on PCAOB AS 2201. External auditor gets scoped read access for ISA 315 / ISA 330 testing. |
| Finding evidence volume explodes; storage costs | Evidence snapshots are de-referenced (stored as UUIDs + audit-trail event IDs, not full JSON clones). Finding lifecycle cleanup (findings >2 years old transition to archive). |
| False escalations waste CFO attention | Auto-escalation only for severity: critical, after 24h silent. Tenant can adjust escalation delay + severity threshold. Finding clustering groups related fires so escalation is per-pattern, not per-fire. |

## Migration Plan

**Cold-start for new tenants:**
1. Create the 6 register tables (`ccm_rule`, `ccm_finding`, `ccm_segregation_matrix`,
   `ccm_user_function_assignment`, `ccm_baseline`, `ccm_audit_committee_report`).
2. Seed the rule library (60 rules) + SoD matrix (50 function codes, 300 conflicts).
3. Kick off a one-time baseline-computation job over the tenant's prior 30–90 days
   of journal history (if available). If no prior history, rules with baseline
   requirements (AMT-04, AMT-05) ship disabled until baselines stabilise.
4. Materialise the SoD function-assignment matrix (nightly job, run immediately).

**Upgrade for existing tenants:**
1. Database migrations (6 new tables + indices).
2. Seed rule library (60 default rules); existing tenants have rules disabled by
   default (opt-in per rule).
3. Seed SoD matrix.
4. Run baseline-computation job over the last 90 days of journal history.
5. Enable journal-entry posting hook for synchronous rule evaluation.

**Down-direction:** Disable CCM (tenant config toggle). Registers remain; findings
remain queryable through audit-trail API. No data loss. Re-enabling later is
seamless (existing findings resurface).

## Open Questions

1. **What LLM provider for audit-committee summary draft?** OpenAI, Claude,
   Ollama local? Cost / latency / privacy tradeoff. Resolved in `opsx-ff`.
2. **How is SoD conflict severity weighted in a multi-policy scenario?** E.g.,
   user holds functions that conflict under policy A but not B. Resolved in
   detailed spec of REQ-CCM-003.
3. **What is the "acceptable" false-positive rate threshold?** >1% dismissed
   findings should trigger rule re-tuning. Tenant-configurable? Resolved in
   Task 4 (trend-analysis logic).
4. **Cold-start baseline stability:** If a tenant has <14 days of history,
   Benford + peer-group rules can't compute stable baselines. What's the
   fallback? Skip the rule, or use a "cold-start" synthetic distribution?
   Resolved in Task 5 (baseline materialisation).
5. **Escalation path for critical findings:** Auto-escalate to CFO only, or
   rotate through a configurable escalation chain? Resolved in Task 3
   (finding triage workflow).
