# Proposal: zzp-urencriterium-tracker

`kind: feature` per ADR-031 — fiscal-compliance and reporting.
The urencriterium (1.225-hour minimum-engagement threshold per art. 3.6
Wet IB 2001) is the fiscal gatekeeper for ZZP-ondernemer tax benefits worth
EUR 1.500–2.500 annually. This spec operationalises the urencriterium as a
rolling dashboard tracking cumulative hours, prognosis-to-year-end, categorical
breakdowns, and audit-safe evidence export.

## Summary

Introduce the **urencriterium tracker** capability for Shillinq
as a fiscal-compliance bridge between time-tracking (`bookkeeping-time-tracking`)
and IB-aangifte (`bookkeeping-ib-aangifte-zzp`). The change declares the
`UrencriteriumYear`, `UrenRegistratie`, `UrenCategorie`, `UrenPrognose`,
`UrenAlert`, and `UrenEvidence` registers; the automatic daily tally and
prognosis computation from time-tracking + agenda + manual entries;
the categorical weighting (billable, acquisitie, administratie, scholing,
reistijd, WBSO); the alert system for quarters and prognose-omslagen;
and the audit-trail PDF-A3 export per art. 52 AWR (7-year retention).

The tracker bridges a critical gap: time-tracking tracks billable project-hours,
but the urencriterium requires a broader fiscal-ledger of non-billable work
(administrative overhead, acquisitie, scholing, reistijd) with explicit Belastingdienst-recognisable
categorisation and evidence linkage.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure.

**Depends on:** [`bookkeeping-time-tracking`](../bookkeeping-time-tracking/proposal.md)
(billable-hour feed), [`bookkeeping-ib-aangifte-zzp`](../bookkeeping-ib-aangifte-zzp/proposal.md)
(IB-aangifte consumer endpoint).

**Cross-project integration:** `openconnector` (ICS/CalDAV import for agenda),
`openregister` (file storage for evidence export), `hrmq` (meewerkende-partner data),
`launchpad` (multi-year trend dashboard).

## Motivation

The urencriterium is a hard-stop requirement for self-employed ZZP-ondernemers
in the Netherlands to claim the zelfstandigenaftrek (EUR 2.470 in 2026). Recent
Hoge Raad rulings (2007 e.v.) and Rechtbank Gelderland 2024 case law tightened
the evidentiary standard: Excel backfills no longer suffice. The Belastingdienst
now requires a "sluitende urenregistratie" — a time-bound, reconcilable ledger
with daily granularity, category citations, and source traceability.

Current time-tracking systems capture billable project-hours; the urencriterium
also embraces non-billable fiscal-ledger activities (acquisitie, administratie,
scholing, reistijd, ICT-onderhoud, vakkennis-bijhouding) — a broader universe
than project-billing.

The tracker closes this gap: it automates the tally, prognoses from rolling-12-week
patterns + seasonality, flags risk via quarterly and omslag-triggered alerts,
links to WBSO-uren for dual-benefit scenarios, and exports audit-trail evidence
that survives a Belastingdienst controle.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`zzp-urencriterium-tracker`); declares 6 new registers
  (`UrencriteriumYear`, `UrenRegistratie`, `UrenCategorie`, `UrenPrognose`,
  `UrenAlert`, `UrenEvidence`) with automatic tally, prognosis, and alerts;
  adds 3 manifest navigation entries (Urencriterium, Prognose, Alerts).
- [ ] Project: openconnector — no source changes; agenda ICS/CalDAV import
  endpoints referenced for meeting + reistijd categorisation.
- [ ] Project: openregister — no source changes; file-storage + SHA-256
  hashing for evidence export.
- [ ] Project: hrmq — no source changes; meewerkende-partner + AO-status
  referenced for norm determination.

## Scope

### In Scope

- One new capability spec (`zzp-urencriterium-tracker`).
- The `UrencriteriumYear` register tracking annual norm (1.225 or 800 per AO status),
  lopende tally, prognose, prognose-confidence, drempel-status (OP_KOERS/RISICO/KRITIEK).
- The `UrenRegistratie` register capturing daily entries, categorised by
  `(BILLABLE_KLANTWERK, ACQUISITIE, ADMINISTRATIE, SCHOLING, REISTIJD_ZAKELIJK,
  FICTIE_ZEZ, R_AND_D_WBSO)` with source-references to time-tracking, agenda,
  handmatige invoer.
- The `UrenCategorie` definition-table declaring Belastingdienst-recognisable
  categories, their fiscal grounds (HR caselaw), weighting, caps, and evidence requirements.
- The `UrenPrognose` register computing monthly forecasts (resterend jaar)
  via rolling-12-week average + seasonality correction + vakantie-adjustments
  + ingeplande-opdrachten, with confidence interval.
- The `UrenAlert` register capturing quarterly-end + omslag-triggered warnings
  with concrete handelingsperspectief (acquisition hours, vakantie-revisie, fiscaal-verlies context).
- The `UrenEvidence` register storing per-kwartaal PDF-A3 exports (art. 52 AWR,
  7-jaar retentie) with SHA-256 hashing + bewaartermijn-indexed retrieval.
- Automatic norm determination on tracker init per individual profiel
  (rechtsvorm, AO-status, parallel loondienst, meewerkende-partner-status).
- Daily rolling-tally batch (end-of-day) updating `UrencriteriumYear.lopendeUren`
  from time-tracking + agenda-imports + manual entries.
- Reistijd-cap per day (max 4 uur).
- Prognose-engine with rolling-12-week-seasonal model (`v3.2-12wk-seasonal`).
- Quarterly + omslag alerts with 3+ handelingsperspectief suggestions.
- WBSO-uren dubbeltelling (S&O-uren auto-include without manual re-entry).
- Verlaagd-norm support (800 uren bij AO per art. 3.6 lid 5 IB).
- Grotendeels-criterium check (>50% underneming time vs. loondienst).
- Zwangerschap-fictie 16-weeks (Wet ZEZ).
- Agenda-import + auto-categorisation via ICS/CalDAV + local LLM-optional classifier.
- Backfill rules: 7-days free; >7 days requires reden + bewijs.
- Multi-onderneming consolidatie (per-entity + consolidated view).
- Read-only Belastingdienst-controleur audit-modus via time-scoped token.
- 5-jaars trend dashboard (gerealiseerde uren per jaar, red-flags for unmet norms).
- Email + factuur pre-fill suggesties (non-geregistreerde-uren detection).

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components,
  controllers, tests, and CI changes are deliberately not in this proposal;
  the task list references them but implementation lands via a separate
  `opsx-apply` cycle.
- **Real-time Toggl/Harvest/Clockify OAuth integration** — listed in REQ-URC-015
  for future; not in scope for T2 MVP (time-tracking webhook + manual ICS-import
  sufficient for MVP).
- **Multi-currency** — Dutch ZZP-ondernemers almost always EUR; future T5 capability.
- **Voluntary AI/ML categorisation** — local LLM optional classifier noted in
  REQ-URC-009; MVP uses manual confirm-per-item for categorisation.
- **DBA-handhaving deep integration** — references `dba-compliance-marker`
  for future linkage; MVP captures urenregistratie as-is.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`zzp-urencriterium-tracker`** — declares six registers, automatic daily tally,
prognosis computation, alert triggers, categorie-weighting, WBSO-dubbel-teling,
norm-determination rules, and evidence export.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-URC-*` for
traceability.

## New Dependencies

- **openconnector** — ICS/CalDAV import for agenda-derived meeting + reistijd hours.
- **openregister** — file-storage with SHA-256 + bewaartermijn tracking for evidence PDFs.
- (Optional) **LLM optional** — local category-classifier for agenda-event pre-categorisation (MVP: manual confirm).

## Impact

- `lib/Settings/shillinq_register.json` — adds 6 new schemas
  (`UrencriteriumYear`, `UrenRegistratie`, `UrenCategorie`, `UrenPrognose`,
  `UrenAlert`, `UrenEvidence`).
- `src/manifest.json` — adds 3 navigation entries (Urencriterium,
  Prognose, Alerts) + their `type: index` pages.
- `openspec/architecture/adr-000-data-model.md` — adds 6 entity entries.
- No new PHP services (tally + prognosis are stateless batch jobs; alert
  generation is trigger-based per category; no OCA\Shillinq\Service\UrenService).

## Cross-Project Dependencies

- **bookkeeping-time-tracking** — billable-hour feed into `UrenRegistratie.BILLABLE_KLANTWERK`.
- **bookkeeping-ib-aangifte-zzp** — consumes `UrencriteriumYear` result + `UrenEvidence`
  fileRef for IB-aangifte.
- **bookkeeping-wbso-administratie** (existing) — S&O-uren bidirectional sync
  to avoid double-entry.
- **openconnector** — ICS/CalDAV endpoints for meeting + reistijd import.
- **openregister** — file-storage + SHA-256 hashing.
- **hrmq** — meewerkende-partner + AO-status lookups.
- **launchpad** — 5-year trend queries over `UrencriteriumYear` history.
- **dba-compliance-marker** — future linkage (out of scope T2 MVP).

## Risks

### Risk 1: Belastingdienst-recognisable categorisation brittle

**Severity**: High
**Mitigation**: The spec embeds explicit fiscal citations (HR 2003 BNB 258,
HR 1996 BNB 302, HR 1996 BNB 388, Hoge Raad 2007, Rechtbank Gelderland 2024)
for each category. Categories are configuration-table entries, not hard-coded,
so 2026+ case-law updates can be absorbed as table-edits. Evidence export includes
category labels + grounds; Belastingdienst can audit the classification rationale.

### Risk 2: Prognosis model relies on backward-looking data

**Severity**: Medium
**Mitigation**: Rolling-12-week average + seasonality correction (e.g., -25%
for August dip) is industry-standard. The model emits confidence interval
(`prognoseConfidence: 0.84`); alerts trigger when confidence drops. High-volatility
years may show wide bounds; alerts highlight this. Operator input (vakantie,
known-grandes-opdrachten) refines manually.

### Risk 3: 7-year evidence retention scales

**Severity**: Medium
**Mitigation**: PDF-A3 exports are per-quarter (4 per year × 7 years = 28 PDFs
per entrepreneur). File-storage quota per entrepreneur is managed by openregister.
Archive-and-retrieve pattern (index by (`ondernemingId`, `periode`)) is proven
on other compliance-assets (auditor-statements, contracts).

### Risk 4: Agenda-import + categorisation introduces data-quality risk

**Severity**: Low-Medium
**Mitigation**: MVP uses manual confirm-per-item (user sees pre-categorisation
suggestion, accepts/rejects). LLM optional; fallback to manual. Non-billable
entries still require user affirmation before tally finalisation.

### Risk 5: Grotendeels-criterium + multi-onderneming require coordinated sync

**Severity**: Low
**Mitigation**: Grotendeels-criterium is a daily batch-check against current
loondienst-hours (from `hrmq` or manual entry). Multi-onderneming consolidation
happens at reporting-time (5-jaar trend) — per-entity norms are stored; user
can filter. No real-time sync required; daily batch is sufficient.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle), rollback
follows the standard pattern: revert the implementing PR; registers are
non-destructive — urenregistraties remain queryable; evidence files remain
in openregister; tally is recomputable from time-tracking.

## Open Questions

1. **Agenda-classifier: LLM vs manual confirm MVP?** — Proposed: MVP is manual
   confirm-per-item; LLM optional for future usability. Resolved in implementing
   UX review.
2. **Grotendeels-criterium: automatic sync of loondienst-hours?** — Proposed:
   operator manually enters or we query hrmq if available. Resolved in integration
   review.
3. **WBSO-uren sync direction** — Proposed: time-tracking sources WBSO → urencriterium
   auto-includes; urencriterium does not write back. Resolved with bookkeeping-wbso
   team.
4. **Evidence export: PDF-A3 or XLSX?** — Proposed: PDF-A3 for audit defensibility
   (art. 52 AWR cites PDFs as standard); XLSX export optional. Resolved in
   document-design review.
