# Schema authorization

## ADDED Requirements

### Requirement: Every schema declares an enforced authorization block
Each shillinq schema MUST declare an OpenRegister `authorization` block, and MUST NOT declare
`x-openregister-rbac` or a transition `x-rbac-role`.

#### Scenario: A user without the role reads nothing
- GIVEN a logged-in user who is in no `shillinq_*` role group
- WHEN they list `PaymentRun` objects through the OpenRegister object API
- THEN the list is empty

### Requirement: Money controls are enforced on the server
Payment runs, payment blocks, purchase orders, supplier invoices, three-way-match exceptions and report
schedules MUST enforce their state rules on the save path, whatever client writes them.

#### Scenario: An approved run cannot be edited
- GIVEN a payment run in state `approved`
- WHEN a user changes a payment line's IBAN or amount
- THEN the save is refused

#### Scenario: Releasing a payment block needs a role and a reason
- GIVEN a blocked AP transaction
- WHEN a bookkeeper without the controller or treasurer role, or without a release reason, clears the block
- THEN the save is refused and the block reason is kept

#### Scenario: A patched approval is refused
- GIVEN a purchase order in `draft`, a supplier invoice in `exception`, or a payment run in `exported`
- WHEN a user patches its state to `approved`, `approved` or `reconciled` through the object API
- THEN the transition is refused, because only the approval, exception and CAMT reconciliation services may make it
