---
status: done
---

# bookkeeping-verplichtingenadministratie Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- verplichtingen-commitment-accounting
- migrate-mandaat-to-approval-chains (2026-07-14, archived)

## Purpose

Canonical home for **commitment accounting** (verplichtingenadministratie): the
BBV / Comptabiliteitswet requirement to consume budget when the organisation
becomes legally bound (PO signed, contract executed, subsidy awarded), through
delivery, invoicing and payment. The core data model and guards
(`Verplichting`/`VerplichtingRegel`, `BudgetBlocker`, `MandaatEnforcer`,
`BudgetImpactEmitter`, drie-staps registratie, raamovereenkomsten, drie-weg-
matching, BBV per-programma reporting) were delivered by the archived
`bookkeeping-verplichtingenadministratie` change (REQ-VPL-001…009).

## Requirements

### Requirement: REQ-VPL-000 — Shillinq SHALL reserve budget on commitment and reconcile it through delivery, invoicing and payment

The system SHALL record a `Verplichting` when the organisation becomes legally
bound (PO approved, contract signed, subsidy awarded), MUST reserve the committed
amount against the budget's `vrije_ruimte` at that moment (before any invoice), and
MUST reconcile the commitment through the delivery / invoicing / payment stages. The
core is implemented (REQ-VPL-001…009, archived change); the archived
`verplichtingen-commitment-accounting` change added auto-materialisation, per-line
reporting and the rechtmatigheid linkage (REQ-VPL-010…012).

#### Scenario: A commitment reserves budget before an invoice exists

- GIVEN a budget with `vrije_ruimte` EUR 200.000 and a new EUR 75.000 commitment
- WHEN the `Verplichting` reaches `aangegaan`
- THEN `openstaande_verplichtingen` MUST increase by EUR 75.000 and `vrije_ruimte`
  MUST decrease to EUR 125.000, with no invoice required

REQ-VPL-001…009 (already implemented in code) are documented in the archived
change at
`openspec/changes/archive/2026-06-14-bookkeeping-verplichtingenadministratie/specs.md`.

The archived change `verplichtingen-commitment-accounting`
(`openspec/changes/archive/2026-07-12-verplichtingen-commitment-accounting/`) added
REQ-VPL-010…012: auto-materialisation of a `Verplichting` from PO approval /
contract activation, committed-vs-realised reporting per budget line, and a
rechtmatigheid linkage for system-raised commitments. Those requirements are now
normative in this canonical spec below.

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

### Requirement: REQ-VPL-013 — The Verplichting `goedkeuren` transition SHALL be gated by a declarative approval chain

The `Verplichting` schema SHALL declare an `x-openregister-approval-chains` entry
whose `transition` is `goedkeuren` (`in_goedkeuring` → `aangegaan`). The chain
SHALL route by `totaalbedrag_excl_btw` (`amountField`, EUR cents): a single
`commitment-administrator` approves commitments from `minAmount` 0, and a
`finance-director` approves commitments at or above EUR 250.000
(`minAmount: 25000000`). The chain SHALL set `separationOfDuties: true` (the
approver MUST NOT be the requester who submitted the commitment) and
`onApprove: advanceTransition` (completion releases the `goedkeuren` transition).

The declaration is consumed by OpenRegister's approval-chains capability
(`x-openregister-approval-chains`, `ApprovalChainAnnotationInstaller`,
`ApprovalChainGateListener`, `ApprovalChainAdvanceListener`; OpenRegister
REQ-006…010). shillinq SHALL NOT ship a parallel PHP approval-chain
implementation. The declaration is inert until the OpenRegister release carrying
that capability is deployed; the mandate-record routing that decides *whether* a
commitment is offered for approval (`MandaatEnforcer`, REQ-VPL-002) is unchanged
and remains a deliberate imperative exception.

#### Scenario: The declared chain names a real gated transition
- **GIVEN** the `Verplichting` schema's `x-openregister-approval-chains`
- **WHEN** the `verplichting-goedkeuring` entry is read
- **THEN** its `transition` MUST equal `goedkeuren`
- **AND** `goedkeuren` MUST exist in `x-openregister-lifecycle.transitions` with `from` `in_goedkeuring` and `to` `aangegaan`

#### Scenario: The chain routes by commitment amount to a single approver tier
- **GIVEN** the declared chain sets `amountField: totaalbedrag_excl_btw`
- **WHEN** its `approvers` are read
- **THEN** there MUST be a `minAmount: 0` tier requiring role `commitment-administrator`
- **AND** a higher tier (`minAmount: 25000000`) requiring role `finance-director`
- **AND** each tier MUST carry `role` and `min` (≥ 1)

#### Scenario: The chain enforces separation of duties and auto-advances
- **GIVEN** the declared chain
- **THEN** `separationOfDuties` MUST be `true`
- **AND** `onApprove` MUST be `advanceTransition`

#### Scenario: Mandate-record enforcement is retained (no dead control)
- **GIVEN** this change adds only the declarative chain
- **THEN** `MandaatEnforcer` MUST still exist
- **AND** the `indienen` transition MUST still reference `MandaatEnforcer::requiresApproval`

### Requirement: A commitment's declared transitions resolve their guards and action (REQ-PCYE-001)

The guard tags `MandateEnforcer::requiresApproval` and
`BudgetBlocker::canCommit` SHALL be registered so that `indienen`,
`aangaan` and `goedkeuren` run their checks and allow or refuse with a
message, and the `record-mutatie` action SHALL be served so that `aangaan`
records a committed movement and raises the outstanding commitments on the
matching budget. None of these transitions MUST abort because a tag or
action cannot be resolved.

#### Scenario: A budget holder signs a commitment within mandate and budget

- GIVEN draft commitment V-2026-0114 of EUR 20,000 on programme 0.4 with EUR 60,000 free in 2026, signed by a budget holder with a EUR 50,000 mandate
- WHEN the budget holder presses Verplichting aangaan on the commitment page
- THEN the commitment shows status committed
- AND the 2026 budget for programme 0.4 shows EUR 40,000 free

#### Scenario: A commitment beyond the budget is refused with the reason

- GIVEN a draft commitment of EUR 80,000 on a budget with EUR 60,000 free
- WHEN the budget holder presses Verplichting aangaan
- THEN the transition is refused with a message naming the EUR 20,000 shortfall

### Requirement: A booked invoice on an order lowers its commitment (REQ-PCYE-002)

When a supplier invoice for an order with a commitment is approved,
shillinq SHALL record an invoiced movement against the commitment, lower
each affected line's remaining amount, move the commitment to partially
invoiced, and move the amount from outstanding commitments to realised on
the budget.

#### Scenario: A first invoice reduces what is still committed

- GIVEN committed V-2026-0114 of EUR 20,000 from order PO-2026-031
- WHEN a supplier invoice of EUR 15,000 for PO-2026-031 is approved
- THEN the commitment page shows EUR 15,000 invoiced and EUR 5,000 remaining, status partially invoiced

### Requirement: Marking the last invoice closes the commitment and releases the rest (REQ-PCYE-003)

A supplier invoice SHALL carry a "last invoice" mark. When an approved
invoice with that mark belongs to an order with a commitment, shillinq
SHALL close the commitment through `afsluiten`, record the released
remainder as a closed movement, set every line's remaining amount to zero,
and return the remainder to the budget's free capacity. `afsluiten` SHALL
be allowed from every open state. Setting the mark SHALL show the amount
that will be released and ask for confirmation.

#### Scenario: The last invoice releases EUR 5,000

- GIVEN V-2026-0114 with EUR 5,000 remaining after an invoice of EUR 15,000
- WHEN the buyer marks that invoice as the last one and confirms the release of EUR 5,000
- THEN the commitment shows status closed with a closed movement of EUR 5,000
- AND the 2026 budget for programme 0.4 shows EUR 5,000 more free capacity

### Requirement: Open commitments carry over to the next year under their number (REQ-PCYE-004)

Shillinq SHALL offer the controller a year-end action that previews every
open commitment line of a closing fiscal year with its remaining amount and
the free capacity of the next year's matching budget, and then carries each
line into the next year: a new line on the same commitment for the
remaining amount, the old line closed, and a carried-forward movement on
both. Lines whose remaining amount exceeds the next year's free capacity
SHALL be listed as shortfalls and still carried. Running the action again
MUST NOT carry a line twice.

#### Scenario: Road maintenance continues into 2027

- GIVEN commitment V-2026-0120 of EUR 48,000 with EUR 30,000 invoiced in 2026 and EUR 10,000 free on the 2027 budget
- WHEN the controller runs Carry open commitments to next year for 2026 and confirms the preview
- THEN V-2026-0120 has a 2027 line of EUR 18,000 and its 2026 line is closed
- AND the preview listed V-2026-0120 as a shortfall of EUR 8,000 on the 2027 budget

#### Scenario: A second run changes nothing

- GIVEN the 2026 carry-over has run
- WHEN the controller runs it again for 2026
- THEN the preview shows no open lines and nothing is written

### Requirement: The spending mandate is namespaced (REQ-VPA-035)

The mandate schema slug SHALL be `SpendingMandate` and SHALL NOT be `Mandate`
or `Mandaat`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `Mandate` resolved to this app's spending ceiling or to
dossiq's administrative-law mandaat depending on which row was reached first.
The two share zero declared fields, so they are renamed apart rather than folded
onto one owner.

`RenameCommitmentSchemas` SHALL map BOTH `Mandaat` and `Mandate` to
`SpendingMandate`. An install still on Dutch reaches the namespaced slug in one
move; one already migrated to `Mandate` follows behind. Mapping only the Dutch
source would strand every install that already ran the vocabulary pass.

When both source slugs exist the step SHALL refuse rather than merge, because
each may own objects.

#### Scenario: A Dutch install lands on the namespaced slug

- **GIVEN** an install carrying `Mandaat`
- **WHEN** the repair step runs
- **THEN** the row is renamed to `SpendingMandate`, keeping its schema id.

#### Scenario: An already-English install follows behind

- **GIVEN** an install carrying `Mandate`
- **WHEN** the repair step runs
- **THEN** the row is renamed to `SpendingMandate`.

#### Scenario: The seeded mandates are actually checked

- **WHEN** the seeded mandates are validated against the commitment kind enum
- **THEN** at least one seeded mandate is checked, so the assertion cannot pass
  by matching nothing.

## Notes

- Depended on by `bookkeeping-rechtmatigheidsverantwoording` (REQ-RV-008),
  `bookkeeping-purchase-order-3way`, and `bookkeeping-programmabegroting`.
- Declarative-first (ADR-031): budget blocking, lifecycle, aggregations and
  notifications are declared; the only imperative surfaces are the fail-closed
  guards and the thin materialisation glue.
