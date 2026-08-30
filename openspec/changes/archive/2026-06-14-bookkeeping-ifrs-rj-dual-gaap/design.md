# Design — Dual GAAP Reporting (IFRS naast Nederlandse Richtlijnen)

## Context

Nederlandse listed groups are obligated by EU-Verordening 1606/2002 to report consolidated
financials under IFRS; subsidiaries and family businesses often elect parallel RJ reporting for
benchmarking, financing, and M&A. The accounting divergence is material: IFRS 16 leases (+5–10%
asset swing), IAS 19 pension obligations (0–50% liability swing), IFRS 9 ECL provisioning
(+3–15% impairment), IFRS 15 revenue reclassification (+2–8%), IAS 36 impairments (+5–20%).

Manual RJ-to-IFRS reconciliation in Excel costs controllers 80–120 hours per quarter and is
error-prone (off-by-one in allocations, missed periodic adjustments, audit-trail gaps).

Shillinq's parallel-ledger architecture solves this: every GL transaction materialises to BOTH
RJ-ledger and IFRS-ledger; divergences are auto-classified; reconciliation-bridge calculations
explode the delta per standard; all trackable from consolidated IFRS output back to source invoice.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard
Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire dual-GAAP surface as **declarative metadata** — schemas + lifecycle +
  aggregations + manifest entries — per ADR-031.
- Automate parallel posting: one GL entry, two ledger postings (RJ + IFRS) with divergence
  classification.
- Surface RJ-to-IFRS differences per standard (LEASE_IFRS16, PENSION_IAS19, ECL_IFRS9,
  REVENUE_IFRS15, IMPAIRMENT_IAS36, deferred tax) in a drill-down reconciliation bridge.
- Enable multi-entity consolidation with automatic framework conversion (RJ subsidiaries →
  IFRS parent, or vice versa).
- Carry forward the original Shillinq bookkeeping scope (RJ + IFRS parallel) under the
  declarative T3 envelope.

## Non-Goals

- No PHP reconciliation service, no `DualLedgerEngine.php`.
- No real-time intra-month ECL re-staging — monthly/quarterly recalculation OK.
- No XBRL-NT or ESEF serialisation — T4 compliance export.
- No US-GAAP or HGB support — IFRS-EU + NL-GAAP-RJ only in T3 MVP.

## Decisions

### D1 — Dual posting is a single GL materialisation event with framework cross-refs

Every GL posting materialises ONE event in OR's GL-materialisation engine, but the event
spawns TWO journal entries (one RJ, one IFRS) linked via `GLLine.accountingFramework` enum
and `GLLine.frameworkJournalEntry` FK. Same audit-trail entry; two sub-ledger identities.

### D2 — Divergence classification is automatic + overrideable

When GL-materialisation fires (per T1 `JournalEntry` pattern), the engine examines
posted accounts and materialisation date; if a divergence reason-code applies
(LEASE_IFRS16, PENSION_IAS19, ECL_IFRS9, etc.), the `DualTransaction` record auto-populates.
Group-accountant MAY override with audit trail. No PHP classifier service.

### D3 — COA Mapping drives account allocation

`ChartOfAccountsMapping` declares (source_account RJ, target_accounts[] IFRS, allocation_rule).
Example: RJ "1530 Operationeel geleasede activa" → IFRS "1531 Right-of-Use asset" (70% of
PV future lease payments) + "2531 Lease liability current" (30%, short-term). Rule is
percentage, formula, or ratio-driver. Group-accountant builds mapping via wizard; validates
against test data before activation (REQ-DGAAP-002).

### D4 — ReconciliationBridge is a declarative aggregation per period

Per period + framework pair (RJ → IFRS), the bridge captures: opening RJ balance, adjustments
per standard (LEASE +€X, PENSION +€Y, ECL +€Z, REVENUE -€A, IMPAIRMENT -€B, TAX_EFFECT +€C),
closing IFRS balance. Pure aggregation query; no PHP bridge-calculation service.

### D5 — StandardSpecificCalculation captures the "why" for each divergence

IAS 19 projected-unit-credit liabilities, IFRS 9 ECL by stage, IFRS 16 incremental-borrowing-rate,
IFRS 15 CINCO model outputs, IAS 36 impairment inputs — all stored with inputs, outputs,
revaluation frequency, and (for IAS 19 + IFRS 9) actuary/validator signoff. Per REQ-DGAAP-005.

### D6 — FrameworkElection is per-entity, auditable, and scope-enforcing

`FrameworkElection` records: legal_entity, primary framework (IFRS-EU or NL-GAAP-RJ),
comply-or-explain motivation (small-entity exemption, group policy, etc.),
RJ variant (RJ-onverkort, RJk, IFRS-volledig), size-criteria attestation (balanstotaal,
netto-omzet, headcount), AVA-besluit reference. System warns on criteria overflow.
Per REQ-DGAAP-010.

### D7 — Drill-down is via FK cross-refs, not separate export

`GLLine.frameworkJournalEntry` → `DualTransaction` → `StandardSpecificCalculation`.
User clicks on consolidated IFRS number (e.g., "IAS 19 service cost €234k"), navigates
reconciliation bridge, lands on `StandardSpecificCalculation` (actuariële rapportage inputs),
digs into source `GLTransaction` + `DualTransaction`. All within OR's relation engine.

### D8 — Multi-entity consolidation conversion is order-independent per entity

Each subsidiary applies its own `FrameworkElection`. Parent's consolidation logic FIRST
converts RJ-reporting subsidiaries to IFRS via their `ReconciliationBridge`, THEN
eliminates intercompany transactions. Or parent uses dual-posted consolidation ledger
if full-dual is elected. No sequential ordering constraint; each entity's bridge is calculated
independently.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| GL materialisation framework | T1 `JournalEntry` materialisation pattern | Extend to dual-post per framework; `GLLine.accountingFramework` enum + `frameworkJournalEntry` FK |
| Lifecycle on framework election | OR `x-openregister-lifecycle` | `FrameworkElection` lifecycle: draft → active → superseded (on RJ-revision effective date) |
| Divergence reason-codes | IFRS/RJ standards domain knowledge | Pre-baked enum: LEASE_IFRS16, PENSION_IAS19, ECL_IFRS9, REVENUE_IFRS15, IMPAIRMENT_IAS36, BORROWING_COST_IAS23, DEFERRED_TAX_IAS12, BUSINESS_COMBINATION_IFRS3, etc. |
| COA-mapping validation | No parallel in legacy | COA-mapping wizard uploads test data, runs reconciliation bridge on test, requires 95% coverage before activation |
| Reconciliation-bridge aggregation | OR `x-openregister-aggregations` | Query: SUM(DualTransaction.adjustments) grouped by standard_code per period |
| Audit trail on framework changes | T2 `bookkeeping-audit-trail` | Automatic on `FrameworkElection` lifecycle transitions, COA-mapping edits, `StandardSpecificCalculation` updates |
| Consolidation-elimination order | T3 `bookkeeping-consolidation` | Parent applies subsidiary's `FrameworkElection.rjVariant`, then consolidation logic; bridge per entity independent |
| Actuary signoff (IAS 19) | External actuarial report | `StandardSpecificCalculation.actuary_signoff` links to docudesk FK for actuariële rapportage PDF |
| Manifest navigation | T1 manifest pattern | 4 entries: Framework Configuration, COA Mapping, Reconciliation Bridge, Dual Ledger Explorer |

**Net new code in implementation cycle**: 6 schema declarations + 2 lifecycle blocks +
3 aggregations + 4 manifest entry pairs + GL-materialisation extension for dual-posting.
At most 1 single-method PHP guard (divergence-classifier) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Dual GL posting | Declarative (T1 materialisation extension) + `GLLine.accountingFramework` enum | Pure state machine; no business logic |
| Divergence classification | Auto-classify + overrideable; no service | Reason-code enum is deterministic per account + date |
| COA-mapping allocation | Declarative formula/percentage/ratio-driver in register | Pure calculation; wizard UI for author, validation on save |
| Reconciliation-bridge calculation | Declarative aggregation (`x-openregister-aggregations`) | GROUP BY standard + SUM adjustments; no dynamic computation |
| Framework election scope enforcement | Lifecycle transition guards (IF RJ-criteria-overflow WARN) | Pure precondition check; no service |
| StandardSpecificCalculation revaluation | Declared frequency (monthly/quarterly/annual); OR scheduled-workflow triggers | Inputs/outputs stored; no recalc service |
| Audit trail on all changes | Automatic OR audit-trail capture | OR extension handles tracing |

No service class authored in this envelope (subject to ADR-031 exception: at most one
single-method divergence-classification guard if OR transaction-parallel is not stable).

## Seed Data

None. Frameworks, COA mappings, and framework elections are uploaded by group-accountant
on first-use. `StandardSpecificCalculation` records are populated by system on divergence
detection or imported from actuarial/valuation reports.

### Example: Lease divergence (IFRS 16 vs RJ 292)

```
DualTransaction:
  base_transaction_id: GL-2026-04-0512
  rj_journal_entries: [
    {account: "1530 Operationeel geleasede activa", debit: 24300, credit: 0},
    {account: "4120 Autokosten", debit: 450, credit: 0},
    {account: "1800 Crediteuren", debit: 0, credit: 24750}
  ]
  ifrs_journal_entries: [
    {account: "1531 Right-of-Use asset", debit: 24300, credit: 0},
    {account: "2531 Lease liability (current)", debit: 0, credit: 9744},
    {account: "2532 Lease liability (non-current)", debit: 0, credit: 14556},
    {account: "1800 Crediteuren", debit: 0, credit: 0}  // no liability under IFRS 16
  ]
  divergence_amount: 24300
  divergence_reason_code: "LEASE_IFRS16"
  divergence_classification: "temporary"

StandardSpecificCalculation (IAS 19):
  standard_code: "IFRS-16"
  contract_reference: "Lease-2026-AUTO-001"
  calculation_method: "incremental_borrowing_rate"
  inputs: {
    future_lease_payments: [450, 450, 450, 450, 450, ...],
    lease_commencement_date: "2026-04-15",
    lease_term_months: 60,
    incremental_borrowing_rate: 0.035
  }
  outputs: {
    rou_asset_initial: 24300,
    liability_current: 9744,
    liability_noncurrent: 14556,
    interest_expense_year1: 851
  }
  revaluation_frequency: "annual"
  audit_evidence_uri: "docudesk://lease-contract-2026-AUTO-001.pdf"
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Dual posting creates 2x GL volume | Standard GL housekeeping (monthly archive, GL truncate per fiscal year) applies to both ledgers separately; no doubling of data-retention cost |
| COA mapping is expert-driven | Wizard provides templates per industry (manufacturing, services, government); validator requires 95% coverage on test data |
| IAS 19 actuarial calcs are expensive | Delegated to external actuary; system stores inputs/outputs, not re-derives. Quarterly re-val OK, daily not required |
| IFRS 9 ECL staging re-calc monthly | Batched monthly (10th of month); intra-month estimation flagged as "stale" if queried |
| FrameworkElection criteria overflow unnoticed | System auto-warns on size-criteria breach; administrator MAY still elect larger-entity framework for compliance purposes |
| Audit-trail noise from dual posting | One audit event per GL posting, with two GLLine sub-entries; no duplication |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the six schemas (additive —
   no existing schema changes).
2. T1 GL-materialisation extension is patched to support dual-posting logic
   (additive to existing T1 implementation).
3. `src/manifest.json` is patched with 4 new menu entries + their pages (additive).
4. If OR transaction-parallel extension is not yet stable, a single-method
   `lib/Lifecycle/DivergenceClassifier.php` ships (per ADR-031 exception annotation).

Down-direction: registers are non-destructive — reverting removes the manifest entries;
dual GL entries remain queryable but unreferenced.

## Open Questions

1. **OR transaction-parallel extension stability** — resolved in `opsx-ff` discovery.
   If not stable, ADR-031 exception path applies.
2. **COA-mapping templates per industry** — defaults for manufacturing, services, government,
   nonprofit. Resolved during implementing cycle's UX review.
3. **IAS 19 actuary signoff workflow** — actuarial report uploaded, system extracts inputs
   (OCR + manual validation), group-accountant approves, actuary confirmation optional.
   Resolved in `StandardSpecificCalculation` workflow design.
4. **IFRS-9 ECL-stage auto-classification** — monthly batched on 10th; if customer migrates
   between stages, bridge captures the adjustment. Resolved in monthly-calc workflow design.
5. **Consolidation elimination order** — open until `bookkeeping-consolidation` proposal
   finalizes the multi-entity pattern.
