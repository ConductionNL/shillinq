# Design — BCF VAT Compensation

## Context

BCF (Btw-compensatiefonds) is the Dutch fund that compensates
municipalities and other public bodies for non-recoverable VAT on
qualifying outputs. The claim is filed quarterly via DigiKoppeling
and aggregates compensable VAT across BBV-mapped postings.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change is **spec-only**.

## Goals

- Declare the `BcfClaim` register with a `draft → submitted →
  accepted → settled` lifecycle per ADR-031.
- Extend T3 `BbvAccountMapping` with `compensablePercentage`
  (default 100) so the BCF aggregation can weight per-account
  mixed-use shares.
- Declare the compensable-VAT aggregation as
  `x-openregister-aggregations` filtered by `bcfCompensable` flag.
- Declare the DigiKoppeling-BCF submission as an OR
  `ScheduledWorkflow` consuming `digikoppeling-bcf` per ADR-019.

## Non-Goals

- No app-local BCF state-machine service.
- No app-local DigiKoppeling HTTP client.
- No parallel "BCF accounts" table — flagging lives on the existing
  `BbvAccountMapping` register per ADR-022.

## Decisions

### D1 — `BcfClaim` lifecycle declarative

`draft → submitted → accepted → settled` declared via
`x-openregister-lifecycle`. `draft → submitted` requires the
arithmetic precondition (`totalCompensableAmount > 0` AND `quarter
is closed`) plus approval-workflow gate per ADR-022.

### D2 — Compensable flagging lives on `BbvAccountMapping`, not a new register

A new "BCF accounts" register would duplicate the per-administration
account-mapping store. Per ADR-022 (don't reimplement existing
abstractions), the `bcfCompensable: boolean` flag and the
`compensablePercentage: int (0-100)` field live on
`BbvAccountMapping`. The BCF aggregation joins through that flag.

**Alternative considered**: Author a `BcfAccount` register. Rejected
— exactly the parallel-link-table anti-pattern.

### D3 — DigiKoppeling submission via OpenConnector

Per ADR-019, the `digikoppeling-bcf` source is registered in
OpenConnector (separate change). shillinq declares an OR
`ScheduledWorkflow` (cron quarterly) that invokes the source. No
app-local HTTP client.

### D4 — Settlement transition via webhook

`accepted → settled` triggers on the Belastingdienst's actual
settlement payment, detected via the OpenConnector source's
webhook. The webhook writes back to `BcfClaim.state` via OR's
generic webhook handler — no shillinq glue.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| `BcfClaim` lifecycle | `x-openregister-lifecycle` (ADR-031) | Declared on schema |
| Compensable-VAT aggregation | `x-openregister-aggregations` (ADR-031) | Cross-schema projection over T1 GL with `BbvAccountMapping.bcfCompensable` filter |
| Approval gate on submit | OR approval-workflow (ADR-022) | Consumed via `requires` precondition |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| Quarterly submission | OR `ScheduledWorkflow` + n8n adapter (ADR-031) | Cron-driven; references `digikoppeling-bcf` |
| HTTP to DigiKoppeling | OpenConnector source (ADR-019) | Symbolic reference; source registration separate |
| Settlement webhook | OR generic webhook handler | No app-local glue |
| RBAC (bcf-administrator) | OR authorization (ADR-022) | Per-schema role |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 1 menu entry under `Overheid` with visibility predicate |

**Net new code in implementation**: 1 schema + 1 field extension on
`BbvAccountMapping` + 1 manifest entry + 1 `ScheduledWorkflow`. No
new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| `BcfClaim` state machine | Declarative (`x-openregister-lifecycle`) | Textbook fit |
| Compensable-VAT aggregation | Declarative (`x-openregister-aggregations`) | Standard projection-filter with weight join |
| Approval gate on submit | Declarative (`requires.approval-workflow`) | Standard precondition |
| Submission to DigiKoppeling | `ScheduledWorkflow` + OpenConnector source | ADR-031 §"orchestrate external systems" |
| Settlement transition | OR webhook handler (declarative routing) | No app-local glue |

No service class authored.

## Seed Data

No seed data ships with this change directly. The
`bcfCompensable` flag and `compensablePercentage` default come
from sibling `bookkeeping-bbv-compliance`'s `rgs-to-bbv-mapping.json`
seed; operator overrides per administration.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Mixed-use compensable percentage | Per-mapping `compensablePercentage` field (default 100); operator-editable; audited |
| Settlement webhook reliability | OpenConnector source's responsibility; missing webhooks fall back to operator-initiated `accepted → settled` |
| Pre-existing periods on first install | Forward-only by `claimQuarter ≥ install date` per `REQ-BCF-003` |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` adds 1 schema (additive).
2. `BbvAccountMapping` gains 2 fields (additive: `bcfCompensable`,
   `compensablePercentage` — already declared in sibling proposal).
3. `src/manifest.json` adds 1 navigation entry (additive,
   visibility-predicated).
4. The `ScheduledWorkflow` is registered for quarterly BCF
   submission.

Down-direction: revert the implementing PR, run the repair step in
down-direction. Existing claims remain queryable.

## Open Questions

1. **Settlement webhook shape** — confirmed with OpenConnector
   source registration during `opsx-ff`.
2. **Quarterly cadence boundary** — claim window must align with
   T2 period boundaries.
