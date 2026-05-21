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

### REQ-SBK-001: Schatkistbankieren compliance

Municipalities SHALL manage Treasury banking through a daily position view, not a parallel ledger. All schatkist deposits/withdrawals post to the GL as regular bank transactions.

### REQ-SBK-002: Account schatkist flagging

Each `Account` (T1) MAY carry an `isSchatkistAccount: boolean` flag (default false). Flagged accounts are Treasury deposit account, working capital account, etc.

#### Scenario: Flag Treasury deposit account

GIVEN a gemeente administration
WHEN Account "1100 Treasury Deposit Account" is created or modified
THEN it is flagged `isSchatkistAccount: true` by the administrator.

### REQ-SBK-003: SchatkistPosition register

The `SchatkistPosition` schema SHALL track daily position with:
- `administrationId` (FK to Administration)
- `businessDate` (business day date)
- `position` (MonetaryAmount, aggregated from flagged accounts)
- `drempelbedragApplies` (boolean, based on municipality size/threshold)
- `drempelbedragAmount` (from seed, e.g. 0.75% of begroting)
- `aboveThreshold` (boolean, position > drempelbedrag)

### REQ-SBK-004: Daily position aggregation

The `SchatkistPosition.position` field SHALL be declared as `x-openregister-aggregations`, summing GL postings on accounts where `isSchatkistAccount = true` for the business day, computed end-of-day.

### REQ-SBK-005: Drempelbedrag seed

The system SHALL ship `schatkist-thresholds.json` with municipality-size-based thresholds:
- `small_municipality: 0.75` (% of begroting)
- `large_municipality: 0.5` (% of begroting)
- `legislative_reference: "Wet HOF art. 2 + ministerial regeling"`

### REQ-SBK-006: Threshold configuration

Each gemeente administration MUST be configured with its municipality size (small/medium/large) to determine drempelbedrag applicability and calculation.

### REQ-SBK-007: Daily position workflow

The system SHALL declare an OR `ScheduledWorkflow` triggering once per business day that:
- Computes the daily aggregated position from flagged accounts
- Creates a new `SchatkistPosition` record
- Compares against drempelbedrag
- Fires a notification if above threshold

### REQ-SBK-008: Threshold-crossing notification

When `SchatkistPosition.position > drempelbedragAmount`, an `x-openregister-notifications` event SHALL alert the treasurer/financial administrator.

### REQ-SBK-009: Manifest entries

The `src/manifest.json` SHALL declare:
- `Overheid > Schatkist-positie` (type: dashboard, shows daily position view)
- `Overheid > Liquidity-reporting` (type: report, trends schatkist position over time)

Visibility: gemeente only.

### REQ-SBK-010: Liquidity reporting

The manifest dashboard SHALL display:
- Daily balance line chart (30 days rolling)
- Current position vs drempelbedrag (bar chart)
- Alert history (threshold crossings)

### REQ-SBK-011: No parallel ledger

Schatkist accounts MUST post to the GL like any other bank account. There is NO separate schatkist ledger, NO parallel reconciliation.

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
