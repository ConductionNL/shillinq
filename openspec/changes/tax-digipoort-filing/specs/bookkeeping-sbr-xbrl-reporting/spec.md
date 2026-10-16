# bookkeeping-sbr-xbrl-reporting Specification (delta)

## Purpose

Filings are built as XBRL instances, validated, handed to integriq's
Digipoort connector, and follow Digipoort's answer; the annual accounts get
their SBR generator. From shillinq matrix rows `tax-sbr` and `tax-vat-file`.

## ADDED Requirements

### Requirement: Annual accounts produce their KvK XBRL instance (REQ-TDF-002)

The `sbr-xbrl` report SHALL produce an `XbrlInstance` from a posted
financial statement, mapping its figures through the XBRL mappings onto the
KvK entry point for the statement's size class, with the XML and its hash
stored and downloadable from the SBR filings page.

#### Scenario: A controller generates the SBR annual accounts

- GIVEN the posted 2025 financial statement of Voorbeeld Bouw B.V., size class small
- WHEN the controller generates the report SBR/XBRL-deponering
- THEN the SBR filings page lists a draft instance for 2025 with filing type kvk-deponering and a downloadable XBRL file

### Requirement: An instance is validated against its entry point before submission (REQ-TDF-003)

The `validate` transition of `XbrlInstance` SHALL be refused, naming each
failing item, when the instance lacks a required concept of its entry
point, its period or identifier is missing, its totals do not add up, or no
taxonomy record is effective for its period.

#### Scenario: A missing required fact blocks validation

- GIVEN a draft annual accounts instance without the concept for equity
- WHEN the controller presses Validate
- THEN validation is refused naming the missing equity concept

### Requirement: Submission hands the instance to integriq and follows its reports (REQ-TDF-004)

The `submit` transition of `XbrlInstance` SHALL store the instance and hand
it to integriq's connector for the `digipoort-sbr` source, with no network
call from shillinq. The instance SHALL move to submitted only on
integriq's delivery report, recording the Digipoort reference, and to
accepted or rejected on the recipient's outcome, recording the errors on
rejection. Without integriq's connector the transition MUST be refused, and
the `digipoort-sbr` connection SHALL report its state daily instead of
being declared not available.

#### Scenario: A rejected filing shows why

- GIVEN a submitted VAT instance
- WHEN integriq reports the Belastingdienst's outcome as rejected with an error code and its text
- THEN the SBR filing page shows state rejected with that code and text
- AND the instance can be reopened and corrected

#### Scenario: The external connections page tells the truth

- GIVEN integriq installed without a Digipoort connector bound
- WHEN an administrator reads the Digipoort SBR row on the external connections page
- THEN it reads simulated, not configured
