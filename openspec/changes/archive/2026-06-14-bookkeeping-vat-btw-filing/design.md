# Design — VAT / BTW Filing

## Context

VAT compliance is mandatory in the Netherlands. Belastingdienst
requires quarterly (or monthly for certain regimes) electronic filing
with exact VAT amounts by rate and type (collected, paid, reverse-charge).
Current Shillinq foundation (T1/T2) has general ledger but no VAT return
workflow. Operators export GL data manually and prepare returns in
external tax software, introducing reconciliation gaps and duplication.

This capability closes the gap: VAT is derived declaratively from GL
transactions marked `vatApplicable: true` and `vatRate`; returns are
generated automatically with full reconciliation; operators review and
submit.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire VAT-filing surface as **declarative metadata** —
  schemas + lifecycle + aggregations + manifest entries — per ADR-031.
- Consume OR's lifecycle extension for VAT return state machine
  (`draft → submitted → verified → filed`) — per ADR-022. Zero parallel
  workflow table.
- Make the spec a **competent tax accountant readable contract** —
  Dutch VAT flow recognisable end-to-end (GL marking, VAT tracking,
  return prep, review, submission).
- Support VAT regime variants: standard rate (21%), reduced rates (9%,
  0%), small-business exemption (KOR), reverse charge (intra-EU imports
  / services).
- Declare VAT reconciliation as aggregations (sum VAT from GL by rate
  and type) so returns are trustworthy.
- Carry forward the bookkeeping foundation's VAT-aware account structure
  (`Account.vatApplicable`) under the declarative T3 envelope.

## Non-Goals

- No PHP VAT calculation service, no `VATReturnService.php`.
- No electronic filing gateway integration to Belastingdienst — T4 or
  dedicated integration.
- No VAT posting automation on invoice/purchase-order issue — GL entry
  creation is T2 responsibility (AP/AR invoicing creates GL postings
  that this spec reads).
- No advanced regimes — margin scheme, consignment, second-hand goods,
  multi-country flows.
- No MTD (Making Tax Digital) UK implementation — tracked as future
  variant.

## Decisions

### D1 — VAT data is sourced from GL transactions, not re-entered

`VATLine` is derived from `GLTransaction` records where the account
has `vatApplicable: true`. No separate VAT master table. VAT amounts are
computed from GL posting amounts and stored in `VATLine` for audit trail.
Operators cannot manually override VAT amounts — they must adjust the GL
entry, which re-derives the VAT line.

Rationale: Single source of truth (GL); audit trail is automatic;
prevents reconciliation gaps.

### D2 — VAT return lifecycle is consumed from OR's lifecycle extension

`VATReturn` declares the state machine (`draft → submitted → verified →
filed`) via OR's `x-openregister-lifecycle` extension. No app-local
workflow state table. Transitions are operator-triggered; audit trail
comes from OR's lifecycle extension per ADR-022.

Rationale: Consistent with T2 compliance workflows; no duplicate
state management.

### D3 — VAT reconciliation is declarative aggregation, not a service

Credit-check and balance verification use `x-openregister-aggregations`:
- `SUM(VATLine.vatAmount where type='collected')` = total VAT payable
- `SUM(VATLine.vatAmount where type='paid')` = total VAT deductible
- `VAT balance = paid - collected` (negative = owe, positive = refund)

No PHP `VATReconciliation` service.

Rationale: Pure arithmetic; aggregation extension handles caching and
performance.

### D4 — VAT regimes are variants, not separate registers

Standard (21%/9%/0%), KOR (small-business exemption), reverse-charge
are `VATReturn.regime` enum values. Each regime changes which GL
accounts / rates apply, but the return structure is identical. Manifest
entries show / hide regime-specific reports based on configuration.

Rationale: Single schema; configuration-driven behaviour; easier to
audit and migrate.

### D5 — VAT lines are immutable once VAT return is submitted

After operator submits `VATReturn` to `submitted` state, new GL postings
in the same period do not auto-append to the return (avoid re-opening
filed returns). Instead, operator explicitly rebases the return
(`draft` state transition) to include new postings, then re-submits.

Rationale: Audit trail integrity; prevents "month was already filed,
but we added 3 more invoices" creep.

### D6 — Reverse-charge VAT is flagged, not auto-calculated

`VATLine.type = 'reverse-charge'` is set by operator (or derived from
AP invoice `reverseChargeApplicable` flag) at GL entry or invoice time.
T3 does not auto-detect intra-EU vs. import scenarios — T4 or external
integration will handle rule-based classification.

Rationale: Rule complexity deferred; operators explicitly mark edge cases;
fewer false positives.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| VAT return lifecycle | OR `x-openregister-lifecycle` (ADR-022) | Lifecycle on `VATReturn` (`draft → submitted → verified → filed`); transitions operator-triggered |
| VAT collection tracking | GL transactions from T2 (`GLTransaction`, `JournalEntry`) | `VATLine` derived from GL where `Account.vatApplicable = true` |
| VAT reconciliation | OR `x-openregister-aggregations` | Aggregations: `SUM(VATLine) by rate`, `SUM(VATLine) by type` |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions + VAT line derivation |
| Manifest navigation | T1 manifest pattern | 2 entries (VAT Returns list, VAT Reports dashboard) + their pages |
| Account VAT flag | T1 `Account` schema (`vatApplicable: boolean`) | Already defined; reused to filter GL when populating VAT lines |
| Account type enum | T1 `Account` schema (`accountType: enum`) | Already defined; used to identify VAT-bearing transactions (revenue, expenses) |

**Net new code in implementation cycle**: 3 schema declarations + 1 lifecycle
block + 3 aggregations + 2 manifest entry pairs. Zero PHP services (all
logic declarative).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| VAT return lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| VAT amount derivation | Computed on GL posting (T2 responsibility) + stored in `VATLine` for audit | GL is SSOT; VAT line is immutable once derived |
| VAT reconciliation | Declarative (`x-openregister-aggregations`) | Pure SUM + GROUP BY |
| Regime logic (standard, KOR, reverse-charge) | Config-driven enum on `VATReturn.regime`; manifest rules hide regime-specific reports | Simple variant selection; rule application deferred to implementation |
| VAT submission + verification | Lifecycle state transition (operator-triggered) | No new service; audit trail from OR extension |

No service class authored in this envelope. All VAT logic is declarative.

## Seed Data

Three example `VATReturn` records for different regimes and periods,
seeded with sample `VATLine` records showing standard/reduced rates and
reverse-charge scenarios:

1. **Q1 2026 Standard Rate (21% VAT)**
   - VAT collected: €3,150 (from €15K sales)
   - VAT paid: €2,100 (from €10K purchases)
   - VAT balance: €1,050 owed
   - Status: draft

2. **Q1 2026 KOR (Small-Business Exemption)**
   - No VAT collected (KOR exempt)
   - No VAT paid (KOR exempt)
   - VAT balance: €0
   - Status: submitted

3. **Q2 2026 Reverse-Charge (Intra-EU)**
   - VAT collected: €1,890 (services incl. reverse-charge)
   - VAT paid: €2,000 (reverse-charge on intra-EU purchase)
   - VAT balance: €-110 refund
   - Status: draft

Each seeded return links to 5-8 `VATLine` records showing line-by-line
GL account, amount, VAT amount, type (collected/paid/reverse-charge),
and rate (21%/9%/0%).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| GL posting to VAT line derivation is lossy (many GL → one VAT line) | Store full GL account number + line reference in `VATLine.glAccountNumber` and `VATLine.glTransactionId` for audit trail |
| VAT regime switching mid-period creates reconciliation debt | Document in spec: regime change requires return rebasing (`draft` transition); operator responsibility to verify before submit |
| Reverse-charge classification is manual → error-prone | Operator flags at invoice/GL entry time; T4 or integration adds rule-based classification later; T3 spec does not auto-detect |
| Belastingdienst filing format may change (monthly → quarterly or vice versa) | `VATReturn.period` is flexible enum (week/month/quarter/year); manifest rules apply period constraints per regime and version |
| Reduced rates (9%, 0%) have complex scoping rules (food, books, energy, etc.) | Spec declares rate as a field; operator responsibility to select correct rate; T4 or integration adds smart rate suggestion |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the three
   schemas (`VATReturn`, `VATDeclaration`, `VATLine`).
2. `src/manifest.json` is patched with 2 new menu entries (VAT Returns,
   VAT Reports) + their pages.
3. Seed data (3 example returns with lines) loaded via
   `ConfigurationService::importFromApp()` (idempotent).

Down-direction: registers are non-destructive — reverting removes
entries from manifest and stops seeding; no DB migration.
