---
status: done
---

# Spec: Bookkeeping — Detachering & Payroll Administration

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (MKB / innovation)  
**Depends on:** bookkeeping-accounts-payable-core  
**Kind:** config

## Purpose

Implement detachering (ZZP/freelance staffing) and payroll bridge: salarisbureau feed imports (ADP/Loket/Visma/Nmbrs) via openconnector + Wet DBA opdrachtgeversverklaring register + IB47 freelance filing.

## Entities

@e2e exclude pure backend/compliance: payroll admin — not browser-testable


### OpdrachtgeversVerklaring (new)

Employer declaration per Wet DBA (freelance worker classification rule).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zzperId | string | Yes | ZZP worker identifier |
| clientId | string | Yes | Client/hiring organization |
| verklaringStatus | enum | Yes | One of `draft`, `accepted`, `rejected`, `amended` |
| looptijd | date | Yes | Agreement start date |
| looptijdEnd | date | No | Agreement end date |
| werkzaamheden | string | Yes | Description of work/services |

### IB47Record (new)

Annual freelance filing (inkomstenbelasting form 47).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| opdrachtvergeverClaimNumber | string | Yes | Client assignment identifier |
| zzperId | string | Yes | ZZP worker identifier |
| inkomstennaam | string | Yes | Income description |
| totaalBedrag | number | Yes | Total annual income in EUR |
| betalingen | array | No | Payment records |
| rapportageStatus | enum | Yes | One of `draft`, `submitted`, `approved`, `archived` |

## Requirements

### REQ-DPA-001: OpdrachtgeversVerklaring register

SHALL declare `OpdrachtgeversVerklaring` register for Wet DBA declarations with RBAC restricting read to bookkeeper/payroll-officer/auditor.

#### Scenario: OpdrachtgeversVerklaring register enforces RBAC

- **GIVEN** the app is installed with the `OpdrachtgeversVerklaring` register declared
- **WHEN** a user without bookkeeper/payroll-officer/auditor role attempts to read a declaration
- **THEN** the read is denied and only authorized roles can access Wet DBA declarations

### REQ-DPA-002: IB47Record register

SHALL declare `IB47Record` register for annual freelance income reporting with RBAC restricting read to authorized roles.

#### Scenario: IB47Record register enforces RBAC

- **GIVEN** the app is installed with the `IB47Record` register declared
- **WHEN** a user without an authorized role attempts to read an annual freelance income record
- **THEN** the read is denied and only authorized roles can access IB47 records

### REQ-DPA-003: Salarisbureau openconnector sources

SHALL declare openconnector source rows for ADP/Loket/Visma/Nmbrs OAuth2 salary feed imports, materializing as loonkosten journal entries.

#### Scenario: Salary feed imports correctly

GIVEN ADP OAuth configured with credentials  
WHEN monthly salary run syncs  
THEN journal entries materialize for each employee per period.

### REQ-DPA-004: Opdrachtgeversverklaring docudesk template

SHALL reference standard docudesk template for opdrachtgeversverklaring.

#### Scenario: Opdrachtgeversverklaring renders from docudesk template

- **GIVEN** the standard docudesk template for opdrachtgeversverklaring is referenced
- **WHEN** a user generates an opdrachtgeversverklaring document
- **THEN** the document is produced from the referenced docudesk template

### REQ-DPA-005: IB47-formulier docudesk template

SHALL reference docudesk template for IB47-formulier (Belastingdienst format) with annual batch submission.

#### Scenario: IB47 batch prepared annually

GIVEN ZZP workers with income records in 2025  
WHEN annual IB47 batch is prepared  
THEN each IB47Record generates a Form 47 for submission to Belastingdienst.

### REQ-DPA-006: Manifest navigation entry

SHALL add `featureFlags.mkb-detachering` navigation for payroll/detachering administration.

#### Scenario: Detachering navigation gated by feature flag

- **GIVEN** the `mkb-detachering` feature flag is enabled in the manifest
- **WHEN** the app navigation renders
- **THEN** the payroll/detachering administration navigation entry is shown

## Test Plan

- PHPUnit: OpdrachtgeversVerklaring lifecycle + Wet DBA validation.
- PHPUnit: IB47Record batch generation per fiscal year.
- Integration: salarisbureau feed import (ADP mock) succeeds; journal entries post correctly.
- Playwright: manifest navigation per flag.
