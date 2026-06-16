# Tasks — Member 06: 3-way Matching Engine (code)

## ThreeWayMatchingEngine

- [x] Implement `evaluateMatch(invoiceId)` — main entry point
- [x] Implement `matchLineItems()` — find candidate (PO line, GRN line) tuples per invoice line
- [x] Implement `calculateDivergence()` — compute price_delta, quantity_delta, vat_delta, date_delta
- [x] Implement `evaluateTolerance()` — check divergence against the applicable ToleranceProfile
- [x] Implement `routeToException()` — write ThreeWayMatch with exception status (resolution UI in member 08)
- [x] Write the ThreeWayMatch record with match_status + divergence_details JSON

## ToleranceProfileService

- [x] Implement `getApplicableProfile()` — most-specific (supplier > category > gl_account > global)
- [x] Implement `evaluateWithinTolerance()` — price_delta vs absolute OR percentage (more permissive)
- [x] Implement `evaluateQuantityVariance()` — qty vs percentage tolerance
- [x] Implement `evaluateDateVariance()` — delivery date vs days tolerance

## Vue view

- [x] Create `ThreeWayMatchIndex.vue` — filterable table by match_status, columns (invoice, supplier, amount, match date), quick-action buttons

## Tests

- [x] Unit tests: line-level matching (product code, date proximity)
- [x] Unit tests: tolerance evaluation (absolute vs %, "more permissive" selection)
- [x] Unit tests: ToleranceProfile scope resolution (supplier overrides global)
- [x] Integration test: auto-approve case (within tolerance)
- [x] Integration test: exception-routing case (variance exceeds tolerance)
