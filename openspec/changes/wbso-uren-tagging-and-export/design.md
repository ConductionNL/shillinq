# Design — WBSO Hours Tagging & RVO Export

## Context

Dutch WBSO subsidy administration demands hourly time entries tagged
with official project codes (SO, TWO, SMART) and activity categories
(A-codes for allowed R&D, B-codes for restricted). RVO (Dutch
government subsidy authority) requires audit-ready export in specific
formats. Shillinq's time-tracking layer (`TimeEntry` per
cost-allocation spec) is the perfect anchor; this capability bridges
the gap from billable hours to WBSO compliance.

status: pr-created

The change is **spec-only** (no PHP business logic). Implementation
is declarative — schemas + lifecycle + aggregations + manifest entries
— per ADR-031. PR opened for review.

The change was **spec-only**. Implementation landed through
`opsx-apply` and the standard Hydra pipeline; this doc explains *why*
the shape is what it is.

## Goals

- Express the entire WBSO tagging + export surface as **declarative
  metadata** — schemas + lifecycle + aggregations + manifest entries
  — per ADR-031.
- Consume OR's lifecycle + aggregations abstractions — per ADR-022.
  Zero parallel WBSO tagging table.
- Make the spec a **Dutch WBSO-auditor readable contract** —
  WBSO subsidy flow recognisable end-to-end (project setup, time
  entry, auto-tag, aggregation, export, RVO submission).
- Declare the RVO export format **as schema** — CSV/PDF/XML shapes
  pre-declared so T4 can attach outbound emission additively.
- Support administration-configurable activity codes per subsidy
  agreement, not hardcoded.

## Non-Goals

- No PHP WBSO export service, no `WBSOExportService.php`.
- No RVO portal API integration — manual upload only.
- No multi-year portfolio tracking — single fiscal year only.
- No AI-driven activity-code classification — manual assignment.

## Decisions

### D1 — WBSO tags are first-class registers, not string enums

`WBSOTag` and `WBSOActivityCode` are full registers in
`shillinq_register.json` so operators can administer, audit, and
version them. Tags are NOT hardcoded in the app; they are
data-driven per RVO directive updates.

### D2 — Auto-tagging fires on TimeEntry lifecycle via aggregation

When a `TimeEntry` is created/updated, an `x-openregister-aggregations`
precondition checks if the parent `Project` has WBSO metadata
(project code + activity code). If both are present, the entry
auto-inherits the tags. If either is missing, the entry enters an
`untagged` state and the operator receives a warning; manual
assignment required. No silent misclassification.

### D3 — TimeEntry lifecycle extends to tag-aware states

`TimeEntry` states evolve: `draft → submitted → tagged → approvalPending → approved`.
The `submitted → tagged` transition is automatic if auto-tagging
succeeds; if it fails (missing project metadata), the entry stalls
in `submitted` state pending operator action per D2.

### D4 — WBSOActivityCode is administration-configurable

Baseline A-codes (A001 = R&D programming, A002 = R&D testing,
A003 = documentation, etc.) and B-codes (B001 = non-eligible) ship
with the schema. Administrations MAY define custom codes per their
subsidy agreement. Custom codes FK to the baseline via
`parentActivityCode`.

### D5 — Export is a lifecycle workflow, not a batch job

`WBSOExportLog` has states: `draft → generated → validated →
submitted`. The operator selects date range, format, and filters;
the export materialises a `WBSOExportLog` record with a lifecycle.
RVO validation (checksum, activity-code presence) fires on
`generated → validated` transition. Manual submission to RVO
portal, or (future) API integration once RVO credentials are
stored.

### D6 — RVO export format declared as schema, not computed

T2 declares the CSV/PDF/XML field shapes (columns, row structure,
totals format) so T4 can attach the outbound computation
additively. T2 does not compute / emit the files — that ships with
T4.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| WBSO tag lifecycle | OR `x-openregister-lifecycle` | Lifecycle on `WBSOTag` (active → archived); schema is pure data |
| Auto-tagging trigger | OR `x-openregister-aggregations` | Aggregation on `TimeEntry.create/update`: SUM(Project.wbsoTag) where projectId matches; if present, auto-assign to entry |
| Activity code classification | Custom enum (baseline + admin override) | `WBSOActivityCode` register with baseline A/B codes; `parentActivityCode` FK for custom variants |
| Export workflow | OR `x-openregister-lifecycle` | Lifecycle on `WBSOExportLog` (draft → generated → validated → submitted) |
| RVO validation rules | Declarative aggregations | Precondition on `generated → validated`: all entries MUST have activity code; checksum validation via aggregation formula |
| Format specification | Schema field shape | CSV/PDF/XML field metadata declared on `WBSOExport` schema; not computed in T2 |
| TimeEntry tagging | T2 `billable-categories-and-tags` | `TimeEntry` FK to `WBSOTag` + `WBSOActivityCode` per auto-tag result |
| Project WBSO metadata | T2 `billable-categories-and-tags` | Project → `WBSOTag` + activity code via category mapping |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on all lifecycle transitions (tag assignment, export generation, validation) |
| Manifest navigation | T1 manifest pattern | 2 entries (WBSO Tags, WBSO Export Dashboard) + their pages |

## Seed Data

Baseline WBSO codes and activity codes (Dutch SMB context):

### WBSOTag (baseline, non-exhaustive)

| wbsoCode | displayName | description | lifecycleState |
|---|---|---|---|
| SO | Stand-alone Project | Solo R&D project funded by WBSO subsidy | active |
| TWO | TechnoWise Open | Open-innovation collaborative project | active |
| SMART | SME Collaboration | SME + university collaboration (SMART) | active |

### WBSOActivityCode (baseline, non-exhaustive)

| code | description | category | allowed | lifecycleState |
|---|---|---|---|---|
| A001 | Software development for R&D | Research & Development | true | active |
| A002 | Software testing & QA for R&D | Research & Development | true | active |
| A003 | Technical documentation | Research & Development | true | active |
| A004 | Project management (R&D context) | Research & Development | true | active |
| A005 | Patent filing & IP research | Research & Development | true | active |
| B001 | Project overhead (not eligible) | Support / Non-eligible | false | active |

### WBSOExportLog (example after first export)

| exportId | period (start–end) | format | recordCount | status | generatedAt | validatedAt | submittedAt | fileUri |
|---|---|---|---|---|---|---|---|---|
| EXP-2026-Q1-001 | 2026-01-01 – 2026-03-31 | CSV | 247 | submitted | 2026-04-10T09:15:00Z | 2026-04-10T09:20:00Z | 2026-04-10T10:30:00Z | s3://shillinq-exports/wbso-2026-q1.csv |

## Architectural Alignment

- **ADR-031 (Declarative Business Logic)**: WBSO tagging is pure
  schema + aggregations; no PHP business logic.
- **ADR-022 (Consume OR Abstractions)**: Lifecycle + aggregations
  consumed from OR; no app-local workflow engine.
- **ADR-024 (Register Declarations)**: `WBSOTag` and
  `WBSOActivityCode` are full registers, not config tables.
- **ADR-030 (JourneyDoc)**: WBSO subsidy admin flow documented as
  user journey (project setup → time entry → tagging → export →
  RVO submission).

## Migration Path

For existing Shillinq deployments with `TimeEntry` records:
1. Operator manually assigns WBSO tags / activity codes in bulk via
   the WBSO Tags dashboard (filter by project, batch-assign).
2. New entries auto-tag on creation if project metadata is present.
3. No breaking changes to the `TimeEntry` schema; WBSO FK is
   optional (null allowed for non-subsidized entries).

## Rollback Path

If WBSO requirements change mid-fiscal-year (e.g., RVO directive
update), rollback is dataless:
1. Revert the spec commit; registers remain (no destructive changes).
2. Operators can re-export with the new spec once a patch lands.
3. Audit trail preserves the old export records and their validation
   status.
