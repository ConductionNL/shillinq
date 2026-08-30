# Spec: bookkeeping-emu-reporting

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (NL gov sector)
**Depends on:** bookkeeping-bbv-compliance, bookkeeping-iv3-reporting

## ADDED Requirements

### Requirement: REQ-EMU-001: The system SHALL compute EMU-saldo and EMU-schuld per ESA 2010 conventions

EMU-saldo (vorderingsoverschot / -tekort) and EMU-schuld MUST be
computed per the ESA 2010 (European System of Accounts)
conventions, as adapted for Dutch decentrale overheden via the BBV
handleiding EMU-saldo. The computation MUST operate over posted GL
data filtered + categorised by ESA-2010 sector classifier (per
REQ-EMU-002). Per ADR-031, the computation MUST be declared as
`x-openregister-aggregations` + `x-openregister-calculations` blocks
— no PHP EMU-service. (If the engine cannot express the multi-
sector filter declaratively, ADR-031 §"PHP guards remain a
legitimate seam" applies; the spec is shape-neutral.)

#### Scenario: Reviewer confirms no parallel EMU computation

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/Emu*` classes
- **THEN** no such classes SHALL exist; EMU computation MUST flow
  through the declarative aggregation/calculation engine (with at
  most one ~20-LOC ADR-031 exception guard if engine-limited).

### Requirement: REQ-EMU-002: The system SHALL declare an ESA-2010 sector classifier on every Account

The `Account` schema MUST gain an optional `esaClassifier` enum
field with values `S.1311 | S.1312 | S.1313 | S.1314 | S.11 |
S.12 | S.13 | S.14 | S.15 | S.2` (the canonical ESA 2010 sector
codes — `S.1311` = centrale overheid, `S.1312` = deelstaatoverheid
(N/A in NL), `S.1313` = lokale overheid (gemeente / provincie /
waterschap), `S.1314` = wettelijke sociale verzekering). The
classifier MUST drive the EMU-saldo computation's sector
filtering. A seed file
`lib/Settings/seeds/esa-2010-classifier.json` MUST ship the
classifier list with EUPL-1.2 SPDX + `_meta` block.

#### Scenario: An ESA-classified account contributes to the right sector

- **GIVEN** an account with `esaClassifier: 'S.1313'` (lokale
  overheid)
- **WHEN** the EMU-saldo aggregation runs for that account's
  postings
- **THEN** the postings MUST contribute to the `S.1313` sector
  rollup, not to `S.1311` or `S.1314`.

### Requirement: REQ-EMU-003: The system SHALL produce EMU-saldo quarterly via IV3 and annually via jaarrekening

Two reporting cadences MUST be supported:

1. **Quarterly** — EMU-saldo derived from the same data feeding
   the IV3-bestand for the quarter. Output rides the CBS EMU-
   bestand from `bookkeeping-cbs-bestanden-extended` REQ-CBSE-005.
2. **Annual** — EMU-saldo en EMU-schuld from the closed
   jaarrekening (per `bookkeeping-financial-statements`).

Both MUST be declarative aggregations. The aggregation MUST
clearly cite which periods + sectors contribute, so the auditor
can reproduce the computation manually.

#### Scenario: Quarterly EMU matches IV3-base quarterly totals

- **GIVEN** a closed quarter with IV3-base totals + EMU
  classification applied
- **WHEN** the quarterly EMU aggregation runs
- **THEN** the implied EMU-saldo MUST be reproducible by hand
  from the IV3-base + ESA classifier per account.

### Requirement: REQ-EMU-004: Per-sector EMU inclusion/exclusion rules SHALL be declared as calculation metadata, not hard-coded

The BBV handleiding EMU-saldo includes per-sector
inclusion/exclusion rules (e.g. de verontreinigingsheffing
aanslag-vorming voor waterschappen, certain reserve-mutaties).
These rules MUST be declared as schema metadata
(`emuInclusionRule: 'included' | 'excluded' | 'partial'`) on the
relevant postings/heffingen — e.g. `WaterschapHeffingPosting` per
REQ-WSB-005. Defaults MUST match the 2026 BBV handleiding.

#### Scenario: Excluded posting does not contribute to EMU-saldo

- **GIVEN** a waterschap administration with the BBVW default
  exclusion on verontreinigingsheffing applied
- **WHEN** the EMU-saldo aggregation runs for a period containing
  €5 000 aan verontreinigingsheffing
- **THEN** the €5 000 MUST NOT contribute to the EMU-saldo total;
  **AND** the calculation metadata MUST surface this exclusion in
  the audit-trail comment for that aggregation run.

### Requirement: REQ-EMU-005: EMU computation results SHALL be reproducible from the audit-trail

For audit purposes, every EMU computation run MUST be tied to:

- The set of `GLLine` rows that contributed (via a stable
  aggregation hash).
- The ESA classifier state at computation time.
- The exclusion-rule metadata applied.

Re-running the EMU computation against the same period MUST
produce identical output as long as the input postings + classifier
+ exclusion rules are unchanged.

#### Scenario: Two runs of EMU on the same closed period agree

- **GIVEN** a closed period with EMU computed at `saldo: €X`
- **WHEN** the EMU computation is re-run a week later without
  any data changes
- **THEN** the produced `saldo` MUST equal `€X` exact.

### Requirement: REQ-EMU-006: EMU reporting SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.gov-emu`) under `Bookkeeping > EMU-rapportage`
with `type: index` listing historical runs + `type: detail` for
each run (showing inputs, classifier state, exclusion metadata,
en de eindcijfers). Per ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: EMU menu toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.gov-emu`
- **WHEN** the flag is ON
- **THEN** the EMU-rapportage menu entry MUST appear.
- **WHEN** the flag is OFF
- **THEN** the entry MUST NOT render.
