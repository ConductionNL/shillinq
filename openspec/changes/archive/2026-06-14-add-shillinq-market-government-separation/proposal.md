# Proposal: add-shillinq-market-government-separation

`kind: config` per ADR-032 — the centre of mass is an
`ondernemingsActiviteit` flag on `CostCenter` + a declarative
integrale-kostprijs calculation + an `algemeenBelangBesluit`
overlay + the transparantieadministratie view. No PHP service
classes are authored.

## Summary

Introduce the **Wet Markt en Overheid separation** capability for
Shillinq as one slice of the Tier 4-specialized rollout per
`adr-001-bookkeeping-tier-roadmap.md`. Per Mededingingswet hoofdstuk
4b, gemeenten / provincies / waterschappen running market activities
must (a) identify ondernemingsactiviteiten as distinct clusters,
(b) compute the integrale kostprijs (direct costs + allocated
overhead + equity compensation), and (c) maintain a
transparantieadministratie showing the ondernemingsactiviteit is
not cross-subsidised. This change adds an `ondernemingsActiviteit`
flag on the T4-base `CostCenter`, declares an integrale-kostprijs
`x-openregister-calculations` block, declares an
`algemeenBelangBesluit` overlay that suppresses the under-cost-
recovery warning, and adds a transparantieadministratie navigation
entry behind `featureFlags.gov-markt-overheid`.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-cost-centers-dimensions`](../../specs/bookkeeping-cost-centers-dimensions/spec.md)
  — the T4-base CostCenter register that gains the
  `ondernemingsActiviteit` flag.

## Motivation

Wet Markt en Overheid imposes a structural obligation on
gemeenten/provincies/waterschappen with ondernemingsactiviteiten;
without dedicated primitives, the integrale-kostprijs + transparantie
view either lives in a separate spreadsheet or in a separate
product. Per the parent envelope's design D6, the obligation maps
cleanly to schema metadata + an `x-openregister-calculations`
block on existing `CostCenter` data — no new register, no PHP
kostprijs service.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds `ondernemingsActiviteit: boolean`
  flag on `CostCenter` (default `false`), declares the integrale-
  kostprijs calculation, adds `algemeenBelangBesluit` overlay
  schema, adds 1 manifest navigation entry behind
  `featureFlags.gov-markt-overheid`
- [ ] Project: openregister — no source changes

## Scope

### In Scope

- One new capability spec
  (`bookkeeping-market-government-separation`) — see the `specs/`
  folder.
- `ondernemingsActiviteit: boolean` flag on `CostCenter` (default
  `false`); when `true`, the kostprijs requirement automatically
  applies; ondernemingsactiviteit views carry `schema:Service`
  type annotation.
- Integrale-kostprijs `x-openregister-calculations` block per
  ondernemingsactiviteit cost-center, summing direct costs +
  allocated overhead via a configurable verdeelsleutel + equity
  compensation (configurable percentage on deployed equity).
- Tarieven-vs-kostprijs aggregation surfacing under-cost-recovery
  warnings when realised revenue < integrale kostprijs.
- `algemeenBelangBesluit` overlay schema with `besluitNummer`,
  `besluitDatum`, `geldigheidsperiode`, `motivering` (docudesk
  attachment URI), `getrokkenBedrag`; suppresses the under-cost-
  recovery warning when valid.
- Manifest navigation entry (Bookkeeping > Markt en Overheid)
  behind `featureFlags.gov-markt-overheid` with `type: index` per
  ondernemingsactiviteit and `type: detail` per cost-center.

### Out of Scope

- **Implementation code** — spec-only change.
- **Vpb administration** — owned by sibling
  `add-shillinq-vpb-corporate-tax`; this spec's flag is consumed
  by that sibling's `VpbBalansLink`.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-market-government-separation`). Each requirement is
prefixed `REQ-MGS-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds
  `ondernemingsActiviteit` flag on `CostCenter`; declares the
  integrale-kostprijs calculation + tarieven-vs-kostprijs
  aggregation; declares the `algemeenBelangBesluit` overlay.
- `src/manifest.json` — adds 1 navigation entry +
  `type: index` + `type: detail` pages behind
  `featureFlags.gov-markt-overheid`.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-calculations` for
  the integrale-kostprijs computation; consumes
  `x-openregister-aggregations` for the tarieven-vs-kostprijs
  check.

## Risks

### Risk 1: Verdeelsleutel choice subjective per administration

**Severity**: Low
**Mitigation**: The overhead verdeelsleutel is configurable per
administration; defaults stay sector-neutral. The
algemeen-belang-besluit overlay covers legitimate exceptions.

### Risk 2: Equity-compensation percentage drifts (Wet Markt en Overheid art. 25i)

**Severity**: Low
**Mitigation**: Configurable per administration; spec references
the regulation, not the percentage value.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-field pattern.

## Open Questions

1. **Default equity-compensation percentage** — Wet Markt en
   Overheid art. 25i does not prescribe a single value; common
   practice uses 4%. Confirm with the Mededingingswet reviewer
   persona before `opsx-apply`.
