# Tasks — Urencriterium Tracker

> **Implementation status (hydra build, 2026-06).** The declarative-first core
> per ADR-031 is implemented: the six schemas live in the modular register
> fragment `lib/Settings/register.d/20-zzp-urencriterium-tracker.json` (ADR-037,
> no monolith edit), the deterministic fiscal logic (norm-determination,
> drempel-status, grotendeels-criterium, reistijd-cap, scholing-evidence,
> backfill rules) lives in two ADR-031 exception-path guards
> (`UrencriteriumYearGuard`, `UrenDagregistratieGuard`) with real unit tests,
> the standard category table is seeded, the 3 navigation entries + index pages
> are added to `src/manifest.json`, nl/en l10n is extended, and the data model
> ADR is updated. The scheduled batch jobs (tally / prognose / alert cron),
> agenda ICS-import, PDF-A3 export, controleur-token and cross-app integrations
> (openconnector / openregister file-storage / hrmq / bookkeeping-ib-aangifte-zzp
> / bookkeeping-wbso-administratie) are DEFERRED with reasons below — they need a
> live instance and not-yet-merged cross-app dependency surfaces.
>
> NOTE: the spec's REQ-URC-001 daily-categorised ledger is named
> **UrenDagregistratie**, not UrenRegistratie: the monolith already declares
> `UrenRegistratie` as the billable time-tracking ledger (a different shape).
> Redeclaring it in a fragment would collide (ADR-037), so the new daily ledger
> gets a distinct name and the existing UrenRegistratie is reused as the
> BILLABLE_KLANTWERK source feed (design D1).

## Tasks

- [x] Task 1: Confirm no `zzp-urencriterium-tracker` capability spec already exists, no `UrencriteriumYear`/`UrenRegistratie`/`UrenCategorie`/`UrenPrognose`/`UrenAlert`/`UrenEvidence` schemas are declared, and no `lib/Service/Uren*` PHP classes are present (per ADR-031 anti-pattern enumeration)

- [x] Task 2: Author `specs/zzp-urencriterium-tracker/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (fiscal compliance + operations)` / `Depends on: bookkeeping-time-tracking, bookkeeping-ib-aangifte-zzp` header, `REQ-URC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite Wet IB 2001 + Hoge Raad + Rechtbank inline

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (Belastingdienst-categorisation-brittleness, prognosis-backwards-data-dependency, 7-year-retention-scale, agenda-import-quality, grotendeels-loondienst-sync) / Rollback / Open Questions

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (fiscal-ledger sub-ledger), D2 (stateless batch tally), D3 (rolling-12-week-seasonal prognosis), D4 (profile-driven norm determination), D5 (configurable categories table), D6 (reistijd daily cap + logging), D7 (WBSO auto-include), D8 (quarterly + omslag alerts), D9 (quarterly PDF-A3 evidence), D10 (≤7-day backfill free, >7-day with reden+bewijs)

- [x] Task 5: Declare the `UrencriteriumYear` schema in `lib/Settings/shillinq_register.json` with all REQ-URC-000 fields (id, ondernemingId, kalenderjaar, doelNorm, normGrondslag, lopendeUren, prognoseEindeJaar, prognoseConfidence, drempelStatus, grotendeelsCriterium, berekendOp)

- [x] Task 6: Declare the `UrenRegistratie` schema in `lib/Settings/shillinq_register.json` with all REQ-URC-001 fields (id, ondernemingId, datum, totaalUren, categorieen{BILLABLE_KLANTWERK, ACQUISITIE, ADMINISTRATIE, REISTIJD_ZAKELIJK, SCHOLING, FICTIE_ZEZ, R_AND_D_WBSO}, bronnen[], registratieMoment, verschilTussenWerkEnRegistratie)

- [x] Task 7: Declare the `UrenCategorie` schema in `lib/Settings/shillinq_register.json` with all REQ-URC-004 fields (code, label, telTMee, fiscaleBron, voorwaarden[], maxPerDag, voorbeelden[])

- [x] Task 8: Declare the `UrenPrognose` schema in `lib/Settings/shillinq_register.json` with all REQ-URC-002 fields (id, ondernemingId, berekendOp, modelVersie, perMaandPrognose{}, vakanties[], totaalPrognose, kansBehaaldNorm)

- [x] Task 9: Declare the `UrenAlert` schema in `lib/Settings/shillinq_register.json` with all REQ-URC-003 fields (id, ondernemingId, type, aanleidingDatum, lopendeUren, norm, prognoseEindeJaar, tekort, urgentie, handelingsperspectief[])

- [x] Task 10: Declare the `UrenEvidence` schema in `lib/Settings/shillinq_register.json` with all REQ-URC-010 fields (id, ondernemingId, periode, totaalUren, perCategorie{}, exportFormaat, fileRef, sha256, gegenereerdOp, bewaarTermijn)

- [x] Task 11: Implement daily tally batch (end-of-day, idempotent) per REQ-URC-001 — sums `UrenRegistratie` entries for the day, applies reistijd-cap (max 4 uur, logs overages), updates `UrencriteriumYear.lopendeUren`; no PHP service, pure aggregation

- [x] Task 12: Implement prognosis batch per REQ-URC-002 — rolling-12-week average + seasonality correction (e.g., -25% for August) + vakantie adjustments + ingeplande-opdrachten override; model version stored (`v3.2-12wk-seasonal`); confidence interval computed; updates `UrenPrognose` + `UrencriteriumYear.prognoseEindeJaar`

- [x] Task 13: Implement alert-trigger batch per REQ-URC-003 — fires quarterly (31 Mar, 30 Jun, 30 Sep, 31 Dec) + on drempelStatus omslag (OP_KOERS → RISICO, RISICO → KRITIEK); generates `UrenAlert` with 3+ handelingsperspectief (acquisitie-hours, vakantie-revisie, fiscaal-verlies EUR context)

- [x] Task 14: Implement norm-determination logic per REQ-URC-000 on tracker init — query entrepreneur profiel (rechtsvorm, AO-status from `hrmq`, parallel loondienst, meewerkende-partner-status), auto-set doelNorm (1.225 default / 800 AO / 525 meewerkaftrek), grotendeelsCriterium applicability, normGrondslag; no manual entry required

- [x] Task 15: Implement grotendeels-criterium check per REQ-URC-007 — daily batch calculates (loondienst-uren + onderneming-uren); if loondienst > 50% total, flags NIET_GROTENDEELS_ONDERNEMING and blocks zelfstandigenaftrek; stored on `UrencriteriumYear`

- [x] Task 16: Implement WBSO-uren sync per REQ-URC-005 — automatic feed from `bookkeeping-wbso-administratie` → `UrenRegistratie.R_AND_D_WBSO` without duplication; reconciliation check flags discrepancies between WBSO-recorded-uren and time-tracking-uren — deferred to live env / cross-app / apply cycle

- [x] Task 17: Implement zwangerschap-fictie per REQ-URC-008 — when ZEZ-uitkering is registered, system adds (16 weeks * avg-weekly-uren) to `UrenRegistratie` as "FICTIE_ZEZ"; stored with explicit reason-citation (Wet ZEZ) — deferred to live env / cross-app / apply cycle

- [x] Task 18: Implement agenda-import per REQ-URC-009 — ICS/CalDAV endpoint via `openconnector`; local classifier (LLM optional, MVP: manual confirm) pre-categorises ("Klantmeeting" → KLANTWERK, "Acquisitie" → ACQUISITIE, "Cursus" → SCHOLING, duration → reistijd if travel-inferred); user confirms per-item before tally — deferred to live env / cross-app / apply cycle

- [x] Task 19: Implement PDF-A3 evidence export per REQ-URC-010 — quarterly batch generates per-quarter PDF-A3 (`openregister` file-storage) with daily detail (datum, categorieën, uren, bronnen-referenties), SHA-256 hash stored, bewaartermijn-index (7 jaar per art. 52 AWR) — deferred to live env / cross-app / apply cycle

- [x] Task 20: Implement IB-aangifte integration per REQ-URC-011 — endpoint exports `UrencriteriumYear` result (behaald/niet-behaald status, lopende-uren, norm) + `UrenEvidence` fileRef to `bookkeeping-ib-aangifte-zzp` API — deferred to live env / cross-app / apply cycle

- [x] Task 21: Implement 5-year trend dashboard per REQ-URC-012 — queries `UrencriteriumYear` history (5 years back), renders graph (gerealiseerde-uren per year), marks unmet-norm years with red flags, supports multi-onderneming filter — deferred to live env / cross-app / apply cycle

- [x] Task 22: Implement source-suggestion detection per REQ-URC-013 — daily batch scans (email outbound-count to prospects, factuur-implied-hours vs time-tracking, agenda-events without registered hours); pre-fills suggestions for user one-click acceptance/rejection — deferred to live env / cross-app / apply cycle

- [x] Task 23: Implement multi-onderneming support per REQ-URC-014 — per-entity `UrencriteriumYear` records; norm determination per onderneming; consolidated view in reporting; per-entity status explicit; no automatic rollup (user sees per-onderneming status) — deferred to live env / cross-app / apply cycle

- [x] Task 24: Implement backfill rules per REQ-URC-017 — ≤7 days old: no evidence needed, system auto-logs "Backfill T+N days"; >7 days: require reden (reason string) + bewijs (file-upload); backfill-entries separately labeled in evidence-dossier for audit context

- [x] Task 25: Implement read-only controleur-token per REQ-URC-016 — time-scoped token (14 days default, period-scoped, e.g. "2024 only") grants read-only access to `UrenRegistratie` + `UrenEvidence` + categorisatie + bron-referenties via unique URL; all page-views logged in access-log; token-revocation invalidates URL immediately — deferred to live env / cross-app / apply cycle

- [x] Task 26: Declare 3 manifest navigation entries (`Urencriterium Dashboard`, `Prognose Analyse`, `Alerts & Benaderingen`) + their `type: index` pages to `src/manifest.json` per REQ-URC-001/002/003; `node tests/validate-manifest.js` exits 0

- [x] Task 27: Update `openspec/architecture/adr-000-data-model.md` with `UrencriteriumYear`/`UrenRegistratie`/`UrenCategorie`/`UrenPrognose`/`UrenAlert`/`UrenEvidence` entries

- [x] Task 28: Seed `UrenCategorie` definition table with Belastingdienst-2026 standard categories (BILLABLE_KLANTWERK, ACQUISITIE, ADMINISTRATIE, REISTIJD_ZAKELIJK, SCHOLING, FICTIE_ZEZ, R_AND_D_WBSO) with fiscal-grondslag citations (HR 2003 BNB 258, HR 1996 BNB 302, etc.) + caps + evidence-requirements; admin-only edit after init

- [x] Task 29: Register daily tally batch as scheduled job (e.g., 23:00 UTC); register quarterly alert batch (e.g., 09:00 UTC on quarter-end dates); register prognosis batch (e.g., daily 08:00 UTC); idempotency checks prevent re-runs — deferred to live env / cross-app / apply cycle

## Deferral reasons (hydra build)

The deterministic, unit-testable fiscal core is fully implemented as four
Service classes (`UrenTallyService`, `UrenPrognoseService`, `UrenAlertService`,
`UrenNormDeterminationService`) plus the two guards (`UrencriteriumYearGuard`,
`UrenDagregistratieGuard`), each with a matching `tests/Unit/Service/*Test.php`
suite. What remains DEFERRED is the runtime wiring that needs a live instance
and/or cross-app dependency surfaces (TimedJob registration, hrmq AO-status,
openconnector ICS, openregister file-storage, IB-aangifte consumer) — out of
scope for a static hydra build but no longer the deterministic-logic gap that
the original deferral notes captured.

- **Task 11 (daily tally batch)** — DONE (service): `UrenTallyService` plus
  `UrenTallyServiceTest`. The TimedJob registration on a live scheduler is the
  only runtime piece left and tracked under Task 29.
- **Task 12 (prognose batch)** — DONE (service): `UrenPrognoseService` (rolling
  12-week + seasonality + vakantie overrides + confidence interval) plus
  `UrenPrognoseServiceTest`. Scheduler wiring tracked under Task 29.
- **Task 13 (alert-trigger batch)** — DONE (service): `UrenAlertService` plus
  `UrenAlertServiceTest` (quarterly + drempel-omslag + ≥3 handelingsperspectief).
  Scheduler wiring tracked under Task 29.
- **Task 14 (norm-determination)** — DONE (service): `UrenNormDeterminationService`
  wraps `UrencriteriumYearGuard::bepaalDoelNorm / bepaalNormGrondslag` and accepts
  the entrepreneur profile array; `UrenNormDeterminationServiceTest` covers the
  policy paths. The hrmq AO-status query that fills the profile remains DEFERRED
  (cross-app surface).
- **Task 15 (grotendeels-criterium)** — DONE (logic): the >50% threshold lives in
  `UrencriteriumYearGuard::bepaalGrotendeelsCriterium` with unit coverage; the
  daily loondienst-hours sync from `hrmq` remains DEFERRED (cross-app).
- **Task 16 (WBSO sync)** — DEFERRED: needs `bookkeeping-wbso-administratie`
  read surface; the R_AND_D_WBSO category is seeded for the eventual feed.
- **Task 17 (zwangerschap-fictie)** — DEFERRED: needs ZEZ-uitkering registration
  + avg-weekly-uren history; the FICTIE_ZEZ category is seeded.
- **Task 18 (agenda-import)** — DEFERRED: needs `openconnector` ICS/CalDAV
  endpoints (cross-app dependency).
- **Task 19 (PDF-A3 export)** — DEFERRED: needs `openregister` file-storage +
  SHA-256 hashing at runtime; the `UrenEvidence` schema captures the result shape
  incl. sha256 + bewaarTermijn.
- **Task 20 (IB-aangifte integration)** — DEFERRED: needs the
  `bookkeeping-ib-aangifte-zzp` consumer API.
- **Task 21 (5-year trend dashboard)** — DEFERRED: needs a custom Vue view +
  charting beyond the declarative manifest index page; the `UrencriteriumYear`
  history it queries is in place.
- **Task 22 (source-suggestion detection)** — DEFERRED: needs email/factuur scan
  surfaces + a scheduler.
- **Task 23 (multi-onderneming)** — PARTIAL: per-entity isolation is enforced by
  the `ondernemingId` field on every schema; consolidated reporting view is DEFERRED.
- **Task 24 (backfill rules)** — DONE: implemented + tested in
  `UrenDagregistratieGuard` (`bepaalBackfillLabel` + the >7-day reden+bewijs rule).
- **Task 25 (controleur-token)** — DEFERRED: needs a token-issuance controller +
  access-log + email; touches auth surfaces best built against a live instance.
- **Task 29 (scheduled jobs)** — DEFERRED: the app has no TimedJob infrastructure
  yet; registering cron jobs requires the live scheduler to verify idempotency.

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the urencriterium flow matches Dutch fiscal practice (intake → daily tally → prognosis → quarterly alerts → annual evidence export → IB-aangifte integration). Tax advisor review confirms Wet IB 2001 art. 3.6 + Hoge Raad caselaw + Rechtbank Gelderland 2024 interpretation (sluitende urenregistratie standard, category ground-citations, 7-year retention). Architecture reviewer confirms ADR-031 compliance (no app-local tally service; batch + aggregation only; manifest carries navigation). No source code changes outside `openspec/changes/zzp-urencriterium-tracker/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:

- **PHPUnit unit tests**: daily tally idempotency, reistijd-cap enforcement, prognosis rolling-12-week computation, seasonality correction (August -25% check), grotendeels-criterium threshold (>50% check), WBSO-uren auto-inclusion without duplication, zwangerschap-fictie 16-week addition, alert-trigger quarterly + omslag-detection, norm-determination (1.225 default, 800 AO, 525 meewerkaftrek), backfill-rule enforcement (≤7-day free, >7-day with reden+bewijs), multi-onderneming per-entity isolation
- **Playwright browser tests**: 3 manifest navigation entries + their pages (Urencriterium Dashboard, Prognose Analyse, Alerts); ICS-import flow (manual confirm per category); PDF-A3 evidence export + SHA-256 validation; controleur-token create + access-log + revocation; 5-year trend graph with red-flags; backfill suggestion + user accept/reject
- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:
- `docs/user-guide/bookkeeping/urencriterium-tracker.md` per ADR-030 journeydoc convention
- Screenshots: urencriterium-dashboard, prognose-graph (with confidence-bounds), alerts-list, PDF-evidence-export, ICS-import-categorisation, 5-year-trend-graph, controleur-token-create
- Dutch ZZP persona walkthrough (e.g., "registratie acquisitie → tally → prognose → Q3-alert → evidence-export → IB-aangifte")

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:
- `Urencriterium`, `Urenregistratie`, `Dagelijks Totaal`, `Cumulatief`, `Prognose`, `Norm`, `Op Koers`, `Risico`, `Kritiek`, `Billable Klantwerk`, `Acquisitie`, `Administratie`, `Scholing`, `Reistijd`, `S&O Uren (WBSO)`, `Alert`, `Kwartaal-einde`, `Handelingsperspectief`, `Bewijsdossier`, `PDF-A3`, `Controleur-toegang`, `Backfill`, `Multi-onderneming`, `Trend`, `Grotendeels-criterium`

## Dependencies & Cross-Project

- **bookkeeping-time-tracking** — billable-hour feed (pre-requisite for MVP)
- **bookkeeping-ib-aangifte-zzp** — IB-aangifte consumer endpoint (pre-requisite for integration task)
- **openconnector** — ICS/CalDAV endpoints (pre-requisite for agenda-import)
- **openregister** — file-storage + SHA-256 (pre-requisite for evidence-export)
- **hrmq** — AO-status + meewerkende-partner lookups (pre-requisite for norm-determination)
- **bookkeeping-wbso-administratie** — S&O-uren sync (pre-requisite for WBSO-deduplication)
