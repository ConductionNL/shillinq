# Tasks — Investeringsaftrek

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-investeringsaftrek` spec — recorded now so spec-
> review and dependency planning are visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Dedup scan executed against shillinq dev tree (2026-06-09). FINDINGS: (a) The umbrella's `InvesteringClassifier` overlay name is NOT present, but the equivalent investeringsaftrek surface IS already landed on `development` under a richer set of schemas via the merged precursor PR #299 (`hydra/bookkeeping-investeringsaftrek`, commits cf63eb25 + aefc7932 — `feat(bookkeeping-investeringsaftrek): KIA/EIA/MIA/Vamil investeringsaftrek (declarative ADR-031)`). The precursor ships an ADR-037 modular register fragment at `lib/Settings/register.d/bookkeeping-investeringsaftrek.json` carrying six schemas — `InvestmentAsset` (1-to-1 FK to `FixedAsset` per `fixedAssetId`, the canonical capitalised-asset overlay covering the umbrella's `InvesteringClassifier` role with `x-schema-org: schema:Thing` annotation), `EnergielijstCode` (yearly RvO Energielijst snapshot, jaartal-versioned, seeded from `seeds/investeringsaftrek-energielijst-2026.json`), `MilieulijstCode` (yearly Milieulijst snapshot carrying MIA percentage + `vamilToegestaan` flag, seeded from `seeds/investeringsaftrek-milieulijst-2026.json`), `InvesteringsaftrekClaim` (the per-claim record covering `aftrekType` enum across `kia | eia | mia | vamil`, `aanvraagNummer`, `toegekendBedrag`, and audit-state — i.e. the umbrella's classifier fields are decomposed into a stronger Asset + Claim split for cardinality reasons), `VamilDepreciation` (Vamil-specific vrije afschrijving line), `KIATier` (the 2026 KIA tiered lookup table seeded from `seeds/investeringsaftrek-kia-tiers-2026.json`). (b) The capability spec at `openspec/specs/bookkeeping-investeringsaftrek/spec.md` IS already on `development` with twelve `### REQ-INV-NNN:` headed requirements (REQ-INV-001 categorisation, REQ-INV-002 Energielijst/Milieulijst version pinning, REQ-INV-003 thresholds, REQ-INV-004 cumulation, REQ-INV-005 KIA tier calculation, REQ-INV-006 KIA 2026 tier table, REQ-INV-007 RvO aanvraag, REQ-INV-008 jaaraangifte, REQ-INV-009 vrijwillige verlaging, REQ-INV-010 desinvesteringsbijtelling, REQ-INV-011 ex-ante calculator, REQ-INV-012 audit trail + RvO archief) — the precursor scope is a strict superset of the umbrella's REQ-INV-001..007. (c) Three yearly tarieven seeds ALREADY EXIST under `lib/Settings/seeds/`: `investeringsaftrek-energielijst-2026.json`, `investeringsaftrek-milieulijst-2026.json`, `investeringsaftrek-kia-tiers-2026.json` (the umbrella's `investeringsaftrek-tarieven-2026.json` is a single-file alternative; the precursor split-by-regime shape is the canonical one because each lijst is independently RvO-versioned and the umbrella's monolithic-seed proposal is suboptimal). (d) The KIA-schalen lookup ADR-031 exception path IS implemented at `lib/Guard/KiaSchalenLookup.php` (the umbrella's anticipated single-method PHP guard) — confirms the calculation engine cannot natively express tiered-threshold lookup against a year-versioned seed and the seam is legitimate per ADR-031. (e) The ADR-037 fragment loader at `lib/Service/SettingsService.php` L1103-1136 auto-merges `register.d/*.json` fragments and folds the fragment signature into the seeded register version so OpenRegister's version-gated `importFromApp` re-imports on every fragment change — the umbrella's "Extend the repair step" Task 7 is satisfied implicitly by this loader plus the existing `lib/Repair/InitializeSettings.php` repair-step entry. (f) No `lib/Service/RvoClient.php` exists — `find lib/Service -iname '*Rvo*'` returns zero hits — confirming the REQ-INV-006 no-app-local-HTTP-client constraint is held (RvO calls ride openconnector per ADR-019). (g) `src/manifest.json` does NOT yet carry investeringsaftrek navigation entries (no `InvestmentAsset` / `Investeringsaftrek` `id` in the pages list) — the precursor's twelve requirements ship the data layer + classification + guard but the navigation surface is the one umbrella concern still pending (`openspec validate` does not gate on manifest entries because the manifest is implementation-level). (h) `openspec/architecture/adr-000-data-model.md` does NOT yet carry an `### InvestmentAsset` entry — the precursor PR did not roll the ADR-000 annotation; this is the second umbrella concern still pending. The data-layer / classification / claim / lookup / Vamil-depreciation / KIA-guard / seed-import surface for the investeringsaftrek capability is therefore ALREADY LANDED on `development` end-to-end — schemas + seeds + KIA guard + fragment auto-loader + capability spec — and the umbrella's residual concerns (manifest navigation + ADR-000 annotation) are downstream implementation-cycle work that can land additively against the precursor's authoritative shape. The T2 umbrella has effectively been delivered by the foundation precursor PR #299 and the per-regime per-tier consumers will attach as they roll.
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
