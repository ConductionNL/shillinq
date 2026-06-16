---
sidebar_position: 5
title: Exemptions
description: Configure short-term and low-value lease exemptions per asset class — straight-line expense posting and policy elections at portfolio level.
---

# Short-term and low-value exemptions

IFRS 16.5–6 allows lessees to elect not to capitalise:

- **Short-term leases** — non-cancellable term (including all options reasonably certain to be exercised) ≤ 12 months. Election is per asset class.
- **Low-value leases** — underlying asset value when new is ≤ ~USD 5,000 (e.g. office equipment, tablets, small fittings). Election is per lease.

Either election turns into a straight-line expense in P&L (Dr. Lease expense / Cr. Bank) over the lease term — no RoU asset, no lease liability, no schedule.

## The policy schema

`LeasePortfolioExemption` declares the customer's elections at portfolio level:

| Field | Purpose |
|---|---|
| `policyEffectiveDate` | When the policy applies from. |
| `shortTermByClass` | Per-asset-class boolean — which classes the short-term exemption is elected for. |
| `lowValueThresholdCents` | Tenant's low-value threshold in cents (default ≈ USD 5,000 equivalent). |
| `lowValueByClass` | Per-asset-class boolean. |
| `approver` / `policyDocument` | Approver person id + docudesk policy PDF FK. |
| `supersededBy` | Self-FK so a policy revision keeps the historical record. |

## How a lease is classified

When the operator picks one of the four `classification` enum values on a `LeaseContract`:

- `IFRS16-capitalised` → full RoU + liability + schedule.
- `short-term-exempt` → straight-line expense, no schedule, no fixed-asset record.
- `low-value-exempt` → same as short-term-exempt, but expense lands in the low-value account subtype.
- `operating-pre-IFRS16` → grandfathered for pre-transition records (audit-trail only).

The `LeaseContractGuard` enforces the enum membership at draft → active (REQ-LC-004); a misclassified or incomplete lease never activates.

## Disclosure impact

`LeaseDisclosureService::aggregateFromLeases` walks every exempt lease and adds its **base payment × schedule length** to:

- `totalShortTermLeaseExpense` (REQ-LE-003)
- `totalLowValueLeaseExpense`

These figures land in the disclosure table CSV alongside the IFRS 16-capitalised totals so the auditor sees the full expense picture in one row.

## Setup recipe

1. **Define the policy.** Create a `LeasePortfolioExemption` record with the elections, threshold, approver, and effective date. Attach the board / CFO sign-off PDF as `policyDocument` (docudesk FK).
2. **Apply to existing leases.** Review the lease register, classify each pre-existing lease per the policy. The classification engine validates the choice against the asset class and term.
3. **Maintain.** When the policy changes, create a new `LeasePortfolioExemption` and set its `supersededBy` on the prior one. Auditors can walk the policy history.

## Common patterns

| Asset class | Short-term elected? | Low-value elected? |
|---|---|---|
| Vehicle | No (typical term 36–60 mo) | No |
| Real estate | No (typical term 60–120 mo) | No |
| IT hardware (laptops) | Yes (12-month refresh cycle) | Yes |
| Office equipment | No | Yes |
| Machinery | No | Depends on per-unit value |
