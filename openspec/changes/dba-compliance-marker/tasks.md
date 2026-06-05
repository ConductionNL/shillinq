# Tasks — DBA Compliance Marker

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `dba-compliance-marker` spec — they
> are recorded now so the spec-review gate, dependency planning, and tier-cascade
> impact are all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

> **Implementation note (ADR-037 / ADR-031 / ADR-022).** Tasks 5–10, 12–13 and
> 18 name `lib/Settings/shillinq_register.json` (the monolith). Per **ADR-037** the
> six schemas, lifecycles, calculations, aggregations and all seed objects were
> declared in the modular fragment `lib/Settings/register.d/dba-compliance-marker.json`
> instead — the monolith is never edited. The `SettingsService::deepMergeConfig`
> loader already unions `components.schemas` by key and concatenates
> `components.objects[]`, and OpenRegister's `ImportHandler` reads seeds from
> `components.objects[]` (verified), so no loader change was needed. Per **ADR-031**
> the centre of mass is declarative; the single PHP exception-path file
> `lib/Lifecycle/DBAComplianceGuard.php` carries the band-derivation, first-factuur
> gate, VBAR breach and completeness ratio the declarative DSL cannot yet express
> (Task 16's VBAR constant lives on that guard as `VBAR_GRENS_EUR`, admin-overridable
> via app config `dba_vbar_grens`). Per **ADR-022** object reads use the real
> ObjectService API (`setRegister`/`setSchema`/`findAll`) only. A klant is a
> Nextcloud-addressbook contact (`klantId`), never a bespoke person schema.

- [x] Task 1: Confirmed no prior `dba-compliance-marker` spec/schemas/`DBA*` service classes exist; the only PHP shipped is the ADR-031 exception-path `DBAComplianceGuard` (no `DBAService`/`ScoringService`/`FlagEngine`).
- [x] Task 2: `specs/dba-compliance-marker/spec.md` authored — proposed/shillinq/T2 header, REQ-DBA-000..018, GIVEN/WHEN/THEN scenarios, citing Wet DBA, Deliveroo-arrest (HR 24-3-2023), VBAR, AWR art. 52.
- [x] Task 3: `proposal.md` authored with Affected Projects / Scope / Risks / Rollback / Open Questions, referencing the shared `nextcloud-app` spec.
- [x] Task 4: `design.md` authored with the Reuse Analysis table and decisions D1–D8.
- [x] Task 5: `DBAOpdracht` declared (ADR-037 fragment, NOT the monolith) with the REQ-DBA-001 fields (ondernemingId, klantId, opdrachtNaam, startDatum, verwachte/feitelijkeEindDatum, verwachte/gerealiseerdeOmzet, modelOvereenkomstId, intakeStatus, intakeDatum, actueleRisicoscore, risicoNiveau, openFlags, evidenceDossierId, administrationId).
- [x] Task 6: `DBAIntake` declared (fragment) with the REQ-DBA-003 fields — the three pijler sub-scores + subtotals, Deliveroo criteria + subtotal, totaalScore, maxScore, interpretatie.
- [x] Task 7: `DBAModelovereenkomst` declared (fragment) with the REQ-DBA-002 fields (naam, bron, publicatieURL, goedkeuringDatum, geldigTot, essentieleBepalingen[], versie, actueleVersie).
- [x] Task 8: `DBARisicoflag` declared (fragment) with the REQ-DBA-004 fields and `x-openregister.immutable: true` (append-only audit record).
- [x] Task 9: `DBAPortfolioRisico` declared (fragment) with the REQ-DBA-005 fields (ondernemingId, peilDatum, actieveOpdrachten, concentratie{}, langjarigeRelaties[], exclusieveRelaties, overallRisico).
- [x] Task 10: `DBAEvidenceDossier` declared (fragment) with the REQ-DBA-007 fields (opdrachtId, stukken[] type/fileRef/datum/sha256, emailArchiefOptIn, compleetheidScore, ontbrekendeStukken[], bewaarTermijn, archiveDate).
- [x] Task 11: `x-openregister-lifecycle` declared on `DBAOpdracht` (draft → actief → beeindigd) and `DBAIntake` (draft → submitted → completed), with guard-backed transitions.
- [x] Task 12: Risk-score `x-openregister-calculations` declared on `DBAIntake.totaalScore` (sum of the four subtotals); the ADR-031 exception band derivation + score recomputation ships as `DBAComplianceGuard::computeTotaalScore` / `::deriveRiskBand` (named per design as the `DBAScoreCalculator` role, folded into the single guard file to keep one exception class).
- [x] Task 13: Completeness `x-openregister-calculations` declared on `DBAEvidenceDossier.compleetheidScore`; the ratio is computed by `DBAComplianceGuard::computeCompleteness` (0–1) with the missing-stukken list.
- [ ] Task 14: **DEFERRED** — daily flag-generation background job (factuurfrequentie/concentratie/langjarigheid/multiple-engagement). Imperative monitoring job; needs a live instance + AP/AR factuur data to exercise. Out of scope for this declarative `kind: config` change; lands in a follow-up apply cycle. The immutable `DBARisicoflag` target + the detection thresholds are fully specced and seeded.
- [ ] Task 15: **DEFERRED** — monthly portfolio-aggregatie job. Same rationale as Task 14; the `DBAPortfolioRisico` schema + `x-openregister-aggregations` shape + a worked seed record are shipped, the job that materialises them is deferred.
- [x] Task 16: VBAR-grens constant shipped as `DBAComplianceGuard::VBAR_GRENS_EUR = 33.0`, admin-overridable via app config key `dba_vbar_grens` (resolved in `resolveVbarGrens()`). No separate `lib/Enums/DBAConstants.php` — co-located on the single ADR-031 exception class.
- [x] Task 17: VBAR effective-rate logic shipped as `DBAComplianceGuard::effectiveHourlyRateBreach(bedrag, uren)` (breach when rate < grens; non-positive inputs → no breach). The per-factuur hook that calls it + hard-mode blocking is the deferred job surface (Task 14/31/32).
- [x] Task 18: `DBAModelovereenkomst` register seeded (fragment `components.objects[]`) with tussenkomstvrij v3 (2024), leverancier-zelfstandig v2 (2023) and an expired geen-werkgeversgezag (2021) example; operator upload is the standard OR register create.
- [ ] Task 19: **DEFERRED** — bespoke intake wizard Vue component (3-step / 20-question). The declarative manifest index/detail pages for `DBAIntake` ship; a bespoke multi-step wizard component is a later UI slice.
- [ ] Task 20: **DEFERRED** — bespoke evidence-dossier curation UI with file-upload + client-side SHA-256. Declarative index/detail pages for `DBAEvidenceDossier` ship; the upload/hash widget is a later UI slice.
- [ ] Task 21: **DEFERRED** — AVG e-mail-archive opt-in flow. The `emailArchiefOptIn` field + the 7-jaar bewaartermijn are declared; the ConsentRecord interaction is deferred.
- [ ] Task 22: **DEFERRED** — compliance-mode (soft/hard/intermediair) administration-config UI + enforcement. The modes are specced (REQ-DBA-000) and `intermediairMode` is a schema field; the config surface + hard-mode blocking is a later slice tied to the factuur hook.
- [ ] Task 23: **DEFERRED** — audit-rapport PDF generation. Needs OR/docudesk report generation + a live dossier; deferred.
- [ ] Task 24: **DEFERRED** — yearly herbeoordeling trigger + HERBEOORDELING_OVERDUE flag. Background-job surface; flag type is declared in the `DBARisicoflag` enum.
- [ ] Task 25: **DEFERRED** — opdrachtgever-inhuur-intake mirror + PO blocking. Optional, later phase per design D-scope; requires hrmq/PO integration.
- [ ] Task 26: **DEFERRED** — WBA upload UI. The `wbaBeoordelingResultaat` + `wbaGeldigTot` fields are declared on `DBAOpdracht`; the upload interaction is a later slice.
- [x] Task 27: Beeindiging precondition shipped as `DBAComplianceGuard::canBeeindigOpdracht` (requires feitelijkeEindDatum to start the 7-jaar clock); the `beeindig` lifecycle transition is declared. End-report PDF generation is part of the deferred report surface (Task 23).
- [ ] Task 28: **DEFERRED** — tussenkomst-driehoek modelling. Optional later phase per design D8; `intermediairMode` flag is declared as the entry point.
- [x] Task 29: Manifest navigation + pages added via the modular fragment `src/manifest.d/dba-compliance-marker.json` (ADR-037-style) — DBA Compliance menu with DBA Opdrachten, DBA Intake Wizard, Modelovereenkomst Register, DBA Portfolio Dashboard, Evidence Browser and Risk Flags, each with index + detail pages. JSON validates.
- [x] Task 30: `openspec/architecture/adr-000-data-model.md` updated with the six DBA entries (descriptions + entity/relations table).
- [ ] Task 31: **DEFERRED** — AP/AR factuurfrequentie hook to flag-generation. Optional, non-blocking; ships with the deferred monitoring job (Task 14).
- [ ] Task 32: **DEFERRED** — AP/AR uurtarief-detectie hook into the VBAR check. The pure VBAR breach helper (Task 17) is ready to be called from the hook; the wiring is deferred with Task 14.
- [x] Task 33: `docs/user-guide/compliance/dba-compliance-marker.md` authored (+ `compliance/_category_.json`) — intake flow, risk-scoring, flag interpretation, evidence-dossier management, audit-rapport export, legal basis.
- [x] Task 34: i18n — Dutch (`l10n/nl.json`) and English (`l10n/en.json`) strings added additively for the DBA compliance vocabulary (modes, intake, risk bands, flag types, evidence dossier, audit report, VBAR, portfolio, modelovereenkomst).

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
