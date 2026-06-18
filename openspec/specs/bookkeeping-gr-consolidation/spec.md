---
status: done
---

# Spec: bookkeeping-gr-consolidation

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (NL gov sector)
**Depends on:** bookkeeping-bbv-compliance, bookkeeping-financial-statements

## Purpose

This specification defines the requirements for bookkeeping gr consolidation in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: GR consolidation — not browser-testable


### REQ-GRC-001: The system SHALL declare a `GRDeelnemer` register identifying each deelnemer of a gemeenschappelijke regeling

The `GRDeelnemer` register MUST declare one record per deelnemer of
the GR with fields: `deelnemerType` (enum
`gemeente | provincie | waterschap`), `deelnemerNaam` (string),
`administrationId` (FK to the deelnemer's own shillinq
administration, optional — many deelnemers will not run shillinq),
`aandeel` (number 0 ≤ x ≤ 1; the deelnemer's quotum-aandeel in de
GR), `actief` (boolean, default true). Per ADR-022 / ADR-024 —
declarative register, no PHP model class.

#### Scenario: Adding a deelnemer with quotum-aandeel

- **GIVEN** a GR administration
- **WHEN** a `GRDeelnemer` with `deelnemerNaam: 'Gemeente
  Voorbeeld'`, `aandeel: 0.40` is created
- **THEN** the save MUST succeed; **AND** the deelnemer MUST appear
  in the consolidated view.

#### Scenario: Aandeel out of range is rejected

- **GIVEN** the schema
- **WHEN** a `GRDeelnemer` with `aandeel: 1.20` is saved
- **THEN** the save MUST fail with a validation error.

### REQ-GRC-002: The system SHALL declare a `GRVerdeelsleutel` register parameterising per-cost-cluster apportionment

A `GRVerdeelsleutel` record MUST declare a named apportionment rule
with fields: `sleutelNaam` (string), `costClusterAccountNumbers`
(array of `Account.accountNumber` strings — the cost cluster the
sleutel applies to), `verdelingsType` (enum
`vast-percentage | inwoner-aantal | gewogen-oppervlak |
custom-formula`), `parameters` (free-form JSON validated against
the chosen `verdelingsType`). Multiple sleutels MAY apply to the
same cost cluster, sequenced by `lineNumber`.

#### Scenario: A custom verdeelsleutel apportions an indirect cost cluster

- **GIVEN** a GR with three deelnemers (aandelen 0.4 / 0.4 / 0.2)
- **AND** a `GRVerdeelsleutel` with `verdelingsType: 'vast-
  percentage'` and `parameters: { "deelnemer-1": 0.4, "deelnemer-2":
  0.4, "deelnemer-3": 0.2 }` applied to cost cluster `41xxx`
- **WHEN** €100 000 is posted against the `41xxx` cluster
- **THEN** the per-deelnemer doorbelasting aggregation MUST produce
  €40 000, €40 000, €20 000 splits.

### REQ-GRC-003: `GLLine` SHALL carry an `eliminationFlag` so inter-GR transactions can be excluded from consolidated views

`GLLine` MUST gain an optional `eliminationFlag: boolean` field
(default `false`). Lines flagged true MUST be excluded from
consolidated trial-balance and financial-statement aggregations
(per `bookkeeping-financial-statements` rollups). The exclusion
MUST be expressed as an `x-openregister-aggregations.filter`
clause (`WHERE eliminationFlag = false`), not as application-side
filtering.

#### Scenario: Eliminated line is excluded from consolidated trial balance

- **GIVEN** a GL line with `eliminationFlag: true` posted to an
  inter-GR account
- **WHEN** the consolidated trial-balance aggregation is queried
- **THEN** the line MUST NOT appear in the rollup; **AND** the
  per-deelnemer (un-eliminated) view MUST still surface it.

### REQ-GRC-004: The system SHALL produce a separate GR jaarrekening AND per-deelnemer doorbelasting via declarative aggregation

Two aggregations MUST be declared:

1. The **GR's own jaarrekening** — standard
   `bookkeeping-financial-statements` rollup, filtered to all
   lines in the GR administration.
2. The **per-deelnemer doorbelasting** — aggregation grouped by
   the resolved deelnemer per applicable `GRVerdeelsleutel`,
   producing a doorbelasting bedrag per deelnemer per cost cluster.

Both MUST be expressible as `x-openregister-aggregations` blocks
on the GL data; per ADR-031, no PHP consolidation service.

#### Scenario: Doorbelasting view sums to the GR's eigen kosten

- **GIVEN** a posted period with €1 000 000 aan toe te rekenen
  kosten in the GR
- **WHEN** the per-deelnemer doorbelasting aggregation runs
- **THEN** the sum across alle deelnemers MUST equal €1 000 000
  (tolerance: €0); **AND** the per-deelnemer waarden MUST match the
  expected aandeel-gewogen som.

### REQ-GRC-005: A doorbelasting SHALL materialise as a balanced `GLTransaction` in the deelnemer's administration when the deelnemer also runs shillinq

The system SHALL satisfy this requirement: A doorbelasting SHALL materialise as a balanced `GLTransaction` in the deelnemer's administration when the deelnemer also runs shillinq.

When a `GRDeelnemer` record has `administrationId` set (i.e. de
deelnemer draait shillinq), the period-end doorbelasting MUST
materialise a balanced `GLTransaction` in that deelnemer's
administratie, with `sourceReference` pointing at the GR's
doorbelasting-rapport (a docudesk attachment URI). The
materialisation MUST be triggered by the GR's period-close
lifecycle transition (per T3 `bookkeeping-period-close`).

#### Scenario: Cross-administration doorbelasting posts on GR period close

- **GIVEN** a GR met deelnemer `Gemeente Voorbeeld` met
  `administrationId: 'adm-voorbeeld'`
- **WHEN** the GR period 2026-Q1 is closed
- **THEN** een balanced `GLTransaction` MUST appear in
  `adm-voorbeeld` met de doorbelasting bedrag; **AND** the GR-side
  audit trail MUST log the materialisation.

### REQ-GRC-006: GR sector view SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.gov-gr`) under
`Bookkeeping > Gemeenschappelijke regeling` met `type: index` +
`type: detail` pages binding to `GRDeelnemer` en `GRVerdeelsleutel`,
plus een derde page voor de geconsolideerde view. Per ADR-024 Tier-4,
no bespoke Vue files.

#### Scenario: GR menu entries toggle with the feature flag

- **GIVEN** the manifest declares `featureFlags.gov-gr`
- **WHEN** the flag is OFF
- **THEN** the GR menu entries MUST NOT render.
- **WHEN** the flag is ON
- **THEN** Deelnemers, Verdeelsleutels, en de Consolidated view
  MUST be available onder Bookkeeping.
