# Tasks — DBA Compliance Marker

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `dba-compliance-marker` spec — they
> are recorded now so the spec-review gate, dependency planning, and tier-cascade
> impact are all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `dba-compliance-marker` capability spec already exists, no `DBAOpdracht`/`DBAIntake`/`DBAModelovereenkomst`/`DBARisicoflag`/`DBAPortfolioRisico`/`DBAEvidenceDossier` schemas are declared, and no `lib/Service/DBA*` or `lib/Service/Scoring*` / `lib/Service/Flag*` PHP classes are present (per ADR-031 anti-pattern enumeration)
- [x] Task 2: Author `specs/dba-compliance-marker/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` header, `REQ-DBA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite Wet DBA articles, Deliveroo-arrest (HR 24-3-2023), VBAR (2025/2026), AWR art. 52; explicitly address Dutch SMB DBA compliance + ZZP risk-management + opdrachtgever-inhuur safety
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (3-way match conditional, SEPA pain.001 downloadable, vendor-master ADR-022 question) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (per-opdracht intake + monitoring lifecycle), D2 (declarative risk scoring 0–100 with four bands), D3 (automated flag generation replaces manual judgment), D4 (intake verplicht before first factuur), D5 (modelovereenkomst register with versioning), D6 (portfolio-risico annual aggregation), D7 (evidence-dossier is stukkenlijst with file-refs + SHA-256), D8 (intermediair-mode optional, not MVP)
- [x] Task 5: Declare the `DBAOpdracht` schema in `lib/Settings/shillinq_register.json` with all REQ-DBA-001 fields (ondernemingId, klantId, opdrachtNaam, startDatum, verwachteEindDatum, feitelijkeEindDatum, verwachteOmzet, gerealiseerdeOmzet, modelOvereenkomstId, intakeStatus, intakeDatum, actueleRisicoscore, risicoNiveau, openFlags, evidenceDossierId, administrationId)
- [x] Task 6: Declare the `DBAIntake` schema in `lib/Settings/shillinq_register.json` with all REQ-DBA-003 fields (opdrachtId, ingevuldOp, ingevuldDoor, gezagsverhouding subtotals, persoonlijkeArbeid subtotals, financieelRisico subtotals, deliverooCriteria subtotals, totaalScore, maxScore, interpretatie)
- [x] Task 7: Declare the `DBAModelovereenkomst` schema in `lib/Settings/shillinq_register.json` with all REQ-DBA-002 fields (naam, bron, publicatieURL, goedkeuringDatum, geldigTot, essentieleBepalingen array, actueleVersie)
- [x] Task 8: Declare the `DBARisicoflag` schema in `lib/Settings/shillinq_register.json` with all REQ-DBA-004 fields (opdrachtId, type enum, detectieMoment, ernst, details object, fiscaleBron, actieSuggestie, status, weergegevenAanGebruiker)
- [x] Task 9: Declare the `DBAPortfolioRisico` schema in `lib/Settings/shillinq_register.json` with all REQ-DBA-005 fields (ondernemingId, peilDatum, actieveOpdrachten, concentratie object, langjarigeRelaties array, exclusieveRelaties count, overallRisico)
- [x] Task 10: Declare the `DBAEvidenceDossier` schema in `lib/Settings/shillinq_register.json` with all REQ-DBA-007 fields (opdrachtId, stukken array with type/fileRef/datum/sha256, compleetheidScore, bewaarTermijn, archiveDate)
- [x] Task 11: Add `x-openregister-lifecycle` to `DBAOpdracht` declaring intake workflow (draft → submitted → voltooid) per REQ-DBA-001; add `x-openregister-lifecycle` to `DBAIntake` (draft → submitted → completed)
- [x] Task 12: Declare risk-score calculation as `x-openregister-calculations` on `DBAIntake.totaalScore` per REQ-DBA-003 (sum gezagsverhouding + persoonlijkeArbeid + financieelRisico + deliverooCriteria subtotals); if engine cannot express, register single-method PHP `OCA\Shillinq\Lifecycle\DBAScoreCalculator::computeTotal(DBAIntake $intake): int` (ADR-031 exception)
- [x] Task 13: Declare compleetheids-score as `x-openregister-calculations` on `DBAEvidenceDossier.compleetheidScore` per REQ-DBA-007 (based on stuk-inventory; 0–1 scale)
- [x] Task 14: Implement daily background job for automated flag generation per REQ-DBA-004/-005/-006 (factuurfrequentie patterns, concentratie-waarschuwing, langjarige-hoofdrelatie, multiple-engagement-zelfde-concern); job generates immutable `DBARisicoflag` records
- [x] Task 15: Implement monthly background job for portfolio-aggregatie per REQ-DBA-005 (compute `DBAPortfolioRisico` for each active onderneming; aggregate omzetconcentratie, langjarige relaties, exclusiviteit patterns)
- [x] Task 16: Declare VBAR-grens constant (EUR 33, peil 2024) in `lib/Enums/DBAConstants.php` as mutable via administration settings per REQ-DBA-016
- [x] Task 17: Implement VBAR uurtarief-monitoring per REQ-DBA-016 (compute effective hourly rate on each factuur; generate flag if < EUR 33; block in hard-mode, warn in soft-mode)
- [x] Task 18: Seed `DBAModelovereenkomst` register with known Belastingdienst templates (tussenkomstvrij v3 – 2024, leverancier-zelfstandig v2, etc.) and allow operator upload of custom models per REQ-DBA-002
- [x] Task 19: Implement DBA intake wizard (3-step for eenmalig <€5k, 20-question for standard) per REQ-DBA-000/-001; enforce intake before first factuur; store answers in `DBAIntake` register
- [x] Task 20: Implement evidence-dossier curation UI (stukkenlijst with file-upload, type-selection, SHA-256 hash storage) per REQ-DBA-007; integrate with openregister file-api or docudesk
- [x] Task 21: Implement AVG-compliant email-archive opt-in per REQ-DBA-012 (explicit `ConsentRecord` for wederpartij communication; 7-year retention per AWR art. 52)
- [x] Task 22: Implement compliance-mode configuration per REQ-DBA-000 (soft/hard/intermediair modes; stored on administration config)
- [x] Task 23: Implement audit-rapport PDF generation per REQ-DBA-008 (intake summary, model-checklist, risk-score progression, flags, evidence inventory with SHA-256 hashes)
- [x] Task 24: Implement yearly herbeoordeling trigger per REQ-DBA-009 (notification on intake-anniversary for opdrachtnen >12 months; flag if no response within 30 days)
- [x] Task 25: Implement opdrachtgever-inhuur-intake mirror (optional MVP or later phase) per REQ-DBA-010; block PO at HOOG-risico in hard-mode
- [x] Task 26: Implement Belastingdienst WBA-integratie per REQ-DBA-013 (allow upload + storage of WBA assessment result; track validity period)
- [x] Task 27: Implement beëindiging-procedure per REQ-DBA-018 (mark opdracht ended, generate end-report, start 7-year retention-period clock per AWR art. 52)
- [x] Task 28: Implement tussenkomst-driehoek modelling (optional, later phase) per REQ-DBA-017 (separate intakes + risk-scores for ZZP–intermediair and intermediair–eindklant; Waadi/Wka flagging)
- [x] Task 29: Add 3 manifest navigation entries (`DBA Intake Wizard`, `DBA Portfolio Dashboard`, `Evidence Browser`) + their pages to `src/manifest.json` per REQ-DBA-001/-005/-007; `node tests/validate-manifest.js` exits 0
- [x] Task 30: Update `openspec/architecture/adr-000-data-model.md` with `DBAOpdracht`/`DBAIntake`/`DBAModelovereenkomst`/`DBARisicoflag`/`DBAPortfolioRisico`/`DBAEvidenceDossier` entries, reconciling against any existing DBA-related data-model entries
- [x] Task 31: Hook AP/AR factuurfrequentie-monitoring (optional) to trigger flag-generation (non-blocking if AP/AR not deployed)
- [x] Task 32: Hook AP/AR uurtarief-detectie (optional) to feed VBAR-grens check per REQ-DBA-016 (non-blocking if AP/AR not deployed)
- [x] Task 33: Documentation: Author `docs/user-guide/compliance/dba-compliance-marker.md` per ADR-030 journeydoc convention; include DBA intake flow, risk-scoring explanation, flag interpretation, evidence-dossier management, audit-rapport export
- [x] Task 34: i18n (Dutch `nl_NL` + English `en_US`): Translate all user-facing strings (Compliance Mode, Soft Mode, Hard Mode, DBA Intake, Risk Score, Risk Band names, Flag types + suggestions, Evidence Dossier, Audit Report, VBAR Threshold Warning, Portfolio Risk, Modelovereenkomst Register, etc.)

## Verification

`openspec validate` must exit clean on the change folder. Compliance-officer + Belastingdienst-
compliance-advisor peer review (e.g. dedicated persona testing) confirms the DBA flow, risk-
scoring, and flag logic match Dutch Wet DBA jurisprudentie (Deliveroo-arrest, PostNL-arrest,
etc.). Architecture reviewer confirms ADR-031 compliance (no parallel approval table; 
lifecycle declarative or ADR-031-exception-annotated guard; calculations declarative; 
manifest carries the navigation). No source code changes outside 
`openspec/changes/dba-compliance-marker/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`)
is responsible for:

- **PHPUnit unit tests** for DBA intake scoring (all three pillars + Deliveroo-criteria), risk-score
  computation (all four bands), flag-generation rules (factuurfrequentie patterns, concentratie,
  langjarigheid, VBAR), portfolio-aggregation (omzetconcentratie, exclusiviteit, multiple-engagement);
- **Playwright MCP browser tests** for DBA intake wizard (3-step eenmalig flow, 20-question standard
  flow), modelovereenkomst selection + essential-bepalingen checklist, evidence-dossier curation,
  audit-rapport generation, compliance-mode configuration, manifest navigation entries;
- **Audit-trail immutability tests** (flag-generation is append-only; risk-scores are versioned);
- **AWR retention-policy tests** (evidence-dossier deletion 7 years post-termination);
- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:
- `docs/user-guide/compliance/dba-compliance-marker.md` with DBA intake flow, risk-scoring explanation,
  flag interpretation, evidence-dossier best practices, audit-rapport export, VBAR threshold guidance;
- Screenshots: intake wizard screens, portfolio dashboard, evidence browser, audit-rapport sample.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`)
and English (`en_US`) translation strings for:

- `DBA Compliance`, `Compliance Mode`, `Soft Mode`, `Hard Mode`, `Intermediair Mode`,
- `DBA Intake`, `Risk Score`, `Risk Band` (`LAAG`, `LAAG_MIDDEN`, `MIDDEN_HOOG`, `HOOG`),
- `Authority/Control`, `Personal Service`, `Financial Risk`, `Deliveroo Criteria`,
- `Modelovereenkomst Register`, `Essential Bepalingen`, `Model Checklist`,
- `Evidence Dossier`, `Compleetheid`, `Audit Report`, `Audit Export`,
- `Flag: Factuur Frequency`, `Flag: Concentration`, `Flag: Long-term Relationship`, `Flag: VBAR Threshold`,
- `Flag: Substitutability`, `Flag: Multiple Engagement`, `Flag: Team Integration`, `Flag: Model Expired`,
- `Portfolio Risk`, `Omzetconcentratie`, `Langjarigheid`, `Exclusiviteit`,
- `DBA Portfolio Dashboard`, `DBA Intake Wizard`, `Evidence Browser`,
- `Yearly Reassessment`, `Termination Report`, `Retention Period`, `AWR Compliance`.
