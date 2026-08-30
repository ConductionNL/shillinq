# revive-lease-capabilities Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- revive-lease-capabilities (this change)

## Purpose

Supplies the missing, executing triggers for shillinq's IFRS-16 lease
cluster of orphaned write capabilities (hydra gate-52; shillinq#446). Five
fully-implemented, fully-tested balance-sheet methods —
`LeasePaymentScheduleService::generateSchedule` and the four
`LeaseReassessmentService::record*` remeasurement methods — had zero
production callers, so the right-of-use (RoU) asset and the lease liability
were silently frozen at their opening values while the app and its test
suite stayed green. No lease arithmetic is added or changed; this capability
is the missing wiring, plus the trigger analysis (design D1) that rules out
the declarative/`ObjectTransitionedEvent` paths that could never fire.

## ADDED Requirements

### Requirement: REQ-LEASE-REVIVE-001: Activating a capitalised lease MUST persist its amortization schedule

When a `LeaseContract` classified `IFRS16-capitalised` transitions into the
`active` state (a `draft → active` update, or a lease created already
`active`), the system MUST persist one `LeasePaymentSchedule` row per period
of the amortization schedule, scoped to the lease's own administration
(ADR-005 IDOR). An exempt or non-capitalised lease MUST persist nothing. A
save that does not move the lease into `active`, or that targets a non-lease
schema, MUST persist nothing. A failure to persist MUST be logged and MUST
NOT roll back the activation itself (fail-soft).

#### Scenario: Activating a 36-month capitalised vehicle lease writes 36 amortizing rows

- **GIVEN** a `draft` `LeaseContract` (`IFRS16-capitalised`, 36 monthly
  payments of 1000.00 in-arrears, IBR 4%, administration `adm-1`)
- **WHEN** it is saved moving `status` from `draft` to `active`
- **THEN** exactly 36 `LeasePaymentSchedule` rows MUST be persisted for
  `adm-1`, each carrying `postedToGl = null`
- **AND** every row's `interest + principal` MUST equal the period payment
  (to the cent)
- **AND** the closing lease liability of the final period MUST be ≈ 0.00.

#### Scenario: A non-edge or out-of-scope save writes nothing

- **GIVEN** the same lease already in `active` (a subsequent save that does
  not change `status`), OR an update to a schema other than `LeaseContract`,
  OR a `short-term-exempt` lease activating
- **WHEN** the object is saved
- **THEN** no `LeasePaymentSchedule` row MUST be written.

### Requirement: REQ-LEASE-REVIVE-002: A lease reassessment endpoint MUST record a balanced remeasurement event

The system MUST expose an authenticated write surface that records each of
the four IFRS-16 remeasurement events — indexation, extension-option
reassessment, scope/term/payment modification, and impairment. Each endpoint
MUST reject an anonymous caller (401), MUST reject an `administrationId` the
caller cannot access (404, ADR-005 IDOR), MUST persist a
`LeaseReassessmentEvent`, and MUST return a payload whose general-ledger
lines balance (sum of debit amounts equals sum of credit amounts). For a
catch-up remeasurement the RoU-asset adjustment MUST equal the lease
liability delta; for an impairment the RoU write-down MUST equal the P&L
impairment loss.

#### Scenario: An indexation uplift posts a balanced catch-up remeasurement

- **GIVEN** an authenticated caller with access to administration `adm-1`
  and an `IFRS16-capitalised` lease whose payment rises from 1000.00 to
  1100.00
- **WHEN** the caller POSTs the indexation event
- **THEN** a `LeaseReassessmentEvent` MUST be persisted with
  `eventType = indexation-remeasurement`
- **AND** the post-event lease liability MUST differ from the pre-event
  liability
- **AND** the returned `glLines` MUST balance (sum debit = sum credit) with
  the RoU-asset debit equal to the lease-liability credit.

#### Scenario: An impairment write-down posts a balanced loss

- **GIVEN** the same lease impaired to a recoverable value below its
  carrying RoU amount
- **WHEN** the caller POSTs the impairment event
- **THEN** the returned `glLines` MUST debit `lease-modification-gain-loss`
  and credit `rou-asset` by the same magnitude (a balanced entry).

#### Scenario: A cross-tenant administration is masked

- **GIVEN** an authenticated caller without access to administration `adm-X`
- **WHEN** the caller POSTs any reassessment event scoped to `adm-X`
- **THEN** the response MUST be 404 and no `LeaseReassessmentEvent` MUST be
  persisted.
