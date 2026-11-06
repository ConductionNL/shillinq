# Spec: bookkeeping-verplichtingenadministratie (delta — budget-core-schema)

This delta MODIFIES REQ-VPL-011 to reflect the `Budget` → `CommitmentBudget`
rename (`budget-core-schema` design.md §1) and to record a positive-control
finding surfaced while making that rename: whether the declarative
`committedVsRealisedPerBudgetLine` aggregation this requirement mandates
("no bespoke reporting service") actually materialises at all, given a
platform-wide hazard in how cross-schema aggregation filters are validated.

## Why this delta exists

`budget-core-schema`'s own consumer sweep (its design.md §2a) touches this
aggregation's `join.through` value directly (renaming its target from
`Budget` to `CommitmentBudget`). While doing so, design.md §6a found
independent evidence that the *existing*, unrelated-to-this-rename
`CommitmentBudget.outstanding_commitments` aggregation on the same schema
filters on a field (`programme`) that does not match the schema's own
declared property name (`programmeCode`) — the textbook shape of the
platform's `AggregationAnnotationValidator` hazard (filter fields validated
against the *declaring* schema, silently discarding the annotation on a
mismatch, logged only as a `nextcloud.log` warning). This does not, by
itself, prove `committedVsRealisedPerBudgetLine` is also affected — it is
adjacent evidence on the same schema, not a direct measurement of this
aggregation — so `budget-core-schema`'s tasks.md requires running the actual
positive control (grep `nextcloud.log`, query the aggregation endpoint
directly for non-empty rows) and recording the real outcome here. **This
delta is written to hold that outcome; the scenario below is filled in with
the measured result, not left as a placeholder, before this change ships.**

## MODIFIED Requirements

### Requirement: REQ-VPL-011 — Committed-vs-realised SHALL be reportable per budget line

The system SHALL declare a per-budget-line committed-vs-realised aggregation
via `x-openregister-aggregations`, grouping `VerplichtingRegel` records by
budget coderingscombinatie (programma + kostenplaats + boekjaar +
grootboekrekening) and joining through `CommitmentBudget` (renamed from
`Budget` by `budget-core-schema`), exposing, per line, `geautoriseerd`,
`verplicht` (openstaande verplichtingen, i.e. sum of `restant_verplicht`),
`gerealiseerd` (sum of `gefactureerd_bedrag`), and `vrij`
(`geautoriseerd − verplicht − gerealiseerd`). The UI SHALL provide a
drilldown from a budget line to the underlying `Verplichting`s. This extends
the per-programma BBV columns of REQ-VPL-009 to per-line granularity and
MUST be declared declaratively (no bespoke reporting service) **provided the
declarative aggregation actually materialises** — if the positive control
below finds it silently discarded by the platform's `AggregationAnnotationValidator`
hazard, this "no bespoke reporting service" mandate is unsatisfiable as
written, and is flagged as an open question for whichever change next
touches this requirement (openregister/foundation-repo fix, or a PHP
fallback service analogous to `budget-core-schema`'s own
`BudgetVsActualsReader`/`Calculator`) — not silently resolved by this delta.

#### Scenario: Budget-line drilldown shows the four columns

- @e2e src/views/**/BudgetLineCommitments*.spec.js
- GIVEN a budget line (programma 5.1 / kostenplaats FAC-2026 / boekjaar 2026
  / grootboek 4400) with `geautoriseerd` EUR 500.000, one open commitment of
  EUR 75.000 and EUR 25.000 already gefactureerd on it
- WHEN a controller opens the committed-vs-realised drilldown for that line
- THEN the line MUST display `geautoriseerd` 500.000, `verplicht` 75.000,
  `gerealiseerd` 25.000, `vrij` 400.000
- AND drilling into the line MUST list its underlying `Verplichting`(s)

#### Scenario: Aggregation is declarative

- GIVEN the verplichtingenadministratie register configuration
- WHEN scanned for the committed-vs-realised aggregation
- THEN it MUST be declared under `x-openregister-aggregations` (per
  ADR-031), joining through `CommitmentBudget`, with no parallel PHP
  reporting service computing the same figures **unless the positive
  control in the scenario below finds the declarative path silently
  discarded, in which case this "no parallel service" mandate is the open
  question this delta hands back (see "Why this delta exists" above)**

@e2e exclude declarative-configuration check, no browser-visible surface —
verified by inspecting the register configuration (carried over from this
requirement's pre-existing scenario, unaffected by the rename itself)

#### Scenario: The aggregation's join target is renamed, and its declarative status is verified, not assumed

- **GIVEN** this change's rename of `join.through` from `Budget` to
  `CommitmentBudget` (`bookkeeping-verplichtingenadministratie.json:536`)
- **WHEN** `nextcloud.log` is grepped for `"annotation on schema"` after a
  fresh register import, and the aggregation endpoint is queried directly
  for non-empty rows against seeded `VerplichtingRegel`/`CommitmentBudget`
  data
- **THEN** the measured outcome, recorded 2026-08-20 (full detail in
  `openspec/specs/bookkeeping-verplichtingenadministratie/spec.md`'s
  REQ-VPL-011 delta): the platform hazard is CONFIRMED live on the shared
  dev instance (40 `"annotation on schema"` warnings from `decidesk`'s
  schemas, dated 2026-08-20). A shillinq-specific DYNAMIC measurement could
  not be completed — the shared instance runs a pre-rename shillinq build
  with no working aggregation-proxy route, and deploying this in-progress
  branch there to force it was out of scope. STATIC analysis against the
  actual declared property lists stands in: `outstanding_commitments`'s
  `where` filters on `programme`/`afgesloten`, neither declared on
  `CommitmentBudget` — CONFIRMS the declaring-schema hazard independent of
  this rename. `committedVsRealisedPerBudgetLine`'s `groupBy`/`filter`
  fields ARE all declared on the declaring schema `Verplichtingsregel`, but
  its `join.select` references `CommitmentBudget.geautoriseerd_bedrag`/
  `.gerealiseerd_bedrag` — field names that do not exist on
  `CommitmentBudget` under any name (the real fields are
  `authorised_amount`/`realised_amount`) — a genuine, independent
  join-field-name defect, newly found here, not one of design.md's original
  two. **Net: both aggregations are very likely non-functional today**, one
  confirmed by the documented hazard, one by an unrelated field-name bug —
  but neither is confirmed by a live materialised-vs-discarded measurement.
  Not fixed here (REQ-BCS-011 non-goal); handed to whichever change next
  touches `committedVsRealisedPerBudgetLine` or gets this branch onto a
  deployable instance for the outstanding live re-check.

@e2e exclude platform-diagnostic verification, not a repeatable browser
assertion — see `budget-core-schema` design.md §6a/§11.2 and tasks.md group
8 for the verification method and where the result is also recorded
