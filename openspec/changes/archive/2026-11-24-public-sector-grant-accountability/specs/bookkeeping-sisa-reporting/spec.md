# bookkeeping-sisa-reporting Specification (delta)

## Purpose

The SiSa appendix is filled from the books. From shillinq matrix rows `pub-subsidies`, `pub-sisa`, `pub-eu-funds`.

## ADDED Requirements

### Requirement: Indicators are computed per specific grant (REQ-SISA-012)

The app SHALL compute, per specific grant received and year, the spending and income indicators from the posted lines tagged with the grant, and SHALL keep output indicators as entered.

#### Scenario: A controller prepares the SiSa appendix

- GIVEN a specific grant with regulation code D8 and EUR 120,000 of posted spending in 2026
- WHEN the controller opens the SiSa appendix for 2026
- THEN the spending indicator for D8 reads EUR 120,000
- AND output indicators without a value are marked as missing

### Requirement: The appendix is exported for the annual accounts (REQ-SISA-013)

The app SHALL export the appendix as a table in the column order of the BZK template.

#### Scenario: A controller exports the appendix

- GIVEN a complete appendix for 2026
- WHEN the controller chooses export
- THEN a CSV downloads with one row per indicator in the BZK column order
