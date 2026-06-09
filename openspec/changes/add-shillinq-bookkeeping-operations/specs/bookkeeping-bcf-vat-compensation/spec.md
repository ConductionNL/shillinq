# Specification: bookkeeping-bcf-vat-compensation

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-vat-btw-filing (T3), bookkeeping-bbv-compliance (T3)

## Overview

BTW Compensatiefonds (BCF) claim administration for Dutch municipalities. Municipalities recover compensable VAT from the fund via quarterly claim submissions per Wet HOF.

## Scope

- `BcfClaim` register for managing BCF claims
- Compensable VAT aggregation by BBV account mapping
- Claim lifecycle with approval gates
- Quarterly submission via DigiKoppeling source
- Claim settlement tracking

## ADDED Requirements

### Requirement: REQ-BCF-001 — BcfClaim register

The `BcfClaim` schema SHALL track BCF submissions with:
- `administrationId` (FK to Administration)
- `periodYear`, `periodQuarter`
- `state` (draft, submitted, accepted, settled)
- `totalClaimAmount` (MonetaryAmount)
- `compensableVatByAccount` (aggregation, see REQ-BCF-005)

#### Scenario: Spec conformance for REQ-BCF-001

- **GIVEN** the REQ-BCF-001 requirement above ("BcfClaim register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-BCF-002 — Required BCF fields

The `BcfClaim` schema MUST include:
- `claimNumber` (unique identifier)
- `claimDate` (when claim was prepared)
- `submittedDate` (when submitted via DigiKoppeling)
- `settlementDate` (when funds received)
- `attachmentUri` (docudesk reference to claim PDF)
- `notes` (optional)

#### Scenario: Spec conformance for REQ-BCF-002

- **GIVEN** the REQ-BCF-002 requirement above ("Required BCF fields")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-BCF-003 — Compensable VAT determination

A GL posting SHALL be treated as compensable if and only if the Account has a corresponding `BbvAccountMapping` entry with `bcfCompensable = true`; the determination MUST be deterministic from the mapping table at posting time.

#### Scenario: Spec conformance for REQ-BCF-003

- **GIVEN** the REQ-BCF-003 requirement above ("Compensable VAT determination")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-BCF-004 — BCF claim aggregation

The `BcfClaim.totalClaimAmount` SHALL aggregate all GL postings for compensable accounts in the claim period, multiplied by the `BbvAccountMapping.compensablePercentage` (e.g. 100% for most VAT accounts, 0% for non-recoverable).

#### Scenario: Aggregate compensable VAT for Q1 claim

GIVEN a gemeente administration with Q1 2026 GL data
WHEN a BcfClaim is created for Q1 2026
THEN the totalClaimAmount = SUM of (GLLine.amount × BbvAccountMapping.compensablePercentage) for all GL postings where BbvAccountMapping.bcfCompensable = true in Q1.

### Requirement: REQ-BCF-005 — Compensable percentage field

Each `BbvAccountMapping` SHALL carry a `compensablePercentage` field (0-100), allowing per-account granularity for partial-recovery scenarios.

#### Scenario: Spec conformance for REQ-BCF-005

- **GIVEN** the REQ-BCF-005 requirement above ("Compensable percentage field")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-BCF-006 — BCF lifecycle

The `BcfClaim.state` lifecycle SHALL declare three transitions:
- `draft → submitted` (requires approval)
- `submitted → accepted` (Fonds ACKs receipt)
- `accepted → settled` (payment received)

#### Scenario: Spec conformance for REQ-BCF-006

- **GIVEN** the REQ-BCF-006 requirement above ("BCF lifecycle")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-BCF-007 — Quarterly DigiKoppeling submission

The system SHALL declare an OR `ScheduledWorkflow` with cron `0 0 1 */3 *` that:
- Creates a new `BcfClaim` record for the quarter
- Invokes the aggregation to populate totalClaimAmount
- Submits to `digikoppeling-bcf` OpenConnector source
- Tracks settlement status

#### Scenario: Spec conformance for REQ-BCF-007

- **GIVEN** the REQ-BCF-007 requirement above ("Quarterly DigiKoppeling submission")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-BCF-008 — Manifest entry

The `src/manifest.json` SHALL declare:
- `Overheid > BCF-claims` (type: index, lists BcfClaim records)

Visibility: gemeente only.

#### Scenario: Spec conformance for REQ-BCF-008

- **GIVEN** the REQ-BCF-008 requirement above ("Manifest entry")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-BCF-009 — Approval gate

The `draft → submitted` transition MUST require approval via `x-openregister-lifecycle.requires.approval-workflow` if the claim amount exceeds an operator-configurable threshold.

#### Scenario: Spec conformance for REQ-BCF-009

- **GIVEN** the REQ-BCF-009 requirement above ("Approval gate")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

## Non-Goals

- No settlement payment processing (Fonds-side)
- No rejection/appeal workflow beyond re-draft

## Reuse

- Aggregation via OR `x-openregister-aggregations` (ADR-031)
- Lifecycle via OR `x-openregister-lifecycle` (ADR-031)
- Approval workflow via OR `approval-workflow` (ADR-022)
- Submission via OR `ScheduledWorkflow` + OpenConnector (ADR-019, ADR-022)

## Dependencies

- T3: BBV-compliance (for BbvAccountMapping), VAT-filing (for GL postings)
- OR: aggregations, lifecycle, ScheduledWorkflow
- OpenConnector: `digikoppeling-bcf` source
- docudesk: for claim document attachment
