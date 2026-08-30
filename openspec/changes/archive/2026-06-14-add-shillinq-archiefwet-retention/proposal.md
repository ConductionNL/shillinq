# Proposal: add-shillinq-archiefwet-retention

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + per-schema retention rule references +
Selectielijst seed data. No PHP service classes — retention
enforcement is OpenRegister's responsibility per ADR-022.

## Summary

Introduce **Archiefwet 1995 + Selectielijst Gemeenten 2020
retention** for Shillinq as a T3 capability per
`adr-001-bookkeeping-tier-roadmap.md`. This change declares the
`RetentionRule` register (per ADR-031), maps every existing
Shillinq schema (T1 + T2 + the other T3 schemas) to a Selectielijst
code via `x-openregister-lifecycle.retention.rule`, wires the
Bewaartermijnen navigation into `src/manifest.json` (per ADR-024),
and ships `selectielijst-gemeenten-2020.json` seed. Retention
enforcement (purge, archive, anonymise on expiry) is consumed from
OpenRegister's `x-openregister-lifecycle.retention` abstraction
per ADR-022 — shillinq does NOT implement a parallel retention
sweep / job / service.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T1 `bookkeeping-general-ledger` (and every T1/T2/T3
schema cited in REQ-ARC-003's mapping table) and consumes
OpenRegister's lifecycle retention abstraction per ADR-022.

## Motivation

Every Dutch operator runs under the Archiefwet 1995 retention
regime. Municipalities, provincies, waterschappen additionally
follow the actuele Selectielijst Gemeenten 2020 (and sector-
specific selectielijsten). Without retention enforcement, a
Shillinq-running operator is non-compliant; the operator's
archiefverordening cannot be met.

The architecture insight is that retention is **OpenRegister's
abstraction, not shillinq's**: declare the rule on every schema
via `x-openregister-lifecycle.retention.rule`, and OR enforces.

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema
  (`RetentionRule`) to `lib/Settings/shillinq_register.json`, adds
  `x-openregister-lifecycle.retention.rule` references on every
  existing schema (T1 + T2 + the other 9 T3 schemas), adds 1
  manifest navigation entry (`Administratie > Bewaartermijnen`),
  ships `lib/Settings/seeds/selectielijst-gemeenten-2020.json`.
- [ ] Project: openregister — depends on `x-openregister-lifecycle.retention`
  enforcement engine being stable. Gap (e.g. "anonymise PII-bearing
  fields but keep the rest") filed as an OR issue.

## Scope

### In Scope

- One new capability spec (`bookkeeping-archiefwet-retention`).
- `RetentionRule` register seeded from
  `selectielijst-gemeenten-2020.json` with fields
  (`selectielijstCode`, `description`, `retentionYears` OR
  `retentionTrigger` for relative, `disposition`, `legalBasis`).
- Per-schema retention rule reference via
  `x-openregister-lifecycle.retention.rule` on EVERY existing
  shillinq schema.
- `daysUntilRetention` derived field on every retention-bound schema
  via `x-openregister-calculations`.
- Per-administration override of seeded rules allowed (operator's
  local archiefverordening prevails).
- Manifest navigation under `Administratie` (visible for all admin
  types).
- Audit trail preserved on records anonymised by retention
  (immutable hashes survive per OR contract).

### Out of Scope

- **OR retention engine itself** — consumed, not authored.
- **Implementation code** — spec-only change.
- **Industry-specific selectielijsten** (housing corp, healthcare)
  — out of scope (roadmap).

## Approach

One delta with ADDED Requirements under `REQ-ARC-*`. The
schema-to-rule mapping table lives in `REQ-ARC-003` and is the
spec's compliance backbone.

## New Dependencies

None. Consumes T1/T2/T3 schemas + OR retention abstraction.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema; adds
  `x-openregister-lifecycle.retention.rule` references on every
  existing shillinq schema (T1 + T2 + other T3).
- `lib/Settings/seeds/selectielijst-gemeenten-2020.json` — new
  file (~30 rules), SPDX header,
  `_meta.source: "Archiefwet 1995 + Selectielijst-2020 publicatie"`.
- `src/manifest.json` — adds 1 navigation entry under
  `Administratie`.
- Repair step extension to import the Selectielijst seed.
- No new PHP services.

## Cross-Project Dependencies

- **OpenRegister** — relies on `x-openregister-lifecycle.retention`
  enforcement engine: purge / archive / anonymise on expiry, while
  preserving audit-trail-immutable hashes. If the engine is missing
  a feature (e.g. selective anonymisation), gap is filed as an OR
  issue and the relevant T3 requirement annotates the shortfall.

## Risks

### Risk 1: OR retention engine feature gaps

**Severity**: Medium
**Mitigation**: Spec consumes the abstraction; OR issues filed for
any gap (especially "anonymise PII while keeping financial
hashes"). The spec does NOT fall back to shillinq-authored
retention code — that would defeat the architecture.

### Risk 2: Operator override conflicts with statutory minima

**Severity**: Low
**Mitigation**: Per-administration override allowed only ABOVE the
statutory minimum (operator may extend retention, never shorten
below the Archiefwet floor). Enforced as a `RetentionRule`
validation rule per `REQ-ARC-004`.

### Risk 3: Audit-trail preservation across anonymisation

**Severity**: Low
**Mitigation**: OR audit-trail-immutable hashes survive
anonymisation by contract. Documented as the expectation in
`REQ-ARC-006`; OR conformance gated at the implementing PR's
integration test.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the
change folder. After implementation: revert the PR, run the repair
step in down-direction. Existing rules + per-schema references
remain queryable (retention enforcement re-engages on next install).

## Open Questions

1. **Selective anonymisation feature** — OR gap; file issue if
   confirmed missing during `opsx-ff`.
2. **Industry-specific selectielijsten** — out of scope; roadmap.
