<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# Journey: Revenue Accountant lays in a three-PO contract and re-estimates variable consideration

**Persona:** Revenue Accountant in a Dutch mid-market SaaS business, responsible
for IFRS 15 contract entry, SSP allocation, variable-consideration estimation
under the IFRS 15.56 constraint, and the monthly contract-balance review.
**Spec:** `bookkeeping-ifrs15-revenue` (REQ-IFRS15-001, REQ-IFRS15-002,
REQ-IFRS15-003, REQ-IFRS15-004, REQ-IFRS15-007)

## Goal

Enter a brand-new 36-month SaaS contract with three performance obligations
(subscription, implementation, usage-based add-on), confirm the relative-SSP
allocation, capture a variable-consideration estimate with its IFRS 15.56
constraint reason, and inspect the resulting contract-asset / contract-liability
balance at month-end.

## Steps

1. **Open Bookkeeping → Revenue Recognition (IFRS 15) → Contracts** and click
   *Add contract*. Fill in:
   - `contractNumber`: C-2026-001
   - `customerId`: ABC SaaS BV (picked from the Nextcloud contact list — no
     bespoke customer entity per ADR-022)
   - `signedAt`: 2026-01-01, `startDate`: 2026-01-01, `endDate`: 2028-12-31
   - `fixedConsideration`: EUR 360,000
   - `currency`: EUR
   - `quoteOrderReference`: the originating sales order from the quote-to-cash
     module (REQ-IFRS15-001 contract origination).
   - `lifecycleState`: `draft`.

2. **Switch to Performance Obligations** and add three rows under the contract:
   - **PO-1 SaaS Subscription** — satisfactionPattern `over-time`, outputMethod
     `time-elapsed`, sspAmount EUR 300,000.
   - **PO-2 Implementation Service** — satisfactionPattern `point-in-time`,
     sspAmount EUR 40,000, planned completion 2026-02-28.
   - **PO-3 Usage-based Add-on** — satisfactionPattern `over-time`, outputMethod
     `units-delivered`, sspAmount EUR 80,000.

3. **Allocate the transaction price**. The Price Allocation view fills in the
   relative-SSP allocation automatically per REQ-IFRS15-004
   (PO-1 EUR 257,143 / PO-2 EUR 34,286 / PO-3 EUR 68,571; total 360,000). Confirm
   each row matches the spec example.

4. **Record variable consideration** for PO-3. Open *Add Variable Consideration*
   from the contract detail and enter:
   - `priorEstimate`: 0
   - `newEstimate`: 30000 (expected value of historical customer usage)
   - `constraintAmount`: 30000
   - `constraintReason`: "Probability of reversal >20% based on market
     volatility; constrained to 30K per IFRS 15.56".
   The captured `VariableConsiderationAdjustment` row links to the operator's
   Nextcloud user (REQ-IFRS15-003) so the constraint is auditable.

5. **Sign and move to delivery.** Use the *Sign* lifecycle action on the
   contract; the state moves `draft → signed`. The first
   `RevenueRecognitionEvent` is posted by the materialisation on
   `RevenueRecognitionEvent` when PO-2 completes on 2026-02-28.

6. **Month-end balance review.** At 30 June 2026 the revenue accountant opens
   **Contract Balances**, filters on C-2026-001, and reads the
   contract-liability of EUR 857 (billed EUR 90,000, recognised EUR 89,143). The
   number ties back to the `GET /api/revenue/cutoff?period_end=2026-06-30`
   output and to the spec example.

## Outcome

The revenue accountant has a single, audit-ready record of:

- the contract and its three POs;
- the relative-SSP allocation tying back to the EUR 360,000 transaction price;
- a documented variable-consideration estimate with constraint reason and
  operator;
- the period-end contract liability ready for the auditor's review
  (`auditor-revenue-assertion.md`) and the controller's IFRS 15 close-out
  (`controller-ifrs15-closeout.md`).

All four IFRS 15 personas (CFO, Revenue Accountant, Controller, Auditor) now
have a corresponding journey document under `docs/journeys/`.
