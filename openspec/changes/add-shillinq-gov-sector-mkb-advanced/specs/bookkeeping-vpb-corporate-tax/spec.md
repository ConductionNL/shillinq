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

## Requirements

### REQ-VPB-001: vpbPligtig flag on Account

SHALL add `vpbPligtig: boolean` field to `Account` (default false).

### REQ-VPB-002: VpbBalansLink register

SHALL declare `VpbBalansLink` overlay enabling per-activity Vpb-balans computation.

### REQ-VPB-003: Vpb-balans aggregation

SHALL declare filtered aggregation producing Vpb-balans (GL filtered to vpbPligtig=true accounts per ondernemingsactiviteit).

#### Scenario: Vpb-balans filtered correctly

GIVEN mixed account postings (Vpb-pligtig and exempt)  
WHEN Vpb-balans is generated per activity  
THEN only vpbPligtig=true accounts appear.

### REQ-VPB-004: Vpb-aangifte voorbereiding template

SHALL reference docudesk template for Vpb-aangifte preparation.

### REQ-VPB-005: SBR submission via openconnector

SHALL wire Vpb-aangifte submission to Belastingdienst SBR endpoint (consumed from T4-base `sbr-xbrl-reporting`).

## Test Plan

- PHPUnit: vpbPligtig flag filtering.
- Integration: Vpb-balans matches worked example.
