---
sidebar_position: 9
title: Monitor controls with the CCM rule engine
description: Triage findings raised by the Continuous Controls Monitoring rule engine, manage the rule library, read the segregation-of-duties scorecard, and sign off the audit-committee report.
---

# Monitor controls with the CCM rule engine

The Continuous Controls Monitoring (CCM) rule engine evaluates every journal
entry, vendor change, and payment instruction against a library of forensic and
SOX-style controls, at the point of posting (synchronously) and on a nightly
sweep (asynchronously). Anomalies become *findings* that you triage through a
four-state workflow, and the period's controls evidence rolls up into an
audit-committee report. This replaces the once-a-year, sample-based external
audit test with a continuous, population-based evidence layer.

## Goal

By the end you will know how to read the findings dashboard, triage a finding
to resolution, enable or tune a rule, read the segregation-of-duties (SoD)
scorecard, and sign off the quarterly audit-committee report.

## Prerequisites

- Shillinq open and the OpenRegister back end connected (see [Open Shillinq for the first time](01-first-launch.md)).
- The `internal-audit` or `finance-director` role on the Shillinq instance, only these roles may close findings or approve reports.
- The CCM seed rule library and the SoD function-code matrix imported (the admin handles this, see [Configure Continuous Controls Monitoring](../admin/04-ccm-configuration.md)).

## Findings triage

A finding is created the moment a rule fires. It captures an immutable evidence
snapshot (the transaction, the actor, the approval chain, the GL context) that
is never edited afterwards, so an auditor can always see exactly why the rule
fired.

1. Open **Continuous Controls Monitoring → Findings**. Findings are listed by
   rule, severity, status, and fire time.
2. Click a finding to open it. The evidence snapshot, severity, and the
   suggested investigation steps are shown.
3. Use **Start investigation** to take ownership (status moves to
   *under-investigation*).
4. Add **investigation notes** as you work, notes are append-only, so the full
   history of how the finding was investigated is preserved.
5. Resolve the finding:
   - **Dismiss — false positive** or **Dismiss — acceptable risk**: a written
     resolution rationale is mandatory.
   - **Confirm — control deficiency** or **Confirm — fraud suspected**: a
     written rationale is mandatory; the finding is escalated.

Critical-severity findings that stay *open* for more than 24 hours are
auto-escalated to the CFO.

## Rule library

The library ships 60 seed rules across eight families: segregation of duties,
duplicate detection, anomalous amounts, timing anomalies, master-data
integrity, approval bypass, manual-journal forensics, and value-chain integrity.

1. Open **Continuous Controls Monitoring → Rule Library**.
2. Each rule shows its code, family, severity, evaluation mode (synchronous
   block / synchronous warn / asynchronous detect), and whether it is enabled.
3. Open a rule to read its description, control objective, and COSO assertion.
4. Rules ship with conservative defaults grounded in forensic-accounting
   research; an admin can tune thresholds or disable a rule per your risk
   appetite (see the admin guide).

## Segregation-of-duties scorecard

The **Function Assignments** view shows which SoD function codes each user
holds, recomputed nightly from roles and grants. Where a user holds two
conflicting functions (for example *VENDOR-CREATE* and *PAYMENT-RELEASE*), the
conflict is recorded against the **Segregation Matrix** and a finding is raised.

## Audit-committee report

1. Open **Continuous Controls Monitoring → Audit Committee Reports**.
2. The report is auto-assembled per period: rule firings by family and severity,
   findings by status, the SoD compliance scorecard, the manual-journal summary,
   and (when SOX mode is on) the SOX 404 deficiency log. The executive summary
   is drafted automatically for you to edit.
3. Edit the executive summary, then **Submit for review**.
4. The audit-committee chair **Approves** the report, approval is blocked unless
   an approver and an executive summary are present.
5. **Distribute** the approved report; the distribution is logged.

## FAQ

- **Too many false positives?** Tune the rule's parameters or disable it; the
  trend layer flags rules that drift so the admin can retune them.
- **Why can't I close a finding?** Only the `internal-audit` or
  `finance-director` role may close findings, this is a deliberate control.
- **What is SOX mode?** An optional toggle that adds control-owner, frequency,
  evidence, and deficiency-rating metadata plus a quarterly attestation
  workflow, see the admin guide.
