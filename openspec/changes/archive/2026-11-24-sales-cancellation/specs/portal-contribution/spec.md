# portal-contribution Specification (delta)

## Purpose

Customers see and cancel their own subscriptions in the portal, and a consumer
reaches the withdrawal function there. From shillinq matrix rows
`sal-churn-reason` and `sal-withdrawal-button`.

## ADDED Requirements

### Requirement: A customer sees and cancels their own subscriptions in the portal (REQ-SCX-003)

For audience `customer`, the portal contribution SHALL declare a
`subscriptions` collection of the customer's own active and paused recurring
profiles, scoped on the `customerMasterId` claim, and a `cancel-subscription`
action forwarded to shillinq. The action SHALL ask for a reason from the fixed
list without requiring one, and shillinq SHALL end the profile per REQ-SCX-001
with `cancelledBy = customer`. A profile of another customer MUST be
unreachable.

#### Scenario: A member cancels and says why

- GIVEN the portal user of Fysio Linde B.V. with an active Maandabonnement Kracht Basis
- WHEN they open Subscriptions in the portal, choose Cancel subscription and pick Switched provider with a short text
- THEN the portal confirms the cancellation with its effective date
- AND staff see the profile ended by the customer with that reason

#### Scenario: A member cancels without a reason

- GIVEN the same portal user
- WHEN they cancel and skip the reason question
- THEN the subscription is cancelled with reason not given

### Requirement: The withdrawal function is offered where a consumer booked online (REQ-SCX-004)

For every appointment a consumer booked through the booking widget that is
still withdrawable, the portal contribution SHALL offer a `withdraw` action
labelled "Withdraw from contract here" (Dutch "Herroep de overeenkomst hier"),
and the booking confirmation mail and the widget's confirmation step SHALL
carry a link signed for that appointment that opens the same action for a
consumer without a portal account. The signed link MUST expire when the
withdrawal period ends and MUST NOT open any other appointment.

#### Scenario: A guest finds the withdrawal button from the confirmation mail

- GIVEN M. Visser booked Knippen en kleuren through the widget of Kapsalon Knip on 2026-09-20 for 2026-10-15
- WHEN she opens the withdrawal link in her confirmation mail on 2026-09-24
- THEN the page shows her appointment and the button Withdraw from contract here, followed by a confirmation step

#### Scenario: A tampered link opens nothing

- GIVEN a withdrawal link whose appointment id was changed
- WHEN it is opened
- THEN no appointment is shown and nothing changes
