# bookings-cancellation-rules Specification (delta)

## Purpose

A consumer who booked online can withdraw while the law allows it, without a
fee, with every payment refunded and a durable acknowledgement. From shillinq
matrix row `sal-withdrawal-button`.

## ADDED Requirements

### Requirement: Shillinq decides whether a booking can still be withdrawn (REQ-SCX-005)

An appointment SHALL be withdrawable when a consumer booked it through the
booking widget, fewer than 14 days have passed since it was booked, it has not
started, and its service is not marked exempt from withdrawal with a named
reason. When it is not withdrawable, the answer MUST carry the reason, and the
portal SHALL show that reason and the ordinary cancellation instead.

#### Scenario: A workshop on a fixed date is exempt

- GIVEN a consumer booked Workshop bloemschikken 12 oktober, marked exempt as a leisure service on a specific date
- WHEN the consumer opens the withdrawal link
- THEN the page says this booking cannot be withdrawn and why, and offers the ordinary cancellation under the cancellation policy

#### Scenario: The withdrawal period has passed

- GIVEN an appointment booked through the widget 15 days ago that has not started
- WHEN the consumer opens it in the portal
- THEN the withdraw action is absent and the page says the withdrawal period ended on the date it ended

### Requirement: A withdrawal cancels without a fee, refunds in full and is acknowledged (REQ-SCX-006)

On a confirmed withdrawal shillinq SHALL record a `Withdrawal`, cancel the
appointment with reason withdrawal and no cancellation fee regardless of the
cancellation policy, credit any invoice and refund every payment made for it in
full, and mail an acknowledgement with the date and time of the withdrawal to
the consumer. A refund the payment adapter cannot pay automatically SHALL be
recorded as due with its deadline, 14 days after the withdrawal, and listed for
staff.

#### Scenario: A consumer withdraws a booking with a deposit

- GIVEN M. Visser's appointment on 2026-10-15 with a deposit of EUR 25 paid and a cancellation policy that charges 50 percent within 30 days
- WHEN she confirms the withdrawal on 2026-09-24
- THEN the appointment shows cancelled with reason withdrawal and a fee of EUR 0
- AND a refund of EUR 25 is initiated, or listed for staff as due by 2026-10-08
- AND she receives an email acknowledging the withdrawal of 2026-09-24 with its time
