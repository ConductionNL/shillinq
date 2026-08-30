# Spec: Bookkeeping — Vpb (Corporate Tax)

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (MKB / innovation)  
**Depends on:** bookkeeping-bbv-compliance, bookkeeping-market-government-separation  
**Kind:** config

## Summary

Implement corporate tax (vennootschapsbelasting) administration per Wet modernisering Vpb-plicht (2016). Vpb-pligtig account tagging + filtered Vpb-balans aggregation + aangifte voorbereiding template.

## Entities

### Account (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vpbPligtig | boolean | No | Is this account subject to Vpb? |

### VpbBalansLink (new, overlay)

Links ondernemingsactiviteit to its Vpb-pligtig accounts for separate Vpb-balans.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| costCenterId | string | Yes | FK to CostCenter (ondernemingsactiviteit) |
| accountNumber | string | Yes | FK to Account (vpbPligtig=true) |

## ADDED Requirements

### Requirement: REQ-VPB-001 — vpbPligtig flag on Account

The system SHALL add a `vpbPligtig: boolean` field to the `Account` schema (default false).

#### Scenario: An account is flagged Vpb-pligtig

- **GIVEN** an Account
- **WHEN** `vpbPligtig` is set to `true`
- **THEN** the account MUST be included in the Vpb-balans aggregation.

### Requirement: REQ-VPB-002 — VpbBalansLink register

The system SHALL declare a `VpbBalansLink` overlay enabling per-activity Vpb-balans computation.

#### Scenario: A Vpb-pligtig account is linked to an ondernemingsactiviteit

- **GIVEN** a vpbPligtig Account and an ondernemingsactiviteit CostCenter
- **WHEN** a VpbBalansLink is declared between them
- **THEN** the account MUST roll up under that activity's Vpb-balans.

### Requirement: REQ-VPB-003 — Vpb-balans aggregation

SHALL declare filtered aggregation producing Vpb-balans (GL filtered to vpbPligtig=true accounts per ondernemingsactiviteit).

#### Scenario: Vpb-balans filtered correctly

GIVEN mixed account postings (Vpb-pligtig and exempt)  
WHEN Vpb-balans is generated per activity  
THEN only vpbPligtig=true accounts appear.

### Requirement: REQ-VPB-004 — Vpb-aangifte voorbereiding template

The system SHALL reference a docudesk template for Vpb-aangifte preparation.

#### Scenario: Vpb-aangifte renders from a docudesk template

- **GIVEN** a generated Vpb-balans
- **WHEN** the Vpb-aangifte voorbereiding document is produced
- **THEN** rendering MUST resolve a docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-VPB-005 — SBR submission via openconnector

The system SHALL wire Vpb-aangifte submission to the Belastingdienst SBR endpoint (consumed from the T4-base `sbr-xbrl-reporting` capability).

#### Scenario: Vpb-aangifte submission rides openconnector/SBR

- **GIVEN** a prepared Vpb-aangifte
- **WHEN** submission to the Belastingdienst is triggered
- **THEN** it MUST route through the SBR-XBRL openconnector path, not an app-local HTTP client.

## Test Plan

- PHPUnit: vpbPligtig flag filtering.
- Integration: Vpb-balans matches worked example.
