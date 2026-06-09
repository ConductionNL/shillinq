---
sidebar_position: 30
title: Continuous close and flux analysis
description: Configure auto-accrual rules, run the nightly soft-close, review flux analysis, and export the board-pack narrative.
---

# Continuous close and flux analysis

Continuous close replaces the traditional 10-15 working-day month-end with a daily soft-close discipline: every night a job posts pro-rata accruals, runs FX revaluation, depreciation, and revenue cut-off, and freezes the result as a "soft-closed" trial balance by 07:00 local. Flux analysis runs immediately after, ranks the material variances against budget / prior-period / prior-year, and either auto-explains (driver decomposition) or routes the item to the GL-account owner with a 24-hour SLA.

This page walks the controller through the three primary operations: (1) defining accrual rules, (2) reviewing the nightly soft-close, (3) exporting the flux narrative for the board pack.

## Prerequisites

- The **bookkeeping-foundation** + **bookkeeping-general-ledger** + **bookkeeping-period-close** modules are installed.
- The **bookkeeping-soft-close-flux** seed has run (Settings → Shillinq → Re-seed register).
- At least one `Administration` record exists with an active `lifecycleState`.
- Optional: the treasury, IFRS 15, IFRS 16 modules are installed for delegated FX / revenue / lease postings.

## Define accrual rules (REQ-CLS-003)

1. Open **Bookkeeping → Accrual Rules** from the navigation.
2. Click **+ New** to define a rule. Provide:
   - **Rule name** — e.g. "Rent accrual"
   - **Target GL account** — where the expense lands (e.g. `4001-rent`)
   - **Contra GL account** — the accrued-expense account (e.g. `2100-accrued-rent`)
   - **Calculation method** — pick one of `fixed-amount`, `percentage-of-revenue`, `straight-line-from-contract`, `days-elapsed-of-period`, `external-lookup`
   - **Calculation parameters** — method-specific JSON (e.g. `{ "amountCents": 1200000 }` for a EUR 12,000 fixed accrual)
   - **Reversal pattern** — `first-of-next-month`, `on-receipt-of-invoice`, `on-settlement`, or `none`
   - **Frequency** — `daily`, `weekly`, or `monthly`
   - **Lifecycle state** — set to `active` to enable evaluation
3. Save. The rule will be picked up by the next nightly soft-close run.

The seed ships five worked examples (rent, utilities, salaries, interest, depreciation) covering every calculation method and reversal pattern; use them as starting points.

## Run a soft-close on demand (REQ-CLS-002)

The nightly cron `OCA\\Shillinq\\Cron\\SoftCloseJob` runs the soft-close for every active administratie at the platform-configured time (default daily, target completion by 07:00 local). For testing or ad-hoc closes:

```bash
curl -X POST \
  -H 'OCS-APIRequest: true' \
  -u $USER:$PASS \
  'https://your-nc/index.php/apps/shillinq/api/v2/soft-close/adm-smb-1/execute-now' \
  -d 'periodId=2026-03'
```

The response carries:
- `status` — `completed` or `failed`
- `postingCount` — total postings written across accruals, FX, revenue, lease
- `accrualPostings`, `fxPostings`, `revenuePostings`, `leasePostings`, `intercompanyMatches`
- `alerts` — list of `ContinuousCloseAlert` rows raised during the run

After a successful run the period transitions `open → soft-closed` in `PeriodStatus`; further regular postings are rejected by `PeriodStatusGuard::postingAllowed`, only accrual reversals and corrections pass through.

## Review flux analysis (REQ-CLS-005 / REQ-CLS-006)

Open **Bookkeeping → Flux Analysis** to list the runs. Each run carries:
- `scope` — administratie, segment, cost-centre, or consolidated
- `comparisonBasis` — `budget`, `forecast`, `prior-period`, or `prior-year`
- `materialityAbsoluteCents` + `materialityPercentage` — the threshold snapshot
- `resultSummary` — `{ materialCount, autoExplainedCount, escalatedCount, totalVarianceCents }`

Drill into a run to see `FluxItem` rows per GL account, ranked by absolute variance. Each row carries:
- `materialityClassification` — `immaterial`, `material`, or `highly-material`
- `autoExplanation` — rule-based driver decomposition string (e.g. `volume +EUR 80,000, price +EUR 60,000`)
- `autoExplanationCoverage` — fraction of the variance covered by the drivers
- `status` — `auto-explained`, `escalated`, or `owner-explained` once the owner has commented

Items with auto-coverage below 80% are routed to the GL account owner with a 24-hour SLA. SLA breaches raise a `ContinuousCloseAlert` and surface in the close-metrics dashboard.

## Export the board-pack narrative (REQ-CLS-007)

The narrative aggregates every material flux item, ranked by absolute variance, and is exportable to PDF, Markdown, or JSON:

```bash
# Markdown (for wiki / email)
curl -H 'OCS-APIRequest: true' -u $USER:$PASS \
  'https://your-nc/index.php/apps/shillinq/api/v2/flux-runs/flux-2026-03-1/narrative?format=markdown'

# PDF (board pack)
curl -H 'OCS-APIRequest: true' -u $USER:$PASS \
  'https://your-nc/index.php/apps/shillinq/api/v2/flux-runs/flux-2026-03-1/narrative?format=pdf' \
  > board-pack-march-2026.pdf

# JSON (embedding)
curl -H 'OCS-APIRequest: true' -u $USER:$PASS \
  'https://your-nc/index.php/apps/shillinq/api/v2/flux-runs/flux-2026-03-1/narrative?format=json'
```

The PDF carries a company-letterhead summary page plus a CFO signature line. The Markdown is ideal for inclusion in the monthly board-meeting wiki page.

## Close-quality KPIs (REQ-CLS-009)

Each soft-close run updates the `CloseMetrics` register for the administratie + period:
- `timeToCloseDays`
- `postCloseAdjustmentCount`
- `auditCorrectionRatio`
- `fluxSLACompliance`
- `unexplainedFluxItemCount`
- `trendData` — rolling 12-period history

These KPIs power the close-quality dashboard (separate change) and are the audit-committee evidence that the close process is effective.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Soft-close run reports 0 accrual postings | All rules `disabled` / `archived`, or `administrationId` does not match | Set `lifecycleState=active` on at least one rule; verify `administrationId` matches the administratie |
| FX postings = 0 | Treasury module not installed | Install **bookkeeping-treasury-ihb** + configure FX positions |
| Period stage stuck at `soft-closed` | Operator must transition hard-close manually | Open Period Status detail → use the `hard-close` transition |
| Flux escalations not routed | `MaterialityPolicy` missing for the administratie | Seed a `MaterialityPolicy` (Settings → Re-seed) or POST one manually |

## Reference

- REQ-CLS-001..010 — `openspec/specs/bookkeeping-continuous-close/spec.md`
- ADR-031 — orchestration exception authorising `SoftCloseExecutor`
- ADR-022 — audit-trail immutability on every register declared by this module
