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
