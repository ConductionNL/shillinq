# Tasks — Member 07: Multi-PO Consolidation (code)

## Consolidation matching

- [x] Implement multi-PO candidate enumeration: match each invoice line to candidate (PO line, GRN line) tuples by product_code + date proximity (within 30 days)
- [x] Implement `disambiguateAmbiguousMatches()` — present candidate tuples to the crediteuren-administrateur when multiple (PO, GRN) candidates match one invoice line
- [x] Store the disambiguation choice in the ThreeWayMatch record
- [x] Create one ThreeWayMatch record per matched (PO line, GRN line, invoice line) trio and process each independently through the member-06 tolerance path

## Tests

- [x] Unit tests: multi-PO consolidation matching (candidate enumeration, date-proximity window)
- [x] Integration test: multi-PO consolidated invoice → per-trio ThreeWayMatch records with mixed outcomes
