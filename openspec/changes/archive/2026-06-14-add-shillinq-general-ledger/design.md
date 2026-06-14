# Design — General Ledger

## Context

Shillinq's mission is a Conduction-native business administration suite
covering bookkeeping, invoicing, procurement, contracts, and downstream
reporting. The 5-tier rollout (see `proposal.md`) starts with Tier 1
**foundation**: a balanced double-entry general ledger built on top of
a hierarchical chart of accounts.

This change is the **second slice** of Tier 1 — the balanced GL itself.
It depends on `add-shillinq-chart-of-accounts` (which declares the
`Account` register that `GLLine.accountNumber` foreign-keys into) and
is consumed by `add-shillinq-journal-entries` (which materialises a
`GLTransaction` on post).

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire GL surface as **declarative metadata** —
  schemas + `x-openregister-lifecycle` rules + manifest entries —
  per ADR-031. No new PHP service classes (with the documented
  ADR-031 exception path for the balance precondition if the engine
  cannot express it declaratively).
- Consume every OpenRegister abstraction that already exists for
  audit trail, RBAC — per ADR-022. No reimplementation in shillinq.
- Make the spec a **competent-bookkeeper readable contract** — a
  Dutch SMB accountant should recognise the model as a faithful
  double-entry general ledger, RGS-conformant, with no surprises.
- Keep Tier 1 narrow enough that Tier 2/3/4/5 specs can each add
  their surface without reshaping the GL's schemas.

## Non-Goals

- No chart of accounts (sibling change owns the `Account` schema).
- No journal entries (sibling change owns the `JournalEntry` schema).
- No invoicing, no AP/AR sub-ledgers, no bank statement matching —
  Tier 2's job.
- No period close, no trial balance generation — Tier 3's job. (Tier
  1 defines `periodId` on every posting so Tier 3 can compute trial
  balance with one aggregation query.)
- No financial-statement rendering — Tier 4's job.
- No multi-currency translation, no VAT/BTW posting automation —
  Tier 5's job.
- No frontend Vue components beyond the generic
  `CnIndexPage`/`CnDetailPage` from `@conduction/nextcloud-vue`
  bound through `src/manifest.json`.

## Decisions

### D1 — Header/line split for GL transactions

Tier 1 splits GL postings across two schemas:

- `GLTransaction` — the header. Carries period, posting date,
  description, source reference, balanced-state, posting-state. Owns
  the lifecycle.
- `GLLine` — the debit-or-credit line. Carries account FK, amount,
  side (`debit`|`credit`), optional sub-ledger FK, optional cost
  centre.

`adr-000-data-model.md`'s existing `GeneralLedgerEntry` entry is
line-level (one entry = one debit OR one credit). The header/line
split is necessary because the balance constraint operates over a
*group* of lines. A flat `GeneralLedgerEntry` model would force the
balance check into application code at write-time and prevent the
constraint from being declarative.

**Alternative considered**: Single flat `GeneralLedgerEntry` model
with a `transactionId` clustering field, balance checked in a
post-write hook. Rejected — moves the invariant from "declared on
the schema" to "lives in the hook implementation", which is the
ADR-031 anti-pattern. Header/line is also the canonical shape in
RGS and every reference SMB accounting product (Exact, AFAS, Yuki,
Twinfield, Snelstart).

The downstream consequence: `GeneralLedgerEntry` in
`adr-000-data-model.md` is **superseded by** `GLLine`. The
ADR-000 update is a one-line note added in this change's
implementation cycle (not in this proposal). The transactional
header `GLTransaction` is **new** in the data model.

### D2 — Balance precondition declared as `x-openregister-lifecycle.requires`, with ADR-031 exception path

The double-entry invariant — sum of `GLLine.amount WHERE side='debit'`
equals sum of `GLLine.amount WHERE side='credit'` for a given
`GLTransaction` — is the most consequential constraint in the spec.
The decision is:

| Path | When | Form |
|---|---|---|
| Declarative | OR's lifecycle engine supports cross-schema sum constraints in `requires` | `x-openregister-lifecycle.transitions.post.requires.balance: { ... }` |
| Single-method PHP guard (ADR-031 exception) | Engine cannot express the constraint declaratively | `lib/Lifecycle/BalanceGuard.php` — exactly one method `isBalanced(string $transactionId): bool`, referenced from `requires` |

The discovery step (`opsx-ff`) resolves which path applies before the
implementing cycle starts. The spec itself is shape-neutral:
`REQ-GL-005` mandates the invariant without prescribing the
implementation.

**Alternative considered**: Author a `PostingService` that checks the
balance after every write. Rejected per ADR-031 — moves the invariant
out of the declared metadata and into a service that downstream code
can bypass. The exception path is single-method, stateless, and
explicitly annotated as an ADR-031 exception.

### D3 — Period stamping via foreign-key, not via mid-line date arithmetic

Every `GLLine` carries `periodId` pointing at a `FiscalPeriod`
record (declared by Tier 3 — referenced by FK here, with a stub
`periodId` field of type `string` for Tier 1 acceptance). Once Tier
3 lands, the FK points at the real schema; until then, callers post
the period identifier as a string. Two reasons for the FK shape:

1. **Trial balance becomes a pure aggregation.** Tier 3's
   trial-balance capability is `x-openregister-aggregations` grouped
   by `(periodId, accountNumber)` summing `debit - credit`. No date
   parsing, no period-boundary edge cases.
2. **Period close becomes a pure lifecycle transition** on the
   period record (Tier 3) — no per-line modification needed.

**Alternative considered**: Carry only `entryDate` and compute the
period on read. Rejected — period boundaries differ per
administration (calendar year vs broken fiscal year vs 13-period
retail), and recomputing on every read is wasteful + brittle.

### D4 — Reversal lifecycle declared, not synthesised

`GLTransaction` declares a `posted → reversed` transition. Reversing
a posted transaction does NOT mutate the original; it emits an
inverse audit event and marks the header `reversed`. The original
lines remain queryable for trial-balance reconstruction at any prior
point in time. This is the canonical immutable-ledger shape.

**Alternative considered**: Hard-delete reversed transactions.
Rejected — destroys audit history, fails statutory retention, and
breaks period-close re-runnability.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Account FK | `add-shillinq-chart-of-accounts` `Account` schema | `GLLine.accountNumber` foreign-keys into `Account.accountNumber` via `x-openregister-relations`. |
| GL line entry shape | `adr-000-data-model.md` `GeneralLedgerEntry` | `GLLine` is the renamed/structured replacement. ADR-000 gets an annotation noting the supersession. |
| Header for grouped postings | None in ADR-000 | This change adds `GLTransaction` as a new entity in ADR-000 (update lands with the implementation cycle, not this spec). |
| Audit trail | OR audit-trail-immutable | Consumed automatically (no schema config). Every state transition writes an audit event with actor, before/after, timestamp, hash chain. |
| RBAC | OR authorization | Per-schema role definitions in the register file. Grants `bookkeeper` create/read, `approver` the `post` transition, `auditor` read-only on everything. |
| Lifecycle engine | `x-openregister-lifecycle` (per ADR-031) | `GLTransaction` declares `draft → posted → reversed`. |
| Balance precondition | `x-openregister-lifecycle.requires` (or single-method PHP guard per ADR-031 exception path) | See D2. |
| Cross-schema relations | `x-openregister-relations` | FKs from `GLLine` → `Account`, `GLLine` → `GLTransaction`. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted on `feature/adopt-app-manifest`) | Adds 1 menu entry + 1 index page + 1 detail page (the detail page shows GL header + lines together). |

**Net new code in implementation cycle**: 2 schema declarations + 1
manifest entry pair. Possibly 1 short PHP lifecycle guard (~20 LOC,
single method) if Risk 1 in `proposal.md` confirms the cross-line
balance precondition cannot run inside the declarative engine.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| GL transaction state machine | Declarative (`x-openregister-lifecycle`) | Pure state machine, fits the extension |
| Balance precondition | Declarative if the engine supports cross-line aggregations in `requires`; otherwise a single-method PHP guard called *by* the lifecycle engine (ADR-031 §"PHP guards remain a legitimate seam") | Resolution lives in `opsx-ff` discovery; spec is shape-neutral |
| Reversal posting | Declarative — lifecycle action on `posted → reversed` emits the inverse audit event | No service code |
| Trial balance prep (period-stamped lines) | Declarative — Tier 3's aggregation will read these fields | No Tier 1 service |
| Audit trail | Consumed from OR's audit-trail-immutable abstraction | ADR-022 |

If the balance precondition needs a PHP guard, it is
`lib/Lifecycle/BalanceGuard.php`, single method, ~20 LOC — and
explicitly cited as an ADR-031 exception in the implementing
cycle's design doc.

## Seed Data

None for the GL surface — `GLTransaction` and `GLLine` are
accumulated through operation. (RGS account templates live in the
sibling `add-shillinq-chart-of-accounts` change.)

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR's lifecycle engine can't express cross-line balance constraint inside `requires` | Document the gap as an OR issue; use a single-method PHP guard called *by* the lifecycle engine per ADR-031 §"PHP guards remain a legitimate seam". Spec is shape-neutral (REQ-GL-005 mandates the invariant without prescribing implementation). |
| Future tier needs a header field Tier 1 didn't anticipate | Adding fields to a register schema is additive (per OR's schema versioning); breaking changes are vanishingly rare. Risk accepted. |
| ADR-000 data-model entry `GeneralLedgerEntry` overlaps with `GLLine` | Reconciliation is a one-paragraph annotation in ADR-000 added during the implementation cycle, noting `GeneralLedgerEntry` is superseded by `GLLine` and a new `GLTransaction` header is added. Not in this spec. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the two new
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with one new menu entry + one new
   index/detail page pair (additive).
3. ADR-000 gains a one-paragraph annotation reconciling
   `GeneralLedgerEntry` with `GLLine` and introducing `GLTransaction`.

Down-direction: registers are non-destructive — disabling the
manifest leaves stranded but queryable records. No destructive
rollback needed at the spec-acceptance gate.

## Open Questions

1. **Does `x-openregister-lifecycle.requires` support cross-line
   sum constraints?** Resolved in `opsx-ff` discovery before the
   implementing cycle starts. If no: thin PHP guard, documented.
2. **Period-id type** — Tier 1 treats `periodId` as a plain string
   identifier; Tier 3 introduces `FiscalPeriod` as a real schema and
   converts the field to an FK. The conversion is additive (existing
   strings remain valid as period identifiers).
