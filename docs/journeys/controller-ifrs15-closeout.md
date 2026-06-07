<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# Journey: Controller runs the IFRS 15 revenue cut-off at period close

**Persona:** Group Controller responsible for the rev-rec close and audit
defence.
**Spec:** `bookkeeping-ifrs15-revenue` (REQ-IFRS15-005, REQ-IFRS15-006,
REQ-IFRS15-007)

## Goal

Recalculate contract asset / contract liability balances for the period, confirm
the GL postings balance, and clear the modification queue before locking the
period.

## Steps

1. Open **Bookkeeping → Revenue Recognition (IFRS 15) → Contract Balances** to
   review the current deferred-revenue (contract liability) and accrued-revenue
   (contract asset) positions per contract.
2. Trigger the revenue cut-off for the period via `GET /api/revenue-cutoff?
   administration_id=<adm>&period_end=YYYY-MM-DD`. For each contract the service
   sums cumulative recognised revenue from `RevenueRecognitionEvent`, compares it
   to the cumulative billed amount, and derives:
   - **contract asset** = max(0, recognised − billed) (right to consideration), or
   - **contract liability** = max(0, billed − recognised) (deferred revenue),
   per IFRS 15.116-119 (REQ-IFRS15-007).
3. The cut-off is **idempotent** — re-running it on the same event + billing
   snapshot yields identical rows, so the GL is never double-posted. The GL
   posting itself reverses the prior-period lines and posts fresh ones (debit
   accrued-revenue / credit revenue) via the materialisation on
   `RevenueRecognitionEvent`.
4. Open **Contract Modifications** and clear any pending amendments. The system
   proposes a classification (new-contract | not-distinct-cumulative |
   prospective) per IFRS 15.18-21; the controller confirms or overrides it with a
   documented reason (REQ-IFRS15-006).
5. For cost-to-cost construction contracts, confirm the revised percentage of
   completion (actual cost / revised estimate) and watch the margin indicator for
   onerous-contract signals (REQ-IFRS15-005).

## Outcome

The period closes with contract asset/liability balances reconciled to the GL,
all modifications classified and traceable, and an idempotent cut-off the auditor
can re-run without side effects.
