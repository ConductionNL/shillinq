# Design: verplichtingen-commitment-accounting

## Architecture Overview

```
PurchaseOrder → approved   ┐
Contract      → signed     ┘─▶ CommitmentMaterialisationListener  (thin glue)
                                    │  build Verplichting + regels from source
                                    ▼
                            CommitmentMaterialisationService
                                    │  reuse existing guards
                       ┌────────────┼─────────────────────┐
                       ▼            ▼                      ▼
                MandaatEnforcer  BudgetBlocker      BudgetImpactEmitter
                (existing)       (existing)         (existing → obligation.activated)
                                    │
                                    ▼
                          Verplichting (aangegaan) — budget reserved
                                    │
             ┌──────────────────────┴───────────────────────┐
             ▼                                                ▼
  x-openregister-aggregations                    rechtmatigheid toetsing
  committed-vs-realised per budget line          (REQ-RV-008, existing) fired
  (declarative) + UI drilldown                   on the auto-created commitment
```

The listener/service is the only new imperative code; it computes nothing that the
existing guards already compute — it just assembles the `Verplichting` and calls
them. Reporting and notification stay declarative.

## API Design

No new public HTTP endpoints. Materialisation is event-driven; the drilldown reads
`VerplichtingRegel` through OpenRegister's existing aggregation/list surface.

## Database Changes

None (ADR-022). The `Verplichting` register gains at most one optional provenance/
rechtmatigheid reference field via the existing register fragment (additive). The
committed-vs-realised aggregation is a declarative block in the same fragment.

## Nextcloud Integration

- Controllers: none new (drilldown uses OR list/aggregation API).
- Services: `Commitment/CommitmentMaterialisationService` (new, small).
- Listeners: `CommitmentMaterialisationListener` (new) on the PO `approved` and
  Contract `signed`/`executed` transitions, registered in `lib/AppInfo/Application.php`.
- Reused: `BudgetBlocker`, `MandaatEnforcer`, `BudgetImpactEmitter`,
  `Verplichting`/`VerplichtingRegel` registers.
- Events/Hooks: no new event class; the existing obligation-activated CloudEvent
  fires via `BudgetImpactEmitter` when the commitment activates.

## Security Considerations

- The listener runs in the approver's authorized context; the commitment inherits
  the PO/contract `administrationId` — no cross-administration leakage.
- `BudgetBlocker` remains fail-closed (CWE-863): any exception denies the
  commitment; the listener MUST NOT swallow a denial into a silent success.
- Override-mandaat reasons are recorded immutably (audit trail), never discarded.

## NL Design System

The budget-line drilldown uses standard NC list/table components and CSS variables;
the four amount columns (`geautoriseerd` / `verplicht` / `gerealiseerd` / `vrij`)
are text-labelled. No hardcoded colours.

## File Structure

```
lib/
  Listener/
    CommitmentMaterialisationListener.php     (new — thin glue)
  Service/
    Commitment/
      CommitmentMaterialisationService.php    (new — assemble + delegate)
  AppInfo/Application.php                      (modified — register listener)
  Settings/register.d/
    bookkeeping-verplichtingenadministratie.json (modified — aggregation + optional ref field)
src/
  views/BudgetLineCommitments.vue              (new — committed-vs-realised drilldown)
```

## Seed Data

Municipality flavour (gemeente). Objects carry the `@self` envelope
`{register: "shillinq", schema: "Verplichting"}` / `{... schema: "VerplichtingRegel"}`.

### Schema: `Verplichting`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | verplichting-2026-0001 | verplichting-2026-0002 | verplichting-2026-0003 |
| verplichtingsnummer | VPL-2026-0001 | VPL-2026-0002 | VPL-2026-0003 |
| soort | inkooporder | raamovereenkomst | subsidiebeschikking |
| bronReferentie | PO-2026-0207 (auto from PO approval) | PO-2026-0231 (framework) | BES-2026-0044 |
| status | aangegaan | aangegaan | aangegaan |
| administrationId | adm-gemeente-zuidoost | adm-gemeente-zuidoost | adm-gemeente-zuidoost |

### Schema: `VerplichtingRegel`
| Field | Object 1 | Object 2 (2026) | Object 3 (2027) |
|-------|----------|-----------------|-----------------|
| slug | vpl-regel-0001 | vpl-regel-0002 | vpl-regel-0003 |
| verplichtingsnummer | VPL-2026-0001 | VPL-2026-0002 | VPL-2026-0002 |
| programma | 5.1 | 5.1 | 5.1 |
| kostenplaats | FAC-2026 | FAC-2026 | FAC-2027 |
| boekjaar | 2026 | 2026 | 2027 |
| grootboekrekening | 4400 | 4400 | 4400 |
| bedrag_excl_btw | 75000.00 | 100000.00 | 100000.00 |
| gefactureerd_bedrag | 25000.00 | 0.00 | 0.00 |
| restant_verplicht | 50000.00 | 100000.00 | 100000.00 |

**Related items per object:**
- Files: Object 1 links the approved PO PDF (source document).
- Notes: Object 3 (subsidie) notes "eindafrekening pending 20% restant".
- Tasks: none.
- Contacts: `bronReferentie` links to the source PurchaseOrder / subsidy record.

## Mixed-spec rationale (thin-glue exception)

This change is `kind: code` under the thin-glue exception. The **only** new
imperative code is:
1. `CommitmentMaterialisationListener` — subscribes to the PO/contract transition
   and forwards to (2). (~≤10 LOC.)
2. `CommitmentMaterialisationService` — assembles the `Verplichting` + regels from
   the source object and calls the **existing** `MandaatEnforcer` + `BudgetBlocker`
   + `BudgetImpactEmitter`. It contains no budget/commitment arithmetic of its own.

Everything else is declarative (see below). The imperative surface stays within the
thin-glue budget (≤20 LOC across ≤2 files) because all domain logic already lives
in the reused guards.

## Declarative-vs-imperative decision (ADR-031)

- **Declarative (`lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json`):**
  - The committed-vs-realised **per-budget-line aggregation** —
    `x-openregister-aggregations` grouping `VerplichtingRegel` by budget
    coderingscombinatie (REQ-VPL-011). No PHP reporting service.
  - The optional rechtmatigheid/provenance **reference field** on `Verplichting`
    and the existing budget-blocking `x-openregister-lifecycle` (unchanged).
  - Operator notification on denied commitments via `x-openregister-notifications`
    (reusing the existing dialect).
- **Imperative (justified — event glue / external trigger):**
  - The materialisation listener + service (thin glue reacting to a lifecycle
    transition to assemble an object and call declared guards). This is allowed as
    integration glue; the budget decision itself remains in the declared
    `BudgetBlocker` guard, not in the glue.

## Trade-offs

- **Listener-materialisation vs. a declarative cross-schema lifecycle action.**
  Chosen: a listener, because building N per-boekjaar regels from a PO's lines and
  routing through mandaat/budget guards is procedural assembly OR does not express
  declaratively today. Alternative (encode PO→Verplichting as a declarative
  relation-materialisation) is rejected as beyond current OR capability and would
  fork the guard logic.
- **Per-line aggregation vs. reusing per-programma REQ-VPL-009.** Chosen: add a
  finer per-line aggregation; the per-programma view stays. Controllers need line
  granularity to answer "which budget line is over-committed", which the programma
  rollup hides.
- **Fail-closed on insufficient budget at approval.** Chosen: deny the approval
  (surfacing the existing message) rather than approve-then-warn, so an unfunded
  commitment can never silently reserve nothing.

## Migration Plan

Additive. Deploy the register fragment (aggregation + optional ref field), then the
service + listener, then the drilldown view. Rollback = unregister the listener and
revert the fragment/view; existing commitments and budgets are untouched.

## Open Questions

- Whether a first-class `Contract` `signed`/`executed` transition exists at apply
  time; if not, the contract branch of the listener is registered but dormant until
  contract-lifecycle-management lands. Does not affect the PO branch.
