# Proposal: bookkeeping-verplichtingenadministratie

`kind: config` per ADR-032 — the centre of mass is declarative schema metadata + lifecycle definitions + seed data for commitment accounting.

## Summary

Add **verplichtingenadministratie** (commitment accounting) to Shillinq's bookkeeping engine. This capability introduces a first-class `verplichting` (commitment) register that tracks the full lifecycle of organisational financial commitments — from signing an inkooporder (PO), raamovereenkomst (framework agreement), arbeidscontract (employment contract), or subsidiebeschikking (subsidy decision) through delivery, invoicing, payment, and closure. It fundamentally changes budget management from "budget minus realised" to **"budget minus realised minus outstanding commitments"**, where budgetholders see their available budget decrease at the moment a PO is signed, not when an invoice is received.

Verplichtingenadministratie is mandatory best practice for Dutch decentrale overheden (municipalities, provinces, water boards) and increasingly required by internal auditors and external accountants. For commercial MKB customers, it is the foundation for cash-flow forecasting and credit-limit management. This change delivers the register, schemas, lifecycle management, and three-step accounting workflow (committed → received → invoiced) that every Dutch operator needs in their first month.

## Motivation

### Current State

Shillinq's T1–T2 bookkeeping foundation provides:

- Double-entry general ledger (T1: `bookkeeping-general-ledger`)
- Chart of accounts aligned to RGS (T1: `bookkeeping-chart-of-accounts`)
- Period close and trial balance (T2: `bookkeeping-period-close`)
- Accounts payable and accounts receivable (T2: `bookkeeping-accounts-payable-core`, `bookkeeping-accounts-receivable-core`)
- Budget tracking (T1: `Budget` register)

This is sufficient for **bookkeeping** (registering past transactions) but insufficient for **Dutch-compliant administration** (managing future obligations).

### Gap Addressed

Every Dutch organisation using Shillinq immediately asks:

1. **How do I track what I've promised to pay?** — A PO signed on 2026-04-15 for EUR 50.000 is a legal obligation, not yet an invoice or a cash outflow. Today's budget register doesn't reflect this commitment; only invoices reduce "available budget".
2. **How do I forecast cash flow?** — Without a verplichting register, CFOs can't distinguish between orders placed (not yet shipped), goods received (not yet invoiced), and invoices received (not yet paid). The three-step pipeline is invisible.
3. **How do I comply with BBV and rechtmatigheidsverantwoording?** — Dutch municipalities must report "openstaande verplichtingen" (outstanding commitments) as a separate category in their accountability; the toetsing (compliance review) is most efficient if applied at the commitment stage (one PO covers hundreds of partial invoices).
4. **How do I manage multi-year contracts?** — A 4-year maintenance raamovereenkomst at EUR 100.000/year must block EUR 100.000 on each year's budget, with jaarlijkse afroepen (annual call-offs) consuming that year's portion only.
5. **How do I mandate-check who can sign?** — Without a verplichting register, there's no point to enforce that a certain person can only sign orders up to EUR 50.000. The enforcement has nowhere to land.

All five gaps are **non-negotiable** for go-live in any Dutch municipality. Today they require either:

- Manual spreadsheet tracking (audit risk, reconciliation burden, no audit trail).
- Bespoke PHP code in shillinq (maintenance debt, no reuse, blocks other operators).
- A separate best-of-breed procurement app (license cost, reconciliation between systems, data fragmentation).

This proposal closes all five gaps in one declarative register.

## Affected Projects

- [x] **shillinq** — adds 6 new OpenRegister schemas to `lib/Settings/shillinq_register.json`: `verplichting`, `verplichtingsregel`, `verplichtingsmutatie`, `mandaat`, `goedkeuringsstap`, and an extension to `budget` with `vrije_ruimte`, `openstaande_verplichtingen` fields. Adds seed data for mandaat templates and bbv-programma mappings. Adds manifest navigation entries for commitment administration. No PHP service classes; all lifecycle and workflow declared via `x-openregister-lifecycle` and `x-openregister-workflow` per ADR-031.
- [ ] **openregister** — no source changes; this change consumes existing OR abstractions (lifecycle, audit, workflow, RBAC, mappings). Cross-app dependency: OR's `x-openregister-aggregations` must support period-filtered sums for budget-tracking; known to be supported.
- [ ] **openconnector** — no source changes; verplichtingenadministratie consumes the procurement import sources (TenderNed, PEPPOL UBL) declared in other changes.
- [ ] **docudesk** — no source changes; PO documents and signed contracts are stored via docudesk URIs linked from `verplichting.documenten`.

## Scope

### In Scope

- **Core register `verplichting`** — master entity tracking a single commitment (PO, contract, subsidy award). Fields: verplichting snummer, soort (inkooporder | raamovereenkomst | arbeidscontract | subsidiebeschikking | huurovereenkomst | leasing | overig), aangaandatum, looptijd, tegenpartij, totaalbedrag (excl./incl. VAT), status lifecycle (concept → in_goedkeuring → aangegaan → deels_geleverd → deels_gefactureerd → deels_betaald → afgesloten → geannuleerd).
- **Register `verplichtingsregel`** — line items on a verplichting; one per budget coderingscombinatie (programma, kostenplaats, boekjaar, grootboekrekening). Tracks bedrag_excl_btw, verwacht_geleverd_op, geleverd_bedrag, gefactureerd_bedrag, betaald_bedrag, restant_verplicht.
- **Register `verplichtingsmutatie`** — immutable audit log of every change: aangegaan, verhoogd, verlaagd, prestatie_ontvangen, gefactureerd, betaald, afgesloten, geannuleerd. Each mutation carries date, soort, bedrag, toelichting, FK to gerelateerde_factuur and gerelateerde_betaling.
- **Register `mandaat`** — authorization record defining who can sign commitments up to what amount, for which soort_verplichting, with second-signature thresholds. Enforced via lifecycle precondition on verplichting state-change.
- **Register `goedkeuringsstap`** — workflow step for commitment authorizations that exceed a mandaathouder's ceiling. Links verplichting → required approval role → assigned user → status (wachtend | in_behandeling | goedgekeurd | afgewezen | teruggezonden).
- **Budget extension** — `vrije_ruimte = geautoriseerd_bedrag - gerealiseerd_bedrag - openstaande_verplichtingen` field added to `Budget` register, recalculated on every verplichting state-change. Drives budget-blocking logic.
- **Lifecycle `verplichting`** — declarative state machine: concept → in_goedkeuring (if mandaat exceeded) → aangegaan (with mutatie) → deels_geleverd (on receipt) → deels_gefactureerd (on invoice match) → deels_betaald (on payment) → afgesloten (with rest-release) | geannuleerd.
- **Three-step accounting** — separates committed (PO signed), received (GR recorded), and invoiced (AP matched). Each step has its own verplichtingsmutatie and may affect budget differently: commitment blocks budget immediately; receipt is informational; invoice unblocks pending portion, moves to AP.
- **Mandate enforcement** — verplichting state-change to `aangegaan` checks mandaat.houder's límite, triggers approval-workflow if exceeded, emits audit trail on mandate check. Supports second-signature thresholds.
- **Multi-year support** — raamovereenkomst with looptijd spanning multiple boekjaren creates one verplichtingsregel per year, blocking budget on each year-end separately. Jaarlijkse afroep (annual call-off) consumes only that year's regel.
- **Budget-blocking logic** — REQ-VPL-001: when verplichting state changes to `aangegaan`, openstaande_verplichtingen increases and vrije_ruimte decreases. If vrije_ruimte would go negative and user lacks override-mandate, status change is rejected with "insufficient budget" error.
- **Salaris and personnel commitments** — arbeidscontract soort with automatic montly realisatie from loonbetaling.
- **Subsidie commitments** — subsidiebeschikking soort with voorschot / eindbetaling workflow and terugvordering.
- **Rechtmatigheidstoetsen integration** — verplichting can carry attached rechtmatigheidstoetsen (compliance checks) per spec `bookkeeping-rechtmatigheidsverantwoording`; factuur-level checks can reference PO-level checks, reducing audit work.
- **BBV / IV3 integration** — verplichtingsregel.programma field ties commitment to BBV program; IV3 quarterly export includes openstaande_verplichtingen per program.
- **Three-way match** — factuurverwerking checks for valid verplichting; REQ-VPL-005 prevents facturering without PO above EUR 5.000.
- **Seed data** — mandaat template library (common organisatie-types: gemeente, province, waterschap, commercial MKB), bbv-programma mappings (if applying), default soort-dropdowns.

### Out of Scope

- **Procurement RFQ / tender workflow** — that is `bookkeeping-purchase-order` (separate change, consumes this change's verplichting as foundation).
- **E-invoicing / PEPPOL integration** — OpenConnector sources for UBL / PEPPOL bill reception land in a separate change; verplichtingenadministratie consumes the results via three-way match.
- **Budgetoverheveling workflow** — if a verplichting would exceed budget, triggering an automatic budgetoverheveling request is out of scope; today's mitigation is "reject with message; user must file budgetoverheveling first".
- **VAT reverse-charge (verlegging) on commitments** — out of scope; reverse-charge tracking lives in `bookkeeping-vat-btw-filing`.
- **Verplichting-to-verplichting cascading** — e.g., a gemeente that sub-contracts to another gemeente and needs to track the upstream commitment — out of scope (future: `bookkeeping-intercompany-verplichtingen`).

## Approach

The change adds 6 schemas and 8 requirements (REQ-VPL-001 through REQ-VPL-008) per the conduction-schema format (RFC 2119, GIVEN/WHEN/THEN scenarios). Each requirement is traceable to the cited Dutch standard (BBV, Wet IB, Aanbestedingswet, NEN 7522, etc.). The only PHP code is in lifecycle precondition guards for mandate-checking and budget-blocking; all state machines, audit trails, workflows, and aggregations are declarative per ADR-031.

Dependency chain (per ADR-032):

```
T1: chart-of-accounts, general-ledger, journal-entries, budget
T2: accounts-payable-core, accounts-receivable-core, period-close
bookkeeping-verplichtingenadministratie:
  depends_on: [T1.chart-of-accounts, T1.general-ledger, T1.budget, T2.accounts-payable-core]
```

## New Dependencies

None external. This change consumes:

- T1's `Account` register and `Budget` register (extends).
- T2's `Invoice` register and `Payment` register (for three-way match and three-step accounting).
- OR abstractions: `x-openregister-lifecycle`, `x-openregister-workflow`, `x-openregister-audit`, `x-openregister-aggregations` (budget calculations), `x-openregister-mappings` (BBV program).
- OpenConnector sources (for procurement import, docudesk for document storage) — external, registered separately.

## Impact

- `lib/Settings/shillinq_register.json` — adds 6 schemas: `verplichting`, `verplichtingsregel`, `verplichtingsmutatie`, `mandaat`, `goedkeuringsstap`, extends `Budget` with `vrije_ruimte` and `openstaande_verplichtingen` fields.
- `lib/Settings/seeds/` — adds: `mandaat-templates.json` (10–15 common patterns: gemeente-default, province-default, MKB-default, custom), `bbv-programma-mapping.json` (if BBV-enabled administration).
- `src/manifest.json` — adds ~4 navigation entries: Verplichtingenregister (list), Verplichtingdetail (detail), Mandaten (list), Budgetbewaking (dashboard).
- PHP lifecycle guards: two single-method guards (~20 LOC each): `MandaatEnforcer::checkAndEnforce($verplichting)` (validates mandate level and triggers approval if exceeded) and `BudgetBlocker::validateBudgetRoom($verplichting, $bedrag)` (checks vrije_ruimte, emits error if insufficient).
- Repair step extension — imports seed mandaat and programma-mapping data on installation.
- No Vue components beyond manifest-driven generic index/detail pages.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle` and `x-openregister-aggregations` being stable. Lowest-risk: the aggregations for budget-tracking are simple period-filtered sums, well-tested in T2.
- **bookkeeping-accounts-payable-core** (T2) — three-way match (REQ-VPL-005) consumes AP Invoice register to verify invoice ↔ PO linkage. AP must be merged and stable before this change's opsx-apply.
- **bookkeeping-rechtmatigheidsverantwoording** (parallel) — optional integration: verplichting can carry attached toetsen; factuur-level toetsen can reference PO-level toetsen. If rechtmatigheidsverantwoording lands first, verplichtingenadministratie auto-consumes it; if later, the reference field is nullable and the feature gates on rechtmatigheidsverantwoording being installed.

## Risks

### Risk 1: Multi-year raamovereenkomst soort may have edge cases with budget-year boundaries and rollover

**Severity:** Medium  
**Mitigation:** The schema supports `looptijd_van` and `looptijd_tot` spanning years; `verplichtingsregel` is created one per boekjaar. If a raamovereenkomst's looptijd doesn't align to calendar years (e.g., 2026-05-01 to 2030-04-30), the regel-creation logic snaps to boekjaar boundaries (likely 2026, 2027, 2028, 2029, 2030 if the administration uses calendar years). A conditional check prevents mid-year regressions. Documented in `design.md`'s edge-cases.

### Risk 2: Budget-blocking on verplichting.aangegaan may trigger a spike in "budget exceeded" rejections

**Severity:** Medium  
**Mitigation:** This is the intended behaviour (budget constraint shifts left from invoice-time to commitment-time) but is a cultural shift for many operators. Mitigation: (1) comprehensive release notes explaining the change, (2) optional "dry-run" mode where budget-exceedance is logged but doesn't reject (configurable per administration), (3) mandate-override feature allowing a CFO to force-accept a commitment above budget with an override reason and audit trail.

### Risk 3: Mandate-checking logic may not account for shared mandate-holders or delegation

**Severity:** Medium  
**Mitigation:** `mandaat.houder` can be a single person, a role, or a team. OR's RBAC (per ADR-022) handles delegation via the `DelegationRule` register. If a mandaathouder goes out-of-office and delegates to a backup, the OR delegation is applied automatically — the verplichting lifecycle checks the effective mandaat-holder at commitment-time. Documented in design.md's workflow section.

### Risk 4: Three-way match logic (PO ↔ GR ↔ Invoice) may not exist for every supplier or soort_verplichting

**Severity:** Low  
**Mitigation:** REQ-VPL-005 specifies the match-required threshold (EUR 5.000); below that, invoices without PO can be posted with a warning. For soort_verplichting that don't have GR (e.g., arbeidscontract, subsidiebeschikking), the GR step is skipped. Documented in design.md's three-step accounting section.

### Risk 5: Terugvordering (subsidie refund) and Annulering (cancellation) may lead to negative verplichtingsmutatie bedragen; aggregation queries must handle sign correctly

**Severity:** Low  
**Mitigation:** `verplichtingsmutatie.soort` includes `geannuleerd` and `teruggevorderd` with negative bedrag; aggregation queries sum absolute values and track sign separately. Documented in schema + design.md's aggregation rules.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact because no implementation lands until `opsx-apply` is run. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR, run the repair step in down-direction. Registers are non-destructive — unused schemas remain queryable but unreferenced; seed data (mandaat templates, programma mappings) remains queryable. The only side effect of rollback is loss of the manifest navigation entries; the underlying data is preserved.

No data migration risk at the spec stage.

## Open Questions

1. **Multi-year raamovereenkomst boundary logic** — if looptijd_tot is mid-year, do we snap to year-end or to the exact date? Currently proposed as year-end snapping. Confirm with a gemeente CFO during spec review.
2. **Mandate override auto-approval** — when a commitmentt exceeds budget and mandate, does the CFO override require a second approver, or is CFO approval sufficient? Currently proposed as CFO approval + audit trail. Confirm with accountant persona.
3. **KvK / IBAN validation** — should tegenpartij.kvk and iban be validated against external registers (KvK public API, ING/Rabobank IBAN check)? Out of scope for now (future: `bookkeeping-vendor-master-validation`); tegenpartij accepts user-entered kvk/iban without lookup.
4. **Wijzigingen (amendments)** — REQ-VPL-006 mentions increasing a commitment from EUR 50k to EUR 60k. Does the EUR 10k increase re-trigger mandate-check? Yes (proposed). Confirm.
5. **Arbeid Werk Maatregel (AWM) labour contracts** — many municipalities have labour-pool contracts where workers are assigned on-demand. Model this as one raamovereenkomst (the pool) with jaarlijkse afroepen (individual assignments)? Or as individual arbeidscontracten? Recommend raamovereenkomst + afroepen. Confirm with HR persona.

