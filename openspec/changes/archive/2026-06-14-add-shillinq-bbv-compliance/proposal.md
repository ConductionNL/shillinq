# Proposal: add-shillinq-bbv-compliance

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + BBV taakveld + RGS↔BBV mapping
seed data. No PHP service classes are authored.

## Summary

Introduce **BBV (Besluit Begroting en Verantwoording)** posting-rule
compliance for Shillinq as a T3 capability per
`adr-001-bookkeeping-tier-roadmap.md`. This change declares the
`BbvAccountMapping` and `BbvTaakveld` registers with
`x-openregister-lifecycle` rules (per ADR-031), extends T1
`GLTransaction.post` with a BBV-mapping precondition for municipal
administrations, wires navigation into `src/manifest.json` (per
ADR-024), and ships the `bbv-taakvelden-2024.json` +
`rgs-to-bbv-mapping.json` seeds loaded via
`ConfigurationService::importFromApp()` (per ADR-022). No PHP
service classes, no parallel link tables, no bespoke Vue components.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T1 `bookkeeping-chart-of-accounts` (FK target for
mapping) and T1 `bookkeeping-general-ledger` (post-precondition
hook).

## Motivation

Every Dutch municipality, provincie, and waterschap reports its
bookkeeping against the BBV taakvelden catalogue (Besluit
Begroting en Verantwoording bijlage IV). Postings without a
taakveld cannot be exported to IV3 or aggregated for BCF. The
mapping from RGS account → taakveld is **operator-editable per
administration** (one municipality posts `4250 Subsidies cultuur`
to taakveld `5.3 Cultuurpresentatie`, another to `5.6 Media`).

Per ADR-022, the mapping lives in its own register — no parallel
link tables, no embedded enum on `Account`.

## Affected Projects

- [x] Project: shillinq — adds 2 new registers/schemas
  (`BbvAccountMapping`, `BbvTaakveld`) to
  `lib/Settings/shillinq_register.json`, adds 1 manifest navigation
  entry (`Overheid > BBV-mapping`) in `src/manifest.json`, ships 2
  seed files (`bbv-taakvelden-2024.json`, `rgs-to-bbv-mapping.json`).
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (audit, RBAC, lifecycle, mappings).

## Scope

### In Scope

- One new capability spec (`bookkeeping-bbv-compliance`).
- `BbvAccountMapping` register carrying (administrationId,
  accountNumber, taakveld, programmaCode, paragraafCode?,
  bcfCompensable, iv3Bucket, autorisatieniveau).
- `BbvTaakveld` register loaded from the BBV bijlage IV seed.
- T1 `GLTransaction.post` precondition: for `gemeente`/`provincie`/
  `waterschap` administrations, every line's `accountNumber` MUST
  resolve to a `BbvAccountMapping` row.
- Manifest navigation under `Overheid` (visibility filtered to
  municipal-administration types).
- RGS↔BBV default mapping seed; per-administration override allowed.

### Out of Scope

- **IV3 export** — owned by sibling `add-shillinq-iv3-reporting`.
- **BCF claim administration** — owned by sibling
  `add-shillinq-bcf-vat-compensation`.
- **Implementation code** — spec-only change.
- **Industry-specific BBV variants** (housing corp, healthcare,
  education) — out of scope (T3+ roadmap).

## Approach

One delta with ADDED Requirements under `REQ-BBV-*`.

## New Dependencies

None. Consumes T1 chart-of-accounts + general-ledger, plus existing
OR abstractions.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 schemas; extends
  T1 `GLTransaction.post` lifecycle with a BBV-mapping precondition
  scoped to municipal administration types.
- `lib/Settings/seeds/bbv-taakvelden-2024.json`,
  `lib/Settings/seeds/rgs-to-bbv-mapping.json` — new files.
- `src/manifest.json` — adds 1 navigation entry + index/detail
  pages.
- Repair step extension to import the BBV seeds for new municipal
  administrations only.
- No new PHP services.

## Cross-Project Dependencies

- **OpenRegister** — relies on lifecycle preconditions reading
  cross-schema relations (FK presence). Standard shape, no new OR
  features needed.

## Risks

### Risk 1: BBV taakveld catalogue revision (2024 → 2026)

**Severity**: Low
**Mitigation**: Seed filename versioning (`bbv-taakvelden-2024.json`
→ `bbv-taakvelden-2026.json`). `_meta.bbvVersion` tag on every
mapping row. Coexistence trivial.

### Risk 2: Per-administration mapping override drift

**Severity**: Low
**Mitigation**: The seed provides defaults; per-administration
override is allowed and audited via OR's audit-trail-immutable.
Operators document local divergence per their archiefverordening.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the
change folder. After implementation: revert the PR, run the repair
step in down-direction. Registers are non-destructive.

## Open Questions

1. **Programma/Paragraaf cardinality** — `paragraafCode` is
   optional per `REQ-BBV-002`; confirm with municipal-controller
   persona during spec review.
2. **Pre-existing GL postings on the BBV precondition gate** —
   newly-introduced gate may reject historic unmapped postings on
   first install. Solution: precondition is forward-only by
   `postingDate ≥ install date` per `REQ-BBV-003`.
