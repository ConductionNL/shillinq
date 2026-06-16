# Spec: Bookkeeping — Rekenkamer Audit Pack

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-audit-trail, bookkeeping-financial-statements  
**Kind:** config

## Summary

Provide audit-ready exports from the existing audit-trail (per ADR-022) in NIVRA-bestand format (standardised audit file for Dutch accountancy), with steekproef (sampling) and ledenraadpleging-export (raad member view) as presentation manifests only—no new audit register.

## ADDED Requirements

### Requirement: REQ-REK-001 — NIVRA-bestand export

SHALL declare an `x-openregister-aggregations` view transforming audit-trail data into NIVRA-bestand format for accountants and rekenkamer.

#### Scenario: NIVRA export generated

GIVEN an audit-trail with 2000+ postings  
WHEN NIVRA-bestand is requested for Q4 2025  
THEN a CSV/XML export in NIVRA standard format is produced.

### Requirement: REQ-REK-002 — Steekproef (sampling) view

SHALL provide a random sampling aggregation allowing selection of N postings within a period for substantive testing.

#### Scenario: Steekproef sample extracted

GIVEN 1500 postings in a period  
WHEN auditor requests a 10% steekproef sample  
THEN 150 postings are randomly selected for review.

### Requirement: REQ-REK-003 — Ledenraadpleging-export

SHALL declare a redacted audit-trail view for raadsleden (council members) excluding sensitive details.

#### Scenario: Council member export redacted

GIVEN full audit-trail with personnel records  
WHEN ledenraadpleging-export is generated  
THEN personnel references are redacted; financial summary remains.

### Requirement: REQ-REK-004 — Docudesk template references

The system SHALL reference three docudesk templates (NIVRA-bestand, steekproef-list, ledenraadpleging-rapport) for document rendering and signing.

#### Scenario: Audit-pack documents render from docudesk templates

- **GIVEN** a completed audit-trail export
- **WHEN** the NIVRA-bestand, steekproef-list, and ledenraadpleging-rapport are generated
- **THEN** each MUST resolve a docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-REK-005 — Manifest navigation entry

The system SHALL add a feature-flag-controlled navigation entry under `featureFlags.gov-rekenkamer` for audit-pack exports.

#### Scenario: Rekenkamer navigation is feature-flag gated

- **GIVEN** the `gov-rekenkamer` feature flag is off
- **WHEN** the UI renders the menu
- **THEN** the audit-pack entry MUST NOT appear; it appears only when the flag is on.

## Test Plan

- PHPUnit: aggregation filters produce correct NIVRA structure.
- Integration: docudesk templates render correctly.
- Playwright: manifest navigation appears per flag.
