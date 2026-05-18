# Tasks — Investeringsaftrek

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-investeringsaftrek` spec — recorded now so spec-
> review and dependency planning are visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `InvesteringClassifier` overlay or `bookkeeping-investeringsaftrek` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [ ] Task 2: Author `specs/bookkeeping-investeringsaftrek/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (MKB / innovation)` / `Depends on: bookkeeping-fixed-assets-depreciation` header, `REQ-INV-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions; cite ADR-031 exception path for KIA-schalen lookup
- [ ] Task 4: Author `design.md` with Reuse Analysis table and Seed Data section; RvO reviewer persona confirms KIA / EIA / MIA / Vamil shape matches the 2026 schalen
- [ ] Task 5: Declare the `InvesteringClassifier` overlay register in `lib/Settings/shillinq_register.json` with `fixedAssetId` FK, `aftrekType` enum (`kia | eia | mia | vamil`), `bedrijfsmiddelCode`, `aanvraagDatum` (optional — required for EIA/MIA/Vamil), `aanvraagNummer` (post-award), `toegekendBedrag` (post-award), `schema:Thing` annotation per REQ-INV-001; cumulative multi-regime support
- [ ] Task 6: Ship `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json` declaring the 2026 KIA threshold/rampup/maximum/taper + EIA 40% + Energielijst codes + MIA 13.5/27/36% per category + Milieulijst codes + Vamil-eligible codes; SPDX in docblock; `_meta` (`source: 'RvO investeringsaftrek-regelingen'`, `year: 2026`) per REQ-INV-003
- [ ] Task 7: Extend the repair step under `lib/Migration/` to import the tarieven seed idempotently when `featureFlags.mkb-investeringsaftrek` is enabled
- [ ] Task 8: Declare the aftrek `x-openregister-calculations` block on `FixedAsset` per regime — KIA (threshold/rampup/maximum/taper lookup), EIA (40%), MIA (13.5/27/36% per category), Vamil (up to 75% in year 1) per REQ-INV-002; if engine cannot express KIA lookup, document the ADR-031 exception path single-method `KiaSchalenLookup`
- [ ] Task 9: Register the RvO aanvraagdossier docudesk template in `lib/Settings/docudesk-templates.json` per REQ-INV-004 (covers EIA/MIA/Vamil — KIA requires no separate aanvraag); template carries asset description, bedrijfsmiddel code, purchase price, investment date, in-service date, bewijsstukken URI refs
- [ ] Task 10: Register the RvO aanvraag-submission + mededeling-feed openconnector source rows in `lib/Settings/openconnector-sources.json` per REQ-INV-006; no `lib/Service/RvoClient.php`
- [ ] Task 11: Wire the mededeling feed to update `toegekendBedrag` asynchronously and write an audit-trail event (`investeringsaftrek.toegekend`) with mededeling id + date + amount per REQ-INV-005
- [ ] Task 12: Add Investeringsaftrek navigation + pages to `src/manifest.json` (`featureFlags.mkb-investeringsaftrek`, `Bookkeeping > Investeringsaftrek`, `type: index` of classifiers + `type: detail` per classifier showing asset link / aanvraag / toekenning / aftrek impact) per REQ-INV-007; `node tests/validate-manifest.js` exits 0
- [ ] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `InvesteringClassifier` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. RvO
reviewer persona walks through worked examples — KIA on €30k
invested (in 2026 rampup), EIA 40% on a €10k Energielijst asset,
MIA 27% on a category-B Milieulijst asset, Vamil 75% year-1
afschrijving. Architecture reviewer confirms ADR-019 + ADR-022 +
ADR-024 + ADR-031 + ADR-032 compliance (no app-local RvO HTTP
client; seed-driven schalen). No source code changes outside
`openspec/changes/add-shillinq-investeringsaftrek/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for KIA schaal lookup at multiple
invested amounts, EIA 40% on Energielijst, MIA percentages per
category, Vamil 75%, cumulative multi-regime classification, award
update audit event (pre-declared on Tasks 5–11); Playwright MCP
browser tests for the Investeringsaftrek pages (pre-declared on
Task 12); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors
`docs/user-guide/bookkeeping/mkb/investeringsaftrek/investeringsaftrek.md`
per ADR-030 journeydoc convention and commits screenshots to
`docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Investeringsaftrek`, `KIA`, `EIA`, `MIA`,
`Vamil`, `Bedrijfsmiddel-code`, `Energielijst`, `Milieulijst`,
`Aanvraagdossier`, `Toegekend bedrag`, `RvO mededeling`.
