# Proposal: bookkeeping-pension-ias19

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`pension-plan`, `actuarial-valuation`, `pension-movement`,
`pension-assumption-sensitivity`, `pension-asset-detail`,
`pension-disclosure-tabel`) + `x-openregister-lifecycle` for period-
based roll-forward workflows. No PHP actuarial calculation service
is authored (subject to ADR-031 exception: a declarative sensitivity-
calculation input guide if entity-supplied calculator unavailable).

## Summary

Introduce the **IAS 19 / RJ 271 employee benefit pension accounting**
capability for Shillinq as one of the T3 regulatory + compliance
capabilities (per `adr-001-bookkeeping-tier-roadmap.md`). This change
declares six new registers:

- `pension-plan` — regeling beskrijving (plan description)
- `actuarial-valuation` — jaarlijkse DBO en plan-asset waardering
- `pension-movement` — jaarlijkse roll-forward (service cost, net interest, actuariële verschillen)
- `pension-assumption-sensitivity` — gevoeligheidsanalyse op DBO
- `pension-asset-detail` — plan-asset categorie-uitsplitsing (IFRS 13 fair-value hiërarchie)
- `pension-disclosure-tabel` — jaarrekening disclosure-tabel

The pension accounting flow is a declarative `x-openregister-lifecycle`
on both `pension-plan` (multi-period planning) and `actuarial-valuation`
(per-period measurement). Actuarial assumptions + DBO/asset rolls are
supplied by external actuaries via manual entry (copy/paste from
actuariële rapporten) or connector API in future T4 phase. Remeasurement
differences (actuariële winsten/verliezen) route via OCI per IAS 19R;
service + net interest post to P&L. PUC method mandatory for DB plans.
Sensitivity analysis auto-generated from balansdatum assumptions.

This change conforms to the shared `nextcloud-app` spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:**
- [`bookkeeping-voorzieningen-claims`](../bookkeeping-voorzieningen-claims/proposal.md) — pension provision is a specialisation of `provision` with `type=pension`; this spec provides actuariële detail
- [`bookkeeping-general-ledger`](../add-shillinq-general-ledger/proposal.md) — service cost, net interest posted to GL; OCI remeasurements via separate OCI accounts
- [`bookkeeping-deferred-tax`](../bookkeeping-deferred-tax/proposal.md) — DTA on timing differences (commercieel vs fiscaal)
- [`bookkeeping-financial-statements`](../add-shillinq-financial-statements/proposal.md) — IAS 19 disclosure table in jaarrekening notes

## Motivation

IAS 19 Employee Benefits and RJ 271 Personeelsbeloningen are mandatory
for any entity with defined-benefit (DB) or hybrid pension obligations.
For Dutch MKB+, the compliance burden is high: actuariële waarderingen,
complex 3-bucket P&L/OCI split, sensitivity disclosures, non-recycling
remeasurement rules. Today, most entiteiten run Excel sheets or outsource
to Big-4 accounting firms (€30–50K/year in honoraria).

Per ADR-031, the PUC calculation, DBO roll-forward, and sensitivity
analysis are declarative metadata: entry schemas + aggregation formulas
+ lifecycle state machines. External actuaries supply raw inputs (DBO,
plan assets, assumptions); Shillinq applies standardised IAS 19 logic
to produce consolidated P&L, OCI, disclosure-table output for the
jaarrekening without requiring specialist actuarieel medewerkers in-
house.

This is one of the T3 regulatory changes; this proposal scopes only the
pension-ias19 slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-pension-ias19`); declares 6 new registers
  (`pension-plan`, `actuarial-valuation`, `pension-movement`,
  `pension-assumption-sensitivity`, `pension-asset-detail`,
  `pension-disclosure-tabel`) with lifecycles + aggregations;
  adds 3 manifest navigation entries (Pension Plans, Actuarial
  Valuations, Disclosure Tables).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations`,
  `x-openregister-calculations` for DBO roll computation,
  sensitivity deltas, PUC method validation.
- [ ] Project: hrmq — (optional) `pension-administration` module
  supplies linked deelnemersbestand for active-participant sync per
  REQ-PEN-010.

## Scope

### In Scope

- One new capability spec (`bookkeeping-pension-ias19`) — see the
  `specs/` folder.
- 6 new registers: `pension-plan` (regeling description with eligibility
  + accrual rules), `actuarial-valuation` (per-period DBO + plan-asset
  measurement), `pension-movement` (roll-forward: opening → closing per
  bucket: service, net interest, remeasurements), `pension-assumption-
  sensitivity` (DBO sensitivity on ±0.5pp discount rate / salary growth,
  ±1yr mortality, ±0.5pp inflation), `pension-asset-detail` (plan-asset
  breakdown by category + IFRS 13 fair-value level), `pension-disclosure-
  tabel` (standardised jaarrekening disclosure).
- DBO measurement: Projected Unit Credit (PUC) method mandatory for DB
  plans per IAS 19 §67 + RJ 271 §6.
- Disconteringsvoet: market-referenced (iBoxx € Corporates AA or equiv.)
  per IAS 19 §83 + RJ 271 guidance; enforcement checks warn on
  government-bond rates.
- P&L / OCI split: service cost + past service + settlement (P&L) vs
  net interest (P&L) vs remeasurements (OCI non-recycling) per IAS 19R.
- Asset ceiling: IFRIC 14 limit on netto vordering when plan assets
  exceed DBO.
- Plan asset categories: cash, equities-quoted, bonds-government,
  bonds-corporate, real-estate, alternative, derivatives per IFRS 13
  fair-value hiërarchie (level 1/2/3).
- Disclosure tabel: per IAS 19 §135–149 jaarrekening note: assumptie
  summary, DBO mutatie-tabel, asset mutatie-tabel, P&L breakdown
  (service / interest / settlement / actuariaal), OCI breakdown
  (demographic / financial / experience / asset return), asset category
  breakdown, duration, verwachte werkgeversbijdrage volgend jaar.
- DC-regeling lichte disclosure: REQ-PEN-008 — only contribution amount
  + brief regeling description for DC plans.
- HRMQ koppeling: periodic validation of deelnemersbestand (actieve
  medewerkers, geboortedatum, salaris, dienstjaren) per REQ-PEN-010.

### Out of Scope

- No PHP actuarial calculation service beyond the ADR-031 exception
  guard.
- No PEC (Pensioenuitvoeringscommissie) governance workflows — T4.
- No real-time asset-management connectors (Bloomberg, FactSet) — T4.
- No longevity / mortality improvements modelling — spec-level inputs only.
- No regulatory filing automation (DNB rapportering, pensioenuitvoerder
  rapportages) — T4.

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Actuarial assumptions (discount rate, salary growth, mortality) supplied manually; quality depends on external actuaris | Spec-level audit trail on all assumptions; governance approval gate on material amendments per decidesk integration |
| DBO calculation off-the-shelf (Mercer, WTW, Sprenkels) often differs from in-house PUC; Shillinq's roll-forward may not match | Sensitivity output on all main assumptions lets controller detect divergence; connector API (T4) for direct feed from actuaris bureau |
| Asset ceiling (IFRIC 14) complex; entity may not recognise all reduction paths | Disclosure tabel highlights asset-ceiling adjustment; optional workflow support in T4 for reduction-plan scenarios |
| Defined-contribution (DC) plans require only light disclosure per IAS 19 §53; accidentally generating full DB disclosure for DC creates noise | `planType` discriminator enforced at schema level; DC plans blocked from entering DB workflows |

## Rollback

Pension accounting is non-reversible once disclosed in jaarrekening.
Rollback occurs only if the spec is rejected before any entity enters
production pension data. Once live, corrections are journalised as
amendments, not deletions.

## Open Questions

1. **Actuarial input source**: Copy/paste from PDF reports (v1) vs structured
   connector feed from major Dutch bureaus (Mercer, WTW, Sprenkels, Aon)?
   Recommend v1 manual, T4 connector.
2. **Asset ceiling reduction paths**: Formal entity decision on contribution-
   reduction vs repayment of overfunded surplus? Governance model TBD in
   decidesk integration.
3. **Mortality table selection**: AG-tafels 2026 standard, or entity-specific
   correction factors? Recommend standard with optional override field.

## Dependencies

- **bookkeeping-voorzieningen-claims**: Pension provision is a `provision`
  subclass; this spec supplies DBO measurement detail.
- **bookkeeping-general-ledger**: Service / net interest GL posting logic
  (separate from GL itself).
- **bookkeeping-deferred-tax**: DTA calculation reads `pension-movement` for
  timing-difference detection.
- **bookkeeping-financial-statements**: Disclosure table consumed by
  jaarrekening renderer.

## Success Criteria

- CFO / group reporting manager can author a pension plan, upload annual
  actuarial valuation, review roll-forward with service/net-interest/OCI
  split, and generate a complete IAS 19 / RJ 271 disclosure table for the
  jaarrekening without manual spreadsheet work.
- Sensitivity analysis on discount rate / salary growth / mortality /
  inflation auto-generated and displayed in disclosure table.
- Audit trail on all assumptions + amendments + amendments approvals visible
  for external accountant review.
- Non-DB (DC) plans blocked from DB workflows; light disclosure auto-
  generated for DC.
