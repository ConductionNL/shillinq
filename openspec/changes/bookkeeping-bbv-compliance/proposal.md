# Proposal: bookkeeping-bbv-compliance

`kind: spec` per ADR-032 — full BBV (Besluit Begroting en Verantwoording) compliance for decentrale overheden.

## Summary

Introduce **BBV Compliance** as a T3 capability transforming shillinq from a generic Dutch bookkeeping ERP into a fully compliant administrative system for **decentrale overheden**: provincies, gemeenten, waterschappen, and gemeenschappelijke regelingen. BBV is a statutory regulation rooted in article 186 of the Gemeentewet, article 190 of the Provinciewet, and article 99 of the Waterschapswet. This spec implements the full BBV apparatus: the mandatory taakveldenstructuur, the RGS-decentraal koppeling, multi-year (T+0 through T+3) budgeting with comparative periods, BBV-conforme classification of investeringen (MVA), reserves and voorzieningen, the verplichte paragrafen (7 mandatory sections), and the document generation pipeline for begroting, tussenrapportage, and jaarstukken. Without BBV compliance a gemeente cannot submit a legally valid jaarrekening to its raad, cannot receive an unqualified accountantsverklaring (rechtmatigheidsverklaring), and cannot be aggregated by CBS into the Iv3 macro-statistics.

**Depends on:** T1 `bookkeeping-chart-of-accounts`, T1 `bookkeeping-general-ledger`. Feeds into sibling specs `bookkeeping-iv3-reporting`, `bookkeeping-procurement-rechtmatigheid`, `bookkeeping-subsidie-management`, `bookkeeping-grondexploitatie`.

## Motivation

Every Dutch gemeente, provincie, waterschap, and gemeenschappelijke regeling operates under the statutory BBV framework dictating *how* the organisation must structure its programmabegroting (programme budget), jaarrekening (annual accounts), meerjarenraming (multi-year forecast), and supporting administration. The BBV consists of:

- **Taakveldenstructuur** (function-based budget classification): 53 taakvelden for gemeenten, 14 for waterschappen, etc., each with verplichte economische categorieën (cost types).
- **RGS-decentraal koppeling** (chart-of-accounts mapping): every G/L account links to an RGS-decentraal D-code establishing the wettelijke positie (legal position).
- **Meerjarenraming** (multi-year forecast): T+0 through T+3 years, all four structureel en reëel sluitend (structurally and actually balanced).
- **Investeringen, Reserves, Voorzieningen** (MVA, reserves, provisions): distinct classification rules and mutatie routes per BBV art. 44, art. 59, art. 60.
- **Paragrafen** (7 mandatory sections): Lokale heffingen, Weerstandsvermogen, Onderhoud kapitaalgoederen, Financiering, Bedrijfsvoering, Verbonden partijen, Grondbeleid (art. 9 BBV).
- **Rechtmatigheidsverantwoording** (2023 onwards): governance and public bodies now provide their own assurance statement covering budgetary, regulatory, and M&O legality.
- **Iv3-aanlevering aan CBS** (quarterly and annual data submission): XBRL-formatted GL aggregations per taakveld × economische categorie, mandatory within 1 month (Q) or 15 July (annual).
- **SiSa-bijlage** (Single information Single audit): accounting for ~50 national subsidy schemes with fixed indicator sets.

## Affected Projects

- [x] Project: shillinq — adds 9 new schemas (`Programma`, `Taakveld`, `EconomischeCategorie`, `RgsDecentraalRekening`, `MeerjarenBudget`, `Reserve`, `Voorziening`, `MaterieleVasteActiva`, `Subsidie`, `Begrotingswijziging`, `BeleidsIndicator`) to `lib/Settings/shillinq_register.json` (register: `bookkeeping-bbv`), extends T1 `Account` with BBV-specific fields (taakveld, economische_categorie, rgs_decentraal_rekening, bbv_classificatie), and extends `JournalEntry` with mandatory taakveld+economische_categorie validation for exploitatie boekingen. Implements multi-year budgeting, BBV-constrained mutatie routes (reserves vs voorzieningen), MVA depreciation rules, and paragrafen template system.
- [ ] Project: openregister — no source changes; consumes lifecycle preconditions, cross-schema constraints, and aggregation pipelines.
- [ ] Project: decidesk — integration via ADR-019 registry to expose jaarstukken and begrotingswijzigingen as agenda items; raadsbesluiten back-synced to track approval chain.

## Scope

### In Scope

- Eleven new capability specs under `bookkeeping-bbv` register: `Programma`, `Taakveld`, `EconomischeCategorie`, `RgsDecentraalRekening`, `MeerjarenBudget`, `Reserve`, `Voorziening`, `MaterieleVasteActiva`, `Subsidie`, `Begrotingswijziging`, `BeleidsIndicator`.
- Extension of T1 `Account` (from `bookkeeping-chart-of-accounts`): mandatory fields `rgs_decentraal_rekening` (ref[RgsDecentraalRekening]), `taakveld` (ref[Taakveld] for exploitatie accounts), `economische_categorie` (ref[EconomischeCategorie]), `bbv_classificatie` (enum: exploitatie | investering | reserve | voorziening | balans-overig).
- BBV-conforme validation on `JournalEntry.post`: every exploitatie-regel must carry valid taakveld + economische_categorie; balans- and reserve/voorziening-boekingen exempt from taakveld-plicht.
- Meerjarenraming (T+0 through T+3) enforcing structureel en reëel sluitend constraint at publication.
- Reserves (algemeen, bestemming) with mutatie routes via taakveld 0.10 (resultaatbestemming).
- Voorzieningen (4 BBV art. 44 categories) with dotatie/vrijval via exploitatie routes.
- MVA-administratie (economisch-nut, economisch-nut-heffing, maatschappelijk-nut) with componentenmethode, afschrijving start post ingebruikname.
- Subsidie-administratie (verstrekt incidenteel/structureel, ontvangen rijk/provincie/EU) with SiSa-bijlage koppeling.
- Seven mandatory paragrafen (Lokale heffingen, Weerstandsvermogen, Onderhoud kapitaalgoederen, Financiering, Bedrijfsvoering, Verbonden partijen, Grondbeleid) with auto-computed fields (weerstandsratio, etc.).
- Vergelijkende periode support (realisatie T-1, primitieve begroting T, begroting na wijziging T, realisatie T, verschil) with stelselwijziging recovery.
- Rightmatigheidsverantwoording per 2023 (begrotings-, voorwaarden-, M&O rechtmatigheid) with raad-configured rapportagegrens and goedkeuringstolerantie.
- Iv3-aanlevering (Q+Y) XBRL-instance generation, taxonomy validation, Kredo-koppeling handoff.

### Out of Scope

- **Sectoral BBV variants** (housing corp, healthcare, education): ADR-032 constraint; roadmap T4+.
- **Consolidated reporting** across multiple decentrale overheden: sibling spec `consolidated-financial-reporting`.
- **GBA/BRP/BRK integration**: handled by `government-public-sector` spec.
- **Organisational policy rules** (e.g. gemeente-specific raadsbesluit templates): policy library separate.

## Approach

One delta with ADDED Requirements under `REQ-BBV-001` through `REQ-BBV-011`.

## New Dependencies

- T1 `bookkeeping-chart-of-accounts` — FK targets for Account.
- T1 `bookkeeping-general-ledger` — JournalEntry lifecycle extension.
- Sibling `bookkeeping-iv3-reporting` — Iv3 XBRL rendering and Kredo submission.
- Sibling `bookkeeping-procurement-rechtmatigheid` — M&O-fouten for rightmatigheidsverantwoording.
- Sibling `bookkeeping-subsidie-management` — SiSa indicator aggregation.
- Sibling `bookkeeping-grondexploitatie` — MVA endwaarde for Grondbeleid paragraaf.

## Impact

- `lib/Settings/shillinq_register.json` — adds 9 new schemas + extends T1 Account with 4 fields + extends JournalEntry post-lifecycle with taakveld+economische_categorie validation.
- `lib/Settings/seeds/bbv-taakvelden-*.json` — taakveld catalogue per overheidslaag (53 for gemeente, 14 for waterschap, etc.) per Iv3 informatievoorschrift.
- `lib/Settings/seeds/rgs-decentraal-*.json` — official RGS-decentraal D-codes imported annually from SBR/Logius.
- `lib/Settings/seeds/economische-categorieen-*.json` — Iv3 economische categorieën (ca. 150 codes).
- `lib/Settings/seeds/beleidsindicatoren-*.json` — 39 BBV-verplichte beleidsindicatoren per Regeling Beleidsindicatoren.
- New manifest navigation: `Financieel > BBV-compliance` with sub-pages for programma-planning, meerjarenraming, paragrafen-editor, reserves/voorzieningen-administratie, MVA-register, rightmatigheidsverantwoording-concept.
- New API endpoints for meerjarenraming balance-checks, sluitend-validation, paragraaf generation, Iv3 export, SiSa-bijlage generation.
- Repair step to import BBV stam-data (taakvelden, RGS-decentraal, economische categorieën, beleidsindicatoren) for new BBV-tenant registrations.

## Cross-Project Dependencies

- **OpenRegister** — relies on lifecycle preconditions for post-validation, cross-schema constraints (uniqueness, FK presence), and declarative aggregations for reporting totals.
- **Decidesk** (via ADR-019) — jaarstukken/begroting published as agenda-items; raadsbesluiten on begrotingswijzigingen/jaarrekening-vaststelling synced back.
- **Openconnector** — RGS-decentraal imports, SiSa-bijlage imports from BZK, Iv3-XBRL submission to CBS via Kredo SOAP/REST.
- **Docudesk** — PDF/A-3 jaarrekening + XBRL archived with 7-year retention per Archiefwet.

## Risks

### Risk 1: BBV taakveld catalogue revision (2025 → 2027)
**Severity**: Low
**Mitigation**: Seed filename versioning (bbv-taakvelden-2025.json → bbv-taakvelden-2027.json). `_meta.iv3Version` tag per taakveld row. Coexistence trivial.

### Risk 2: Rechtmatigheidsverantwoording tolerance drift
**Severity**: Medium
**Mitigation**: Rapportagegrens and goedkeuringstolerantie are raad-configured per tentant (stored in Administration). Default 1%/3% per Kadernota. Change audit-trailed.

### Risk 3: Pre-existing GL postings without BBV mapping
**Severity**: Low
**Mitigation**: Precondition forward-only by `postingDate ≥ install date`. Back-fill is operator workflow (separate change).

### Risk 4: Iv3-taxonomie updates mid-year
**Severity**: Low
**Mitigation**: CBS publishes taxonomy updates; Iv3-aanlevering cycle cached at export-time. Schema version stored in XBRL instance metadata.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the change folder. After implementation: revert the PR, run the repair step in down-direction (optional; existing records remain queryable). Registers are non-destructive.

## Open Questions

1. **Programma/Paragraaf cardinality** — REQ-BBV-002 makes `paragraafCode` optional; confirm with gemeente-controller persona during spec review.
2. **Meerjarenraming forward-fill logic** — should T+1/T+2/T+3 be auto-propagated from T+0 with inflation adjustment, or manually entered per gemeente? Confirm with strateeg persona.
3. **Stelselwijziging recovery scope** — how many prior periods should be recomputed when taakveld catalogue changes mid-administration? Confirm scope with controller + accountant.
4. **Paragraaf Grondbeleid coupling** — requires `bookkeeping-grondexploitatie` spec to be defined first; gate dependency in planning.
