# Proposal: add-shillinq-rekenkamer-audit-pack

`kind: config` per ADR-032 — the centre of mass is three
aggregation declarations + three docudesk template references + an
openconnector source row. No PHP service classes are authored; no
parallel audit register exists.

## Summary

Introduce the **rekenkamer + accountantscontrole audit-pack**
capability for Shillinq as one slice of the Tier 4-specialized
rollout per `adr-001-bookkeeping-tier-roadmap.md`. The audit-pack
is a **presentation manifest** on top of the existing T2
audit-trail-immutable surface per ADR-022 — explicitly NOT a new
audit register. Three outputs ship: a NIVRA-bestand export (the
Dutch accountancy profession's standardised audit-file format),
deterministic-seed steekproef sampling for substantive testing, and
a ledenraadpleging-export with personally-identifying data redacted
per the audit-pack profile. Every audit-pack export is itself
recorded as an immutable audit event with cryptographic linkage to
the produced document.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-audit-trail`](../../specs/bookkeeping-audit-trail/spec.md)
  — the immutable audit-trail surface the audit-pack projects from.
- [`bookkeeping-financial-statements`](../../specs/bookkeeping-financial-statements/spec.md)
  — supplies the trial-balance + chart-of-accounts the
  NIVRA-bestand bundles.

## Motivation

Rekenkamers and accountants expect a standardised audit-file
(NIVRA-bestand), reproducible substantive samples, and a redacted
slice fit for raadsleden review. Without dedicated audit-pack
primitives, each rekenkamer / accountant gets a bespoke export
process. Per ADR-022, the OR audit-trail-immutable surface already
provides the hash-chained event log; the audit-pack is a thin
projection / transformation on top — no parallel storage, no
re-implementation. Per the parent envelope's design D3, the
audit-pack is purely presentation: 3 aggregations + 3 docudesk
templates + 1 openconnector source row.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 0 new registers; 3 aggregation
  declarations on existing audit-trail + GL data; 3 docudesk
  template references; 1 openconnector source row; 1 manifest
  navigation entry behind `featureFlags.gov-rekenkamer`
- [ ] Project: openregister — no source changes; consumes
  audit-trail-immutable + `x-openregister-aggregations`
- [ ] Project: docudesk — registers 3 templates (NIVRA-bestand,
  steekproef werkpapier, ledenraadpleging-export); shillinq
  references them by URI

## Scope

### In Scope

- One new capability spec (`bookkeeping-rekenkamer-audit-pack`) —
  see the `specs/` folder.
- Three aggregation declarations on existing audit-trail + GL data:
  1. NIVRA-bestand (every transaction + audit event + trial balance
     + chart of accounts in effect for the period).
  2. Steekproef (reproducible deterministic sample given periodId,
     sampleSize, seed).
  3. Ledenraadpleging-export (redacted slice with fields tagged
     `redactFor: ['raadsleden']` replaced by stable hash).
- Three docudesk template references (XML NIVRA, steekproef
  werkpapier, raadsleden-export).
- One openconnector source row for any external audit-portal
  submission (per accountant per administration).
- Every export writes an immutable audit event with operator id,
  output type, period id, document URI, SHA-256.
- Manifest navigation entry (Bookkeeping > Audit pack) behind
  `featureFlags.gov-rekenkamer` with three sub-pages.

### Out of Scope

- **Implementation code** — spec-only change.
- **A parallel audit register** — explicitly NOT in scope (per
  ADR-022); audit-pack outputs project from
  audit-trail-immutable.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-rekenkamer-audit-pack`). Each requirement is prefixed
`REQ-REK-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — no schema changes; only
  aggregation declarations that read from audit-trail-immutable +
  GL.
- `src/manifest.json` — adds 1 navigation entry + 3 sub-pages
  behind `featureFlags.gov-rekenkamer`.
- `lib/Settings/docudesk-templates.json` — registers 3 template
  references.
- `lib/Settings/openconnector-sources.json` — 1 source row (per
  accountant per administration).
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes audit-trail-immutable + aggregation
  engine; the aggregation MUST support deterministic sampling with
  a seed parameter. If not, ADR-031 exception path applies (a
  ~20-LOC PHP guard documented as engine-limit fallback).
- **docudesk** — 3 templates registered; shillinq references by
  URI.
- **openconnector** — audit-portal source row per administration.

## Risks

### Risk 1: NIVRA-bestand format evolves with the standard version

**Severity**: Low
**Mitigation**: Output format MUST conform to the
controleprotocol referenced in `_meta.standardVersion`. Multiple
templates may coexist (`nivra-bestand-2026.xml`,
`nivra-bestand-2027.xml`); the spec references the standard, not
year-values.

### Risk 2: Steekproef deterministic seed across engine versions

**Severity**: Medium
**Mitigation**: The deterministic-sample requirement is stated in
REQ-REK-003; if the aggregation engine cannot guarantee
reproducibility, the implementing cycle falls back to a thin
~20-LOC PHP sampler per ADR-031 exception path.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback: revert the implementing
PR; aggregations + manifest entries are non-destructive (existing
audit-trail data unaffected).

## Open Questions

1. **Redaction profile fields** — confirm with the accountant
   persona which `description`-level free-text fields should be
   redacted by default for raadsleden export, and which should be
   replaced by hash vs. by `[REDACTED]` placeholder.
