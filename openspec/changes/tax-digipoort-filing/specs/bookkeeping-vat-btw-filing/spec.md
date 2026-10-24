# bookkeeping-vat-btw-filing Specification (delta)

## Purpose

A VAT return is filed with the Belastingdienst through its XBRL instance,
and shows filed only when Digipoort accepted it. From shillinq matrix row
`tax-vat-file`.

## ADDED Requirements

### Requirement: A VAT return is filed through its XBRL instance (REQ-TDF-001)

Pressing Submit on a prepared VAT return SHALL build its XBRL instance on
the Belastingdienst OB entry point valid for the period, validate it, and
hand it to the filing connection. The return SHALL show submitted, with
the Digipoort delivery reference, only when the connection reports the
delivery; it SHALL show accepted or rejected, with the error codes, when
the Belastingdienst's outcome is reported. When no filing connection is
configured, Submit MUST be refused with that reason and the return MUST
stay in draft.

#### Scenario: A bookkeeper files the Q3 return

- GIVEN the prepared and checked Q3 2026 return of Bakkerij De Korenbloem and a configured filing connection
- WHEN the bookkeeper presses Submit on the return page
- THEN the return shows submitted with a Digipoort delivery reference once integriq reports the delivery
- AND shows accepted after the Belastingdienst's outcome arrives

#### Scenario: Without a filing connection nothing is claimed

- GIVEN an instance without integriq's Digipoort connector
- WHEN the bookkeeper presses Submit on a prepared return
- THEN the refusal says SBR filing is not configured
- AND the return stays in draft and its XBRL file can be downloaded
