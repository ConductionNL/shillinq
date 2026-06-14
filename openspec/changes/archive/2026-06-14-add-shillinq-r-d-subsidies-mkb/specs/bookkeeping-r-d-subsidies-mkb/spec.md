# Spec: bookkeeping-r-d-subsidies-mkb

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation)
**Depends on:** bookkeeping-subsidie-verantwoording

## ADDED Requirements

### Requirement: REQ-RDS-001: The system SHALL declare a `subsidieRegeling` enum on the existing Subsidie register for the R&D regelingen

The T3 `Subsidie` register (from
`bookkeeping-subsidie-verantwoording`) MUST gain a
`subsidieRegeling` enum field with values `mit | sbir |
eu-horizon | efro | react-eu | other`. The `Subsidie` register
SHOULD carry a schema.org type of `schema:Grant` (a
`SubsidieProject` materialisation is exposed as
`schema:ResearchProject` where the subsidie funds R&D work).
Per ADR-031 + ADR-022 this is a schema overlay — no parallel
`RDSubsidie` register. When `subsidieRegeling ≠ 'other'`, the
regeling-specific kostencategorieën constraint (REQ-RDS-002)
MUST apply.

#### Scenario: An EU-Horizon subsidie gets the regeling label

- **GIVEN** a new `Subsidie` record
- **WHEN** it is stored with `subsidieRegeling: 'eu-horizon'`
- **THEN** the save MUST succeed; **AND** further
  kostendossier inputs MUST exclusively accept the EU-Horizon
  kostencategorieën (REQ-RDS-002).

### Requirement: REQ-RDS-002: Per-regeling kostencategorieën SHALL be constrained declaratively

Each `subsidieRegeling` value MUST have a specific set of
allowed `kostencategorie` values (declaratively via JSON Schema
`oneOf` or `if/then` construct):

| Regeling | Allowed kostencategorieën |
|---|---|
| `mit` | `personnel`, `materials`, `external-services`, `equipment-depreciation`, `other-direct` |
| `sbir` | `personnel`, `materials`, `equipment-depreciation`, `other-direct` |
| `eu-horizon` | `personnel`, `subcontracting`, `other-direct`, `indirect-25-percent` |
| `efro` | `personnel`, `external-services`, `materials`, `equipment`, `other`, `indirect-flat-rate` |
| `react-eu` | (same as EFRO + REACT-specific `green-recovery`) |

Per ADR-031, no PHP category validator.

#### Scenario: An invalid category for EU-Horizon is refused

- **GIVEN** a `Subsidie` with `subsidieRegeling: 'eu-horizon'`
- **WHEN** a kostenpost with `kostencategorie: 'equipment'` is
  booked (Horizon does not allow that as a direct category)
- **THEN** the save MUST fail with a schema validation error.

### Requirement: REQ-RDS-003: Per-regeling voortgangsrapportage SHALL be generated via a declarative aggregation

The voortgangsrapportage per R&D subsidie MUST be an
`x-openregister-aggregations` block that groups `kostenpost`
records per `kostencategorie` + `periodId`, filtered on the
parent subsidie. A docudesk template per regeling MUST render
the report in the format required by the subsidie provider
(e.g. EU Horizon Periodic Report layout, MIT voortgangsrapport).
No PHP report renderer.

#### Scenario: Horizon voortgangsrapportage groups costs per Horizon category

- **GIVEN** a Horizon subsidie with kostenpost records in every
  Horizon category
- **WHEN** the voortgangsrapportage is rendered
- **THEN** the docudesk document MUST follow the Horizon
  canonical layout, with cumulative + periodic totals per
  Horizon category.

### Requirement: REQ-RDS-004: Per-regeling audit-pack SHALL be assembled per regeling requirements via docudesk templates

Each R&D regeling has its own audit-trail requirements — EU
Horizon requires an `Audit Certificate` with explicit timesheet
evidence; MIT requires a declaration from the WBSO / S&O
administration; EFRO requires a procurement dossier per
purchase over a drempel. Per regeling, a docudesk template MUST
assemble an audit-pack from the OR audit-trail-immutable + the
subsidie's kostendossier + relevant external attachments (by
URI per ADR-022). No PHP audit-pack builder.

#### Scenario: EU-Horizon audit-pack includes timesheet references

- **GIVEN** a Horizon subsidie with personnel costs + related
  S&O-uren-staten
- **WHEN** the audit-pack is rendered
- **THEN** the docudesk document MUST contain, per personnel
  kostenpost, a URI reference to the related S&O-uren-staat.

### Requirement: REQ-RDS-005: Budget monitoring per regeling SHALL surface warnings when a kostencategorie max is at risk of being exceeded

Each R&D regeling has per-kostencategorie sub-maxima (e.g.
Horizon indirect-25% is bound to 25% of direct costs). An
`x-openregister-calculations` block per regeling MUST verify
this and surface a warning when ≥90% of the max is reached. Per
ADR-031, declarative — no PHP budget watcher.

#### Scenario: Horizon indirect-25% warning at 90% usage

- **GIVEN** a Horizon subsidie with €100 000 direct costs and
  €22 500 indirect-25% costs (projected over the remaining
  budget)
- **WHEN** the budget monitoring calculation runs
- **THEN** a warning MUST appear ("indirect-25% limit of
  €25 000 approaching; current usage 90%").

### Requirement: REQ-RDS-006: R&D subsidies administration SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.mkb-r-d-subsidies`) under
`Bookkeeping > R&D Subsidies` with `type: index` per regeling +
`type: detail` per subsidie (budget, kostendossier,
voortgangsrapportage, audit-pack). Per ADR-024 Tier-4, no
bespoke Vue files.

#### Scenario: R&D subsidies menu toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.mkb-r-d-subsidies`
- **WHEN** the flag is ON
- **THEN** the menu MUST appear.
- **WHEN** the flag is OFF
- **THEN** the menu MUST NOT render.
