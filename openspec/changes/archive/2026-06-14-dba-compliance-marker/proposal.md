# Proposal: dba-compliance-marker

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`DBAOpdracht`, `DBAIntake`, `DBAModelovereenkomst`, `DBARisicoflag`, 
`DBAPortfolioRisico`, `DBAEvidenceDossier`) + `x-openregister-lifecycle` +
`x-openregister-calculations` for risk scoring + evidence-aggregation. No PHP
risk-engine service classes are authored (subject to ADR-031 exception: at most 
single-method guard helpers for complex scoring rules if the calculation engine 
cannot express them declaratively).

## Summary

Introduce the **DBA compliance marker** capability for Shillinq as a standalone
T2 compliance feature supporting ZZP operators and MKB opdrachtgevers to manage
Wet DBA (Wet deregulering beoordeling arbeidsrelaties, May 2016) and upcoming
VBAR (Verduidelijking Beoordeling Arbeidsrelaties en Rechtsvermoeden) compliance
per assignment/engagement. This change declares six registers (`DBAOpdracht`,
`DBAIntake`, `DBAModelovereenkomst`, `DBARisicoflag`, `DBAPortfolioRisico`,
`DBAEvidenceDossier`) with lifecycle, automated monitoring, and evidence-dossier
aggregation. The capability materialises risk-scores on a 0–100 scale with four
risk bands (LAAG, LAAG_MIDDEN, MIDDEN_HOOG, HOOG), monitors factuur patterns
+ omzetconcentratie, and auto-generates compliance flags grounded in Wet DBA
jurisprudentie (Deliveroo-arrest HR 24-3-2023, PostNL-arrest, etc.) and the
new VBAR uurtarief-grens (EUR 33 peil 2024, geïndexeerd).

The capability serves both ZZP-perspektief (opdrachtnemer-zijde) and 
opdrachtgever-perspektief (inhuur-flow for MKB), including evidence-dossier 
for Belastingdienst audit + optional intermediair-driehoek modelling (Waadi / 
Wka compliance).

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure.

**Depends on:** [`bookkeeping-accounts-payable-receivable`](../bookkeeping-ap-ar)
(factuurfrequentie-monitoring, uurtarief-detectie), [`bookkeeping-payroll-engine-nl`]
(optional: na-heffing scenario calculation), [`zzp-urencriterium-tracker`] (optional: 
own-undertaking evidence), [`zzp-cashflow-13wk`] (optional: omzetconcentratie 
from cashflow).

## Motivation

Since the Wet DBA handhavingsmoratorium lift on 1 January 2025, the Belastingdienst 
has resumed active enforcement with correction notices and back-taxes up to tens of 
thousands of EUR per engagement. For ZZP'ers, a single negative DBA assessment can 
trigger reclass to werknemer status with loonheffing + sociale-zekerheid-premies 
across multiple years. For MKB opdrachtgevers, riskless inhuur is now a compliance 
requirement. Shillinq serves both audiences. A structured, evidence-driven DBA 
compliance-tracking system is now essential business infrastructure.

The Wet DBA operates on three pillars (gezagsverhouding, persoonlijke arbeid, 
financieel risico) plus jurisprudentie-criteria from the Deliveroo-arrest (aard, 
duur, exclusiviteit, ondernemerschap, modelovereenkomst, feitelijke uitvoering). 
The upcoming VBAR introduces an automatic-werknemer rechtsvermoeden at uurtarief 
< EUR 33 (peil 2024). Shillinq must map this complexity into operational intake, 
scoring, monitoring, and audit readiness.

This is a standalone T2 compliance change, independent of other bookkeeping 
tiers (works alongside AP/AR, not dependent on it).

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`dba-compliance-marker`);
  declares 6 new registers (`DBAOpdracht`, `DBAIntake`, `DBAModelovereenkomst`,
  `DBARisicoflag`, `DBAPortfolioRisico`, `DBAEvidenceDossier`) with lifecycles,
  calculations, and aggregations; adds 1 intake-flow + 1 evidence-browser + 1
  portfolio-dashboard to manifest. Integrates with AP/AR for factuur-monitoring
  and uurtarief-detectie (not blocking; optional).
- [ ] Project: openregister — no source changes; consumes existing calculations,
  aggregations, and lifecycle extensions per ADR-031. If complex scoring rules
  cannot be expressed declaratively, ADR-031 exception path applies (single-method
  guard helpers for scoring logic).
- [ ] Project: bookkeeping-ap-ar — optional: flag VBAR uurtarief-grens on each
  factuur line; monitors factuurfrequentie patterns per DBAOpdracht. Changes are
  non-blocking hooks if deployed. No schema changes required.
- [ ] Project: openconnector — optional: fetch Belastingdienst modelovereenkomst-register
  as external data source + maintain sync on register updates.
- [ ] Project: hrmq — optional: mirror DBA intake flow on opdrachtgever-side (PO/
  inhuur-flow) + sync MKB opdrachtgever profiles with shillinq administrations.

## Scope

### In Scope

- One new capability spec (`dba-compliance-marker`) — see the `specs/` folder.
- Six new registers:
  - `DBAOpdracht` (per klant/project engagement, carries intake-ref, modelovereenkomst-ref,
    risk-score, open-flags-count, evidence-dossier-ref)
  - `DBAIntake` (questionnaire answers: gezag/arbeid/financieel + Deliveroo-criteria, scored)
  - `DBAModelovereenkomst` (register of Belastingdienst-approved model contracts + own variants)
  - `DBARisicoflag` (per-opdracht rolling flags: factuurfrequentie, concentratie, langjarigheid, VBAR, etc.)
  - `DBAPortfolioRisico` (annual aggregation across all active opdrachtnen: omzetconcentratie, exclusiviteit, multiple-engagement-zelfde-concern)
  - `DBAEvidenceDossier` (stukkenlijst: overeenkomst, facturen, urenstaten, communicatie, SHA-256-hashes)
- DBA intake flow (verplicht before first factuur per opdracht; skip-rules for <€5000 eenmalig)
- Automated monitoring (daily factuurpatroon check, monthly portfolio-aggregatie)
- Risk scoring (0–100 scale, four bands, based on Wet DBA + Deliveroo criteria)
- Flag generation (FACTUURFREQUENTIE_LIJKT_OP_LOON, CONCENTRATIE_WAARSCHUWING, LANGJARIGE_HOOFDRELATIE, VBAR_GRENS_ONDERSCHREDEN, etc.)
- Evidence-dossier aggregation (PDF export with intake + scores + flags + bijlagen)
- Periodieke herbeoordeling (jaarlijks for engagements >12 maanden)
- Modelovereenkomst-register with versiehistorie en geldigheidstoets
- Optional: tussenkomst-driehoek modelling (ZZP–intermediair–eindklant)
- Optional: Opdrachtgever-perspektief (inhuur-intake, PO-blokkering at HOOG-risico)
- Optional: VBAR uurtarief-monitoring (soft-flag now, hard-blokkering from Jan 1, 2026)
- Manifest entries: DBA Intake Wizard, DBA Portfolio Dashboard, Evidence Browser

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components, controllers,
  tests are not in this proposal; task list references them but implementation lands
  via separate `opsx-apply` cycle.
- **Live Belastingdienst integration** — reading/writing to Belastingdienst-WBA-webmodule.
  The spec allows upload of WBA results but does not automate Belastingdienst API calls.
- **Correctieverplichting workflow** — legal/accounting scenario (what to do when corrected).
  Evidence-dossier packing is in scope; herclassificatie-berekening (loon i.p.v. winst)
  is out-of-scope (optional `opsx-apply` extension).
- **Multi-tenant intermediair administration** — intermediairs have complex setups (own
  shillinq instances, client-specific configs). This spec assumes single-operator view;
  multi-tenant broker flows are future T4/T5 scope.
- **Real-time bank-account category flagging** — expense-account GL posting can flag a
  high-risk DBA category (e.g. "salaries") but cross-referencing with DBA engagement is
  a heuristic improvement, not core.

## Approach

One delta, adding ADDED requirements to a brand-new spec:

**`dba-compliance-marker`** — declares the six registers, the intake lifecycle,
automated monitoring rules, scoring logic, flag templates, evidence-dossier shape,
and portfolio-aggregation. The spec is grounded in Wet DBA articles, Deliveroo-arrest
criteria, and VBAR timing.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is
prefixed `REQ-DBA-*` for traceability.

## New Dependencies

- **`openconnector`** (optional, for Belastingdienst modelovereenkomst-register sync)
- **`openregister`** (calculations, aggregations, audit-trail-immutable per ADR-031)
- **Existing shillinq AP/AR** (optional hooks for factuurfrequentie-monitoring +
  uurtarief-detectie; non-blocking if not present)

No new PHP frameworks or external tax-API dependencies. The VBAR grens (EUR 33)
is baked as a configuration constant; updateable via administration settings.

## Impact

- `lib/Settings/shillinq_register.json` — adds 6 new schemas with lifecycle,
  calculations, aggregations.
- `src/manifest.json` — adds 3 navigation entries (DBA Intake, DBA Portfolio,
  Evidence Browser) + their pages.
- `lib/Enums/DBAConstants.php` — VBAR_GRENS_EUR = 33, risk-band thresholds,
  Deliveroo-criteria scoring rules (optional, gated by ADR-031 exception if
  declarative calculation cannot express).
- No new bespoke Vue components required for MVP (uses standard OR register UI
  + calculation/aggregation displays).
- Optional opdrachtgever-perspektief adds 1 additional wizard entry (Inhuur DBA Intake).

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle` (ADR-031),
  `x-openregister-calculations` (ADR-031), `x-openregister-aggregations` (ADR-031),
  audit-trail-immutable. If scoring rules are not expressible declaratively,
  ADR-031 exception path applies.
- **Bookkeeping AP/AR** (optional) — hooks for factuurfrequentie-monitoring +
  uurtarief-detectie. Shillinq DBA works standalone if hooks not present.
- **OpenConnector** (optional) — Belastingdienst modelovereenkomst-register as
  external data source for sync + validation.

## Risks

### Risk 1: Scoring rules are complex; may not be fully expressible in declarative calculations

**Severity**: Medium
**Mitigation**: The scoring logic (Wet DBA three-pillar scoring + Deliveroo-criteria weighting)
is designed to be expressible via `x-openregister-calculations` (arithmetic + conditional
aggregations per ADR-031). If the engine cannot express a complex rule (e.g. "if exclusief
AND langjarig>2y then boost gezagsverhouding-score by 5"), ADR-031 exception path applies:
a single-method `OCA\Shillinq\Lifecycle\DBAScoreCalculator::computeTotal(...): int` ships,
cited in spec, ~50 LOC, no state.

### Risk 2: VBAR uurtarief-grens threshold changes post-enactment

**Severity**: Low
**Mitigation**: The EUR 33 threshold is baked as a PHP constant in `lib/Enums/DBAConstants.php`
or as a mutable administration setting. If the VBAR legislation updates the grens
(e.g. indexed annually), a one-line constant bump or admin-UI update suffices.

### Risk 3: Belastingdienst modelovereenkomst-register not synchronized

**Severity**: Low
**Mitigation**: Shillinq ships with a seed list of known modelovereenkomsten (Belastingdienst
v2024, tussenkomstvrij, etc.). Operators can upload their own PDFs. Optional `openconnector`
sync keeps the seed fresh; lack of sync does not break the feature.

### Risk 4: Evidence-dossier file-storage + retention becomes unbounded

**Severity**: Medium
**Mitigation**: Per AWR art. 52, dossiers are kept 7 years from engagement end-date.
`DBAEvidenceDossier` declares a `bewaartermijn: "7j"` field; `openregister` or a job
automatically archives/deletes after expiry. Spec is explicit on retention policy.

### Risk 5: Intermediair-driehoek (Waadi/Wka) complicates scoring

**Severity**: Medium-High
**Mitigation**: Intermediair-mode is optional; declared as a separate `DBAIntake.intermediairMode`
flag. When true, the intake asks for both ZZP–intermediair AND intermediair–eindklant relations
separately, with separate risk-scores. If intermediairs are a small segment, the feature can ship
in a later `opsx-apply` without blocking core ZZP scope.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact.
After implementation (separate cycle), rollback follows the standard pattern: revert the
implementing PR; registers are non-destructive — DBA opdrachtnen remain queryable but unreferenced.
Evidence-dossier cleanup follows AWR retention policy.

## Open Questions

1. **Scoring declarativity** — see Risk 1; resolved in `opsx-ff` discovery against OR's
   calculation engine capabilities.
2. **Opdrachtgever-perspektief binding** — should hrmq push inhuur-intakes to shillinq
   (two-way sync) or is it advisory-only? Resolved during implementing cycle's UX review.
3. **Intermediair-driehoek priority** — is this MVP or a later slice? Scoped in task
   prioritization.
4. **Evidence-file storage** — should evidence PDF's be stored in openregister file-api
   or in docudesk? Contract TBD with implementation cycle.
