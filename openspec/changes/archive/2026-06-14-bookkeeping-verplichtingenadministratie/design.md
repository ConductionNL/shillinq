# Design — Verplichtingenadministratie (Commitment Accounting)

## Context

Verplichtingenadministratie (commitment or obligation accounting) is the bookkeeping layer that tracks **legal and financial commitments** before they become invoices or payments. In Dutch public-sector and MKB practice, it answers three critical questions that T1–T2 alone cannot answer:

1. **What have we promised to pay?** (PO signed, raamovereenkomst agreed, subsidy awarded)
2. **How much budget is actually free?** (Budget minus realised minus outstanding commitments)
3. **How do we audit a transaction before the invoice arrives?** (Compliance check at commitment-time, not invoice-time)

This change introduces a first-class `verplichting` register and declares all its state transitions, data flows, and integrations with budget and accounts-payable as declarative metadata per ADR-031. No PHP service classes; every behavior is expressible in OR's lifecycle, aggregation, and workflow abstractions.

## Goals

- **Track the full commitment lifecycle** — from signed PO / contract / subsidy award through delivery, invoicing, payment, and closure.
- **Block budget at commitment-time, not invoice-time** — correct the budget calculation to include outstanding commitments.
- **Enforce mandate (authorization) on commitment** — who can sign what commitment up to what amount.
- **Support Dutch regulatory requirements** — multi-year raamovereenkomsten, BBV program codeering, drie-staps boeking (committed ↔ received ↔ invoiced), rechtmatigheidstoetsen at commitment-level.
- **Enable three-way matching** — verplichting ↔ good receipt ↔ invoice, reducing post-invoice audit work.
- **Express every behaviour declaratively** — per ADR-031, no service classes, all state machines via `x-openregister-lifecycle`.

## Non-Goals

- **Procurement workflow** (RFQ, tender, award) — that's `bookkeeping-purchase-order`, a separate change.
- **Budgetoverheveling automation** — if commitment exceeds budget, user must manually file budgetoverheveling first; the system rejects and advises.
- **Verplichting-to-verplichting cascading** — inter-organisatie commitments (gemeente A subcontracts gemeente B) is future scope.
- **Reverse-charge VAT tracking** — VAT-specific commitment logic lives in `bookkeeping-vat-btw-filing`.
- **Bespoke Vue components** — manifest-driven generic index/detail pages only; no custom modals or dashboards beyond the budget-blocking dashboard.

## Decisions

### D1 — Verplichtingsmutatie as immutable audit log, not state mutation

Every state change to a verplichting (aangegaan, verhoogd, verlaagd, prestatie_ontvangen, gefactureerd, betaald, afgesloten, geannuleerd) is recorded as a separate immutable `verplichtingsmutatie` record. The verplichting.status field itself is derived from the latest mutation. This inverts the conventional "update the verplichting record" pattern:

**Conventional (rejected):**
```
UPDATE verplichting SET status='aangegaan', aangaandatum='2026-04-15' WHERE id='vpl-1'
```

**Declarative (this change):**
```
INSERT verplichtingsmutatie (verplichting, soort='aangegaan', bedrag=50000, datum='2026-04-15') VALUES ...
-- verplichting.status is a computed field derived from MAX(verplichtingsmutatie.datum) grouped by verplichting
```

**Why:** ADR-031 prohibits mutable state in service classes. OR's audit-trail-immutable gives us immutability for free; every mutation is both a functional change (the state transition) and an audit entry (the mutation record). No parallel audit table, no jotting down "who changed what" separately. The mutation record carries date, soort, bedrag, toelichting, gebruiker — everything an auditor needs.

**Alternative considered:** Store state on the verplichting record and use OR's audit-trail to log the change. Rejected — that's less transparent; an auditor looking at the verplichting sees today's status, not the full history in one place.

### D2 — Verplichtingsregel per budget coderingscombinatie

One PO may span multiple boekjaren, kostens plaatsen, programma's, and GL accounts. Rather than flatten this into a single row, each regel is a distinct budget-tracking entity:

```json
{
  "verplichting": "PO-2026-00874",
  "regelnummer": 1,
  "omschrijving": "Licentie 2026",
  "boekjaar": 2026,
  "bedrag_excl_btw": 62000,
  "kostenplaats": "KP-1042",
  "programma": "5.1",
  "grootboekrekening": "4310"
}
```

Each regel has its own geleverd_bedrag, gefactureerd_bedrag, betaald_bedrag, restant_verplicht fields. On a partially-paid 4-year raamovereenkomst, there are 4 regels (one per year), each tracking its own delivery/invoicing/payment independently.

**Why:** BBV and IV3 reporting require budget consumption per programma + boekjaar. If we flattened all lines into one verplichting record, budget queries would be complex joins. By giving each regel its own record, budget-blocking aggregation queries become simple period-filtered sums.

**Alternative considered:** Single line per verplichting, with a `budgetAllocations: [{ boekjaar, programma, bedrag }]` JSON array. Rejected — that's unqueryable; a budget-blocking check would require JSON subqueries or PHP deserialization. A normalized regel is queryable, auditable, and amendable per ligne.

### D3 — Mandate enforcement via lifecycle precondition, not separate permission check

When a verplichting is created and the user attempts to move it to `aangegaan`, the lifecycle engine checks:

```yaml
x-openregister-lifecycle:
  states:
    - aangegaan:
        requires:
          - name: mandate-check
            precondition:
              guard: "MandaatEnforcer::checkAndEnforce($verplichting)"
              onFailure: "approval-workflow"  # if mandate exceeded, route to approval-workflow
```

**Why:** ADR-031 states "authorization logic that gates state transitions lives in the lifecycle precondition, not in a separate permission layer." A user's right to sign a EUR 30k commitment is context-specific (their mandate level, the commitment soort, the current fiscal year), not a static entitlement. The lifecycle precondition is the right place to evaluate context.

**Alternative considered:** Embed mandate-check in OR's RBAC as a role attribute. Rejected — that would require a new RBAC concept (role attributes) and still wouldn't capture the context (mandaat amount depends on verplichting soort and date).

### D4 — Budget-blocking via aggregation on vrije_ruimte, not locked rows

The `Budget` register carries `vrije_ruimte = geautoriseerd_bedrag - gerealiseerd_bedrag - openstaande_verplichtingen`. On every verplichting state-change:

- `aangegaan` → openstaande_verplichtingen increases, vrije_ruimte decreases.
- `gefactureerd` → openstaande_verplichtingen decreases (invoice now owns the commitment), AP registers the payable.
- `betaald` → no change to openstaande_verplichtingen (invoice stage already released it).
- `afgesloten` with restant → openstaande_verplichtingen decreases by the restant amount (rest is freed back to budget).

**Why:** A single "lock" granularity (per verplichting or per regel?) is ambiguous. Aggregating at budget-period level (per boekjaar + programma + kostenplaats) gives finance the clear view they need: "programma 5.1 / 2026 has EUR 500k budget, EUR 200k realised invoices, EUR 150k outstanding commitments, EUR 150k free. If I sign a EUR 160k commitment, I exceed."

**Alternative considered:** Lock individual verplichting regels once aangegaan, preventing deletion or amendment. Rejected — that's not budget management, that's data protection; budget is the real constraint, and locking prevents legitimate amendments (e.g., increasing a commitment from EUR 50k to EUR 75k and re-approving).

### D5 — Three-step accounting: committed (verplichting.aangegaan) ↔ received (prestatie_ontvangen) ↔ invoiced (gefactureerd)

The verplichtingsmutatie soort enum includes:

- `aangegaan` — PO signed, commitment is now legal.
- `prestatie_ontvangen` — goods/services delivered, GR recorded (optional; some soort like arbeidscontract skip this).
- `gefactureerd` — invoice received, matched against PO, moved to AP register.
- `betaald` — payment run cleared.
- `afgesloten` — commitment fully realised or restant released back to budget.

Each step is a separate mutation and may affect budget tracking, GL posting, and AP status differently.

**Why:** NEN 7522 (Dutch procurement standard) and IPSAS 19 distinguish three stages. In shillinq terms: commitment-time (budget impact), receipt-time (inventory/asset impact), invoice-time (AP impact). Conflating any two loses auditability.

**Alternative considered:** Two steps only (committed and invoiced, no receipt). Rejected — many Dutch operators track GR for stock/inventory purposes separately; collapsing receipt into either commitment or invoicing loses that tracking.

### D6 — Mandaat as a register, not an enum

`mandaat` is a full register record with schema:

```json
{
  "mandaatcode": "M-INKOOP-50K",
  "naam": "Inkoopmandaat tot 50.000 EUR",
  "houder": "uuid|ref:gebruiker",
  "maximumbedrag": 50000.00,
  "soort_verplichting": ["inkooporder", "raamovereenkomst"],
  "vereist_tweede_handtekening_boven": 25000.00,
  "geldig_van": "2025-01-01",
  "geldig_tot": "2027-12-31"
}
```

**Why:** Mandates change over time (new director, new budget year, new delegations). A register allows operator-management: add a new mandaat for a new hire, update dates when a mandate expires, track historical mandates for audit. A hard enum would require code changes.

**Alternative considered:** Embed mandaat info as a computed role attribute in OR's RBAC. Rejected — role attributes in OR don't carry effective-date or second-signature thresholds; they're too simple for Dutch mandate complexity.

### D7 — Goedkeuringsstap workflow on mandate-exceeded, not parallel approval table

When a verplichting soort and amount exceed a mandaat, the lifecycle transitions to `in_goedkeuring` and creates a goedkeuringsstap record:

```json
{
  "verplichting": "PO-2026-00874",
  "stapnummer": 1,
  "rol_vereist": "directeur",
  "toegewezen_aan": "user-5",
  "status": "wachtend",
  "vereist_handtekening": true
}
```

The approval is tracked within shillinq via goedkeuringsstap, not delegated to OR's approval-workflow (which is consumed elsewhere). Each step can require a digital handtekening (file attachment).

**Why:** Mandaat-driven approvals are specific to procurement; not every approval in shillinq is mandate-driven. A dedicated goedkeuringsstap register gives finance a clear view of what approvals are pending on which commitments — "I'm waiting on the directeur's approval for PO-2026-00874 since 2026-04-16."

**Alternative considered:** Consume OR's approval-workflow for mandate-exceeded. Rejected — OR approval-workflow is generic; wiring a mandaat-specific escalation chain into it would obscure the intent.

### D8 — Three-way match (PO ↔ GR ↔ Invoice) as a lifecycle precondition on invoice posting

When an AP invoice is posted (REQ-VPL-005), the lifecycle precondition checks:

```yaml
received → matched:
  requires:
    - name: three-way-match
      precondition: >
        verplichting EXISTS
        AND verplichting.soort IN ('inkooporder', 'raamovereenkomst')
        AND invoice.bedrag <= verplichting.regel[matching-boekjaar].bedrag * (1 + tolerance)
        AND (GR_quantity <= PO_quantity OR soort_verplichting skips GR)
      onFailure: manual-review
```

**Why:** The three-way match is not a standalone register; it's a precondition check that gates the invoice from draft → received. This is the right place to enforce matching logic: as close to the action (invoice posting) as possible, via lifecycle, per ADR-031.

**Alternative considered:** A separate `ThreeWayMatch` register tracking matches independently. Rejected — that's a derived entity; the real logic is "can this invoice be posted?", not "did I record a match?"

### D9 — Budget calculations via `x-openregister-aggregations`, not cron job

`Budget.vrije_ruimte` is a calculated field:

```yaml
vrije_ruimte:
  type: calculation
  formula: "geautoriseerd_bedrag - gerealiseerd_bedrag - openstaande_verplichtingen"
  openstaande_verplichtingen:
    aggregation: SUM(verplichtingsregel.bedrag_excl_btw)
    where:
      - verplichting.status NOT IN ('afgesloten', 'geannuleerd')
      - verplichtingsregel.boekjaar = budget.fiscaalYear
      - verplichtingsregel.programma = budget.programmaCode
```

This aggregation is evaluated on-demand when the budget is queried, not via a nightly cron job.

**Why:** Budget is a real-time question ("Can I sign a EUR 160k commitment right now?"). A cron job would be stale and operationally fragile.

**Alternative considered:** Persist calculated vrije_ruimte on the Budget record, updated whenever a verplichting changes. Rejected — that's denormalization; multiple updates to the same verplichting would repeatedly touch the budget record, creating lock contention.

### D10 — Raamovereenkomst regels snapped to boekjaar boundaries, even if looptijd spans mid-year

A 4-year raamovereenkomst `looptijd_van='2026-05-01' looptijd_tot='2030-04-30'` will have regels for boekjaren 2026, 2027, 2028, 2029, 2030 (snapped to year-end) — even though the first and last years are partial. Each regel carries the full year's budget allocation; partial-year adjustment (e.g., EUR 50k for May–Dec 2026 instead of EUR 100k) is a manual override.

**Why:** Dutch budgeting is fiscal-year-based; administrators think in calendar-year buckets. Snapping to boekjaar boundaries makes budget queries simple. Partial-year adjustments are rare and operationally transparent when visible as regel amendments.

**Alternative considered:** Create 5 regels with exact date boundaries and pro-rata bedrag (EUR 58.33k for May–Dec, EUR 100k for full years, EUR 41.67k for Jan–Apr). Rejected — that's complex and doesn't match how budget-holders think.

## RBAC Role Inventory

Verplichtingenadministratie introduces 4 named roles, all scoped per administration:

| Role | Scope | Capabilities |
|---|---|---|
| `commitment-administrator` | per administration | create/read/update `verplichting`, `verplichtingsregel`; trigger `aangegaan` transition (subject to mandate check) |
| `budget-holder` | per programma or kostenplaats | read `verplichting`, `Budget`; receive budget-exceeded alerts; approve mandate-exceedance via goedkeuringsstap if in escalation chain |
| `finance-director` | per administration | read all commitment data; approve mandate-exceeded commitment; authorize budgetoverheveling |
| `auditor` | per administration (read-only) | read-only access to all verplichtingen, mutaties, mandaten; export audit trail |

These roles are declared in the `verplichting` schema's `x-openregister-rbac` block; no app-local RBAC code.

## Seed Data

Three seed files under `lib/Settings/seeds/`:

| File | Purpose | Rows | Citation |
|---|---|---|---|
| `mandaat-templates.json` | Common mandaat patterns for gemeente, province, waterschap, MKB | 10–15 | Mandaatregeling Gemeentewet 168 e.v., VNG Handreikingen |
| `bbv-programma-mapping.json` | Default mapping RGS account → BBV programma + paragraaf (gemeente only) | ~50 | BBV Bijlage III |
| `verplichting-soort-labels.json` | i18n labels for soort enum (inkooporder, raamovereenkomst, etc.) | 7 | Internal |

Each seed file carries `_meta: { source, version, imported }` so future revisions can coexist. All seeded data is editable per-administration (mandaat-templates are starting points, operator adds/modifies as needed).

## Declarative vs. Imperative (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Verplichting state machine (aangegaan → deels_geleverd → etc.) | Declarative (`x-openregister-lifecycle`) | Textbook state machine fit |
| Verplichtingsmutatie creation on state-change | Declarative (`x-openregister-lifecycle` action) | Each transition auto-creates the mutation record |
| Budget aggregation (openstaande_verplichtingen sum) | Declarative (`x-openregister-aggregations`) | Period-filtered sum over verplichtingsregels |
| Mandate-level check (does user have mandate to sign this amount?) | Thin PHP guard (~20 LOC): `MandaatEnforcer::checkAndEnforce($verplichting)` | Context-specific check (amount + soort + effective date) requires brief code; guard is called from lifecycle precondition, not a service method |
| Approval-workflow escalation (route to directeur if mandate exceeded) | Declarative (`x-openregister-lifecycle.requires` → `goedkeuringsstap` creation) | Workflow trigger + record creation is declarative |
| Three-way match (PO ↔ GR ↔ Invoice) | Thin PHP guard (~20 LOC): `ThreeWayMatcher::validateMatch($invoice)` if aggregation engine can't express the joins; otherwise declarative | Simple tolerance check + FK existence. If OR's aggregations can join verplichtingsregel ↔ GR ↔ Invoice with amount-tolerance, fully declarative; otherwise thin guard from lifecycle precondition |
| Pension contract automatic realisatie (monthly salary reduction) | Declarative via scheduled workflow | Each month-end, a scheduled workflow creates verplichtingsmutatie `betaald` for each employee's salary |
| Multi-year raamovereenkomst regel creation (snap to boekjaar) | Thin PHP code in repair step (~50 LOC) | Complex loop; not a recurring lifecycle action, so doesn't belong in lifecycle guards. Repair step is the right place for seeding complex initial state. |
| BBV programmacode mapping enforcement | Declarative (`x-openregister-mappings`) | Mapping table from GL account → programma; used on regel creation |

**Net new PHP: ~40 LOC across 2 guards + ~50 LOC in repair step for raamovereenkomst seeding. No service classes.**

## Migration Plan

Spec-only — no runtime changes in this proposal. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with 6 new schemas + Budget extension (additive).
2. `lib/Settings/seeds/` receives 3 new seed files.
3. `src/manifest.json` receives ~4 new menu entries (verplichting list, verplichting detail, mandaten list, budget-position dashboard).
4. Repair step extends to:
   - Import seed mandaat-templates and bbv-programma-mapping.
   - For any existing raamovereenkomst records (if already in the system via T4 procurement), create verplichtingsregel records (one per boekjaar).
5. No down-migration risk at spec stage.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Budget-blocking shift (budget impact at commitment, not invoice) may reject many legitimate commitments operators are used to committing beyond budget | Mitigation: (a) comprehensive release notes explaining the change, (b) optional "dry-run" mode (configurable per administration) where budget-exceedance is logged but doesn't reject, (c) mandate-override feature for CFO force-acceptance with audit trail. Confirm with gemeente finance director during spec review. |
| Mandate-checking may not handle shared-mandate scenarios (two signatories required to reach a threshold) | OR's delegation rules (per ADR-022) handle one-to-one delegation (A → B covers A's absence). Shared mandates (A + B = higher authority) require a future `bookkeeping-shared-mandaat` spec. Documented as out-of-scope. |
| Three-way match tolerance (how much variance before manual review?) is operator-specific | Tolerance is a configurable parameter per administration (default: 2%). Operator can adjust. Documented in design. |
| Subsidy terugvordering (refund) may create negative bedrag mutations; calculations must handle sign correctly | verplichtingsmutatie.bedrag can be negative (soort='geannuleerd' or soort='teruggevorderd'). Aggregations use ABS() or explicit sign-handling. Documented in schema. |
| Multi-year raamovereenkomst snapping to boekjaar may not match operator's contractual year | Mitigation: regel bedrag is editable per-line; operator can adjust a 2026-regel down from EUR 100k to EUR 58.33k if the contract is May–Dec. Documented as manual adjustment. |

## Open Questions for Review

1. **Mandate second-signature threshold** — if a commitment of EUR 30k falls between an individual's mandate (EUR 25k) and the threshold for requiring a second signature (EUR 25k–EUR 50k), is one approval enough or two required? Currently proposed: one approval to the next-level mandaathouder. Confirm with gemeente controller.
2. **Raamovereenkomst afroep matching** — if a raamovereenkomst has a regel for EUR 100k / 2027 and an invoice for EUR 25k arrives in 2027 March, does the invoice automatically match the 2027-regel, or does user manually select the regel? Proposed: automatic if only one applicable regel; manual pick if multiple. Confirm with procurement officer.
3. **Arbeidscontract monthly realisatie** — should it be a scheduled workflow that auto-creates verplichtingsmutatie `betaald` each month-end, or should it be manual? Proposed: auto. Confirm with HR / payroll persona.
4. **Budget-year vs. contract-year mismatch** — if a gemeente's budget-year is calendar (Jan–Dec) but a raamovereenkomst is fiscal (May–April), who resolves the conflict? Proposed: operator manually splits the raamovereenkomst into two if needed, or accepts the mismatch. Confirm with CFO.
5. **KvK / IBAN validation** — should tegenpartij.kvk and iban be validated against external data sources, or accepted as user-entered? Proposed: user-entered for now (external validation is a future change). Confirm scope.

