# Tasks — Innovatiebox Administratie

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-innovatiebox-administratie` spec — recorded now so
> spec-review and dependency planning are visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `IPAssetValuation`, `InnovatieboxElection`, `WinstToerekening` schemas or `bookkeeping-innovatiebox-administratie` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [x] Task 2: Author `specs/bookkeeping-innovatiebox-administratie/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (MKB / innovation)` / `Depends on: bookkeeping-cost-centers-dimensions, bookkeeping-vpb-corporate-tax` header, `REQ-IBA-NNN` requirements, `#### Scenario:` blocks; PRESERVE the existing `0.09` statutory tariff verbatim per Wet Vpb art. 12b 2026
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions; cite the seeded tariff approach per REQ-IBA-007 (NOT hard-coded; the 0.09 statutory rate is correct now)
- [x] Task 4: Author `design.md` with Reuse Analysis table and Seed Data section; bookkeeper persona reads the forfaitair vs afpelmethode flow end-to-end
- [x] Task 5: Declare the `InnovatieboxElection` schema in `lib/Settings/shillinq_register.json` with `administrationId`, `fiscalYear`, `route` enum (`forfaitair | afpelmethode`), `applicableTariff` (default `0.09` per REQ-IBA-007 seed), `forfaitairCapBedrag` (default 25000), `forfaitairPercentage` (default 0.25 per Wet Vpb art. 12bg) per REQ-IBA-002
- [x] Task 6: Declare the `IPAssetValuation` schema (afpelmethode only) with `assetNaam`, `assetType` enum (s-en-o-certificaat / octrooi / kwekersrecht / softwareprogrammatuur / model-tekening), `wbsoVerklaringNummer` FK (required when assetType is s-en-o-certificaat), `octrooiNummer`, `valuationBedrag`, `valuationDate`, `applicableTariff` (default `0.09`), `vpbBalansLinkId` FK, `schema:Intangible` annotation per REQ-IBA-001
- [x] Task 7: Declare the `WinstToerekening` schema per-period mapping omzet/winst to one or more IP-assets via configurable verdeelsleutel per REQ-IBA-004
- [x] Task 8: Ship `lib/Settings/seeds/innovatiebox-tariefen.json` declaring the historic tariff schedule (before 2018: 0.05, 2018–2020: 0.07, 2021–present: 0.09 statutory per Wet Vpb art. 12b 2026) + forfaitair parameters (cap 25000, percentage 0.25); SPDX in docblock; `_meta` (`source: 'Wet Vpb art. 12b/12bg'`) per REQ-IBA-007
- [x] Task 9: Declare the innovatiebox-administratie aggregation reading the active election per `(administrationId, fiscalYear)` and computing — forfaitair: `min(forfaitairPercentage × operatingProfit, forfaitairCapBedrag)` per REQ-IBA-002, afpelmethode: per-asset `winstToerekening × applicableTariff` per REQ-IBA-003
- [x] Task 10: Enforce mutual exclusion: exactly one `InnovatieboxElection` per `(administrationId, fiscalYear)`; for afpelmethode the aggregation requires at least one `IPAssetValuation`; for forfaitair no `IPAssetValuation` is required
- [x] Task 11: Register the Vpb-aangifte innovatiebox-sectie docudesk template in `lib/Settings/docudesk-templates.json` populated from the innovatiebox-administratie aggregation per REQ-IBA-005
- [x] Task 12: Wire cap-application + tariff-application + route-election to write audit-trail-immutable events automatically
- [x] Task 13: Add Innovatiebox navigation + pages to `src/manifest.json` (`featureFlags.mkb-innovatiebox`, `Bookkeeping > Innovatiebox`, `type: index` (assets + election) + `type: detail` (per-asset detail + winsttoerekening)) per REQ-IBA-006; `node tests/validate-manifest.js` exits 0
- [x] Task 14: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `IPAssetValuation` + `InnovatieboxElection` + `WinstToerekening` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder.
Bookkeeper persona walks through both routes — forfaitair example
(€200k operating profit → 25k cap binds; €500k → 25k cap binds at
9% statutory) + afpelmethode example (S&O-certificaat asset, €100k
winst attributed, 9% statutory → €9k Vpb impact). Architecture
reviewer confirms ADR-022 + ADR-024 + ADR-031 + ADR-032 compliance
(seed-driven tariffs; no PHP innovatiebox-service; declarative
mutual exclusion). No source code changes outside
`openspec/changes/add-shillinq-innovatiebox-administratie/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for forfaitair cap-binding, afpelmethode
per-asset × tariff, route mutual exclusion, tariff lookup per
fiscal year (verifying 2026 = 0.09 per Wet Vpb art. 12b), seed
idempotent re-run (pre-declared on Tasks 5–12); Playwright MCP
browser tests for the Innovatiebox pages (pre-declared on Task 13);
`composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors `docs/user-guide/bookkeeping/mkb/innovatiebox/innovatiebox-administratie.md`
per ADR-030 journeydoc convention and commits screenshots of both
routes to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Innovatiebox`, `IP-asset`, `Octrooi`,
`Kwekersrecht`, `Softwareprogrammatuur`, `Forfaitair`,
`Afpelmethode`, `Winsttoerekening`, `Innovatiebox tarief`,
`Forfaitair plafond`.
