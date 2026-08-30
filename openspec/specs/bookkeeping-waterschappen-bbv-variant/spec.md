---
status: done
---

# Spec: bookkeeping-waterschappen-bbv-variant

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (NL gov sector)
**Depends on:** bookkeeping-bbv-compliance

## Purpose

This specification defines the requirements for bookkeeping waterschappen bbv variant in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: waterschappen BBV variant — not browser-testable


### REQ-WSB-001: The system SHALL support a `bbvVariant: 'waterschap'` overlay on the existing BBV-compliance register

The system SHALL satisfy this requirement: The system SHALL support a `bbvVariant: 'waterschap'` overlay on the existing BBV-compliance register.

The `Account`, `BBVProgramma`, and any other schemas declared by the
T3 `bookkeeping-bbv-compliance` spec MUST gain an optional
`bbvVariant` field (enum `gemeente | waterschap | provincie`,
default `gemeente`). When `bbvVariant: waterschap` is set, the
records MUST be interpreted under the BBVW (BBV-Waterschappen)
regulatory framework. Variants MUST be schema overlays per ADR-031
— no parallel `WaterschapAccount` register or PHP service class
(per ADR-022 anti-pattern list).

#### Scenario: A waterschap administration is provisioned with the variant flag

- **GIVEN** a fresh shillinq install
- **WHEN** the operator configures the administration as a
  `waterschap` via the manifest sector switch
- **THEN** every newly seeded `Account` and `BBVProgramma` record
  MUST carry `bbvVariant: 'waterschap'`; **AND** existing
  gemeente-seeded records in the same install MUST remain unaffected.

#### Scenario: An unknown bbvVariant is rejected

- **GIVEN** the schema is loaded
- **WHEN** an `Account` with `bbvVariant: 'rijksoverheid'` is saved
- **THEN** the save MUST fail with an enum-violation error.

### REQ-WSB-002: The system SHALL declare the BBVW programma-indeling per `kostentoedeling` rather than per `taakveld`

The system SHALL satisfy this requirement: The system SHALL declare the BBVW programma-indeling per `kostentoedeling` rather than per `taakveld`.

BBVW groups postings by `kostentoedeling` (the BBVW handleiding's
canonical cost-attribution buckets — watersysteembeheer,
zuiveringsbeheer, etc.) instead of the gemeente `taakveld` shape.
The `BBVProgramma` schema MUST gain a `programmaStructure` enum
field (`taakveld | kostentoedeling`) discriminating the
classification used per record. Aggregations rolled up to the
programma level MUST honour the discriminator.

#### Scenario: A waterschap posting rolls up by kostentoedeling

- **GIVEN** a waterschap administration with the BBVW programma
  seed loaded
- **WHEN** a `GLLine` is posted with `programmaCode: 'watersysteem-
  beheer'`
- **THEN** the programma-level aggregation MUST roll up under
  the `kostentoedeling` structure (not the `taakveld` structure),
  visible in the IV3-extraction surface.

### REQ-WSB-003: The system SHALL ship a BBV-Waterschappen programma seed (`bbv-waterschappen-programmas-2026.json`)

The seed file MUST live at
`lib/Settings/seeds/bbv-waterschappen-programmas-2026.json`, MUST
carry an SPDX header (EUPL-1.2 + Copyright Conduction B.V.) inside
the docblock per `feedback_spdx-in-docblock.md`, MUST carry an
`_meta` block (`source: 'BBVW handleiding'`, `year: 2026`), and
MUST contain the canonical BBVW kostentoedeling cluster headers
(watersysteembeheer, zuiveringsbeheer, wegenbeheer,
muskusratbestrijding, etc.). Seed loading MUST be idempotent on
repair-step re-run per the T1 pattern (REQ-CoA-007).

#### Scenario: Seed file validates and seeds idempotently

- **GIVEN** a fresh waterschap install
- **WHEN** the repair step runs
- **THEN** the BBVW programma records appear in the `BBVProgramma`
  register; **AND** re-running the repair step MUST NOT duplicate
  records or overwrite operator edits.

### REQ-WSB-004: The system SHALL declare a `WaterschapHeffingPosting` register for the three sector-specific belastingen

The system SHALL satisfy this requirement: The system SHALL declare a `WaterschapHeffingPosting` register for the three sector-specific belastingen.

Waterschapsbelastingen consist of three distinct heffingen:
`watersysteemheffing`, `zuiveringsheffing`, and
`verontreinigingsheffing`. Each MUST be expressible as a posting
type via a `WaterschapHeffingPosting` register with fields:
`heffingType` (enum), `aanslagJaar` (integer),
`tariefGrondslag` (string — the canonical grondslag e.g.
"vervuilingseenheden"), `tarief` (number ≥ 0), `aanslagBedrag`
(number ≥ 0), `journalEntryId` (FK to the materialised journal).
The register MUST NOT carry its own ledger lines — the heffing
materialises a regular balanced `GLTransaction` per T1
REQ-GL-001.

#### Scenario: A zuiveringsheffing aanslag materialises a balanced journal entry

- **GIVEN** the waterschap administration with the heffing-posting
  schema declared
- **WHEN** a `WaterschapHeffingPosting` with
  `heffingType: 'zuiveringsheffing'`, `aanslagBedrag: 1500.00` is
  saved with `state: 'posted'`
- **THEN** a `JournalEntry` of subtype `manual` MUST be
  materialised with a balanced 2-line GLTransaction; **AND** the
  journal MUST reference the heffing-posting via
  `sourceReference`.

### REQ-WSB-005: The system SHALL apply the BBVW-specific EMU-saldo exclusion rules in the EMU computation

The system SHALL satisfy this requirement: The system SHALL apply the BBVW-specific EMU-saldo exclusion rules in the EMU computation.

Per the EMU-bijlage waterschappen handleiding, certain heffingen
and reserveringen are **excluded** from the waterschap EMU-saldo
(e.g. de verontreinigingsheffing aanslag-vorming wordt op andere
wijze meegenomen). The EMU calculation declared in
`bookkeeping-emu-reporting` MUST honour an
`emuExclusionRule` field on `WaterschapHeffingPosting` that excludes
or includes specific heffingen from the EMU rollup. Default values
MUST match the 2026 BBVW handleiding.

#### Scenario: Excluded heffing does not contribute to EMU-saldo

- **GIVEN** a waterschap administration with
  `WaterschapHeffingPosting` records carrying
  `emuExclusionRule: 'excluded'` on the verontreinigingsheffing
  records per the BBVW handleiding
- **WHEN** the EMU-saldo aggregation runs for the period
- **THEN** the excluded postings MUST NOT contribute to the
  computed saldo; **AND** an audit trail entry MUST record the
  exclusion rationale.

### REQ-WSB-006: Waterschappen sector view SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.gov-waterschap`) under
`Bookkeeping > Waterschapsbelastingen` with a `type: index` page
binding to the `WaterschapHeffingPosting` register and a
`type: detail` page rendering the heffing fields + the materialised
journal-entry link. Per ADR-024 Tier-4, no bespoke Vue files —
rendering uses generic `CnIndexPage` / `CnDetailPage`.

#### Scenario: Feature flag toggles visibility

- **GIVEN** the manifest declares `featureFlags.gov-waterschap`
- **WHEN** the feature flag is OFF
- **THEN** the Waterschapsbelastingen menu entry MUST NOT render.
- **WHEN** the flag is ON
- **THEN** the entry MUST appear under Bookkeeping.
