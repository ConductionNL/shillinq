# Proposal: bookkeeping-credit-control-dunning

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(`DunningLadder`, `KlantLadderOverride`, `DunningRun`, `IncassoKostenBerekening`,
`DunningPauseDispute`, `CreditScore`, `OninbaarAfschrijving`) + dunning-workflow
lifecycle + aggregations + manifest entries. No PHP dunning calculation service is
authored (subject to ADR-031 exception: at most guardrails on incassokost-staffel
compliance if OR's dunning-workflow calculation engine is not yet declarative).

## Summary

Introduce the **Credit Control & Dunning Ladder** capability for Shillinq as one
of the T2 compliance + operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability establishes automated
dunning workflow for overdue accounts receivable, with Dutch legal compliance
(Wet Incassokosten art. 6:96 BW, Besluit BIK staffel, handelsrente B2B per
art. 6:119a BW, wettelijke rente B2C per art. 6:119 BW), per-klant ladder
overrides, dispute pausing, and integration with external credit-scoring
providers (Graydon, Creditsafe, Atradius Insights) and incasso bureaus
(Bos Incasso, Atradius Collections, Intrum).

The change declares seven new registers: `DunningLadder` (configuration per
klant-groep), `KlantLadderOverride` (per-klant exceptions with audit trail),
`DunningRun` (per-invoice per-stage execution log with evidence), `IncassoKostenBerekening`
(BIK-staffel calculation + wettelijke rente per B2B/B2C), `DunningPauseDispute`
(dispute pause management with audit trail), `CreditScore` (optional external
credit-scoring snapshot), and `OninbaarAfschrijving` (write-off with BTW-teruggaaf
per art. 29 OB).

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md)
spec for app structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`bookkeeping-accounts-receivable-core`](../bookkeeping-accounts-receivable-core/proposal.md)
(openstaande facturen + klantmaster), [`bookkeeping-btw-aangifte`](../bookkeeping-btw-aangifte/proposal.md)
(BTW-teruggaaf art. 29 OB bij oninbaarheid), [`bookkeeping-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(afschrijving-boeking), [`docudesk`](../docudesk/proposal.md) (dunning-templates
+ evidence-opslag), [`openconnector`](../openconnector/proposal.md) (incassobureau-API's
+ credit-score-integratie), [`openregister`](../openregister/proposal.md) 
(dunning-workflow lifecycle).

## Motivation

Dutch ZZP'ers en MKB-ondernemers lijden onder chronische betalingsachterstand:
volgens Atradius (Payment Practices Barometer 2024) wordt gemiddeld 39% van alle
B2B-facturen in Nederland te laat betaald; 7% non-payment (afschrijving).
Voor de ondernemer betekent dat reëel cashflow-risico, administratieve last bij
elke individuele aanmaning, emotionele/relationele complicaties, en onbenutte
wettelijke incassokostenstaffel (Besluit BIK) en 14-dagen-brief (Wet IK art. 6:96
BW).

Per ADR-022, dunning-workflow lifecycle komt van OR's dunning-workflow abstraction.
Per ADR-031, de BIK-staffel-berekening, wettelijke rente-berekening, dispute-pauzering,
en partial-payment verwerking zijn declaratieve metadata + aggregaties, niet
`DunningCalculationService` PHP classes. De ondernemers kunnen per klant de ladder
aanpassen (overheid: extended terms; vertrouwde klanten: handmatig overrulebaar),
e-mail/brief-templates personaliseren, en escalaties pauzeren bij disputes met
volledige audit-trail.

Dit is een van acht T2 capability changes; deze proposal scoped de dunning-ladder
slice met focus op Nederlandse wettelijke compliance en relatie-bewuste escalatie.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-credit-control-dunning`);
  declares 7 new registers (`DunningLadder`, `KlantLadderOverride`, `DunningRun`,
  `IncassoKostenBerekening`, `DunningPauseDispute`, `CreditScore`, `OninbaarAfschrijving`)
  with lifecycles + aggregations; adds 5 manifest navigation entries (Dunning Ladders,
  Klant Overrides, Dunning Runs, Incasso Kosten, Oninbare Afschrijvingen).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations` for dunning-workflow
  staging, BIK-staffel aggregations, dispute-pause guards.
- [ ] Project: openconnector — no source changes; consumed via outbound connectors
  for incassobureau-API (POST dossier-bundel), credit-score-API (Graydon, Creditsafe,
  Atradius Insights), PostNL Track & Trace aangetekende-post.
- [ ] Project: docudesk — no source changes; dunning-templates (stage 1–5 toon-gradient)
  and evidence-archival (e-mail-headers, PDF-renderingen, digital-signature proof)
  consumed via `bookkeeping-document-attachment-integration`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-credit-control-dunning`) — see the `spec.md`
  folder.
- 7 new registers per ADR-024 (see data model in context-brief): `DunningLadder`
  (1 per ondernemer or per klant-groep; 5-stage default: 0/14/30/60/90 dagen),
  `KlantLadderOverride` (per-klant exception met motivatie en audit-trail),
  `DunningRun` (per factuur per stage: vastgestelde inhoud, kanaal, verstuurde-op,
  delivery-status, open-tracking), `IncassoKostenBerekening` (BIK-staffel per
  hoofdsom; wettelijke rente B2B 11,5% per art. 6:119a BW, B2C 7% per art. 6:119 BW),
  `DunningPauseDispute` (pauze start/eind, reden, gepauzeerdDoor, evidenceRefs),
  `CreditScore` (optioneel: snapshot Graydon/Creditsafe/Atradius Insights per klant),
  `OninbaarAfschrijving` (afschrijving + BTW-teruggaaf art. 29 OB).
- Wettelijke compliance: 14-dagen-brief voor B2C per art. 6:96 lid 6 BW; BIK-staffel
  voor incassokosten per Besluit BIK (15%/10%/5%/1%/0.5%, minimum €40); handelsrente
  B2B per art. 6:119a BW (actuele ECB-rente + 8 pp); wettelijke rente B2C per
  art. 6:119 BW.
- 5-stage ladder met template-bibliotheek per stage en relatie-bewuste toon-gradient
  (vriendelijk → zakelijk → formeel → juridisch); ondernemer kan ladder per
  klant-groep aanpassen (bv. overheid: extended terms; VIP: geen stage 4/5).
- Partial-payment-verwerking: saldo-aanpassingen, incassokost-herberekening,
  ladder-doorloop op resterende bedrag.
- Dispute-pauzering: dunning paused totdat dispute opgelost; partial-settlement
  correct geboekt; ladder hervatten op resterende saldo.
- Evidence-trail: onveranderbare registratie per DunningRun (e-mail-headers,
  PDF-renderingen met SHA-256-hashes, optioneel digitaal ondertekend) voor
  gerechtelijke/incassoproceders.
- Anti-pattern-detector: detecteer onbedoelde escalatie (bv. admin-fout met IBAN)
  en contacteer klant proactief vóór juridische escalatie.
- Overheid-specifieke termijnen: 30-dagen-betaaltermijn per Wet betalingstermijnen
  overheid; ladder-aanpassingen per ARIV/ARVODI inkoopvoorwaarden.
- Betalingsregeling-onderhandeling bij stage 4: termijn-afspraken, gespreide bedragen,
  auto-administratie deelbetalingen, wanprestatie → auto-escalatie naar stage 5.
- Optionele credit-score-integratie: waarschuwing bij lage score, vooruitbetaling-advies.
- Optionele overdracht incasso via API: POST dossier-bundel (factuur, alle ladder-runs,
  evidence, klantgegevens) naar gekoppeld incassobureau (Bos Incasso, Atradius
  Collections, Intrum).
- Optionele aangetekende-post via PostNL API: stage 4 ingebrekestelling via PostNL
  Track & Trace, ontvangstbevestiging als evidence vastleggen.
- Oninbare afschrijving: definitive oninbaarheid-markering, afschrijving-boeking
  + BTW-teruggaaf per art. 29 OB voor eerstvolgende BTW-aangifte.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components,
  controllers, tests, and CI changes are deliberately not in this proposal;
  the task list references them but the implementation lands via a separate
  `opsx-apply` cycle.
- No PHP actuarial dunning-calculation service beyond the ADR-031 exception guard
  (declarative staffel-berekening via aggregatie-query).
- Multi-currency dunning — T5. T2 dunning is EUR-only.
- Real-time asset-management integrations (Bloomberg, FactSet) — T4.
- Mortaliteit/longevity-improvement modelling — scenario-inputs only.
- Regulatory filing automation (DNB rapportering, pensioenuitvoerder rapportages) — T4.
- Consumer-credit-regulation licensing (voor zuivere B2C-debiteuren op grote schaal) — out of scope.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-credit-control-dunning`** — declares the seven registers, the
dunning-workflow lifecycle (consuming OR's dunning-workflow), the BIK-staffel +
wettelijke-rente aggregations, dispute-pauzering, partial-payment verwerking,
ladder-overrides, evidence-trail, anti-pattern-detector, en oninbare-afschrijving.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is
prefixed `REQ-CCD-*` for traceability. Dutch legal references (BW art., Besluit,
Wet) are cited inline.

## New Dependencies

- **openregister** — `x-openregister-lifecycle` + `x-openregister-aggregations`
  for dunning stages + BIK-staffel + wettelijke-rente calculations.
- **docudesk** — dunning-templates + evidence-archival.
- **openconnector** — outbound dunning-channel API's (e-mail, PostNL aangetekende-post,
  incassobureau-API, credit-score-API).

## Impact

- `lib/Settings/shillinq_register.json` — adds 7 new schemas (`DunningLadder`,
  `KlantLadderOverride`, `DunningRun`, `IncassoKostenBerekening`, `DunningPauseDispute`,
  `CreditScore`, `OninbaarAfschrijving`); declares lifecycles + aggregations.
- `src/manifest.json` — adds 5 navigation entries + their `type: index` +
  `type: detail` pages.
- No new PHP dunning-calculation services (subject to ADR-031 exception: guardrails
  only on BIK-staffel compliance if OR's aggregation-calculation is not yet available).
- No new bespoke Vue components (manifest entries use standard detail/list layouts).

## Cross-Project Dependencies

- **openregister** — depends on `x-openregister-lifecycle` for dunning stages,
  `x-openregister-aggregations` for BIK-staffel + rente + aging-report calculations.
- **bookkeeping-accounts-receivable-core** — depends on AR master data (klanten,
  openstaande facturen, vervaldatum-tracking).
- **docudesk** — depends on dunning-template storage + PDF-rendering + evidence-archival
  per `bookkeeping-document-attachment-integration`.
- **openconnector** — depends on outbound channel connectors (e-mail SMTP, PostNL API,
  incassobureau-API, credit-score-API).

## Risks

### Risk 1: Incassokostenstaffel BIK berekening ontbreekt in OR

**Severity**: Medium

**Mitigation**: If OR's `x-openregister-aggregations` is not yet able to express
the per-schaal BIK-staffel calculation, the spec captures the gap, files an OR issue,
and the implementing cycle MAY ship a single-method `OCA\Shillinq\Service\BIKStaffelCalculator`
per ADR-031 §"PHP guards remain a legitimate seam". The service is removed once OR's
aggregation-calculation lands. Spec is shape-neutral.

### Risk 2: Wettelijke rente-berekening B2B/B2C ECB-rente-tracking

**Severity**: Low-Medium

**Mitigation**: ECB Main Refinancing Rate is published monthly; the app must fetch
the current rate at dunning-run-time or cache it with expiry. If no external ECB-API
integration is available, default rates per 1-1-2026 (11,5% B2B, 7% B2C) are
hard-coded with a "rates may be outdated" warning in the UI. T3 adds periodic
ECB-rate refresh.

### Risk 3: Per-klant ladder-overrides kunnen onbedoeld ingebrekestelling voorkomen

**Severity**: Low

**Mitigation**: Audit-trail op alle overrides; manager/controller role-gate op
stage 4/5 overrides; UI-warning "Klant exempted from stage 4/5 escalation".

### Risk 4: Dispute-pauze-einde-datum: hard-deadline (60 dagen) vs. handmatig

**Severity**: Low

**Mitigation**: Spec defines both: operator kan handmatig "dispute opgelost" markeren,
of ladder hervaatautomatisch na 60-dagenperiode. Audit-trail op beide paden.

### Risk 5: Partial-payment mismatch: klant betaalt minder dan verwacht

**Severity**: Medium

**Mitigation**: Bank-reconciliation (REQ-AP-008) matches op bedrag; API-guard voor
partial-payment-verwerking checkt saldo-match vóór ladder-hervatten. Audit-trail
op payment-reconciliation.

## Rollback

Dunning-escalaties zijn non-reversible eenmaal verstuurd (e-mail, post, incassoprocedure).
Rollback occurs only if the spec is rejected before any entity activates dunning in
production. Once live, corrections zijn journalised as handmatige dispute-pauzering
+ partial-settlement, niet deleties.

## Open Questions

1. **Actuarial input source for credit-score-API**: Direct API feed (v1) van Graydon/
   Creditsafe bij elke factuuraanmaak, of periodic batch pull (v2, goedkoper)?
   Recommend v1 real-time met caching voor performance.

2. **14-dagen-brief formatting**: Wettelijk verplichte placeholder-set per art. 6:96
   lid 6 BW — waar is deze template bijbehorend? Recommend in docudesk met
   vaste-content library (conform RJ Guidance).

3. **Overheid-specifieke ARIV/ARVODI-volgorde**: Verschillen per landsdeel/afdeling
   (gemeente vs. provincie vs. Rijk). Admin-configureerbare ladder per overheid-type?
   Recommend per-klant override + guidance.

4. **PostNL aangetekende-post bulk-handling**: Stage 4 kan 100+ ingebrekestellingen
   per dag genereren. PostNL API v1 single-item-post; batch-API beschikbaar?
   Recommend async queue + batch-consolidatie.

## Dependencies

- **bookkeeping-accounts-receivable-core**: AR invoices, klantmaster, betaalstatusses.
- **bookkeeping-general-ledger**: afschrijving-boekingen, oninbare-afschrijving-tabel.
- **bookkeeping-btw-aangifte**: BTW-teruggaaf art. 29 OB per oninbare-afschrijving.
- **docudesk**: dunning-template-opslag, evidence-archival (e-mail, PDF, digitaal-ondertekend).
- **openconnector**: incassobureau-API, credit-score-API (Graydon, Creditsafe,
  Atradius Insights), PostNL aangetekende-post-API.
- **openregister**: dunning-workflow lifecycle, `x-openregister-aggregations` voor
  BIK-staffel, wettelijke-rente, aging-reports.

## Success Criteria

- ZZP'er kan standaard 5-stage ladder activeren, templates personaliseren, e-mail-afzender
  instellen, test-bericht versturen, en dunning activeren zonder handmatige dunning-administratie.
- Ladder werkt correct voor B2B (incassokosten direct na verzuim) en B2C (14-dagen-brief
  verplicht vóór incassokosten).
- BIK-staffel-berekening correct (€8.400 → €795), wettelijke rente correct (B2B 11,5%,
  B2C 7%).
- Per-klant overrides met audit-trail: overheid automatisch extended terms, VIP klanten
  geen stage 4/5.
- Dispute-pauzering + partial-payment-verwerking werkt correct: ladder paused, saldo
  aangepast, hervaatná dispute-resolutie of 60-dagen-deadline.
- Evidence-trail compleet: alle dunning-runs, verstuurde templates, payment-matches,
  dispute-events gearchiveerd voor gerechtelijke/incasso-procedures.
- Overdracht incasso: dossier-bundel POST'ed naar incassobureau-API, ladder-status
  "OVERGEDRAGEN_INCASSO", factuur in shillinq als "in handen incasso" gemarkeerd.
- Anti-pattern-detector werkt: admin-fout (verkeerde IBAN, vergeten betalingskenmerk)
  detected, proactive klant-contact vóór juridische escalatie.
- Credit-score-integratie optioneel: waarschuwing bij lage score, deelfacturatie-advies.
- Oninbare-afschrijving: definitieve oninbaarheid-markering, afschrijving-boeking +
  BTW-teruggaaf voorbereiding voor eerstvolgende BTW-aangifte.
