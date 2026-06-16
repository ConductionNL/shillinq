---
sidebar_position: 7
title: FAQ
description: Frequently asked questions on IFRS 16 in Shillinq — identified asset, IBR derivation, reasonably certain, modifications, and transition.
---

# IFRS 16 FAQ

## What is an "identified asset"? When does IFRS 16 apply?

IFRS 16.B9 — an asset is **identified** when it is explicitly specified in the contract (e.g. a named car, an addressed floor) or implicitly specified (only one supplier-asset can fulfil the contract). If the supplier has a **substantive substitution right** — they can swap the asset without your consent and economically benefit from doing so — then there is no identified asset and IFRS 16 does **not** apply.

In practice: building leases, vehicle leases, and dedicated IT hardware are in scope; pooled cloud capacity (where the supplier can move you between racks) typically is not.

## How is the IBR derived? Which method should I use?

The **incremental borrowing rate** (IBR) is the rate you would pay to borrow over a similar term, on a similar collateral, in a similar currency. Shillinq supports four derivation methods:

1. **Group policy matrix** — a treasury-published rate table by term × currency × collateral. Best for groups with central treasury.
2. **Yield curve + spread** — a benchmark sovereign / swap curve plus the company's credit spread. Best for listed groups with observable credit spreads.
3. **Weighted-average external debt** — actual borrowing-cost average from existing debt. Best for SMEs with limited treasury sophistication.
4. **External quote** — bank quotation. Best for unusual term / currency combinations.

**Pick the method your group treasury or auditor prescribes.** A 0.5 % error on a EUR 1M liability is EUR 5K of impact, and the IBR is the single most material judgement in the standard. Attach the derivation evidence (rate sheet, curve screenshot, bank email) to `ibrEvidenceDocuments` — the audit pack pulls it.

## What is "reasonably certain" exercise of an extension option?

IFRS 16.B37 — consider every factor available at commencement (or reassessment) date: economic incentives (penalty for non-exercise), historical exercise patterns, business reasons, leasehold improvements with useful life beyond the non-cancellable term.

The judgement is **not** "more likely than not"; it is a **higher bar**. A 2-year extension on a 5-year car lease is usually **not** reasonably certain (the fleet is replaced every 5 years). A 5-year extension on a 10-year building lease, where you have already installed EUR 200K of leasehold improvements with a 12-year useful life, **is** reasonably certain.

Shillinq's reassessment workflow surfaces every extension option that is currently `possible` (i.e. not `reasonably-certain` or `unlikely`) and prompts a review at each period close.

## How do I account for modifications (scope, payment, term changes)?

Apply the IFRS 16.44 decision tree:

1. **Is it a separate lease?** If the modification adds an underlying asset AND the additional payment reflects a stand-alone price → yes, account for a new lease (`recordModification(...approach: 'separate-lease')`).
2. **Otherwise → remeasure.** Compute the new liability using the IBR at the modification date and adjust the RoU asset by the same amount.
3. **Special cases:**
   - **Decrease in scope or term** (e.g. early termination of part of a building lease) → recognise gain or loss for the difference between the liability decrease and the RoU decrease pro-rata (`recordModification` partial-termination, or `recordImpairment` if the asset is abandoned).
   - **Payment-only change** with no scope change → no IBR reset, prospective adjustment is usually appropriate (`recordModification(...approach: 'prospective')`).

## What is the EUR 100,000 decidesk threshold?

Per REQ-LR-007, any reassessment event with an RoU adjustment magnitude above EUR 100,000 is created with `status='pending-approval'` and a webhook fires to decidesk for board approval. Below the threshold, the event auto-approves (or routes to the lease administrator only, per tenant configuration). The threshold is hard-coded today (`DECIDESK_THRESHOLD_CENTS = 10_000_000`); tenant-configurable thresholds land with the decidesk integration in Phase 2.

## What's the difference between modified-retrospective and full-retrospective transition?

- **Modified-retrospective** (IFRS 16.C5(b)) — at the transition date, recognise the lease liability = PV of remaining payments at the transition-date IBR; recognise the RoU asset = liability (adjusted for prepaid / accrued rent). No comparative restatement, no opening retained-earnings adjustment. **Most customers pick this.**
- **Full-retrospective** (IFRS 16.C5(a)) — restate every comparative period as if IFRS 16 had always applied. Opening retained earnings carries the cumulative catch-up. **Listed groups and groups with peers on full-retrospective often pick this.**

`LeaseTransitionWizard::compute` supports both, with the IFRS 16.C3 / C10 practical-expedient elections (single-discount-rate-by-class, exempt-at-transition, hindsight-on-extension-options, exclude-initial-direct-costs, use-onerous-contracts-provision) honoured in the recognition payload and disclosed in the C12 transition note.

## Why are my draft leases not in the disclosure?

By design (REQ-LD-001). Only `active` and `modified` leases contribute to the disclosure table. Drafts are excluded so unfinished records do not pollute the period-end snapshot. Promote them to `active` (subject to the `LeaseContractGuard` precondition) to include.

## Why is the closing liability of the final period exactly zero?

`LeaseAmortizationCalculator::buildSchedule` forces the final period's closing liability to zero — any cents-level rounding from the periodic-rate calculation is absorbed into that row's principal portion. Without this, you would see a EUR 0.03 residual after 60 periods; the auditor would flag it; the override keeps the schedule clean.

## Why are exempt leases written with no schedule rows?

Per REQ-LE-003 — exempt leases (`short-term-exempt`, `low-value-exempt`) post a straight-line expense each period directly to the GL; they have no RoU asset, no lease liability, and therefore no amortisation schedule to materialise. The disclosure table aggregates the expense for these leases under `totalShortTermLeaseExpense` / `totalLowValueLeaseExpense`.
