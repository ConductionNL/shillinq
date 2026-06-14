# Spec: bookkeeping-ccm-rule-engine

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:**
- `bookkeeping-journal-entries` (synchronous rule evaluation at posting time)
- `bookkeeping-audit-trail` (immutable evidence for findings)
- `bookkeeping-general-ledger` (GL-account baselines for anomaly detection)
- `bookkeeping-vendor-master` (master-data integrity rule data)
- `bookkeeping-payments` (payment-instruction interception for block-mode rules)

## ADDED Requirements

### REQ-CCM-001: The system SHALL register rule definitions (`ccm-rule`) with control family, objective, COSO assertion, evaluation mode, DSL logic, and parameter defaults

Every control rule in the CCM library MUST be registered as a `ccm-rule`
register entry with the following attributes:

| Property | Type | Required | Purpose |
|---|---|---|---|
| `rule_code` | string | Yes | Rule identifier, e.g., `CCM-002`, sequential per tenant |
| `rule_name` | string | Yes | Human-readable rule name in Dutch |
| `description` | string | Yes | Plain-language description of the control objective |
| `control_family` | enum | Yes | One of: segregation-of-duties, duplicate-detection, anomalous-amount, timing, master-data, approval-bypass, manual-journal, value-chain, custom |
| `control_objective` | string | Yes | The fraud/control pattern being prevented (e.g., "prevent vendor master fraud") |
| `control_assertion` | enum | Yes | COSO assertion: existence, completeness, accuracy, valuation, rights-and-obligations, presentation-and-disclosure |
| `severity` | enum | Yes | informational, low, medium, high, critical |
| `evaluation_mode` | enum | Yes | synchronous-block (prevent posting), synchronous-warn (allow + flag), or asynchronous-detect (nightly sweep) |
| `schedule` | enum | Yes | event-triggered (sync rules), hourly, daily, weekly, monthly (async rules) |
| `trigger_events` | array | Yes | One or more of: journal-entry-posted, vendor-created, vendor-modified, payment-instruction-created, approval-granted, period-end-approaching, etc. |
| `rule_logic` | JSON | Yes | Constrained DSL expression (see REQ-CCM-002) |
| `parameters` | object | No | Tenant-configurable thresholds (z-score, lookback window, sample size, Benford chi-square, etc.) with sensible defaults |
| `suppression_rules` | object | No | Known exceptions (e.g., "JE from parent consolidation, tagged `parent-consolidation`, always suppressed") |
| `enabled` | boolean | Yes | Whether the rule is active for this tenant (defaults: false for custom rules, true for seed rules) |
| `effective_from` | datetime | No | Date rule becomes active |
| `effective_to` | datetime | No | Date rule expires |
| `rule_owner` | FK user | Yes | Typically internal-audit or CFO; owns triage of findings |
| `rule_reviewer` | FK user | No | Typically external-auditor for SOX engagements |
| `last_review_date` | datetime | No | Last date the rule was reviewed for effectiveness |
| `next_review_date` | datetime | No | Next scheduled review |
| `sox_key_control` | boolean | No | Whether this rule is a key control for SOX 404 (if sox_mode enabled) |
| `version_history` | array | No | Self-FK chain for rule versioning (change audit trail) |

Every rule MUST be version-controlled in its `version_history` chain; changes to rule DSL, parameters, or status are immutable audit events.

#### Scenario: Duplicate-invoice rule is registered with SoD family

- **GIVEN** `ccm-rule` with rule_code="CCM-001", control_family="duplicate-detection"
- **WHEN** queried for the rule
- **THEN** control_objective, control_assertion, severity, evaluation_mode, trigger_events, and rule_logic MUST all be present and not null

#### Scenario: Rule parameter defaults are grounded in research

- **GIVEN** `ccm-rule` with control_family="anomalous-amount" (e.g., Benford's Law detection)
- **WHEN** `parameters.benford_chi_square_threshold` is inspected
- **THEN** the default MUST be grounded in published forensic-accounting research (e.g., Nigrini 1996, or Durtschi-Hillison-Pacini 2004, cited in the rule's documentation)

### REQ-CCM-002: The rule DSL SHALL be a constrained JSON expression language with deterministic evaluation (no `eval()`, no arbitrary code execution)

Rules are expressed as JSON-serializable expressions using a fixed set of
operators. The DSL is compiled to an abstract syntax tree (AST) on first
load, cached, and evaluated against a transaction context without dynamic
code execution.

**Allowed operators:**

| Operator | Arity | Purpose | Example |
|---|---|---|---|
| `event-matches` | 1 | Matches the triggering event type | `{ "event-matches": "journal-entry-posted" }` |
| `field-equals` | 2 | Field value matches a literal | `{ "field-equals": ["posting_channel", "manual-by-user"] }` |
| `field-in-set` | 2 | Field value is in an enumerated set | `{ "field-in-set": ["account_code", ["4100", "4200"]] }` |
| `field-greater-than` | 2 | Field value exceeds a threshold | `{ "field-greater-than": ["amount", 50000] }` |
| `field-between` | 3 | Field value is within a range | `{ "field-between": ["amount", 1000, 99999] }` |
| `field-matches-regex` | 2 | Field matches a regex pattern | `{ "field-matches-regex": ["narrative", "^adjustment|correction$"] }` |
| `time-is-within` | 2 | Timestamp within a window | `{ "time-is-within": ["posting_date", "last-day-of-period", 2] }` (last 2 days of period) |
| `time-is-weekend` | 1 | Timestamp falls on Saturday or Sunday | `{ "time-is-weekend": "posting_date" }` |
| `time-is-outside-business-hours` | 2 | Timestamp outside 08:00–18:00 local | `{ "time-is-outside-business-hours": ["posting_date", "08:00", "18:00"] }` |
| `user-has-function` | 2 | User holds a SoD function code | `{ "user-has-function": ["posting_user", "INVOICE-POST"] }` |
| `user-also-has-function` | 3 | User holds a second SoD function | `{ "user-also-has-function": ["posting_user", "PAYMENT-RELEASE", "INVOICE-POST"] }` (checks if same user also holds second) |
| `user-is-in-role` | 2 | User has a role (admin, cfo, auditor) | `{ "user-is-in-role": ["posting_user", "cfo"] }` |
| `value-deviates-from-baseline` | 4 | Value (z-score) exceeds baseline stddev | `{ "value-deviates-from-baseline": ["amount", "vendor", 3.0, "12-month"] }` |
| `value-violates-benford` | 3 | Leading-digit distribution violates Benford | `{ "value-violates-benford": ["amount", "leading-digit", 0.05] }` (chi-square p-value threshold) |
| `count-of-similar-in-period-exceeds` | 4 | Count of similar items exceeds threshold | `{ "count-of-similar-in-period-exceeds": ["vendor", "amount", 5, "30-days"] }` |
| `duplicate-of-existing` | 4 | Exact or fuzzy match to prior transaction | `{ "duplicate-of-existing": ["vendor", "amount", "30-days", 1.0] }` (1.0 = exact match) |
| `master-data-changed-within` | 3 | Master data changed recently | `{ "master-data-changed-within": ["vendor", "14-days", "bank_account"] }` |
| `approval-chain-bypassed` | 2 | Approval chain is shorter than policy | `{ "approval-chain-bypassed": ["amount_threshold", "policy"] }` |
| `posted-while-period-closing` | 2 | Posted during period-end close window | `{ "posted-while-period-closing": ["posting_date", "3-days"] }` |
| `same-user-as` | 3 | Back-reference to related user field | `{ "same-user-as": ["approver", "creator", "jes"] }` (compare approver to the creator of related JE) |

**Compound operators:**

- `all-of` (AND) — all sub-expressions must be true
- `any-of` (OR) — at least one sub-expression must be true
- `none-of` (NOT) — all sub-expressions must be false

**Evaluation:**

1. DSL is validated against a JSON Schema on insert/update.
2. On rule load, the DSL is compiled to an AST by `RuleEngine::compileRule()`.
3. The AST is cached in memory (Redis TTL = rule's `updated_at` + 7 days).
4. At evaluation time, the transaction context is passed to `RuleEngine::evaluate(ast, context)`, which traverses the AST and returns a boolean + diagnostic metadata.
5. No `eval()`, `shell_exec()`, `exec()`, `assert()`, or dynamic class instantiation.

#### Scenario: Rule DSL is validated on registration

- **GIVEN** a `ccm-rule` with invalid DSL (e.g., unknown operator)
- **WHEN** the rule is saved
- **THEN** a validation error MUST be raised; the rule MUST NOT be created

#### Scenario: Rule AST is cached and reused

- **GIVEN** a rule evaluated on transaction 1
- **WHEN** the same rule is evaluated on transaction 2 (without rule modification)
- **THEN** the RuleEngine MUST reuse the cached AST without recompilation (≤10µs lookup overhead)

#### Scenario: DSL evaluation produces diagnostic metadata

- **GIVEN** a rule evaluating `value-deviates-from-baseline` with z-score=3.5
- **WHEN** the rule fires (z-score exceeds 3.0 threshold)
- **THEN** the diagnostic MUST include `z_score: 3.5`, `baseline_mean: 5000.00`, `baseline_stddev: 1200.00` so the finding investigator knows *why* the rule fired

### REQ-CCM-003: Synchronous rules MUST add ≤100ms latency to journal posting at the 95th percentile; asynchronous rules SHALL complete nightly by 06:00 local time

**Synchronous evaluation** (sync-block, sync-warn modes):

- Triggered during the `bookkeeping-journal-entries` post transaction.
- Only rules with `evaluation_mode="synchronous-block"` or `"synchronous-warn"` are evaluated.
- Total latency (rule evaluation + database queries for baselines + context gathering) MUST NOT exceed 100ms at the 95th percentile across a representative workload (100K journal lines/year, 20–50 active sync rules).
- Measurement is continuous; CI gate runs a load test on every implementation PR and reports 95th percentile latency. Latency SLA is published to operations.

**Asynchronous evaluation** (async-detect mode):

- Runs on a nightly batch job scheduled for 23:00 local time.
- Only rules with `evaluation_mode="asynchronous-detect"` are evaluated.
- Evaluation is incremental: only transactions posted since the last sweep are re-evaluated.
- Population-based rules (Benford, peer-group comparison, trend deviation) are evaluated in batches per GL-account or vendor (not per-transaction).
- Completion SLA: ≤7 hours (finish by 06:00) for a tenant with 1M+ journal lines/year. If the sweep will miss the window, the job escalates to operations for cluster autoscaling.
- Baseline computation (used by sync + async rules) runs concurrently with the async sweep; baseline recomputation SLA: ≤1 hour.

#### Scenario: Journal posting latency is within SLA

- **GIVEN** a journal entry with 10 active sync rules
- **WHEN** the entry is posted
- **THEN** the posting transaction MUST complete within 100ms at p95 (measured across 1000+ postings/day workload)

#### Scenario: Async sweep completes by 06:00 for large tenant

- **GIVEN** a tenant with 50K journal lines added since yesterday's 23:00
- **WHEN** the nightly sweep starts at 23:00
- **THEN** all async rules MUST be evaluated + findings created by 06:00; if forecast shows miss, ops is notified by 02:00 with cluster-scale recommendations

### REQ-CCM-004: Every finding SHALL capture immutable evidence (before/after, actor, approver chain) at fire time and SHALL transition through a four-state workflow (open → under-investigation / dismissed / escalated)

A **`ccm-finding`** register entry is created on every rule fire. It captures:

| Property | Type | Required | Purpose |
|---|---|---|---|
| `rule_id` | FK ccm-rule | Yes | Which rule fired |
| `fire_timestamp` | datetime | Yes | When the rule fired |
| `triggering_event` | object | Yes | Typed reference: journal-entry UUID, vendor UUID, payment-instruction UUID, etc. |
| `severity` | enum | Yes | inherited from rule; overridable per finding |
| `title` | string | Yes | Human-readable title (auto-generated, e.g., "Manual JE EUR 487,000 posted by CFO on last day of Q4") |
| `evidence` | JSON | Yes | Immutable snapshot of transaction context at fire time: journal entry lines, user, approvals, vendor master, recent baseline values, etc. |
| `suggested_investigation_steps` | array | Yes | Auto-generated from rule template (e.g., "Review invoice approval chain", "Compare amount to 12-month vendor baseline") |
| `assignee` | FK user | Yes | Defaults to rule owner; can be reassigned |
| `status` | enum | Yes | open, under-investigation, awaiting-information, dismissed-false-positive, dismissed-acceptable-risk, confirmed-control-deficiency, confirmed-fraud-suspected |
| `status_history` | array | Yes | Audit trail of status changes with {timestamp, user, old_status, new_status} |
| `investigation_notes` | array | Yes | Append-only array of {timestamp, user, note}; no editing |
| `resolution_rationale` | string | No | Mandatory free-text on dismissal or escalation |
| `resolved_by` | FK user | No | User who closed the finding |
| `resolved_at` | datetime | No | When the finding was closed |
| `escalated` | boolean | No | Whether finding is escalated (auto-set after 24h inactivity on critical findings) |
| `escalated_to` | FK user or role | No | CFO, audit committee chair, etc. |
| `escalated_at` | datetime | No | When auto-escalation triggered |
| `linked_findings` | array | No | FKs to related findings (for clustering by vendor/user/pattern) |
| `external_reference` | string | No | e.g., audit-firm finding number, whistleblower report ID |

**Four-state workflow:**

```
open → under-investigation → {dismissed-with-rationale | confirmed-control-deficiency}
                            → escalated-to-cfo
```

**Rules:**
- Findings start in `open` status with `assignee = rule_owner`.
- Assignee is notified (in-app) on finding creation.
- Only users with `internal_audit` or `finance_director` roles can transition status.
- Transition to `dismissed-*` REQUIRES `resolution_rationale` (mandatory text).
- Transition to `confirmed-*` REQUIRES `resolution_rationale` + optional `escalated_to` user.
- Auto-escalation: if a `severity=critical` finding remains in `open` or `under-investigation` for >24 hours, it auto-transitions to `escalated` with `escalated_to = CFO_user_id`.

**Evidence immutability:**
- `evidence` JSON is captured at fire time and NEVER updated.
- Investigation notes are append-only; no deletions, no edits.
- All transitions are audit-trailed by the `status_history` array.

#### Scenario: Critical finding auto-escalates after 24h inactivity

- **GIVEN** a finding with `severity=critical`, `status=open`, `fire_timestamp = 2026-05-22 15:00`
- **WHEN** the clock reaches 2026-05-23 15:05 (24h 5min later) AND the status has not changed
- **THEN** a scheduled job MUST auto-transition the finding to `status=escalated`, set `escalated_to = CFO`, set `escalated_at = now`, and send a notification to the CFO

#### Scenario: Finding evidence is immutable

- **GIVEN** a finding with `evidence = {journal_entry: {...}, posting_user: "alice@example.com"}`
- **WHEN** the finding's `status` is later changed to `dismissed-acceptable-risk`
- **THEN** the `evidence` JSON MUST remain unchanged; investigation notes are appended but not edited

### REQ-CCM-005: Segregation-of-duties violations SHALL be detected by comparing user function-code assignments against the SoD matrix

The **SoD matrix** (`ccm-segregation-matrix`) defines function-code conflicts:

| Property | Type | Purpose |
|---|---|---|
| `function_code` | string | e.g., VENDOR-CREATE, INVOICE-POST, PAYMENT-RELEASE, BANK-RECONCILIATION, JOURNAL-MANUAL, MASTER-DATA-CHANGE, APPROVAL-EXPENSE |
| `conflicting_functions` | array | Array of function codes; no single user MAY hold two or more codes from this set simultaneously |
| `conflict_severity` | enum | low, medium, high, or critical |
| `rationale` | string | Plain-language reason (e.g., "Preventie van fiktieve facturen; geen vier-ogen-regel mogelijk") |
| `compensating_controls_allowed` | array | List of text descriptions (e.g., "Secondary approval by CFO", "Monthly reconciliation by external auditor") |

**User function-code assignment** (`ccm-user-function-assignment`):
- Computed nightly (batch job) from Nextcloud roles + shillinq direct role assignments + temporary elevations (e.g., "alice elevated to PAYMENT-RELEASE during vacation cover, expires 2026-06-30").
- For each user: map their roles → function codes.
- Check each user's function codes against the SoD matrix; if a user holds conflicting codes, create a finding.

**SoD rule evaluation (synchronous):**
- When a user performs an action that triggers a function (e.g., posts a payment = PAYMENT-RELEASE), check the SoD matrix in real time.
- If the posting user also holds a conflicting function (e.g., INVOICE-CREATION), fire an SoD rule and create a finding.
- High-confidence SoD violations are synchronous-block (prevent the action); lower-confidence can be synchronous-warn.

#### Scenario: User with dual SoD function conflict is detected

- **GIVEN** user alice holds roles [AP-Clerk, Treasurer]
- **WHEN** the nightly materialization job maps alice's roles to function codes and discovers she holds both INVOICE-POST and PAYMENT-RELEASE
- **THEN** a `ccm-user-function-assignment` record is created for alice with `conflict_with = [{ "function": "PAYMENT-RELEASE", "severity": "critical" }]`, and the SoD-03 rule triggers a finding

#### Scenario: SoD violation can be overridden with documented compensating control

- **GIVEN** a critical SoD finding (user holds conflicting functions)
- **WHEN** the rule_owner reviews the finding and sees `compensating_controls_allowed = ["Secondary approval by CFO"]`
- **THEN** the finding can be dismissed with rationale "Compensated by CFO monthly review of payment log" (rationale references the control)

### REQ-CCM-006: The system SHALL generate a monthly or quarterly audit-committee report with executive summary, rule-firing trends, SoD compliance scorecard, and optional SOX 404 deficiency log

The **`ccm-audit-committee-report`** register captures the period-end
deliverable:

| Property | Type | Purpose |
|---|---|---|
| `period` | string | e.g., "2026-Q1", "2026-05" (month) |
| `generated_at` | datetime | Report generation timestamp |
| `generator_user_id` | FK user | Typically internal-audit lead or CFO |
| `executive_summary` | string | LLM-drafted (editable before approval), ~300 words, summarizing key findings + risks + recommendations |
| `rule_firings_by_family` | object | { "segregation-of-duties": 12, "duplicate-detection": 5, ... } |
| `rule_firings_by_severity` | object | { "critical": 2, "high": 15, "medium": 30, "low": 100, "informational": 500 } |
| `findings_by_status` | object | { "open": 5, "under-investigation": 3, "dismissed-false-positive": 50, "confirmed-control-deficiency": 2 } |
| `unresolved_criticals` | array | FKs to finding IDs with severity=critical and status != dismissed/confirmed |
| `top_n_findings` | array | Top 5–10 findings by severity + investigation time (longest-open first) |
| `trend_analysis` | object | Year-over-year rule-fire rates, dismissal rates, average time-to-resolution per rule |
| `sod_compliance_scorecard` | object | { "users_compliant": 350, "users_non_compliant": 12, "conflicts_critical": 1, "conflicts_high": 8 } |
| `manual_je_summary` | object | Count of manual JEs by poster, count flagged by rules, % with secondary approval |
| `approver_bypass_summary` | object | Count of approval-chain deviations, count overridden with rationale |
| `sox_deficiencies` | array | If `sox_mode=true`: array of { finding_id, control_code, deficiency_type: "control_deficiency\|significant\|material_weakness", rating: "minor\|medium\|major", remediation_plan } |
| `approver_user_id` | FK user | Audit committee chair or CFO who signs off |
| `approval_date` | datetime | When approved for distribution |
| `distribution_log` | array | Array of { recipient_email, recipient_role, sent_at } |

**Generation workflow:**
1. Report is auto-generated on a configurable schedule (monthly default, quarterly for SOX mode).
2. The generator (internal-audit lead) drafts the executive summary via an LLM (OpenAI / Claude, cost TBD in `opsx-ff`).
3. The CFO reviews and edits the summary.
4. The report is finalized and sent to the audit committee chair for sign-off.
5. No report is auto-published; human approval is mandatory.

#### Scenario: Monthly report is generated with trend analysis

- **GIVEN** a `ccm-audit-committee-report` for period="2026-05"
- **WHEN** the report is generated
- **THEN** `trend_analysis` MUST include: SoD-rule fire-rate (this month vs. last month), dismissal-rate trend, average time-to-resolution trend, and a flag if any rule's fire-rate has drifted >10% above baseline

#### Scenario: SOX mode report includes deficiency ratings

- **GIVEN** `sox_mode=true` and a critical SoD finding escalated to control-deficiency status
- **WHEN** the period-end report is generated
- **THEN** the finding MUST be included in `sox_deficiencies` array with a filled `deficiency_type` (e.g., "significant_deficiency") and `rating` (e.g., "major"), along with a `remediation_plan` field (mandatory for inclusion)

### REQ-CCM-007: The rule library SHALL ship with 60 seed rules covering eight control families (segregation-of-duties, duplicate-detection, anomalous-amount, timing, master-data, approval-bypass, manual-journal, value-chain)

**Segregation-of-duties (7 rules):**
- SoD-01: Same-user-created-and-paid-vendor (VENDOR-CREATE + PAYMENT-RELEASE)
- SoD-02: Same-user-created-and-approved-invoice (INVOICE-CREATE + INVOICE-APPROVAL)
- SoD-03: Approver-also-posted JE (JOURNAL-APPROVAL + JOURNAL-POST)
- SoD-04: Reconciler-also-posted bank reconciliation (BANK-REC + BANK-REC-POST)
- SoD-05: Master-data-change-by-AP-clerk-without-approval (VENDOR-MODIFY without secondary approval)
- SoD-06: Dormant-account-reactivated-by-single-user (reactivate vendor after >12mo dormancy)
- SoD-07: Critical-role-held-by-single-user (no backup; business-continuity risk)

**Duplicate-detection (5 rules):**
- DUP-01: Exact-duplicate-invoice (vendor + amount + reference exact match)
- DUP-02: Fuzzy-duplicate-invoice (vendor + amount within 1% + within 30 days)
- DUP-03: Duplicate-payment (vendor + bank + amount + within 7 days)
- DUP-04: Duplicate-vendor (same bank account, different name/address/KvK)
- DUP-05: Duplicate-employee (same bank account on two employee records)

**Anomalous-amounts (6 rules):**
- AMT-01: Benford's-Law-violation (leading-digit chi-square > threshold over 12-month window)
- AMT-02: Round-number-anomaly (suspicious frequency of "10,000.00" style amounts)
- AMT-03: Just-under-threshold (amount EUR 24,950 when approval threshold EUR 25,000)
- AMT-04: Amount-deviates-from-vendor-baseline (z-score > 3)
- AMT-05: Amount-deviates-from-GL-account-baseline (z-score > 3)
- AMT-06: Vendor-spike (vendor total this month > 5× trailing-12-month average)

**Timing-anomalies (6 rules):**
- TIM-01: Weekend-posting (manual JE on Saturday or Sunday)
- TIM-02: Outside-business-hours-posting (manual JE between 20:00–06:00 local)
- TIM-03: Last-day-of-period-posting (manual JE on last business day of quarter, amount > threshold)
- TIM-04: Backdated-posting (entry date > 5 business days before posting date)
- TIM-05: Forward-dated-posting (posting date before entry date)
- TIM-06: Period-end-concentration (>30% of monthly manual JEs in last 3 days)

**Master-data-integrity (6 rules):**
- MD-01: Vendor-bank-account-changed-before-large-payment (bank account change within 14 days of payment > threshold)
- MD-02: Vendor-name-change-shortly-after-creation (layering risk)
- MD-03: Vendor-address-matches-employee-address (conflict-of-interest risk)
- MD-04: Vendor-phone-matches-employee-phone (conflict-of-interest risk)
- MD-05: Vendor-created-and-used-same-day (rush vendor; no time for due diligence)
- MD-06: Dormant-vendor-reactivated-for-first-payment (reactivate after >12mo dormancy)

**Approval-bypass (5 rules):**
- AB-01: Emergency-override-invoked (override flag set on transaction)
- AB-02: Approval-chain-shorter-than-policy (actual approver count < required count)
- AB-03: Approver-on-leave-but-approved (timestamp during recorded absence)
- AB-04: Self-approval (approver = requester)
- AB-05: Split-PO (multiple POs to same vendor within 30 days, sum > approval threshold)

**Manual-JE-forensics (6 rules):**
- MJ-01: Manual-JE-by-C-suite-without-secondary-approval (C-suite can post JE only with secondary approval)
- MJ-02: Manual-JE-with-vague-narrative ("adjustment", "correction" with no detail)
- MJ-03: Manual-JE-reversed-within-5-days (potential covering of initial JE)
- MJ-04: Manual-JE-to-revenue-account (unusual; check authorization)
- MJ-05: Manual-JE-to-reserves-account (unusual; check authorization)
- MJ-06: Round-trip-JE (Dr/Cr same account through suspense account)

**Value-chain-integrity (5 rules):**
- VC-01: Payment-to-high-risk-country (per FATF / EU sanctions list)
- VC-02: Payment-to-entity-on-EU-sanctions-list (auto-blocked per AML regulation)
- VC-03: Vendor-with-PEP-match (Politically Exposed Person; continuous monitoring)
- VC-04: Customer-with-adverse-media-match (continuous monitoring)
- VC-05: Invoice-from-new-vendor-without-KvK-validation (missing business registration)

Each seed rule ships with DSL logic, parameter defaults, severity, evaluation mode, and rule owner assignment.

#### Scenario: Benford's-Law rule ships with conservative chi-square threshold

- **GIVEN** rule AMT-01 (Benford's Law violation)
- **WHEN** the rule's parameters are inspected
- **THEN** `benford_chi_square_threshold` MUST default to 0.05 (p-value), grounded in Nigrini 1996 forensic-accounting research and cited in the rule documentation

#### Scenario: Custom rules can be authored by admin users

- **GIVEN** a tenant admin user
- **WHEN** they create a new `ccm-rule` with `control_family="custom"` and valid DSL
- **THEN** the rule is created and disabled by default; admin must explicitly enable it per rule

### REQ-CCM-008: The system SHALL notify the rule owner (typically internal-audit or CFO) when a critical or high-severity finding is created, and SHALL auto-escalate critical findings to the CFO after 24 hours of inactivity

**Notification on finding creation:**
- In-app notification to the `assignee` (default: `rule_owner`): "Finding created: {rule_name}, {title}. Assigned to you. Please review and triage."
- Optional: n8n integration (out-of-scope for base capability) can push to Slack/Teams/email for out-of-app visibility.

**Auto-escalation after 24h:**
- A scheduled job runs every 6 hours (or more frequently, depending on SLA negotiation).
- For each finding with `severity=critical` AND `status ∈ [open, under-investigation]` AND `fire_timestamp < now - 24h`:
  - Auto-transition to `status=escalated`.
  - Set `escalated_to = CFO_user_id`.
  - Set `escalated_at = now`.
  - Send in-app notification to CFO: "Critical finding auto-escalated: {rule_name}. Originally assigned to {assignee}. Click to triage."

#### Scenario: Critical finding is created and escalates after 24h inactivity

- **GIVEN** a finding with `severity=critical`, `fire_timestamp=2026-05-22T15:00Z`, `assignee=alice@internal-audit`
- **WHEN** the clock reaches 2026-05-23T15:05Z and the finding's status is still `open`
- **THEN** the auto-escalation job MUST set `status=escalated`, `escalated_to=cfo@example.com`, and notify the CFO

### REQ-CCM-009: All rules, findings, and audit-committee reports SHALL have immutable audit trails per `bookkeeping-audit-trail`, with every state change capturing actor, timestamp, before/after diff, and hash-chain verification

Per ADR-022, all six register schemas (`ccm-rule`, `ccm-finding`,
`ccm-segregation-matrix`, `ccm-user-function-assignment`, `ccm-baseline`,
`ccm-audit-committee-report`) MUST carry `x-openregister-audit: true` in
their schema declarations.

Every create / update / lifecycle transition (e.g., finding status change) is
captured in OR's append-only, hash-chained audit log. Shillinq MUST NOT
ship an `AuditService`, `AuditLogger`, or app-local audit table.

#### Scenario: Rule modification is audit-trailed

- **GIVEN** a rule with DSL logic modified from v1 to v2
- **WHEN** the audit trail is queried for that rule
- **THEN** the audit event MUST include actor, timestamp, `beforeState` (DSL v1), `afterState` (DSL v2), and hash-chain verification

#### Scenario: No app-local audit code exists

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/Audit*`, `lib/Db/AuditTrail*`, `lib/Service/Audit*`
- **THEN** no such classes or files SHALL exist (enforcement via code review + CI gate)
