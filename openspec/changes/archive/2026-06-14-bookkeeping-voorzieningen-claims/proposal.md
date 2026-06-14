# Proposal: bookkeeping-voorzieningen-claims

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(`provision`, `provision-movement`, `contingent-liability`) with type-specific
detail registers (`pensioenvoorziening-detail`, `jubileumvoorziening-detail`,
etc.) + `x-openregister-lifecycle` for period-based roll-forward workflows.
No manual workaround documentation or Excel templates persist; all
prior-provision state is canonicalized as linked `provision-movement` history.

## Summary

Introduce the **IAS 37 / RJ 252 Provisions, Contingent Liabilities and
Contingent Assets** capability for Shillinq as one of the T3 regulatory +
compliance capabilities (per `adr-001-bookkeeping-tier-roadmap.md`). This change
declares nine new registers:

- `provision` — core polymorphic provision record (legal/constructive, probability, best estimate, sensitivity)
- `provision-movement` — period-by-period roll-forward (opening, additions, releases, discontering, estimate changes, closing)
- `contingent-liability` — non-balance-sheet liabilities (probable but unreliable estimate, or remote)
- `pensioenvoorziening-detail` — own-managed pension specifics (actuarial method, mortality table, participant count)
- `jubileumvoorziening-detail` — jubilee commitment specifics (eligible employees, service years, CAO reference)
- `herstructureringsvoorziening-detail` — restructuring detail (plan communication, affected employees, costs)
- `garantievoorziening-detail` — warranty detail (historical claim rates, period, revenue base)
- `milieuvoorziening-detail` — environmental remediation (location, framework, consultant, completion date)
- `claims-voorziening-detail` — legal dispute detail (case reference, legal counsel, settlement estimate, advice memo)

The provision accounting flow is a declarative `x-openregister-lifecycle` on
both `provision` (multi-period planning, herwaardering cycles) and
`provision-movement` (per-period measurement). Three-criteria recognition (IAS 37
§35–37 / RJ 252 §301–305), best-estimate valuation with sensitivity bandbreedte
(§39 / §306), disconteringsvoet when material timing horizon (§45 / §310),
proof-of-obligation (legal/constructive) per type, and peer-review audit trail.
Period-closed movements are immutable; active provisions open for annual
herwaardering and linked to jaarrekening-toelichting disclosure (IAS 37 §85 /
RJ 252 §408).

This change conforms to the shared `nextcloud-app` spec for app structure and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-general-ledger`](../add-shillinq-general-ledger/proposal.md) — provision dotations, vrijvallen, discontering routed to GL
- [`bookkeeping-chart-of-accounts`](../add-shillinq-chart-of-accounts/proposal.md) — `provision.linkedAccount` FK to balance-sheet provision accounts
- [`bookkeeping-financial-statements`](../add-shillinq-financial-statements/proposal.md) — provision disclosure toelichting with sensitivity + contingent liabilities table
- [`bookkeeping-pension-ias19`](../bookkeeping-pension-ias19/proposal.md) — pensioenvoorziening is a `provision` subclass with actuariële detail
- [`bookkeeping-deferred-tax`](../bookkeeping-deferred-tax/proposal.md) — provision timing differences (commercieel vs fiscaal aftrekbaarheid)

## Motivation

IAS 37 / RJ 252 is mandatory for any entity with uncertain future obligations.
For Dutch MKB+, the compliance burden is high: three-criteria judgment (obligation,
probability, reliable estimate), best-estimate vs range sensitivity, discontering
decisions, distinction from contingent liabilities (disclosure-only), and annual
herwaardering with fresh evidence. Today, most entiteiten manage voorzieningen
via untracked Excel sheets or loose accounting-firm memos, creating audit risk
and limiting retraceability.

Per ADR-031, the three-criteria recognition logic, best-estimate sensitivity,
discontering and unwinding, period-closed roll-forward, and peer-review audit
trail are all declarative metadata: entry schemas + aggregation formulas +
lifecycle state machines. Controllers register voorzieningen once, track mutations
over time, and generate disclosure tables automatically for the jaarrekening
without manual re-keying or spreadsheet footwork.

This is one of the T3 regulatory changes; this proposal scopes only the
voorzieningen-claims slice. Pensioenvoorziening (IAS 19 / RJ 271) is a
specialisation and separate spec.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-voorzieningen-claims`); declares 9 new registers with lifecycles + aggregations; adds 3 manifest navigation entries (Provisions, Movements, Contingent Liabilities).
- [ ] Project: openregister — no source changes; consumes existing `x-openregister-lifecycle`, `x-openregister-aggregations`, `x-openregister-calculations` for three-criteria validation, sensitivity range analysis, discontering unwinding, and period-driven movement query.
- [ ] Project: hrmq — (optional) `personnel-administration` module supplies linked medewerker roster for jubileum + herstructurering voorzieningen.
- [ ] Project: docudesk — archives expert rapporten (milieu, actuaris, legal), reorganisatieplannen, peer-review sign-offs under restricted-access (CFO, audit committee, accountant).
- [ ] Project: decidesk — routes material voorzieningen (> EUR 100K or > 1% balanstotaal) for audit-committee / CFO approval gate before status=active.

## Scope

### In Scope

- One new capability spec (`bookkeeping-voorzieningen-claims`) — see the `specs/` folder.
- 9 new registers: `provision` (core, polymorphic), `provision-movement` (roll-forward), `contingent-liability` (disclosure-only), plus 6 type-specific detail registers (pensioen, jubileum, herstructurering, garantie, milieu, claims).
- Three-criteria recognition validation (obligation, probability > 0.5, reliable estimate) per IAS 37 §35–37 / RJ 252 §301–305 enforced at schema level.
- Best-estimate + sensitivity range (low / high bandbreedte) per IAS 37 §39 / RJ 252 §306.
- Disconteringsvoet application when material future timeline per IAS 37 §45 / RJ 252 §310; unwinding of discount as rente jaarlijks.
- Provision-type-specific schema fields: pensioenvoorziening (actuarial method, mortality, participant count, from pension-plan link); jubileumvoorziening (eligible employees, service years, CAO reference); herstructureringsvoorziening (detailed plan date, communicated parties, affected employees, expected payments); garantievoorziening (product categories, historical claim rate, revenue base); milieuvoorziening (location, regulatory framework, expert consultant, completion deadline, ontmantelings obligation); claims-voorziening (case reference, court, legal counsel, amount claimed, settlement estimate, legal-opinion memo); onderhoudsvoorziening (asset reference, cycle, next scheduled, estimated cost, inflation assumption).
- Period-closed `provision-movement` records immutable once period closes; active provisions open for herwaardering per balansdatum (REQ-PROV-009).
- Peer-review audit trail: every provision ≥ EUR 100K or ≥ 1% balanstotaal requires CFO / audit-committee approval before status=active.
- Automatic distinction (REQ-PROV-007): probability > 0.5 → voorziening on balance sheet; 0.05 < probability ≤ 0.5 → contingent liability in toelichting; < 0.05 → remote, no disclosure.
- Aansluiting with jaarrekening toelichting (REQ-PROV-008): aggregate disclosure table per provision type + materiality + sensitivity narrative per IAS 37 §85 / RJ 252 §408.

### Out of Scope

- No PHP provision valuation service (best-estimate calculation, sensitivity computation).
- No real-time expert-report ingestion from external vendors — manual copy/paste from PDF v1; connector API T4.
- No herstructureringsvoorziening restructuring-plan workflow automation (plan authoring, employee communication, consent tracking) — owned by decidesk integration (T4).
- No Dutch pension actuarial PUC calculation — handled by separate `bookkeeping-pension-ias19` spec.
- No multi-currency FX revaluation within provision discontering — single-currency scope.

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Best-estimate valuation is inherently subjective; management may bias high or low depending on earnings motive | Spec-level audit trail on all estimate entries + peer-review approval gate + external accountant challenge in jaarrekening cycle; sensitivity range disclosure makes range transparent |
| Three-criteria judgment (obligation exists, probability > 50%, reliable estimate) may be unclear for edge cases (e.g., moral obligation without legal requirement) | Comprehensive guidance in design.md per IAS 37 BC §82–89 distinction; schema prompts for obligatingEvent text; legal counsel involvement mandatory for claims-voorziening |
| Disconteringsvoet selection (risk-free vs risk-adjusted) complex; entity may pick wrong reference rate | Spec warns on government-bond rate; recommends market-proxy (AA corporates, EURIBOR) per IAS 37 BC §141 |
| Provision lifecycle: early recognition vs deferred may affect P&L timing; management incentive to defer | Audit trail + period close prevents retroactive changes; prior-period adjustments routed through schattingswijziging (prospective per IAS 8) |
| Claims-voorziening legal advice memo sensitive (attorney-client privilege); accidental disclosure creates legal exposure | Legal memos stored under restricted access (CFO, audit committee, accountant only) per docudesk role-based control |

## Rollback

Provision accounting is non-reversible once disclosed in jaarrekening. Rollback
occurs only if the spec is rejected before any entity enters production provision
data. Once live, corrections are journalised as amendments (schattingswijziging
via provision-movement record), not deletions.

## Open Questions

1. **Best-estimate sourcing**: Copy/paste from expert rapporten / legal memos (v1) vs structured connector feed from major consultancies (Big-4, specialized firms)? Recommend v1 manual, T4 connector.
2. **Herstructureringsvoorziening approval**: Entity decision on detailed plan communication format (memo, email, formal announcement)? Recommend flexible guidance, audit acceptance per auditor practice.
3. **Dubieuze-debiteuren-voorziening**: Treat as provision per RJ 252 §366, or as direct valuation allowance on debiteuren? Recommend separate account per Dutch practice; not modelled in this spec (accounts-receivable separate).

## Dependencies

- **bookkeeping-general-ledger**: Provision movements (dotatie, vrijval, discontering) post to GL via journal entries.
- **bookkeeping-chart-of-accounts**: Each `provision` record linked to a balance-sheet account code (Account.accountNumber).
- **bookkeeping-financial-statements**: Disclosure tabel (provision per type, movements, sensitivity, contingent liabilities) consumed by jaarrekening renderer.
- **bookkeeping-pension-ias19**: Pensioenvoorziening is a `provision` with type=pensioen; detailed actuariële measurement in separate spec.
- **bookkeeping-deferred-tax**: Provision timing differences (commercieel dotatie vs fiscaal aftrek timing) detected via provision-movement mutations.

## Success Criteria

- Controller can register a voorziening (e.g., milieu EUR 800K, claims EUR 500K) with three-criteria rationale, best estimate + range, and discontering if > 1 year; all validations passed without manual workaround.
- System blocks voorziening recognition if any of three criteria are missing or probability ≤ 0.5 (suggests contingent-liability path instead).
- Annual herwaardering cycle opens prior-year provisions for estimate review; changed estimate recorded prospectively via effectOfChangeInEstimate.
- Period-closed movements locked; jaarrekening disclosure table auto-generated with opening, additions, releases, discontering, changes, closing per IAS 37 §85.
- Material voorzieningen (> EUR 100K or > 1% balance) require CFO / audit-committee peer-review approval before status=active; approval trail visible for accountant audit.
- Sensitivity ranges and contingent-liability disclosure visible in jaarrekening notes; no manual transcription needed.
