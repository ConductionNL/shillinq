# Proposal: add-shillinq-iv3-reporting

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + workflow declarations + OR Mapping transformation.
No PHP service classes (one conditional thin XML renderer permitted
under ADR-031 exception, ~30 LOC).

## Summary

Introduce **IV3 (Informatie voor Derden) quarterly export to CBS**
for Shillinq as a T3 capability per
`adr-001-bookkeeping-tier-roadmap.md`. This change declares the
`Iv3Export` register with `x-openregister-lifecycle` rules (per
ADR-031), declares the IV3 XML generation as an OR Mapping
transformation, declares the quarterly CBS submission as an OR
`ScheduledWorkflow` consuming the `cbs-iv3` OpenConnector source
(per ADR-019), and wires navigation into `src/manifest.json` (per
ADR-024). No PHP service classes for state machines or aggregation;
no app-local HTTP client.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T3 `bookkeeping-bbv-compliance` (taakveld + iv3Bucket
flags on every posting) and T2 `bookkeeping-period-close`
(quarter-end definition).

## Motivation

Every Dutch gemeente / provincie / waterschap MUST file IV3
quarterly to CBS — financial statistics aggregated by BBV taakveld
and IV3 bucket. Without IV3 export, a Shillinq-running municipality
cannot comply with the Financiële-verhoudingenwet.

The IV3 process is a textbook fit for declarative metadata: the
`Iv3Export` lifecycle (`draft → generated → submitted → accepted`)
is a clean state machine; the buckets aggregation is a sum-by-
iv3Bucket projection over posted `GLLine` rows; the CBS submission
is an OR `ScheduledWorkflow`.

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`Iv3Export`)
  to `lib/Settings/shillinq_register.json`, adds 1 OR Mapping
  declaration for the IV3 XML transformation, adds 1 manifest
  navigation entry (`Overheid > IV3-rapportages`) in
  `src/manifest.json`, declares the quarterly CBS `ScheduledWorkflow`.
- [ ] Project: openregister — no source changes; consumes existing OR
  abstractions (lifecycle, aggregations, mappings, scheduled
  workflow). Conditional thin PHP XML renderer permitted per
  ADR-031 exception if Mapping engine cannot express CBS schema.
- [ ] Project: openconnector — no source changes; references the
  `cbs-iv3` source symbolically. Source registration lands separately.

## Scope

### In Scope

- One new capability spec (`bookkeeping-iv3-reporting`).
- `Iv3Export` register with quarterly lifecycle.
- Buckets aggregation as derived field via
  `x-openregister-aggregations` filtered by `BbvAccountMapping.iv3Bucket`.
- IV3 XML generation via OR Mapping (transformation spec) →
  attachment artefact; ADR-031 exception path documented for the
  conditional thin XML renderer.
- Quarterly CBS submission as `ScheduledWorkflow` consuming
  `cbs-iv3`.
- Manifest navigation under `Overheid` (visibility filtered to
  municipal admin types).

### Out of Scope

- **BBV taakveld mapping** — owned by sibling
  `add-shillinq-bbv-compliance`.
- **BCF claim administration** — owned by sibling
  `add-shillinq-bcf-vat-compensation`.
- **Implementation code** — spec-only change.
- **CBS schema validation engine** — operator-managed via the
  OpenConnector source's response handling.

## Approach

One delta with ADDED Requirements under `REQ-IV3-*`.

## New Dependencies

None. Consumes T3 BBV compliance + T2 period-close + existing OR
abstractions + `cbs-iv3` OpenConnector source (registered separately).

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema with
  lifecycle; adds 1 OR Mapping declaration for the IV3 XML shape.
- `src/manifest.json` — adds 1 navigation entry with
  visibility-predicate scoped to municipal admins.
- Repair step registers the quarterly CBS `ScheduledWorkflow`.
- No new PHP services (conditional ~30 LOC XML renderer only if
  Mapping engine cannot express CBS schema; ADR-031 exception).

## Cross-Project Dependencies

- **OpenRegister** — relies on aggregations + mappings + scheduled
  workflow. If OR's Mapping engine cannot express the full CBS XML
  shape (mixed-content nodes), the gap is filed as an OR issue and
  the thin XML renderer ships under the ADR-031 exception path.
- **OpenConnector** — symbolic reference to `cbs-iv3`.

## Risks

### Risk 1: CBS XML schema mixed-content nodes

**Severity**: Medium
**Mitigation**: If OR's Mapping engine cannot express mixed-content
XML nodes, a single-method PHP renderer ships under ADR-031
exception (`Iv3XmlRenderer::render(Iv3Export $export): string`),
documented and reviewed.

### Risk 2: CBS deadline alignment

**Severity**: Low
**Mitigation**: `ScheduledWorkflow` cron defaults to quarter-start
(`0 0 1 */3 *`); operators reconfigure if CBS deadlines change.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the
change folder. After implementation: revert the PR, run the repair
step in down-direction. Registers are non-destructive.

## Open Questions

1. **IV3 monthly cadence** — Wet HOF revision may push monthly;
   `ScheduledWorkflow` cron is operator-configurable.
2. **Mapping engine vs PHP renderer for CBS XML** — resolved during
   `opsx-ff` discovery before implementation cycle.
