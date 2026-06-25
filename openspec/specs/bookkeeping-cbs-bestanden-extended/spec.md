---
status: done
---

# Spec: Bookkeeping — CBS-Bestanden Extended

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-iv3-reporting  
**Kind:** config

## Purpose

Add extended CBS (Centraal Bureau voor de Statistiek) statistical file exports beyond the baseline IV3: Iv3-detail, Kerngegevens jaarstaten, Iv3-OZB, EMU-bestand, periodieke statistiekleveringen. Each is a transformation aggregation + docudesk template + openconnector source row.

## Requirements

@e2e exclude pure backend/compliance: CBS data export — not browser-testable


### REQ-CBSE-001: Iv3-detail aggregation

SHALL provide an `x-openregister-aggregations` declaration for Iv3-detail (detailed GL data per programma).

#### Scenario: Iv3-detail aggregation declared

- **GIVEN** the CBS extended export configuration
- **WHEN** Iv3-detail is requested
- **THEN** an `x-openregister-aggregations` declaration produces detailed GL data aggregated per programma

### REQ-CBSE-002: Kerngegevens export

SHALL declare Kerngegevens jaarstaten aggregation and docudesk template.

#### Scenario: Kerngegevens jaarstaten export available

- **GIVEN** the CBS extended export configuration
- **WHEN** the Kerngegevens jaarstaten export is generated
- **THEN** the declared aggregation feeds its docudesk template to render the Kerngegevens jaarstaten output

### REQ-CBSE-003: Iv3-OZB export

SHALL declare Iv3-OZB (property tax reporting) transformation per CBS schema.

#### Scenario: Iv3-OZB transformation conforms to CBS schema

- **GIVEN** the CBS extended export configuration
- **WHEN** the Iv3-OZB property tax report is produced
- **THEN** the declared transformation outputs data conforming to the CBS Iv3-OZB schema

### REQ-CBSE-004: EMU-bestand export

SHALL declare EMU-bestand aggregation (foreign exchange balance per ESA 2010).

#### Scenario: EMU-bestand aggregation declared per ESA 2010

- **GIVEN** the CBS extended export configuration
- **WHEN** the EMU-bestand export is generated
- **THEN** the declared aggregation produces the foreign exchange balance computed per ESA 2010

### REQ-CBSE-005: Periodieke statistiekleveringen

SHALL declare scheduled quarterly/annual stat submission via openconnector to CBS endpoint.

#### Scenario: Scheduled statistic submission to CBS

- **GIVEN** an openconnector source configured for the CBS endpoint
- **WHEN** a quarterly or annual submission schedule fires
- **THEN** the periodic statistic delivery is submitted to the CBS endpoint via openconnector

## Test Plan

- PHPUnit: aggregations produce correct CBS structure.
- Integration: docudesk templates render; openconnector submission succeeds.
