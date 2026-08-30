# Proposal: bookkeeping-titel-9-jaarrekening

## Summary

Introduce the **Titel 9 annual financial statement (jaarrekening) generation** capability for Shillinq, enabling Dutch commercial entities (BV, NV, coöperatie, stichting) to automatically generate, review, and file annual accounts conforming to Titel 9 Boek 2 Burgerlijk Wetboek (Dutch Commercial Law art. 2:361–2:401). This change declares six new registers (`AnnualReport`, `BalanceSheet`, `IncomeStatement`, `CashFlowStatement`, `Note`, `DirectorReport`) and supporting workflow (`ReviewWorkflow`), generators for statutory balance sheet and income statement models, toelichting (note) templates keyed to groottecategorie (size category), kasstroomoverzicht (cash flow statement) for middelgroot+ entities, and deponering (KVK filing) integration via SBR-XBRL per `bookkeeping-sbr-xbrl-reporting`.

This change declares the data shapes and templates as OpenRegister schemas and configuration; implementation of generation logic and XBRL conversion follows in the implementing cycle.

## Motivation

Dutch annual financial statements are **mandatory, wettelijk-specifiek (legally specific), and routinely produced but expensive**. Today, small-to-midsize businesses outsource jaarrekening preparation to samenstel-accountants at €1.500–4.000 per year, even when source bookkeeping is perfect. Titel 9 mandates exact balans (balance sheet) and V&W (income statement) models; wettelijke toelichting (statutory notes) keyed to groottecategorie (micro, klein, middelgroot, groot); kasstroomoverzicht (only for middelgroot+); and SBR-XBRL electronic filing at KVK.

Shillinq's Titel 9 jaarrekening-generatie **closes this gap** by:
1. Automating groottecategorie classification per art. 2:395a–398 BW
2. Generating wettelijk-conforme balans and V&W from the general ledger
3. Auto-generating toelichting paragrafen (notes) with mandatory disclosures
4. Generating kasstroomoverzicht and bestuursverslag (director's report) for middelgroot+ entities
5. Supporting accountant review workflows with change tracking
6. Converting to SBR-XBRL and filing at KVK via Digipoort

A small-business owner with a kleine BV can now prepare, sign, and file their jaarrekening entirely within Shillinq, eliminating €1.500+ in annual samenstel costs.

## Affected Projects

- [x] **shillinq** — adds 6 new registers (`AnnualReport`, `BalanceSheet`, `IncomeStatement`, `CashFlowStatement`, `Note`, `DirectorReport`) to `lib/Settings/shillinq_register.json`; declares associated schemas with groottecategorie-keyed templates; adds review workflow schema (`ReviewWorkflow`); adds jaarrekening-generation service interfaces (implementation in opsx-apply cycle); extends `src/manifest.json` with Jaarrekening navigation; seeds groottecategorie-determination logic.
- [x] **bookkeeping-sbr-xbrl-reporting** (dependency, already proposed) — consumes the final `AnnualReport` snapshot and converts to SBR-XBRL per KVK taxonomy, handles Digipoort submission, receives deponering status updates.
- [ ] **openregister** — this change consumes `x-openregister-lifecycle` for workflow state transitions (concept → vastgesteld → gedeponeerd), audit-trail-immutable for jaarrekening snapshots, RBAC for accountant vs. bestuur vs. general access.

## Scope

### In Scope

- **Proposal, Design, Spec, Tasks** (this change) — defines requirements and data model; implementation follows in opsx-apply cycle.
- **Register declarations**: `AnnualReport` (with groottecategorie, status, dates, accountant reference), `BalanceSheet` (wettelijke rubrieknummering art. 2:373 BW, activa/passiva structure, comparatief), `IncomeStatement` (model A categorisch / model E functioneel per art. 2:377 BW), `CashFlowStatement` (indirect method, three cashflow categories per RJ 350), `Note` (toelichting-paragraaf with wettelijke-basis field), `DirectorReport` (bestuursverslag per art. 2:391 BW for middelgroot+).
- **Groottecategorie classification** schema and logic per art. 2:395a–398 BW (two-of-three criteria over two consecutive years: balanstotaal, netto-omzet, gemiddeld aantal werknemers).
- **ReviewWorkflow** schema for orchestrating concept → in-review (accountant) → vastgesteld (AV) → gedeponeerd (KVK) progression with audit trail per step.
- **Seed configuration** for groottecategorie-determination rules, mandatory toelichting-templates per groottecategorie, balans/V&W rubriek mappings from rekeningschema → wettelijke rubrieken.
- **Manifest navigation** entries under `Bookkeeping > Jaarrekening` (Annual Report list/detail pages, review workflow interface).
- **Cross-module integration points** with `bookkeeping-financial-statements` (T1 balans/V&W data sources), `bookkeeping-sbr-xbrl-reporting` (XBRL conversion), `bookkeeping-grootboek` (GL rekeningschema).

### Out of Scope

- **Implementation code** (generator services, XBRL converters, Digipoort client) — this change specifies what; opsx-apply implements how.
- **XBRL taxonomy or Digipoort submission details** — owned by `bookkeeping-sbr-xbrl-reporting`.
- **Consolidated jaarrekening** (groepjaarrekening) — owned by future `bookkeeping-consolidation-commercial`.
- **Fiscal jaarrekening** (VPB/IB aangifte) — future separate module.
- **Toelichting-content authoring UI** — manifest detail pages render forms; content entry is operator responsibility.

## Approach

One new spec describing all registers, templates, and workflow:

**`bookkeeping-titel-9-jaarrekening`** — declares the six registers, groottecategorie-classification logic, mandatory toelichting schemas keyed to groottecategorie, balans/V&W rubriek structure, kasstroomoverzicht shape, bestuursverslag sections, and review workflow state machine. Each requirement follows RFC 2119 format with `REQ-T9-NNN` numbering and GIVEN/WHEN/THEN scenarios.

### Design Principles (from context-brief analysis)

- **Automatisering met handmatig-override**: Concept jaarrekening is real-time (updates as GL changes); once opgemaakt (signed by bestuur), it becomes immutable snapshot.
- **Groottecategorie-bestuurde template-selectie**: Micro-BV gets verkorte balans only; klein gets balans + beperkte toelichting; middelgroot+ gets volledige jaarrekening + bestuursverslag + kasstroomoverzicht; groot gets CSRD/ESG-ready template.
- **Wettelijke rubrieken as first-class data**: The exact rubriek codes (B.I.1, C.III.2, etc.) from art. 2:373 BW are stored; mapping from operator's rekeningschema to these rubrieken is configuration, not code.
- **Accountant review as workflow**: Not a separate "accountant mode" but a ReviewWorkflow record with step-by-step tracking, audit trail per change, and role-based access (accountant read-only on source, edit capability on review items).

## New Dependencies

- **`@conduction/openregister`** — `x-openregister-lifecycle` for workflow state transitions, audit-trail-immutable for jaarrekening snapshots, RBAC for role-based access.
- **RJ (Raad voor de Jaarverslaggeving) knowledge base** — toelichting-templates reference RJ guidelines (RJ 210, 212, 220, 240, 250, etc.); configuration seeds embed RJ section keys.

## Impact

- **`lib/Settings/shillinq_register.json`** — adds 6 schemas (`AnnualReport`, `BalanceSheet`, `IncomeStatement`, `CashFlowStatement`, `Note`, `DirectorReport`), `ReviewWorkflow` schema for step orchestration. Additive; no changes to existing T1/T2/T3 registers.
- **`lib/Settings/seeds/`** — groottecategorie-classification config, balans-rubriek mappings, V&W-rubriek mappings, toelichting-template registry per groottecategorie.
- **`src/manifest.json`** — navigation entries under `Bookkeeping > Jaarrekening` (Annual Report index/detail, Review Workflow detail).
- **`openspec/specs/bookkeeping-titel-9-jaarrekening/`** — new spec directory.

## Cross-Project Dependencies

- **`bookkeeping-financial-statements` (T1 output)** — provides aggregated balans/V&W per rubriek; this module wraps in statutory structure.
- **`bookkeeping-sbr-xbrl-reporting`** — consumes final `AnnualReport` snapshot and converts to XBRL; handles Digipoort API.
- **`bookkeeping-grootboek` (GL input)** — provides general ledger lines; rekeningschema → wettelijke-rubriek mapping is configuration.
- **`openregister` (platform)** — provides lifecycle, audit-trail, RBAC, ScheduledWorkflow abstractions.

## Risks

### Risk 1: Groottecategorie misclassification impairs legal compliance

**Severity**: High  
**Mitigation**: Classification logic per art. 2:395a–398 BW (two-of-three criteria over two consecutive years) is declarative seed configuration, reviewable by compliance team. System displays the classification with underpinning numerics and allows operator override with motivatie. Accountant review gate (REQ-T9-007) catches classification errors before filing.

### Risk 2: Toelichting-template coverage incomplete for niche entities

**Severity**: Medium  
**Mitigation**: Core RJ guidelines (RJ 210–272, 350) are seeded as mandatory-template registry; operator can author custom sections. Accountant review gate catches missing mandatory disclosures. T4 governance: if a niche sector arises, an RJ-domain expert author adds the toelichting-template; no emergency patches needed.

### Risk 3: Balans/V&W rubriek-mapping misconfiguration renders jaarrekening non-compliant

**Severity**: High  
**Mitigation**: Rekeningschema → wettelijke-rubriek mappings are configuration (seeds), reviewed by bookkeeper-persona and compliance-persona at opsx-plan stage. Cross-validation: when BalanceSheet is generated, system checks that all GL accounts have been mapped to a rubriek; unmapped accounts trigger a warning. Task 5 includes a mapping audit task.

### Risk 4: SBR-XBRL conversion errors cause KVK deponering rejection

**Severity**: Medium  
**Mitigation**: XBRL conversion logic owned by `bookkeeping-sbr-xbrl-reporting` module; this module provides the canonical jaarrekening snapshots. REQ-T9-008 specifies validation and error-handling at the XBRL boundary; if KVK rejects, error-detail is parsed and mapped back to source field for operator correction.

### Risk 5: Accountant workflow UX is confusing; accountant edits conflict with bestuur edits

**Severity**: Medium  
**Mitigation**: ReviewWorkflow is a strict linear state machine (concept → in-review → vastgesteld → gedeponeerd); during `in-review`, bestuur cannot edit without cancelling review. Review comments are immutable change log per issue (REQ-T9-007). Conflict resolution: bestuur cancels review, makes changes, resubmits.

### Risk 6: Capacity: Toelichting-generation complexity (MVA-verloop, EV-mutation matrix, debt schedule, etc.) is underestimated

**Severity**: Low  
**Mitigation**: Toelichting templates are structured records (not free-text blocks). MVA-verloop, EV-mutation, debt-schedule are seed-provided schema templates; implementation cycle decomposes into per-template tasks. If a template proves complex (e.g., segment reporting for CSRD), it can be deferred as a separate opsx-apply batch.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder. After implementation (separate opsx-apply cycle), rollback follows the standard pattern: revert the implementing PR. The new registers are optional in the sense that existing T1/T2/T3 administrations continue unchanged; jaarrekening-generatie is opt-in per administration.

## Open Questions

1. **Geconsolideerde jaarrekening (consolidated) scope** — REQ-T9-010 is enkelvoudige only. When does `bookkeeping-consolidation-commercial` start? Confirm it is a separate change.
2. **XBRL entry-point per groottecategorie** — KVK specifies different XBRL entry-points for micro/klein/middelgroot/groot (e.g., NT16-Klein-KVK, NT16-Groot-KVK). Does `bookkeeping-sbr-xbrl-reporting` handle entry-point selection, or does this module? Clarify in design review.
3. **Bestuursverslag automatic sections** — REQ-T9-006 specifies template sections (financiële gang, risico's, toekomst, personeel, milieu, R&D, ESG). Do we generate automatic content (e.g., YoY revenue delta, headcount from HR module) or only provide empty templates? Settled in design review.
4. **Accountant sign-off format** — REQ-T9-007 mentions NV-COS 700 controleverklaring. Is the controleverklaring authored in Shillinq or in the accountant's own software and then attached? Clarify accountant UX boundary.
5. **Time-to-deposition deadline tracking** — REQ-T9-010 specifies wettelijke termijnen (5 months opmaak, 7 months vaststelling, 8 days deponering). Is deadline-reminder orchestration a workflow scheduler or a simple progress-bar UI? Clarify scope.
