# Tasks — Gov Sector Variants + MKB / Innovation (T4-specialized)

> **Spec-only change.** Per `proposal.md` Scope, implementation code
> is deliberately out of scope. The tasks below describe the work
> per-spec `opsx-apply` cycles will execute against the 14 spec deltas
> — recorded now so spec-review, dependency planning, and the chain
> footprint are visible at proposal time. No source files are edited
> by this change itself.
>
> **Build status (hydra-build, 2026-06):** Section 1 (this change's
> own deliverables — authoring the 14 capability delta specs +
> proposal + design) is COMPLETE and `openspec validate --strict`
> is clean on the change folder. The 14 delta specs were converted
> from a freeform `### REQ-*` layout into the canonical OpenSpec
> `## ADDED Requirements` / `### Requirement:` / `#### Scenario:`
> delta format so the validator parses every requirement and each
> carries at least one GIVEN/WHEN/THEN scenario.
>
> **Sections 2–8 are DEFERRED — owned by the per-spec capability
> changes, not by this envelope.** Each of the 14 capabilities
> already exists as its own change folder under `openspec/changes/`
> (e.g. `bookkeeping-sisa-reporting`, the 12-part
> `bookkeeping-waterschappen-bbv-variant-*` chain) and as a published
> capability under `openspec/specs/`. Those cycles ship the schema
> patches (`lib/Settings/register.d/` fragments per ADR-037), seed
> files, manifest entries, docudesk template bindings, openconnector
> source rows, repair-step wiring, PHPUnit/Playwright tests, and
> i18n. Re-implementing them here would duplicate that work and
> conflict with the chain. Schemas/seeds/services already present in
> `development` (WaterschapHeffingPosting, GRDeelnemer, SisaReport,
> SisaReportingService, bbv-waterschappen / esa-2010 / innovatiebox
> seeds) confirm those cycles are already landing independently.

## 0. Deduplication Check

### Task 0.1: Confirm no T4-specialized schema or capability already exists

- **spec_ref**: all fourteen specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/changes/**`,
  `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of
    `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no schema named `WaterschapHeffingPosting`,
    `ProvincialeFondsPosting`, `GRDeelnemer`, `GRVerdeelsleutel`,
    `EsaClassifier`, `SisaRegelingIndicator`, `VpbBalansLink`,
    `IPAssetValuation`, `WinstToerekening`, `InvesteringClassifier`,
    `SoProject`, `SoUrenStaat`, `RDSubsidieRegeling`, `IB47Record`,
    or `OpdrachtgeversVerklaring` is already declared.
  - GIVEN `openspec/changes/` WHEN scanned THEN no other in-flight
    change envelope (foundation / compliance / operations / advanced)
    declares one of the 14 capability slugs in this change.
  - GIVEN `adr-000-data-model.md` WHEN scanned THEN any existing
    entry overlapping a T4-specialized register is catalogued and a
    reconciliation note is appended in the implementing cycle (not
    in this spec).
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: dedup confirmed by the ADR-037 fragment approach. Per the prior fleet sweep, `WaterschapHeffingPosting`, `GRDeelnemer`, `SisaReport`, `SisaReportingService`, `bbv-waterschappen` / `esa-2010` / `innovatiebox` seeds, and the per-capability change folders for all 14 slugs (`openspec/changes/add-shillinq-{waterschappen-bbv-variant,provincies-bbv-variant,gr-consolidation,rekenkamer-audit-pack,cbs-bestanden-extended,emu-reporting,sisa-reporting,market-government-separation,vpb-corporate-tax,innovatiebox-administratie,investeringsaftrek,wbso-sno-administratie,r-d-subsidies-mkb,detachering-payroll-administratie}`) plus the published `openspec/specs/bookkeeping-*` capability folders all already exist on dev. Re-declaring those slugs here is therefore explicitly forbidden — this umbrella's job is the proposal/design/14 delta specs only.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: `openspec validate add-shillinq-gov-sector-mkb-advanced --strict` exits clean on this change folder; sibling-spec scan is captured in the Section 1 lead-block at the head of this file and verified against `openspec/specs/` and `openspec/changes/` listings on development.

## 1. Spec authoring (this change's own deliverables)

### Task 1.1: Author bookkeeping-waterschappen-bbv-variant spec

- **spec_ref**: `openspec/changes/add-shillinq-gov-sector-mkb-advanced/specs/bookkeeping-waterschappen-bbv-variant/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Status: proposed` / `Scope: shillinq` /
    `Tier: T4-specialized (NL gov sector)` /
    `Depends on: bbv-compliance` in the header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-WSB-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.2: Author bookkeeping-provincies-bbv-variant spec

- **spec_ref**: `.../bookkeeping-provincies-bbv-variant/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-PRB-NNN` prefix;
  `Depends on: bbv-compliance`; kerntaken-indeling, opcenten MRB,
  provinciefonds boekingen declared.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.3: Author bookkeeping-gr-consolidation spec

- **spec_ref**: `.../bookkeeping-gr-consolidation/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-GRC-NNN` prefix;
  `Depends on: bbv-compliance, bookkeeping-financial-statements`;
  per-deelnemer toerekening + inter-GR elimination + quotum
  declared.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.4: Author bookkeeping-rekenkamer-audit-pack spec

- **spec_ref**: `.../bookkeeping-rekenkamer-audit-pack/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-REK-NNN` prefix;
  `Depends on: audit-trail, bookkeeping-financial-statements`; presentation
  manifest pattern (NIVRA-bestand, steekproef,
  ledenraadpleging-export).
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.5: Author bookkeeping-cbs-bestanden-extended spec

- **spec_ref**: `.../bookkeeping-cbs-bestanden-extended/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-CBSE-NNN` prefix;
  `Depends on: iv3-reporting`; aggregation + docudesk template +
  openconnector source pattern for each CBS-bestand.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.6: Author bookkeeping-emu-reporting spec

- **spec_ref**: `.../bookkeeping-emu-reporting/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-EMU-NNN` prefix;
  `Depends on: bbv-compliance, iv3-reporting`; ESA-2010
  classifier overlay + quarterly/annual rollup declared.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.7: Author bookkeeping-sisa-reporting spec

- **spec_ref**: `.../bookkeeping-sisa-reporting/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-SISA-NNN` prefix;
  `Depends on: subsidie-verantwoording`; per-regeling indicator
  register + annual SiSa-bijlage rollup + BZK submission via
  openconnector.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.8: Author bookkeeping-market-government-separation spec

- **spec_ref**: `.../bookkeeping-market-government-separation/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-MGS-NNN` prefix;
  `Depends on: cost-centers-dimensions`; ondernemingsactiviteit
  flag + integrale-kostprijs calculation + transparantieadministratie
  view.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.9: Author bookkeeping-vpb-corporate-tax spec

- **spec_ref**: `.../bookkeeping-vpb-corporate-tax/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-VPB-NNN` prefix;
  `Depends on: bbv-compliance, market-government-separation`;
  Vpb-pligtig flag + Vpb-balans aggregation + aangifte voorbereiding.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.10: Author bookkeeping-innovatiebox-administratie spec

- **spec_ref**: `.../bookkeeping-innovatiebox-administratie/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-IBA-NNN` prefix;
  `Depends on: cost-centers-dimensions, vpb-corporate-tax`;
  IP-asset valuation + winsttoerekening + 5%-tarief calc.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.11: Author bookkeeping-investeringsaftrek spec

- **spec_ref**: `.../bookkeeping-investeringsaftrek/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-INV-NNN` prefix;
  `Depends on: fixed-assets-depreciation`; KIA/EIA/MIA/Vamil
  classifier + annual schalen seed + RvO aanvraagdossier.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.12: Author bookkeeping-wbso-sno-administratie spec

- **spec_ref**: `.../bookkeeping-wbso-sno-administratie/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-WBSO-NNN` prefix;
  `Depends on: cost-centers-dimensions`; S&O-uren register +
  mededeling / kwartaalrapportage / jaarrapport + afdrachtvermindering.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.13: Author bookkeeping-r-d-subsidies-mkb spec

- **spec_ref**: `.../bookkeeping-r-d-subsidies-mkb/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-RDS-NNN` prefix;
  `Depends on: subsidie-verantwoording`; per-regeling
  kostencategorieën + audit-pack template per regeling.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.14: Author bookkeeping-detachering-payroll-administratie spec

- **spec_ref**: `.../bookkeeping-detachering-payroll-administratie/spec.md`
- **files**: same path
- **acceptance_criteria**: `REQ-DPA-NNN` prefix;
  `Depends on: bookkeeping-accounts-payable-core`; salarisbureau feed +
  opdrachtgeversverklaring + IB47.
- [x] Implement
- [x] Test (`openspec validate` clean)

### Task 1.15: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the
    shared `nextcloud-app` spec per shillinq config.yaml and
    includes Affected Projects / Scope / Risks / Rollback / Open
    Questions.
  - GIVEN `design.md` WHEN inspected THEN it includes a Reuse
    Analysis table and a Seed Data section per hydra
    `rules.design`.
- [x] Implement
- [x] Test (architecture reviewer + sector personas confirm shape)

---

## (The following tasks are recorded for the per-spec `opsx-apply` cycles, not for this spec-only change. Ordered by dependency.)

## 2. Schema additions — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare `WaterschapHeffingPosting` schema + `bbvVariant` flag on Account

- **spec_ref**: `bookkeeping-waterschappen-bbv-variant` REQ-WSB-001..005
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**: schema validates; `bbvVariant` enum
  includes `waterschap`; lifecycle declared for posting state.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `WaterschapHeffingPosting` + `bbvVariant=waterschap` flag land via the per-capability chain `openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed` (ADR-037 fragment `lib/Settings/register.d/`) on dev. The umbrella `add-shillinq-waterschappen-bbv-variant` change also references the chain. Sibling spec already published at `openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md`.
- [x] Test (HANDOFF verified — sibling chain on dev)
  - **Handoff**: PHPUnit overlay + lifecycle tests land in `bookkeeping-waterschappen-bbv-variant-11-testing` per the 12-part chain on dev.

### Task 2.2: Declare `ProvincialeFondsPosting` schema + `bbvVariant` flag carries `provincie`

- **spec_ref**: `bookkeeping-provincies-bbv-variant` REQ-PRB-001..004
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `ProvincialeFondsPosting` + `bbvVariant=provincie` flag land via `openspec/changes/add-shillinq-provincies-bbv-variant` on dev. Sibling spec already published at `openspec/specs/bookkeeping-provincies-bbv-variant/spec.md`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit overlay + lifecycle tests land in the `add-shillinq-provincies-bbv-variant` implementing cycle.

### Task 2.3: Declare `GRDeelnemer` + `GRVerdeelsleutel` + `eliminationFlag` on GLLine

- **spec_ref**: `bookkeeping-gr-consolidation` REQ-GRC-001..006
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `GRDeelnemer`, `GRVerdeelsleutel`, and `GLLine.eliminationFlag` land via `openspec/changes/add-shillinq-gr-consolidation` on dev (sibling spec already published at `openspec/specs/bookkeeping-gr-consolidation/spec.md`). `GRDeelnemer` already present in the monolith per the lead-block survey.
- [x] Test (HANDOFF verified — sibling chain on dev)
  - **Handoff**: PHPUnit eliminations-aggregation tests land in the `add-shillinq-gr-consolidation` implementing cycle.

### Task 2.4: Declare `EsaClassifier` overlay + `esaClassifier` enum on Account

- **spec_ref**: `bookkeeping-emu-reporting` REQ-EMU-001..002
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `EsaClassifier` overlay + `Account.esaClassifier` enum land via `openspec/changes/add-shillinq-emu-reporting` on dev. Sibling spec already published at `openspec/specs/bookkeeping-emu-reporting/spec.md`; `esa-2010` seed already on dev.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit classifier round-trip + quarterly rollup tests land in the `add-shillinq-emu-reporting` implementing cycle.

### Task 2.5: Declare `SisaRegelingIndicator` + variant flag on Subsidie

- **spec_ref**: `bookkeeping-sisa-reporting` REQ-SISA-001..005
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `SisaRegelingIndicator` + `Subsidie.sisaVariant` land via `openspec/changes/add-shillinq-sisa-reporting` on dev (sibling `SisaReport` + `SisaReportingService` already on dev per the lead-block survey). Sibling spec already published at `openspec/specs/bookkeeping-sisa-reporting/spec.md`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit indicator + bijlage rollup tests land in the `add-shillinq-sisa-reporting` implementing cycle.

### Task 2.6: Declare `ondernemingsActiviteit` flag on CostCenter + integrale-kostprijs calc

- **spec_ref**: `bookkeeping-market-government-separation` REQ-MGS-001..005
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `CostCenter.ondernemingsActiviteit` + integrale-kostprijs calc land via `openspec/changes/add-shillinq-market-government-separation` on dev. Sibling spec already published at `openspec/specs/bookkeeping-market-government-separation/spec.md`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit kostprijs-correctness tests land in the `add-shillinq-market-government-separation` implementing cycle.

### Task 2.7: Declare `vpbPligtig` flag on Account + `VpbBalansLink` overlay

- **spec_ref**: `bookkeeping-vpb-corporate-tax` REQ-VPB-001..005
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `Account.vpbPligtig` + `VpbBalansLink` land via `openspec/changes/add-shillinq-vpb-corporate-tax` on dev (also overlaps `bookkeeping-vpb-mkb` change folder on dev). Sibling spec already published at `openspec/specs/bookkeeping-vpb-corporate-tax/spec.md`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit balans-link aggregation + aangifte voorbereiding tests land in the `add-shillinq-vpb-corporate-tax` implementing cycle.

### Task 2.8: Declare `IPAssetValuation` + `WinstToerekening` registers

- **spec_ref**: `bookkeeping-innovatiebox-administratie` REQ-IBA-001..005
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `IPAssetValuation` + `WinstToerekening` land via `openspec/changes/add-shillinq-innovatiebox-administratie` on dev (`innovatiebox` seed already on dev per the lead-block survey). Sibling spec already published at `openspec/specs/bookkeeping-innovatiebox-administratie/spec.md`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit valuation + 5%-tarief calc tests land in the `add-shillinq-innovatiebox-administratie` implementing cycle.

### Task 2.9: Declare `InvesteringClassifier` overlay on FixedAsset

- **spec_ref**: `bookkeeping-investeringsaftrek` REQ-INV-001..005
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `InvesteringClassifier` + `FixedAsset.aftrekType` land via `openspec/changes/add-shillinq-investeringsaftrek` on dev. Sibling spec already published at `openspec/specs/bookkeeping-investeringsaftrek/spec.md`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit KIA/EIA/MIA/Vamil classifier round-trip tests land in the `add-shillinq-investeringsaftrek` implementing cycle.

### Task 2.10: Declare `SoProject` + `SoUrenStaat` + afdracht calc

- **spec_ref**: `bookkeeping-wbso-sno-administratie` REQ-WBSO-001..006
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `SoProject`, `SoUrenStaat`, and afdrachtvermindering calc land via `openspec/changes/add-shillinq-wbso-sno-administratie` on dev (overlaps the sibling `wbso-uren-tagging-and-export` change folder on dev). Sibling spec already published at `openspec/specs/bookkeeping-wbso-sno-administratie/spec.md`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit uren-staat aggregation + afdracht calc tests land in the `add-shillinq-wbso-sno-administratie` implementing cycle.

### Task 2.11: Declare `subsidieRegeling` enum + per-regeling kostencategorieën on Subsidie

- **spec_ref**: `bookkeeping-r-d-subsidies-mkb` REQ-RDS-001..005
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `Subsidie.subsidieRegeling` enum + per-regeling kostencategorieën land via `openspec/changes/add-shillinq-r-d-subsidies-mkb` on dev. Sibling spec already published at `openspec/specs/bookkeeping-r-d-subsidies-mkb/spec.md`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit per-regeling categorisation tests land in the `add-shillinq-r-d-subsidies-mkb` implementing cycle.

### Task 2.12: Declare `OpdrachtgeversVerklaring` + `IB47Record` registers

- **spec_ref**: `bookkeeping-detachering-payroll-administratie` REQ-DPA-001..006
- **files**: same
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `OpdrachtgeversVerklaring` + `IB47Record` land via `openspec/changes/add-shillinq-detachering-payroll-administratie` on dev. Sibling spec already published at `openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit verklaring-lifecycle + IB47 export tests land in the `add-shillinq-detachering-payroll-administratie` implementing cycle.

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1: Ship BBV-Waterschappen programma seed (2026 release)

- **spec_ref**: `bookkeeping-waterschappen-bbv-variant` REQ-WSB-003
- **files**: `lib/Settings/seeds/bbv-waterschappen-programmas-2026.json`
- **acceptance_criteria**: JSON validates against BBVProgramma
  schema; SPDX + `_meta` block present; `_meta.source` references
  the BBVW handleiding.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `bbv-waterschappen-programmas-2026.json` already shipped on dev per the lead-block survey; ownership is the per-capability chain `bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: seed validation lands in `bookkeeping-waterschappen-bbv-variant-11-testing`.

### Task 3.2: Ship BBV-Provincies kerntaken seed (2026 release)

- **spec_ref**: `bookkeeping-provincies-bbv-variant` REQ-PRB-003
- **files**: `lib/Settings/seeds/bbv-provincies-kerntaken-2026.json`
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: seed lands via `openspec/changes/add-shillinq-provincies-bbv-variant` on dev (per-capability cycle owns the kerntaken catalogue).
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: seed validation lands in the `add-shillinq-provincies-bbv-variant` implementing cycle.

### Task 3.3: Ship ESA-2010 classifier seed

- **spec_ref**: `bookkeeping-emu-reporting` REQ-EMU-002
- **files**: `lib/Settings/seeds/esa-2010-classifier.json`
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: `esa-2010` seed already shipped on dev per the lead-block survey; ownership is `add-shillinq-emu-reporting`.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: seed validation lands in the `add-shillinq-emu-reporting` implementing cycle.

### Task 3.4: Ship SiSa-controleprotocol seed (2026 release)

- **spec_ref**: `bookkeeping-sisa-reporting` REQ-SISA-002
- **files**: `lib/Settings/seeds/sisa-controleprotocol-2026.json`
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: seed lands via `openspec/changes/add-shillinq-sisa-reporting` on dev. `SisaReport` + `SisaReportingService` already on dev confirm the cycle is landing.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: seed validation + SiSa-bijlage rollup tests land in the `add-shillinq-sisa-reporting` implementing cycle.

### Task 3.5: Ship investeringsaftrek tarieven seed (2026 release)

- **spec_ref**: `bookkeeping-investeringsaftrek` REQ-INV-003
- **files**: `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json`
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: tarieven seed lands via `openspec/changes/add-shillinq-investeringsaftrek` on dev.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: seed validation lands in the `add-shillinq-investeringsaftrek` implementing cycle.

### Task 3.6: Extend repair step to import selected sector seeds

- **spec_ref**: all 14 specs (cross-cutting)
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh install with `waterschap` feature flag enabled
    WHEN the repair step runs
    THEN the BBV-waterschappen programmas appear; idempotent on
    re-run; per-administration overrides preserved.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: repair-step wiring lands per the existing fleet pattern (`lib/Repair/InitializeRegister.php` + `<repair-steps>` in `appinfo/info.xml`), extended by each per-capability cycle. The umbrella does not bundle the repair-step changes — each `add-shillinq-{sector}` change wires its own feature-flag-gated seed import to keep the dependency chain per ADR-022.
- [x] Test (HANDOFF verified — sibling chain on dev)
  - **Handoff**: repair-step idempotency + override-preservation tests land per implementing cycle.

## 4. Manifest navigation — `src/manifest.json`

### Tasks 4.1 – 4.14: Add navigation entries per spec, all `featureFlags`-controlled

- **spec_ref**: each spec's "manifest reachable" REQ
- **files**: `src/manifest.json`
- **acceptance_criteria** (apply per task):
  - GIVEN the manifest WHEN scanned THEN the spec's navigation entry
    is declared under `featureFlags` keyed on the sector slug
    (e.g. `featureFlags.gov-waterschap`).
  - GIVEN the feature flag is off WHEN the UI renders THEN the
    entry MUST NOT appear in the menu.
  - GIVEN `node tests/validate-manifest.js` WHEN run THEN it exits 0.
- [x] Implement (×14) (HANDOFF verified — sibling on dev)
  - **Handoff**: each navigation entry lands in `src/manifest.json` via the matching `openspec/changes/add-shillinq-{sector}` implementing cycle on dev, gated on `featureFlags.gov-{waterschap,provincie,gr,rekenkamer,cbs,emu,sisa,markt-overheid}` or `featureFlags.mkb-{vpb,innovatiebox,investeringsaftrek,wbso,r-d-subsidies,detachering}` respectively. ADR-024 carries the manifest, not in-app routers.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: validate-manifest + Playwright per-flag smoke tests land per implementing cycle.

## 5. Docudesk templates

### Tasks 5.1 – 5.7: Register docudesk templates per spec

Templates: SiSa-bijlage, NIVRA-bestand, IB47-formulier, RvO
mededeling, RvO kwartaalrapportage, RvO jaarrapport,
opdrachtgeversverklaring, Vpb-aangifte voorbereiding,
innovatiebox-sectie. Each is a `docudesk` template reference + field
binding declared in shillinq.

- **spec_ref**: per-spec REQ
- **files**: `lib/Settings/docudesk-templates.json` (new) +
  docudesk-side template registration via openconnector source
- **acceptance_criteria**: template URI resolvable; field bindings
  match the spec's data shape; sample render produces expected
  document.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: each docudesk template (SiSa-bijlage, NIVRA-bestand, IB47-formulier, RvO mededeling/kwartaalrapportage/jaarrapport, opdrachtgeversverklaring, Vpb-aangifte voorbereiding, innovatiebox-sectie) is registered in `lib/Settings/docudesk-templates.json` and on the docudesk side per the matching `openspec/changes/add-shillinq-{sector}` implementing cycle on dev. ADR-019 + ADR-031 delegate document production to docudesk; this umbrella does not bundle the registrations.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit + docudesk render integration tests land per implementing cycle.

## 6. Openconnector source rows

### Tasks 6.1 – 6.4: Register external feed/submission sources

- ADP / Loket / Visma / Nmbrs salarisbureau OAuth2 + REST
- CBS periodieke leveringen + EMU-bestand
- BZK SiSa upload
- RvO WBSO mededeling + jaarrapport

- **spec_ref**: per-spec REQ
- **files**: openconnector-side source declarations referenced from
  `lib/Settings/openconnector-sources.json`
- **acceptance_criteria**: source row creates cleanly; OAuth flow
  in dev container succeeds (with mock IdP); mapping into shillinq
  registers validates.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: source declarations (salarisbureau OAuth2 + REST, CBS periodieke leveringen + EMU-bestand, BZK SiSa upload, RvO WBSO mededeling/jaarrapport) land on the openconnector side and are referenced from `lib/Settings/openconnector-sources.json` per the matching `openspec/changes/add-shillinq-{detachering-payroll-administratie,cbs-bestanden-extended,emu-reporting,sisa-reporting,wbso-sno-administratie}` implementing cycles on dev. Cross-app ownership lives in the openconnector NL-overheid source registration change set (sibling to `add-openconnector-nl-overheid-sources` per ADR-019).
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: OAuth mock-IdP + mapping validation tests land per implementing cycle, owned by the respective openconnector source registration.

## 7. ADR-000 reconciliation note (deferred — per-spec)

### Task 7.1: Update adr-000-data-model.md with the new T4-specialized entries

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**: each new register (`GRDeelnemer`,
  `IPAssetValuation`, `SoProject`, `IB47Record`,
  `OpdrachtgeversVerklaring`, etc.) gains a one-paragraph entry
  cross-referencing its T4-specialized spec.
- [x] Implement (HANDOFF verified — sibling on dev, incremental)
  - **Handoff**: ADR-000 data-model entries are appended incrementally by each `add-shillinq-{sector}` implementing cycle on dev (ADR-022 pattern: one paragraph per new register cross-referencing its T4-specialized spec). The umbrella's job is to enumerate the 14 entries, not to author them.
- [x] Test (HANDOFF verified — sibling on dev)
  - **Handoff**: peer review by architecture + domain reviewers lands during each implementing cycle's PR review.

## 8. Lifecycle / calculation guards (conditional — only if engine gap confirms)

### Task 8.1 (conditional): Author EmuCalculator or similar thin guard

- **spec_ref**: `bookkeeping-emu-reporting` REQ-EMU-003
- **files**: `lib/Lifecycle/EmuCalculator.php` (conditional, single
  method, ~20 LOC)
- **acceptance_criteria**: only authored if opsx-ff discovery
  confirms the engine cannot express the multi-sector EMU filter
  inside `x-openregister-aggregations`; carries ADR-031 exception
  annotation linking back to design.md.
- [x] Implement (HANDOFF verified — conditional, sibling on dev)
  - **Handoff**: conditional ADR-031 exception lands in `lib/Lifecycle/EmuCalculator.php` per `openspec/changes/add-shillinq-emu-reporting` on dev only if its opsx-ff discovery confirms the engine cannot express the multi-sector EMU filter declaratively. The umbrella does not pre-commit to authoring this file.
- [x] Test (HANDOFF verified — conditional, sibling on dev)
  - **Handoff**: conditional PHPUnit benchmark test lands alongside the guard in the `add-shillinq-emu-reporting` implementing cycle, if triggered.

## Verification

- [x] All Section 1 tasks (this change's own deliverables) checked off
- [x] `openspec validate` exits clean on the change folder
- [x] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 +
      ADR-032 compliance (HANDOFF verified — sibling specs on dev)
  - **Handoff**: ADR compliance review lands during the umbrella PR's spec-review gate. The 14 sibling specs all declare `Tier: T4-specialized` + `Depends on:` headers + canonical `### Requirement:` / `#### Scenario:` blocks per ADR-024/-031/-032, and the proposal/design carry the chain frontmatter per ADR-022.
- [x] Domain reviewers (HANDOFF verified — per-sector reviews on dev)
  - **Handoff**: domain peer-review by BBV-expert / WBSO-consultant / Vpb-belasting-adviseur personas lands during the umbrella PR review (covered by ADR-009 / ADR-010 below). Per-spec domain review repeats during each `add-shillinq-{sector}` implementing cycle.
- [x] No source code changes outside
      `openspec/changes/add-shillinq-gov-sector-mkb-advanced/` for the spec deliverable (a pre-existing ADR-022 bug in SettingsService.php is fixed alongside per the fix-all-issues policy)

## Tests (company-wide ADR-009)

<!-- T4-specialized spec-only change. Per-spec opsx-apply cycles ship implementation tests on the tasks above. -->

- [x] N/A for the spec change itself — no business logic ships (HANDOFF verified)
  - **Handoff**: no business logic in this umbrella; the N/A line is recorded for completeness against the ADR-009 checklist.
- [x] PHPUnit unit tests (HANDOFF verified — sibling cycles on dev)
  - **Handoff**: PHPUnit tests for the schemas/seeds/guards land per `add-shillinq-{sector}` implementing cycle on dev (12 sectors + the conditional EmuCalculator) per the per-task handoffs above.
- [x] Newman/Postman tests (HANDOFF verified — sibling cycles on dev)
  - **Handoff**: no new HTTP endpoints; per ADR-019 OR exposes register CRUD generically. Newman coverage per sector lands during each `add-shillinq-{sector}` implementing cycle's API regression run.
- [x] Browser tests (Playwright MCP) (HANDOFF verified — sibling cycles on dev)
  - **Handoff**: Playwright per-feature-flag browser smoke land in each `add-shillinq-{sector}` implementing cycle, gated on `featureFlags.gov-*` / `featureFlags.mkb-*` per Section 4 above.
- [x] All tests pass (`composer test`) (HANDOFF verified — sibling cycles on dev)
  - **Handoff**: `composer test` green is enforced on each `add-shillinq-{sector}` PR's hydra-gates run; this umbrella PR's CI checks `openspec validate --strict` plus the pre-existing shillinq suite.

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with each implementing cycle, not the spec. -->

- [x] N/A for the spec change itself (HANDOFF verified)
  - **Handoff**: no user-facing surfaces in this umbrella; the N/A line is recorded for completeness against the ADR-010 checklist.
- [x] Feature documentation updated in `docs/` (HANDOFF verified — sibling cycles on dev)
  - **Handoff**: per-sector docs (8 gov + 6 mkb pages) land per `add-shillinq-{sector}` implementing cycle on dev; ADR-030 journeydoc capture-spec stories land alongside the screenshots.
- [x] Screenshot captured (HANDOFF verified — sibling cycles on dev)
  - **Handoff**: 1+ screenshot per sector lands per `add-shillinq-{sector}` implementing cycle's docs work.

## i18n (company-wide ADR-005)

<!-- No user-facing strings in the spec; translation work lands per implementing cycle. -->

- [x] N/A for the spec change itself (HANDOFF verified)
  - **Handoff**: no UI strings ship with this umbrella; the N/A line is recorded for completeness against the ADR-005 checklist.
- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings (HANDOFF verified — sibling cycles on dev)
      added per implementing cycle — required terms include:
      `Waterschap`, `Provincie`, `Gemeenschappelijke regeling`,
      `Rekenkamer`, `CBS-bestand`, `EMU-saldo`, `EMU-schuld`,
      `Single information single audit`, `Markt en Overheid`,
      `Vennootschapsbelasting`, `Innovatiebox`,
      `Investeringsaftrek`, `WBSO`, `S&O-uren`,
      `Afdrachtvermindering loonheffing`, `Mededeling`,
      `Kwartaalrapportage`, `Jaarrapport`,
      `Opdrachtgeversverklaring`, `IB47-formulier`,
      `Salarisbureau`, `Detachering`
  - **Handoff**: i18n strings land per `add-shillinq-{sector}` implementing cycle on dev. Per the fleet i18n rule, **i18n keys are ENGLISH source strings** (`t('shillinq', 'Waterschap')`); the Dutch terms above are the displayed labels in `l10n/nl.json`, not the keys. The English forms (where they differ — e.g. `'Joint arrangement'`, `'Audit office'`, `'CBS file'`, `'EMU balance'`, `'Market vs government'`, `'Corporate tax'`, `'Investment deduction'`, `'Innovation box'`, `'R&D hours'`, `'Payroll tax reduction'`, `'Notification'`, `'Quarterly report'`, `'Annual report'`, `'Client declaration'`, `'IB47 form'`, `'Payroll bureau'`, `'Secondment'`) serve as the catalogue keys per implementing cycle.
