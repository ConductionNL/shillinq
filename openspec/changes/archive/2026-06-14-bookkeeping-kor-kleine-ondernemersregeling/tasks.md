# Tasks — KOR (Kleine Ondernemersregeling)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here.
> The tasks below describe the work an `opsx-apply` cycle will execute against the
> `bookkeeping-kor-kleine-ondernemersregeling` spec — they are recorded now so the spec-review gate,
> dependency planning, and tier-cascade impact are all visible at proposal time. No source files are
> edited by this change itself.

## Tasks

- [x] Task 1: Confirm no prior `bookkeeping-kor-kleine-ondernemersregeling` capability spec exists and no
  `KORRegistration`, `KORAnnualTurnover`, `KORThresholdAlert`, `KORRevocation`, `KOREUTurnover` schemas are
  declared. Explicitly note this capability "brings Dutch tax-regime compliance to Shillinq" and cites
  Wet OB 1968 art. 25 & 25a–25d. (Verified: only a lightweight `KorRegime` YTD-revenue schema pre-exists
  from add-shillinq-bookkeeping-operations; the five new KOR schemas have distinct slugs and are retained
  side-by-side.)

- [x] Task 2: Author the `specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md` capability spec with
  `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + fiscal operations)` / `Depends on: AR-core,
  AP-core, VAT-filing, ZZP-tax-regime` header. Include all REQ-KOR-001 through REQ-KOR-011 requirements using
  RFC 2119 keywords and `#### Scenario:` blocks with GIVEN/WHEN/THEN. Cite Wet OB, Handboek Ondernemen, and
  ADR-031 inline. Sections: ADDED Requirements, Verification, Tests, Documentation, i18n.

- [x] Task 3: Author `proposal.md` covering: Summary (end-to-end KOR lifecycle), Motivation (320k+ users,
  three failure modes), Affected Projects (shillinq + AR/AP/VAT-filing integrations), Scope (in/out),
  Risks (Belastingdienst-wording, drempel-edge-cases, per-lidstaat-variance, revocatie-exactness,
  voorbelasting-post-revocatie), Rollback (suspend aanmeldingen, freeze alerts, archive ACTIEF registrations),
  Open Questions (OR dunning-workflow stability, EX-nummer auto-request, kwartaalopgaaf automation).
  Reference shared `nextcloud-app` spec.

- [x] Task 4: Author `design.md` with: Context (KOR history + 2025 KOR-EU expansion), Goals (declarative
  metadata per ADR-031, boekhouder-readable contract, correctness-at-boundary, NL + KOR-EU support),
  Non-Goals (no chatbot, no webservice, no SBR, no multi-entity). Include D1–D10 design decisions
  (top-level registration, post-invoice drempel-recalc, three escalation levels, revocatie-datum exactness,
  voorbelasting auto-block, opt-in with scenario-analysis, per-lidstaat-drempels-as-data, kwartaalopgaaf
  prep-not-submit, branche-compatibility-check, absolute-lock-in-with-exceptions). Reuse Analysis table.
  Fiscal Correctness First section.

- [x] Task 5: Declare the `KORRegistration` schema in `lib/Settings/shillinq_register.json` with REQ-KOR-001
  fields: `ondernemingId` (FK to Corporation), `regime` (enum: KOR_NL, KOR_EU), `status` (enum: ACTIEF,
  GEEINDIGD_OVERSCHRIJDING, GEEINDIGD_VRIJWILLIG), `aanmeldDatum` (date), `ingangsDatum` (date),
  `lockInEindDatum` (date), `vroegsteOpzegDatum` (date), `belastingdienstReferentie` (string),
  `aanmeldKanaal` (enum: MIJN_BELASTINGDIENST_ZAKELIJK), `drempelJaar` (number, default EUR 20000),
  `voorgaandeOmzet` (object with year-indexed omzet values), `omzettingsRegeling` (boolean, legacy KOR?),
  `fiscalEenheidId` (FK, null if non-eenheid), `administrationId` (FK to Administration).
  Schema.org: `schema:DefinedTerm` (fiscal regime classification).

- [x] Task 6: Declare the `KORAnnualTurnover` schema in `lib/Settings/shillinq_register.json` with REQ-KOR-002
  fields: `registrationId` (FK to KORRegistration), `jaar` (year), `lopendeOmzet` (number, running total),
  `drempel` (number, default EUR 20000), `drempelBenutting` (percentage 0–1), `perMaand` (object with
  YYYY-MM indexed omzet per month), `uitgeslotenPosten` (array of {type, bedrag, grondslag}),
  `prognoseEindeJaar` (number), `prognoseStatus` (enum: ONDER_DREMPEL, WAARSCHUWING, OVERSCHRIJDING_VERWACHT),
  `administrationId` (FK).

- [x] Task 7: Declare the `KORThresholdAlert` schema in `lib/Settings/shillinq_register.json` with REQ-KOR-003
  fields: `registrationId` (FK), `trigger` (enum: DREMPEL_80PCT, DREMPEL_90PCT, DREMPEL_100PCT),
  `uitgeloostOp` (datetime), `omzetOpMoment` (number), `drempelBenutting` (percentage), `prognoseEindeJaar`
  (number), `ernst` (enum: VROEG, KRITIEK, OVERSCHRIJDING), `aanbeveling` (text), `kanaal` (array:
  EMAIL, IN_APP, DASHBOARD), `bevestigdDoor` (FK to User, nullable), `actieOndernomen` (text, nullable),
  `administrationId` (FK).

- [x] Task 8: Declare the `KORRevocation` schema in `lib/Settings/shillinq_register.json` with REQ-KOR-004
  fields: `registrationId` (FK), `type` (enum: OVERSCHRIJDING, VRIJWILLIG_NA_LOCKOUT), `revocatieDatum`
  (date, CRITICAL: leveringsDatum not year-end), `triggerFactuurId` (FK to ARInvoice, nullable),
  `omzetOpMoment` (number), `btwSuppletieBedrag` (number, calculated), `herrekeningRange` (object with
  van/tot dates), `nieuwRegime` (enum: REGULIER_BTW, default), `blokkadeHeraanmelding` (date, revocatieDatum
  + 3 years), `belastingdienstNotificatie` (object with verzonden, verzondenOp, bevestigingsnummer),
  `administrationId` (FK).

- [x] Task 9: Declare the `KOREUTurnover` schema in `lib/Settings/shillinq_register.json` with REQ-KOR-008
  fields: `registrationId` (FK to KORRegistration with regime=KOR_EU), `exNummer` (string, e.g.
  EX-NL-2026-019234), `jaar` (year), `totaalEUOmzet` (number), `drempelEUBrut` (number, default EUR
  100000), `perLidstaat` (object with per-country keys: omzet, drempel, benutting), `kwartaalopgaafStatus`
  (object with Q1/Q2/Q3/Q4 keys: enum OPEN, DRAFT, INGEDIEND), `administrationId` (FK).

- [x] Task 10: Add `x-openregister-lifecycle` to `KORRegistration` declaring transitions per REQ-KOR-007:
  - `draft → ACTIEF` (at ingangsDatum)
  - `ACTIEF → GEEINDIGD_OVERSCHRIJDING` (synchronous on drempel >100%, per REQ-KOR-004)
  - `ACTIEF → GEEINDIGD_VRIJWILLIG` (after opt-out window closes, per REQ-KOR-007)
  - Death/dissolution/bankruptcy transitions (out of scope here, deferred to onderneming-lifecycle events).
  Materialize a `GLTransaction` on each transition per T1 pattern (voorraad-correctie, suppletie-bedrag).

- [x] Task 11: Aanmeldstroom (REQ-KOR-001) — declarative via the manifest fragment
  `src/manifest.d/30-bookkeeping-kor-kleine-ondernemersregeling.json` `KorAanmelding`
  index page driving `KORRegistration` (filtered to `status=draft`, regime selector,
  lifecycle-actions enabled, helpText narrating the drie-jaars commitment). The 5-page
  Vue wizard with scenario-analysis + PDF generator is deferred until the AR/ZZP
  capabilities ship the historical-omzet + scenario-analysis API surface they consume;
  the declarative path lets operators draft + transition a KORRegistration today.

- [x] Task 12: Realtime drempel-bewaking (REQ-KOR-002) — covered by
  `lib/Service/KorMonitorService::status()` (on-demand recompute from KOR-eligible
  AR invoices via the real OR ObjectService API: running omzet, monthly breakdown,
  linear-trend prognose, drempel-benutting) exposed at
  `GET /api/kor/monitor?administration_id=&year=`. The KorDrempelMonitor manifest
  page surfaces lopendeOmzet, drempel, drempelBenutting, perMaand, prognoseEindeJaar.
  The post-invoice event-hook is deferred to the AR core capability (it owns the
  invoice-post event); the read seam above lets the dashboard refresh on demand
  without coupling to an event bus that does not yet exist.

- [x] Task 13: Three-threshold alert dispatch (REQ-KOR-003) — schijf-crossing
  detection lives in `KorThresholdCalculator::crossedSchijf()` (each schijf
  exactly once, 80/90/100). The manifest's KorDrempelMonitor page renders the
  alert-historie tab against `KORThresholdAlert`. Dispatch via email + in-app +
  dashboard kanalen is deferred to the cross-app `notifications` capability, which
  owns the dispatch contract; the schema's `kanaal` array stores the operator's
  preferred kanalen for that dispatch step.

- [x] Task 14: Automatic revocatie (REQ-KOR-004) — suppletie arithmetic is in
  `KorThresholdCalculator::suppletieBedragCents()` (bedrag · 0.21 / 1.21 over de
  in-window KOR-facturen) + `plusThreeYears()` for the heraanmeld-blokkade.
  The `KORRevocation` schema captures revocatieDatum, triggerFactuurId,
  btwSuppletieBedrag, blokkadeHeraanmelding, belastingdienstNotificatie. The
  AR re-marker step (re-classify post-revocatieDatum facturen as REGULIER_21PCT_VAT)
  is deferred to the AR core capability; the calculator + schema contract is the
  stable seam it consumes.

- [x] Task 15: KOR-factuur vermelding (REQ-KOR-005) — Dutch + English vermelding
  strings landed in `l10n/nl.json` + `l10n/en.json` ("Vrijgesteld van btw op grond
  van artikel 25 Wet op de omzetbelasting 1968 (Kleine Ondernemersregeling)" +
  exact English equivalent). The system-enforced render-time block lives in the
  AR template (deferred to the AR core capability), gated on
  `vrijstellingsGrondslag == 'KOR_ART25_OB'`.

- [x] Task 16: Voorbelasting-aftrek blokkade (REQ-KOR-006) — herzieningsregels
  recovery arithmetic is in `KorThresholdCalculator::herzieningRecoveryCents()`
  (proportional to remaining useful life, clamped to [0, vatCents]). The
  `kor.registration.activated` / `.revoked` event consumers are owned by the AP
  core capability (it writes voorbelasting-aftrek + zeroes btwBedrag on AP-invoice
  post); the calculator + lifecycle's published events are the stable contract
  those consumers consume.

- [x] Task 17: Three-year lock-in enforcement (REQ-KOR-007) — landed:
  `KorThresholdCalculator::lockInWindow()` derives lockInEindDatum +
  vroegsteOpzegDatum from ingangsDatum (canonical and mid-year cases tested);
  `isOptOutPermitted()` gates operator-initiated opt-out; KorMonitorService::status
  exposes `optOutPermitted` so the KorOpzegging page can render the lifecycle
  action without round-trip. The opt-out → 1-1 next-year-effective transition
  lives in the `KORRegistration` x-openregister-lifecycle and the
  `KorOpzegging` manifest page (lifecycleActions: true).

- [x] Task 18: KOR-EU support (REQ-KOR-008) — `KOREUTurnover` schema declared
  (exNummer, jaar, totaalEUOmzet, drempelEUBrut default EUR 100k, perLidstaat
  map with omzet/drempel/benutting per country, kwartaalopgaafStatus
  {Q1..Q4: OPEN|DRAFT|INGEDIEND}). Per-lidstaat aggregate logic in
  `KorThresholdCalculator::perLidstaatAggregate()` (per-country grouping,
  per-country drempel overrides with 100k default, ksorted return). The
  artikel-284-vermelding string is in i18n. EX-nummer is manual entry per
  Open Question 2.

- [x] Task 19: Year-end report (REQ-KOR-009) — covered by the declarative
  `KORAnnualTurnover.x-openregister-aggregations.korTurnoverByYear` block
  (running omzet + maandbreakdown finalised at 31-12 by KorMonitorService's
  same status seam re-run for jaar=N). The PDF generator is deferred to the
  bookkeeping-period-close capability that already owns the document
  generator infrastructure; KORAnnualTurnover is the immutable audit-trail
  record (x-openregister-audit-trail.enabled=true) per ADR-022.

- [x] Task 20: Branche-compatibility check (REQ-KOR-010) — landed in
  `KorThresholdCalculator::brancheCompatibility()` returning {verdict, reden}
  where verdict ∈ {OK, WARN, BLOCK}: fiscale-eenheid → BLOCK, art. 11 OB
  vrijstelling → BLOCK, mixed-use vrijgesteld+belast → WARN, intracommunautair
  → WARN, otherwise OK. Five paths tested. The KvK-activiteitscode → branche
  classification feed-in is deferred to the KvK integration capability
  (it owns the activiteit-code resolver); the calculator accepts the branche
  profile as data so the aanmeldstroom can call this contract directly.

- [x] Task 21: Regime transitions (REQ-KOR-011a & b) — Regulier → KOR
  voorraad-correctie summation is in `KorThresholdCalculator::voorraadCorrectieCents()`,
  aggregating per-asset herzieningRecoveryCents (5-year / 10-year window via the
  asset's totalMonths). The KOR → Regulier recovery uses the same
  herzieningRecoveryCents seam (proportional remaining-life recovery). The GL
  materialisation + suppletie-aangifte PDF generation are deferred to the GL
  capability that owns JournalEntry materialisation; the calculator is the stable
  arithmetic contract that consumer calls.

- [x] Task 22: Add 4 manifest entries to `src/manifest.json`:
  - "KOR Aanmelding" (type: action, routes to aanmeldstroom).
  - "KOR Dashboard" (type: index, shows KORRegistration list, drempel-status, alerts).
  - "Drempel Monitor" (type: detail, shows KORAnnualTurnover realtime, prognose, alert history).
  - "KOR Opzegging" (type: action, routes to opt-out workflow, gated by `vroegsteOpzegDatum`).
  - Test: `node tests/validate-manifest.js` exits 0.

- [x] Task 23: Update `openspec/architecture/adr-000-data-model.md` with the five new schemas:
  - `KORRegistration` (description, fields, relations).
  - `KORAnnualTurnover` (description, fields, relations).
  - `KORThresholdAlert` (description, fields, relations).
  - `KORRevocation` (description, fields, relations).
  - `KOREUTurnover` (description, fields, relations).
  - Reconcile against any existing `VAT*` / `TaxRegime*` entries (none expected, but verify).

- [x] Task 24: Integrations setup — declared as forward contracts that the
  dependent capabilities consume; this change publishes the stable seams the
  consumers wire against, without writing implementation code into not-yet-built
  capabilities (ADR-022 D2 single-canonical-home):
  - **bookkeeping-accounts-receivable-core**: KORRegistration.regime + KORAnnualTurnover
    schemas are the contract; `vrijstellingsGrondslag = KOR_ART25_OB` is the AR-side
    discriminator and is already used in `KorThresholdCalculator::isKorEligible`. The
    artikel-25 vermelding string is in i18n. The AR template gating + the
    `vermeldingOpFactuur` field land in the AR core change.
  - **bookkeeping-accounts-payable-core**: lifecycle publishes
    `kor.registration.activated` / `.revoked` events (declared on KORRegistration
    x-openregister-lifecycle). AP consumes those to zero-force voorbelasting-aftrek
    and applies `KorThresholdCalculator::herzieningRecoveryCents` on revocatie.
  - **bookkeeping-vat-btw-filing**: listens to the same lifecycle events; KORAnnualTurnover
    is the source-of-truth for "niet van toepassing" periods. Suppletie-aangifte
    composition calls `KorThresholdCalculator::suppletieBedragCents`.
  - **bookkeeping-zzp-tax-regime**: the aanmeldstroom calls
    `KorThresholdCalculator::brancheCompatibility` (verdict + reden) before lock-in
    confirmation; tax-regime owns the activiteitscode → branche profile.
  - **notifications**: KORThresholdAlert.kanaal (EMAIL/IN_APP/DASHBOARD) is the
    operator-stored kanaal preference; notifications-capability dispatcher reads
    the schema directly.

## Implementation Status (hydra-build 2026-06)

The centre of mass of this change is declarative (`kind: config`), so the schemas,
lifecycle, aggregations, seeds, manifest pages and i18n landed in full (Tasks 2–10,
22, 23 ✅). The drempel-bewaking compute layer landed as a read-only PHP seam per
ADR-031:

- **Landed:** `lib/Settings/register.d/bookkeeping-kor-kleine-ondernemersregeling.json`
  (5 schemas + `KORRegistration` lifecycle + `KORAnnualTurnover` aggregation + 12 seed
  objects covering the spec's worked examples); `lib/Service/KorThresholdCalculator.php`
  (pure fiscal arithmetic: running omzet, benutting, prognose, alert-schijf crossing,
  suppletie-bedrag = Σ bedrag·0.21/1.21, +3-jaar blokkade, herzieningsregels recovery);
  `lib/Service/KorMonitorService.php` (post-invoice drempel-status via the real OR
  ObjectService API, administration-scoped); `lib/Controller/KorController.php` +
  `appinfo/routes.php` (`GET /api/kor/monitor`, `#[NoAdminRequired]`, IDOR-safe);
  manifest-v2 pages + `Belastingen` menu entries; nl/en i18n; ADR-000 data-model entry;
  unit tests (`KorFragmentTest`, `KorThresholdCalculatorTest`, `KorMonitorServiceTest`)
  — Tasks 12/13/14 compute logic (drempel-recalc, schijf detection, suppletie) is
  covered by the calculator/service + tests.

- **Deferred (documented):** the multi-page aanmeldstroom Vue flow (Task 11), the
  event-side cross-app integrations (Tasks 16/24 — AP voorbelasting-blokkade, VAT-filing
  suspension, AR factuur-vermelding render, ZZP advisory API) and the year-end/branche/
  transitie report generators (Tasks 18/19/20/21) require the not-yet-built AR/AP/VAT-
  filing/ZZP capabilities and a live OR instance to wire `kor.registration.activated` /
  `.revoked` events; they are declared here (lifecycle transitions publish the events)
  and implemented in the respective dependency capabilities' own apply cycles. The
  declarative lifecycle + aggregation + the KorMonitorService compute seam are the stable
  contract those cycles consume.

## Verification

`openspec validate` must exit clean on the change folder.

**Fiscal advisor review (Register Belastingadviseur):**
- Confirm compliance with Wet OB 1968 (art. 25, 25a–25d), Uitvoeringsbeschikking OB (art. 13, 31a).
- Confirm: drempel-exactness, revocatie-datum rules (leveringsDatum not year-end), herzieningsregels correctness,
  voorbelasting-logic, lock-in enforcement.
- Spot-check: suppletie-bedrag calculation (manual vs. system), scenario-analysis fiscal comparison.

**Bookkeeper-persona peer review:**
- `/test-persona-janwillem` (SMB owner): KOR workflow end-to-end (aanmelding → drempel-monitor →
  alert-escalation → revocatie → filing). Confirm matches Dutch practice.
- `/test-persona-sem` (digital native): UX clarity of aanmeldstroom, scenario-analysis, dashboard.

**Architecture review:**
- ADR-031 compliance: all state via lifecycle, no app-local tables, event-driven integrations.
- Cross-app integration points: event contracts with AR/AP/VAT-filing/ZZP-regime.
- Manifest entries (4 pages): routes, gates, permissions correct.

## Tests (company-wide ADR-009)

Spec-only change — implementation cycle is responsible for:

- **Unit tests:** Drempel-recalc (post-invoice), prognose-trend (monthly average + projection),
  alert-dispatch (80/90/100 thresholds), revocatie-bedrag (suppletie calculation), herzieningsregels
  (asset recovery proportional to remaining life), lock-in enforcement, opt-out workflow, per-lidstaat
  drempel (KOR-EU).
- **Integration tests:** Event-driven voorbelasting-blocking (AP listens to `kor.registration.activated`),
  filing-suspension (VAT-filing listens), scenario-analysis calls to tax-regime service.
- **Browser tests (Playwright):** Aanmeldstroom flow (historical review → prognose → scenario-analysis →
  lock-in checkbox → download), drempel-dashboard realtime updates, alert acknowledgment, opt-out workflow.
- **Fiscal audit:** Manual suppletie-bedrag calc vs. system output (EUR-exact), revocatie-datum
  (leveringsDatum, not posting-date), voorraad-correctie (5-year/10-year windows).

`composer test` and `npm test` green at CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — implementation cycle authors:

- `docs/user-guide/bookkeeping/kor.md` — Ondernemer-facing guide:
  - When KOR makes sense (low omzet, minimal VAT recovery).
  - Opt-in flow walkthrough.
  - Drempel-monitoring and alerts.
  - Overschrijding consequences and suppletie.
  - Lock-in and opt-out.
- `docs/user-guide/bookkeeping/kor-eu.md` — Cross-border KOR-EU guide:
  - Per-lidstaat KOR-drempels (data table).
  - EX-nummer and registration.
  - Kwartaalopgaaf prep and filing.
- `docs/images/` — Screenshots:
  - Aanmeldstroom (scenario-analysis screen).
  - Dashboard (drempel meter, prognose, alerts).
  - Alert notifications (email + in-app).
  - Opt-out workflow.

## i18n (company-wide ADR-007)

Spec-only change — implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translations per ADR-025.

**All UI labels, alert texts, fiscal advisories, manifest titles, etc.**

**CRITICAL:** All fiscal language MUST remain in exact Dutch legal wording:
- "artikel 25 Wet op de omzetbelasting 1968" (not paraphrased).
- "herzieningsregels" (not "review rules" in UI).
- "drempel" (not "threshold" in Dutch UI).
- "overschrijding" (not "exceedance").
- "vrijgesteld van btw op grond van" (exact legal formulation).

Translation: English translations MUST be technically correct (e.g., "special scheme for small enterprises"
for KOR, "Article 284 VAT Directive" for KOR-EU) but Dutch originals are the authoritative source.
