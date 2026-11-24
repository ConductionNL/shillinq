# recurring-invoicing Specification (delta)

## Purpose

Every subscription that ends records why, and the reasons are visible. From
shillinq matrix row `sal-churn-reason`.

## ADDED Requirements

### Requirement: Ending a subscription records a reason (REQ-SCX-001)

The end transitions of `RecurringInvoiceProfile` SHALL record a cancellation
with a reason code from a fixed list, an optional text, who cancelled (staff or
customer), when it was requested and when it takes effect. A staff member MUST
choose a reason code other than `not-given`; a customer MAY leave the reason
out.

#### Scenario: Staff end a subscription

- GIVEN a bookkeeper on the recurring profile page of Personal training 10 lessen
- WHEN they choose End and pick the reason Too expensive
- THEN the profile shows state ended, reason Too expensive, cancelled by staff and the effective date

#### Scenario: Staff cannot end without a reason

- GIVEN the same bookkeeper
- WHEN they choose End without picking a reason
- THEN the end is refused with the message that a cancellation reason is required

### Requirement: The cancellation reasons are reported (REQ-SCX-002)

The Reports page SHALL offer a cancellation reasons report that shows, per
quarter of the effective date, the number of ended subscriptions and the lost
monthly recurring amount per reason code, for the current administration.

#### Scenario: The owner reviews why members left

- GIVEN ten subscriptions of Sportschool De Kracht ended in the third quarter of 2026, four for switched provider
- WHEN the owner opens Cancellation reasons from the Reports page
- THEN the third quarter shows 10 ended subscriptions with switched provider as the largest reason at 4 and its lost monthly amount
