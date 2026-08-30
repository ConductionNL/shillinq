---
sidebar_position: 4
title: Configure Continuous Controls Monitoring
description: Seed the CCM rule library and SoD matrix, understand the nightly materialisation jobs, manage rule lifecycle and baselines, and enable SOX mode.
---

# Configure Continuous Controls Monitoring

The Continuous Controls Monitoring (CCM) rule engine adds six OpenRegister
schemas (`CcmRule`, `CcmFinding`, `CcmSegregationMatrix`,
`CcmUserFunctionAssignment`, `CcmBaseline`, `CcmAuditCommitteeReport`) to the
`shillinq` register, plus a seed library of 60 rules and a segregation-of-duties
function-code matrix. This guide covers the one-time configuration and the
ongoing lifecycle.

## Goal

By the end the CCM schemas, seed rules, and SoD matrix will be imported, the
nightly jobs will be understood, and you will know how to manage the rule
lifecycle, baselines, escalation paths, and SOX mode.

## Prerequisites

- Admin rights on the Shillinq instance and a connected OpenRegister back end
  (see [Manage Shillinq settings](03-admin-settings.md)).

## Seeding the rule library and SoD matrix

The CCM schemas and seed objects ship as an ADR-037 register fragment
(`lib/Settings/register.d/bookkeeping-ccm-rule-engine.json`) and are imported by
the same forced-reimport flow as the rest of the register. On install or upgrade
the 60 seed rules and the SoD function-code matrix are loaded into the
`shillinq` register. Seeding is idempotent, an existing rule (matched by
`ruleCode`) is not re-created on a later upgrade.

## Nightly materialisation jobs

The three nightly jobs are declared on the schemas as OpenRegister scheduled
workflows (no PHP background-job classes are involved):

| Job | Schedule | Purpose |
|---|---|---|
| Async rule sweep | 23:00 | Evaluates asynchronous-detect rules (Benford, peer-group, trend) over transactions since the last sweep; must complete by 06:00. |
| SoD materialisation | 23:15 | Recomputes every user's SoD function-code assignments from roles and grants, recording conflicts against the matrix. |
| Baseline materialisation | 23:30 | Computes mean / median / stddev / Benford / typical-hour baselines per scope over a rolling 12-month window; completion SLA ≤1h. |

A separate hourly workflow auto-escalates critical findings still *open* after
24 hours to the CFO.

## Rule lifecycle

- **Seed rules** ship enabled with conservative defaults grounded in published
  forensic-accounting research (Nigrini's Benford's Law, AICPA SAS 99, the ISACA
  CCM guide).
- **Custom rules** use the reserved custom-rule slots; a custom rule ships
  disabled until you give it logic and enable it.
- **Tuning** is per-rule: thresholds (z-score, lookback window, Benford
  chi-square cutoff) live in the rule's `parameters`.
- **Drift**: the trend layer tracks each rule's fire-rate, false-positive rate,
  and dismissal rate; a rule firing far above its baseline is flagged for a
  retuning review.

## Baseline stability

Anomalous-amount and Benford rules compare against materialised baselines. A
baseline is marked `stable: false` when the sample size or variance is too small
to flag against, rules that depend on an unstable baseline do not fire until it
stabilises. For a brand-new tenant with little history, give the baseline jobs a
few nights to accumulate a representative window before relying on those rules.

## Escalation paths

Critical findings auto-escalate to the CFO (`finance-director` role) after 24
hours without a response. The escalation delay and the severity threshold are
configurable per tenant.

## SOX mode

SOX mode is an optional tenant toggle. When enabled it activates the additional
metadata a SOX 404 attestation requires (control owner, control frequency,
control evidence, deficiency rating, remediation plan) and the quarterly
management-certification workflow on the audit-committee report. Leave it off for
non-regulated tenants to avoid the metadata overhead.

## Audit-trail retention

Every rule, finding, and report carries an immutable OpenRegister audit trail.
Finding evidence remains queryable through the audit-trail API even after CCM is
disabled, retain findings per your legal-hold and Archiefwet obligations.
