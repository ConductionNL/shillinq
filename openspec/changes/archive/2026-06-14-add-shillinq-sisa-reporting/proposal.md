# Proposal: add-shillinq-sisa-reporting

`kind: config` per ADR-032 — the centre of mass is a
`SisaRegelingIndicator` register + a year-versioned controleprotocol
seed + a declarative SiSa-bijlage aggregation + an openconnector BZK
upload source row. No PHP service classes are authored.

## Summary

Introduce the **Single Information Single Audit (SiSa) reporting**
capability for Shillinq as one slice of the Tier 4-specialized
rollout per `adr-001-bookkeeping-tier-roadmap.md`. SiSa is the BZK
reporting framework for **specifieke uitkeringen** — government
grants tied to specific performance indicators. This change
declares a `SisaRegelingIndicator` register attaching per-regeling
indicatoren to subsidies of subtype `specifieke-uitkering`, ships a
`sisa-controleprotocol-2026.json` seed, declares the annual
SiSa-bijlage as an aggregation rendered via docudesk, and registers
the BZK upload as an openconnector source. Every submission is
recorded as an immutable audit event linked to the parent
jaarrekening.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-subsidie-verantwoording`](../../specs/bookkeeping-subsidie-verantwoording/spec.md)
  — the T3 subsidie register the SiSa indicators attach to.

## Motivation

Every gemeente/provincie/waterschap with specifieke uitkeringen
owes BZK an annual SiSa-bijlage with per-regeling indicators per
controleprotocol. Without dedicated primitives, the bijlage is a
spreadsheet against the audit-trail. Per the parent envelope's
design D5, the indicators are schema-declared per regeling (seeded
from the annual controleprotocol), and the bijlage rendering is a
declarative aggregation per controleprotocol. No SiSa service.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 1 schema (`SisaRegelingIndicator`),
  ships `lib/Settings/seeds/sisa-controleprotocol-2026.json`,
  declares the SiSa-bijlage aggregation, registers 1 docudesk
  template + 1 openconnector source row, adds 1 manifest navigation
  entry behind `featureFlags.gov-sisa`
- [ ] Project: openregister — no source changes
- [ ] Project: docudesk — registers SiSa-bijlage template
- [ ] Project: openconnector — registers BZK SiSa upload source

## Scope

### In Scope

- One new capability spec (`bookkeeping-sisa-reporting`) — see
  the `specs/` folder.
- `SisaRegelingIndicator` register attaching to the parent
  `Subsidie` (subtype `specifieke-uitkering`) with `regelingCode`,
  `indicatorCode`, `indicatorOmschrijving`, `indicatorWaarde`,
  `indicatorEenheid`, `peilDatum`.
- `lib/Settings/seeds/sisa-controleprotocol-2026.json` seed loaded
  via `ConfigurationService::importFromApp()`; per-regeling
  indicator definitions with `verplicht: boolean`; SPDX header +
  `_meta` block.
- Annual SiSa-bijlage as a declarative aggregation grouping
  `SisaRegelingIndicator` by `(regelingCode, controleprotocol)`
  for the closed fiscal year; missing `verplicht: true` indicatoren
  surface as warnings in audit preview.
- BZK upload via openconnector source row per ADR-019.
- Every submission writes an immutable audit event with operator
  id, regeling list, controleprotocol version, document SHA-256,
  BZK response status.
- Manifest navigation entry (Bookkeeping > SiSa-rapportage) behind
  `featureFlags.gov-sisa` with `type: index` (indicators per
  regeling per year) + `type: detail` (annual bijlage with
  submission status).

### Out of Scope

- **Implementation code** — spec-only change.
- **Non-SiSa subsidies** — owned by T3 `bookkeeping-subsidie-
  verantwoording`; this spec extends only specifieke uitkeringen.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-sisa-reporting`). Each requirement is prefixed
`REQ-SISA-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema
  (`SisaRegelingIndicator`); declares the SiSa-bijlage aggregation.
- `lib/Settings/seeds/sisa-controleprotocol-2026.json` — new file
  (~200 records), SPDX in docblock, `_meta` block (`source: 'BZK
  SiSa-controleprotocol'`, `year: 2026`).
- `src/manifest.json` — adds 1 navigation entry +
  `type: index` + `type: detail` pages behind
  `featureFlags.gov-sisa`.
- `lib/Settings/docudesk-templates.json` — registers SiSa-bijlage
  template.
- `lib/Settings/openconnector-sources.json` — registers BZK
  upload source.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-aggregations`,
  audit-trail-immutable.
- **docudesk** — SiSa-bijlage template.
- **openconnector** — BZK SiSa upload source (OAuth or PKI-cert
  per BZK specifiek).

## Risks

### Risk 1: SiSa controleprotocol revisions yearly with new regelingen

**Severity**: Low
**Mitigation**: Seed filename version-pinned
(`sisa-controleprotocol-2026.json` → `-2027.json`). Spec references
the controleprotocol, not year-values.

### Risk 2: BZK upload endpoint authentication

**Severity**: Low
**Mitigation**: Openconnector owns the auth; shillinq references
by source id. Per ADR-019.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-register pattern.

## Open Questions

1. **Controleprotocol seed update cadence** — typically BZK
   publishes the new controleprotocol in Q4 for the next year;
   confirm with the SiSa reviewer persona before `opsx-apply`.
