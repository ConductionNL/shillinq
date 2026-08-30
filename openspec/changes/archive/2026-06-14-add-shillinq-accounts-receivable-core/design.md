# Design — Accounts Receivable (Core)

**Status:** pr-created

## Context

Customer invoicing was the original Shillinq scope. AR is the
operational completion of the AP/AR pair. Per ADR-022, dunning
workflow comes from OR's dunning-workflow extension, not from an
app-local table. Per ADR-031, AR aging + credit-limit checks are
declarative aggregations, not PHP report services.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire AR-core surface as **declarative metadata** —
  schemas + lifecycle + aggregations + manifest entries — per
  ADR-031.
- Consume OR's dunning-workflow abstraction — per ADR-022. Zero
  parallel dunning table.
- Make the spec a **competent-bookkeeper readable contract** —
  Dutch SMB AR flow recognisable end-to-end (customer intake,
  invoice issue, dunning escalation, payment match, GL posting,
  aging, write-off).
- Carry forward the **original Shillinq invoicing scope** under
  the declarative T2 envelope.
- Declare the UBL 2.1 / Peppol BIS 3.0 field shape so T4 can
  attach outbound emission additively.

## Non-Goals

- No PHP dunning service, no `ARInvoiceService.php`.
- No UBL 2.1 / Peppol BIS 3.0 outbound emission — T4.
- No multi-currency translation — T5.
- No VAT/BTW posting automation — T3.

## Decisions

### D1 — AR is a sub-ledger that materialises GL transactions

Symmetric to D1 of `add-shillinq-accounts-payable-core`: `ARInvoice`
is a sub-ledger register; issuing an AR invoice materialises a
balanced `GLTransaction` per the T1 `JournalEntry` pattern.

### D2 — AR dunning is consumed from OR's dunning-workflow

`ARInvoice` declares the dunning policy by FK to a dunning-policy
record managed in OR. The dunning workflow (reminder 1 at +14
days, reminder 2 at +30 days, formal notice at +45 days, debt
collection escalation at +60 days — all customisable per
administration) runs in OR's engine; shillinq carries no app-local
dunning service.

If OR's dunning-workflow extension is not yet stable, ADR-031's
exception path applies: a single-method
`OCA\Shillinq\Lifecycle\DunningGuard` ships, cited in the spec.

### D3 — Write-off is a lifecycle transition that materialises a compensating GL posting

`written-off` is a terminal state. The transition emits a balanced
compensating posting (debit write-off expense, credit AR control)
through the same materialisation extension T1 uses for `JournalEntry`.
No PHP write-off service.

### D4 — Credit-limit check is an aggregation precondition

On `ARInvoice.issue`, a declarative precondition checks that
`SUM(outstanding ARInvoice.amount where customerId = X) +
this.amount <= CustomerMaster.creditLimit`. Pure aggregation; no
PHP service.

### D5 — AR aging is an aggregation

Symmetric to D5 of AP-core: GROUP BY `(customerId, agingBucket)`
where buckets are computed from `(today - dueDate)` thresholds.
`paid` / `written-off` invoices excluded.

### D6 — UBL 2.1 / Peppol BIS 3.0 field shape declared, NOT computed

T2 declares the UBL canonical field shape on `ARInvoice` (line
items, tax breakdown, party identifiers) so T4 can attach the
outbound emission additively. T2 does not compute or emit UBL —
that ships with T4 e-invoicing outbound.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| AR invoice lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `ARInvoice` (`draft → issued → paid` / `overdue` / `disputed` / `written-off`); materialises balanced `GLTransaction` per T1 pattern |
| AR dunning workflow | OR dunning-workflow (if stable; else gap) | Consumed via lifecycle reference; PHP guard fallback per ADR-031 exception if needed |
| Credit-limit check | OR `x-openregister-aggregations` | Aggregation precondition on `ARInvoice.issue` |
| AR aging | OR `x-openregister-aggregations` | GROUP BY `(customerId, agingBucket)` excluding paid/written-off |
| Materialised GL posting (issue + write-off) | T1 `JournalEntry` materialisation pattern | Same lifecycle action shape |
| Source-document attachment | T2 `bookkeeping-document-attachment-integration` | `ARInvoice.sourceDocumentUri` consumes the contract |
| Customer master | New T2 register (or OR contact abstraction if stable) | Per ADR-022 review |
| Payment matching | T2 `bookkeeping-bank-reconciliation` (`MatchingRule` + `ReconciliationMatch`) | Bank-rec emits candidate match; operator confirms; AR lifecycle transitions to `paid` |
| UBL 2.1 / Peppol BIS 3.0 field shape | UBL public standard | Declared as schema fields; not computed in T2 |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions |
| Manifest navigation | T1 manifest pattern | 4 entries (Customers, AR, AR Aging, Dunning) + their pages |

**Net new code in implementation cycle**: 3 schema declarations + 2
lifecycle blocks + 2 aggregations + 4 manifest entry pairs. At most
1 single-method PHP guard (`DunningGuard`) gated by ADR-031
exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| AR invoice lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| AR dunning workflow | Consumed from OR dunning-workflow if stable; else single-method `DunningGuard` per ADR-031 exception | Resolution in discovery; spec shape-neutral |
| Credit-limit precondition | Declarative (`x-openregister-aggregations` predicate) | Pure SUM check |
| AR aging | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM + bucket calc |
| Write-off compensating posting | Lifecycle action invoking T1's materialisation extension | No new service |
| Payment matching → `paid` transition | Lifecycle transition triggered by operator confirmation of bank-rec match | No new service |
| UBL field shape | Declarative — schema fields | Declared, not computed in T2 |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `DunningGuard`).

## Seed Data

None. Customers + AR invoices are operator-authored on first use;
no templates.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR dunning-workflow not yet stable | Spec shape-neutral; PHP guard fallback (`DunningGuard`, single-method, ~20 LOC) per ADR-031 exception; remove when OR extension lands |
| Customer master overlaps with OR's contact abstraction | Per ADR-022 review during implementing cycle |
| Write-off path creates audit-trail gap | Write-off transition requires audit-trailed reason; compensating posting captured by GL audit |
| UBL field shape drifts before T4 lands | Pin UBL 2.1 / Peppol BIS 3.0 in the spec; T4 attaches additively |
| Credit-limit aggregation slow with many open invoices | Pre-aggregated cache via OR's aggregation extension if performance gates trip; per-spec optimisation in implementing cycle |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the three
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 4 new menu entries + their
   pages (additive).
3. If OR's dunning-workflow extension is not yet stable,
   `lib/Lifecycle/DunningGuard.php` ships (single method, ~20 LOC,
   ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; AR invoices remain queryable but unreferenced.

## Open Questions

1. **OR dunning-workflow stability** — resolved in `opsx-ff`
   discovery; OR issue filed if needed.
2. **Customer master vs OR contact** — resolved per ADR-022 review.
3. **Default dunning cadence** — defaults to industry-standard
   (reminder 1 at +14, reminder 2 at +30, formal notice at +45,
   collection at +60); customisable per administration; resolved
   during implementing cycle's UX review.
