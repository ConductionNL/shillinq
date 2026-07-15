# bookkeeping-verplichtingenadministratie — spec delta

## ADDED Requirements

### Requirement: REQ-VPL-013 — The Verplichting `goedkeuren` transition SHALL be gated by a declarative approval chain

The `Verplichting` schema SHALL declare an `x-openregister-approval-chains` entry
whose `transition` is `goedkeuren` (`in_goedkeuring` → `aangegaan`). The chain
SHALL route by `totaalbedrag_excl_btw` (`amountField`, EUR cents): a single
`commitment-administrator` approves commitments from `minAmount` 0, and a
`finance-director` approves commitments at or above EUR 250.000
(`minAmount: 25000000`). The chain SHALL set `separationOfDuties: true` (the
approver MUST NOT be the requester who submitted the commitment) and
`onApprove: advanceTransition` (completion releases the `goedkeuren` transition).

The declaration is consumed by OpenRegister's approval-chains capability
(`x-openregister-approval-chains`, `ApprovalChainAnnotationInstaller`,
`ApprovalChainGateListener`, `ApprovalChainAdvanceListener`; OpenRegister
REQ-006…010). shillinq SHALL NOT ship a parallel PHP approval-chain
implementation. The declaration is inert until the OpenRegister release carrying
that capability is deployed; the mandate-record routing that decides *whether* a
commitment is offered for approval (`MandaatEnforcer`, REQ-VPL-002) is unchanged
and remains a deliberate imperative exception.

#### Scenario: The declared chain names a real gated transition
- **GIVEN** the `Verplichting` schema's `x-openregister-approval-chains`
- **WHEN** the `verplichting-goedkeuring` entry is read
- **THEN** its `transition` MUST equal `goedkeuren`
- **AND** `goedkeuren` MUST exist in `x-openregister-lifecycle.transitions` with `from` `in_goedkeuring` and `to` `aangegaan`

#### Scenario: The chain routes by commitment amount to a single approver tier
- **GIVEN** the declared chain sets `amountField: totaalbedrag_excl_btw`
- **WHEN** its `approvers` are read
- **THEN** there MUST be a `minAmount: 0` tier requiring role `commitment-administrator`
- **AND** a higher tier (`minAmount: 25000000`) requiring role `finance-director`
- **AND** each tier MUST carry `role` and `min` (≥ 1)

#### Scenario: The chain enforces separation of duties and auto-advances
- **GIVEN** the declared chain
- **THEN** `separationOfDuties` MUST be `true`
- **AND** `onApprove` MUST be `advanceTransition`

#### Scenario: Mandate-record enforcement is retained (no dead control)
- **GIVEN** this change adds only the declarative chain
- **THEN** `MandaatEnforcer` MUST still exist
- **AND** the `indienen` transition MUST still reference `MandaatEnforcer::requiresApproval`
