# Proposal: add-shillinq-innovatiebox-administratie

`kind: config` per ADR-032 — the centre of mass is two new
registers (`IPAssetValuation`, `WinstToerekening`) + an
`InnovatieboxElection` election register + a year-versioned
tarieven-seed + declarative aggregations rendering the
innovatiebox-administratie. No PHP service classes are authored.

## Summary

Introduce the **innovatiebox administratie** capability for Shillinq
as one slice of the Tier 4-specialized rollout per
`adr-001-bookkeeping-tier-roadmap.md`. Per Wet Vpb art. 12b /
12bg, profit attributable to self-produced immateriële activa MAY
be taxed at the innovatiebox tariff (currently 9% per Wet Vpb art.
12b 2026 statutory rate). Two routes are supported: the **forfaitair**
election (Wet Vpb art. 12bg — 25% of operating profit capped at
€25 000, no per-asset valuation required) and the **afpelmethode**
(Wet Vpb art. 12b — explicit per-IP-asset valuation +
winsttoerekening). This change declares the `InnovatieboxElection`
register parameterising the per-fiscal-year route, the
`IPAssetValuation` register (afpelmethode only), the
`WinstToerekening` register (per-period mapping omzet → IP-assets),
the innovatiebox-administratie aggregation rendering the 9% tariff
impact, the annual `innovatiebox-tariefen.json` seed, and the
Vpb-aangifte innovatiebox-sectie docudesk template.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-cost-centers-dimensions`](../../specs/bookkeeping-cost-centers-dimensions/spec.md)
  — the T4-base cost-center register the WinstToerekening references.
- [`bookkeeping-vpb-corporate-tax`](../../specs/bookkeeping-vpb-corporate-tax/spec.md)
  — supplies the Vpb-balans surface the innovatiebox-sectie
  attaches to.

## Motivation

Without dedicated innovatiebox primitives, taxpayers must hand-
maintain IP-asset valuations + winsttoerekening + tariff
attribution in spreadsheets, then transcribe into the Vpb-aangifte.
Per the parent envelope's design D8, the innovatiebox flow is
clean enough to express declaratively: an IP-asset register
(afpelmethode), an election register (forfaitair vs afpelmethode),
a per-period winsttoerekening, and an aggregation summing tariff
impact. The annual tarieven (including the statutory 9% rate) are
seeded so a 2027 statutory change is a seed update, not a code
change.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 3 schemas (`IPAssetValuation`,
  `InnovatieboxElection`, `WinstToerekening`), ships
  `lib/Settings/seeds/innovatiebox-tariefen.json`, declares the
  innovatiebox-administratie aggregation, registers Vpb-aangifte
  innovatiebox-sectie docudesk template, adds 1 manifest
  navigation entry behind `featureFlags.mkb-innovatiebox`
- [ ] Project: openregister — no source changes
- [ ] Project: docudesk — registers innovatiebox-sectie template

## Scope

### In Scope

- One new capability spec
  (`bookkeeping-innovatiebox-administratie`) — see the `specs/`
  folder.
- `InnovatieboxElection` register parameterising per-fiscal-year
  route (`forfaitair | afpelmethode`) + applicable tariff +
  forfaitair cap/percentage.
- `IPAssetValuation` register (afpelmethode only) declaring IP
  assets (S&O-certificaat, octrooi, kwekersrecht,
  softwareprogrammatuur, model-tekening) with `valuationBedrag`,
  `valuationDate`, `applicableTariff` (default `0.09` per
  REQ-IBA-007 seed), `vpbBalansLinkId` FK.
- `WinstToerekening` register per-period mapping omzet/winst to
  one or more IP-assets via configurable verdeelsleutel.
- Innovatiebox-administratie aggregation rendering the 9% tariff
  impact per asset (afpelmethode) or the capped 25% × operating
  profit (forfaitair).
- `lib/Settings/seeds/innovatiebox-tariefen.json` seed with the
  full historic tariff schedule (2007: 0.10, before 2018: 0.05,
  2018–2020: 0.07, 2021–present: 0.09 — currently 0.09 statutory)
  + forfaitair parameters; SPDX in docblock; `_meta` block.
- Vpb-aangifte innovatiebox-sectie docudesk template.
- Manifest navigation entry (Bookkeeping > Innovatiebox) behind
  `featureFlags.mkb-innovatiebox` with `type: index` (assets +
  election) and `type: detail` (per-asset detail + winsttoerekening).

### Out of Scope

- **Implementation code** — spec-only change.
- **WBSO administratie itself** — owned by sibling
  `add-shillinq-wbso-sno-administratie`; the `IPAssetValuation`
  references S&O-certificaten by `wbsoVerklaringNummer` field.
- **Vpb-balans + aangifte** — owned by sibling
  `add-shillinq-vpb-corporate-tax`; this spec attaches the
  innovatiebox-sectie to it.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-innovatiebox-administratie`). Each requirement is
prefixed `REQ-IBA-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 schemas
  (`IPAssetValuation`, `InnovatieboxElection`, `WinstToerekening`);
  declares the innovatiebox-administratie aggregation.
- `lib/Settings/seeds/innovatiebox-tariefen.json` — new file,
  SPDX in docblock, `_meta` block (`source: 'Wet Vpb art. 12b/12bg'`).
- `src/manifest.json` — adds 1 navigation entry +
  `type: index` + `type: detail` pages behind
  `featureFlags.mkb-innovatiebox`.
- `lib/Settings/docudesk-templates.json` — registers Vpb-aangifte
  innovatiebox-sectie template.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-aggregations`,
  audit-trail-immutable.
- **docudesk** — innovatiebox-sectie template.

## Risks

### Risk 1: Statutory tariff change

**Severity**: Low
**Mitigation**: Per REQ-IBA-007 the tariff schedule is seeded.
A future tariff change ships as a new seed file
(`innovatiebox-tariefen-2028.json`); aggregation reads the active
tariff per fiscal year. NO code change required. The current
statutory 9% (`0.09`) is correct per Wet Vpb art. 12b 2026.

### Risk 2: Forfaitair vs afpelmethode mutual exclusion per fiscal year

**Severity**: Low
**Mitigation**: Enforced by `InnovatieboxElection.route` being a
required per-fiscal-year field; aggregation refuses to combine
both routes for the same `administrationId` + `fiscalYear`.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-register pattern.

## Open Questions

1. **Forfaitair default route for new SMB administraties** —
   cleaner audit trail per REQ-IBA-002; confirm with the
   bookkeeper persona that forfaitair should be the default for
   new MKB administraties (existing administraties retain their
   active election).
