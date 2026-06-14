# Spec: Bookkeeping — CBS-Bestanden Extended

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-iv3-reporting  
**Kind:** config

## Summary

Add extended CBS (Centraal Bureau voor de Statistiek) statistical file exports beyond the baseline IV3: Iv3-detail, Kerngegevens jaarstaten, Iv3-OZB, EMU-bestand, periodieke statistiekleveringen. Each is a transformation aggregation + docudesk template + openconnector source row.

## ADDED Requirements

### Requirement: REQ-CBSE-001 — The system SHALL provide an Iv3-detail aggregation

The system SHALL provide an `x-openregister-aggregations` declaration for Iv3-detail (detailed GL data per programma), reusing the baseline IV3 aggregation engine rather than a parallel PHP transformer.

#### Scenario: Iv3-detail aggregation rolls up GL lines per programma

- **GIVEN** posted GL lines tagged with a BBV programma code
- **WHEN** the Iv3-detail aggregation runs for a reporting period
- **THEN** the result MUST present one detail row per programma with debit/credit subtotals matching the underlying GL.

### Requirement: REQ-CBSE-002 — The system SHALL declare a Kerngegevens export

The system SHALL declare a Kerngegevens jaarstaten aggregation and a docudesk template reference for rendering.

#### Scenario: Kerngegevens export references a docudesk template

- **GIVEN** the CBS-bestanden-extended manifest entry is enabled
- **WHEN** the Kerngegevens export is requested
- **THEN** the aggregation output MUST bind to a resolvable docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-CBSE-003 — The system SHALL declare an Iv3-OZB export

The system SHALL declare an Iv3-OZB (property tax reporting) transformation per the CBS schema.

#### Scenario: Iv3-OZB transformation matches the CBS column layout

- **GIVEN** OZB-classified GL postings
- **WHEN** the Iv3-OZB transformation runs
- **THEN** the produced file MUST conform to the CBS Iv3-OZB column layout for the reporting year.

### Requirement: REQ-CBSE-004 — The system SHALL declare an EMU-bestand export

The system SHALL declare an EMU-bestand aggregation (balance per ESA 2010) that consumes the EMU-reporting ESA classifier overlay.

#### Scenario: EMU-bestand reuses the ESA-2010 classifier

- **GIVEN** accounts carrying an `esaClassifier` value from the emu-reporting spec
- **WHEN** the EMU-bestand aggregation runs
- **THEN** balances MUST be grouped by ESA-2010 classification without a second classifier definition.

### Requirement: REQ-CBSE-005 — The system SHALL declare periodieke statistiekleveringen

The system SHALL declare a scheduled quarterly/annual statistics submission via openconnector to the CBS endpoint, with the schedule expressed declaratively (no app-local cron).

#### Scenario: Periodic delivery is declared as an openconnector source

- **GIVEN** the CBS-bestanden-extended configuration
- **WHEN** the periodic delivery is inspected
- **THEN** it MUST resolve to an openconnector source row targeting the CBS endpoint, not a shillinq-local HTTP client.

## Test Plan

- PHPUnit: aggregations produce the correct CBS structure.
- Integration: docudesk templates render; openconnector submission succeeds.
