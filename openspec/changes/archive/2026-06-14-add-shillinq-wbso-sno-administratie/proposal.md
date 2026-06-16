# Proposal: add-shillinq-wbso-sno-administratie

`kind: config` per ADR-032 — the centre of mass is two new registers
(`SoProject`, `SoUrenStaat`) + a declarative afdracht-calculation
+ docudesk templates for the RvO outputs + openconnector sources.
No PHP service classes are authored.

## Summary

Introduce the **WBSO / S&O administratie** capability for Shillinq
as one slice of the Tier 4-specialized rollout per
`adr-001-bookkeeping-tier-roadmap.md`. Per Wet vermindering afdracht
loonbelasting hoofdstuk VA, S&O-werk MUST be administered per
project + per medewerker, with a quarterly mededeling +
kwartaalrapportage + jaarrapport to RvO. The afdrachtvermindering
loonheffing is computed from the mededeling. This change declares
the `SoProject` register (with RvO link), the `SoUrenStaat` per-
medewerker-per-week-per-project hours register (with lifecycle
`draft → goedgekeurd → afgesloten` and approval-workflow on
goedgekeurd), declares the afdracht calculation reading uren ×
uurloon × actueel afdrachtpercentage (32% standard / 40% starters
per RvO 2026), registers four docudesk templates (mededeling,
kwartaalrapportage, jaarrapport, plus aanvraag template), and
registers openconnector RvO submission sources.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-cost-centers-dimensions`](../../specs/bookkeeping-cost-centers-dimensions/spec.md)
  — the T4-base CostCenter register the `SoProject` references.

## Motivation

Without dedicated WBSO primitives, S&O-administratie lives in
spreadsheets + manual mededelingen, and the afdrachtvermindering
loonheffing is computed by hand. Per the parent envelope's design
D10, two registers + a calculation + four templates close the loop
end-to-end. Per ADR-019 the RvO roundtrip rides openconnector.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 2 schemas (`SoProject`,
  `SoUrenStaat`), declares the afdracht calculation, registers 4
  docudesk templates, registers 1 openconnector RvO source,
  adds 1 manifest navigation entry behind
  `featureFlags.mkb-wbso`
- [ ] Project: openregister — no source changes
- [ ] Project: docudesk — registers mededeling, kwartaalrapportage,
  jaarrapport, and aanvraag templates
- [ ] Project: openconnector — registers RvO source

## Scope

### In Scope

- One new capability spec (`bookkeeping-wbso-sno-administratie`) —
  see the `specs/` folder.
- `SoProject` register (with `schema:Project` annotation):
  `projectNaam`, `rvoProjectNummer`, `sEnOCertificaatNummer`,
  `looptijdStart`, `looptijdEind`, `costCenterId` FK, `status`
  enum (`aangevraagd | toegekend | afgerond`).
- `SoUrenStaat` register (with `schema:Action` annotation):
  `soProjectId` FK, `medewerkerId` (NC user or Detachering record
  FK), `weekISO` (ISO-8601 week), `aantalUren` (≥ 0, decimals
  down to 0.25 hour), `taakOmschrijving`, `state` enum (`draft |
  goedgekeurd | afgesloten`); lifecycle declared with
  approval-workflow on the `goedgekeurd` transition per ADR-022.
- Quarterly mededeling docudesk template populated from
  `SoUrenStaat` aggregation (state ≠ draft) per quarter per
  project.
- Kwartaalrapportage + jaarrapport docudesk templates.
- Afdrachtvermindering `x-openregister-calculations` block:
  `aantalUren × medewerker.sEnOUurloon × actueelAfdrachtPercentage`
  (seeded from RvO 2026 — 32% standard, 40% starters); projected
  value displayed side-by-side with the authoritative RvO
  mededeling value.
- 1 openconnector source for RvO submissions (mededeling +
  kwartaalrapportage + jaarrapport).
- Manifest navigation entry (Bookkeeping > WBSO) behind
  `featureFlags.mkb-wbso` with 4 sub-pages (Projecten, Uren-staten,
  Mededelingen + rapportages, Afdrachtvermindering).

### Out of Scope

- **Implementation code** — spec-only change.
- **Salarisbureau import** — owned by sibling
  `add-shillinq-detachering-payroll-administratie`; this spec
  references its `Detachering` record via `medewerkerId`.
- **Innovatiebox** — owned by sibling
  `add-shillinq-innovatiebox-administratie`; the S&O-certificaat
  is referenced by the innovatiebox `IPAssetValuation.wbsoVerklaringNummer`.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-wbso-sno-administratie`). Each requirement is prefixed
`REQ-WBSO-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 schemas
  (`SoProject`, `SoUrenStaat`); declares the lifecycle + approval-
  workflow on `SoUrenStaat`; declares the afdracht calculation.
- `src/manifest.json` — adds 1 navigation entry + 4 sub-pages
  behind `featureFlags.mkb-wbso`.
- `lib/Settings/docudesk-templates.json` — registers 4 RvO
  templates.
- `lib/Settings/openconnector-sources.json` — registers RvO source.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-lifecycle` (with
  approval-workflow `requires`), `x-openregister-aggregations`,
  `x-openregister-calculations`, audit-trail-immutable.
- **docudesk** — 4 templates.
- **openconnector** — RvO source row per ADR-019.

## Risks

### Risk 1: RvO afdrachtpercentages revise yearly

**Severity**: Low
**Mitigation**: Percentages are seed values (referenced by the
calculation); a future percentage change is a seed update, NOT a
code change.

### Risk 2: Projected vs authoritative afdrachtvermindering drift

**Severity**: Low
**Mitigation**: Per REQ-WBSO-006, both values are shown side-by-
side in the WBSO detail view with a delta-reconciliation warning
for the loonheffing administration.

### Risk 3: Privacy footprint on S&O-uren data

**Severity**: Medium
**Mitigation**: RBAC restricts read on `SoUrenStaat` to
`bookkeeper`, `payroll-officer`, `auditor`. Audit-trail-immutable
per ADR-022 logs every access.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-register pattern.

## Open Questions

1. **Afdracht calculation locus** — per REQ-WBSO-006, shillinq
   projects the afdracht; RvO mededeling is authoritative for
   loonaangifte. Confirm with the WBSO-consultant persona before
   `opsx-apply`.
