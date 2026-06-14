# Proposal: bookkeeping-kor-kleine-ondernemersregeling

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(`KORRegistration`, `KORAnnualTurnover`, `KORThresholdAlert`, `KORRevocation`, `KOREUTurnover`)
+ `x-openregister-lifecycle` consuming threshold-monitoring workflow + aggregations + manifest entries.
No business-logic dunning-like state machine in PHP; drempel-bewaking is event-driven.

## Summary

Introduce the **KOR (Kleine Ondernemersregeling)** capability for Shillinq as one of the T2
compliance + fiscal operations capabilities. This capability formalises the complete KOR lifecycle
under the T2 declarative envelope: opt-in workflow with scenario-analysis, realtime threshold
monitoring with three escalation levels (80% / 90% / 100%), automatic mid-year revocation upon
overschrijding with suppletie calculation, three-year lock-in enforcement, grensoverschrijdende
KOR-EU support (per 2025 law), and correctness enforcement (factuurvermelding artikel 25 OB,
voorbelasting-aftrek blokkade, revocatie-datum als leveringsdatum not year-end).

The change declares five new registers: `KORRegistration` (aanmelding + lock-in), `KORAnnualTurnover`
(drempel-benutting met maandprognose), `KORThresholdAlert` (80/90/100 %-schijven), `KORRevocation`
(beëindiging + revocatie-bedrag), `KOREUTurnover` (cross-border omzet per lidstaat + kwartaalopgaaf).
Integrates with `bookkeeping-vat-btw-filing` (filing-app paused during KOR), `bookkeeping-accounts-receivable-core`
(no-BTW factuurvariант), `bookkeeping-accounts-payable-core` (voorbelasting blokkeert),
`bookkeeping-zzp-tax-regime` (zelfstandigenaftrek context).

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec
for app structure.

**Depends on:**
- [`add-shillinq-accounts-receivable-core`](../add-shillinq-accounts-receivable-core/proposal.md)
  (KOR-variant factuurvermelding);
- [`add-shillinq-accounts-payable-core`](../add-shillinq-accounts-payable-core/proposal.md)
  (voorbelasting-aftrek blokkade);
- [`add-shillinq-vat-btw-filing`](../add-shillinq-vat-btw-filing/proposal.md)
  (filing-suspension during ACTIEF KOR);
- [`add-shillinq-zzp-tax-regime`](../add-shillinq-zzp-tax-regime/proposal.md)
  (tax-scenario context for aanmeldstroom).

## Motivation

KOR covers 320.000+ Dutch entrepreneurs (2024 Belastingdienst census), overwegend ZZP'ers and
small MKB. Per Wet implementatie Richtlijn (EU) 2020/285 (per 1-1-2025), KOR expanded to include
KOR-EU grensoverschrijdend trade with a EUR 100.000 threshold and per-lidstaat drempels. The current
state of practice is manual aanmelding via mijnbelastingdienst.nl + spreadsheet drempel-tracking +
dunning-like warning logic in Excel. Shillinq, as a modernised boekhoudapp, MUST integrate KOR
end-to-end: aanvraag-voorbereiding, realtime drempel-bewaking, automatic revocatie, and correct
fiscal treatment (no-BTW factuur, voorbelasting-blokkade, three-year lock-in enforcement).

The spec is driven by three failure modes observed in practice:
1. **Unknowing aanmelding** — Onnemers melden zich aan zonder de drie-jaars-lock-in volledig te
   begrijpen; Belastingdienst receives ~1000s bezwaarschriften annually.
2. **Surprise overschrijding** — Ondernemers overschrijden de drempel zonder tijdige waarschuwing;
   revocatie post-facto is fiscaal correct but operationally painful (suppletie aangifte, back-tax).
3. **Incorrect factuurvermelding** — Ondernemers schrijven "0% BTW" of forget the artikel 25-vermelding
   entirely, creating compliance risk.

This spec addresses all three through: mandatory scenario-analysis during aanmelding, automatic
80/90/100% alert escalation, and system-enforced factuur-template compliance.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-kor-kleine-ondernemersregeling`);
  declares 5 new registers (`KORRegistration`, `KORAnnualTurnover`, `KORThresholdAlert`,
  `KORRevocation`, `KOREUTurnover`) with lifecycles, aggregations, and manifest entries
  (KOR Dashboard, Drempel Monitor, Aanmelding, Opzegging).
- [x] Project: accounts-receivable-core — KOR facturen require special `vermeldingOpFactuur`
  field and `vrijstellingsGrondslag: "KOR_ART25_OB"` on invoice lines; no BTW-tarief.
- [x] Project: accounts-payable-core — Voorbelasting-aftrek MUST be zero-forced during ACTIEF KOR.
- [x] Project: vat-btw-filing — VAT filing MUST be marked "niet van toepassing" for KOR periods.
- [x] Project: zzp-tax-regime — KOR status feeds into tax-scenario advisories (IB aangifte pre-fill).
- [x] Project: notifications — Threshold alerts dispatch via email + in-app + dashboard per
  `notifications.dispatch` contract. (Consumed declaratively: `KORThresholdAlert.kanaal`
  stores the operator-chosen channels; the notifications capability reads the schema directly.)

## Scope

### In Scope

- One new capability spec (`bookkeeping-kor-kleine-ondernemersregeling`) per T2 tier.
- The `KORRegistration` register: aanmeldgegevens, regime (NL-KOR or KOR-EU), status
  (ACTIEF / GEEINDIGD_OVERSCHRIJDING / GEEINDIGD_VRIJWILLIG), lock-in einddatum, vroegsteOpzegDatum,
  belastingdienst referentie (from mijnbelastingdienst.nl aanvraag).
- The `KORAnnualTurnover` register: lopende omzet per jaar, drempel-benutting (%), maandelijke
  prognose-trend, uitgeslotenPosten (vrijgestelde prestaties, intracommunautaire).
- The `KORThresholdAlert` register: 80% / 90% / 100% trigger events, omzet at moment of trigger,
  ernst level (VROEG / KRITIEK / OVERSCHRIJDING), kanaal (EMAIL / IN_APP / DASHBOARD), aanbeveling.
- The `KORRevocation` register: revocatie-type (OVERSCHRIJDING / VRIJWILLIG_NA_LOCKOUT), revocatie-datum,
  triggerfactuur, suppletie-bedrag (berekend), herrekeningRange, nieuw-regime, blokkade-heraanmelding,
  belastingdienst-notificatie-status.
- The `KOREUTurnover` register (KOR-EU only): omzet per EU-lidstaat, drempelbenenutting per lidstaat,
  kwartaalopgaaf-status (Q1/Q2/Q3/Q4).
- Aanmeldstroom (REQ-001): scenario-analysis, drie-jaars-lock-in confirmation, pre-filled aanvraag
  generator for mijnbelastingdienst.nl.
- Realtime drempel-bewaking (REQ-002): post-invoice recalc, prognose-update, maandgemiddelde trend.
- Drempel-schijven (REQ-003): 80% (informatief), 90% (kritiek + opt-out advies), 100% (revocatie).
- Automatische revocatie (REQ-004): status-change, revocatie-datum = leveringsdatum (not year-end),
  hermarkering van facturen na revocatie-datum, suppletie-berekening.
- Factuurvermelding (REQ-005): "Vrijgesteld van btw op grond van artikel 25 Wet op de omzetbelasting 1968
  (Kleine Ondernemersregeling)" — system-enforced on every KOR-invoice.
- Voorbelasting-blokkade (REQ-006): voorbelasting-aftrek = 0 while ACTIEF KOR; correctie post-revocatie.
- Drie-jaars-lock-in (REQ-007): opzegging vóór lockInEindDatum geblokkeerd; opt-out workflow na afloop.
- KOR-EU registratie (REQ-008): EX-nummer beheer, per-lidstaat drempel-monitoring, kwartaalopgaaf
  per kwartaal, vermeldingsformulering artikel 284 VAT Directive 2006/112/EC.
- Jaarlijkse eindafrekening (REQ-009): definitieve jaaromzet vaststelling, rapportage per ondernemer,
  KOR-EU jaarlijkse eindopgaaf voorbereiding.
- Drempelbeoordeling vooraf (REQ-010): branche-specifieke combinaties met bestaande vrijstellingen
  signaleren (bv. onroerend goed verhuur + KOR = contraproductief).
- Transitie regulier ↔ KOR (REQ-011): voorraad-correctie, herzieningsregels, suppletie-aangifte.

### Out of Scope

- No Belastingdienst webservice integration — aanmelding remains web-formulier-only via mijnbelastingdienst.nl.
  Shillinq pre-fills and validates, but the actual submission is the ondernemer's responsibility.
- No automatic aanmelding via SBR-route (XBRL) — waiting for Belastingdienst API maturity.
- No multi-entity KOR-eenheid — KOR applies to a single onderneming per KvK number. Fiscale-eenheid
  support is out of scope (see REQ cross-app integration).
- No customer-journey chatbot or wizard beyond the scenario-analysis flow; advisory is text-based.
- No integration with compliance-audit or grant-subsidy-management; KOR is a tax-regime, not a subsidy.

## Risks

1. **Belastingdienst-wording compliance** — The spec cites exact law text and Handboek Ondernemen.
   MUST undergo review by a Register Belastingadviseur (RB) or belastingadviseur to confirm
   implementatie-correctheid.
2. **Drempel-berekening edge cases** — Pro-rata drempel for mid-year starters (NOT pro-rata, full EUR 20.000),
   multi-activity drempel-aggregatie, intracommunautaire exemption logic — all subtle. Peer review MUST
   include a practical boekhouder.
3. **KOR-EU-lidstaat-drempel-variance** — Per-lidstaat KOR-drempels differ (BE €25.000, DE €22.000, etc.).
   Shillinq MUST declare them as data, not code.
4. **Revocatie-datum-exactness** — Revocatie-datum MUST be the leveringsdatum of the triggerfactuur,
   not the postingDatum or kwartaal-einde. Subtle but critical for suppletie correctness.
5. **Voorbelasting-correctie-post-revocatie** — Herzieningsregels for investeringsgoederen <5 jaar (10 jaar
   for onroerend) require accurate tracking of purchase-date and usage-start. Non-trivial.

## Rollback

If KOR implementation introduces critical bugs (e.g. wrong suppletie-bedrag, wrong revocatie-datum,
incorrect voorbelasting-blokkade), rollback MUST:
1. Suspend new aanmeldingen (REQ-001 aanmeldstroom goes into quarantine).
2. Freeze all alert-dispatch (no new REQ-003 alerts).
3. Allow existing ACTIEF KORRegistrations to remain live (critical: never revoke mid-cycle).
4. Mark the spec as `status: experimental` pending fix.

## Open Questions

1. **OR's dunning-workflow stability** — This spec assumes a generic threshold-alert model can consume
   OR's dunning-workflow extension. Confirm OR stability and shape. If not stable, ADR-031 exception
   applies: single-method `KORThresholdGuard`.
2. **EX-nummer auto-assignment** — KOR-EU aanmelding requires an EX-nummer (e.g. EX-NL-2026-001) from
   Belastingdienst. Should Shillinq auto-request via SBR, or wait for manual entry? Deferred to
   implementation discovery.
3. **Kwartaalopgaaf automation** — KOR-EU requires Q1/Q2/Q3/Q4 opgaaf per kwartaal. Should Shillinq auto-generate
   drukklaar PDF or just data-prep? Deferred to implementation discovery + T3/T4 tier coordination.
