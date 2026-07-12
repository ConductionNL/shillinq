---
status: in-progress
---

# bookkeeping-verplichtingenadministratie Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- verplichtingen-commitment-accounting

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
core is implemented (REQ-VPL-001…009, archived change); the in-progress change adds
auto-materialisation, per-line reporting and the rechtmatigheid linkage
(REQ-VPL-010…012).

#### Scenario: A commitment reserves budget before an invoice exists

- GIVEN a budget with `vrije_ruimte` EUR 200.000 and a new EUR 75.000 commitment
- WHEN the `Verplichting` reaches `aangegaan`
- THEN `openstaande_verplichtingen` MUST increase by EUR 75.000 and `vrije_ruimte`
  MUST decrease to EUR 125.000, with no invoice required

REQ-VPL-001…009 (already implemented in code) are documented in the archived
change at
`openspec/changes/archive/2026-06-14-bookkeeping-verplichtingenadministratie/specs.md`.

The in-progress change `verplichtingen-commitment-accounting` adds REQ-VPL-010…012:
auto-materialisation of a `Verplichting` from PO approval / contract signature,
committed-vs-realised reporting per budget line, and a rechtmatigheid linkage for
system-raised commitments. Those normative requirements are authored as the change
delta at
`openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md`
and will be synced into this canonical spec on archive (`openspec sync`).

## Notes

- Depended on by `bookkeeping-rechtmatigheidsverantwoording` (REQ-RV-008),
  `bookkeeping-purchase-order-3way`, and `bookkeeping-programmabegroting`.
- Declarative-first (ADR-031): budget blocking, lifecycle, aggregations and
  notifications are declared; the only imperative surfaces are the fail-closed
  guards and the thin materialisation glue.
