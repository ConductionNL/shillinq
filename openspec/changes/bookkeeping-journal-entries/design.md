# Design — Journal Entries (T1 foundation)

## Context

Shillinq's double-entry ledger foundation (T1) includes:
- `bookkeeping-chart-of-accounts` — hierarchical account structure
- `bookkeeping-general-ledger` — machine-level GL transactions + balanced posting
- `bookkeeping-journal-entries` — human-level journal entry surface

Bookkeepers work with journal entries, not raw GL transactions. A journal entry is a
human-authored form that the system converts into a balanced GL posting when "posted".
Three sub-types cover the full range: manual (one-off), recurring (cadence-driven),
and reversing (auto-flip at period boundary).

This design doc explains *why* the `JournalEntry` schema is shaped the way it is,
how it connects to the GL layer, and what declarative abstractions it consumes from
OpenRegister.

## Goals

- Express the entire journal-entry surface as **declarative metadata** — schema +
  `x-openregister-lifecycle` rules + manifest entries — per ADR-031. No new PHP
  service classes (see Risk 1 in proposal.md for the one exception).
- Consume every OpenRegister abstraction that already exists for audit trail, RBAC,
  approval workflow, scheduled workflows, attachments — per ADR-022. No reimplementation
  in shillinq.
- Make the spec a **competent-bookkeeper readable contract** — a Dutch SMB accountant
  should recognise journal entries as the standard memoriaalboekingen, recurring
  templates, and reversing accruals without modification.
- Keep the two-layer separation clear: `JournalEntry` is the human surface;
  `GLTransaction` is the machine result. Recurring and reversing templates are *not*
  themselves postings — they are templates that produce postings.

## Non-Goals

- No invoicing, no AP/AR sub-ledgers — T2's job.
- No period close, no trial balance — T3's job.
- No multi-currency translation, no VAT/BTW posting automation — T5's job.
- No frontend Vue components beyond the generic `CnIndexPage` / `CnDetailPage`.
- No PHP code authored in this change (the spec-only intent is explicit; tasks reference
  where a materialization service lands *if* needed per Risk 1).

## Decisions

### D1 — Declarative-first, per ADR-031

Every T1 behaviour expressible as schema metadata MUST be declared in
`lib/Settings/shillinq_register.json`, not authored as a PHP service. Concretely:

| Behaviour | Declarative form |
|---|---|
| Journal draft/pending/posted/voided state machine | `x-openregister-lifecycle` on `JournalEntry` |
| Approval gate on posting | `x-openregister-lifecycle` consuming OR's approval-workflow extension |
| Recurring journal materialisation on cadence | OR's `ScheduledWorkflow` primitive + n8n adapter |
| Reversing journal auto-flip at period boundary | Lifecycle action emitting event for OR to consume |
| Audit trail of every state change | OR's built-in audit-trail-immutable (no app config required) |

**Alternative considered**: Author a PHP `JournalService` handling all state transitions,
approval routing, and materialization. Rejected per ADR-031 — that is exactly the
anti-pattern decidesk's MotionService / VotingService / QuorumService are mid-migration
away from. T1 starts clean.

### D2 — `JournalEntry` is the human surface; `GLTransaction` is the materialised result

Bookkeepers think in journal entries (manual entries, recurring templates, accruals).
The GL transaction is the machine-readable balanced posting created when a journal is
posted. T1 keeps the two layers explicit:

- A `JournalEntry` can be `manual`, `recurring`, or `reversing`.
- Posting a `JournalEntry` materialises exactly one `GLTransaction` (with N `GLLine` rows).
  The materialisation is declarative per ADR-031 (lifecycle action + event), not a PHP
  service.
- `JournalEntry.glTransactionId` is the back-reference once posted.
- Recurring journals declare a `cadence` object; the OR scheduled-workflow primitive
  materialises postings on the schedule.
- Reversing journals declare `reversesOn` (period boundary); on that boundary, the OR
  lifecycle engine creates the inverse posting.

**Alternative considered**: Collapse `JournalEntry` and `GLTransaction` into one schema.
Rejected — recurring and reversing templates need a header that is *not* itself a posting
(they are templates that *produce* postings on a schedule or at a boundary). Two layers
also match every reference product (Exact, AFAS, Yuki, Twinfield, Snelstart).

### D3 — Source documents reference docudesk by FK, not by embedded blob

A journal entry's source document (PDF invoice, scan, bank statement) is referenced by
docudesk attachment URI. The `JournalEntry.sourceDocumentUri` field stores a stable URI;
the content lives in docudesk's storage. No file blob in the register.

This is the ADR-022 canonical pattern: when OR or a sibling app provides an abstraction,
consume it by reference, not by copy.

### D4 — Approval gate via OR's approval-workflow extension

Posting a `JournalEntry` may require approval depending on administration policy
(e.g., journals over €5000 require approval; all government journals require dual control).
The approval workflow is OR's, not shillinq's:

```jsonc
"x-openregister-lifecycle": {
  "transitions": {
    "post": {
      "from": ["draft", "pending"],
      "to": "posted",
      "requires": { "approval-workflow": { "policy": "@self.approvalPolicy" } }
    }
  }
}
```

shillinq declares the trigger; OR's engine runs the approval routing, recipient resolution,
and approval-state machine. Per ADR-022, no app-local approval table.

### D5 — Three journal sub-types: manual, recurring, reversing

T1 supports exactly three journal sub-types. Adding a new sub-type (e.g., `closing` for
T3 period close) MUST go through a future openspec change with explicit enum-extension
justification.

- **`manual`** — operator-authored one-off entry (memoriaalboekingen)
- **`recurring`** — template that materialises postings on a cadence (abonnementen,
  maandelijkse afschrijvingen, periodieke transitorische posten)
- **`reversing`** — entry whose materialised posting is automatically inverted at the
  start of a designated future period (periode-eind accruals, vooruitbetaalde kosten)

The `journalType` field is a closed enum; unknown values are rejected at schema
validation.

### D6 — Cadence shape for recurring journals

A `JournalEntry` with `journalType: "recurring"` MUST carry a `cadence` object of the
shape:

```json
{
  "interval": "monthly" | "weekly" | "daily" | "yearly",
  "anchor": "2026-01-01",
  "endsOn": "2026-12-31" | null,
  "count": 12 | null
}
```

The cadence is consumed by OR's `ScheduledWorkflow` primitive (per ADR-031 background-job
guidance). On each scheduled tick, a new `GLTransaction` is materialised from the template's
lines and posted with back-reference to the template via `journalEntryId`.

**Alternative considered**: Hardcode a set of intervals (monthly, quarterly, yearly) as
enum values. Rejected — future tiers may need business-day cadences, week-of-month
patterns, or custom schedules. The JSON object shape allows evolution without schema
breaking changes.

### D7 — Reversing journals trigger at period boundary

A `JournalEntry` with `journalType: "reversing"` MUST carry a `reversesOn` field
naming the `periodId` whose start triggers the inverse posting. On the period boundary,
OR's lifecycle engine MUST materialise a new `GLTransaction` with the opposite `side`
on every line, post it, and link it back to the original via `reversesTransactionId`.

The schedule MUST NOT be implemented as a per-app `*Job` PHP class walking `findAll()`
(per ADR-031 §"Background jobs that walk an object queue" — paths 1 and 2 are correct;
path 3 is the anti-pattern). T1 RECOMMENDS the OR `ScheduledWorkflow` + n8n adapter
path; the alternative is a lifecycle action driven by T3's period-close transition.

**Alternative considered**: Implement reversals as a post-write hook in shillinq. Rejected
— moves the behaviour from "declared on the schema" to "lives in the hook", which is the
ADR-031 anti-pattern. Lifecycle declaration with OR orchestration is cleaner.

## Reuse Analysis

| Capability needed | What already exists | T1 reuse strategy |
|---|---|---|
| Journal entry shape | `adr-000-data-model.md` `JournalEntry` entry | T1's spec aligns with the existing entry; renames `isBalanced` → derived (declarative calculation per ADR-031 instead of stored field), keeps `entryDate`, `entryNumber`, `description`, `journalCode`, `reference`, `departmentCode`, `memo`. |
| GL transaction materialisation | `adr-000-data-model.md` `GLTransaction` + `bookkeeping-general-ledger` spec | Consumed via lifecycle action → event → OR materialisation. No app-local code. |
| State machine | OR `x-openregister-lifecycle` | Declares transitions: draft → pending → posted → voided (or draft → posted if approval not required). |
| Approval workflow | OR approval-workflow (per ADR-022) | Consumed via `x-openregister-lifecycle.requires`. No app-local approval logic. |
| Audit trail | OR audit-trail-immutable | Consumed automatically (no schema config). Every state transition writes an audit event. |
| RBAC | OR authorization | Per-schema role definitions in the register file. T1 grants `bookkeeper` create/read on `JournalEntry`, `approver` the `post` transition, `auditor` read-only. |
| Attachments | docudesk | Referenced by URI from `JournalEntry.sourceDocumentUri`. No file storage in shillinq. |
| Recurring schedule | OR `ScheduledWorkflow` + n8n adapter (per ADR-031 background-job guidance) | A `JournalEntry` of sub-type `recurring` declares a cadence; the OR scheduled-workflow primitive materialises the next posting. No app-local cron job. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | T1 adds 1 menu entry + 1 index page + 1 detail page, all consuming `type: index` / `type: detail` library renderers. |

**Net new code in T1 implementation**: 1 schema declaration + 1 manifest page + 0 seed
JSON files (no JournalEntry seed data — those are accumulated through operation). Possibly
1 thin PHP service (~50 LOC, single method) if Risk 1 in `proposal.md` confirms the
cross-schema materialisation cannot run inside the declarative engine. See
"Declarative-vs-imperative decision" section below.

## Declarative-vs-imperative decision (per ADR-031)

Per ADR-031 enforcement, each behaviour was classified before this spec was finalised:

| Behaviour | Decision | Why |
|---|---|---|
| Journal state machine | Declarative (`x-openregister-lifecycle`) | Pure state machine, fits the extension |
| Approval gate on posting | Declarative (consumes OR's approval-workflow extension) | ADR-022 pattern |
| Recurring journal materialisation | Declarative — OR's `ScheduledWorkflow` + n8n adapter | ADR-031 §"Background jobs that walk an object queue" path 2 |
| Reversing journal auto-flip at period boundary | Declarative — lifecycle action emits event that OR consumes | T3 owns the period-close trigger; T1 declares the shape |
| Materialisation of GL transaction on journal post | Declarative IF OR's lifecycle engine supports cross-schema effects; otherwise a single-method PHP service `BookkeepingMaterializationService::materializeGLTransaction(journalId)` called *by* the lifecycle engine | Resolution lives in `opsx-ff` discovery; spec is shape-neutral |
| Audit trail of every state change | Consumed from OR's audit-trail-immutable abstraction | ADR-022 |

No service class authored in this T1 envelope beyond the one exception above. If the
materialisation needs a PHP service, it is `lib/Lifecycle/BookkeepingMaterializationService.php`,
single method, ~50 LOC — and explicitly cited as an ADR-031 exception in the implementing
cycle's design doc.

## Seed Data

T1 ships **zero** `JournalEntry` seed objects. Journal entries are operational data
accumulated through bookkeeping; they are not seeded. The register is empty on first
install and populated as operators record entries.

No seed files for recurring/reversing journal templates either — those are created
by operators as needed. If there is demand for a library of standard accrual templates
(year-end depreciation, monthly subscriptions), that is a T2+ feature request, not part
of T1.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR's lifecycle engine cannot materialise GL transaction on journal post declaratively | Document the gap as an OR issue; use a single-method PHP service `BookkeepingMaterializationService::materializeGLTransaction()` called *by* the lifecycle engine per ADR-031 §"PHP guards remain a legitimate seam". Spec is shape-neutral (REQ-JE-007 mandates the outcome). Resolved in `opsx-ff` discovery. |
| Scheduled-workflow + n8n adapter not ready in T1 | Ship manual + reversing in T1; defer recurring journals to T2's `add-shillinq-bookkeeping-compliance` change. The enum value `journalType: "recurring"` is allowed by the schema but rejected in the UI with "coming in T2". |
| Recurring journal cadence evolves; spec freeze forces breaking change | The cadence is a JSON object, not an enum. New interval types (business-day, week-of-month) can be added without schema migration. Backwards-compatible. |
| Approval policy scope ambiguity | Spec assumes a simple threshold policy per administration (journals over €5000 require approval). If policy needs per-journal-type variation (manual requires dual control; recurring automatic), resolve with bookkeeper persona in `opsx-ff`. Schema supports both via the `approvalPolicy` field. |
| Reversing journal collides with manual journal at period boundary | The reversing posting is timestamped to the period's first day; manual journals on that day post separately. Same audit trail surfaces both. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the new `JournalEntry` schema
   (additive — no existing schema changes).
2. `src/manifest.json` is patched with 1 new menu entry + 1 new index/detail page pair
   (additive).
3. If Risk 1 materialisation requires a PHP service: add `lib/Lifecycle/BookkeepingMaterializationService.php`
   (~50 LOC, single method).

Down-direction: registers are non-destructive — disabling the schema + reverting the
manifest leaves stranded but queryable records. No destructive rollback needed at the
spec-acceptance gate; real rollback happens at the implementing PR's revert.

## Open Questions

1. **Does `x-openregister-lifecycle` support emitting cross-schema effects?** Resolved in
   `opsx-ff` discovery before the implementing cycle starts. If no: thin PHP service,
   documented in design.md as Risk 1 mitigation.
2. **Is `ScheduledWorkflow` + n8n ready in T1, or defer recurring to T2?** Check OR
   roadmap / feature matrix before `opsx-apply`. If defer: ship manual + reversing,
   mark recurring as "coming in T2".
3. **Approval policy scope** — simple threshold (journals > €X) or per-journal-type?
   Confirm with bookkeeper persona during spec review.
