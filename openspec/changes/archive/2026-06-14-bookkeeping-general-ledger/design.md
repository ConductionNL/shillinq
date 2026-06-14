# Design — General Ledger

## Context

Shillinq's mission is a Conduction-native business administration suite
covering bookkeeping, invoicing, procurement, contracts, and downstream
reporting. The 5-tier rollout (see `proposal.md`) starts with Tier 1
**foundation**: a balanced double-entry general ledger built on top of
a hierarchical chart of accounts.

This change is the **core GL capability** of Tier 1 — the balanced GL itself,
responding to strong market demand for general ledger and financial
management (1616 demand score). It depends on `bookkeeping-chart-of-accounts`
(which declares the `Account` register that `GLLine.accountNumber` foreign-keys
into) and is consumed by downstream tiers for trial balance, financial reporting,
and multi-currency translation.

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
- Support asset repair ledger linking and subledger reconciliation
  to address market demand (features 2–7 in context-brief).
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

The flat `GeneralLedgerEntry` model (one entry = one debit OR one credit)
would force the balance check into application code at write-time and
prevent the constraint from being declarative. The header/line split is
necessary because the balance constraint operates over a *group* of lines.

**Alternative considered**: Single flat `GeneralLedgerEntry` model
with a `transactionId` clustering field, balance checked in a
post-write hook. Rejected — moves the invariant from "declared on
the schema" to "lives in the hook implementation", which is the
ADR-031 anti-pattern. Header/line is also the canonical shape in
RGS and every reference SMB accounting product (Exact, AFAS, Yuki,
Twinfield, Snelstart).

### D2 — Declarative balance precondition with ADR-031 exception path

The balance invariant is declared as an `x-openregister-lifecycle` precondition
on the `posted` state transition, per ADR-031:

```
GLTransaction.posted.requires:
  - condition: "SUM(GLLine.amount WHERE side='debit') = SUM(GLLine.amount WHERE side='credit')"
    error: "Transaction is unbalanced"
```

**If OpenRegister's engine cannot express cross-line aggregation**, the
implementation cycle will register a thin (~20 LOC) `BalanceGuard` service
implementing the check in PHP, annotated with the ADR-031 exception reason.
The guard is a single method named `isBalanced(string $transactionId): bool`
and lives in `src/Lifecycle/BalanceGuard.php`.

**Why not signed amounts?** Encoding sign in the `amount` field (e.g. `amount: -100`)
would require the balance check to aggregate both positive and negative values,
leading to edge cases with negative zero. Separating the polarity into the `side`
enum makes the aggregation clean: `SUM(amount WHERE side='debit') = SUM(amount WHERE side='credit')`.

### D3 — Period-stamped lines with Tier 3 migration path

Every `GLLine` carries a `periodId` field for period-aware trial balance
computation. In Tier 1 (before `FiscalPeriod` exists), `periodId` is a
plain string (e.g. `"2026-Q1"`).

**Tier 3 migration**: When the `FiscalPeriod` register is introduced,
`periodId` will become an `x-openregister-relations` FK, and the
auto-resolution precondition will resolve the posting date against the
active fiscal period. Until then, the system accepts any string match
between parent and child `periodId`.

### D4 — Sub-ledger references as optional FKs

`GLLine` carries optional `subLedgerType` (enum: `ap`, `ar`, `project`, `none`)
and `subLedgerRef` (string identifier) fields. This allows Tier 1 GL to
reference Tier 2 sub-ledgers (e.g. invoice line items in AP) without
importing the sub-ledger schemas.

On `posted` transition, the lifecycle engine may validate that the FK
target exists (per ADR-022). If not, draft lines may reference non-existent
sub-ledgers; the validation will be added when the sub-ledger specs land.

### D5 — Asset repair integration via sub-ledger FK

Asset repair linked GL transactions use the `subLedgerType: "ar"` and
`subLedgerRef: <asset-repair-id>` fields to record the repair document
association. The `postingDate` field allows the linked GL entry to
use a completion date different from the posting date, addressing
feature 2 in the context-brief.

Downstream specs for asset repair module will define the exact endpoint
shape and validation rules.

## Reuse Analysis

| Capability | Reused From | Rationale |
|---|---|---|
| Object CRUD, pagination, filtering | `ObjectService` (OpenRegister) | All GL transactions and lines use standard register semantics |
| Audit trail (before/after snapshots) | `AuditTrailService` (OpenRegister) | Every GL state change is immutably logged |
| RBAC (role-based read/write) | `AuthorizationService` (OpenRegister) | GL posting permissions controlled by role and object-level ACL |
| Lifecycle state machine | `x-openregister-lifecycle` (OpenRegister) | `draft → posted → reversed` transitions with preconditions |
| Schema-driven forms | `CnFormDialog` (@conduction/nextcloud-vue) | GL transaction and line create/edit forms auto-generated from schema |
| Schema-driven list pages | `CnIndexPage` (@conduction/nextcloud-vue) | GL transaction list with pagination, sorting, filtering |
| Schema-driven detail pages | `CnDetailPage` (@conduction/nextcloud-vue) | GL transaction detail with associated lines, audit trail, notes |
| Relations (FK navigation) | `x-openregister-relations` (OpenRegister) | `GLLine.accountNumber → Account.accountNumber` FK with bidirectional lookup |

**Custom code required for:**
- Balance precondition (if OpenRegister engine cannot express cross-line aggregation; see D2)
- Asset repair linking endpoint (downstream integration, not Tier 1)

## Seed Data

GL transactions and lines in the seed data represent realistic posting scenarios
for a Dutch SMB (consultancy, non-profit, small trading company). Each example
includes a balanced transaction with multiple lines in EUR.

### GLTransaction examples (3 objects)

1. **Quarterly revenue posting** — recognizes consulting services invoiced in Q1
2. **Payroll accrual** — monthly salary accrual for 5 employees
3. **Bank reconciliation posting** — closing bank statement variance

### GLLine examples (10 objects)

Each transaction has N ≥ 2 balanced lines with realistic account numbers
and amounts based on RGS (Referentie Grootboek Schema) standards:

- Revenue recognition (1000-4999 range: income/service accounts)
- Payroll liabilities (2000-2999 range: liability accounts)
- Bank cash (1100-1199 range: bank accounts)
- Accruals and reserves (2700-2799 range: accrual accounts)

All seed amounts are in EUR, realistic for SMB operations (1K–50K per posting).
