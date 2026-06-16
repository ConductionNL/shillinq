# Specification: bookkeeping-vat-btw-filing

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-general-ledger (T1), bookkeeping-period-close (T2)

## Overview

This specification defines the VAT (BTW) filing capability for Shillinq, enabling operators to manage periodic VAT returns (kwartaal/maand), ICP statements, VAT corrections (suppletie), and reverse-charge submissions through SBR/Digipoort.

## Scope

- `VatReturn` register with periodic VAT return lifecycle (`draft → submitted → accepted → corrected`)
- `IcpStatement` register for intra-community transactions declarations
- `VatCorrection` register for VAT correction submissions (suppletie-aangifte)
- `VatTariff` register for VAT rate management
- Periodic aggregation of GL postings by VAT rate/bucket
- External submission workflow via SBR/Digipoort per ADR-022
- Declarative lifecycle with approval gates per ADR-031

## ADDED Requirements

### Requirement: REQ-VBTW-001 — VatReturn register declaration

The `VatReturn` schema SHALL be declared in `lib/Settings/shillinq_register.json` as a primary register for managing periodic VAT return submissions.

#### Scenario: Create draft VAT return for monthly period

GIVEN a shillinq municipality administration
WHEN a VAT return is created for January 2026 with periodType "month"
THEN a `VatReturn` record exists in `state: draft` with `administrationId`, `periodType`, `periodYear`, `periodMonth`, `periodQuarter`, and `createdAt` fields.

### Requirement: REQ-VBTW-002 — VatReturn required fields

The `VatReturn` schema MUST include:
- `administrationId` (FK to Administration)
- `periodType` (enum: month, quarter, year)
- `periodYear` (integer)
- `periodMonth` (integer, 1-12, required if periodType="month")
- `periodQuarter` (integer, 1-4, required if periodType="quarter")
- `state` (enum with lifecycle states: draft, submitted, accepted, corrected)
- `amount` (MonetaryAmount: total VAT liability)
- `currency` (ISO 4217, default EUR)
- `notes` (optional free text)

#### Scenario: Spec conformance for REQ-VBTW-002

- **GIVEN** the REQ-VBTW-002 requirement above ("VatReturn required fields")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-VBTW-003 — VAT tariff seed data

The system SHALL ship `btw-tariffs-2026.json` seed containing current statutory VAT rates (21%, 9%, 0%, vrijgesteld, verlegd) with RGS account hints per Wet OB 1968.

#### Scenario: Spec conformance for REQ-VBTW-003

- **GIVEN** the REQ-VBTW-003 requirement above ("VAT tariff seed data")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-VBTW-004 — VAT return aggregation by rate

The `VatReturn.rubrieken` field SHALL be declared as `x-openregister-aggregations` grouping GL postings by VAT rate/bucket, summing per rate for the return period.

#### Scenario: Aggregate VAT by rate from general ledger

GIVEN a VAT return for Q1 2026
WHEN the rubrieken aggregation is computed
THEN it returns buckets with total debit/credit per rate (21%, 9%, 0%, reverse-charge) for all GL postings in that period.

### Requirement: REQ-VBTW-005 — VatReturn lifecycle

The `VatReturn.state` lifecycle SHALL declare four transitions:
- `draft → submitted` (operator initiates filing)
- `submitted → accepted` (external system ACKs receipt)
- `accepted → corrected` (operator files suppletie-aangifte)
- `draft → corrected` (early correction before submission)

#### Scenario: Spec conformance for REQ-VBTW-005

- **GIVEN** the REQ-VBTW-005 requirement above ("VatReturn lifecycle")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-VBTW-006 — Submission approval gate

The `draft → submitted` transition SHALL require approval via `x-openregister-lifecycle.requires.approval-workflow` per ADR-022 if the VAT liability exceeds an operator-configurable threshold.

#### Scenario: Spec conformance for REQ-VBTW-006

- **GIVEN** the REQ-VBTW-006 requirement above ("Submission approval gate")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-VBTW-007 — IcpStatement register

The `IcpStatement` schema SHALL be declared for intra-community supplies declarations, carrying:
- `administrationId` (FK to Administration)
- `periodType` (enum: month, quarter, year)
- `periodYear`, `periodMonth`, `periodQuarter` (as VatReturn)
- `state` (draft, submitted, accepted)
- `icpTransactions` (aggregation or line items)

#### Scenario: Spec conformance for REQ-VBTW-007

- **GIVEN** the REQ-VBTW-007 requirement above ("IcpStatement register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-VBTW-008 — VatCorrection for suppletie

The `VatCorrection` schema SHALL model VAT correction submissions with:
- `administrationId`, `periodType`, `periodYear`, etc. (as VatReturn)
- `correctionReason` (enum: underreporting, overreporting, calculation-error, late-discovery)
- `originalReturnId` (FK to the VatReturn being corrected)
- `adjustmentAmount` (MonetaryAmount)
- `state` (draft, submitted, accepted)

#### Scenario: Spec conformance for REQ-VBTW-008

- **GIVEN** the REQ-VBTW-008 requirement above ("VatCorrection for suppletie")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-VBTW-009 — Reverse-charge tracking (verleggingsregeling)

Reverse-charge transactions (verleggingsregeling) SHALL be flagged on GL postings and aggregated separately in the VAT return buckets, per Wet OB 1968 reverse-charge rules.

#### Scenario: Spec conformance for REQ-VBTW-009

- **GIVEN** the REQ-VBTW-009 requirement above ("Reverse-charge tracking (verleggingsregeling)")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-VBTW-010 — SBR/Digipoort submission workflow

The system SHALL declare an OR `ScheduledWorkflow` (or event-triggered workflow) that:
- Triggers on `VatReturn.submit` transition
- Consumes the `digipoort-sbr` OpenConnector source per ADR-022
- Passes the aggregated VAT buckets as payload
- Updates `VatReturn.state` to `submitted` on ACK receipt

#### Scenario: Spec conformance for REQ-VBTW-010

- **GIVEN** the REQ-VBTW-010 requirement above ("SBR/Digipoort submission workflow")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-VBTW-011 — Manifest entries

The `src/manifest.json` SHALL declare three navigation entries:
- `Belastingen > BTW-aangiften` (type: index, lists VatReturn records)
- `Belastingen > ICP-opgaaf` (type: index, lists IcpStatement records)
- `Belastingen > BTW-correcties` (type: index, lists VatCorrection records)

Each SHALL render via `CnIndexPage` / `CnDetailPage` library renderers per ADR-024.

#### Scenario: Spec conformance for REQ-VBTW-011

- **GIVEN** the REQ-VBTW-011 requirement above ("Manifest entries")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

## Non-Goals

- No XBRL/SBR Nederlandse Taxonomie generation — T4 responsibility
- No multi-currency translation — T5 responsibility
- No bespoke Vue components — manifest-driven generics only

## Reuse

- GL posting aggregation via OR `x-openregister-aggregations` (ADR-031)
- Lifecycle state management via OR `x-openregister-lifecycle` (ADR-031)
- Audit trail via OR audit-trail-immutable (ADR-022)
- External submission via OR `ScheduledWorkflow` + OpenConnector source (ADR-019, ADR-022)
- Manifest navigation via OR generic `CnIndexPage` / `CnDetailPage` (ADR-024)

## Dependencies

- T1: Account, GLLine, GLTransaction (for postings aggregation)
- T2: FiscalPeriod (for period scoping), TrialBalance (for period-end data)
- OR: lifecycle, aggregations, notifications, ScheduledWorkflow, audit-trail-immutable
- OpenConnector: `digipoort-sbr` source registration (separate proposal)
