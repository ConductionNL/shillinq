# bookkeeping-verplichtingenadministratie Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- verplichtingen-commitment-accounting

## Purpose

Delta extending the existing commitment-accounting capability (REQ-VPL-001…009,
delivered by the archived `bookkeeping-verplichtingenadministratie` change) with
the missing **trigger, visibility and rechtmatigheid linkage**: auto-materialise a
`Verplichting` from PO/contract approval, report committed-vs-realised per budget
line, and feed the auto-created commitment into rechtmatigheid toetsing. Reuses
`BudgetBlocker`, `MandaatEnforcer`, `BudgetImpactEmitter` — does not redefine them.

## ADDED Requirements

@e2e exclude commitment materialisation + declarative aggregation are backend surfaces; only the per-budget-line drilldown is browser-testable (covered by REQ-VPL-011).

### Requirement: REQ-VPL-010 — Approving a PO or signing a contract SHALL auto-materialise a Verplichting

The system SHALL materialise a `Verplichting` when a `PurchaseOrder` reaches
`approved` (bookkeeping-purchase-order-3way REQ-PO3W-001) or a `Contract` reaches
`signed`/`executed`. The materialised commitment MUST set `bronReferentie` to the
source object id, create one `VerplichtingRegel` per budget coderingscombinatie
(programma + kostenplaats + boekjaar + grootboekrekening) with `bedrag_excl_btw`
taken from the source lines, and drive the commitment through the existing
`MandaatEnforcer` and `BudgetBlocker` guards (so budget is reserved on
`aangegaan`). Materialisation MUST be **idempotent**: a repeated approval
transition for the same `bronReferentie` MUST NOT create a duplicate
`Verplichting`. When `BudgetBlocker` denies the commitment (insufficient
`vrije_ruimte`, no override mandaat), the source approval MUST surface the denial
rather than proceed with an unfunded commitment. This is thin event-glue (a
listener + a small materialisation service); it introduces no parallel budget or
commitment logic.

#### Scenario: PO approval materialises a commitment and reserves budget

- GIVEN an approved `PurchaseOrder` for EUR 75.000 on programma 5.1 / boekjaar
  2026 with sufficient `vrije_ruimte` and a covering mandaat
- WHEN the `approved` transition fires
- THEN a `Verplichting` with `bronReferentie` = the PO id and one
  `VerplichtingRegel` of EUR 75.000 (programma 5.1, boekjaar 2026) MUST be created
- AND the budget's `openstaande_verplichtingen` MUST increase by EUR 75.000 and
  `vrije_ruimte` decrease by EUR 75.000 (via the existing `BudgetBlocker`)

#### Scenario: Materialisation is idempotent on transition replay

- GIVEN a `PurchaseOrder` that already materialised a `Verplichting`
- WHEN the `approved` transition is re-emitted for the same PO id
- THEN no second `Verplichting` MUST be created and the budget MUST NOT be
  double-reserved

#### Scenario: Insufficient budget blocks the approval, not just the invoice

- GIVEN an approved `PurchaseOrder` for EUR 300.000 on a budget line whose
  `vrije_ruimte` is EUR 200.000 and no override mandaat is present
- WHEN materialisation runs
- THEN `BudgetBlocker` MUST deny the commitment and the approval MUST surface the
  "insufficient budget" denial; `vrije_ruimte` MUST remain EUR 200.000

#### Scenario: Multi-year raamovereenkomst materialises one regel per boekjaar

- GIVEN an approved framework `PurchaseOrder` of EUR 100.000/year for 2026–2029
- WHEN materialisation runs
- THEN FOUR `VerplichtingRegel`s MUST be created (one per boekjaar), each
  reserving EUR 100.000 on its own boekjaar budget independently (consistent with
  REQ-VPL-004)

### Requirement: REQ-VPL-011 — Committed-vs-realised SHALL be reportable per budget line

The system SHALL declare a per-budget-line committed-vs-realised aggregation via
`x-openregister-aggregations`, grouping `VerplichtingRegel` records by budget
coderingscombinatie (programma + kostenplaats + boekjaar + grootboekrekening) and
exposing, per line, `geautoriseerd`, `verplicht` (openstaande verplichtingen,
i.e. sum of `restant_verplicht`), `gerealiseerd` (sum of `gefactureerd_bedrag`),
and `vrij` (`geautoriseerd − verplicht − gerealiseerd`). The UI SHALL provide a
drilldown from a budget line to the underlying `Verplichting`s. This extends the
per-programma BBV columns of REQ-VPL-009 to per-line granularity and MUST be
declared declaratively (no bespoke reporting service).

#### Scenario: Budget-line drilldown shows the four columns

- @e2e src/views/**/BudgetLineCommitments*.spec.js
- GIVEN a budget line (programma 5.1 / kostenplaats FAC-2026 / boekjaar 2026 /
  grootboek 4400) with `geautoriseerd` EUR 500.000, one open commitment of
  EUR 75.000 and EUR 25.000 already gefactureerd on it
- WHEN a controller opens the committed-vs-realised drilldown for that line
- THEN the line MUST display `geautoriseerd` 500.000, `verplicht` 75.000,
  `gerealiseerd` 25.000, `vrij` 400.000
- AND drilling into the line MUST list its underlying `Verplichting`(s)

#### Scenario: Aggregation is declarative

- GIVEN the verplichtingenadministratie register configuration
- WHEN scanned for the committed-vs-realised aggregation
- THEN it MUST be declared under `x-openregister-aggregations` (per ADR-031), with
  no parallel PHP reporting service computing the same figures

### Requirement: REQ-VPL-012 — Auto-created commitments SHALL be fed into rechtmatigheid toetsing

A `Verplichting` materialised by REQ-VPL-010 SHALL carry the linkage that triggers
the existing rechtmatigheid toetsing at the commitment stage
(bookkeeping-rechtmatigheidsverantwoording REQ-RV-008), so lawfulness checks fire
on system-raised commitments exactly as they do on manually-raised ones. Any
`MandaatEnforcer` override applied during materialisation MUST record its override
reason on the commitment as a rechtmatigheid-relevant afwijking (feeding the
REQ-RV-005 aggregation). This requirement adds only the linkage; it does not
modify the toetsing engine.

#### Scenario: Toetsing fires on a system-materialised commitment

- GIVEN a PO approval that materialises a `Verplichting` of EUR 75.000
- WHEN the commitment is created
- THEN the rechtmatigheid toetsing for the commitment stage (REQ-RV-008) MUST be
  triggered against the same `bronReferentie`, not deferred to invoice time

#### Scenario: Mandaat override is recorded as an afwijking

- GIVEN a commitment materialised under an override mandaat because `vrije_ruimte`
  was insufficient
- WHEN the `Verplichting` is created
- THEN the override reason MUST be recorded on the commitment and MUST be visible
  to the rechtmatigheid aggregation (REQ-RV-005) as an afwijking

## Non-Functional Requirements

- **Performance:** materialisation from a single approval MUST complete within the
  approval transition's normal latency budget (no extra user-perceived delay
  beyond the existing budget guard).
- **Accessibility:** the budget-line drilldown MUST meet WCAG 2.1 AA; the four
  amount columns MUST be labelled, not colour-coded only.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); i18n
  keys in English.

## Acceptance Criteria

- [ ] PO `approved` (and contract `signed`) materialises an idempotent Verplichting via the existing guards
- [ ] Insufficient budget denies the approval (fail-closed), not just the invoice
- [ ] Committed-vs-realised per budget line declared as an `x-openregister-aggregations` query with a UI drilldown
- [ ] Auto-created commitments trigger rechtmatigheid toetsing (REQ-RV-008) and record override afwijkingen

## Notes

- Depends on the existing capability code (`BudgetBlocker`, `MandaatEnforcer`,
  `BudgetImpactEmitter`, `Verplichting`/`VerplichtingRegel`) and specs
  `bookkeeping-purchase-order-3way`, `bookkeeping-programmabegroting`,
  `bookkeeping-rechtmatigheidsverantwoording`.
- If the first-class `Contract` lifecycle is not yet present, the contract trigger
  is registered but dormant until contract-lifecycle-management lands.
