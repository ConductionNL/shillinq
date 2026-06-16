# Specification: bookkeeping-zzp-tax-regime

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-general-ledger (T1)

## Overview

ZZP (self-employed) tax regime for Dutch freelancers. Tracks 1225-urencriterium (qualifying hours for business status), zelfstandigenaftrek, startersaftrek, MKB-winstvrijstelling, and IB-aangifteformulier export per Wet IB 2001.

## Scope

- `UrenRegistratie` register for billable hour tracking
- `ZzpDeduction` register for tax deduction management
- `IbAangifteExport` for income tax form export
- YTD qualifying hours aggregation (cross-period, with ADR-031 exception option)
- Automated deduction calculation based on hours and thresholds
- Excluded hours handling (sick leave, parental leave, vacation, admin)

## ADDED Requirements

### Requirement: REQ-ZZP-001 — UrenRegistratie register

The `UrenRegistratie` schema SHALL track billable hours with:
- `administrationId` (FK to Administration)
- `personId` (FK to person/freelancer)
- `workDate` (date worked)
- `hours` (number of hours)
- `category` (enum: billable, excluded, admin, project)
- `excludedReason` (enum: sick, parental-leave, vacation, non-billable-admin; required if category="excluded")
- `projectId` (optional FK to Project)
- `hourlyRate` (MonetaryAmount at work-date)

#### Scenario: Spec conformance for REQ-ZZP-001

- **GIVEN** the REQ-ZZP-001 requirement above ("UrenRegistratie register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ZZP-002 — Excluded hours handling

Billable hours marked as `category: excluded` with a valid `excludedReason` (sick, parental-leave, vacation, non-billable-admin) SHALL NOT count toward the 1225-urencriterium per Wet IB 2001 art. 3.6.

#### Scenario: Exclude ziekte hours from qualifying total

GIVEN a freelancer with 1400 hours total in 2026, including 200 hours marked `category: excluded, excludedReason: sick`
WHEN ytdQualifyingHours is calculated
THEN it returns 1200 hours (1400 - 200 excluded) and the freelancer qualifies for 1225-criteria status.

### Requirement: REQ-ZZP-003 — Qualifying hours aggregation

The `ZzpDeduction.ytdQualifyingHours` field SHALL be declared as `x-openregister-calculations`, summing `UrenRegistratie.hours` where category ≠ "excluded" or excludedReason ≠ null for the calendar year.

If OR's aggregation engine cannot span fiscal periods, a single-method PHP guard `UrencriteriumGuard::currentYtdHours(string $personId, int $year): float` is permitted per ADR-031 exception, documented in design.md.

#### Scenario: Spec conformance for REQ-ZZP-003

- **GIVEN** the REQ-ZZP-003 requirement above ("Qualifying hours aggregation")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ZZP-004 — ZzpDeduction register

The `ZzpDeduction` schema SHALL track deduction eligibility with:
- `personId` (FK to freelancer)
- `administrationId` (FK to Administration)
- `year` (2026, etc.)
- `ytdQualifyingHours` (derived, see REQ-ZZP-003)
- `qualifies1225` (boolean, computed from ytdQualifyingHours ≥ 1225)
- `qualifiesStarters` (boolean, if founded < 2 years, 800-hour threshold)
- `zelfstandigenaftrek` (MonetaryAmount, from seed)
- `startersaftrek` (MonetaryAmount, if qualifiesStarters)
- `mkbWinstvrijstelling` (MonetaryAmount, from seed)

#### Scenario: Spec conformance for REQ-ZZP-004

- **GIVEN** the REQ-ZZP-004 requirement above ("ZzpDeduction register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ZZP-005 — Tax deduction amounts seed

The system SHALL ship `zzp-deduction-amounts-2026.json` with:
- `zelfstandigenaftrek: 5000` (EUR, standard deduction)
- `startersaftrek: 1000` (EUR, additional for first 2 years)
- `mkbWinstvrijstelling: 900` (EUR, exemption threshold)
- `thresholds: { "1225": 1225, "starters": 800 }`

#### Scenario: Spec conformance for REQ-ZZP-005

- **GIVEN** the REQ-ZZP-005 requirement above ("Tax deduction amounts seed")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ZZP-006 — Deduction calculation

`ZzpDeduction.totalAllowableDeduction` SHALL equal `zelfstandigenaftrek + (startersaftrek if qualifiesStarters) + mkbWinstvrijstelling`; the engine MUST evaluate this formula declaratively via `x-openregister-calculations`.

#### Scenario: Spec conformance for REQ-ZZP-006

- **GIVEN** the REQ-ZZP-006 requirement above ("Deduction calculation")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ZZP-007 — IB-aangifteformulier export

The `IbAangifteExport` schema SHALL prepare export fields for Dutch income tax return (Inkomstenbelastingaangifte):
- `personId`, `year`, `exportDate`
- `totalIncome` (from GL revenue aggregation)
- `allowableDeductions` (from ZzpDeduction)
- `taxableIncome` (totalIncome - deductions)
- `attachmentUri` (docudesk reference to export PDF)

#### Scenario: Spec conformance for REQ-ZZP-007

- **GIVEN** the REQ-ZZP-007 requirement above ("IB-aangifteformulier export")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ZZP-008 — Manifest entries

The `src/manifest.json` SHALL declare:
- `Belastingen > Urenregistratie` (type: index, lists UrenRegistratie records)
- `Belastingen > ZZP-aftrek` (type: detail, shows ZzpDeduction + deduction summary)
- `Belastingen > IB-aangifte` (type: index, lists IbAangifteExport records)

Visibility: zzp administrations only.

#### Scenario: Spec conformance for REQ-ZZP-008

- **GIVEN** the REQ-ZZP-008 requirement above ("Manifest entries")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ZZP-009 — Manifest entry for IB export

The `src/manifest.json` SHALL declare:
- `Belastingen > IB-aangifte-export` (type: action, exports ZzpDeduction to IB form PDF)

#### Scenario: Spec conformance for REQ-ZZP-009

- **GIVEN** the REQ-ZZP-009 requirement above ("Manifest entry for IB export")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

## Non-Goals

- No automatic IB form submission to tax authorities
- No multi-year deduction averaging

## Reuse

- Calculation via OR `x-openregister-calculations` (ADR-031)
- Aggregation via OR `x-openregister-aggregations` or PHP guard per ADR-031 exception
- Seed import via repair step (ADR-022)

## Dependencies

- T1: GLTransaction (for revenue aggregation)
- OR: calculations, aggregations
- docudesk: for export attachment
