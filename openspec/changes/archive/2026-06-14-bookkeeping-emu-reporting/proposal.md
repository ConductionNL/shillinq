# Proposal: bookkeeping-emu-reporting

`kind: financial` — Automated EMU-saldo and EMU-schuld reporting pipeline for Dutch decentrale overheden under Wet Houdbare Overheidsfinanciën. Converts BBV accrual-basis general ledger to cash-basis EMU-saldo via macro-rules and transaction-level adjustments. Generates quarterly EMU-saldo aangifte (kwartaalenquête) and annual EMU-schuld position (bruto debt). Includes automated CBS XBRL indiening, reconciliation with BBV jaarrekening, and afwijkingsalert on referentiewaarde overschrijding.

## Summary

Introduce automated EMU reporting capability for Nederlandse decentrale overheden (gemeenten, provincies, waterschappen, gemeenschappelijke regelingen). The spec declares four core entities: `EMUReport` (draft/concept/ingediend aangifte), `EMUAdjustment` (accrual→kas conversie rules per Wet Hof art. 3), `CashFlowItem` (kasstroom geclassificeerd naar IV3), `DebtPosition` (schuld per instrument per peildatum). 

Pipeline: BBV-grootboek → automatic quarterly EMU-saldo draft → user review/adjustment → signed indiening via CBS XBRL/Digipoort. Includes afwijkingsdetectie t.o.v. meerjarenraming, intercompany-eliminatie voor gemeenschappelijke regelingen, en reconciliatie BBV↔EMU.

The capability materialises a cash-flow reporting surface conforming to Wet Hof art. 3 & 5, CBS-enquête EMU specifications, Eurostat ESA2010 classificatie, en IV3 taxonomie. Cross-app integration with bookkeeping-bbv (foundation), bookkeeping-iv3-reporting (shared taxonomie), bookkeeping-schatkistbankieren (schuld), bookkeeping-begroting-meerjaren (afwijking detection).

## Motivation

**Regulatory mandate:** Nederlandse decentrale overheden moeten onder Wet Houdbare Overheidsfinanciën periodiek hun EMU-saldo (kassaldo: ontvangsten minus uitgaven) en EMU-schuld (bruto schuldpositie) aan het CBS rapporteren. CBS aggregeert deze rapportages voor Notificatie EDP richting Eurostat, die toetst of Nederland binnen Europese Stabiliteits- en Groeipact-normen blijft (3% BBP tekort, 60% BBP schuld).

**Current pain:** EMU-rapportage is handmatig: financieel beleidsmedewerker exporteert BBV-grootboek, past ad-hoc spreadsheet-regels toe voor accrual→kas conversies (afschrijvingen eruit, bruto investeringen erin), reconcilieert manueel met begroting en voorgaand jaar, en vult CBS-enquête handmatig in. Foutgevoelig. Geen audit-trail. Lastig voor accountant om te controleren.

**Market intelligence:** Alle Nederlandse gemeenten/provincies/waterschappen met EMU-rapportageplicht hebben dit probleem. Opmaak gebeurt in December-Maart (voor Q4/jaar, in te dienen in Maart/April). Geen standaard softwareoplossing beschikbaar (CBS stelt alleen enquête-template beschikbaar).

**Product opportunity:** Automatiseren van deze pipeline geeft concerncontroller zekerheid, accountant auditability, en CBS snellere/accuratere rapportages.

## Affected Projects

- [x] Project: Shillinq bookkeeping app — adds 1 capability spec (`bookkeeping-emu-reporting`); declares 4 new entities (`EMUReport`, `EMUAdjustment`, `CashFlowItem`, `DebtPosition`) in ADR-000 data model; adds EMU-rapportage surface in UI.
- [ ] Project: openregister — consumes Account, GLLine, Budget entities; no new registers required.
- [ ] Project: openconnector → SBR/Digipoort — future connector for XBRL indiening; declarative route per ADR-002.

## Scope

### In Scope

- Automatic quarterly EMU-saldo draft generation within 5 working days of quarter-end from BBV-grootboek.
- Accrual→kas conversie per Wet Hof art. 3: macro-rules (eliminatie afschrijving, eliminatie voorzieningdotatie, toevoeging bruto-investering, toevoeging aflossing, eliminatie boekwinst, intercompany-eliminatie) + transaction-level `EMUAdjustment` records.
- EMU-saldo presentatie per CBS-template (10 verplichte tussenregels).
- EMU-schuld berekening (bruto nominaal) conform ESA2010: AF.2 (deposito's, schatkistbankieren negatief), AF.3 (obligaties), AF.4 (leningen).
- IV3-classificatie (hoofdstuk-functie-categorie) as shared taxonomy with IV3-rapportage.
- Vergelijking kwartaal-saldo met meerjarenraming (absolute + procentuele afwijking); top-3 contributor adjustment detection.
- Alert bij individuele EMU-referentiewaarde overschrijding (80% cumulatief) of macro-sectornorm dreigt.
- Intercompany-eliminatie voor gemeenschappelijke regelingen (consolidatie S.1313).
- Reconciliatie jaarlijkse EMU ↔ BBV jaarrekening (sommatie 4 kwartaal-EMU = BBV-saldo + adjustments).
- Digitale ondertekening en indiening via CBS XBRL/Digipoort (requires SBR/openconnector integration, declarative).
- Audit-trail en bewaarplicht 10 jaar (WORM-archief handoff to docudesk).

### Out of Scope

- **SBR/Digipoort connector implementation** — declarative route only; implementation in openconnector project.
- **Multi-entity consolidation UI** — XBRL rendering of consolidation group; future feature.
- **Template-based manual adjustments UI** — end-user macro-rule wizard; design phase TBD.
- **Mobile/native client** — web app only.
- **Predictive EMU modeling** — "what-if" scenario analysis roadmap item for T3.

## Approach

One spec, adding ADDED requirements to a brand-new capability:

**`bookkeeping-emu-reporting`** — declares the four entities (`EMUReport`, `EMUAdjustment`, `CashFlowItem`, `DebtPosition`), auto-generation pipeline, adjustment rules per Wet Hof art. 3, IV3-taxonomie classificatie, reconciliatie algorithm, afwijkings-detection logic, en XBRL indiening surface.

Each requirement is prefixed `REQ-EMU-NNN` for traceability. Adjustments are REQ-EMU-002. Quarterly pipeline is REQ-EMU-001. Schuld is REQ-EMU-004. Reconciliatie is REQ-EMU-009. Etc.

## New Dependencies

- Consumes: Account, GLLine, Budget, Administration entities (already in ADR-000).
- Depends on: bookkeeping-bbv (foundation), bookkeeping-begroting-meerjaren (budget lookup), bookkeeping-verbonden-partijen (GR/consolidation lookup), bookkeeping-schatkistbankieren (debt data sync).
- Conditional: openconnector SBR/Digipoort (future connector).

## Impact

- `lib/Settings/shillinq_register.json` — adds 4 new schemas (`EMUReport`, `EMUAdjustment`, `CashFlowItem`, `DebtPosition`).
- `openspec/architecture/adr-000-data-model.md` — documents 4 new entities.
- Scheduler: new cron job for quarterly EMU-saldo draft generation (5 days after quarter-end, 06:00 UTC).
- API endpoints: GET/POST EMUReport, GET EMUAdjustment list, PATCH EMUReport (user corrections), POST "submit to CBS" action.
- PHP service class: `EMUReportingService` for pipeline orchestration, adjustment application, reconciliatie calculation.

## Cross-Project Dependencies

- **bookkeeping-bbv** — foundation; provides GLLine, Account, saldo berekening.
- **bookkeeping-iv3-reporting** — shares IV3 taxonomie + CashFlowItem entity.
- **bookkeeping-schatkistbankieren** — provides daily schatkistbankieren saldo (DebtPosition sync).
- **bookkeeping-begroting-meerjaren** — provides begroot-saldo for afwijkings-vergelijking.
- **bookkeeping-verbonden-partijen** — provides GR/related-party info for intercompany-eliminatie.
- **openconnector** — future SBR/Digipoort connector (declarative route only in this spec).

## Risks

### Risk 1: CBS-enquête template changes mid-year

**Severity**: Medium
**Mitigation**: CBS publiceert jaarlijks op 1 januari. Spec locks 2026 template; version field on `EMUReport.cbsTemplateVersion`. Next year spec change handles new template.

### Risk 2: Afschrijving/voorzieningendotatie rules interpretation differs per gemeente

**Severity**: High
**Mitigation**: Macro-rule configurable per organisation. Concerncontroller can override regel-per-regel. Audit-trail logs every adjustment. Accountant signs off.

### Risk 3: Consolidation group (GR) data stale → wrong elimination

**Severity**: Medium
**Mitigation**: Sync GR registratie from bookkeeping-verbonden-partijen weekly. Alert if GR status changes mid-quarter. Manual review before submission.

### Risk 4: XBRL indiening faalt → município's deadline at risk

**Severity**: High
**Mitigation**: Dry-run validation before final indiening. Fallback to manual CSV export for emergency submission to CBS. Support team escalation process.

## Rollback Strategy

Spec-only change. To roll back: revert this change commit; delete EMUReport/EMUAdjustment/CashFlowItem/DebtPosition from data model; drop scheduler job. No data migration. After implementation lands, rollback is: revert implementing PR; existing EMU reports remain in docudesk archief (WORM, no delete).

## Open Questions

1. **Concerncontroller authority** — can individual user adjust EMU-adjustments or only in batch via Excel import? Resolved during design review.
2. **Schatkistbankieren sync cadence** — daily or per-quarter-end? Resolved during architecture discussion with treasury team.
3. **Revision aangifte process** — auto-generate correction aangifte on late GL entry or require manual trigger? Resolved during UX design.
4. **GR consolidation scope** — apply to all GRs in S.1313 or operator-configurable? Resolved per Wet Hof guidance during design.
