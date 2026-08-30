# Specification: bookkeeping-kor-kleine-ondernemersregeling

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-vat-btw-filing (T3)

## Overview

KOR (Kleine OndernemersRegeling) tracking for Dutch SMBs. KOR allows small businesses with revenue below €20k to exempt themselves from VAT filing. This spec manages opt-in/opt-out lifecycle, drempel tracking, and automatic regime switching.

## Scope

- `KorRegime` register tracking KOR status per administration
- `KorThreshold` seed data (€20k omzetdrempel, 80% warning)
- YTD revenue aggregation (cross-period)
- Automatic threshold-crossing detection and notification
- Optional PHP guard for aggregation if OR engine lacks period-spanning aggregations

## ADDED Requirements

### Requirement: REQ-KOR-001 — KorRegime register

The `KorRegime` schema SHALL track KOR status with:
- `administrationId` (FK to Administration)
- `state` (enum: outside, opted-in, threshold-warning, threshold-exceeded, opted-out)
- `optedInDate` (timestamp, when operator chose KOR)
- `optedOutDate` (timestamp, when exited KOR)
- `ytdRevenue` (derived field, see REQ-KOR-004)
- `thresholdAmount` (from seed, €20k)

#### Scenario: Spec conformance for REQ-KOR-001

- **GIVEN** the REQ-KOR-001 requirement above ("KorRegime register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-KOR-002 — KOR regime lifecycle

The `KorRegime.state` SHALL transition:
- `outside → opted-in` (operator elects KOR exemption)
- `opted-in → threshold-warning` (YTD revenue ≥ 80% of €20k)
- `threshold-warning → threshold-exceeded` (YTD revenue ≥ 100%)
- `threshold-exceeded → opted-out` (auto-transition with alert or operator action)

#### Scenario: Spec conformance for REQ-KOR-002

- **GIVEN** the REQ-KOR-002 requirement above ("KOR regime lifecycle")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-KOR-003 — Threshold seed data

The system SHALL ship `kor-thresholds-2026.json` with:
- `thresholdAmount: 20000` (EUR)
- `warningPercentage: 80`
- `legislativeReference: "Wet OB 1968 art. 25 lid 1"`

#### Scenario: Spec conformance for REQ-KOR-003

- **GIVEN** the REQ-KOR-003 requirement above ("Threshold seed data")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-KOR-004 — YTD revenue aggregation

The `KorRegime.ytdRevenue` field SHALL be declared as `x-openregister-calculations`, aggregating revenue from `Invoice` (T2 AR register) for the current calendar year.

If OR's aggregation engine cannot span fiscal periods in a `requires` condition, a single-method PHP guard `KorThresholdGuard::currentYtdRevenue(string $adminId, int $year): float` is permitted per ADR-031 exception, documented in design.md.

#### Scenario: Compute YTD revenue for threshold check

GIVEN a KOR-opted-in SMB with invoices totaling €15,000 in Jan-Jun 2026
WHEN ytdRevenue is calculated
THEN it returns 15000.00 EUR and no threshold transition fires.

### Requirement: REQ-KOR-005 — Automatic threshold detection

When ytdRevenue crosses 80% or 100%, the lifecycle SHALL automatically:
- Emit an `x-openregister-notifications` event (warning or alarm)
- Transition state to `threshold-warning` or `threshold-exceeded`

#### Scenario: Spec conformance for REQ-KOR-005

- **GIVEN** the REQ-KOR-005 requirement above ("Automatic threshold detection")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-KOR-006 — Opt-out VAT posting (NOT auto-posted)

When KOR transitions `threshold-exceeded → opted-out`, the system SHALL create a `JournalEntry` template (debit VAT expense, credit VAT control) in `state: pending`. The operator + accountant review the posting before approval. The posting MUST NOT auto-post.

#### Scenario: Spec conformance for REQ-KOR-006

- **GIVEN** the REQ-KOR-006 requirement above ("Opt-out VAT posting (NOT auto-posted)")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-KOR-007 — Per-administration KOR tracking

Each SMB administration MAY have zero or one active `KorRegime` record, and the engine MUST enforce that uniqueness via the schema constraint. KOR remains optional; most SMBs opt out.

#### Scenario: Spec conformance for REQ-KOR-007

- **GIVEN** the REQ-KOR-007 requirement above ("Per-administration KOR tracking")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-KOR-008 — Manifest entry

The `src/manifest.json` SHALL declare:
- `Belastingen > KOR-status` (type: detail, shows KorRegime status + YTD progress)

Visibility: mkb/zzp administrations only.

#### Scenario: Spec conformance for REQ-KOR-008

- **GIVEN** the REQ-KOR-008 requirement above ("Manifest entry")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-KOR-009 — Dashboard widget

The system SHALL declare an `x-openregister-widgets` entry showing KOR threshold progress (0-100% bar) for opted-in administrations on the dashboard.

#### Scenario: Spec conformance for REQ-KOR-009

- **GIVEN** the REQ-KOR-009 requirement above ("Dashboard widget")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-KOR-010 — Alerting thresholds

Notifications MUST fire at 80% (warning: "approaching KOR threshold") and 100% (alarm: "KOR threshold exceeded").

#### Scenario: Spec conformance for REQ-KOR-010

- **GIVEN** the REQ-KOR-010 requirement above ("Alerting thresholds")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

## Non-Goals

- No automatic regime switching without operator approval
- No retroactive VAT recalculation if KOR regime changes

## Reuse

- Calculation via OR `x-openregister-calculations` (ADR-031)
- Aggregation via OR `x-openregister-aggregations` or PHP guard per ADR-031 exception
- Lifecycle via OR `x-openregister-lifecycle` (ADR-031)
- Notifications via OR `x-openregister-notifications` (ADR-031)
- Widgets via OR `x-openregister-widgets` (ADR-031)

## Dependencies

- T3: VAT-filing (context for YTD revenue)
- T2: Invoice (AR register for revenue aggregation)
- OR: calculations, aggregations, lifecycle, notifications, widgets
