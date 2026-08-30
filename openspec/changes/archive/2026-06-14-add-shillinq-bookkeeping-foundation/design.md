# Design — Bookkeeping Foundation (T1)

## Context

Shillinq's mission is a Conduction-native business administration suite
covering bookkeeping, invoicing, procurement, contracts, and downstream
reporting. The 5-tier rollout (see `proposal.md`) starts with **T1 —
foundation**: a balanced double-entry general ledger with hierarchical
accounts and human-authored journals. Every later tier (sub-ledgers,
period close, financial reporting, multi-currency / tax) consumes T1.

Currently `lib/Settings/shillinq_register.json` ships only a placeholder
`example` schema; `openspec/architecture/adr-000-data-model.md`
enumerates 225 financial entities but none have landed as registers
yet. T1 lays the smallest set that lets the rest of the model attach
incrementally without later destructive migrations.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire T1 surface as **declarative metadata** —
  schemas + `x-openregister-lifecycle` rules + manifest entries —
  per ADR-031. No new PHP service classes.
- Consume every OpenRegister abstraction that already exists for
  audit trail, RBAC, approval workflow, attachments — per ADR-022.
  No reimplementation in shillinq.
- Make the spec a **competent-bookkeeper readable contract** — a
  Dutch SMB accountant should recognise the model as a faithful
  double-entry chart-of-accounts + ledger + journal flow,
  RGS-conformant, with no surprises.
- Keep T1 narrow enough that T2/T3/T4/T5 specs can each add their
  surface without reshaping T1's schemas.

## Non-Goals

- No invoicing, no AP/AR sub-ledgers, no bank statement matching —
  T2's job.
- No period close, no trial balance generation — T3's job. (T1
  defines `period_id` on every posting so T3 can compute trial
  balance with one aggregation query.)
- No financial-statement rendering — T4's job.
- No multi-currency translation, no VAT/BTW posting automation —
  T5's job. (T1 carries `currency` on `Account` so T5 doesn't
  need a destructive migration.)
- No frontend Vue components beyond the generic
  `CnIndexPage`/`CnDetailPage` from `@conduction/nextcloud-vue`
  bound through `src/manifest.json`. Bespoke views are deferred
  until a real need appears.
- No PHP code authored in this change (the spec-only intent is
  explicit; tasks reference where guards land *if* needed).

## Decisions

### D1 — Declarative-first, per ADR-031

Every T1 behaviour expressible as schema metadata MUST be declared
in `lib/Settings/shillinq_register.json`, not authored as a PHP
service. Concretely:

| Behaviour | Declarative form |
|---|---|
| Account active/blocked/archived state machine | `x-openregister-lifecycle` on `Account` |
| GL transaction draft/posted/reversed state machine | `x-openregister-lifecycle` on `GLTransaction` |
| Balance constraint (sum debits = sum credits) | `x-openregister-lifecycle.requires` on `GLTransaction.post` transition (precondition) |
| Journal entry pending/approved/posted/voided | `x-openregister-lifecycle` on `JournalEntry` |
| Approval routing on journal post | `x-openregister-lifecycle` consuming OR's approval-workflow extension |
| Account hierarchy navigation | `x-openregister-relations` (self-relation `parentAccountNumber → Account.accountNumber`) |
| Audit trail of every state change | OR's built-in audit-trail-immutable (no app config required) |

**Alternative considered**: Author a PHP `BookkeepingService` mirroring
Exact / Twinfield style. Rejected per ADR-031 — that is exactly the
anti-pattern decidesk's MotionService / VotingService / QuorumService
are now mid-migration away from. T1 starts clean.

### D2 — Header/line split for GL transactions

T1 splits GL postings across two schemas:

- `GLTransaction` — the header. Carries period, posting date,
  description, source reference, balanced-state, posting-state. Owns
  the lifecycle.
- `GLLine` — the debit-or-credit line. Carries account FK, amount,
  side (`debit`|`credit`), optional sub-ledger FK, optional cost
  center.

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

### D3 — `JournalEntry` is the human surface; GL transactions are the materialised result

Bookkeepers think in journal entries (memoriaalboekingen, recurring
templates, reversing accruals). The GL transaction is the
machine-readable balanced posting that gets created when a journal
is posted. T1 keeps the two layers explicit:

- A `JournalEntry` can be a `manual`, `recurring`, or `reversing`
  sub-type.
- Posting a `JournalEntry` materialises exactly one `GLTransaction`
  (with N `GLLine` rows). The materialisation is declarative — a
  lifecycle `post` action emits a CloudEvent that the OR engine
  consumes and creates the GL header + lines. No PHP orchestration.
- `JournalEntry.glTransactionId` is the back-reference once posted.
- Reversing journals declare a `reversesOn: <period_id>` field; the
  OR scheduled-workflow primitive (per ADR-031 background-job
  guidance) creates the inverse posting at period boundary. The
  spec does not author the schedule; it requires it.

**Alternative considered**: Collapse `JournalEntry` and
`GLTransaction` into one schema. Rejected — recurring and reversing
templates need a header that is *not* itself a posting (they are
templates that *produce* postings on a cadence). Two layers also
match every reference product.

### D4 — RGS templates as seed data, not hard-coded enums

The chart-of-accounts shape is fixed by RGS conformance, but the
exact account numbers and names vary by administration type. T1
ships three RGS 3.5 templates:

- `rgs-3.5-mkb.json` — the standard SMB chart.
- `rgs-3.5-zzp.json` — the simplified ZZP/freelancer chart.
- `rgs-bbv.json` — the BBV (Besluit Begroting en Verantwoording)
  chart for Dutch government / municipal bookkeeping.

Templates are JSON arrays of `Account` records. The repair step
seeds whichever template the administration selects on first run
(or none — operators may build their own). Per-administration
override is allowed: any seeded account can be edited, archived,
or augmented with sub-accounts.

**Alternative considered**: Bake one template into the schema as
enum constraints. Rejected — RGS evolves (4.x ships before T1's
implementation cycle finishes is plausible), accounts vary per
administration, and government / SMB / ZZP cannot share enums.
Seed files keep schema stable and templates evolveable.

### D5 — Period stamping via foreign-key, not via mid-line date arithmetic

Every `GLLine` carries `period_id` pointing at a `FiscalPeriod`
record (declared by T3 — referenced by FK here, with a stub `period`
field of type `string` for T1 acceptance). Once T3 lands, the FK
points at the real schema; until then, callers post the period
identifier as a string. Two reasons for the FK shape:

1. **Trial balance becomes a pure aggregation.** T3's trial-balance
   capability is `x-openregister-aggregations` grouped by
   `(period_id, account_number)` summing `debit - credit`. No date
   parsing, no period-boundary edge cases.
2. **Period close becomes a pure lifecycle transition** on the
   period record (T3) — no per-line modification needed.

**Alternative considered**: Carry only `entryDate` and compute the
period on read. Rejected — period boundaries differ per
administration (calendar year vs broken fiscal year vs 13-period
retail), and recomputing on every read is wasteful + brittle.

### D6 — Source documents reference docudesk by FK, not by embedded blob

A journal entry's source document (PDF invoice, scan, bank
statement) is referenced by docudesk attachment URI. The
`JournalEntry.sourceDocumentUri` field stores a stable URI; the
content lives in docudesk's storage. No file blob in the register.

This is the ADR-022 canonical pattern: when OR or a sibling app
provides an abstraction, consume it by reference, not by copy.

### D7 — Approval gate via OR's approval-workflow extension

Posting a `JournalEntry` may require approval depending on
administration policy (e.g. journals over €5000 require approval;
all government journals require dual control). The approval
workflow is OR's, not shillinq's:

```jsonc
"x-openregister-lifecycle": {
  "transitions": {
    "post": {
      "from": "pending",
      "to": "posted",
      "requires": { "approval-workflow": { "policy": "@self.amountPolicy" } }
    }
  }
}
```

shillinq declares the trigger; OR's engine runs the approval
routing, recipient resolution, and approval-state machine. Per
ADR-022, no app-local approval table.

## Reuse Analysis

| Capability needed | What already exists | T1 reuse strategy |
|---|---|---|
| Account hierarchy | `adr-000-data-model.md` `Account` entry, `GeneralLedgerAccount` entry | T1's `Account` formalises the existing entry. The two ADR-000 entries are reconciled in the implementation cycle (the GL-prefixed entry becomes the canonical one; `Account` keeps its "business workspace" sense reserved for T2 multi-tenancy). |
| GL line entry shape | `adr-000-data-model.md` `GeneralLedgerEntry` | `GLLine` is the renamed/structured replacement. ADR-000 gets an annotation noting the supersession. |
| Header for grouped postings | None in ADR-000 | T1 adds `GLTransaction` as a new entity in ADR-000 (update lands with the implementation cycle, not this spec). |
| Journal entry shape | `adr-000-data-model.md` `JournalEntry` | T1's spec aligns with the existing entry; renames `isBalanced` → derived (declarative calculation per ADR-031 instead of stored field), keeps `entryDate`, `entryNumber`, `description`, `accountCode`, `journalCode`, `reference`, `vatAmount` (deferred — T5 owns VAT), `departmentCode`, `memo`. |
| Audit trail | OR audit-trail-immutable | Consumed automatically (no schema config). Every state transition writes an audit event with actor, before/after, timestamp, hash chain. |
| RBAC | OR authorization | Per-schema role definitions in the register file. T1 grants `bookkeeper` create/read on `JournalEntry` + `GLTransaction`, `approver` the `post` transition, `auditor` read-only on everything. |
| Approval workflow | OR approval-workflow (per ADR-022) | Consumed via `x-openregister-lifecycle.requires`. No app-local approval logic. |
| Attachments | docudesk | Referenced by URI from `JournalEntry.sourceDocumentUri`. No file storage in shillinq. |
| Lifecycle engine | `x-openregister-lifecycle` (per ADR-031) | All three schemas declare lifecycles. |
| Seed data import | `ConfigurationService::importFromApp()` (per shillinq config.yaml `design` rule 3) | Repair-step pattern already in use for the placeholder schema; T1 extends it to load the chosen RGS template into the `Account` register. |
| Cross-schema relations | `x-openregister-relations` | Self-relation on `Account.parentAccountNumber`; FKs from `GLLine` → `Account`, `GLLine` → `GLTransaction`, `JournalEntry` → `GLTransaction`. |
| Recurring schedule | OR `ScheduledWorkflow` + n8n adapter (per ADR-031 background-job guidance) | A `JournalEntry` of sub-type `recurring` declares a cadence; the OR scheduled-workflow primitive materialises the next posting. No app-local cron job. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted on `feature/adopt-app-manifest`) | T1 adds 3 menu entries + 3 index pages + 3 detail pages, all consuming `type: index` / `type: detail` library renderers. |

**Net new code in T1 implementation**: 4 schema declarations + 3
manifest pages + 3 seed JSON files. Possibly 1 short PHP lifecycle
guard (~20 LOC, single method) if Risk 1 in `proposal.md` confirms
the cross-line balance precondition cannot run inside the
declarative engine.

## Declarative-vs-imperative decision (per ADR-031)

Per ADR-031 enforcement, each behaviour was classified before this
spec was finalised:

| Behaviour | Decision | Why |
|---|---|---|
| Account state machine | Declarative (`x-openregister-lifecycle`) | Pure state machine, fits the extension |
| GL transaction state machine | Declarative | Same |
| Balance precondition | Declarative if the engine supports cross-line aggregations in `requires`; otherwise a single-method PHP guard called *by* the lifecycle engine (ADR-031 §"PHP guards remain a legitimate seam") | Resolution lives in `opsx-ff` discovery; spec is shape-neutral |
| Hierarchical account navigation | Declarative (`x-openregister-relations` self-relation) | Standard relation shape |
| Trial balance prep (period-stamped lines) | Declarative — T3's aggregation will read these fields | No T1 service |
| Recurring journal materialisation | Declarative — OR's `ScheduledWorkflow` + n8n adapter | ADR-031 §"Background jobs that walk an object queue" path 2 |
| Reversing journal materialisation | Declarative — lifecycle action on period close (T3) emits the inverse | T3 owns the trigger; T1 declares the shape |
| Approval routing | Consumed from OR's approval-workflow abstraction | ADR-022 |
| Audit trail | Consumed from OR's audit-trail-immutable abstraction | ADR-022 |

No service class authored in this T1 envelope. If the balance
precondition needs a PHP guard, it is `lib/Lifecycle/BalanceGuard.php`,
single method, ~20 LOC — and explicitly cited as an ADR-031 exception
in the implementing cycle's design doc.

## Seed Data

T1 ships three RGS template seeds, all under `lib/Settings/seeds/`:

| File | Purpose | Approximate row count |
|---|---|---|
| `rgs-3.5-mkb.json` | Standard SMB chart of accounts. Five top-level cluster headers (assets/liabilities/equity/revenue/expenses) and the RGS 3.5 canonical SMB account tree. | ~150 |
| `rgs-3.5-zzp.json` | Simplified ZZP/freelancer subset of RGS 3.5. | ~40 |
| `rgs-bbv.json` | BBV chart for Dutch municipal / government bookkeeping. | ~120 |

Format: a JSON array of `Account` records matching the schema declared
in `bookkeeping-chart-of-accounts/spec.md`. Loaded via
`ConfigurationService::importFromApp()` in the repair step. The
administration's first-run flow selects which template to seed (or
none — operators may build their own). After seeding, accounts are
fully editable through normal OR object operations; per-administration
override is the default behaviour.

Each seed file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "RGS 3.5", "variant":
  "mkb", "imported": "<iso-timestamp>" } }`) so a future migration to
  RGS 4.x can identify which records were template-sourced versus
  operator-authored.

**Example `Account` seed records (Dutch SMB — illustrative, not exhaustive):**

```json
[
  {
    "_meta": { "source": "RGS 3.5", "variant": "mkb", "imported": "2026-05-20T00:00:00Z" },
    "accountNumber": "1000",
    "name": "Kas",
    "accountType": "assets",
    "currency": "EUR",
    "administrationId": "adm-1",
    "lifecycleState": "active"
  },
  {
    "accountNumber": "1100",
    "name": "Debiteuren",
    "accountType": "assets",
    "currency": "EUR",
    "administrationId": "adm-1",
    "lifecycleState": "active"
  },
  {
    "accountNumber": "4000",
    "name": "Omzet",
    "accountType": "revenue",
    "currency": "EUR",
    "administrationId": "adm-1",
    "lifecycleState": "active"
  },
  {
    "accountNumber": "7000",
    "name": "Inkoopkosten",
    "accountType": "expenses",
    "currency": "EUR",
    "administrationId": "adm-1",
    "lifecycleState": "active"
  },
  {
    "accountNumber": "8990",
    "name": "Resultaat lopend boekjaar",
    "accountType": "equity",
    "currency": "EUR",
    "administrationId": "adm-1",
    "lifecycleState": "active",
    "isClosingAccount": true
  }
]
```

No seed data for `GLTransaction`, `GLLine`, or `JournalEntry` — those
are accumulated through operation.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR's lifecycle engine can't express cross-line balance constraint inside `requires` | Document the gap as an OR issue; use a single-method PHP guard called *by* the lifecycle engine per ADR-031 §"PHP guards remain a legitimate seam". Spec is shape-neutral (REQ-GL-005 mandates the invariant without prescribing implementation). |
| Account hierarchy depth unbounded | Schema permits arbitrary depth; UI (T4+ reporting) renders the first 4 levels by default with collapse/expand. No T1 enforcement of max depth. |
| Reversing journal collides with manual journal at period boundary | The reversing posting is timestamped to the period's first day; manual journals on that day post separately. Same audit trail surfaces both. |
| RGS 4.x ships during T1 implementation | Seed files versioned in filename (`rgs-3.5-*`); coexistence is trivial; a `rgs-4.0-*` file can be added without touching the schema. |
| ADR-000 data-model entries `GeneralLedgerAccount` and `GeneralLedgerEntry` overlap with T1's `Account` and `GLLine` | Reconciliation is a one-paragraph annotation in ADR-000 added during the implementation cycle, noting `GeneralLedgerEntry` is superseded by `GLLine` and `GeneralLedgerAccount`'s fields fold into `Account` under the bookkeeping role. Not in this spec. |
| Future tier needs a header field T1 didn't anticipate | Adding fields to a register schema is additive (per OR's schema versioning); breaking changes are vanishingly rare. Risk accepted. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the three new
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with three new menu entries + three
   new index/detail page pairs (additive).
3. A new repair step (or extension of the existing one) imports the
   selected RGS template into the `Account` register on first install.
4. ADR-000 gains a one-paragraph annotation reconciling
   `GeneralLedgerAccount` / `GeneralLedgerEntry` with `Account` /
   `GLLine` / `GLTransaction`.

Down-direction: registers are non-destructive — disabling the seed
import + reverting the manifest leaves stranded but queryable
records. No destructive rollback needed at the spec-acceptance gate;
real rollback happens at the implementing PR's revert.

## Open Questions

1. **Does `x-openregister-lifecycle.requires` support cross-line
   sum constraints?** Resolved in `opsx-ff` discovery before the
   implementing cycle starts. If no: thin PHP guard, documented.
2. **RGS template variant for housing corp / healthcare / education
   sectors** — out of scope for T1; placed on the rollout roadmap.
3. **Closing-account cardinality** — `REQ-CoA-009` proposes
   exactly one closing account per administration. Confirm with
   the bookkeeper persona during spec review.
