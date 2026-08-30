# Spec: bookkeeping-rekenkamer-audit-pack

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (NL gov sector)
**Depends on:** bookkeeping-audit-trail, bookkeeping-financial-statements

## ADDED Requirements

### Requirement: REQ-REK-001 — The audit pack SHALL be a presentation manifest on top of the existing audit-trail surface — no new audit register

The rekenkamer + accountantscontrole audit-pack MUST NOT introduce a parallel audit register. Per ADR-022, the OR audit-trail-immutable abstraction already provides the hash-chained, append-only event log. The audit-pack MUST be expressed as:

1. Aggregation declarations (`x-openregister-aggregations`) that
   project the audit-trail into the required output shapes.
2. Docudesk template references that render each output.
3. Openconnector source rows for any external submission targets
   (e.g. de accountant zijn audit-portal).

#### Scenario: Reviewer confirms no parallel audit storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `audit_`,
  `rekenkamer_`, or `nivra_`
- **THEN** no such classes SHALL exist; all audit-pack outputs
  MUST flow through OR's audit-trail-immutable + aggregation
  surface.

### Requirement: REQ-REK-002 — The system SHALL produce a NIVRA-bestand audit-trail export in the standardised format

A NIVRA-bestand (the Dutch accountancy profession's standardised audit-file format) MUST be exportable for a given period. The export MUST contain: every transaction in the period (header + lines), every audit event for those transactions, the period's trial balance, and the chart of accounts in effect. Output format MUST conform to the NIVRA standard (XML or specified CSV) per the controleprotocol referenced in `_meta.standardVersion`. Export generation MUST be a declarative aggregation + docudesk template — no PHP audit-export service.

#### Scenario: NIVRA-bestand for a closed period parses against the standard

- **GIVEN** a closed fiscal period with N postings and audit events
- **WHEN** an operator triggers the NIVRA-bestand export
- **THEN** the resulting file MUST parse against the NIVRA XML
  Schema (xsd) declared in `_meta.standardVersion`; **AND** the
  posting count + audit event count MUST match the period's
  totals.

### Requirement: REQ-REK-003 — The system SHALL support reproducible steekproef sampling within a period

For substantive testing, the audit-pack MUST expose a `steekproef` aggregation that, given a `periodId`, `sampleSize`, and a `seed`, returns a deterministic random sample of `GLTransaction` records from the period. Reproducibility MUST be guaranteed — re-running with the same seed MUST yield the same sample. The sample MUST be exportable as a docudesk werkpapier.

#### Scenario: Same seed produces the same sample

- **GIVEN** a closed period with 1 000 postings
- **WHEN** the steekproef aggregation runs twice with
  `sampleSize: 30, seed: 'audit-2026-q1'`
- **THEN** both runs MUST return the same 30 `GLTransaction` ids,
  in the same order.

### Requirement: REQ-REK-004 — The system SHALL produce a ledenraadpleging-export with personally-identifying data redacted per the audit-pack profile

For raadsleden review, a redacted slice of the period's postings MUST be exportable. The redaction profile MUST be declared as metadata on the `audit-pack` aggregation: fields tagged with `redactFor: ['raadsleden']` MUST be replaced by a stable hash in the export. Default redactions MUST include `description`-level free-text fields that may carry personal data + sub-ledger refs into AP / AR.

#### Scenario: Redaction profile removes personal data

- **GIVEN** an audit-pack aggregation with the `raadsleden`
  redaction profile active
- **WHEN** a posting with a free-text `description: 'Salaris Jan
  Bakker maart 2026'` is exported
- **THEN** the `description` field MUST be replaced by a stable
  hash (or by a placeholder like `[REDACTED]`); **AND** the
  numeric and account-code fields MUST remain intact.

### Requirement: REQ-REK-005 — Every audit-pack export SHALL itself be recorded as an immutable audit event

Producing an audit-pack output (NIVRA, steekproef, ledenraadpleging) MUST itself write an audit event to the OR audit-trail-immutable log, including: the operator id, the output type, the period id, the document URI (the docudesk attachment), and the SHA-256 hash of the produced document. This MUST be enforced by OR's audit engine — not by app-local logging.

#### Scenario: NIVRA export logs an audit event

- **GIVEN** an operator triggers a NIVRA-bestand export
- **WHEN** the export completes
- **THEN** een nieuw immutable audit event MUST appear in the
  audit-trail with the event type `audit-pack.nivra.exported`,
  the operator id, en de SHA-256 hash van het bestand.

### Requirement: REQ-REK-006 — The audit pack SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu entry (`featureFlags.gov-rekenkamer`) under `Bookkeeping > Audit pack` with sub-pages for NIVRA export, steekproef, and ledenraadpleging-export. Per ADR-024 Tier-4, no bespoke Vue files — pages render via generic `CnIndexPage` / `CnDetailPage` driven by the manifest.

#### Scenario: Audit-pack menu toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.gov-rekenkamer`
- **WHEN** the flag is ON
- **THEN** the three audit-pack sub-pages MUST appear under
  Bookkeeping > Audit pack.
- **WHEN** the flag is OFF
- **THEN** the audit-pack menu MUST NOT render.
