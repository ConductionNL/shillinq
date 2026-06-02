# Tasks — Member 06: 3-way Matching Engine (code)

## ThreeWayMatchingEngine

- [ ] Implement `evaluateMatch(invoiceId)` — main entry point
- [ ] Implement `matchLineItems()` — find candidate (PO line, GRN line) tuples per invoice line
- [ ] Implement `calculateDivergence()` — compute price_delta, quantity_delta, vat_delta, date_delta
- [ ] Implement `evaluateTolerance()` — check divergence against the applicable ToleranceProfile
- [ ] Implement `routeToException()` — write ThreeWayMatch with exception status (resolution UI in member 08)
- [ ] Write the ThreeWayMatch record with match_status + divergence_details JSON

## ToleranceProfileService

- [ ] Implement `getApplicableProfile()` — most-specific (supplier > category > gl_account > global)
- [ ] Implement `evaluateWithinTolerance()` — price_delta vs absolute OR percentage (more permissive)
- [ ] Implement `evaluateQuantityVariance()` — qty vs percentage tolerance
- [ ] Implement `evaluateDateVariance()` — delivery date vs days tolerance

## Vue view

- [ ] Create `ThreeWayMatchIndex.vue` — filterable table by match_status, columns (invoice, supplier, amount, match date), quick-action buttons

## Tests

- [ ] Unit tests: line-level matching (product code, date proximity)
- [ ] Unit tests: tolerance evaluation (absolute vs %, "more permissive" selection)
- [ ] Unit tests: ToleranceProfile scope resolution (supplier overrides global)
- [ ] Integration test: auto-approve case (within tolerance)
- [ ] Integration test: exception-routing case (variance exceeds tolerance)
