# Design — Journal Entries

## Context

Shillinq's mission is a Conduction-native business administration suite
covering bookkeeping, invoicing, procurement, contracts, and downstream
reporting. The 5-tier rollout (see `proposal.md`) starts with Tier 1
**foundation**: a balanced double-entry general ledger built on top of
a hierarchical chart of accounts, fed by human-authored journal
entries.

This change is the **third slice** of Tier 1 — the journal-entry human
surface. It depends on `add-shillinq-chart-of-accounts` (which declares
`Account`) and `add-shillinq-general-ledger` (which declares
`GLTransaction` + `GLLine`); posting a journal entry materialises a
balanced `GLTransaction` with N `GLLine` rows.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire journal-entry surface as **declarative
  metadata** — schema + `x-openregister-lifecycle` rules + manifest
  entries — per ADR-031. No new PHP service classes.
- Consume every OpenRegister abstraction that already exists for
  audit trail, RBAC, approval workflow, attachments, scheduled
  workflows — per ADR-022. No reimplementation in shillinq.
- Make the spec a **competent-bookkeeper readable contract** — a
  Dutch SMB accountant should recognise the model as a faithful
  memoriaalboeking + recurring-template + reversing-accrual flow,
  with no surprises.
- Keep Tier 1 narrow enough that Tier 2/3/4/5 specs can each add
  their surface without reshaping the `JournalEntry` schema.

## Non-Goals

- No chart of accounts (sibling change owns the `Account` schema).
- No GL postings (sibling change owns `GLTransaction` + `GLLine`).
- No VAT/BTW posting automation — Tier 3's job.
- No frontend Vue components beyond the generic
  `CnIndexPage`/`CnDetailPage` from `@conduction/nextcloud-vue`
  bound through `src/manifest.json`.
- No PHP code authored in this change.

## Decisions

### D1 — `JournalEntry` is the human surface; GL transactions are the materialised result

Bookkeepers think in journal entries (memoriaalboekingen, recurring
templates, reversing accruals). The GL transaction is the
machine-readable balanced posting that gets created when a journal
is posted. Tier 1 keeps the two layers explicit:

- A `JournalEntry` can be a `manual`, `recurring`, or `reversing`
  sub-type.
- Posting a `JournalEntry` materialises exactly one `GLTransaction`
  (with N `GLLine` rows). The materialisation is declarative — a
  lifecycle `post` action emits a CloudEvent that the OR engine
  consumes and creates the GL header + lines. No PHP orchestration.
- `JournalEntry.glTransactionId` is the back-reference once posted.
- Reversing journals declare a `reversesOn: <periodId>` field; the
  OR scheduled-workflow primitive (per ADR-031 background-job
  guidance) creates the inverse posting at period boundary. The
  spec does not author the schedule; it requires it.

**Alternative considered**: Collapse `JournalEntry` and
`GLTransaction` into one schema. Rejected — recurring and reversing
templates need a header that is *not* itself a posting (they are
templates that *produce* postings on a cadence). Two layers also
match every reference product (Exact, AFAS, Yuki, Twinfield,
Snelstart).

### D2 — Recurring + reversing schedules declarative via `ScheduledWorkflow`

A `JournalEntry` of sub-type `recurring` carries a `cadence`
(cron-like or named-interval); the OR `ScheduledWorkflow` primitive
materialises the next posting per ADR-031 background-job path 2.
A `reversing` journal carries `reversesOn: <periodId>`; the same
scheduled-workflow primitive creates the inverse posting at period
boundary (Tier 3 owns the period-close trigger; Tier 1 declares the
shape).

**Alternative considered**: A `RecurringJournalService` with an
app-local cron job. Rejected per ADR-031 background-job guidance —
that is the anti-pattern; OR's `ScheduledWorkflow` + n8n adapter is
the canonical path, with the `occ openregister:scheduled-workflow:run`
fallback if the n8n adapter is not yet wired on the target environment.

### D3 — Source documents reference docudesk by FK, not by embedded blob

A journal entry's source document (PDF invoice, scan, bank
statement) is referenced by docudesk attachment URI. The
`JournalEntry.sourceDocumentUri` field stores a stable URI; the
content lives in docudesk's storage. No file blob in the register.

This is the ADR-022 canonical pattern: when OR or a sibling app
provides an abstraction, consume it by reference, not by copy.

### D4 — Approval gate via OR's approval-workflow extension

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

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Account FK | `add-shillinq-chart-of-accounts` `Account` schema | `JournalEntry.lines[].accountNumber` FKs into `Account.accountNumber`. |
| GL transaction back-reference | `add-shillinq-general-ledger` `GLTransaction` schema | `JournalEntry.glTransactionId` carries the materialised header's id. |
| Journal entry shape | `adr-000-data-model.md` `JournalEntry` | Spec aligns with the existing entry; renames `isBalanced` → derived (declarative calculation per ADR-031 instead of stored field), keeps `entryDate`, `entryNumber`, `description`, `accountCode`, `journalCode`, `reference`, `vatAmount` (deferred — Tier 5 owns VAT), `departmentCode`, `memo`. |
| Audit trail | OR audit-trail-immutable | Consumed automatically (no schema config). Every state transition writes an audit event with actor, before/after, timestamp, hash chain. |
| RBAC | OR authorization | Per-schema role definitions in the register file. Grants `bookkeeper` create/read, `approver` the `post` transition, `auditor` read-only. |
| Approval workflow | OR approval-workflow (per ADR-022) | Consumed via `x-openregister-lifecycle.requires`. No app-local approval logic. |
| Attachments | docudesk | Referenced by URI from `JournalEntry.sourceDocumentUri`. No file storage in shillinq. |
| Lifecycle engine | `x-openregister-lifecycle` (per ADR-031) | `JournalEntry` declares `pending → approved → posted → voided`. |
| Recurring schedule | OR `ScheduledWorkflow` + n8n adapter (per ADR-031 background-job guidance) | A `JournalEntry` of sub-type `recurring` declares a cadence; the OR scheduled-workflow primitive materialises the next posting. No app-local cron job. |
| Reversing schedule | OR `ScheduledWorkflow` + period-close trigger (Tier 3 owns the trigger) | Tier 1 declares the `reversesOn` field shape and the auto-inverse semantic; Tier 3 wires the trigger. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted on `feature/adopt-app-manifest`) | Adds 1 menu entry + 1 index page + 1 detail page. |

**Net new code in implementation cycle**: 1 schema declaration + 1
manifest entry pair. No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Journal entry pending/approved/posted/voided | Declarative (`x-openregister-lifecycle`) | Pure state machine, fits the extension |
| Approval routing | Consumed from OR's approval-workflow abstraction | ADR-022 |
| Recurring journal materialisation | Declarative — OR's `ScheduledWorkflow` + n8n adapter | ADR-031 §"Background jobs that walk an object queue" path 2 |
| Reversing journal materialisation | Declarative — lifecycle action on period close (Tier 3) emits the inverse | Tier 3 owns the trigger; Tier 1 declares the shape |
| Audit trail | Consumed from OR's audit-trail-immutable abstraction | ADR-022 |
| Source-document linkage | FK URI to docudesk attachment | ADR-022 |

No service class authored in this envelope.

## Seed Data

None for the journal-entry surface — `JournalEntry` records are
accumulated through operation. Opening-balances ship as the first
operator-authored `JournalEntry` of sub-type `manual` with a
`sourceDocumentUri` pointing at the docudesk attachment of the prior
system's closing trial balance.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR's approval-workflow does not expose amount-threshold policy binding | File OR issue; ship the static-policy variant in Tier 1 and queue threshold policies for the next OR release. Spec mandates the behaviour without prescribing the OR mechanism. |
| Reversing journal collides with manual journal at period boundary | The reversing posting is timestamped to the period's first day; manual journals on that day post separately. Same audit trail surfaces both. |
| Recurring materialisation needs `ScheduledWorkflow` + n8n adapter | If the adapter is not yet wired on the target environment, fall back to a Nextcloud cron job invoking `occ openregister:scheduled-workflow:run` per ADR-031 — still no app-local cron. |
| Future tier needs a `JournalEntry` field Tier 1 didn't anticipate | Adding fields to a register schema is additive; breaking changes are vanishingly rare. Risk accepted. |
| ADR-000 data-model entry `JournalEntry` overlaps with this spec | Reconciliation is a one-paragraph annotation in ADR-000 added during the implementation cycle. Not in this spec. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the
   `JournalEntry` schema (additive — no existing schema changes).
2. `src/manifest.json` is patched with one new menu entry + one new
   index/detail page pair (additive).
3. ADR-000 gains a one-paragraph annotation noting the spec
   superset of the existing `JournalEntry` entry.

Down-direction: registers are non-destructive — disabling the
manifest leaves stranded but queryable records. No destructive
rollback needed at the spec-acceptance gate.

## Open Questions

1. **Approval-policy binding shape** — resolved in `opsx-ff`
   discovery with OR's current `approval-workflow` extension shape.
2. **Reversing-journal collision with manual journal at period
   boundary** — the reversing posting is timestamped to the period's
   first day; manual journals on that day post separately. Confirm
   with the bookkeeper persona that this is the desired UX.
