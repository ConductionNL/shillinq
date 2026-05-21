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

## Requirements

### REQ-WBSO-001: SoProject register

SHALL declare `SoProject` register for project tracking with lifecycle state (draft → active → completed).

### REQ-WBSO-002: SoUrenStaat register

SHALL declare `SoUrenStaat` register with lifecycle (draft → goedgekeurd → afgesloten) and RBAC restricting read to bookkeeper/payroll-officer/auditor roles.

### REQ-WBSO-003: Quarterly RvO mededeling

SHALL declare docudesk template for quarterly RvO mededeling (uren summary + projected loonheffing afdracht).

### REQ-WBSO-004: Annual jaarrapport

SHALL declare docudesk template for annual S&O jaarrapport per RvO requirements.

### REQ-WBSO-005: Afdrachtvermindering calculation

SHALL declare `x-openregister-calculations` computing loonheffing afdracht (wage tax reduction) from uren + uurloon per project.

#### Scenario: Afdracht computed correctly

GIVEN 100 S&O hours at €50/hour in Q1  
WHEN afdrachtvermindering is calculated  
THEN result reflects correct wage tax reduction per RvO.

### REQ-WBSO-006: Manifest navigation entry

SHALL add `featureFlags.mkb-wbso` navigation for S&O project administration.

## Test Plan

- PHPUnit: urenStaat lifecycle + RBAC.
- PHPUnit: afdrachtvermindering calculation per RvO.
- Integration: mededeling/jaarrapport rendering.
