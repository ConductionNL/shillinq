# Spec Delta: 3-way Matching Engine

## ADDED Requirements

### Requirement: Automated line-level 3-way matching within tolerance

The system SHALL evaluate a supplier invoice against its purchase
order(s) and goods receipt note(s) at line level on (product_code,
quantity, price, vat), computing price_delta, quantity_delta, vat_delta,
and date_delta per line. The system SHALL resolve the applicable
`ToleranceProfile` as the most-specific in scope order supplier >
category > gl_account > global, and SHALL treat a price divergence as
within tolerance when it satisfies EITHER the absolute amount OR the
percentage threshold, whichever is more permissive. When all line
divergences are within tolerance the system SHALL write a `ThreeWayMatch`
with match_status `auto_approved` and route the invoice to payment
without manual intervention; otherwise it SHALL set an `exception_*`
match_status and route to the exception workflow.

#### Scenario: Auto-approve a 0.25% price delta

- **GIVEN** an invoice for €18,547 against a PO of €18,500 with a GRN of 180 units accepted
- **WHEN** the matching engine evaluates line divergences against the global ToleranceProfile (price_tolerance_amount €10, price_tolerance_percentage 0.5%)
- **THEN** it calculates price_delta=+€47 (0.25% < 0.5%), quantity_delta=0, vat_delta=0, writes a ThreeWayMatch with match_status="auto_approved", logs divergence_details, and routes the invoice to payment approval

### Requirement: Configurable tolerance profiles per supplier, category, or GL account

The system SHALL allow a controller to create `ToleranceProfile` records
scoped to global, supplier, category, or gl_account, and the matching
engine SHALL apply the most-specific applicable profile when evaluating
divergence. Profile changes SHALL be audit-logged with before/after
snapshots.

#### Scenario: Supplier-scoped zero-tolerance overrides global

- **GIVEN** a controller creates a supplier-scoped ToleranceProfile with price_tolerance_amount=0 and quantity_tolerance_percentage=0
- **WHEN** subsequent invoices from that supplier are matched
- **THEN** the engine applies the supplier-scoped profile instead of the global one, treats any variance as an exception, routes to crediteuren-administrateur + controller, and logs the profile change with a before/after snapshot
