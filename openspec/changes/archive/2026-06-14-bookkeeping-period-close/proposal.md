# Proposal: bookkeeping-period-close

`kind: feature` — the centre of mass is a guided period close workflow with
AI-powered task flagging and a declarative lifecycle on the new
`PeriodClose` register that gates posting against closed periods. Implements
the period-close assistant capability as T2 compliance + operations feature.

## Summary

Introduce the **period close** capability for Shillinq bookkeeping as a T2
compliance + operations feature. This change adds a new `PeriodClose` register
with an `open → closing → closed → audit-locked` lifecycle, an AI-powered
close assistant that flags incomplete tasks (open AP/AR, unreconciled bank
lines, outstanding dunning notices), and a guided month-end close workflow
with mandatory sign-off. Postings against a closed period are rejected by an
OpenRegister lifecycle precondition. Reopening a closed period requires
elevated role + audit-trailed reason; the `audit-locked` state is irreversible.

This change implements both features listed in the context-brief:
- AI-powered Close Assistant: guided month-end close with task flagging
- Run guided period close with task checklist and sign-off

Year-end close (opening-balance journal generation, retained-earnings rollover)
is explicitly deferred to T3.

**Depends on:** `bookkeeping-general-ledger` (GLTransaction posting rules),
`bookkeeping-trial-balance` (pre-close preview).

## Motivation

A period close turns a running ledger into auditable financial history. Without
it, postings can be backdated and audits fail. Dutch SMBs and government entities
run monthly or quarterly close cycles — the pattern is industry-standard (matches
Exact / AFAS / Twinfield). This spec implements the period-close workflow as a
guided assistant with AI-powered task flagging, reducing manual checklist burden
and improving close quality.

The two features drive this implementation:
1. **AI-powered Close Assistant** (demand 701): automated detection of incomplete
   close tasks — open invoices, unreconciled items, outstanding dunning notices.
2. **Guided Period Close** (demand 696): structured workflow with mandatory
   sign-off and audit-trail capture.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-period-close`);
  declares 1 new register (`PeriodClose`) in `lib/Settings/shillinq_register.json`
  with the lifecycle block; adds an additive closed-period rejection clause to
  T1's `GLTransaction.post` precondition list; adds 1 manifest navigation entry;
  implements AI close assistant service + Vue detail page.
- [ ] Project: openregister — no source changes; consumes
  `x-openregister-lifecycle` (precondition + reopen workflow).

## Scope

### In Scope

- One new capability spec (`bookkeeping-period-close`) implementing both features
  from context-brief.
- The `PeriodClose` register with fields: `periodId`, `administrationId`,
  `startDate`, `endDate`, `state`, `closedAt`, `closedBy`, `auditLockedAt`,
  `auditLockedBy`, `closeReason`, `reopenedHistory`, `taskChecklistItems`,
  `aiFlags`.
- The `open → closing → closed → audit-locked` lifecycle declared per ADR-031.
- Closed-period posting precondition: additive clause added to T1's
  `GLTransaction.post` rejecting postings whose `periodId` resolves to a
  `PeriodClose` in state `closed` or `audit-locked`.
- Reopen workflow: requires elevated role + audit-trailed reason; original close
  timestamp + actor preserved in `reopenedHistory`.
- AI close assistant service: detects open AP/AR, unreconciled bank lines,
  outstanding dunning notices, outstanding expense claims.
- Vue detail page: displays period info, lifecycle action buttons, task checklist
  with AI flags, trial-balance preview link, close reason/audit-lock audit trail.
- Manifest navigation entry (Bookkeeping > Period Close).

### Out of Scope

- **Year-end close** — opening-balance journal generation, retained-earnings
  rollover are T3.
- **Period roll-forward to a new fiscal year** — T3.
- **VAT period close** — T3.
- **Multi-step period close** (e.g., separate VAT close before GL close) — T3.

## Approach

One delta adding feature and spec requirements:

**`bookkeeping-period-close`** — declares the `PeriodClose` register, its
lifecycle, the closed-period precondition on `GLTransaction.post`, the reopen
workflow, the AI close assistant service, and the Vue navigation/detail page.
Each requirement is prefixed `REQ-PC-*` for traceability. Requirements follow
conduction-schema format (RFC 2119, GIVEN/WHEN/THEN scenarios).

## New Dependencies

- OpenRegister `x-openregister-lifecycle` (already available).
- AI integration: Claude API via existing ChatService (for close assistant).
- No new external packages beyond what Shillinq already pulls.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 new schema (`PeriodClose`);
  declares its lifecycle; additively augments T1's `GLTransaction.post`
  precondition list with the closed-period rejection clause.
- `src/Services/` — adds `PeriodCloseAssistantService` (detects incomplete tasks,
  flags via AI).
- `src/Components/` — adds `PeriodCloseDetail.vue` (detail page with lifecycle
  actions, checklist, AI flags).
- `src/manifest.json` — adds 1 navigation entry + 1 `type: detail` page entry.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle` preconditions
  (already stable).
- **T1 general ledger** — depends on `bookkeeping-general-ledger` having landed;
  the precondition augments T1's `GLTransaction.post`.
- **T2 trial balance** — depends on `bookkeeping-trial-balance` for the pre-close
  preview link.

## Risks

### Risk 1: Backdating prevention vs operator correction workflow

**Severity**: Medium
**Mitigation**: Once a period is closed, ALL postings dated within that period
are rejected. Operators must reopen the period (elevated role + audit-trailed
reason) to post a correction. This is industry-standard (matches Exact / AFAS /
Twinfield behaviour). The reopen workflow is declared in the spec.

### Risk 2: AI task flagging false positives

**Severity**: Medium
**Mitigation**: AI flags are surfaced as *warnings*, not blockers. Operators
review and mark as resolved manually. All flags are audit-trailed. A low-confidence
flag (e.g., "Consider reconciling bank account XYZ") does not prevent close.

### Risk 3: Close assistant latency for large datasets

**Severity**: Low
**Mitigation**: Close assistant runs asynchronously. Operators can proceed with
close while assistant compiles the flag list. Flags arrive within 2-5 seconds for
typical Dutch SMB datasets; a timeout at 10s surfaces a "partial results" message.

## Rollback Strategy

If spec is rejected: revert the commit; delete the change folder; no runtime impact.
If implementation fails: revert the implementing PR; registers are non-destructive;
`PeriodClose` records remain queryable but unreferenced.

## Open Questions

1. **Close assistant AI model** — Sonnet vs Opus tradeoff for latency/cost.
   Recommend Haiku for speed; escalate to Sonnet if accuracy issues surface.
2. **Default close cadence** — monthly vs quarterly. Recommend monthly; operator-
   configurable per administration.
3. **Mandatory checklist item types** — what closes count as *must verify before
   close*. Recommend: open AP invoices, open AR invoices. Unreconciled bank lines
   + dunning notices are *should verify*.
