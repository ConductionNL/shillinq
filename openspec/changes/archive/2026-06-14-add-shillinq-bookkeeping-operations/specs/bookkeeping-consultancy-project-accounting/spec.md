# Specification: bookkeeping-consultancy-project-accounting

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-general-ledger (T1), bookkeeping-accounts-receivable-core (T2)

## Overview

Project accounting for consultancy firms (Conduction's primary customer profile). Implements RJ 270 / IFRS 15 percentage-of-completion revenue recognition, multi-rate billing, WIP tracking, utilisation reporting, and project P&L per Dutch RJ 270 standard.

## Scope

- `Project` register for project lifecycle and metrics
- `ProjectAssignment` register for resource allocation
- `BillableHour` register (extension of UrenRegistratie) for hour tracking + rate snapshots
- `RateCard` register for multi-rate definitions (junior/medior/senior/partner)
- `WipBalance` register for period-end WIP snapshots
- RJ 270 percentage-of-completion calculation (revenue recognition)
- Project P&L aggregation
- Utilisation reporting (billable % of available capacity)

## ADDED Requirements

### Requirement: REQ-CPA-001 — Project register

The `Project` schema SHALL track projects with:
- `administrationId` (FK to Administration)
- `projectCode` (unique identifier)
- `projectName` (client + project name)
- `state` (enum: offerte, active, on-hold, closed, archived)
- `startDate`, `endDate` (projected or actual)
- `contractValue` (MonetaryAmount, total contract price)
- `estimatedCost` (MonetaryAmount, for RJ 270 calculation)
- `costIncurredToDate` (derived aggregation, see REQ-CPA-007)
- `recognisedRevenue` (derived calculation, see REQ-CPA-007)

#### Scenario: Spec conformance for REQ-CPA-001

- **GIVEN** the REQ-CPA-001 requirement above ("Project register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-002 — Project lifecycle

The `Project.state` lifecycle SHALL declare five transitions:
- `offerte → active` (contract signed)
- `active → on-hold` (suspended, e.g. client pause)
- `on-hold → active` (resumed)
- `active → closed` (delivery complete)
- `closed → archived` (no further access)

#### Scenario: Spec conformance for REQ-CPA-002

- **GIVEN** the REQ-CPA-002 requirement above ("Project lifecycle")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-003 — ProjectAssignment register

The `ProjectAssignment` schema SHALL track resource allocation with:
- `projectId` (FK to Project)
- `personId` (FK to resource/consultant)
- `roleId` (e.g. "lead", "dev", "qa", "pm")
- `startDate`, `endDate` (assignment period)
- `allocatedHours` (hours available for project)
- `billableHours` (actual hours worked per BillableHour register)

#### Scenario: Spec conformance for REQ-CPA-003

- **GIVEN** the REQ-CPA-003 requirement above ("ProjectAssignment register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-004 — RateCard register

The `RateCard` schema SHALL define billing rates with:
- `administrationId` (FK to Administration)
- `level` (enum: junior, medior, senior, partner)
- `hourlyRate` (MonetaryAmount)
- `effectiveFrom` (date rate takes effect)
- `effectiveTo` (date rate expires, null for current)
- `currency` (EUR)

#### Scenario: Spec conformance for REQ-CPA-004

- **GIVEN** the REQ-CPA-004 requirement above ("RateCard register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-005 — RateCard seed

The system SHALL ship `rate-card-templates.json` with default rates:
- Junior: €65/hour
- Medior: €95/hour
- Senior: €135/hour
- Partner: €180/hour

(Operators override per administration.)

#### Scenario: Spec conformance for REQ-CPA-005

- **GIVEN** the REQ-CPA-005 requirement above ("RateCard seed")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-006 — BillableHour register

The `BillableHour` schema (extends T3's UrenRegistratie) SHALL track:
- `urenRegistratieId` (FK to UrenRegistratie)
- `projectId` (FK to Project)
- `personLevel` (enum: junior, medior, senior, partner)
- `workDate` (date worked)
- `hours` (hours worked)
- `recognisedRate` (MonetaryAmount, snapshotted at work-date per RateCard effective-date)
- `recognisedRevenue` (hours × recognisedRate)

#### Scenario: Spec conformance for REQ-CPA-006

- **GIVEN** the REQ-CPA-006 requirement above ("BillableHour register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-007 — RJ 270 percentage-of-completion

The `Project.recognisedRevenue` field SHALL be declared as `x-openregister-calculations` per RJ 270 §3.2.4 (cost-to-cost method):

`recognisedRevenue = contractValue × (costIncurredToDate / estimatedCost)`

Where:
- `costIncurredToDate` = `x-openregister-aggregations` summing GLLine.amount for cost accounts tagged to the project
- `estimatedCost` = operator-set field on Project

#### Scenario: Compute RJ 270 revenue for 50% complete project

GIVEN a project with contractValue=€100k, estimatedCost=€80k, costIncurredToDate=€40k
WHEN recognisedRevenue is calculated
THEN it returns €50k (€100k × (€40k / €80k)).

### Requirement: REQ-CPA-008 — WipBalance snapshots

The `WipBalance` register SHALL snapshot WIP (work-in-progress) at period-end via a scheduled workflow triggered by T2's period-close event:
- `projectId` (FK to Project)
- `periodId` (FK to FiscalPeriod)
- `costIncurredToDate` (snapshot of cost aggregation)
- `recognisedRevenue` (snapshot of RJ 270 calculation)
- `wipBalance` (cost incurred - recognised revenue)

#### Scenario: Spec conformance for REQ-CPA-008

- **GIVEN** the REQ-CPA-008 requirement above ("WipBalance snapshots")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-009 — Rate snapshot at write time

Each `BillableHour.recognisedRate` MUST be snapshotted at the hour's work date, not at invoice date. This prevents retroactive rate changes from distorting revenue recognition per RJ 270 §3.2.4.

#### Scenario: Hour logged Jan 2026, invoiced Mar 2026, rate-card changed Feb

GIVEN a 10-hour assignment logged 2026-01-15 at €95/hour (medior)
AND a rate-card change on 2026-02-01 to €105/hour
WHEN the hour is invoiced on 2026-03-15
THEN BillableHour.recognisedRate = €95/hour (locked to work-date rate, not current rate).

### Requirement: REQ-CPA-010 — Project P&L

The `Project.profitLoss` (or project dashboard) SHALL display as `x-openregister-aggregations`:
- Project revenue (recognisedRevenue + invoiced amount)
- Project costs (GLLine sum filtered by project FK)
- Gross margin (revenue - costs)
- Margin % (margin / revenue)

#### Scenario: Spec conformance for REQ-CPA-010

- **GIVEN** the REQ-CPA-010 requirement above ("Project P&L")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-011 — Utilisation reporting

The system SHALL expose utilisation metrics via aggregation:
- `billableHours / allocatedHours` per assignment
- `billableHours / (8 hours/day × business days)` per person across projects
- Utilisation trends over time

#### Scenario: Spec conformance for REQ-CPA-011

- **GIVEN** the REQ-CPA-011 requirement above ("Utilisation reporting")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-012 — Manifest entries

The `src/manifest.json` SHALL declare:
- `Projecten > Overzicht` (type: index, lists all Project records)
- `Projecten > P&L` (type: dashboard, shows project profitability)
- `Projecten > Tarieven` (type: detail, shows RateCard management)
- `Projecten > Utilisatie` (type: dashboard, shows utilisation metrics)

Visibility: consultancy administrations only.

#### Scenario: Spec conformance for REQ-CPA-012

- **GIVEN** the REQ-CPA-012 requirement above ("Manifest entries")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-013 — RJ 270 stage seed

The system SHALL ship `rj-270-stages.json` with 4 canonical stages:
- `initiation: "RJ 270 §1: project setup"` (contract negotiation)
- `execution: "RJ 270 §2: delivery"` (work in progress)
- `closeout: "RJ 270 §3: revenue recognition"` (final billing)
- `complete: "RJ 270 §4: project archived"` (closed)

#### Scenario: Spec conformance for REQ-CPA-013

- **GIVEN** the REQ-CPA-013 requirement above ("RJ 270 stage seed")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-CPA-014 — Compliance citation

This spec SHALL cite:
- RJ 270 §3 (Raad voor de Jaarverslaglegging, percentage-of-completion)
- IFRS 15 §B14-B19 (revenue recognition over time)

#### Scenario: Spec conformance for REQ-CPA-014

- **GIVEN** the REQ-CPA-014 requirement above ("Compliance citation")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

## Non-Goals

- No multi-currency consolidated reporting (T5)
- No inter-project eliminations

## Reuse

- Calculation via OR `x-openregister-calculations` (ADR-031)
- Aggregation via OR `x-openregister-aggregations` (ADR-031)
- Lifecycle via OR `x-openregister-lifecycle` (ADR-031)
- Workflow via OR `ScheduledWorkflow` (ADR-031)
- Seed import via repair step (ADR-022)

## Dependencies

- T1: GLTransaction (for cost aggregation), Account (cost account tagging)
- T2: Invoice (for invoiced amount), FiscalPeriod (for period-end close)
- OR: calculations, aggregations, lifecycle, ScheduledWorkflow
