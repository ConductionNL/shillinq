<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# Journey: Auditor asserts the IFRS 15 revenue figures

**Persona:** External auditor (Big-4 or Dutch mid-market) validating recognition,
variable-consideration estimates, and disclosure completeness.
**Spec:** `bookkeeping-ifrs15-revenue` (REQ-IFRS15-003, REQ-IFRS15-007,
REQ-IFRS15-010)

## Goal

Trace revenue from each contract through to the GL, test the variable-
consideration constraint for reasonableness, and confirm the IFRS 15.110-129
disclosure pack is complete.

## Steps

1. Open **Bookkeeping → Revenue Recognition (IFRS 15) → Contracts** and inspect a
   contract's lifecycle (draft → signed → in-delivery → completed) and its
   performance obligations, SSPs, and allocated prices. The relative-SSP
   allocation ties back exactly to the transaction price (REQ-IFRS15-004).
2. For each `RevenueRecognitionEvent`, follow `glTransactionId` to the
   materialised GL transaction and confirm it is balanced (one debit accrued-
   revenue, one credit revenue, no duplicates) (REQ-IFRS15-007).
3. Open **Variable Consideration Adjustment** records and review each
   re-estimation: prior estimate, new constrained estimate, the documented
   constraint reason (IFRS 15.56), the delta, and the operator. The constraint
   must be defensible against historical actuals (REQ-IFRS15-003).
4. Review the **Revenue Waterfall** remaining-performance-obligation amounts and
   the **Contract Balances** asset/liability reconciliation; confirm they agree
   with the GL control accounts.
5. Walk the IFRS 15.110-129 disclosure structure: revenue disaggregation, contract
   balance reconciliation, remaining performance obligations, significant
   judgements (variable consideration, financing component, principal-vs-agent),
   and accounting policies (REQ-IFRS15-010).

## Outcome

The auditor confirms the five-step process is traceable end-to-end (contract → PO
→ price → allocation → recognition event → GL posting), the variable-consideration
estimates are constrained and documented, and the disclosure pack is complete and
Dutch-GAAP (BW2 Title 9) aligned.
