# bookkeeping-three-way-matching-engine Specification

## Purpose

Line-level three-way matching of a supplier invoice against its purchase orders and goods receipts, within tolerance profiles scoped to supplier, category, GL account or global. Synced from the archived change `2026-06-14-bookkeeping-purchase-order-3way-06-matching-engine`; the umbrella spec `bookkeeping-purchase-order-3way` (REQ-PO3W-004, REQ-PO3W-006) states the same behaviour at a higher level.

## Requirements

### Requirement: Automated line-level 3-way matching within tolerance (REQ-TWM-001)

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

### Requirement: Configurable tolerance profiles per supplier, category, or GL account (REQ-TWM-002)

The system SHALL allow a controller to create `ToleranceProfile` records
scoped to global, supplier, category, or gl_account, and the matching
engine SHALL apply the most-specific applicable profile when evaluating
divergence. Profile changes SHALL be audit-logged with before/after
snapshots.

#### Scenario: Supplier-scoped zero-tolerance overrides global

- **GIVEN** a controller creates a supplier-scoped ToleranceProfile with price_tolerance_amount=0 and quantity_tolerance_percentage=0
- **WHEN** subsequent invoices from that supplier are matched
- **THEN** the engine applies the supplier-scoped profile instead of the global one, treats any variance as an exception, routes to crediteuren-administrateur + controller, and logs the profile change with a before/after snapshot

### Requirement: A delivery outside the date tolerance is an exception (REQ-TWM-003)

The ThreeWayMatch match_status enum MUST include `exception_date`.

For every invoice line matched to a goods receipt line, the engine MUST compare two dates. The expected date is the order line's `expected_delivery_date`, or the order's `expected_delivery_date` when the line has none. The actual date is the `received_at` of the goods receipt that holds the matched receipt line. The `date_delta` is the actual date minus the expected date, in whole days.

The engine MUST treat the line as out of tolerance when the absolute `date_delta` is larger than the profile's `date_tolerance_days`. Early and late deliveries count the same. When the applicable profile has no `date_tolerance_days`, or no profile applies, the tolerance MUST be zero days.

The engine MUST NOT check the date when there is no expected date, when the matched receipt has no `received_at`, or when the line matched a service receipt. Such a line keeps the status its other axes give it. A `date_delta` within the tolerance MUST NOT change the status either.

When the lines give more than one outcome, the match MUST take the first status in this order: `exception_missing_po`, `exception_missing_grn`, `exception_price`, `exception_quantity`, `exception_date`, `within_tolerance`. With none of these the match is `auto_approved`. An `exception_date` match MUST route to the exception workflow like the other `exception_*` statuses, and the divergence details MUST record the expected date, the actual date and the `date_delta`.

**Feature tier**: MVP
**Files**: `shillinq/lib/Service/ThreeWayMatchingEngine.php`, `shillinq/lib/Service/ToleranceProfileService.php`

#### Scenario: A late delivery beyond the tolerance is an exception (REQ-TWM-003-01)

- GIVEN an order line with expected_delivery_date 2026-06-01
- AND a tolerance profile with date_tolerance_days 3
- AND the matched goods receipt has received_at 2026-06-08
- AND price and quantity match exactly
- WHEN the matching engine evaluates the invoice
- THEN the match_status MUST be "exception_date"
- AND the divergence details MUST record expected 2026-06-01, actual 2026-06-08 and a date_delta of 7
- AND the match MUST route to the exception workflow
- @e2e exclude backend matching engine, covered by PHPUnit

#### Scenario: An early delivery beyond the tolerance is an exception (REQ-TWM-003-02)

- GIVEN an order line with expected_delivery_date 2026-06-10
- AND a tolerance profile with date_tolerance_days 3
- AND the matched goods receipt has received_at 2026-06-02
- WHEN the matching engine evaluates the invoice
- THEN the match_status MUST be "exception_date" with a date_delta of -8
- @e2e exclude backend matching engine, covered by PHPUnit

#### Scenario: A delivery within the tolerance keeps its status (REQ-TWM-003-03)

- GIVEN an order line with expected_delivery_date 2026-06-01
- AND a tolerance profile with date_tolerance_days 3
- AND the matched goods receipt has received_at 2026-06-04
- AND price and quantity match exactly
- WHEN the matching engine evaluates the invoice
- THEN the match_status MUST be "auto_approved"
- @e2e exclude backend matching engine, covered by PHPUnit

#### Scenario: A line without an expected date is not date checked (REQ-TWM-003-04)

- GIVEN an order and order line without expected_delivery_date
- AND the matched goods receipt has received_at 2026-06-30
- WHEN the matching engine evaluates the invoice
- THEN the match_status MUST be the one price and quantity give
- AND the divergence details MUST hold no deliveryDate entry
- @e2e exclude backend matching engine, covered by PHPUnit

#### Scenario: The order's date applies when the line has none (REQ-TWM-003-05)

- GIVEN an order with expected_delivery_date 2026-06-01 and an order line without one
- AND a tolerance profile with date_tolerance_days 3
- AND the matched goods receipt has received_at 2026-06-08
- WHEN the matching engine evaluates the invoice
- THEN the match_status MUST be "exception_date"
- @e2e exclude backend matching engine, covered by PHPUnit

#### Scenario: A price breach outranks a date breach (REQ-TWM-003-06)

- GIVEN one invoice line whose price is beyond the price tolerance
- AND another invoice line delivered 7 days late against a date_tolerance_days of 3
- WHEN the matching engine evaluates the invoice
- THEN the match_status MUST be "exception_price"
- AND the divergence details MUST still record the date breach
- @e2e exclude backend matching engine, covered by PHPUnit
