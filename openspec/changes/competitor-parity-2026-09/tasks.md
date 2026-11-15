# Tasks: competitor-parity-2026-09

- [ ] 1.1 Build `case-payment-requests` (ledger row 12.12). Archive it on merge and tick it here.
- [ ] 1.2 Build `leges-at-intake` (ledger row 1.11, depends on 1.1). Archive it on merge and tick it here.
- [ ] 1.3 Build `fees-payments-and-the-contract-register` (discovery cluster 55, depends on 1.1 and 1.2). Archive it on merge and tick it here.
- [ ] 2.1 Hand dossiq its halves: the fee declared per case type and per intake channel, the contract a case runs under, and the payment state read from shillinq.
- [ ] 2.2 Ask the integriq lane which provider capabilities `live-payment-providers` exposes for a batch submission, and agree the per line result shape.
- [ ] 2.3 Ask the portaliq lane to confirm the checkout step still fits when the fee carries an intake channel.
- [ ] 3.1 Keep the already-covered table current: C-deadlines-10 on `compliance-deadline-calendar`, C-intake-38 on `payment-run-sepa-export`.
- [ ] 3.2 Add any later shillinq change that cites the gap register or the discovery sweep to the index above, in the same PR that opens it.
