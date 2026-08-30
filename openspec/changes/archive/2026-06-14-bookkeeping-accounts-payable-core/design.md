# Design — Accounts Payable (Core)

## Context

Accounts payable is the operational mirror to AR. Vendors are paid per invoices
received. Aged payables reports with payment scheduling detail (showing upcoming
obligations by due date) are market-demanded features (demand score: 96 across
three survey variants: "Aged payables report with vendor payment scheduling
detail", "Aged payables report showing upcoming payment obligations", "Accounts
payable aging report showing upcoming vendor payments").

Per ADR-022, dunning workflow comes from OR's dunning-workflow extension, not
from an app-local table. Per ADR-031, AP aging + payment scheduling are
declarative aggregations, not PHP report services.

The change is **spec-only**. Implementation lands later through `opsx-apply` and
the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire AP-core surface as **declarative metadata** — schemas +
  lifecycle + aggregations + manifest entries — per ADR-031.
- Consume OR's dunning-workflow abstraction — per ADR-022. Zero parallel
  dunning table.
- Make the spec a **competent-bookkeeper readable contract** — Dutch SMB AP flow
  recognisable end-to-end (vendor intake, invoice receipt, dunning escalation,
  payment matching, GL posting, aging, write-off).
- Deliver **three aged payables report variants** (detail, summary, timeline)
  addressing the top-three market-demanded features.
- Declare the AP core data model as a thin operational overlay on the general
  ledger and chart of accounts.

## Non-Goals

- No PHP AP service, no `APTransactionService.php`.
- No Peppol BIS 3.0 inbound e-invoicing — future. Manual upload is T2.
- No multi-currency posting — T5. EUR-only in T2.
- No VAT/BTW reverse-charge automation — T3. Manual per-invoice configuration.

## Decisions

### D1 — AP is a sub-ledger that materialises GL transactions

`APTransaction` is a sub-ledger register; receiving an AP invoice materialises
a balanced `GLTransaction` per the T1 `JournalEntry` pattern.

### D2 — AP dunning is consumed from OR's dunning-workflow

`APTransaction` declares the dunning policy by FK to a dunning-policy record
managed in OR. The dunning workflow (reminder 1 at +14 days, reminder 2 at +30
days, formal notice at +45 days, debt collection escalation at +60 days — all
customisable per administration) runs in OR's engine; shillinq carries no
app-local dunning service.

If OR's dunning-workflow extension is not yet stable, ADR-031's exception path
applies: a single-method `OCA\Shillinq\Lifecycle\APGuard` ships, cited in the
spec.

### D3 — Write-off is a lifecycle transition that materialises a compensating GL posting

`written-off` is a terminal state. The transition emits a balanced compensating
posting (credit AP payable, debit expense) through the same materialisation
extension T1 uses for `JournalEntry`. No PHP write-off service.

### D4 — Aged payables is delivered as three aggregation variants

- **Detail**: per-invoice breakdown grouped by vendor + due-date bucket (most
  granular; ~200 rows for a typical SMB).
- **Summary**: by vendor (sum of all outstanding) grouped by aging bucket.
- **Timeline**: by due date (when do payments become due) grouped by amount.

All three are declarative `x-openregister-aggregations` queries, not PHP report
classes.

### D5 — Payee is a thin vendor master view

`Payee` (per ADR-011 schema.org `schema:Organization`) declares vendor contact
+ payment terms + dunning policy + credit limit. If OR's contact abstraction is
stable per ADR-022, `Payee` fields map through to the shared contact record;
otherwise app-local with documented migration path.

### D6 — AP aging bucket definition is administration-configurable

Bucket thresholds (current 0–30 days, 30–60, 60–90, 90+) are stored in
administration config (per ADR-022's `IAppConfig` pattern), not hardcoded in
the schema. This allows SMBs in strict payment disciplines (e.g., public sector)
to customize.

### D7 — Payment matching transitions AP invoices from issued → paid

When bank-reconciliation emits a candidate match against an AP invoice, the
operator confirms and the AP invoice transitions to `paid` via a lifecycle
action. No separate payment-matching service.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| AP invoice lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `APTransaction` (`draft → issued → paid` / `overdue` / `disputed` / `written-off`); materialises balanced `GLTransaction` per T1 pattern |
| AP dunning workflow | OR dunning-workflow (if stable; else gap) | Consumed via lifecycle reference; PHP guard fallback per ADR-031 exception if needed |
| Aged payables detail | OR `x-openregister-aggregations` | GROUP BY `(vendorId, dueDateBucket)` excluding paid/written-off; ordered by due date ASC |
| Aged payables summary | OR `x-openregister-aggregations` | GROUP BY `(vendorId, agingBucket)` with SUM(amount); ordered by aging severity DESC |
| Aged payables timeline | OR `x-openregister-aggregations` | GROUP BY `dueDate` with SUM(amount); ordered by due date ASC |
| Materialised GL posting (issue + write-off) | T1 `JournalEntry` materialisation pattern | Same lifecycle action shape |
| Vendor master | New T2 register (or OR contact abstraction if stable) | Per ADR-022 review |
| Payment matching | T2 `bookkeeping-bank-reconciliation` (`MatchingRule` + `ReconciliationMatch`) | Bank-rec emits candidate match; operator confirms; AP lifecycle transitions to `paid` |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions |
| Manifest navigation | T1 manifest pattern | 4 entries (Vendors, AP, AP Aging, Dunning) + their pages |

**Net new code in implementation cycle**: 3 schema declarations + 2 lifecycle
blocks + 3 aggregations + 4 manifest entry pairs. At most 1 single-method PHP
guard (`APGuard`) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| AP invoice lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| AP dunning workflow | Consumed from OR dunning-workflow if stable; else single-method `APGuard` per ADR-031 exception | Resolution in discovery; spec shape-neutral |
| Aged payables detail | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM + bucket calc |
| Aged payables summary | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM + bucket ordering |
| Aged payables timeline | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM + date ordering |
| Write-off compensating posting | Lifecycle action invoking T1's materialisation extension | No new service |
| Payment matching → `paid` transition | Lifecycle transition triggered by operator confirmation of bank-rec match | No new service |

No service class authored in this envelope (subject to ADR-031 exception: at
most one single-method `APGuard`).

## Seed Data

Mock AP data (3–5 realistic vendor invoices per administration) with:

- **Vendors** (3 examples): Utilities provider (elektriciteit), office supplies
  distributor, professional services firm.
- **Invoices** (5–8 examples): ranging from current (due within 7 days) to
  overdue (30+ days), with 1–3 line items per invoice, realistic Dutch VAT
  codes, and amounts €500–€5000.
- **Dunning notices** (optional seed): 2–3 examples showing dunning escalation
  (reminder → formal notice) for one overdue invoice.

Seed data SHALL be included in `lib/Settings/shillinq_register.json` under
`components.objects[]` with `@self` envelope per ADR-001 seed-data pattern.
Seed data is idempotent — re-importing skips objects matched by slug.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR dunning-workflow not yet stable | Spec shape-neutral; PHP guard fallback (`APGuard`, single-method, ~20 LOC) per ADR-031 exception; remove when OR extension lands |
| Payee master overlaps with OR's contact abstraction | Per ADR-022 review during implementing cycle |
| Write-off path creates audit-trail gap | Write-off transition requires audit-trailed reason; compensating posting captured by GL audit |
| Aged payables aggregation slow with many open invoices | Pre-aggregated cache via OR's aggregation extension if performance gates trip; per-spec optimisation in implementing cycle |
| Bucket thresholds need to be customized per customer requirement | Config-driven via `IAppConfig` (administration-level); not spec-breaking |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the three schemas
   (additive — no existing schema changes).
2. Seed data (5–8 mock invoices, 3 vendors) is inserted via `ConfigurationService::importFromApp()`
   repair step.
3. `src/manifest.json` is patched with 4 new menu entries + their pages (additive).
4. If OR's dunning-workflow extension is not yet stable, `lib/Lifecycle/APGuard.php`
   ships (single method, ~20 LOC, ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes the manifest
entries; AP invoices remain queryable but unreferenced.

## Open Questions

1. **OR dunning-workflow stability** — resolved in `opsx-ff` discovery; OR issue
   filed if needed.
2. **Payee master vs OR contact** — resolved per ADR-022 review.
3. **Default dunning cadence** — defaults to industry-standard (reminder 1 at +14,
   reminder 2 at +30, formal notice at +45, collection at +60); customisable per
   administration; resolved during implementing cycle's UX review.
4. **AP aging bucket thresholds** — current/30/60/90 as defaults; customisable
   per administration; resolved during implementing cycle's settings review.
5. **Batch payment matching** — can multiple AP invoices be matched to a single
   bank deposit? Or only 1:1 matching? Resolved in bank-reconciliation spec
   review.
