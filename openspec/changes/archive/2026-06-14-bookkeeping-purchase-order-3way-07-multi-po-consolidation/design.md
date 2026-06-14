# Design — Member 07: Multi-PO Consolidation (code)

## Context

`kind: code` member extending the matching engine (member 06) to handle
consolidated invoices spanning many POs/GRNs.

## Decisions

### D9 — Multi-PO consolidated invoice via line-level matching

Carried from the giant's D9. One supplier invoice can invoice lines from
up to ~10 different POs. The engine matches each invoice line to candidate
(PO line, GRN line) tuples by product_code + date proximity (within 30
days). When multiple candidates match a single invoice line,
`disambiguateAmbiguousMatches()` presents the choice to the
crediteuren-administrateur and stores the chosen tuple in the
`ThreeWayMatch` record. Each matched trio produces its own `ThreeWayMatch`,
processed independently through the member-06 tolerance path.

## Security (ADR-005)

- Candidate enumeration and the persisted disambiguation choice are
  server-authoritative; the UI surfaces options but the server validates
  the chosen tuple against real PO/GRN records.

## Reuse
- `ThreeWayMatchingEngine` + `ToleranceProfileService` (member 06)
- `ThreeWayMatch` register (member 01) for per-trio records and the
  stored disambiguation choice
