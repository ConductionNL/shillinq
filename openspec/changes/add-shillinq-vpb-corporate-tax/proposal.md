# Proposal: add-shillinq-vpb-corporate-tax

`kind: config` per ADR-032 — the centre of mass is a `vpbPligtig`
flag on `Account` + a `VpbBalansLink` overlay + a declarative
Vpb-balans aggregation + a Vpb-aangifte voorbereiding docudesk
template. No PHP service classes are authored.

## Summary

Introduce the **vennootschapsbelasting (Vpb) administration**
capability for Shillinq as one slice of the Tier 4-specialized
rollout per `adr-001-bookkeeping-tier-roadmap.md`. Per Wet
modernisering Vpb-plicht (2016), municipal ondernemingsactiviteiten
and certain stichtingen/verenigingen are Vpb-pligtig. This change
declares a `vpbPligtig` flag on `Account`, declares a
`VpbBalansLink` overlay tying ondernemingsactiviteit cost-centers
(from sibling `add-shillinq-market-government-separation`) to
Vpb-pligtig accounts, declares the Vpb-balans as an aggregation,
and ships a Vpb-aangifte voorbereiding docudesk template. The
actual aangifte transmission rides the T4-base SBR-XBRL path.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-bbv-compliance`](../../specs/bookkeeping-bbv-compliance/spec.md)
  — the BBV-base register the Vpb-balans filters from.
- [`bookkeeping-market-government-separation`](../../specs/bookkeeping-market-government-separation/spec.md)
  — supplies the `ondernemingsActiviteit` flag the
  `VpbBalansLink` references.

## Motivation

Without dedicated Vpb primitives, municipal organisations running
ondernemingsactiviteiten have no clean path from GL to
Vpb-aangifte; the Vpb-balans either lives in a spreadsheet or in a
separate product. Per the parent envelope's design D7, the
Vpb-balans is the same GL data filtered to Vpb-pligtig accounts —
a tag + filter problem, not a transformation. No PHP Vpb-service.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds `vpbPligtig` flag on `Account`,
  declares `VpbBalansLink` overlay, declares Vpb-balans
  aggregation, registers Vpb-aangifte voorbereiding docudesk
  template, adds 1 manifest navigation entry behind
  `featureFlags.mkb-vpb`
- [ ] Project: openregister — no source changes
- [ ] Project: docudesk — registers Vpb-aangifte voorbereiding
  template

## Scope

### In Scope

- One new capability spec (`bookkeeping-vpb-corporate-tax`) — see
  the `specs/` folder.
- `vpbPligtig: boolean` flag on `Account` (default `false`).
- `VpbBalansLink` overlay register with `costCenterId` (FK to
  `CostCenter` with `ondernemingsActiviteit: true`),
  `accountNumbers` array, `vpbPligtigVanaf` date.
- Vpb-balans aggregation (output `VpbBalansFiltered` with
  `schema:Dataset` annotation) filtering `GLLine` on
  `accountNumber IN VpbBalansLink.accountNumbers` AND `periodId IN
  fiscalYearPeriods`, grouped per `costCenterId`, producing
  Activa/Passiva/Resultaat per ondernemingsactiviteit.
- Vpb-aangifte voorbereiding docudesk template populated from
  the Vpb-balans aggregation.
- Aangifte transmission rides T4-base
  `bookkeeping-sbr-xbrl-reporting` SBR endpoint.
- Manifest navigation entry (Bookkeeping > Vennootschapsbelasting)
  behind `featureFlags.mkb-vpb` with `type: index` (Vpb-pligtige
  cost-centers/accounts) + `type: detail` (Vpb-balans + aangifte
  voorbereiding per ondernemingsactiviteit).

### Out of Scope

- **Implementation code** — spec-only change.
- **SBR-XBRL transmission** — owned by T4-base
  `bookkeeping-sbr-xbrl-reporting`; this spec only declares the
  payload binding.
- **Innovatiebox / investeringsaftrek / WBSO** — owned by sibling
  changes.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-vpb-corporate-tax`). Each requirement is prefixed
`REQ-VPB-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions, the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`, and the
T4-base SBR-XBRL transmission path.

## Impact

- `lib/Settings/shillinq_register.json` — adds `vpbPligtig` flag
  on `Account`; declares `VpbBalansLink` overlay; declares the
  Vpb-balans aggregation.
- `src/manifest.json` — adds 1 navigation entry +
  `type: index` + `type: detail` pages behind
  `featureFlags.mkb-vpb`.
- `lib/Settings/docudesk-templates.json` — registers Vpb-aangifte
  voorbereiding template.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-aggregations` for
  the Vpb-balans filter + grouping.
- **docudesk** — Vpb-aangifte voorbereiding template.
- **T4-base SBR-XBRL** — SBR endpoint transmission.

## Risks

### Risk 1: Vpb-aangifte XSD evolves yearly

**Severity**: Low
**Mitigation**: The docudesk template references the
Belastingdienst Vpb XSD version per fiscal year; multiple template
versions may coexist; spec references the regulation, not values.

### Risk 2: Linking ondernemingsactiviteit cost-centers to Vpb-pligtige accounts is operator-curated

**Severity**: Low
**Mitigation**: `VpbBalansLink` records are authored by the
bookkeeper; an aggregation invariant warns when a Vpb-pligtig
account has no link to a cost-center (orphaned Vpb-pligt risk).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-field pattern.

## Open Questions

1. **Vpb-balans periodscope** — proposed as `fiscalYearPeriods`
   in `REQ-VPB-003`; confirm with the Vpb-belastingadviseur
   persona whether ongoing-year views should also be exposed.
