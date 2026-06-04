# Spec: Bookkeeping — CBS-Bestanden Extended

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-iv3-reporting  
**Kind:** config

## Summary

Add extended CBS (Centraal Bureau voor de Statistiek) statistical file exports beyond the baseline IV3: Iv3-detail, Kerngegevens jaarstaten, Iv3-OZB, EMU-bestand, periodieke statistiekleveringen. Each is a transformation aggregation + docudesk template + openconnector source row.

## Requirements

@e2e exclude pure backend/compliance: CBS data export — not browser-testable


### REQ-CBSE-001: Iv3-detail aggregation

SHALL provide an `x-openregister-aggregations` declaration for Iv3-detail (detailed GL data per programma).

### REQ-CBSE-002: Kerngegevens export

SHALL declare Kerngegevens jaarstaten aggregation and docudesk template.

### REQ-CBSE-003: Iv3-OZB export

SHALL declare Iv3-OZB (property tax reporting) transformation per CBS schema.

### REQ-CBSE-004: EMU-bestand export

SHALL declare EMU-bestand aggregation (foreign exchange balance per ESA 2010).

### REQ-CBSE-005: Periodieke statistiekleveringen

SHALL declare scheduled quarterly/annual stat submission via openconnector to CBS endpoint.

## Test Plan

- PHPUnit: aggregations produce correct CBS structure.
- Integration: docudesk templates render; openconnector submission succeeds.
