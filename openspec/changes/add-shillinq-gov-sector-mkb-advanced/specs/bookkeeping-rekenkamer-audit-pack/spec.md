# Spec: Bookkeeping — Rekenkamer Audit Pack

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-audit-trail, bookkeeping-financial-statements  
**Kind:** config

## Summary

Provide audit-ready exports from the existing audit-trail (per ADR-022) in NIVRA-bestand format (standardised audit file for Dutch accountancy), with steekproef (sampling) and ledenraadpleging-export (raad member view) as presentation manifests only—no new audit register.

## Requirements

### REQ-REK-001: NIVRA-bestand export

SHALL declare an `x-openregister-aggregations` view transforming audit-trail data into NIVRA-bestand format for accountants and rekenkamer.

#### Scenario: NIVRA export generated

GIVEN an audit-trail with 2000+ postings  
WHEN NIVRA-bestand is requested for Q4 2025  
THEN a CSV/XML export in NIVRA standard format is produced.

### REQ-REK-002: Steekproef (sampling) view

SHALL provide a random sampling aggregation allowing selection of N postings within a period for substantive testing.

#### Scenario: Steekproef sample extracted

GIVEN 1500 postings in a period  
WHEN auditor requests a 10% steekproef sample  
THEN 150 postings are randomly selected for review.

### REQ-REK-003: Ledenraadpleging-export

SHALL declare a redacted audit-trail view for raadsleden (council members) excluding sensitive details.

#### Scenario: Council member export redacted

GIVEN full audit-trail with personnel records  
WHEN ledenraadpleging-export is generated  
THEN personnel references are redacted; financial summary remains.

### REQ-REK-004: Docudesk template references

SHALL reference three docudesk templates (NIVRA-bestand, steekproef-list, ledenraadpleging-rapport) for document rendering and signing.

### REQ-REK-005: Manifest navigation entry

SHALL add a feature-flag-controlled navigation entry under `featureFlags.gov-rekenkamer` for audit-pack exports.

## Test Plan

- PHPUnit: aggregation filters produce correct NIVRA structure.
- Integration: docudesk templates render correctly.
- Playwright: manifest navigation appears per flag.
