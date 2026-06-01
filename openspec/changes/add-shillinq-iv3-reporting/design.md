# Design — IV3 Reporting

**status: pr-created**

## Context

IV3 (Informatie voor Derden) is the quarterly financial-statistics
filing every Dutch decentralised government MUST submit to CBS.
The report aggregates GL postings by BBV taakveld + iv3Bucket and
ships as an XML payload to CBS via DigiKoppeling.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change is **spec-only**.

## Goals

- Declare the `Iv3Export` surface as a single register with a
  lifecycle (per ADR-031).
- Declare the buckets aggregation as `x-openregister-aggregations`
  filtered by `BbvAccountMapping.iv3Bucket`.
- Declare the IV3 XML generation as an OR Mapping transformation,
  with the ADR-031 exception path documented for the conditional
  thin XML renderer.
- Declare the quarterly CBS submission as an OR `ScheduledWorkflow`
  consuming `cbs-iv3` per ADR-019.

## Non-Goals

- No app-local IV3 state-machine service.
- No app-local CBS HTTP client.
- No bespoke Vue beyond manifest-driven generic pages.

## Decisions

### D1 — `Iv3Export` lifecycle declarative

`draft → generated → submitted → accepted (or rejected)` declared
via `x-openregister-lifecycle`. Generation transition triggers the
mapping transformation; submission transition invokes the
`ScheduledWorkflow`.

### D2 — Buckets aggregation declarative

`Iv3Export.buckets` is a derived field via
`x-openregister-aggregations`, projecting sum-by-iv3Bucket over T1
`GLLine` rows filtered by `periodId` (Q1/Q2/Q3/Q4 boundaries from
T2 `bookkeeping-period-close`) and joining to
`BbvAccountMapping.iv3Bucket` from T3 `bookkeeping-bbv-compliance`.

### D3 — IV3 XML via Mapping (preferred) or thin renderer (exception)

The XML structure is a fixed CBS schema. Declarative-first: an OR
Mapping transformation specification maps the aggregated buckets
to the CBS XML shape, producing an attachment artefact.

**Exception path (per ADR-031)**: If the Mapping engine cannot
express mixed-content XML nodes (a known limitation of some JSON-
oriented mapping engines), a single-method PHP renderer
`Iv3XmlRenderer::render(Iv3Export $export): string` ships, ~30 LOC,
ADR-031 exception annotation referencing this section.

### D4 — CBS submission via OpenConnector

Per ADR-019, the `cbs-iv3` source is registered in OpenConnector
(separate change). shillinq declares an OR `ScheduledWorkflow`
(cron `0 0 1 */3 *` by default) that invokes the source. No app-
local HTTP client.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| `Iv3Export` lifecycle | `x-openregister-lifecycle` (ADR-031) | Declared on schema |
| Buckets aggregation | `x-openregister-aggregations` (ADR-031) | Cross-schema projection over T1 GL with BBV-mapping join |
| XML transformation | OR Mapping engine | Declarative-first; exception path for mixed-content |
| Quarterly submission | OR `ScheduledWorkflow` + n8n adapter (ADR-031) | Cron-driven; references `cbs-iv3` |
| HTTP to CBS | OpenConnector `cbs-iv3` source (ADR-019) | Symbolic reference; source registration separate |
| RBAC (bbv-controller) | OR authorization (ADR-022) | Per-schema role |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 1 menu entry under `Overheid` with visibility predicate |

**Net new code in implementation**: 1 schema + 1 mapping + 1
manifest entry + 1 `ScheduledWorkflow`. Possibly 1 thin XML
renderer (~30 LOC) if D3 exception path triggers.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| `Iv3Export` state machine | Declarative (`x-openregister-lifecycle`) | Textbook fit |
| Buckets aggregation | Declarative (`x-openregister-aggregations`) | Standard projection-join |
| IV3 XML generation | OR Mapping (preferred) or thin renderer (ADR-031 exception) | Resolved during `opsx-ff` |
| CBS submission | `ScheduledWorkflow` + OpenConnector source | ADR-031 §"orchestrate external systems" |

No service class authored beyond the conditional ~30 LOC XML
renderer.

## Seed Data

No seed data ships with this change directly. The IV3 buckets
catalogue is structurally derived from BBV (per sibling
`bookkeeping-bbv-compliance` seed `rgs-to-bbv-mapping.json`).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| CBS XML mixed-content nodes | ADR-031 exception path; thin renderer documented |
| CBS deadline alignment | Operator-configurable `ScheduledWorkflow` cron |
| BBV taakveld revision impacts IV3 buckets | Seed versioning in sibling `bbv-compliance`; IV3 spec is bucket-agnostic |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` adds 1 schema (additive).
2. The IV3 Mapping transformation is registered.
3. `src/manifest.json` adds 1 navigation entry (additive,
   visibility-predicated).
4. The `ScheduledWorkflow` is registered for quarterly CBS
   submission.

Down-direction: revert the implementing PR, run the repair step in
down-direction. Existing IV3 exports remain queryable.

## Open Questions

1. **Mapping engine vs renderer** — resolved during `opsx-ff`
   discovery.
2. **Monthly vs quarterly cadence** — operator-configurable.
