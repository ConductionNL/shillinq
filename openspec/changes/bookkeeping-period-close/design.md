# Design — Period Close

## Context

Shillinq T1 establishes the general ledger with posting rules. T2 adds the
period-close capability — a guided workflow with AI-powered task flagging that
turns a running ledger into auditable financial history. Dutch SMBs and
government entities run monthly or quarterly close cycles; the pattern is
industry-standard. This spec implements the period-close workflow as a guided
assistant with lifecycle gates to prevent backdating and audit-trail capture
for compliance.

This change implements both features from context-brief:
- AI-powered Close Assistant: guided month-end close with task flagging
- Run guided period close with task checklist and sign-off

## Goals

- Express the entire period-close surface as **declarative metadata** —
  `PeriodClose` schema + lifecycle + additive precondition on T1's
  `GLTransaction.post` — per ADR-031. Lifecycle gates are declarative; close
  assistant service detects incomplete tasks; Vue page surfaces the workflow.
- Consume OR's `x-openregister-lifecycle` per ADR-022. Lifecycle is declarative,
  not a PHP state machine.
- Implement **AI-powered task flagging** — close assistant detects open invoices,
  unreconciled bank lines, outstanding dunning notices, expense claims; flags
  surface as warnings to guide operator.
- Make the spec a **competent-bookkeeper readable contract** — a Dutch SMB
  accountant should recognise the `open → closing → closed → audit-locked` flow
  and the guided close checklist as the industry-standard pattern.
- Support the **guided close workflow** — structured checklist with mandatory
  sign-off, audit-trail capture, reason fields for reopen requests.

## Non-Goals

- No PHP state-machine service, no custom workflow engine.
- No year-end close (opening-balance journal generation, retained-earnings
  rollover) — T3.
- No VAT-period close — T3.
- No multi-step close (e.g., separate VAT close before GL close) — T3.
- No custom UI components beyond the detail page binding to manifest.

## Decisions

### D1 — Period close is a lifecycle on a new `PeriodClose` register

`PeriodClose` is a new register with an `open → closing → closed → audit-locked`
lifecycle declared per ADR-031. Postings against a closed period are rejected by
an OR lifecycle precondition on `GLTransaction.post` (added additively to T1's
existing precondition list).

**Alternative considered**: A PHP `PeriodCloseService` state machine. Rejected
per ADR-031 — explicit anti-pattern.

### D2 — `closed` is reversible, `audit-locked` is irreversible

The `closed` state is reversible by an operator with the elevated `period-closer`
role + an audit-trailed reason. The `audit-locked` state is irreversible — once
an auditor signs off, the period freezes. Original close timestamp + actor are
preserved in `reopenedHistory`. Industry-standard shape; matches Exact / AFAS /
Twinfield. Late corrections after audit-lock require a compensating journal in
the next open period.

**Alternative considered**: Both states reversible. Rejected — audit integrity
requires irreversibility once signed off.

### D3 — Close assistant detects incomplete tasks via a service, flags surface as warnings

`PeriodCloseAssistantService` queries the database for:
- Open AP transactions (outstanding invoices + dunning notices not yet paid)
- Open AR transactions (outstanding invoices not yet collected)
- Unreconciled bank lines (bank receipts with no GL posting match)
- Outstanding expense claims (submitted but not approved/reimbursed)

Results are formatted as *warnings*, not blockers. Operators review and manually
mark as resolved. All flags are audit-trailed and retained in `PeriodClose.aiFlags`.

**Alternative considered**: Flags as blockers — prevent close if any flag exists.
Rejected — operators need flexibility for edge cases; flags guide, not gate.

### D4 — Year-end close explicitly deferred to T3

T2 declares only the monthly/quarterly close lifecycle. Year-end close
(opening-balance journal generation, retained-earnings rollover) is its own
concern and ships in T3.

### D5 — Guided close workflow via manifest-bound detail page

`PeriodCloseDetail.vue` is a standard Vue detail page bound through the manifest
to the `PeriodClose` register. It surfaces:
- Period metadata (start/end dates, fiscal year, administration)
- Lifecycle action buttons (Close, Reopen, Lock for Audit)
- Task checklist (expandable sections: AP, AR, Bank, Expense Claims)
- AI-generated flags inline with checklist items
- Trial-balance preview link
- Audit-trail log of close/reopen actions

No custom state machine; all data from the `PeriodClose` register + related items.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Period lifecycle state machine | OR `x-openregister-lifecycle` (ADR-031) | The entire `PeriodClose` lifecycle |
| Closed-period posting rejection | OR `x-openregister-lifecycle` preconditions | Additive clause on T1's `GLTransaction.post` precondition list |
| Reopen audit-trail capture | OR audit-trail-immutable | Automatic on lifecycle transitions |
| Reopen role gate | OR authorization | `period-closer` role required for `closing → open` |
| Audit-lock role gate | OR authorization | `auditor` role required for `closed → audit-locked` |
| AI task detection | Claude API (ChatService) | Close assistant service queries DB, calls Claude for summary |
| Task checklist rendering | OpenRegister object data | `PeriodClose.taskChecklistItems` (array of task objects) |
| Trial balance preview | T2 `bookkeeping-trial-balance` aggregation | Manifest-side link from detail page action |
| Detail page + lifecycle UI | `@conduction/nextcloud-vue` (`CnDetailPage`, lifecycle buttons) | Manifest-bound standard components |

**Net new code in implementation**: 1 schema declaration + 1 lifecycle block +
1 additive precondition clause + 1 service class (close assistant) + 1 detail
page component + 1 manifest entry. No state-machine code.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Period-close lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Closed-period posting rejection | Declarative — adds to T1's existing `GLTransaction.post` precondition list | Engine already handles preconditions |
| Reopen role gate | Declarative — OR authorization on the transition | Standard role check |
| Audit-lock role gate | Declarative — OR authorization on the transition | Standard role check |
| Close reason capture | Declarative — schema field on reopen | Engine handles |
| AI task flagging | Imperative service call (CloseAssistantService) | Detection logic must query database; not statically declarable |
| Guided checklist UI | Imperative Vue component | Interaction flow (expand/collapse sections, mark items resolved) is UI logic |

The service + Vue components are minimal — they orchestrate data, not decision logic.

## Seed Data

The implementing cycle's repair step creates `PeriodClose` records for every
distinct open period in the administration (idempotent backfill). Example:

```json
{
  "@self": {
    "register": "shillinq",
    "schema": "PeriodClose",
    "slug": "2026-01"
  },
  "periodId": "2026-01",
  "administrationId": "admin-001",
  "startDate": "2026-01-01",
  "endDate": "2026-01-31",
  "state": "open",
  "closedAt": null,
  "closedBy": null,
  "auditLockedAt": null,
  "auditLockedBy": null,
  "closeReason": null,
  "reopenedHistory": [],
  "taskChecklistItems": [],
  "aiFlags": []
}
```

Seed includes 3-5 example periods (past, current, future) with varied states
(open, closed, audit-locked) so operators see realistic close workflows.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Reopening a closed period is destructive | Reopen requires elevated role + audit-trailed reason; original close timestamp + actor preserved; matches industry-standard behaviour |
| `audit-locked` irreversibility is too strict for late corrections | Corrective path is a compensating journal in the next open period; documented in the spec |
| AI task flagging false positives | Flags are warnings, not blockers; all flags audit-trailed; operators manually verify |
| Close assistant latency for large datasets | Async detection; timeout at 10s with "partial results" message; typical SMB detection < 2s |
| Operators close prematurely without resolving AP/AR | Pre-close checklist (manifest-side, surfaced on detail page) lists open items; non-blocking warnings guide operator |

## Migration Plan

Spec-only — no destructive runtime migration. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the `PeriodClose`
   schema + lifecycle (additive — no existing schema changes except T1's
   `GLTransaction.post` precondition list, which is additive).
2. `src/manifest.json` is patched with one new menu entry + one detail page entry
   (additive).
3. The repair step seeds `PeriodClose` records for every open period in each
   administration (idempotent backfill).
4. `PeriodCloseAssistantService` and `PeriodCloseDetail.vue` are new files
   (no refactoring).

Down-direction: registers are non-destructive — reverting removes the lifecycle
gating; `PeriodClose` records remain queryable but unreferenced.

## Open Questions

1. **Close assistant AI model** — Sonnet vs Opus vs Haiku tradeoff. Recommend
   Haiku for latency; escalate to Sonnet if accuracy issues surface.
2. **Default close cadence** — monthly vs quarterly. Recommend monthly;
   operator-configurable per administration during UX review.
3. **Pre-close checklist item types** — what closes count as *must verify*.
   Recommend: open AP invoices, open AR invoices. Unreconciled bank + dunning
   notices are *should verify*.
4. **Audit-lock approval workflow** — single auditor sign-off vs approval chain.
   Recommend single auditor (elevated role required); escalation chain is T3.
