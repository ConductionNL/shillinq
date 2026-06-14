# Proposal: add-shillinq-bcf-vat-compensation

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + workflow declarations. No PHP
service classes are authored.

## Summary

Introduce **BCF (Btw-compensatiefonds) claim administration** for
Shillinq as a T3 capability per
`adr-001-bookkeeping-tier-roadmap.md`. This change declares the
`BcfClaim` register with `x-openregister-lifecycle` rules (per
ADR-031), extends T3 `BbvAccountMapping` with a
`compensablePercentage` field, declares the BCF aggregation as
`x-openregister-aggregations` filtered by `bcfCompensable`, declares
the quarterly DigiKoppeling-BCF submission as an OR
`ScheduledWorkflow` consuming the `digikoppeling-bcf` OpenConnector
source (per ADR-019), and wires navigation into `src/manifest.json`
(per ADR-024). No PHP service classes, no parallel "BCF accounts"
table.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T3 `bookkeeping-vat-btw-filing` (compensable VAT
identified per rate) and T3 `bookkeeping-bbv-compliance`
(`compensablePercentage` lives on `BbvAccountMapping`).

## Motivation

Most Dutch municipalities claim back recoverable VAT through the
btw-compensatiefonds (BCF) — the alternative to letting non-
recoverable VAT distort their budget. Without BCF claim
administration, a Shillinq-running municipality cannot recover the
~€3M/year typically owed back per medium-sized gemeente.

The BCF claim flow is a textbook fit for declarative metadata: the
`BcfClaim` lifecycle (`draft → submitted → accepted → settled`) is a
clean state machine; the compensable-VAT aggregation is a sum
projection over posted `GLLine` rows filtered by the
`BbvAccountMapping.bcfCompensable` flag; the DigiKoppeling
submission is an OR `ScheduledWorkflow`.

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`BcfClaim`)
  to `lib/Settings/shillinq_register.json`, extends
  `BbvAccountMapping` with `compensablePercentage`, adds 1 manifest
  navigation entry (`Overheid > BCF-claims`) in `src/manifest.json`,
  declares the quarterly DigiKoppeling-BCF `ScheduledWorkflow`.
- [ ] Project: openregister — no source changes; consumes existing OR
  abstractions.
- [ ] Project: openconnector — no source changes; references the
  `digikoppeling-bcf` source symbolically.

## Scope

### In Scope

- One new capability spec (`bookkeeping-bcf-vat-compensation`).
- `BcfClaim` register with quarterly lifecycle and arithmetic
  precondition on submit.
- Extension to `BbvAccountMapping` (from sibling
  `add-shillinq-bbv-compliance`): `compensablePercentage` field
  (default 100 for fully-compensable, lower for mixed-use).
- Compensable-VAT aggregation as derived field via
  `x-openregister-aggregations` filtered by `bcfCompensable` +
  weighted by `compensablePercentage`.
- Quarterly DigiKoppeling-BCF submission as `ScheduledWorkflow`
  consuming `digikoppeling-bcf`.
- Manifest navigation under `Overheid` (visibility filtered to
  municipal admin types).

### Out of Scope

- **BBV taakveld mapping** — owned by sibling
  `add-shillinq-bbv-compliance` (this spec extends one field).
- **VAT/BTW filing** — owned by sibling
  `add-shillinq-vat-btw-filing`.
- **Implementation code** — spec-only change.
- **Mixed-use account splits** beyond the
  `compensablePercentage` field — operator workflow, not a schema
  concern.

## Approach

One delta with ADDED Requirements under `REQ-BCF-*`. A second delta
extends `BbvAccountMapping` (per sibling spec) with the
`compensablePercentage` field — declared in this spec, applied via
the implementing cycle's repair step.

## New Dependencies

None. Consumes T3 VAT-filing + T3 BBV-compliance + existing OR
abstractions + `digikoppeling-bcf` OpenConnector source (registered
separately).

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema with
  lifecycle; extends `BbvAccountMapping` with one field.
- `src/manifest.json` — adds 1 navigation entry under `Overheid`
  with visibility predicate.
- Repair step registers the quarterly BCF `ScheduledWorkflow`.
- No new PHP services.

## Cross-Project Dependencies

- **OpenRegister** — relies on aggregations + lifecycle preconditions.
  Standard shape.
- **OpenConnector** — symbolic reference to `digikoppeling-bcf`.

## Risks

### Risk 1: Mixed-use account compensable percentage

**Severity**: Low
**Mitigation**: `compensablePercentage` defaults to 100 for fully-
compensable mapped accounts; operator can lower for mixed-use
(public/private split). Audit-trail records every change.

### Risk 2: Claim arithmetic edge cases

**Severity**: Low
**Mitigation**: `REQ-BCF-006` declares the submit precondition
(`totalCompensableAmount > 0` AND `quarter is closed`); arithmetic
correctness is unit-tested.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the
change folder. After implementation: revert the PR, run the repair
step in down-direction. Registers are non-destructive.

## Open Questions

1. **Settlement timing** — `accepted → settled` transition triggers
   on the Belastingdienst's actual settlement payment (typically
   30-60 days post-submit). Detected via the OpenConnector
   `digikoppeling-bcf` source webhook; confirm during spec review.
2. **Pre-existing periods on first install** — claim window is
   forward-only by `claimQuarter ≥ install date` per `REQ-BCF-003`.
