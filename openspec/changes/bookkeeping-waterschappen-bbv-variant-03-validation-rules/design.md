# Design — Member 03: validation rules

## Scope

This `kind: config` member declares schema-level validation on both
registers from member 01. No controller or UI code.

## Declarative-vs-imperative decision (ADR-031 / ADR-022)

| Validation | Decision | Why |
|---|---|---|
| Programme code regex + uniqueness | Schema constraint | Field-level, declarative |
| Fiscal-year bounds | Schema `minimum`/`maximum` | Declarative |
| Allocation 0–100 + precision | Schema constraint | Declarative |
| Effective-date ordering | Schema cross-field rule | Declarative where supported |
| Per-account allocation ≤ 100% | OpenRegister save-time rule | Cross-record sum; declared on the register, enforced by OR on save |

All rules live in `lib/Settings/shillinq_register.json`. The detail
page (member 07) surfaces these errors inline but does not own the
constraints — OpenRegister is the single enforcement point (ADR-022).

## Rules carried from the giant (REQ-BBVW-008)

- **programmeName**: required, non-empty, max 255.
- **programmeCode**: required, matches `^\d+\.\d+(\.\d+)?$`, unique per
  (administration, fiscalYear).
- **fiscalYear**: required integer 1900–2100.
- **status**: required enum `active | archived`.
- **glAccountNumber**: required, must exist in Chart of Accounts (FK).
- **allocationPercentage**: required, 0–100, precision 0.01.
- **effectiveFrom**: required ISO 8601 date.
- **effectiveTo**: optional ISO date, ≥ effectiveFrom when present.
- **Per-account sum**: total per GL account per fiscal year = 100%
  (±0.1% tolerance).

## Seed data

None — reuses member 01 fixtures; the integration test for over-
allocation rejection lives in member 11.

## Security (ADR-005)

Validation is fail-closed: an invalid write SHALL return HTTP 400 with
a descriptive message and SHALL NOT persist. No bypass path.
