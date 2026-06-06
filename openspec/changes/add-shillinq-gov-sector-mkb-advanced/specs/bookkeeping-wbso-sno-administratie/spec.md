# Spec: Bookkeeping — WBSO/S&O Administration

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (MKB / innovation)  
**Depends on:** bookkeeping-cost-centers-dimensions  
**Kind:** config

## Summary

Implement S&O (speur & ontwikkelingswerk / R&D) administration: project-medewerker uren register + lifecycle management + quarterly RvO mededeling + annual jaarrapport + afdrachtvermindering loonheffing calculation.

## Entities

### SoProject (new)

A research & development project subject to S&O tax incentives.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| projectName | string | Yes | Project name |
| rvoProjectNumber | string | No | RvO project identifier |
| soCertificaat | string | No | S&O certificate reference |
| startDate | date | Yes | Project start date |
| endDate | date | No | Project end date (if applicable) |
| status | enum | Yes | One of `draft`, `active`, `completed`, `archived` |

### SoUrenStaat (new)

Weekly hour statement per medewerker on a project.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| projectId | string | Yes | FK to SoProject |
| medewerkerId | string | Yes | FK to Person (or employee ID) |
| week | date | Yes | Week start date (Monday) |
| hoursWorked | number | Yes | S&O hours in the week |
| uurloon | number | No | Hourly rate (EUR) |
| status | enum | Yes | One of `draft`, `goedgekeurd`, `afgesloten` |

## ADDED Requirements

### Requirement: REQ-WBSO-001 — SoProject register

The system SHALL declare a `SoProject` register for project tracking with lifecycle state (draft → active → completed).

#### Scenario: An S&O project advances through its lifecycle

- **GIVEN** a SoProject in `draft`
- **WHEN** it is activated and later completed
- **THEN** its lifecycle state MUST transition draft → active → completed.

### Requirement: REQ-WBSO-002 — SoUrenStaat register

The system SHALL declare a `SoUrenStaat` register with lifecycle (draft → goedgekeurd → afgesloten) and RBAC restricting read to bookkeeper/payroll-officer/auditor roles.

#### Scenario: Urenstaat is RBAC-restricted personnel data

- **GIVEN** a SoUrenStaat record with personnel hours
- **WHEN** a user without the bookkeeper, payroll-officer, or auditor role requests it
- **THEN** the read MUST be denied; only the authorized roles MAY read it.

### Requirement: REQ-WBSO-003 — Quarterly RvO mededeling

The system SHALL declare a docudesk template for the quarterly RvO mededeling (uren summary + projected loonheffing afdracht).

#### Scenario: Quarterly mededeling renders from a docudesk template

- **GIVEN** approved urenstaten for a quarter
- **WHEN** the RvO mededeling is generated
- **THEN** rendering MUST resolve a docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-WBSO-004 — Annual jaarrapport

The system SHALL declare a docudesk template for the annual S&O jaarrapport per RvO requirements.

#### Scenario: Annual jaarrapport renders from a docudesk template

- **GIVEN** approved urenstaten for a year
- **WHEN** the S&O jaarrapport is generated
- **THEN** rendering MUST resolve a docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-WBSO-005 — Afdrachtvermindering calculation

SHALL declare `x-openregister-calculations` computing loonheffing afdracht (wage tax reduction) from uren + uurloon per project.

#### Scenario: Afdracht computed correctly

GIVEN 100 S&O hours at €50/hour in Q1  
WHEN afdrachtvermindering is calculated  
THEN result reflects correct wage tax reduction per RvO.

### Requirement: REQ-WBSO-006 — Manifest navigation entry

The system SHALL add a `featureFlags.mkb-wbso` navigation entry for S&O project administration.

#### Scenario: WBSO navigation is feature-flag gated

- **GIVEN** the `mkb-wbso` feature flag is off
- **WHEN** the UI renders the menu
- **THEN** the S&O administration entry MUST NOT appear; it appears only when the flag is on.

## Test Plan

- PHPUnit: urenStaat lifecycle + RBAC.
- PHPUnit: afdrachtvermindering calculation per RvO.
- Integration: mededeling/jaarrapport rendering.
