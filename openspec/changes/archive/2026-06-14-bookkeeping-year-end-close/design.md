# Design — Year-End Close

**Status:** pr-created

## Context

Year-end close is the transition from one fiscal year to the next,
locking the current period and seeding the next period with opening
balances. Per ADR-022, the closing workflow comes from OR's
closing-workflow extension, not from an app-local closing-service. Per
ADR-031, closing-entry generation is declarative, not a PHP service.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains *why*
the shape is what it is.

## Goals

- Express the entire year-end close surface as **declarative metadata**
  — schemas + lifecycle + aggregations + templates + manifest entries —
  per ADR-031.
- Consume OR's closing-workflow abstraction — per ADR-022. Zero parallel
  closing service.
- Make the spec a **competent-bookkeeper-readable contract** — Dutch SMB
  closing flow recognisable end-to-end (checklist, closing entries,
  balance lock, new-year seeding).
- Declaratively express closing-entry generation rules so operators can
  configure which accounts close and how, without code changes.
- Enforce immutability of closed periods to preserve audit integrity.

## Non-Goals

- No PHP closing service, no `ClosingEntryService.php`.
- No multi-currency revaluation — T5.
- No management close-variance reporting — T4-specialized.
- No sector-specific closing rules (IRS LIFO reserve, etc.) — T4-specialized.

## Decisions

### D1 — Year-end close is a fiscal-year lifecycle transition

`FiscalYear` (from T2) transitions through states: open → in-progress →
closed. The open → in-progress transition verifies the closing checklist.
The in-progress → closed transition materialises closing entries,
calculates retained earnings, locks the period, and seeds opening
balances for the next FY.

### D2 — Closing-entry generation is template-driven, not PHP-coded

Operators define which accounts close to retained earnings, which
reverse, which depreciate, via `ClosingEntryTemplate` records loaded on
install. No PHP `ClosingEntryGenerator`; the lifecycle action reads the
template and OR's `x-openregister-lifecycle` materialisation extension
emits the GL postings.

If OR's materialisation extension is not yet stable, ADR-031's exception
path applies: a single-method `OCA\Shillinq\Lifecycle\ClosingEntryGuard`
ships, cited in the spec.

### D3 — Closing is audited and immutable

Once a fiscal year is closed, the period becomes immutable (read-only)
via `x-openregister-lifecycle` immutable-period flag. All closing
actions (closing-entry generation, balance carryforward, new-period
seeding) are audit-trailed. If a correction is needed post-close, the
operator explicitly unclos es the period, posts the correction, and
re-closes.

### D4 — Closing checklist is declarative preconditions

The checklist (trial balance verified, accruals recorded, depreciation
posted, FX gains/losses declared, related-party transactions reviewed)
is expressed as `x-openregister-lifecycle.requires` predicates on the
in-progress → closed transition. Each checklist item is a named
aggregation that fails if conditions are not met.

### D5 — Retained earnings tracks income rollforward

`RetainedEarnings` is a sub-ledger register tracking opening balance,
net income for the period, distributions, and closing balance. The
closing lifecycle action materialises the retained-earnings entry (debit
net income from the closing account, credit retained earnings account).

### D6 — Opening balances are auto-seeded from closing balances

On successful close of FY N, the next FY N+1 is auto-seeded with opening
balances equal to FY N closing balances. Balance-carryforward validation
verifies this seeding is correct before the new FY is activated.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Year-end close lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `FiscalYear` (open → in-progress → closed) with declarative preconditions (checklist) |
| Closing-workflow orchestration | OR closing-workflow (if stable; else gap) | Consumed via lifecycle reference; PHP guard fallback per ADR-031 exception if needed |
| Closing checklist | OR `x-openregister-lifecycle.requires` | Declarative checklist items as aggregation predicates |
| Archive-period locking | OR immutable-period flag | `x-openregister-lifecycle` marks closed FY as immutable |
| Closing-entry generation | T1 `JournalEntry` materialisation pattern | Same lifecycle action shape; template-driven via `ClosingEntryTemplate` |
| Retained-earnings tracking | New T4 register (`RetainedEarnings`) | Sub-ledger tracking opening balance + net income + distributions + closing balance |
| Balance carryforward | OR `x-openregister-aggregations` | Aggregation validating next FY opening balances = prior FY closing balances |
| GL transactions on closing | T1 GL posting | Lifecycle action materialises GL entries (closing entries, retained-earnings entry, opening-balance seeding) |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions and closing-entry posting |
| Manifest navigation | T1 manifest pattern | 2 entries (Year-End Close Checklist, Closing Entries) + their pages |

**Net new code in implementation cycle**: 3 schema declarations + 1
lifecycle block + 3-4 aggregations + 2 manifest entry pairs. At most
1 single-method PHP guard (`ClosingEntryGuard`) gated by ADR-031
exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Fiscal-year close lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Closing checklist validation | Declarative (`x-openregister-lifecycle.requires` predicates) | Aggregation checks, no side effects |
| Closing-entry generation | Template-driven lifecycle action invoking OR closing-workflow if stable; else single-method `ClosingEntryGuard` per ADR-031 exception | Rule expressiveness via templates; no PHP service |
| Archive-period locking | Declarative — `x-openregister-lifecycle` immutable flag | Enforced at OR layer |
| Retained-earnings materialisation | Lifecycle action materialising GL entry | No new service |
| Balance carryforward validation | Aggregation-driven precondition on next FY activation | No service |
| Opening-balance seeding | Lifecycle action on successful close | No service |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `ClosingEntryGuard`).

## Seed Data

### ClosingEntryTemplate seeds

Three default templates (customisable, shipped enabled):
- **Revenue closing template**: All revenue accounts (4000–4999) close
  to income-summary account (9900).
- **Expense closing template**: All expense accounts (5000–6999) close
  to income-summary account (9900).
- **Accrual reversal template**: Prior-year accrual accounts (e.g.,
  9700–9799) reverse to current-year expense (5900 – audit/adjustments).

All templates are marked `active: true` on install, customisable via
manifest entries.

### Administration-level defaults

- **Closing account**: Each administration selects its single closing
  account (typically 9900 – income summary) via `ClosingAccount` record.
  Seed: initialize with account 9900 if it exists; else first "other"
  account.
- **Closing policy**: Multi-period-close allowed? Unclose allowed?
  Defaults: close only once per FY; unclose requires CFO role.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR closing-workflow not yet stable | Spec shape-neutral; PHP guard fallback (`ClosingEntryGuard`, single-method, ~30 LOC) per ADR-031 exception; remove when OR extension lands |
| Closing-entry template scope creep | Seed three default templates; custom domain-specific rules deferred to T4-specialized or operator-side rule authoring via API |
| Archive-period immutability blocks late corrections | Unclose capability available for corrections; all unclose/re-close actions audit-trailed |
| Balance carryforward validation complexity | Aggregation-driven; OR handles the SUM logic |
| Multi-currency FX revaluation absent in T4 | T5 attaches. T4 closes each currency independently; T5 consolidates and revalues |
| Closing checklist too strict / too lenient | Defaults are preconditions; operators can override via manifest entries (selectively disable checklist items for emergency close) |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the three
   schemas (additive — no existing schema changes).
2. `ClosingEntryTemplate` seed records are loaded (revenue, expense,
   accrual-reversal templates, marked active).
3. `src/manifest.json` is patched with 2 new menu entries +  their pages.
4. If OR's closing-workflow extension is not yet stable,
   `lib/Lifecycle/ClosingEntryGuard.php` ships (single method, ~30 LOC,
   ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes the
manifest entries; closed fiscal years remain queryable but unreferenced.

## Open Questions

1. **OR closing-workflow stability** — resolved in `opsx-ff` discovery;
   OR issue filed if needed.
2. **Closing-account selection UI** — dropdown from chart of accounts,
   or auto-selected? Resolved in implementing cycle UX review.
3. **Unclose capability and access control** — who can unclose a period
   and under what conditions? (CFO-only? Requires memo? Disallowed?)
   Settled in governance review.
4. **Closing checklist strictness** — are checklist failures blocking
   (hard gate) or warnings (soft gate)? Defaults to hard; operators can
   override. Settled in implementing cycle.
5. **Multi-period closing** — can operator close multiple periods in one
   cycle (e.g., monthly closes from Jan–Nov in one job)? Or one at a
   time? Defaults to one at a time; multi-period is T4-specialized.
   Settled in implementing cycle.
