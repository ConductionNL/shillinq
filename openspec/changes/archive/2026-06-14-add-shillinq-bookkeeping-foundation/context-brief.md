# Proposal: add-shillinq-bookkeeping-foundation

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + RGS seed data. No PHP service
classes are authored.

## Summary

Introduce the foundational double-entry bookkeeping engine for Shillinq as
**Tier 1 of a 5-tier bookkeeping rollout**. This change adds three new
declarative capabilities — chart of accounts, general ledger, and journal
entries — declared as OpenRegister registers + schemas with
`x-openregister-lifecycle` rules (per ADR-031), wired into
`src/manifest.json` (per ADR-024), and consuming OpenRegister's audit,
RBAC, and approval-workflow abstractions instead of reimplementing them
(per ADR-022). No PHP service classes, no custom database tables, no
Vue components — the entire bookkeeping foundation lands as register
metadata + manifest entries.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

## Motivation

Shillinq's `adr-000-data-model.md` already enumerates 225 financial
entities including `GeneralLedgerAccount`, `GeneralLedgerEntry`,
`JournalEntry`, and `FiscalYear` (all marked
**Primary spec: financial-reporting-accountability**), but none of
those entities are yet declared in `lib/Settings/shillinq_register.json`
— the register file ships only a placeholder `example` schema. Every
downstream Shillinq capability (invoicing, accounts payable/receivable,
trial balance, period close, multi-currency, tax reporting, financial
reporting, treasury, procurement spend) depends on a balanced
double-entry ledger being present and trustworthy. Until the
foundation is laid, the rest of the rollout cannot start.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown. This change delivers **Tier 1**:
`add-shillinq-bookkeeping-foundation`.

T1 is intentionally narrow: a balanced general ledger with hierarchical
accounts and manual + recurring + reversing journals. Every downstream
tier (T2, T3, T4-base, T4-specialized — all in this PR) consumes this
surface.

## Affected Projects

- [x] Project: shillinq — adds 3 new registers/schemas to
  `lib/Settings/shillinq_register.json`, adds 2 manifest navigation
  entries in `src/manifest.json`, ships RGS seed templates in
  `lib/Settings/seeds/`
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (audit, RBAC, `x-openregister-lifecycle`,
  approval workflow, attachments). If a needed extension is missing,
  it is filed as an OR issue and the gap recorded in `design.md`.
- [ ] Project: docudesk — no source changes; journal entries
  reference docudesk attachments by foreign-key URI per ADR-022.

## Scope

### In Scope

- Three new capability specs (`bookkeeping-chart-of-accounts`,
  `bookkeeping-general-ledger`, `bookkeeping-journal-entries`) — see
  the `specs/` folder.
- Hierarchical chart of accounts (assets / liabilities / equity /
  revenue / expenses, sub-accounts, account-level
  active/blocked/archived lifecycle, closing-account designation).
- RGS (Referentie Grootboek Schema) seed templates for three variants:
  SMB (`rgs-3.5-mkb.json`), ZZP (`rgs-3.5-zzp.json`), and
  government/BBV (`rgs-bbv.json`). Per-administration override allowed.
- Double-entry posting engine — every general-ledger posting is a
  balanced set of journal lines (sum of debits = sum of credits in
  the administration's base currency), enforced by an
  `x-openregister-lifecycle` rule on the GL transaction schema.
- Sub-ledger references (AP / AR / project) by FK only — T2 owns the
  sub-ledgers themselves.
- Opening-balances import as a journal-entry type with
  source-document FK to a docudesk attachment.
- Period-stamped postings — every GL line carries a `period_id`
  resolved at post-time against the active fiscal-period record.
- Manual journal entries (memoriaalboekingen) with multi-line balanced
  entries, recurring journals (depreciation / subscriptions /
  accruals), and reversing journals (auto-reversing on next period
  boundary).
- Approval-gate workflow consumed from OpenRegister's approval-workflow
  abstraction per ADR-022 — DO NOT reimplement.
- Manifest navigation entries (Bookkeeping > Chart of Accounts,
  Bookkeeping > General Ledger, Bookkeeping > Journals) using
  `type: index`/`type: detail` page renderers from
  `@conduction/nextcloud-vue` (Tier-4 manifest renderer adopted on
  `feature/adopt-app-manifest`).
- Audit trail consumed from OpenRegister's
  audit-trail-immutable abstraction per ADR-022 — DO NOT reimplement.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle on the spec.
- **Subsequent tiers (T2, T3, T4-base, T4-specialized)** ship in this
  same PR as separate changes; see
  [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
  for the breakdown:
  - T2 (`add-shillinq-bookkeeping-compliance`) — sub-ledgers + period
    machinery (AP/AR, trial balance, period close, financial
    statements, bank reconciliation, audit-trail + document-attachment
    integrations).
  - T3 (`add-shillinq-bookkeeping-operations`) — operations + NL
    regulatory core. **VAT/BTW filing ships here** as
    `bookkeeping-vat-btw-filing`; it is not part of T1's surface.
  - T4-base (`add-shillinq-bookkeeping-advanced`) — advanced engine
    features (SBR/XBRL, fixed assets, multi-currency translation,
    cost centers + dimensions, year-end close, bank connectors,
    reconciliation reports). The schemas in T1 carry a `currency`
    field but FX revaluation / CTA postings live in T4-base.
  - T4-specialized (`add-shillinq-gov-sector-mkb-advanced`) — NL gov
    sector variants + Vpb + innovation regimes + MKB R&D +
    detachering bridge.
- **Future T5 work** — UBL/Peppol BIS 3.0 outbound, intercompany
  eliminations, advanced group consolidation, treasury cash
  forecasting, IFRS rebridge, multi-administration aggregation —
  tracked separately and explicitly OUT of this PR.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` already render
  generically from a register manifest. No bespoke Vue files in this
  spec.

## Approach

Three deltas, each adding ADDED Requirements to a brand-new spec:

1. **`bookkeeping-chart-of-accounts`** — declares the `Account`
   register (renaming the existing data-model entity `Account` to its
   bookkeeping role; the existing entry in `adr-000-data-model.md` is
   already aligned). Hierarchical via `parentAccountNumber`. Lifecycle
   `active → blocked → archived` declared as
   `x-openregister-lifecycle`. Seed templates loaded via
   `ConfigurationService::importFromApp()` during the repair step.
2. **`bookkeeping-general-ledger`** — declares the `GLTransaction`
   and `GLLine` registers (the existing data-model entry
   `GeneralLedgerEntry` is the per-line equivalent; this spec
   formalises the header/line split needed for balancing). The
   header carries balanced-state and posting-state lifecycle
   transitions; the balance constraint is an
   `x-openregister-lifecycle` precondition on `post`, not a PHP
   service check.
3. **`bookkeeping-journal-entries`** — declares the `JournalEntry`
   register as a higher-level human-authored construct that
   materialises one or more GL transactions on post. Sub-types:
   `manual` (memoriaal), `recurring` (with `cadence`), and
   `reversing` (auto-creates inverse on next period boundary).
   Approval gate consumed via the OpenRegister approval-workflow
   abstraction.

All three specs follow the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed for traceability
(`REQ-CoA-*`, `REQ-GL-*`, `REQ-JE-*` as requested for this multi-spec
change) and renumbered to start at REQ-001 within each capability when
folded in by `opsx-apply`.

## New Dependencies

None. This change consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35` (from
`shillinq-manifest-tier4`).

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 schemas
  (`Account`, `GLTransaction`, `GLLine`) and 1 supporting schema
  (`JournalEntry`); declares `x-openregister-lifecycle` rules on
  `GLTransaction` (balance + posting) and `JournalEntry` (approval).
- `lib/Settings/seeds/rgs-3.5-mkb.json`,
  `lib/Settings/seeds/rgs-3.5-zzp.json`,
  `lib/Settings/seeds/rgs-bbv.json` — new files, seed account
  templates. Imported via `ConfigurationService::importFromApp()` in
  the repair step.
- `src/manifest.json` — adds 3 navigation entries
  (Chart of Accounts, General Ledger, Journals) and 3 `type: index` +
  3 `type: detail` page entries.
- Repair step (`lib/Migration/Version*.php` or repair class) —
  reuses the existing register-import pattern; one additional step
  to seed the chosen RGS template per administration.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being
  stable: `x-openregister-lifecycle` (ADR-031), audit-trail-immutable
  (ADR-022), approval-workflow (ADR-022 + the `object-interactions`
  spec). If a needed shape is missing (e.g. cross-schema sum
  constraint inside a lifecycle precondition), the gap is filed as an
  OR issue and the relevant requirement is annotated in `design.md`
  under "Declarative-vs-imperative decision".
- **docudesk** — journal entries reference attachments by foreign-key
  URI for source documents. No code coupling — the FK is a plain
  string field validated by JSON Schema.

## Risks

### Risk 1: OpenRegister's lifecycle engine cannot express the cross-line balance constraint declaratively

**Severity**: Medium
**Mitigation**: The balance constraint requires summing
`GLLine.debit` and `GLLine.credit` rows that reference the parent
`GLTransaction` and asserting equality before allowing the
`post` transition. Whether this fits inside an
`x-openregister-lifecycle.requires` declaration depends on whether
the engine supports cross-schema aggregations in preconditions. If
not, ADR-031's exception path applies: a thin
`BookkeepingBalanceGuard::isBalanced(transactionId)` PHP guard is
called from the lifecycle's `requires` field. The guard is single-
method, no state, ~20 LOC. Document the gap as an OR issue. The
spec author resolves this during `opsx-ff` discovery, not during
`opsx-apply`.

### Risk 2: RGS template scope creep

**Severity**: Low
**Mitigation**: Ship the three named templates only (SMB / ZZP /
BBV). Industry-specific templates (housing corp, healthcare,
education) are explicitly out of scope; record them on the T2+
roadmap. Operators can extend a seeded template through normal
OpenRegister object edits — no template forking required.

### Risk 3: Tier-cascade — downstream specs blocked if T1 lands incomplete

**Severity**: Medium
**Mitigation**: T1 is the foundation; getting the schema shape wrong
forces a destructive migration later. Mitigation is rigorous spec
review (reviewer checks every requirement against the
data-model ADR and against existing competitor schemas — Exact, Twinfield,
AFAS, Yuki — captured in `design.md`'s Reuse Analysis). Acceptance
criterion: a competent bookkeeper can read the three specs and
confirm the model matches a real Dutch SMB chart-of-accounts +
double-entry posting flow without modification.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation (separate
cycle), rollback follows the standard pattern: revert the implementing
PR, run the repair step in down-direction (registers are
non-destructive — unused schemas remain queryable but unreferenced).
No data migration risk at the spec stage.

## Open Questions

1. **Cross-schema balance precondition** — see Risk 1. The
   `opsx-ff` design phase resolves whether the lifecycle engine
   supports the constraint or whether a `requires` PHP guard is
   needed. The spec itself is shape-neutral: `REQ-GL-005` mandates
   the balance invariant without prescribing implementation.
2. **RGS version pinning** — RGS 3.5 is the current SBR-compatible
   release as of 2026-05. If 4.x ships before T1 implementation,
   the seed file naming carries the version (`rgs-3.5-*.json`) so
   side-by-side coexistence is trivial.
3. **Closing-account designation cardinality** — exactly one
   closing account per accountType cluster, or per administration?
   `REQ-CoA-009` defines per-administration single closing account;
   confirm with the bookkeeper persona before `opsx-apply`.



## Design

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

T1 ships three RGS template seeds, all under
`lib/Settings/seeds/`:

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

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "RGS 3.5", "variant":
  "mkb", "imported": "<iso-timestamp>" } }`) so a future migration to
  RGS 4.x can identify which records were template-sourced versus
  operator-authored.

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



## Tasks

# Tasks — Bookkeeping Foundation (T1)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the three spec deltas — they
> are recorded now so the spec-review gate, dependency planning, and
> tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## 0. Deduplication Check

### Task 0.1: Confirm no T1 schema or capability already exists

- **spec_ref**: all three specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no `Account`, `GLTransaction`, `GLLine`, or `JournalEntry`
    schema is already declared (only the placeholder `example`).
  - GIVEN `openspec/specs/` WHEN scanned THEN no
    `bookkeeping-*` capability spec already exists.
  - GIVEN `adr-000-data-model.md` WHEN read THEN the existing entries
    for `Account`, `GeneralLedgerAccount`, `GeneralLedgerEntry`,
    `JournalEntry`, `FiscalYear` are catalogued and the reconciliation
    note from `design.md` is appended.
- [ ] Implement
- [ ] Test

## 1. Spec foundation (this change)

### Task 1.1: Author bookkeeping-chart-of-accounts spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-chart-of-accounts/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` /
    `Depends on: none` header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-CoA-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header per conduction-schema rule).
- [x] Implement
- [ ] Test (spec validation — `openspec validate` clean)

### Task 1.2: Author bookkeeping-general-ledger spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` /
    `Depends on: bookkeeping-chart-of-accounts` header.
  - GIVEN the spec WHEN scanned THEN it declares the header/line
    split (`GLTransaction` + `GLLine`), the balance invariant, the
    period-stamp field, and references ADR-022 for audit and
    ADR-031 for the lifecycle precondition.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.3: Author bookkeeping-journal-entries spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger` (which transitively
    depends on bookkeeping-chart-of-accounts).
  - GIVEN the spec WHEN scanned THEN it declares the three
    sub-types (manual / recurring / reversing), the docudesk
    source-document FK, and the OR approval-workflow integration
    via `x-openregister-lifecycle.requires`.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.4: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the
    shared `nextcloud-app` spec per shillinq config.yaml
    `rules.proposal` and includes Affected Projects / Scope /
    Risks / Rollback / Open Questions.
  - GIVEN `design.md` WHEN inspected THEN it includes a
    Reuse Analysis table and a Seed Data section per hydra
    `rules.design`.
- [x] Implement
- [ ] Test (peer review — bookkeeper persona reads the model
  end-to-end and confirms RGS conformance)

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare the `Account` schema

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-001 .. REQ-CoA-009)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN a JSON Schema validator
    WHEN `Account` is loaded
    THEN every field from REQ-CoA-002 (accountNumber, name,
    accountType, currency, parentAccountNumber, isClosingAccount,
    administrationId, lifecycleState, description) is present with
    the typing the spec mandates.
  - GIVEN the `Account` schema
    WHEN scanned for lifecycle metadata
    THEN it carries an `x-openregister-lifecycle` block with the
    `active → blocked`, `active → archived`, and `blocked →
    archived` transitions from REQ-CoA-005.
  - GIVEN the parent-relation field
    WHEN scanned
    THEN it carries `x-openregister-relations` self-relation per
    REQ-CoA-003.
- [ ] Implement
- [ ] Test (`composer check:strict` + `npm run check:manifest` if
  the manifest validator is wired; PHPUnit integration test
  asserting schema load + lifecycle transition behaviour)

### Task 2.2: Declare the `GLTransaction` schema

- **spec_ref**: `bookkeeping-general-ledger/spec.md` (REQ-GL-001 .. REQ-GL-006)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema
    WHEN loaded
    THEN it carries header fields per REQ-GL-002 (transactionNumber,
    postingDate, periodId, currency, description, sourceReference,
    state, journalEntryId, administrationId).
  - GIVEN the schema's lifecycle
    WHEN scanned
    THEN it carries `draft → posted` and `posted → reversed`
    transitions per REQ-GL-004 and the balance-invariant precondition
    per REQ-GL-005.
  - GIVEN the precondition on `post`
    WHEN inspected
    THEN it either declares a cross-line aggregation inside
    `x-openregister-lifecycle.requires` OR references a single-method
    PHP guard (`OCA\Shillinq\Lifecycle\BalanceGuard`) per the
    ADR-031 exception path — design.md's open question resolves which.
- [ ] Implement
- [ ] Test (PHPUnit asserting unbalanced posting fails; balanced
  posting succeeds; reversed posting emits inverse audit event)

### Task 2.3: Declare the `GLLine` schema

- **spec_ref**: `bookkeeping-general-ledger/spec.md` (REQ-GL-003)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema
    WHEN loaded
    THEN fields per REQ-GL-003 are present (transactionId,
    lineNumber, accountNumber, side, amount, currency, periodId,
    subLedgerType, subLedgerRef, costCenter, description).
  - GIVEN `side`
    WHEN scanned
    THEN it is an enum of `["debit", "credit"]`.
  - GIVEN `amount`
    WHEN scanned
    THEN it is a non-negative number; the sign is encoded in `side`
    per REQ-GL-003.
- [ ] Implement
- [ ] Test (PHPUnit: rejecting `side: both`, rejecting negative
  amount; accepting valid line)

### Task 2.4: Declare the `JournalEntry` schema with three sub-types

- **spec_ref**: `bookkeeping-journal-entries/spec.md` (REQ-JE-001 .. REQ-JE-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema
    WHEN loaded
    THEN fields per REQ-JE-002 are present (journalNumber, entryDate,
    description, lines, sourceDocumentUri, sourceDocumentApp,
    journalType, cadence, reversesOn, glTransactionId,
    approvalState, administrationId, state).
  - GIVEN `journalType`
    WHEN scanned
    THEN it is an enum of `["manual", "recurring", "reversing"]`.
  - GIVEN the schema's lifecycle
    WHEN scanned
    THEN it declares `pending → posted → voided` with the
    approval-workflow `requires` per REQ-JE-008.
  - GIVEN `cadence`
    WHEN journalType is `recurring`
    THEN it is required; otherwise it is forbidden (REQ-JE-005).
- [ ] Implement
- [ ] Test (PHPUnit: lifecycle transitions; recurring materialisation
  via the OR scheduled-workflow primitive; reversing journal posts
  inverse on period boundary)

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1: Ship RGS 3.5 SMB seed template

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-006)
- **files**: `lib/Settings/seeds/rgs-3.5-mkb.json`
- **acceptance_criteria**:
  - GIVEN the seed file
    WHEN loaded
    THEN it is a JSON array of records each conforming to the
    `Account` schema.
  - GIVEN the file
    WHEN opened
    THEN the top SPDX header is present and an `_meta` block with
    `source: "RGS 3.5"`, `variant: "mkb"` is included per design.md
    Seed Data section.
- [ ] Implement
- [ ] Test (PHPUnit: load + import + queryable; record count
  matches RGS 3.5 canonical SMB cardinality)

### Task 3.2: Ship RGS 3.5 ZZP seed template

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-006)
- **files**: `lib/Settings/seeds/rgs-3.5-zzp.json`
- **acceptance_criteria**:
  - GIVEN the seed file
    WHEN loaded
    THEN every record conforms to the `Account` schema and the
    `_meta.variant` is `"zzp"`.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.3: Ship BBV seed template for government bookkeeping

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-006)
- **files**: `lib/Settings/seeds/rgs-bbv.json`
- **acceptance_criteria**:
  - GIVEN the seed file
    WHEN loaded
    THEN every record conforms to the `Account` schema and the
    `_meta.variant` is `"bbv"`.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.4: Extend the repair step to import the selected template

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-007)
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh shillinq install
    WHEN the repair step runs
    THEN the chosen RGS template's accounts appear in the `Account`
    register, idempotent on re-run.
  - GIVEN per-administration override
    WHEN an account is edited after seeding
    THEN the operator edit persists across subsequent repair runs
    (the repair step does not re-overwrite seeded records).
- [ ] Implement
- [ ] Test (PHPUnit + browser smoke in dev container)

## 4. Manifest navigation — `src/manifest.json`

### Task 4.1: Add Chart of Accounts navigation + pages

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-008)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest
    WHEN scanned
    THEN it declares a menu entry `Bookkeeping > Chart of Accounts`
    (or top-level if the bookkeeper persona review favours a flat
    nav), a `type: index` page binding to the `Account` register,
    and a `type: detail` page for individual accounts.
  - GIVEN `node tests/validate-manifest.js`
    WHEN run
    THEN it exits 0 (schema + consistency clean).
- [ ] Implement
- [ ] Test (validate-manifest + browser smoke)

### Task 4.2: Add General Ledger navigation + pages

- **spec_ref**: `bookkeeping-general-ledger/spec.md` (REQ-GL-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest
    WHEN scanned
    THEN it declares a menu entry `Bookkeeping > General Ledger`
    with `type: index` + `type: detail` pages binding to
    `GLTransaction` (the detail page shows GL header + lines).
  - GIVEN `validate-manifest.js`
    WHEN run
    THEN it exits 0.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.3: Add Journals navigation + pages

- **spec_ref**: `bookkeeping-journal-entries/spec.md` (REQ-JE-009)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest
    WHEN scanned
    THEN it declares a menu entry `Bookkeeping > Journals` with
    `type: index` + `type: detail` pages binding to `JournalEntry`.
  - GIVEN the detail page config
    WHEN inspected
    THEN it surfaces the `journalType`, `state`, `approvalState`,
    `sourceDocumentUri`, and the line grid.
- [ ] Implement
- [ ] Test (same as 4.1)

## 5. ADR-000 reconciliation note

### Task 5.1: Update adr-000-data-model.md

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the ADR
    WHEN opened
    THEN the `GeneralLedgerEntry` section carries a one-paragraph
    note: "Superseded by `GLLine` from
    `bookkeeping-general-ledger`; T1 split the flat entry into
    header (`GLTransaction`) + line (`GLLine`) to make the balance
    constraint declarative per ADR-031".
  - GIVEN the `Account` and `GeneralLedgerAccount` sections
    WHEN read
    THEN the reconciliation paragraph from design.md's Reuse
    Analysis is inserted.
- [ ] Implement
- [ ] Test (peer review by the bookkeeper persona)

## 6. Lifecycle guard (conditional — only if Risk 1 confirms)

### Task 6.1 (conditional): Author BalanceGuard

- **spec_ref**: `bookkeeping-general-ledger/spec.md` REQ-GL-005
- **files**: `lib/Lifecycle/BalanceGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN the discovery step concluded the engine cannot express
    the cross-line balance constraint declaratively
    WHEN the guard is implemented
    THEN it has exactly one method `isBalanced(string $transactionId): bool`
    and is referenced from `x-openregister-lifecycle.requires` on
    the `GLTransaction.post` transition.
  - GIVEN the guard
    WHEN code-reviewed
    THEN it carries the ADR-031 exception annotation linking back
    to design.md's Declarative-vs-imperative decision table.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: balanced returns true; unbalanced returns false;
  decimal precision edge cases — €0.005 rounding)

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a competent Dutch bookkeeper persona
      (e.g. `/test-persona-janwillem` for SMB, or a domain-expert
      review) confirms the schema shape matches a real RGS-conformant
      ledger
- [ ] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031
      compliance (no app-local audit; no app-local approval table; no
      service-class state machines; manifest carries the navigation)
- [ ] No source code changes outside `openspec/changes/add-shillinq-bookkeeping-foundation/`

## Tests (company-wide ADR-009)

<!-- T1 spec-only change. Implementation-cycle tests are pre-declared on tasks 2-6 above for completeness. -->

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — declared on tasks 2.1, 2.2, 2.3, 2.4, 3.4, 6.1; lands with implementation cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new endpoints in T1 (OR exposes register CRUD generically; tests cover the register HTTP surface)
- [ ] Browser tests (Playwright MCP) for UI changes — declared on tasks 4.1, 4.2, 4.3; lands with implementation cycle
- [ ] All tests pass (`composer test`) — enforced at implementing PR's CI gate

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with the implementation cycle, not the spec. -->

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/` — `docs/user-guide/bookkeeping/` index + per-capability pages (chart-of-accounts, general-ledger, journal-entries) authored during implementation cycle per ADR-030 journeydoc convention
- [ ] Screenshot captured and committed to `docs/images/` — authored during implementation cycle (3 screenshots: CoA index, GL detail, Journal create form)

## i18n (company-wide ADR-005)

<!-- No user-facing strings in the spec; translation work lands with the implementation cycle. -->

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added during implementation cycle — required terms: `Bookkeeping`, `Chart of Accounts`, `General Ledger`, `Journal Entry`, `Account`, `Debit`, `Credit`, `Balance`, `Posted`, `Reversed`, `Approval Pending`, `Recurring`, `Reversing`