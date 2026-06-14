# Design — Markt en Overheid Separation

## Context

Wet Markt en Overheid (Mededingingswet hoofdstuk 4b) requires
gemeenten / provincies / waterschappen running market activities to
identify ondernemingsactiviteiten as distinct clusters, compute
the integrale kostprijs, and demonstrate the activity is not
cross-subsidised by public funds. Without dedicated primitives,
this lives in spreadsheets and gets challenged during audit.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — `ondernemingsActiviteit` flag on the existing `CostCenter`, NOT a parallel register

Per ADR-031 + the parent envelope's design D6, the variation maps
cleanly to schema metadata: `CostCenter` gains an
`ondernemingsActiviteit: boolean` field (default `false`). When
`true`, the cost-center is subject to the kostprijs requirement.
No parallel `OndernemingsActiviteit` register; no PHP kostprijs
service. When materialised as a separate view, the
ondernemingsactiviteit carries a schema.org type of
`schema:Service`.

### D2 — Integrale kostprijs as a declarative `x-openregister-calculations` block

The kostprijs = direct costs (lines posted to the cost-center) +
allocated overhead (via a configurable verdeelsleutel, re-using
the `GRVerdeelsleutel` shape from sibling
`add-shillinq-gr-consolidation`) + equity compensation
(configurable percentage on deployed equity, per Wet Markt en
Overheid art. 25i). Modelled as a single calculation block on
`CostCenter`. No service.

### D3 — Tarieven-vs-kostprijs aggregation surfaces under-cost-recovery warnings

Per Wet Markt en Overheid art. 25i, tarieven for
ondernemingsactiviteit prestaties MUST cover the integrale
kostprijs unless an algemeen-belang-besluit justifies a lower
tariff. An aggregation compares realised revenue per
ondernemingsactiviteit with its integrale kostprijs; an under-
cost-recovery result surfaces a warning ("€X under-cost-recovery;
requires algemeen-belang-besluit or tariff adjustment") in the
transparantieadministratie view.

### D4 — `algemeenBelangBesluit` as an overlay that suppresses the warning

The exception path is structured, not free-form: an
`algemeenBelangBesluit` overlay carries `besluitNummer`,
`besluitDatum`, `geldigheidsperiode`, `motivering` (free-form or
docudesk attachment URI), and `getrokkenBedrag`. When a valid
besluit (geldigheidsperiode covers the warning's period) is linked
to the cost-center, the warning is suppressed and an informational
banner cites the besluit number. Operators retain full
auditability of legitimate exceptions.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Cost-center register | T4-base `bookkeeping-cost-centers-dimensions` | Add `ondernemingsActiviteit` flag; no parallel register. |
| Verdeelsleutel shape | Sibling `add-shillinq-gr-consolidation` REQ-GRC-002 | Re-use `GRVerdeelsleutel` (or a `OverheadVerdeelsleutel` clone if separation makes sense in the implementing cycle). |
| Calculation engine | `x-openregister-calculations` (ADR-031) | Integrale-kostprijs block per ondernemingsactiviteit cost-center. |
| Aggregation engine | `x-openregister-aggregations` | Tarieven-vs-kostprijs comparison surfacing warnings. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically — every besluit/exception logs. |
| Docudesk attachment | docudesk (ADR-022) | `motivering` field references a docudesk attachment URI. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.gov-markt-overheid`. |

**Net new code in implementation cycle**: 1 flag field
(`ondernemingsActiviteit`) + 1 overlay schema
(`algemeenBelangBesluit`) + 1 calculation declaration + 1
aggregation declaration + 1 manifest entry. No new PHP service.

## Seed Data

None. The verdeelsleutel + equity-compensation percentage are
operationally authored per administration.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Verdeelsleutel choice subjective | Configurable per administration; defaults sector-neutral. |
| Equity-compensation percentage drift | Configurable per administration; spec references regulation, not value. |
| Algemeen-belang-besluit overlay misused as a blanket suppression | Each besluit carries `getrokkenBedrag` + `motivering`; the suppression scope is explicit. |
| Realised revenue mapping to cost-center ambiguous | The implementing cycle documents which revenue accounts attach to ondernemingsactiviteit cost-centers via the same dimension-link as costs. |
