# Design — Bank Reconciliation

## Context

Bank reconciliation is the daily-operations loop connecting
external reality (the bank statement) to the internal ledger. Per
ADR-031, matching is declarative: predicate-based `MatchingRule`
records consumed by an aggregation that emits candidate matches.
Per ADR-022, the original statement file is archived via docudesk,
not in an app-local store.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire bank-reconciliation surface as **declarative
  metadata** — schemas + lifecycle + matching-rule predicates
  consumed by aggregation + manifest entries — per ADR-031.
- Consume docudesk for statement-file archival — per ADR-022.
  Zero file storage in shillinq.
- Make the spec a **competent-bookkeeper readable contract** —
  Dutch SMB daily-reconciliation flow recognisable end-to-end
  (statement import, rule-based candidate matching, operator
  confirmation, suspense-account routing, audit-lock).
- Keep the parser path shape-neutral so the spec ships regardless
  of whether OR's calculation extension supports CAMT.053 /
  MT940 primitives yet.

## Non-Goals

- No PHP matching service, no `MatcherService.php`.
- No PHP statement-parser service beyond the ADR-031 exception
  guard if needed.
- No PSD2 live-feed bank connectors — T4.
- No multi-currency FX revaluation — T5.
- No AP / AR sub-ledger clearing logic — owned by AP / AR specs.

## Decisions

### D1 — Bank reconciliation is two registers + a matching-rule schema

T2 declares `BankStatement` (header per upload) and
`BankStatementLine` (per transaction) registers. Matching against
AP/AR is rule-driven: a `MatchingRule` schema declares predicates
consumed by an `x-openregister-aggregations` query that emits
candidate `ReconciliationMatch` records.

**Alternative considered**: A monolithic statement register with
embedded line records. Rejected — line-level operator confirmation
+ aggregation across lines needs first-class records.

### D2 — Operator confirms / rejects candidate matches

The aggregation emits candidates with confidence scores; the
operator confirms or rejects through the standard register UI.
Confirmed matches trigger lifecycle transitions on the matched AP /
AR invoice via the depends-on chain.

**Alternative considered**: Auto-confirm above a confidence
threshold. Rejected for v1 — bookkeeper trust is built first; auto-
confirm is a future enhancement once rule packs are mature.

### D3 — Unmatched lines route to a designated suspense account

Unmatched lines post against a designated suspense account
(operator-configured per administration). Two paths for designation:
schema flag on T1's `Account` (`isSuspenseAccount: true` additive
boolean) OR an administration setting. Resolved during `opsx-ff`
discovery; spec shape-neutral.

**Resolution (opsx-apply 2026-06-08):** schema-flag path chosen. T1's
`Account` schema gains an additive `isSuspenseAccount: boolean` (default
`false`) via the `lib/Settings/register.d/add-shillinq-bank-reconciliation.json`
overlay fragment per ADR-037; the monolith `shillinq_register.json` is
not edited. Rationale: (a) symmetry with the pre-existing
`isClosingAccount` flag on the same schema (T1 closing-account pattern,
REQ-CoA-009); (b) no new administration-settings surface to plumb
through Vue + persisted config; (c) discoverable in the standard Account
register UI; (d) one-flag-per-account allows future per-bank-account
designation without a schema change. The materialisation of the
suspense-clearing `GLTransaction` is declarative per the same T1
REQ-JE-007 pattern; no PHP suspense-routing service ships.

### D4 — Reconciliation workflow is a lifecycle on `BankStatement`

`BankStatement` declares
`imported → in-progress → reconciled → audit-locked`. The
`audit-locked` state is reached when the period close audit-locks
the period; statement transitions automatically.

### D5 — Parser path is shape-neutral

Parsing CAMT.053 (ISO 20022 XML) and MT940 (legacy SWIFT text) is
either: (a) `x-openregister-calculations` if OR's calculation
extension supports structured-text parsing primitives, or (b) a
single-method `OCA\Shillinq\Lifecycle\StatementParser::parse(...)`
per ADR-031 exception. Resolved during `opsx-ff` discovery.

### D6 — Duplicate-import constraint is declarative

Uniqueness on file checksum + period overlap; declared as a
lifecycle precondition on `BankStatement.import`. No PHP duplicate-
detection service.

### D7 — Statement file archived via docudesk

The original uploaded file (XML / MT940 text / CSV) is archived via
docudesk per `bookkeeping-document-attachment-integration`.
`BankStatement.sourceDocumentUri` carries the URI.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Bank statement parsing (CAMT.053 / MT940) | OR `x-openregister-calculations` (if extension supports XML / structured-text); else single-method `OCA\Shillinq\Lifecycle\StatementParser` per ADR-031 exception | Parser path resolved during discovery; spec shape-neutral |
| Bank matching rules | OR `x-openregister-aggregations` consuming declarative match predicates | Rule predicates declared as schema metadata on `MatchingRule`; aggregation emits candidate matches |
| Suspense account routing | T1 `Account` register reference | A designated account flagged `isSuspenseAccount: true` (additive boolean) OR administration setting; resolved in discovery |
| Reconciliation lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `BankStatement` |
| Duplicate-import precondition | OR `x-openregister-lifecycle` precondition | Declarative uniqueness on file checksum + period overlap |
| Statement file archival | T2 `bookkeeping-document-attachment-integration` | `BankStatement.sourceDocumentUri` consumes the contract |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions |
| AP / AR clearing | AP / AR sub-ledger specs consume `ReconciliationMatch` confirmation | Operator confirmation triggers AP / AR lifecycle transition |
| Manifest navigation | T1 manifest pattern | 2 entries (Bank Reconciliation, Matching Rules) + their pages |

**Net new code in implementation cycle**: 4 schema declarations + 1
lifecycle block + 1 aggregation + 2 manifest entry pairs + 0–1
parser file (sunset path). At most 1 single-method PHP guard
(`StatementParser`) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Bank statement import (CAMT.053 / MT940 parsing) | Declarative if engine supports structured-text parsing extension; else single-method `StatementParser` per ADR-031 exception | Resolution in discovery; spec shape-neutral |
| Bank matching rule evaluation | Declarative (`x-openregister-aggregations` with predicate schema) | Pure data join |
| Bank reconciliation lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Suspense account routing | Declarative — schema flag or administration setting + lifecycle action on unmatched line | No service code |
| Duplicate-import detection | Declarative (lifecycle precondition on file checksum + period overlap) | Engine evaluates |
| Statement file archival | FK to docudesk by URI | ADR-022 |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `StatementParser`).

## Seed Data

None. Operators author matching rules on first use; rule template
packs are a roadmap item.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| CAMT.053 / MT940 format drift across Dutch banks | Battle-tested OR parsing extension if available; per-bank quirks handled through matching-rule customisation rather than parser changes |
| Parsing extension not yet shipped on OR | Single-method `StatementParser` per ADR-031 exception; removed when OR extension lands |
| Duplicate import on retried bank webhook | Declarative uniqueness on file checksum + period overlap; idempotent re-import path |
| Suspense account designation: schema flag vs administration setting | Resolved during discovery; spec shape-neutral; either path is declarative |
| Matching aggregation slow with many open AP / AR invoices | Pre-aggregated cache via OR's aggregation extension if performance trips; per-spec optimisation in implementing cycle |
| Auto-confirm above confidence threshold tempting but risky | Explicitly out of scope for v1; future enhancement once rule packs are mature |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the 4 new
   schemas (additive — no existing schema changes except possibly
   T1 `Account.isSuspenseAccount` additive boolean per suspense
   designation decision).
2. `src/manifest.json` is patched with 2 new menu entries + their
   pages (additive).
3. If OR's calculation extension does not yet support CAMT.053 /
   MT940 parsing, `lib/Lifecycle/StatementParser.php` ships
   (single method, ~50 LOC, ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; statements + matches remain queryable but
unreferenced.

## Open Questions

1. **Parser path** — RESOLVED (opsx-apply 2026-06-08): ADR-031 exception
   path taken. OR's calculation extension does not yet support
   CAMT.053 / MT940 structured-text parsing primitives nor a
   cross-schema `requires:` aggregation guard for the
   "no unmatched lines remain" precondition, so the single-method
   `OCA\Shillinq\Lifecycle\StatementParser` (parse + allLinesResolved)
   ships, ~305 LOC. Sunset when OR's calculation extension lands.
2. **Suspense account designation** — RESOLVED (opsx-apply 2026-06-08):
   schema-flag path. `Account.isSuspenseAccount: boolean` ships as an
   additive overlay in
   `lib/Settings/register.d/add-shillinq-bank-reconciliation.json`. See
   D3 above for rationale.
3. **Matching-rule seed packs** — current decision: no T2 seeds;
   operators author rules; rule-template packs are roadmap.
4. **Auto-confirm above confidence threshold** — explicitly out of
   v1; future enhancement once rule packs are mature. The
   `MatchingRule.autoConfirm` boolean exists for individual rules but
   no global confidence-threshold gate ships in T2.
