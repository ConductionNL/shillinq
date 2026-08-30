# Proposal: add-shillinq-cbs-bestanden-extended

`kind: config` per ADR-032 — the centre of mass is multiple
aggregation declarations + docudesk template references +
openconnector source rows on top of the existing IV3 reporting. No
PHP service classes are authored.

## Summary

Introduce the **extended CBS-bestanden** capability for Shillinq
as one slice of the Tier 4-specialized rollout per
`adr-001-bookkeeping-tier-roadmap.md`. T3's
`bookkeeping-iv3-reporting` already extracts the BBV programma
totals; this change adds the additional CBS-bestanden — Iv3-detail
(per-taakveld-per-categorie detail), Kerngegevens jaarstaten
(annual ratios with denominators like inwoner-aantal), Iv3-OZB
(OZB-inkomsten + WOZ-waarden per heffingstijdvak), and the
EMU-bestand (consuming the sibling EMU-reporting spec). Each is a
transformation atop existing GL aggregations + a docudesk template
+ an openconnector source row pointing at the CBS endpoint.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-iv3-reporting`](../../specs/bookkeeping-iv3-reporting/spec.md)
  — supplies the base IV3 aggregation the extended bestanden roll
  up from.

## Motivation

The CBS expects additional periodic bestanden beyond the base IV3
extraction — without these, an adopter has to bolt on a separate
product for Iv3-detail, Kerngegevens jaarstaten, Iv3-OZB, and the
EMU-bestand. Per the parent envelope's design D4, each bestand is
purely a transformation on existing GL data — no new postings, no
new ledger register, just aggregations + docudesk templates +
openconnector sources.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 0 ledger registers; declares 4+
  aggregations + 1 `kernGegevensConfig` schema; adds
  `ozbCategorie` flag on `GLLine`; adds 4 docudesk template
  references; adds 4 openconnector source rows; adds 1 manifest
  navigation entry behind `featureFlags.gov-cbs-extended`
- [ ] Project: openregister — no source changes
- [ ] Project: docudesk — registers 4 templates (Iv3-detail CSV,
  Kerngegevens XML, Iv3-OZB CSV, EMU-bestand XML)
- [ ] Project: openconnector — registers CBS endpoint sources

## Scope

### In Scope

- One new capability spec (`bookkeeping-cbs-bestanden-extended`) —
  see the `specs/` folder.
- Iv3-detail aggregation grouping `GLLine` by `(periodId,
  taakveld, categorie)` summing `(debit - credit)` in EUR; CSV
  output per CBS-canonical layout; per-quarter submission.
- Kerngegevens jaarstaten aggregation consuming the closed fiscal-
  year jaarrekening + an administration-level `kernGegevensConfig`
  schema (declaring `inwonerAantal`, `oppervlak`, etc.); XML
  output.
- Iv3-OZB aggregation grouping OZB-postings by `(periodId,
  ozbCategorie)` where `ozbCategorie` is a `GLLine` flag
  distinguishing eigenaars-deel / gebruikers-deel / woning /
  niet-woning; CSV per CBS Iv3-OZB layout.
- EMU-bestand aggregation consuming the ESA-2010 classifier from
  sibling `add-shillinq-emu-reporting`; XML per CBS EMU layout.
- All CBS submissions ride openconnector source rows per ADR-019
  (Iv3, Iv3-detail, Iv3-OZB, Kerngegevens, EMU).
- Manifest navigation entry (Bookkeeping > CBS-bestanden) behind
  `featureFlags.gov-cbs-extended` listing per-bestand sub-pages
  with `type: detail` showing latest run + history.

### Out of Scope

- **Implementation code** — spec-only change.
- **EMU computation itself** — owned by sibling
  `add-shillinq-emu-reporting`; this spec only declares the
  transformation that consumes the EMU classifier + computation.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-cbs-bestanden-extended`). Each requirement is
prefixed `REQ-CBSE-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions, the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`, and
existing openconnector source patterns.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 administration-
  level `kernGegevensConfig` schema; adds `ozbCategorie` flag on
  `GLLine`; declares the four CBS aggregations.
- `src/manifest.json` — adds 1 navigation entry + per-bestand
  sub-pages behind `featureFlags.gov-cbs-extended`.
- `lib/Settings/docudesk-templates.json` — registers 4 template
  references.
- `lib/Settings/openconnector-sources.json` — 4 CBS source rows.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-aggregations`,
  audit-trail-immutable, scheduled-workflow (for periodic
  triggers).
- **docudesk** — 4 templates registered.
- **openconnector** — 4 CBS source rows; submission protocol is
  openconnector-side per ADR-019.

## Risks

### Risk 1: CBS-bestand layouts evolve

**Severity**: Medium
**Mitigation**: Each docudesk template references a `_meta.cbsSpec`
version; multiple templates may coexist (`iv3-detail-2026.csv` →
`-2027.csv`); spec references the standard, not the values.

### Risk 2: Kerngegevens denominators (inwoner-aantal, oppervlak) are administratie-config, not posting data

**Severity**: Low
**Mitigation**: A small `kernGegevensConfig` schema is declared
per administration; values are operator-authored. No external
feed required at T4-specialized.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
non-destructive additive-aggregation pattern.

## Open Questions

1. **Iv3-detail submission cadence** — quarterly confirmed in
   REQ-CBSE-002; verify with the BBV reviewer persona whether the
   CBS portal expects calendar-quarter alignment or fiscal-period
   alignment for administraties that close on non-calendar quarters.
