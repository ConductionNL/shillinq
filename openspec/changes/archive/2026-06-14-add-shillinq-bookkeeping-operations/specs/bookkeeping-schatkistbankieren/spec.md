# Specification: bookkeeping-schatkistbankieren

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-general-ledger (T1)

## Overview

Schatkistbankieren (Treasury banking) for Dutch municipalities. Wet HOF mandates municipalities above a threshold (drempelbedrag) bank with the State Treasury, with daily liquidity position reporting per Wet HOF art. 2 + ministerial regeling.

## Scope

- `SchatkistPosition` register for daily aggregated position
- `Account.isSchatkistAccount` boolean flag on T1 accounts
- Daily position aggregation (not a parallel ledger)
- Drempelbedrag seed tracking (0.75% for small, 0.5% for large municipalities)
- Daily liquidity reporting dashboard
- Threshold-crossing notifications

## ADDED Requirements

### Requirement: REQ-SBK-001 — Schatkistbankieren compliance

Municipalities SHALL manage Treasury banking through a daily position view, not a parallel ledger. All schatkist deposits/withdrawals post to the GL as regular bank transactions.

#### Scenario: Spec conformance for REQ-SBK-001

- **GIVEN** the REQ-SBK-001 requirement above ("Schatkistbankieren compliance")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SBK-002 — Account schatkist flagging

Each `Account` (T1) MAY carry an `isSchatkistAccount: boolean` flag (default false); when set, the engine MUST treat the account as a Treasury deposit/working-capital account and SHALL include it in the daily SchatkistPosition aggregation.

#### Scenario: Flag Treasury deposit account

GIVEN a gemeente administration
WHEN Account "1100 Treasury Deposit Account" is created or modified
THEN it is flagged `isSchatkistAccount: true` by the administrator.

### Requirement: REQ-SBK-003 — SchatkistPosition register

The `SchatkistPosition` schema SHALL track daily position with:
- `administrationId` (FK to Administration)
- `businessDate` (business day date)
- `position` (MonetaryAmount, aggregated from flagged accounts)
- `drempelbedragApplies` (boolean, based on municipality size/threshold)
- `drempelbedragAmount` (from seed, e.g. 0.75% of begroting)
- `aboveThreshold` (boolean, position > drempelbedrag)

#### Scenario: Spec conformance for REQ-SBK-003

- **GIVEN** the REQ-SBK-003 requirement above ("SchatkistPosition register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SBK-004 — Daily position aggregation

The `SchatkistPosition.position` field SHALL be declared as `x-openregister-aggregations`, summing GL postings on accounts where `isSchatkistAccount = true` for the business day, computed end-of-day.

#### Scenario: Spec conformance for REQ-SBK-004

- **GIVEN** the REQ-SBK-004 requirement above ("Daily position aggregation")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SBK-005 — Drempelbedrag seed

The system SHALL ship `schatkist-thresholds.json` with municipality-size-based thresholds:
- `small_municipality: 0.75` (% of begroting)
- `large_municipality: 0.5` (% of begroting)
- `legislative_reference: "Wet HOF art. 2 + ministerial regeling"`

#### Scenario: Spec conformance for REQ-SBK-005

- **GIVEN** the REQ-SBK-005 requirement above ("Drempelbedrag seed")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SBK-006 — Threshold configuration

Each gemeente administration MUST be configured with its municipality size (small/medium/large) to determine drempelbedrag applicability and calculation.

#### Scenario: Spec conformance for REQ-SBK-006

- **GIVEN** the REQ-SBK-006 requirement above ("Threshold configuration")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SBK-007 — Daily position workflow

The system SHALL declare an OR `ScheduledWorkflow` triggering once per business day that:
- Computes the daily aggregated position from flagged accounts
- Creates a new `SchatkistPosition` record
- Compares against drempelbedrag
- Fires a notification if above threshold

#### Scenario: Spec conformance for REQ-SBK-007

- **GIVEN** the REQ-SBK-007 requirement above ("Daily position workflow")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SBK-008 — Threshold-crossing notification

When `SchatkistPosition.position > drempelbedragAmount`, an `x-openregister-notifications` event SHALL alert the treasurer/financial administrator.

#### Scenario: Spec conformance for REQ-SBK-008

- **GIVEN** the REQ-SBK-008 requirement above ("Threshold-crossing notification")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SBK-009 — Manifest entries

The `src/manifest.json` SHALL declare:
- `Overheid > Schatkist-positie` (type: dashboard, shows daily position view)
- `Overheid > Liquidity-reporting` (type: report, trends schatkist position over time)

Visibility: gemeente only.

#### Scenario: Spec conformance for REQ-SBK-009

- **GIVEN** the REQ-SBK-009 requirement above ("Manifest entries")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SBK-010 — Liquidity reporting

The manifest dashboard SHALL display:
- Daily balance line chart (30 days rolling)
- Current position vs drempelbedrag (bar chart)
- Alert history (threshold crossings)

#### Scenario: Spec conformance for REQ-SBK-010

- **GIVEN** the REQ-SBK-010 requirement above ("Liquidity reporting")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SBK-011 — No parallel ledger

Schatkist accounts MUST post to the GL like any other bank account. There is NO separate schatkist ledger, NO parallel reconciliation.

#### Scenario: Spec conformance for REQ-SBK-011

- **GIVEN** the REQ-SBK-011 requirement above ("No parallel ledger")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

## Non-Goals

- No automatic Treasury transfer initiation
- No government treasury settlement processing

## Reuse

- Aggregation via OR `x-openregister-aggregations` (ADR-031)
- Notifications via OR `x-openregister-notifications` (ADR-031)
- Workflow via OR `ScheduledWorkflow` (ADR-031)
- Seed import via repair step (ADR-022)

## Dependencies

- T1: Account (isSchatkistAccount flag), GLLine (for aggregation)
- OR: aggregations, notifications, ScheduledWorkflow
