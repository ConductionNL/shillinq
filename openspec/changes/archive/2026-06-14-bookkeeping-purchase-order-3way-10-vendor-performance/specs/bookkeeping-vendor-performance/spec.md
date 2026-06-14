# Spec Delta: Vendor Performance Scoring

## ADDED Requirements

### Requirement: Monthly vendor scoring with auto-review eligibility

The system SHALL run a monthly aggregation per supplier computing
on_time_delivery_rate, quantity_accuracy_rate, price_accuracy_rate, and
invoice_accuracy_rate from the goods-receipt and invoice-match history,
and SHALL compute overall_score as the weighted average 40% on-time + 30%
quantity + 20% price + 10% invoice. When overall_score is 96 or above
(after a 90-day bootstrap period) the system SHALL set
automated_review_eligible to TRUE and MAY relax that supplier's
ToleranceProfile (or present the relaxation to the controller),
notifying the controller of the elevated status. The system SHALL record
score_trend (improving, stable, declining) relative to the prior period.

#### Scenario: 98.5 score unlocks auto-review

- **GIVEN** a supplier with 96%+ on-time delivery, 99% quantity accuracy, 97% price accuracy, and 100% invoice-accuracy-on-first-try
- **WHEN** the monthly vendor-scoring aggregation runs
- **THEN** it computes overall_score=98.5, sets automated_review_eligible=TRUE, optionally relaxes the supplier's ToleranceProfile, and notifies the controller; subsequent invoices auto-approve unless an exception is fraud_alert or other critical condition

#### Scenario: Below-threshold supplier stays ineligible

- **GIVEN** a supplier with overall_score=86.0
- **WHEN** the monthly aggregation runs
- **THEN** automated_review_eligible remains FALSE and the supplier's tolerance profile is unchanged
