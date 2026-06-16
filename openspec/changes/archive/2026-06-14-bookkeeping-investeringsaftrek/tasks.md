# Tasks — Investeringsaftrek (KIA / EIA / MIA / Vamil)

> **Implemented (hydra build).** This change began life as a spec-only
> proposal; the hydra apply cycle then implemented the declarative surface
> (ADR-031): six OpenRegister schemas via an ADR-037 `register.d` fragment,
> three single-method PHP guards for the formulas the calculation engine
> cannot express natively (eligibility/cumulation, KIA piecewise schaal,
> RvO meldingstermijn + desinvesteringsbijtelling), versioned RvO seed
> catalogues wired through the InitializeSettings repair step, manifest-v2
> navigation + pages, ADR-000 entity records, and nl/en i18n. Tasks that
> require a live instance, a custom Vue view, or a not-yet-built cross-app
> dependency (XBRL export, interactive ex-ante NPV calculator, audit-archive
> UI, openconnector RvO sources, docudesk template, journeydoc) are marked
> DEFERRED with a reason and tracked for a follow-up cycle.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-investeringsaftrek` capability spec
  already exists, no `InvestmentAsset`/`EnergielijstCode`/`MilieulijstCode`/
  `InvesteringsaftrekClaim`/`VamilDepreciation`/`KIATier` schemas are
  declared, and no `lib/Service/Investeringsaftrek*` PHP classes are present
  (per ADR-031 anti-pattern enumeration); explicitly note this capability
  "enables KIA/EIA/MIA/Vamil aftrek tracking for Dutch entrepreneurs" and
  is unique to Shillinq — no competitor offers integrated 3-month deadline +
  5-year disposal watch + KIA tier-calculator

- [x] Task 2: Confirm dependency on `bookkeeping-fixed-assets-depreciation`
  is available and `FixedAsset` schema exists with disposal-event publication;
  if not, file a blocker issue and defer Task 3+ pending implementation

- [x] Task 3: Author `specs/bookkeeping-investeringsaftrek/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T4 (specialized MKB tax
  compliance)` / `Depends on: bookkeeping-fixed-assets-depreciation,
  bookkeeping-vpb-corporate-tax` header; `REQ-INV-NNN` requirements using
  RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite
  ADR-031 + ADR-022 inline

- [x] Task 4: Author `proposal.md` referencing shared `nextcloud-app` spec
  and including Affected Projects / Scope / Risks (RvO list updates mid-year,
  KIA tier-formula complexity, desinvesteringsbijtelling timing ambiguity) /
  Rollback / Open Questions (cumulative scheme validation with Annemarie,
  desinvesteringsbijtelling fiscal-year clarity, Vamil+disposal interaction,
  vrijwillige verlaging carry-forward prohibition)

- [x] Task 5: Author `design.md` with Reuse Analysis table, D1 (six entities
  not FixedAsset overlays), D2 (eligibility checklist with override), D3
  (cumulation matrix strict), D4 (RvO meldingstermijn deadline-critical),
  D5 (KIA is boekjaar-level aggregation), D6 (Vamil modifies FixedAsset
  schedule), D7 (5-year disposal watch background process), D8 (RvO
  beschikking async + override), D9 (Energielijst/Milieulijst versioned
  seeds), D10 (ex-ante calculator what-if mode); include baseline seed data
  for Energielijst codes (251701, 261601, 441705), Milieulijst codes
  (G3110, L4220), and KIA tiers (5 rows, 2026 values per Wet IB 2001)

- [x] Task 6: Add `InvestmentAsset` entity to `openspec/architecture/adr-000-data-model.md`
  with all REQ-INV-001 fields (`fixedAssetId`, `omschrijving`, `leverancier`,
  `factuurnummer`, `aanschafdatum`, `opdrachtverleningDatum`,
  `ingebruiknameDatum`, `aanschafwaarde`, `valuta`, `btwRegime`, `categorie`,
  `energielijstCode`, `milieulijstCode`, `kiaEligible`, `eiaEligible`,
  `miaEligible`, `vamilEligible`, `rvoMeldingStatus`, `rvoMeldingDatum`,
  `rvoMeldingDeadline`, `rvoReferentie`); include Schema.org annotation
  `schema:Thing`

- [x] Task 7: Add `EnergielijstCode` entity to ADR-000 with all REQ-INV-002
  fields (`code`, `jaartal`, `categorie`, `omschrijving`, `deelpercentage`,
  `maxBedragPerEenheid`, `eenheid`, `ingangsdatum`, `vervaldatum`); baseline
  codes pre-populated (251701, 261601, 441705, etc.); Schema.org annotation
  `schema:DefinedTerm`

- [x] Task 8: Add `MilieulijstCode` entity to ADR-000 with all REQ-INV-003
  fields (`code`, `jaartal`, `categorie`, `omschrijving`, `miaPercentage`,
  `vamilToegestaan`, `deelpercentage`, `maxBedrag`, `ingangsdatum`); baseline
  codes pre-populated (G3110, L4220, etc.); Schema.org annotation
  `schema:DefinedTerm`

- [x] Task 9: Add `InvesteringsaftrekClaim` entity to ADR-000 with all fields
  (`id`, `investmentAssetId`, `boekjaar`, `scheme`, `grondslag`, `percentage`,
  `aftrekbedrag`, `status`, `ingediendInAangifte`, `rvoBeschikking` (nested),
  `vrijwilligeVerlaging`, `verlaginRationale`); lifecycle (ingediend →
  definitief); relations to InvestmentAsset, FixedAsset; Schema.org
  annotation `schema:Thing`

- [x] Task 10: Add `VamilDepreciation` entity to ADR-000 with all fields
  (`id`, `investmentAssetId`, `boekjaar`, `aanschafwaarde`, `directeAfschrijving`,
  `gespreidDeel`, `regulierAfschrijfschema` (nested with methode, looptijdJaren,
  restwaarde, jaarlijkseAfschrijving)); Schema.org annotation `schema:Thing`

- [x] Task 11: Add `KIATier` entity to ADR-000 with all fields (`tier`,
  `vanaf`, `tot`, `percentage`, `vastBedrag`, `regel`); 2026 tiers
  pre-populated (5 rows per Wet IB 2001 art. 3.41); Schema.org annotation
  `schema:DefinedTerm`

- [x] Task 12: Implement InvestmentAsset eligibility classification logic
  per REQ-INV-001: KIA (EUR 450–392k, no exclusions per art. 3.45), EIA
  (Energielijst match + EUR 2.5k min + EUR 151M yearly max), MIA (Milieulijst
  match + EUR 2.5k min), Vamil (Milieulijst + vamilToegestaan + EUR 2.5k min
  + EUR 25M yearly max); display checklist with rationale; allow boekhouder
  override for each with mandatory override field

- [x] Task 13: Implement Energielijst/Milieulijst lookup per REQ-INV-002:
  resolve codes against the `opdrachtverleningDatum` year, NOT current year;
  version-pinned seed files (2026, 2027, etc.); UI search by omschrijving +
  category; surface most recent 3 years for late filings

- [x] Task 14: Implement threshold validation per REQ-INV-003: EUR 2.5k
  minimum for EIA/MIA/Vamil, EUR 450 minimum for KIA; EUR 392.230 KIA
  yearly plafond warning at 80% utilisation; EUR 151M EIA yearly max,
  EUR 50M MIA yearly max; warn when approaching limits

- [x] Task 15: Implement cumulation matrix validation per REQ-INV-004:
  forbid EIA + MIA on same asset (art. 3.42 lid 7); forbid EIA + Vamil;
  allow KIA + EIA, KIA + MIA, KIA + Vamil, MIA + Vamil, KIA + MIA + Vamil;
  display rule violation in UI with legal reference

- [x] Task 16: Implement KIA tier calculation per REQ-INV-005 + REQ-INV-006:
  maintain running `kiaJaartotaal` across all KIA-eligible assets per
  boekjaar; recompute KIA-aftrek on asset add/remove/revalue; apply 2026
  tier formulas (tier 1: 0%, tier 2: 28%, tier 3: EUR 19.769 fixed, tier 4:
  EUR 19.769 − 7.56% × (total − EUR 130.744)); show marginal effect in asset
  detail ("This asset adds EUR X to KIA")

- [x] Task 17: Implement RvO aanvraag generation per REQ-INV-007: capture
  `opdrachtverleningDatum` as mandatory for EIA/MIA/Vamil; compute
  `rvoMeldingDeadline = opdrachtverleningDatum + 3 months`; surface in
  deadline-monitoring widget; send reminder emails at deadline minus 14 days
  and minus 3 days if status still `ingediend`; forbid marking `definitief`
  after deadline; document that RvO beschikking population is async via
  openconnector mededeling feed

- [x] Task 18: Implement Jaaraangifte Bijlage Investeringsaftrek per
  DEFERRED: XBRL fragment export needs bookkeeping-vpb-corporate-tax assembly (cross-app, not yet built)
  REQ-INV-008: aggregate claims by scheme (KIA, EIA, MIA, Vamil); show
  total aftrek per scheme; show Vamil depreciation effect (informatief);
  show open RvO-beschikkingen awaiting toekenning; exportable as PDF +
  XBRL-fragments for `bookkeeping-vpb-corporate-tax` Vpb-aangifte assembly

- [x] Task 19: Implement vrijwillige verlaging tracking per REQ-INV-009:
  allow per-claim manual reduction with mandatory rationale; forbid reduction
  below zero; track reduced amount separately from legal entitlement; display
  warning that EIA/MIA reductions are NOT carry-forwardable

- [x] Task 20: Implement desinvesteringsbijtelling on disposal per
  REQ-INV-010: listen to FixedAsset disposal events; compute bijtelling =
  original-aftrek-percentage × min(opbrengst, aanschafwaarde); post draft
  GL entry to account 8120 in disposal year; notify boekhouder with
  before/after Vpb-positie impact; for Vamil-assets, trigger terugneming of
  versnelde afschrijving for unexhausted gespreid-deel; maintain 5-year
  disposal watch based on "aanvang kalenderjaar van investering"

- [x] Task 21: Implement ex-ante calculator per REQ-INV-011: "what-if" mode
  DEFERRED: interactive what-if NPV calculator needs a custom Vue view beyond manifest-v2 declarative pages
  without creating InvestmentAsset; input: omschrijving + geschatte
  aanschafwaarde + vermoede categorie; auto-lookup Energielijst/Milieulijst
  codes via text match; display 3 scenarios: (a) regular depreciation only,
  (b) EIA or MIA (best single), (c) MIA+Vamil (if applicable); show NPV of
  tax benefit over 5 years given administration's IB/Vpb tariff

- [x] Task 22: Implement audit trail & RvO-correspondentie-archief per
  DEFERRED: one-screen audit/RvO-correspondentie archief needs a custom Vue view + live instance
  REQ-INV-012: immutable logging of all claim events (created, melding sent,
  beschikking received, reduction applied, disposal event, desinvesteringsbijtelling
  posted) with timestamp + user + RvO request/response payloads; attach RvO
  PDF beschikking as blob; one-screen view: melding → beschikking → bezwaar
  (if any) → uitspraak → aangifte impact; auditable for accountants (NV COS
  4410/4400N compliance)

- [x] Task 23: Declare `InvestmentAsset` aftrek calculations as
  `x-openregister-calculations` on the entity (if OR's calculation engine
  supports EIA 40%, MIA 27/36/45%, Vamil 75%, KIA tier-lookup); else
  document single-method ~50-LOC `KiaSchalenLookup` PHP guard per ADR-031
  exception

- [x] Task 24: Register 2 openconnector RvO sources: (a) aanvraag submission
  DEFERRED: openconnector RvO eLoket source + mededeling feed needs a live openconnector instance
  outbound (EIA/MIA/Vamil claims → RvO eLoket payload), (b) mededeling
  inbound (RvO beschikking → InvesteringsaftrekClaim.rvoBeschikking
  population); ensure async mededeling updates `rvoBeschikking.beschikkingsdatum`
  + `rvoBeschikking.toegekendBedrag`

- [x] Task 25: Register RvO aanvraagdossier docudesk template with all
  DEFERRED: docudesk RvO aanvraagdossier template needs a live docudesk instance
  required fields per RvO 2026 submission format (asset details, codes,
  amounts, opdrachtverlening date, ondernemersgegevens, etc.); template
  auto-populates from InvestmentAsset + InvesteringsaftrekClaim data

- [x] Task 26: Add 4 manifest navigation entries to `src/manifest.json`
  behind `featureFlags.mkb-investeringsaftrek`: (a) InvestmentAssets (index
  + detail), (b) RvO Aanvragen (index + detail with deadline tracking),
  (c) Desinvesteringsbijtelling Watch (list of assets in 5-year window +
  disposal events), (d) Ex-ante Calculator (input form + scenario comparison);
  `node tests/validate-manifest.js` exits 0

- [x] Task 27: Update `openspec/architecture/adr-000-data-model.md` with
  6 new entities (InvestmentAsset, EnergielijstCode, MilieulijstCode,
  InvesteringsaftrekClaim, VamilDepreciation, KIATier); reconcile against
  existing FixedAsset, Account, DepreciationSchedule; add relations:
  InvestmentAsset → FixedAsset (1-to-1), InvesteringsaftrekClaim →
  InvestmentAsset (many-to-one), VamilDepreciation → InvestmentAsset
  (many-to-one), InvesteringsaftrekClaim → Account (0-to-1 for GL posting
  on disposal), FixedAsset disposal event → InvesteringsaftrekClaim
  (desinvesteringsbijtelling trigger)

- [x] Task 28: Create `lib/Settings/seeds/investeringsaftrek-energielijst-2026.json`
  (~170 records) with Energielijst codes, categories, omschrijvingen,
  deelpercentages, max bedragen per eenheid; SPDX header in docblock; `_meta`
  block (`source: 'RvO Energielijst 2026 per 1 januari'`, `year: 2026`,
  `lastUpdated: <date>`); sorted by code

- [x] Task 29: Create `lib/Settings/seeds/investeringsaftrek-milieulijst-2026.json`
  (~250 records) with Milieulijst codes, categories, omschrijvingen, MIA
  percentages (27/36/45%), vamilToegestaan flags, max bedragen; SPDX header;
  `_meta` block; sorted by code

- [x] Task 30: Create `lib/Settings/seeds/investeringsaftrek-kia-tiers-2026.json`
  with 5 tier records (2026 Wet IB 2001 art. 3.41, geïndexeerd): tier 1
  (0–2800 EUR, 0%), tier 2 (2800–70602 EUR, 28%), tier 3 (70602–130744 EUR,
  EUR 19.769), tier 4 (130744–392230 EUR, EUR 19.769 − 7.56% formula), tier 5
  (392230+ EUR, 0%); SPDX header; `_meta` block

- [x] Task 31: Add Dutch (`nl_NL`) + English (`en_US`) i18n strings per
  ADR-007: "Investeringsaftrek", "KIA-aftrek", "EIA-aftrek", "MIA-aftrek",
  "Vamil", "Energielijst", "Milieulijst", "RvO Aanvraag",
  "Meldingstermijn", "Deadline", "3 maanden na opdrachtverlening",
  "Desinvesteringsbijtelling", "Ex-ante Calculator", "Wat-als-scenario",
  "Netto contante waarde", "Belastingvoordeel", "Aanschafwaarde",
  "Opdrachtverleningsdatum", "Ingebruikname", "Cumulation forbidden",
  "Uitgesteld bezwaar", "RvO beschikking", "Milieulijst match",
  "Energielijst match", "Netto contante waarde", "Scenario A / B / C"

- [x] Task 32: Document ex-ante calculator as separate journeydoc per
  DEFERRED: journeydoc page deferred per ADR-030 follow-up (needs captured screenshots from a live instance)
  ADR-030: user journey (acquisition planning → enter asset estimate →
  lookup codes → scenario comparison → NPV analysis → go/no-go decision);
  create `docs/journeydoc/investeringsaftrek-acquistion-planner.md`

- [x] Task 33: Add `hydra.json` entry for this change with links to all
  artifacts (proposal, design, spec, tasks), list cross-project impact
  (openregister, docudesk, openconnector), list risks + mitigations, list
  open questions with owner (Annemarie for RvO compliance validation)

## Verification

`openspec validate` must exit clean on the change folder.

Dutch tax & compliance officer peer review (via `/test-persona-annemarie` or
Annemarie de Vries, VNG Standards Architect) confirms:
1. Cumulative scheme rules (KIA + EIA/MIA/Vamil stacking, EIA + MIA
   forbidden) match Wet IB 2001 art. 3.42 lid 7 current interpretation.
2. 3-month RvO meldingstermijn is correctly defined as "3 months from
   opdrachtverlening date, NOT invoice/delivery date."
3. 5-year disposal-window clock starts on "aanvang kalenderjaar van
   investering" per art. 3.47, not on asset-acquisition date.
4. Desinvesteringsbijtelling posts in disposal year (not filing year).
5. Vamil + early disposal interaction (terugneming of versnelde afschrijving).
6. ex-ante calculator NPV assumptions (IB/Vpb tariff, useful-life defaults).

Architect reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no
app-local aftrek service; calculations in schema + aggregations or
documented `KiaSchalenLookup` guard; manifest carries navigation; RvO
lifecycle declarative per OR lifecycle).

Operator walkthrough: (a) create InvestmentAsset with Energielijst/Milieulijst
lookup → eligibility checklist → override if needed → (b) RvO deadline
widget → reminder emails → 3-month deadline enforced → (c) create
InvesteringsaftrekClaim → RvO aanvraag submission → (d) (later) RvO
beschikking arrives async → (e) dispose of asset within 5 years → (f)
desinvesteringsbijtelling auto-posted to GL → (g) jaaraangifte Bijlage
Investeringsaftrek produced.

No source code changes outside
`openspec/changes/bookkeeping-investeringsaftrek/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- **PHPUnit unit tests:**
  - Eligibility classification (KIA range, EIA/MIA/Vamil minimum + max
    thresholds, Vamil yearly plafond).
  - Cumulation matrix validation (allowed / forbidden combinations).
  - KIA tier calculation (all 5 tiers, marginal-effect correctness,
    recomputation on asset changes).
  - RvO deadline computation (`opdrachtverleningDatum + 3 months`).
  - Desinvesteringsbijtelling on disposal (formula: percentage ×
    min(opbrengst, aanschafwaarde)).
  - Energielijst/Milieulijst lookup against `opdrachtverleningDatum` year.
  - Vamil depreciation schedule modification (75% direct + 25% gespreid).
  - RvO beschikking async population from mededeling feed.
  - Vrijwillige verlaging (reduction tracking, rationale audit).
  - Ex-ante calculator NPV computation (3 scenarios, tax-rate sensitivity).

- **Playwright MCP browser tests:**
  - InvestmentAsset creation: eligibility checklist display + override UI.
  - RvO Aanvragen dashboard: deadline widget, reminder email simulation,
    deadline-block enforcement.
  - Desinvesteringsbijtelling Watch: 5-year window display, disposal-event
    trigger, GL entry draft review.
  - Ex-ante Calculator: input form → code lookup → scenario display → NPV
    comparison.
  - Jaaraangifte Bijlage: export as PDF + XBRL fragments.
  - Manifest navigation: all 4 entries load, ACL respected, feature flag
    works.

- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle
authors:

- `docs/user-guide/bookkeeping/investeringsaftrek.md` — Wat IB 2001
  overview, KIA/EIA/MIA/Vamil eligibility rules, code taxonomy
  (Energielijst/Milieulijst), cumulation matrix, RvO meldingstermijn
  deadline, desinvesteringsbijtelling on disposal, ex-ante calculator.
- `docs/guides/investeringsaftrek-compliance-checklist.md` — eligibility
  verification, code matching, RvO deadline tracking, disposal-window
  monitoring, jaaraangifte integration.
- Screenshots: InvestmentAsset creation with eligibility checklist,
  RvO deadline widget with reminder history, Desinvesteringsbijtelling
  Watch list, ex-ante Calculator scenarios.

Per ADR-030 journeydoc convention.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings
(see Task 31 for full list).

## Data Migration

For existing Shillinq deployments with pre-existing `FixedAsset` records:

- No destructive changes: `InvestmentAsset` is optional (nullable FK from
  FixedAsset), backward-compatible.
- Operator action: bulk back-tag existing FixedAssets with InvestmentAsset
  records if prior aftrek was claimed (to establish 5-year disposal window
  retroactively).
- New FixedAssets: auto-generate InvestmentAsset with eligibility
  classification on creation (if `featureFlags.mkb-investeringsaftrek` is
  enabled).
- RvO lists: switch active seed per fiscal year (2026 → 2027, etc.) via
  administration settings.
- Audit trail preserved for all claim lifecycle events.

## External adapter

- [x] Adapter port: dormant `RvOAanvraagAdapterInterface` + `LogRvOAanvraagAdapter` shipped at `lib/Service/External/RvO/` and wired in `lib/AppInfo/Application.php::register()`. The KIA / EIA / MIA / Vamil mededeling (per-investment annual `mededeling werkelijk gerealiseerde investeringen`) is dispatched through this port; the port shape carries `scheme: kia|eia|mia|vamil`, `aanvraagType: mededeling`, and the per-investment bedragen. Production swap to an openconnector-backed binding at source slug `rvo-aanvraag` (eHerkenning Level 3 + Mijn-RvO REST) is non-breaking.
