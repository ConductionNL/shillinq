# Spec: bookkeeping-market-government-separation

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (NL gov sector)
**Depends on:** bookkeeping-cost-centers-dimensions

## ADDED Requirements

### Requirement: REQ-MGS-001: The system SHALL declare an `ondernemingsActiviteit` flag on `CostCenter` distinguishing market activities from public-task activities

Per Wet Markt en Overheid (Mededingingswet hoofdstuk 4b),
gemeenten / provincies / waterschappen that conduct
ondernemingsactiviteiten MUST separate these activities from
public tasks. The `CostCenter` schema (from T4-base
`bookkeeping-cost-centers-dimensions`) MUST gain an
`ondernemingsActiviteit: boolean` field (default `false`). When
`true`, the cost-center MUST be subject to the
integrale-kostprijs requirement (REQ-MGS-002) and the
transparantie-administratie (REQ-MGS-005). Per ADR-031 the flag
MUST be schema metadata — no parallel `OndernemingsActiviteit`
register. When materialised as a separate view, the
ondernemingsactiviteit SHOULD carry a schema.org type of
`schema:Service`.

#### Scenario: A cost-center flagged as ondernemingsactiviteit is subject to the kostprijs requirement

- **GIVEN** a `CostCenter` with `ondernemingsActiviteit: true`
- **WHEN** costs are booked on this cost-center
- **THEN** the integrale-kostprijs calculation (REQ-MGS-002)
  MUST automatically apply.

### Requirement: REQ-MGS-002: The system SHALL compute integrale kostprijs declaratively per ondernemingsactiviteit cost-center

The integrale-kostprijs per ondernemingsactiviteit MUST include:

- Direct costs (lines posted directly to the cost-center).
- Toe te rekenen overhead via a configurable verdeelsleutel
  (re-using the `GRVerdeelsleutel` shape from
  `bookkeeping-gr-consolidation` REQ-GRC-002 or a similar
  overlay named `OverheadVerdeelsleutel`).
- A reasonable compensation for own equity used (per Wet Markt
  en Overheid art. 25i, percentage configurable per
  administration).

The computation MUST be declared as an
`x-openregister-calculations` block on `CostCenter` — no PHP
kostprijs service.

#### Scenario: Integrale kostprijs sums direct costs + allocated overhead + equity compensation

- **GIVEN** an ondernemingsactiviteit with €100 000 direct
  costs, a verdeelsleutel allocating €20 000 overhead, and a
  configured 4% equity compensation on €50 000 of deployed
  equity
- **WHEN** the integrale-kostprijs calculation runs
- **THEN** the result MUST be
  `€100 000 + €20 000 + (€50 000 × 0.04) = €122 000`.

### Requirement: REQ-MGS-003: Tarieven for ondernemingsactiviteit prestaties SHALL at minimum cover the integrale kostprijs (default; exceptions explicitly marked)

Per Wet Markt en Overheid art. 25i, gemeenten MUST charge
tarieven that at minimum cover the integrale kostprijs for
market activities, unless an algemeen-belang-besluit justifies a
lower tariff. The shillinq model MUST surface this via an
aggregation that compares realised revenue per
ondernemingsactiviteit with the integrale kostprijs for that
period. An under-cost-recovery result MUST surface a warning in
the transparantie-administratie view (REQ-MGS-005).

#### Scenario: An under-cost-recovery ondernemingsactiviteit triggers a warning

- **GIVEN** an ondernemingsactiviteit with integrale kostprijs
  €122 000 and realised revenue €100 000 for the period
- **WHEN** the transparantie-administratie view renders
- **THEN** a warning MUST appear stating "€22 000 under-cost-
  recovery; requires algemeen-belang-besluit or tariff
  adjustment".

### Requirement: REQ-MGS-004: Algemeen-belang-besluiten SHALL be administratively tracked as a structured override on the ondernemingsactiviteit cost-center

An `algemeenBelangBesluit` overlay MUST be linkable to an
ondernemingsactiviteit cost-center, with fields: `besluitNummer`
(string), `besluitDatum` (date), `geldigheidsperiode`
(date-range), `motivering` (string or docudesk attachment URI),
`getrokkenBedrag` (number, the cost-recovery exception amount).
The warning from REQ-MGS-003 MUST be suppressed when a valid
algemeen-belang-besluit applies.

#### Scenario: A valid algemeen-belang-besluit suppresses the warning

- **GIVEN** an ondernemingsactiviteit with an under-cost-
  recovery result
- **AND** an `algemeenBelangBesluit` with
  `geldigheidsperiode: 2026-01-01..2026-12-31` linked to the
  cost-center
- **WHEN** the transparantie-administratie view for 2026-Q1
  renders
- **THEN** the warning MUST be suppressed; **AND** an
  informational banner MUST mention the besluit number.

### Requirement: REQ-MGS-005: The transparantie-administratie SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.gov-markt-overheid`) under
`Bookkeeping > Markt en Overheid` with `type: index` per
ondernemingsactiviteit (direct costs, overhead, equity
compensation, integrale kostprijs, revenue, margin, status) +
`type: detail` for the substantiation per cost-center. Per
ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: Transparantie-administratie toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.gov-markt-overheid`
- **WHEN** the flag is ON
- **THEN** the Markt en Overheid menu MUST appear under
  Bookkeeping.
- **WHEN** the flag is OFF
- **THEN** the menu MUST NOT render.
