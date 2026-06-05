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

- [ ] Task 11: Implement the aanmeldstroom (REQ-KOR-001) as a multi-page Vue component flow:
  - Page 1: Historical omzet review (2024, 2025 data).
  - Page 2: Current-year prognose (linear trend from YTD or manual input).
  - Page 3: Scenario-analysis (Regulier vs. KOR fiscal comparison, edge-case warnings).
  - Page 4: Three-year lock-in confirmation (MANDATORY checkbox).
  - Page 5: Pre-filled aanvraag generator (PDF/JSON download for mijnbelastingdienst.nl submission).
  - FAIL-SAFE: do not allow submission without explicit lock-in checkbox.

- [ ] Task 12: Implement realtime drempel-bewaking (REQ-KOR-002):
  - Hook into AR invoice post-workflow.
  - On each KOR-eligible invoice post, recalculate `KORAnnualTurnover.lopendeOmzet`, `drempelBenutting`,
    monthly trend, and `prognoseEindeJaar`.
  - Display on dashboard: running %, monthly breakdown, prognose-alert.
  - Update WITHIN 1 second of post for visible realtime feedback.

- [ ] Task 13: Implement three-threshold alert dispatch (REQ-KOR-003):
  - At 80% benutting: dispatch email alert (VROEG).
  - At 90% benutting: dispatch email + in-app + dashboard alert (KRITIEK).
  - At 100% benutting: SYNCHRONOUSLY trigger REQ-KOR-004 revocatie-flow (OVERSCHRIJDING).
  - Ensure each threshold fires only ONCE (not repeatedly as benutting oscillates).
  - Log all alerts to `KORThresholdAlert` table with timestamps and user acknowledgment.

- [ ] Task 14: Implement automatic revocatie (REQ-KOR-004):
  - On drempel >100%, immediately:
    1. Create `KORRevocation` record with type=OVERSCHRIJDING, revocatieDatum=invoice.leveringsDatum.
    2. Transition `KORRegistration.status` to GEEINDIGD_OVERSCHRIJDING.
    3. Re-mark all invoices with leveringsDatum ≥ revocatieDatum as REGULIER_21PCT_VAT.
    4. Calculate `btwSuppletieBedrag` for all KOR-invoices between ingangsDatum and revocatieDatum-1.
    5. Set `blokkadeHeraanmelding` = revocatieDatum + 3 years.
    6. Dispatch sync alert OVERSCHRIJDING (email + in-app).
  - Unit tests for suppletie-bedrag calc (manual recalc vs. system, EUR exactness).

- [ ] Task 15: Enforce KOR-factuur vermelding (REQ-KOR-005):
  - In AR invoice-render template: IF `vrijstellingsGrondslag == "KOR_ART25_OB"`:
    - Set `btwTarief = null`, `btwBedrag = 0`.
    - Inject vermelding text: "Vrijgesteld van btw op grond van artikel 25 Wet op de omzetbelasting 1968
      (Kleine Ondernemersregeling)."
    - NO manual override permitted; system-enforced at render time.
  - ELSE (post-revocatie): render standard VAT lines + vermelding.
  - Test: render KOR and non-KOR invoices to PDF; verify vermelding present/absent correctly.

- [ ] Task 16: Implement voorbelasting-aftrek blokkade (REQ-KOR-006):
  - Listen to `kor.registration.activated` event.
  - For all AP invoices posted while `KORRegistration.status == ACTIEF`:
    - Force `voorbelastingAftrekBaar = false`.
    - Zero `btwBedrag` (no VAT recovery, even if invoice has VAT lines).
    - Book gross amount to cost account.
  - On revocatie, listen to `kor.registration.revoked` event:
    - Re-enable voorbelasting-aftrek for new invoices.
    - Apply herzieningsregels for assets purchased during KOR (prop. recovery per remaining useful life).
  - Unit tests for: blocking during ACTIEF, recovery post-revocatie, herzieningsregels calc.

- [ ] Task 17: Implement three-year lock-in enforcement (REQ-KOR-007):
  - Block any opt-out attempt before `lockInEindDatum` (except death/dissolution/bankruptcy).
  - At `vroegsteOpzegDatum` (3 months before lockInEindDatum), open opt-out workflow.
  - Opt-out effective 1-1 of next calendar year.
  - Create `KORRevocation` record with type=VRIJWILLIG_NA_LOCKOUT on approval.
  - Test: reject opt-out before window, accept within window, effective-date accuracy.

- [ ] Task 18: Implement KOR-EU support (REQ-KOR-008):
  - Parallel registration path for KOR-EU (separate from KOR-NL).
  - Per-lidstaat drempel-monitoring (same logic as KOR-NL, replicated per country).
  - Kwartaalopgaaf preparation (Q1/Q2/Q3/Q4 status tracking, no auto-submit to Belastingdienst).
  - KOR-EU factuur vermelding (artikel 284 VAT-richtlijn wording).
  - EX-nummer storage (manual entry for now; future SBR auto-assign).
  - Test: multi-lidstaat drempel-logic, per-lidstaat revocatie (revokes only exceeding country), Q-opgaaf prep.

- [ ] Task 19: Implement year-end report (REQ-KOR-009):
  - At 31-12: finalize `KORAnnualTurnover.lopendeOmzet`.
  - Generate PDF report: "KOR Jaarlijkse Omzet Verantwoording [YYYY]".
  - Include: monthly omzet breakdown, total, drempel %, 3-year trend, recommendation.
  - For KOR-EU: prepare jaarlijkse eindopgaaf (cumulative per lidstaat, Q-status confirmation).
  - Archive all records (immutable audit trail).

- [ ] Task 20: Implement branche-compatibility check (REQ-KOR-010):
  - Before aanmelding allowed: detect branche from KvK activiteitscode.
  - IF full VAT exemption (art. 11 OB): advise against KOR (no benefit, blocks voorbelasting).
  - IF mixed-use (vrijgestelde + belaste): recalc effective KOR-drempel on belaste omzet only.
  - IF intracommunautaire: advise OSS-regime alternative.
  - IF fiscale-eenheid: block aanmelding (eenheid must apply, not individual).
  - Test: all five detection paths, correct recommendations.

- [ ] Task 21: Implement regime transitions (REQ-KOR-011a & b):
  - REQ-KOR-011a (Regulier → KOR at ingangsDatum):
    - Calculate voorraad-correctie per herzieningsregels (5-year / 10-year window).
    - Prepare suppletie-aangifte with all corrections.
    - Materialize GL posting.
  - REQ-KOR-011b (KOR → Regulier at revocatieDatum):
    - Re-enable voorbelasting-aftrek.
    - Apply herzieningsregels for KOR-period purchases (prop. recovery).
    - Prepare retrospective voorbelasting-credit aangifte.
  - Unit tests for all transitions, EUR-exactness of corrections.

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

- [ ] Task 24: Integrations setup:
  - **bookkeeping-accounts-receivable-core**: Add `vrijstellingsGrondslag` enum values (`KOR_ART25_OB`,
    `REGULIER_21PCT_VAT`, etc.) and `vermeldingOpFactuur` field. Template must enforce KOR-vermelding
    at render time.
  - **bookkeeping-accounts-payable-core**: Listen to `kor.registration.activated` and `revoked` events;
    zero-force `voorbelastingAftrekBaar` during ACTIEF; apply herzieningsregels on revocatie.
  - **bookkeeping-vat-btw-filing**: Listen to `kor.registration.activated`; mark VAT declarations
    "niet van toepassing" for KOR periods. On revocatie, resume normal filing + suppletie-aangifte.
  - **bookkeeping-zzp-tax-regime**: Provide tax-scenario advisory API (call from aanmeldstroom).
  - **notifications**: Dispatch threshold alerts per `KORThresholdAlert.kanaal`.

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
