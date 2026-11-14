# object-payment-requests Specification (delta)

---
status: proposed
---

## Purpose

A case type with a fee gets its leges request at intake: in the portal
journey as a checkout step, at the desk as a one-click request. Extends
`case-payment-requests`. Requested by the dossiq competitor analysis,
register row 1.11.

## ADDED Requirements

### Requirement: A fee schedule per type value (REQ-SOPR-006)

Shillinq SHALL declare a `feeSchedule` schema with `targetApp`,
`register`, `schema`, `typeProperty`, `typeValue`, `productRef`, `amount`,
`currency`, `revenueAccount`, `payAtIntake` (enum `required`, `optional`,
`later`), `validFrom` and `validTo`. When `productRef` is set the amount
SHALL be read from the pipelinq product (ADR-107). At most one schedule
SHALL be valid per tuple on a given day.

#### Scenario: Two overlapping schedules for one type are refused

- GIVEN a schedule for `dossiq/case` `caseType = bouwvergunning` valid all of 2026
- WHEN a second one for the same tuple valid from 2026-06-01 is saved
- THEN validation refuses it and names the overlapping schedule
- @e2e exclude validity-window invariant; covered by PHPUnit on the register import

### Requirement: The journey raises the request and renders the checkout (REQ-SOPR-007)

A journey step of kind `payment` with provider `shillinq` SHALL, after the
object is written, resolve the schedule for the object's type value and
date, append one `leges` request on the object through
`shillinq-payment-requests`, and render the checkout of
`portal-payment-initiation`. `required` SHALL block completion until the
request is `authorized`; `optional` and `later` SHALL complete with the
request `pending` and the link in the receipt. No schedule SHALL complete
the step with `noFee` and no request. A resumed run SHALL reuse its
request.

#### Scenario: A citizen pays leges at the end of the application

- GIVEN a schedule of 245.00 EUR with `payAtIntake = required` for `bouwvergunning` and a journey with a `payment` step after the write
- WHEN the citizen reaches the step
- THEN one `pending` leges request of 245.00 exists on the new case, the checkout renders, and the journey completes only after the provider reports `authorized`
- @e2e exclude the provider round trip is live-only; the step's request creation and gating are covered by PHPUnit with a stub provider, and the rendering by the journey e2e in portaliq once the step ships

#### Scenario: A type without a fee skips the step

- GIVEN no schedule for `melding`
- WHEN a `melding` journey reaches the payment step
- THEN the step completes with `noFee` and no request exists
- @e2e exclude covered by the same PHPUnit suite

### Requirement: The desk sees the fee and raises it in one click (REQ-SOPR-008)

`shillinq-payment-requests`'s `list` on a type object SHALL include the
valid schedule as `fee`. The panel on a created object of that type SHALL
offer "Raise leges request" with the amount, which appends the request
through `create` as the calling user.

#### Scenario: A clerk raises leges after a desk intake

- GIVEN a case of type `bouwvergunning` created at the desk and a valid schedule
- WHEN the clerk activates "Raise leges request" in the panel
- THEN one `pending` leges request of the schedule's amount exists on the case and the panel shows its link
- e2e: `tests/e2e/payment-request-panel.spec.ts`
