# Design — Urencriterium Tracker

## Context

The urencriterium is a fiscal hard-gate for Dutch ZZP-ondernemers:
without proof of ≥1.225 hours/year engagement (art. 3.6 Wet IB 2001), they lose
the zelfstandigenaftrek (EUR 2.470 in 2026) + startersaftrek + meewerkaftrek.
Time-tracking systems capture billable project-hours; the urencriterium is a
broader fiscal-ledger that includes non-billable overhead (administratie,
acquisitie, scholing, reistijd, vakliteratuur-bijhouding).

Recent Hoge Raad / Rechtbank rulings (2007 e.v., Gelderland 2024) now require
a "sluitende urenregistratie" (daily, reconcilable, category-cited, source-traced)
rather than a post-hoc Excel backfill.

The tracker bridges time-tracking → urencriterium → IB-aangifte, automating:
daily tally from multiple sources, prognosis-to-year-end, alerts for quarters +
risk-omslagen, audit-trail evidence export (PDF-A3, 7-jaar retentie per art. 52 AWR).

This spec is **declarative-first** per ADR-031: registers + aggregations + batch jobs,
minimal PHP services.

## Goals

- Operationalise the urencriterium as a **rolling daily dashboard** — cumulative
  hours, prognosis, drempel-status, categorised breakdown.
- Automate the **daily tally** from time-tracking (billable), agenda (reistijd, meetings),
  manual entries (acquisitie, scholing, administratie), avoiding manual day-count error.
- Compute a **prognosis-to-year-end** via rolling-12-week average + seasonality
  + vakantie + known-opdrachten, with confidence interval for risk-flagging.
- Generate **alerts** at quarters + omslag-triggers with concrete handelingsperspectief
  (acquisition hours, vakantie-revisie, fiscaal-verlies context).
- Export **audit-trail evidence** (per-kwartaal PDF-A3) with daily detail,
  category-citations, source-traceability, 7-jaar retention (art. 52 AWR), SHA-256
  integrity.
- Support **fiscal variability** (AO-status → 800-uren norm; zwangerschap-fictie →
  16-weken; grotendeels-criterium → >50% test; meewerkende-partner → 525-uren;
  multi-onderneming → per-entity + consolidated).
- Make the spec a **competent-bookkeeper-readable contract** — Dutch fiscal norms
  + categorisation grounds (Hoge Raad caselaw citations) recognisable end-to-end.

## Non-Goals

- No PHP `UrencriteriumService` class (tally, prognosis, alerts are batch/trigger jobs).
- No UX form-design (Vue component shape is per-app UI patterns, not data-shape).
- No real-time Toggl/Harvest/Clockify OAuth integration (webhook + ICS import
  sufficient for MVP; OAuth as future T3).
- No multi-currency (Dutch ZZP default EUR; T5 future).
- No DBA-handhaving integration MVP (evidence is available; linkage future).

## Decisions

### D1 — Urencriterium is a fiscal-ledger sub-ledger, not time-tracking

`UrenRegistratie` is a daily fiscal-categorised ledger separate from (and fed by)
project time-tracking. Time-tracking tracks billable project-hours; urencriterium
tracks fiscal-ledger hours (billable + non-billable overhead).

The two sync at the `BILLABLE_KLANTWERK` category level: entries from
time-tracking flow in automatically; non-billable entries (acquisitie, scholing,
administratie) are manual or agenda-derived.

### D2 — Daily tally is stateless batch-driven, not event-streaming

End-of-day batch (say, 23:00) sums `UrenRegistratie` entries for the day and
updates `UrencriteriumYear.lopendeUren`. No real-time streaming; batch simplifies
reconciliation and audit-trail consistency.

Batch is idempotent: re-running it against the same day's `UrenRegistratie`
entries yields identical `lopendeUren` outcome.

### D3 — Prognosis is rolling-12-week-seasonal, not ML

`UrenPrognose` uses a proven formula: rolling-12-week average + seasonality
correction (e.g., -25% for August dip) + vakantie adjustments + manual
ingeplande-opdrachten override. Model version is stored (`v3.2-12wk-seasonal`)
so upgrades are tracked. Confidence interval emitted for risk-flagging.

No ML/neural-net; the model is transparent to a bookkeeper.

### D4 — Norm determination is profile-driven, automatic on init

On first tracker activation, the system queries the entrepreneur's profiel
(rechtsvorm, AO-status from `hrmq`, parallel loondienst, meewerkende-partner-status)
and auto-sets:

- `doelNorm`: 1.225 (default) or 800 (AO per art. 3.6 lid 5).
- `grotendeelscriterium`: applicability if loondienst + underneming coexist.
- `meewerkaftrek-norm`: 525 if meewerkende-partner.
- `normGrondslag`: caselaw citation (art. 3.6 lid 1 / lid 5 / etc.).

No manual norm-entry required.

### D5 — Categories are configurable table entries, not hard-coded

`UrenCategorie` register defines every Belastingdienst-recognisable category
with fiscal grondslag (Hoge Raad caselaw), conditions, caps, and evidence reqs.
Categories are editable (admin-only) so 2026+ Rechtbank rulings can be absorbed
as table-updates. Tally references category codes; categorisation ground is
documented at export-time.

### D6 — Reistijd capped daily; overages logged, not discarded

When reistijd exceeds 4 uur/day (per Hoge Raad caselaw), the cap is applied,
and a note is logged: "Reistijd-cap toegepast: N uur niet meegeteld."
This creates an audit trail (entrepreneur can see what was capped + why).

### D7 — WBSO-uren auto-include without duplication

When S&O-uren are registered in `bookkeeping-wbso-administratie`, they
automatically flow into `UrenRegistratie.R_AND_D_WBSO` without requiring
manual re-entry. A reconciliation check flags if time-tracking shows a
different number of hours for the same WBSO project.

### D8 — Alerts are generated at fixed times + omslag-triggers

Quarterly alerts (31 Mar, 30 Jun, 30 Sep, 31 Dec) are generated via batch.
Omslag-triggered alerts fire when `drempelStatus` transitions (e.g., OP_KOERS → RISICO
when prognose drops below norm). Alerts include 3+ handelingsperspectief suggestions
tied to the entrepreneur's specific situation.

### D9 — Evidence export is per-kwartaal PDF-A3, SHA-256 hashed

`UrenEvidence` exports per quarter (not per year) for operational manageability.
Format is PDF-A3 (archivable per art. 52 AWR). Each export is SHA-256 hashed;
on retrieval, the hash is re-computed and compared against the stored hash.
Retention is 7 years from issue-date.

### D10 — Backfill rules: ≤7 days free; >7 days requires reden + bewijs

If an entrepreneur adds hours retroactively:
- **≤7 days old**: no extra evidence needed; system auto-logs "Backfill T+N days."
- **>7 days old**: system requires a reason (e.g., "invoiced on date X for work date Y")
  and evidence (e-mail, factuur, agenda-event); flagged separately in evidence-dossier
  so Belastingdienst can audit the context.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Billable-hour feed | `bookkeeping-time-tracking` | Time-tracking entries auto-flow to `UrenRegistratie.BILLABLE_KLANTWERK` |
| Agenda-import | `openconnector` (ICS/CalDAV) | Agenda import + local classifier → `UrenRegistratie.(REISTIJD_ZAKELIJK, ACQUISITIE, SCHOLING, meeting-derived)` |
| File-storage + integrity | `openregister` | Evidence PDFs stored with SHA-256 + bewaartermijn-index |
| S&O-uren tracking | `bookkeeping-wbso-administratie` | Bi-directional sync to avoid duplication; WBSO-uren auto-feed `UrenRegistratie.R_AND_D_WBSO` |
| AO-status + meewerkende-partner | `hrmq` | Lookups for norm determination + grotendeels-criterium checks |
| IB-aangifte consumer | `bookkeeping-ib-aangifte-zzp` | Urencriterium result + evidence fileRef passed via API |
| Multi-year trends | `launchpad` | 5-year `UrencriteriumYear` queries + trend viz |
| Automatic tally + prognosis | OR `x-openregister-aggregations` | Daily batch-driven tally; prognosis via formula (not declarative aggregation) |
| Audit-trail on updates | T2 `bookkeeping-audit-trail` | Automatic on category-changes, evidence-exports, alert-triggers |

**Net new code in implementation cycle**: 6 schema declarations + daily tally batch
(stateless aggregation) + prognosis batch (formula-driven) + alert-trigger batch
(threshold-driven) + 3 manifest entries. No PHP service classes (per ADR-031).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Norm determination (1.225 vs 800 vs meewerkaftrek-525 vs grotendeels) | Declarative — rule table + profile lookup | Rules are policy, not code; 2026+ tax-law changes absorbed as edits |
| Daily tally | Stateless batch-driven aggregation | Idempotent; reconcilable; audit-trail-friendly |
| Prognosis computation | Formula-driven batch (rolling-12-week-seasonal) | Transparent to bookkeeper; upgradeable without code-change |
| Alert generation | Trigger-driven (quarterly + omslag-threshold) | Declarative rules table + threshold config |
| Category weighting + caps | Configuration table (`UrenCategorie`) | Fiscal grondslag-cited; updatable for caselaw changes |
| Evidence export | Batch-driven PDF-A3 generation | Template-driven per openregister + signature-factory |
| WBSO-dubbel-teling | Sync-pattern (bi-directional reconciliation check) | Not a service; a data-shape interaction |
| Reistijd-cap enforcement | Aggregation precondition + logging | Rule: reistijd ≤ 4/day; overages logged, not errored |

No service class authored (per ADR-031).

## Seed Data

None. Entrepreneurs initialise the tracker on first use; categories are pre-loaded
from `UrenCategorie` definition table (seeded as part of capability deployment,
per Belastingdienst 2026 guidance). No invoice/hour templates; daily entries
are operator-authored.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Belastingdienst categorisation interpretation drifts | Categories embed explicit caselaw citations (HR 2003, HR 1996, Hoge Raad 2007, Rechtbank Gelderland 2024); config-table allows 2026+ updates; evidence export includes category label + grounds |
| Prognosis model too simplistic (ignores project-pipeline volatility) | Rolling-12-week + seasonality is industry-standard; confidence interval flagged; entrepreneur can override with ingeplande-opdrachten; high-volatility years show wide bounds, triggering alerts |
| Agenda-classifier wrong-categorises meetings | MVP: manual confirm-per-item (user sees suggestion, accepts/rejects). LLM optional for future. Non-billable always requires affirmation before tally-finalisation |
| 7-year PDF storage quota scales poorly | Per-quarter exports (28 PDFs/year × 7 = 196 per entrepreneur over lifetime); openregister file-quota managed; archive-and-retrieve (index by `(ondernemingId, periode)`) proven pattern |
| Grotendeels-criterium loondienst-hours not synced in real-time | Operator manually enters or we query hrmq daily; daily batch sufficient; no real-time sync required |
| WBSO-uren sync direction conflict (urencriterium vs WBSO-administratie) | Design: time-tracking → WBSO + urencriterium; no reverse-writes. Reconciliation check flags discrepancies |
| Multi-onderneming per-entity norms silently computed wrong | Design: per-entity `UrencriteriumYear` records with separate norm-determination; consolidated view aggregates per-entity results; no automatic rollup (user-facing report shows per-entity status) |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the six
   schemas (additive — no existing schema changes).
2. `UrenCategorie` definition table is seeded with Belastingdienst-2026-standard
   categories (admin-only edit after init).
3. Daily tally batch is registered as a scheduled job (e.g., 23:00 UTC).
4. Quarterly alert batch is registered (e.g., 09:00 UTC on Q-end dates).
5. `src/manifest.json` is patched with 3 new menu entries + their
   pages (additive).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; urenregistraties + evidence files remain in openregister,
queryable but unreferenced. Tally is recomputable from time-tracking.

## Open Questions

1. **Agenda-classifier: LLM or manual-confirm MVP?** — Proposed: MVP is manual
   confirm-per-item; LLM as future T3 usability feature. Resolved in UX review.
2. **Grotendeels-criterium: how to sync loondienst-hours?** — Proposed: operator
   manual entry OR hrmq query if available. Resolved in integration review.
3. **Evidence format: PDF-A3 or XLSX export?** — Proposed: PDF-A3 (audit standard
   per art. 52 AWR); XLSX optional. Resolved in document-design review.
4. **Prognose seasonality: static -25% for August, or data-driven?** — Proposed:
   start with -25% (industry-standard); measure actual cohort variance in
   first 2 years; upgrade model in future. Resolved in analytics review.
