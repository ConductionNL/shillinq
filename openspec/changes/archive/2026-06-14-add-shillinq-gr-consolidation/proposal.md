# Proposal: add-shillinq-gr-consolidation

`kind: config` per ADR-032 — the centre of mass is two new
declarative registers (`GRDeelnemer`, `GRVerdeelsleutel`) + an
`eliminationFlag` field on `GLLine` + declarative aggregations for
consolidation and per-deelnemer doorbelasting. No PHP service
classes are authored.

## Summary

Introduce the **gemeenschappelijke regeling (GR) consolidation**
capability for Shillinq as one slice of the Tier 4-specialized
rollout per `adr-001-bookkeeping-tier-roadmap.md`. A GR is a
separate juridical entity with its own jaarrekening, funded by
deelnemers via a `verdeelsleutel`. This change declares two
registers (`GRDeelnemer` for member identification + quotum-aandeel,
`GRVerdeelsleutel` for per-cost-cluster apportionment rules) and an
`eliminationFlag` field on `GLLine` so inter-GR transactions can be
excluded from consolidated views. The GR's own jaarrekening and the
per-deelnemer doorbelasting are both declarative aggregations on
the GL data. The doorbelasting MUST materialise as a balanced
`GLTransaction` in deelnemer-administraties that themselves run
shillinq.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-bbv-compliance`](../../specs/bookkeeping-bbv-compliance/spec.md)
  — the BBV-base register that GRs report under.
- [`bookkeeping-financial-statements`](../../specs/bookkeeping-financial-statements/spec.md)
  — supplies the standard jaarrekening rollup that the
  consolidated GR view filters via the `eliminationFlag`.

## Motivation

A gemeenschappelijke regeling is a juridical fact: deelnemers
delegate a public task to a separate entity and fund it via a
quotum-verdeling. Without dedicated consolidation primitives,
inter-GR boekingen leak into consolidated rollups and per-deelnemer
doorbelastingen require either hand-spreadsheets or a separate
product. ADR-031 lets us express both surfaces as declarative
aggregations over the existing GL data — no PHP consolidation
service — provided the GR is represented as a real shillinq
administration with deelnemer-FK records.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 2 schemas (`GRDeelnemer`,
  `GRVerdeelsleutel`), adds `eliminationFlag: boolean` field on
  `GLLine`, extends `src/manifest.json` with 1 navigation entry
  behind `featureFlags.gov-gr`, three sub-pages (Deelnemers,
  Verdeelsleutels, Consolidated view)
- [ ] Project: openregister — no source changes; consumes
  `x-openregister-aggregations` and the period-close lifecycle
  hook from T3 `bookkeeping-period-close`

## Scope

### In Scope

- One new capability spec (`bookkeeping-gr-consolidation`) — see
  the `specs/` folder.
- `GRDeelnemer` register identifying each deelnemer with
  `deelnemerType`, `deelnemerNaam`, optional `administrationId` FK,
  `aandeel` (0 ≤ x ≤ 1), `actief` boolean.
- `GRVerdeelsleutel` register with `sleutelNaam`,
  `costClusterAccountNumbers`, `verdelingsType` enum
  (vast-percentage / inwoner-aantal / gewogen-oppervlak /
  custom-formula), `parameters` JSON.
- `eliminationFlag: boolean` field on `GLLine` (default `false`).
- Declarative aggregations: GR own jaarrekening (filtered with
  `WHERE eliminationFlag = false`) + per-deelnemer doorbelasting
  per applicable `GRVerdeelsleutel`.
- Per-deelnemer doorbelasting materialises a balanced
  `GLTransaction` in the deelnemer-administratie when
  `administrationId` is set (triggered by the GR period-close
  lifecycle).
- Manifest navigation entry (Bookkeeping > Gemeenschappelijke
  regeling) behind `featureFlags.gov-gr` with sub-pages for
  Deelnemers, Verdeelsleutels, and the Consolidated view.

### Out of Scope

- **Implementation code** — spec-only change.
- **Multi-administration global consolidation** — T5
  cross-cutting, future change family.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-gr-consolidation`). Each requirement is prefixed
`REQ-GRC-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 schemas
  (`GRDeelnemer`, `GRVerdeelsleutel`); adds `eliminationFlag`
  field on `GLLine`.
- `src/manifest.json` — adds 1 navigation entry + 3 sub-pages
  behind `featureFlags.gov-gr`.
- Cross-administration doorbelasting materialisation — bound to
  the T3 `bookkeeping-period-close` lifecycle trigger; no new
  cron, no app-local scheduler.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-aggregations` with
  filter clauses, lifecycle triggers from T3 period-close.

## Risks

### Risk 1: Cross-administration doorbelasting materialisation requires write access into the deelnemer-administratie

**Severity**: Medium
**Mitigation**: The materialisation only runs when the deelnemer's
`administrationId` is set + the operator confirms via the GR's
period-close approval gate. Per ADR-022, the cross-admin write is
audited in both the GR's and the deelnemer's audit-trail.

### Risk 2: Quotum-aandeel sum must equal 1.0 across active deelnemers

**Severity**: Low
**Mitigation**: An aggregation invariant flags when the sum of
`aandeel` across `actief: true` deelnemers ≠ 1.0; the warning
surfaces in the Consolidated view. No hard refusal; some
verdeelsleutels (custom-formula) intentionally allow partial
aandelen.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-register pattern.

## Open Questions

1. **GR consolidation cardinality** — does a single shillinq
   administration represent the GR (with deelnemers as FK records)
   or do deelnemers each run their own administration with cross-
   admin aggregation? `REQ-GRC-002` proposes the first.
2. **Eliminations across periods** — should the elimination apply
   period-locally, or rolling cumulative? Confirm with the BBV
   reviewer persona before `opsx-apply`.
