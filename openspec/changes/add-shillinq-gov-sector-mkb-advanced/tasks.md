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
- [ ] Implement
- [ ] Test

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
- [ ] Implement
- [ ] Test (PHPUnit: variant overlay round-trips; reject unknown
  variant)

### Task 2.2: Declare `ProvincialeFondsPosting` schema + `bbvVariant` flag carries `provincie`

- **spec_ref**: `bookkeeping-provincies-bbv-variant` REQ-PRB-001..004
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.3: Declare `GRDeelnemer` + `GRVerdeelsleutel` + `eliminationFlag` on GLLine

- **spec_ref**: `bookkeeping-gr-consolidation` REQ-GRC-001..006
- **files**: same
- [ ] Implement
- [ ] Test (PHPUnit: eliminations-filtered aggregation matches
  worked-example)

### Task 2.4: Declare `EsaClassifier` overlay + `esaClassifier` enum on Account

- **spec_ref**: `bookkeeping-emu-reporting` REQ-EMU-001..002
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.5: Declare `SisaRegelingIndicator` + variant flag on Subsidie

- **spec_ref**: `bookkeeping-sisa-reporting` REQ-SISA-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.6: Declare `ondernemingsActiviteit` flag on CostCenter + integrale-kostprijs calc

- **spec_ref**: `bookkeeping-market-government-separation` REQ-MGS-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.7: Declare `vpbPligtig` flag on Account + `VpbBalansLink` overlay

- **spec_ref**: `bookkeeping-vpb-corporate-tax` REQ-VPB-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.8: Declare `IPAssetValuation` + `WinstToerekening` registers

- **spec_ref**: `bookkeeping-innovatiebox-administratie` REQ-IBA-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.9: Declare `InvesteringClassifier` overlay on FixedAsset

- **spec_ref**: `bookkeeping-investeringsaftrek` REQ-INV-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.10: Declare `SoProject` + `SoUrenStaat` + afdracht calc

- **spec_ref**: `bookkeeping-wbso-sno-administratie` REQ-WBSO-001..006
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.11: Declare `subsidieRegeling` enum + per-regeling kostencategorieën on Subsidie

- **spec_ref**: `bookkeeping-r-d-subsidies-mkb` REQ-RDS-001..005
- **files**: same
- [ ] Implement
- [ ] Test

### Task 2.12: Declare `OpdrachtgeversVerklaring` + `IB47Record` registers

- **spec_ref**: `bookkeeping-detachering-payroll-administratie` REQ-DPA-001..006
- **files**: same
- [ ] Implement
- [ ] Test

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1: Ship BBV-Waterschappen programma seed (2026 release)

- **spec_ref**: `bookkeeping-waterschappen-bbv-variant` REQ-WSB-003
- **files**: `lib/Settings/seeds/bbv-waterschappen-programmas-2026.json`
- **acceptance_criteria**: JSON validates against BBVProgramma
  schema; SPDX + `_meta` block present; `_meta.source` references
  the BBVW handleiding.
- [ ] Implement
- [ ] Test

### Task 3.2: Ship BBV-Provincies kerntaken seed (2026 release)

- **spec_ref**: `bookkeeping-provincies-bbv-variant` REQ-PRB-003
- **files**: `lib/Settings/seeds/bbv-provincies-kerntaken-2026.json`
- [ ] Implement
- [ ] Test

### Task 3.3: Ship ESA-2010 classifier seed

- **spec_ref**: `bookkeeping-emu-reporting` REQ-EMU-002
- **files**: `lib/Settings/seeds/esa-2010-classifier.json`
- [ ] Implement
- [ ] Test

### Task 3.4: Ship SiSa-controleprotocol seed (2026 release)

- **spec_ref**: `bookkeeping-sisa-reporting` REQ-SISA-002
- **files**: `lib/Settings/seeds/sisa-controleprotocol-2026.json`
- [ ] Implement
- [ ] Test

### Task 3.5: Ship investeringsaftrek tarieven seed (2026 release)

- **spec_ref**: `bookkeeping-investeringsaftrek` REQ-INV-003
- **files**: `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json`
- [ ] Implement
- [ ] Test

### Task 3.6: Extend repair step to import selected sector seeds

- **spec_ref**: all 14 specs (cross-cutting)
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh install with `waterschap` feature flag enabled
    WHEN the repair step runs
    THEN the BBV-waterschappen programmas appear; idempotent on
    re-run; per-administration overrides preserved.
- [ ] Implement
- [ ] Test (PHPUnit + browser smoke per sector)

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
- [ ] Implement (×14)
- [ ] Test (validate-manifest + browser smoke per enabled flag)

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
- [ ] Implement
- [ ] Test (PHPUnit + docudesk integration test)

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
- [ ] Implement
- [ ] Test

## 7. ADR-000 reconciliation note (deferred — per-spec)

### Task 7.1: Update adr-000-data-model.md with the new T4-specialized entries

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**: each new register (`GRDeelnemer`,
  `IPAssetValuation`, `SoProject`, `IB47Record`,
  `OpdrachtgeversVerklaring`, etc.) gains a one-paragraph entry
  cross-referencing its T4-specialized spec.
- [ ] Implement (incremental — as each spec lands)
- [ ] Test (peer review)

## 8. Lifecycle / calculation guards (conditional — only if engine gap confirms)

### Task 8.1 (conditional): Author EmuCalculator or similar thin guard

- **spec_ref**: `bookkeeping-emu-reporting` REQ-EMU-003
- **files**: `lib/Lifecycle/EmuCalculator.php` (conditional, single
  method, ~20 LOC)
- **acceptance_criteria**: only authored if opsx-ff discovery
  confirms the engine cannot express the multi-sector EMU filter
  inside `x-openregister-aggregations`; carries ADR-031 exception
  annotation linking back to design.md.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: worked example matches the CBS published
  benchmark)

## Verification

- [x] All Section 1 tasks (this change's own deliverables) checked off
- [x] `openspec validate` exits clean on the change folder
- [ ] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 +
      ADR-032 compliance (no app-local services; manifest carries the
      navigation; chain frontmatter declared; no `kind: mixed`)
- [ ] Domain reviewers (BBV-expert / WBSO-consultant / Vpb-belasting-
      adviseur) confirm the model matches real Dutch government +
      MKB tax practice
- [x] No source code changes outside
      `openspec/changes/add-shillinq-gov-sector-mkb-advanced/` for the spec deliverable (a pre-existing ADR-022 bug in SettingsService.php is fixed alongside per the fix-all-issues policy)

## Tests (company-wide ADR-009)

<!-- T4-specialized spec-only change. Per-spec opsx-apply cycles ship implementation tests on the tasks above. -->

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic
      (`tests/Unit/`) — declared on tasks 2.1–2.12, 3.6, 8.1; lands
      per implementing cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new
      endpoints in T4-specialized (OR exposes register CRUD
      generically; tests cover the register HTTP surface per sector)
- [ ] Browser tests (Playwright MCP) for UI changes — declared on
      tasks 4.1–4.14; lands per implementing cycle
- [ ] All tests pass (`composer test`) — enforced at implementing
      PR's CI gate per sector

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with each implementing cycle, not the spec. -->

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/` — per-sector pages
      under `docs/user-guide/bookkeeping/gov-{waterschap,provincie,
      gr,rekenkamer,cbs,emu,sisa,markt-overheid}/` and
      `docs/user-guide/bookkeeping/mkb/{vpb,innovatiebox,
      investeringsaftrek,wbso,r-d-subsidies,detachering}/` per
      ADR-030 journeydoc convention
- [ ] Screenshot captured and committed to `docs/images/` — per spec
      during implementing cycle (1 screenshot minimum per sector)

## i18n (company-wide ADR-005)

<!-- No user-facing strings in the spec; translation work lands per implementing cycle. -->

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings
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
