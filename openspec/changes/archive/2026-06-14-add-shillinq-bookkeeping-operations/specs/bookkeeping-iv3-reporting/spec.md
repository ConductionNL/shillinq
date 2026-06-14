# Specification: bookkeeping-iv3-reporting

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-bbv-compliance (T3), bookkeeping-period-close (T2)

## Overview

Quarterly IV3 export to CBS (Centraal Bureau voor de Statistiek) for Dutch municipalities. IV3 aggregates GL data by CBS-defined buckets and submits quarterly via `cbs-iv3` OpenConnector source per Statistische en Inlichtingendienst.

## Scope

- `Iv3Export` register with export lifecycle
- Aggregation from GL by CBS bucket per BBV taakveld
- Declarative mapping to IV3 XML structure
- Quarterly workflow via SBR/Digipoort channel
- CSV/XML generation per CBS specification

## ADDED Requirements

### Requirement: REQ-IV3-001 — Iv3Export register

The `Iv3Export` schema SHALL track quarterly exports with:
- `administrationId` (FK to Administration)
- `periodYear`, `periodQuarter` (for 2026 Q1, Q2, etc.)
- `state` (draft, generated, submitted, accepted)
- `generatedAt` (timestamp)
- `submittedAt` (timestamp, after state=submitted)
- `acceptedAt` (timestamp, after state=accepted)

#### Scenario: Spec conformance for REQ-IV3-001

- **GIVEN** the REQ-IV3-001 requirement above ("Iv3Export register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-IV3-002 — IV3 required fields

The `Iv3Export` schema MUST include per CBS IV3-bestand specificaties:
- Identification (gemeente code, reporting period)
- Activity buckets (summed by taakveld from BBV mapping)
- Revenue / expense / balance figures (aggregated from GL by IV3Bucket)
- Personnel data (FTE count from payroll, if available)

#### Scenario: Spec conformance for REQ-IV3-002

- **GIVEN** the REQ-IV3-002 requirement above ("IV3 required fields")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-IV3-003 — IV3 aggregation by bucket

The `Iv3Export.buckets` field SHALL be declared as `x-openregister-aggregations` grouping GL postings by:
- `BbvAccountMapping.iv3Bucket` (CBS bucket identifier)
- Summing amounts per bucket for the quarter
- Excluding non-applicable GL lines per CBS rules

#### Scenario: Spec conformance for REQ-IV3-003

- **GIVEN** the REQ-IV3-003 requirement above ("IV3 aggregation by bucket")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-IV3-004 — IV3 mapping to XML

The system SHALL express the transformation from aggregated buckets to IV3 XML via OR's declarative mapping engine (per ADR-022), NOT via bespoke PHP rendering.

#### Scenario: Spec conformance for REQ-IV3-004

- **GIVEN** the REQ-IV3-004 requirement above ("IV3 mapping to XML")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-IV3-005 — IV3 export lifecycle

The `Iv3Export.state` lifecycle SHALL declare four transitions:
- `draft → generated` (operator triggers aggregation)
- `generated → submitted` (external submission via `cbs-iv3` source)
- `submitted → accepted` (CBS ACKs receipt)
- `draft → corrected` (early amendment before submission)

#### Scenario: Spec conformance for REQ-IV3-005

- **GIVEN** the REQ-IV3-005 requirement above ("IV3 export lifecycle")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-IV3-006 — Quarterly submission workflow

The system SHALL declare an OR `ScheduledWorkflow` with cron `0 0 1 */3 *` (quarter start) that:
- Creates a new `Iv3Export` record
- Invokes the aggregation to populate buckets
- Triggers submission to `cbs-iv3` OpenConnector source
- Updates state on ACK

#### Scenario: Spec conformance for REQ-IV3-006

- **GIVEN** the REQ-IV3-006 requirement above ("Quarterly submission workflow")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-IV3-007 — Manifest entry

The `src/manifest.json` SHALL declare:
- `Overheid > IV3-rapportages` (type: index, lists Iv3Export records)

Visibility: gemeente/provincie/waterschap only.

#### Scenario: Spec conformance for REQ-IV3-007

- **GIVEN** the REQ-IV3-007 requirement above ("Manifest entry")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-IV3-008 — CBS compliance citation

This spec SHALL cite Statistische en Inlichtingendienst IV3-bestand specs (latest version per CBS guidance) for bucket definitions and submission format.

#### Scenario: Spec conformance for REQ-IV3-008

- **GIVEN** the REQ-IV3-008 requirement above ("CBS compliance citation")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

## Non-Goals

- No full XBRL generation (T4)
- No data validation against CBS edits (CBS-side)

## Reuse

- Aggregation via OR `x-openregister-aggregations` (ADR-031)
- XML mapping via OR mapping engine (ADR-022)
- Lifecycle via OR `x-openregister-lifecycle` (ADR-031)
- Submission workflow via OR `ScheduledWorkflow` + OpenConnector (ADR-019, ADR-022)

## Dependencies

- T3: BBV-compliance (for taakveld → IV3Bucket mapping)
- T2: period-close (for period delineation), GL (for postings)
- OR: aggregations, mappings, ScheduledWorkflow
- OpenConnector: `cbs-iv3` source
