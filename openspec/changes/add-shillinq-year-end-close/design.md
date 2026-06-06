# Design — Year-End Close

**status: pr-created**

## Decisions

### D1 — Year-end close is the highest-stakes operation; declarative makes it auditable

Year-end close emits two journals (retained-earnings transfer in year
N, opening-balance journal in year N+1) and a dimensional rollover.
Each is a lifecycle action on `FiscalYear` (per REQ-YEC-003 / -004 /
-005). The audit chain is consumed from OR's audit-trail-immutable
(no app config), making every step queryable post-hoc.

**Alternative considered**: Author `YearEndCloseService` mirroring
Exact / AFAS style. Rejected per ADR-031 — the highest-stakes
operation is exactly where the declarative shape matters most: the
review surface is the schema metadata, not a multi-method PHP service.

### D2 — Reopen is a designed escape hatch, not a bypass

Re-opening a closed year is the operational escape hatch. Per
REQ-YEC-006, the `closed → reopened` transition is admin-only
(consuming OR's RBAC `admin` role per ADR-022), requires a non-empty
`reopenReason`, and emits two reversing `JournalEntry` records that
pair with the original closing + opening journals. The audit chain is
fully traceable; an auditor can reconstruct the close/reopen history
from the audit trail alone.

**Alternative considered**: Make year-end close irreversible with an
"emergency admin override" outside the lifecycle. Rejected — that
bypasses the audit chain and creates an audit blind spot. The
declared reopen path keeps everything visible.

### D3 — Closing emits T1 `JournalEntry` records, not a new entity

The retained-earnings transfer and the opening-balance journal are
T1 `JournalEntry` records (manual sub-type for the close, reversing
sub-type for the reopen). No new entity is needed; the close is a
composition of existing T1 primitives.

### D4 — Dimensional rollover fires via CloudEvents

When a `FiscalYear` transitions `closing → closed`, an OR CloudEvent
fires per dimension register (CostCenter / KostenDrager / Project /
custom). The dimension register's subscriber decides whether to carry
the dimension forward (active dimensions) or archive it (lifecycle
state `archived`). This keeps the rollover logic in the dimension
registers themselves, not in a year-end-close service.

### D5 — `yearNumber` uniqueness per `administrationId`

Multi-tenant installs serve multiple administrations; each has its
own fiscal-year sequence. `FiscalYear` declares uniqueness on
(`yearNumber`, `administrationId`) so the same year number can exist
across administrations without conflict.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Fiscal-year storage | New `FiscalYear` register | Declared per ADR-024; lifecycle drives the close |
| Fiscal-year state machine | `x-openregister-lifecycle` (ADR-031) | Declarative — no PHP state machine |
| Retained-earnings transfer journal | T1 `JournalEntry` (manual sub-type) | Emitted by `FiscalYear.open → closing` action; consumes T1 |
| Opening-balance journal in next year | T1 `JournalEntry` (manual sub-type) | Emitted by `FiscalYear.closing → closed` action; consumes T1 |
| Dimensional rollover at year-end | OR CloudEvents | Lifecycle action emits CloudEvents per dimension register |
| Year-end re-open RBAC guard | OR authorization `admin` role | Referenced from `x-openregister-lifecycle` per ADR-022 |
| Reopen-reason required precondition | `x-openregister-lifecycle.requires` | Declarative; same path as T1 balance precondition |
| Reverse-and-reopen audit chain | T1 `JournalEntry` (reversing sub-type) | Emitted by `FiscalYear.closed → reopened` action |
| All-periods-closed precondition | T3 `bookkeeping-period-close` | Lifecycle precondition gates `open → closing` |
| Audit trail | OR audit-trail-immutable | Consumed automatically |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4) | Adds 1 menu entry + 1 index/detail page pair (role-gated actions) |

**Net new code in implementation cycle**: 1 schema declaration + 1
manifest entry pair. No new PHP service.

## Seed Data

None. `FiscalYear` records are auto-created (one per
administration-year); no template ships in this change.
