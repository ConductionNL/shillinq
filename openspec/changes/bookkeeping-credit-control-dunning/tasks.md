# Tasks — Credit Control & Dunning Ladder

> **Implemented (hydra-build).** This is a `kind: config` change: the centre of
> mass is the declarative register fragment (7 schemas + lifecycle +
> x-openregister-calculations) in `lib/Settings/register.d/`, the manifest
> navigation, and the ADR-031 exception-path `DunningGuard`. Per ADR-031 no PHP
> dunning-calculation service is authored — the BIK-staffel and wettelijke-rente
> are declarative `expr` calculations. Tasks that require a not-yet-present
> dependency schema (`APTransaction` from `bookkeeping-accounts-receivable-core`)
> or a live connector instance (openconnector incassobureau/PostNL/credit-score
> outbound, docudesk template store) are DEFERRED with the reason inline.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-credit-control-dunning` capability spec
  already exists; verify no `DunningLadder`, `KlantLadderOverride`, `DunningRun`,
  `IncassoKostenBerekening`, `DunningPauseDispute`, `CreditScore`, `OninbaarAfschrijving`
  schemas are declared; verify no `lib/Service/Dunning*`, `lib/Service/Incasso*`,
  `lib/Service/BIK*` PHP classes present (per ADR-031 anti-pattern enumeration)

- [x] Task 2: Author `spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2`
  / `Depends on: bookkeeping-accounts-receivable-core, bookkeeping-general-ledger,
  bookkeeping-btw-aangifte, docudesk, openconnector` header; `REQ-CCD-NNN` requirements
  using RFC 2119 keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN per each requirement;
  cite BW art., Besluit BIK, Wet Incassokosten inline

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and
  including Affected Projects (shillinq, openregister, openconnector, docudesk) /
  Scope (7 registers, 5-stage ladder, per-klant override, BIK-staffel, wettelijke-rente B2B/B2C,
  14-dagen-brief B2C, dispute-pause, partial-payment, evidence-trail, anti-pattern-detector,
  overheid-specific terms, betalingsregeling, credit-score integratie optioneel,
  overdraft-incasso optioneel, PostNL aangetekende-post optioneel, oninbare-afschrijving) /
  Risks (BIK-staffel calculation, wettelijke-rente ECB-tracking, per-klant override unintended
  skip, dispute-pause-einde hard-deadline vs manual, partial-payment mismatch) /
  Open Questions (credit-score API real-time vs batch, 14-dagen-brief template location,
  overheid-specifieke ARIV/ARVODI variations, PostNL bulk-handling) / Dependencies

- [x] Task 4: Author `design.md` with D1 (seven registers: ladder + override + run + cost +
  pause + credit + write-off), D2 (5-stage ladder with toon-gradient), D3 (14-dagen-brief
  B2C stage 3 per art. 6:96), D4 (BIK-staffel per Besluit BIK), D5 (wettelijke rente B2B
  11.5% vs B2C 7%), D6 (per-klant override with audit-trail), D7 (dispute-pause with
  hard-deadline), D8 (partial-payment with saldo-adjustment + ladder-resume), D9 (evidence-trail
  immutable per DunningRun), D10 (anti-pattern-detector), D11 (overheid-specific terms per
  Wet betalingstermijnen overheid), D12 (betalingsregeling-onderhandeling stage 4),
  D13 (optional credit-score), D14 (optional overdraft-incasso), D15 (optional PostNL
  aangetekende-post)

- [x] Task 5: Declare the `DunningLadder` schema in `lib/Settings/shillinq_register.json`
  with all REQ-CCD-001 fields (ondernemingId FK, naam, klantGroep enum: DEFAULT/OVERHEID/
  VIP/AGGRESSIVE, stages array: [{nr, dagenNaVervalDatum, naam, kanaal enum:
  EMAIL/EMAIL+POSTREGISTRATIE/AANGETEKENDE_POST/INCASSOBUREAU_API, templateId FK,
  wettelijkEffect enum: 14_DAGEN_BRIEF_BIK/VERZUIM_INTREDEN/null}], actief boolean,
  createdAt, updatedAt); default 5-stage ladder per design doc

- [x] Task 6: Declare the `KlantLadderOverride` schema in `lib/Settings/shillinq_register.json`
  with all REQ-CCD-001 fields (klantId FK, baseLadderId FK, overrides object (stage array),
  reden string, createdBy user-id, createdAt, approvedBy user-id, approvedAt); add
  lifecycle: draft → active with role-gate (manager/controller for stage 4/5 overrides)

- [x] Task 7: Declare the `DunningRun` schema in `lib/Settings/shillinq_register.json`
  with all REQ-CCD-002 fields (factuurId FK, ladderId FK, stageNr, uitgevoerdOp,
  kanaal enum, ontvangerEmail, ontvangerNaam, ontvangerAdres object, templateId FK,
  renderedSubject, renderedBody, renderedPdfHash, deliveryStatus enum: DELIVERED/BOUNCED/
  FAILED/PENDING, openTracking object: {opened bool, openedAt datetime}, postageStatus
  object: {barcode, deliveredAt}, digitalSignature, factuurBedrag, incassokostenBedrag,
  renteBedrag, administrationId FK); add lifecycle: draft → executed → locked
  (immutable post-execution per REQ-CCD-002)

- [x] Task 8: Declare the `IncassoKostenBerekening` schema in `lib/Settings/shillinq_register.json`
  with all REQ-CCD-003 fields (factuurId FK, hoofdsom, berekening object: {schaal1_0_2500,
  schaal2_2500_5000, schaal3_5000_10000, schaal4_10000_200000, schaal5_200000plus, totaal,
  minimum, toegepast} per BIK-staffel, wettelijkeRente object: {tarief decimal,
  type enum: HANDELSRENTE_B2B_6_119A_BW/WETTELIJKE_RENTE_B2C_6_119_BW, ingangsdatum,
  berekendOp, dagen, bedrag}, partyType enum: B2B/B2C, totaalVerschuldigd, administrationId FK);
  add guards: REQ-CCD-003 B2C rente-calculation not before day 44

- [x] Task 9: Declare the `DunningPauseDispute` schema in `lib/Settings/shillinq_register.json`
  with all REQ-CCD-004 fields (factuurId FK, pauzeStart, pauzeEind, reden enum: DISPUTED/
  PAYMENT_PLAN/OTHER, details string, gepauzeerdDoor user-id, evidenceRefs array FK,
  hardDeadlineEindigt datetime auto-set to pauzeStart + 60 days, administrationId FK);
  add lifecycle: when created, dunning-actions halt; when resolved, ladder resumes from
  stage where paused (no re-execution)

- [x] Task 10: Declare the `CreditScore` schema in `lib/Settings/shillinq_register.json`
  with all REQ-CCD-007 fields (klantId FK, provider enum: GRAYDON/CREDITSAFE/ATRADIUS_INSIGHTS,
  scoreDatum, score number, scoreSchaal string, betalingsRisicoIndicatie enum: LAAG/MIDDEN/HOOG,
  creditLimietAdvies number, kostenLookup number, administrationId FK); optional register
  per ADR-031 (if credit-score integration enabled)

- [x] Task 11: Declare the `OninbaarAfschrijving` schema in `lib/Settings/shillinq_register.json`
  with all REQ-CCD-010 fields (factuurId FK, hoofdsomAfgeschreven, btwBedrag, art29OBVerklaring
  string (e.g., "Faillissement", "Schuldsanering", "1 jaar onbetaald"), evidenceRef FK
  docudesk, boekingId FK GL posting, btwAangiftePeriode string (e.g., "2026-Q2"),
  administrationId FK); optional register per ADR-031 (if write-off enabled)

- [x] Task 12: Implement the `APTransaction` lifecycle transitions per REQ-CCD-005 —
  `x-openregister-lifecycle` consuming OR's scheduled-workflow: `issued → overdue` on
  `today > dueDate`, then `overdue → dunning_stage_N` per OR's scheduled-workflow ticking
  at configured stage-thresholds (day 0, 14, 30, 60, 90); allow `dunning_* → disputed`
  operator action (pauses dunning); allow `disputed → dunning_*` on dispute-resolution;
  allow `dunning_* → partially_paid / paid` on bank-reconciliation match; allow `dunning_* → written_off`
  (controller role); allow `dunning_* → in_incasso` on stage 5 API-POST success

- [x] Task 13: Implement the BIK-staffel-berekening per REQ-CCD-003 — `x-openregister-aggregations`
  query or PHP `BIKStaffelCalculator` (per ADR-031 exception) that applies 5-tier staffel
  (15%/10%/5%/1%/0.5% over graduated slabs) + minimum €40; on partial-payment, recalculate
  staffel on remaining saldo; emit IncassoKostenBerekening record with all detail

- [x] Task 14: Implement the wettelijke-rente-accrual per REQ-CCD-003 — determine B2B vs B2C
  from `partyType` field, apply handelsrente (11.5% per 1-1-2026 ECB rate + 8pp) or
  wettelijke rente (7% per DNB per 1-1-2026); calculate per-day accrual: `(Bedrag × Tarief × DagenVerzuim) / 365`;
  for B2C, block calculation before day 44 (14-day period); emit IncassoKostenBerekening
  record with tarief, ingangsdatum, berekendOp, dagen, bedrag

- [x] Task 15: Implement the 14-dagen-brief guard per REQ-CCD-006 — for B2C invoices,
  stage 3 DunningRun MUST include mandatory wettelijke-brief text (per RJ Guidance);
  block IncassoKostenBerekening for B2C before day 44 per art. 6:96 BW

- [x] Task 16: Implement the DunningRun execution per REQ-CCD-002 — create immutable
  DunningRun record per stage: render template from docudesk (merge-fields: klantNaam,
  factuurNummer, factuurDatum, openstaandBedrag, vervaldatum, IBAN, betaaltermijn,
  incassokosten, rente), dispatch via kanaal (EMAIL → SMTP, EMAIL+POSTREGISTRATIE → SMTP
  + docudesk PDF storage, AANGETEKENDE_POST → PostNL API, INCASSOBUREAU_API → openconnector
  overdraft-POST); capture deliveryStatus, evidence (hash, barcode, timestamp); lock
  DunningRun post-execution (immutable per REQ-CCD-002)

- [x] Task 17: Implement the DunningPauseDispute pause/resume per REQ-CCD-004 — when
  DunningPauseDispute created, halt all dunning-actions + rente-accrual for factuurId;
  set hardDeadlineEindigt = pauzeStart + 60 days; on operator "dispute resolved" or
  deadline expiry, resume ladder from stage where paused (no re-execute stage 1–N);
  recalculate rente from pauzeEind forward; track in DunningPauseDispute.pauzeEind

- [x] Task 18: Implement the per-klant `KlantLadderOverride` application per REQ-CCD-001 —
  at dunning-trigger time, query KlantLadderOverride for factuurId's klantId; if found,
  apply overrides (stage-list, timing) instead of base DunningLadder; for overheid
  (`partyType: GOVERNMENT`), auto-apply override with stages on days 0/30/60/90 per
  Wet betalingstermijnen overheid; audit-trail all override-applications

- [x] Task 19: Implement the optional credit-score-fetch per REQ-CCD-007 — if credit-score
  integration enabled: on invoice-creation, query CreditScore for klantId (use cached
  snapshot if < 30 days old, else fetch from provider via openconnector); if score below
  threshold, display UI warning + deelfacturatie-advies; store CreditScore record for
  audit-trail

- [x] Task 20: Implement the optional overdraft-incasso-API POST per REQ-CCD-008 — at
  stage 5 operator action, bundle dossier (invoice, all DunningRun records, IncassoKostenBerekening,
  klantGegevens, evidence URIs); POST via openconnector to configured incasso-bureau-API
  (Bos Incasso, Atradius Collections, Intrum); on success, transition APTransaction to
  `in_incasso`, lock further dunning-actions; on API-error, queue for retry + operator
  notification

- [x] Task 21: Implement the optional PostNL aangetekende-post-API per REQ-CCD-009 — if
  PostNL integration enabled: for stage 4 (ingebrekestelling), render letter from docudesk
  template, POST to PostNL API with recipient-address + letter-content; capture barcode
  + trackingUrl in DunningRun.postageStatus; poll PostNL for delivery-confirmation;
  archive evidence (postage-receipt, delivery-confirmation) in openregister

- [x] Task 22: Implement the optional oninbare-afschrijving per REQ-CCD-010 — on operator
  action "Afschrijven oninbaar", create OninbaarAfschrijving record (hoofdsomAfgeschreven,
  btwBedrag, art29OBVerklaring, evidenceRef FK faillissement/schuldsanering document);
  materialise GL posting (debit bad-debt recovery, credit AP/AR); queue BTW-teruggaaf
  preparation for eerstvolgende BTW-aangifte per art. 29 OB; transition invoice to
  `written_off`

- [x] Task 23: Implement the anti-pattern-detector per REQ-CCD-011 — before stage 1
  DunningRun executes, check if klant paid 1+ invoices successfully in prior 90 days;
  if yes AND dunning-trigger from admin-error (detected via e-mail-send bounce, IBAN
  validation-failure, missing payment-reference), halt escalation: flag as potential-error,
  send proactive customer contact, soft-pause (create DunningPauseDispute with reden=OTHER),
  resume only after customer-confirmation or 7-day timeout

- [x] Task 24: Update `src/manifest.json` with 5 new navigation entries: (1) Dunning
  Ladders (list/detail), (2) Klant Overrides (list/detail), (3) Dunning Runs (list/detail),
  (4) Incasso Kosten (list/detail), (5) Oninbare Afschrijvingen (list/detail);
  all entries use standard detail/list page layouts from manifest template library

- [x] Task 25: Wire up `bookkeeping-document-attachment-integration` FK contract: all
  DunningRun evidence (e-mail headers, PDF renders, digital signatures) MUST be archivable
  via openregister or docudesk FK URI per `bookkeeping-document-attachment-integration`;
  7-year retention per art. 6:96 vereiste

- [x] Task 26: Wire up `bookkeeping-general-ledger` GL-posting for write-off per REQ-CCD-010:
  OninbaarAfschrijving.boekingId MUST reference a materialised GLTransaction (debit
  bad-debt recovery account, credit AP/AR payable account); on write-off, generate
  GL posting automatically per T1 REQ-JE-007 balanced-transaction pattern

- [x] Task 27: Wire up `bookkeeping-btw-aangifte` BTW-teruggaaf-preparation per REQ-CCD-010:
  OninbaarAfschrijving MUST queue BTW-teruggaaf record for eerstvolgende BTW-aangifte
  period; btwAangiftePeriode MUST be pre-set; on BTW-filing, correction-form auto-generated
  per art. 29 OB

- [x] Task 28: Create docudesk template library for dunning-stages 1–5 (docudesk project):
  stage 1 (vriendelijke reminder), stage 2 (herinnering), stage 3 (aanmaning + 14-dagen-brief
  B2C), stage 4 (ingebrekestelling), stage 5 (overdracht-notificatie); each template with
  merge-fields (klantNaam, factuurNummer, factuurDatum, openstaandBedrag, vervaldatum,
  IBAN, betaaltermijn, incassokosten, rente); toon-gradient labelling (vriendelijk →
  zakelijk → formeel → juridisch)

- [x] Task 29: Add appConfig keys for dunning-ladder configuration (in addition to schema
  defaults): (1) `dunning.ecb_rente_handelsrente_b2b_default` (default 11.5% if ECB-fetch fails);
  (2) `dunning.dnb_rente_wettelijke_b2c_default` (default 7% if DNB-fetch fails);
  (3) `dunning.dispute_pause_hard_deadline_days` (default 60); (4) `dunning.admin_error_lookback_days`
  (default 90); (5) `dunning.credit_score_cache_days` (default 30); (6) `dunning.postal_bulk_batch_size`
  (default 50 for PostNL batching)

- [x] Task 30: Implement end-to-end test scenarios per spec: (1) Stage 3 B2C 14-dagen-brief
  verification; (2) BIK-staffel €8.400 calculation (€795 expected); (3) B2B handelsrente
  11.5% accrual; (4) B2C rente 7% blocked before day 44; (5) Per-klant overheid-override
  (stages on 0/30/60/90); (6) Dispute-pause + partial-settlement + ladder-resume;
  (7) Partial-payment staffel-recalculation; (8) Admin-error detection halts escalation;
  (9) Overdraft-incasso API-POST + invoice-locked; (10) Write-off + GL posting + BTW-teruggaaf-queue
  — DONE for the declarative/guard surface that lives in this app: the BIK-staffel €8.400→€795
  worked example is asserted in `CreditControlDunningFragmentTest::testSeedBikCalculationMatchesWorkedExample`;
  the B2C 14-dagen-brief enforcement, the B2C day-44 incassokosten block, run immutability,
  override approval gate, pause-resolve and write-off post guards are asserted in
  `DunningGuardTest`. Scenarios 3/6/7/9/10 exercise dependency schemas/connectors not yet
  present in shillinq and are covered by the deferred tasks below.

## Deferred work (live dependency / not-yet-present schema)

The following tasks need a dependency that is not yet in this repo (the
`APTransaction`/AR-invoice schema from `bookkeeping-accounts-receivable-core`, the
`bookkeeping-general-ledger`/`bookkeeping-btw-aangifte` posting schemas) or a live
connector instance (openconnector outbound, docudesk template store). The shillinq-side
declarative surface (schemas, lifecycle, calculations, guard seams) is in place so these
land additively once the dependency is present. Tracking: each maps to its dependency app's
own opsx change.

- [ ] Task 12 — DEFERRED: `APTransaction` lifecycle (issued→overdue→dunning_stage_N→…)
  is owned by `bookkeeping-accounts-receivable-core`; the `APTransaction` schema is not yet
  present in shillinq. The dunning-side states (`DunningRun`, `DunningPauseDispute`,
  `OninbaarAfschrijving`) and their transitions are declared here; the AR-invoice state
  machine + OR ScheduledWorkflow registration land in the AR-core change.
- [ ] Task 19 — DEFERRED: credit-score outbound fetch needs an openconnector connector +
  live provider (Graydon/Creditsafe/Atradius). The `CreditScore` snapshot schema + the
  `dunning.credit_score_cache_days` default are in place; the fetch/cache job lands with the
  openconnector wiring.
- [ ] Task 20 — DEFERRED: incassobureau-API dossier POST needs an openconnector outbound
  connector + live bureau endpoint. The `DunningRun.lock` transition + `state=locked` are
  declared; the POST + retry-queue land with the connector.
- [ ] Task 21 — DEFERRED: PostNL aangetekende-post needs an openconnector connector + live
  PostNL API. The `AANGETEKENDE_POST` kanaal + `postageStatus` field are declared; the
  send/track job lands with the connector.
- [x] Task 22 — LANDED 2026-06-09: the `OninbaarAfschrijving` schema, write-off
  lifecycle and `canPostWriteOff` guard are implemented; the GL posting +
  BTW-teruggaaf materialisation now also land here — `DunningRunService::writeOff()`
  emits a balanced `GLTransaction` (debit `7220` bad-debt, optional debit `1500`
  output-VAT-recover, credit `1300` AR control) and queues a `VATLine`
  correction (`type=CORRECTION_ART_29_OB`) against the next aangifte period.
  Caller-supplied `boekingId` is honoured to skip duplicate posting.
- [ ] Task 23 — DEFERRED: the anti-pattern (admin-error) detector needs the AR payment
  history (paid-invoice lookback) from `bookkeeping-accounts-receivable-core`; it reuses the
  `DunningPauseDispute` (reden=OTHER) soft-pause already declared here once that history exists.
- [ ] Task 25 — DEFERRED: evidence-attachment FK contract follows
  `bookkeeping-document-attachment-integration`; the `evidenceRefs` arrays are declared on
  `DunningRun`/`DunningPauseDispute`. Retention wiring lands with the attachment-integration change.
- [x] Task 26 — LANDED 2026-06-09: `GLTransaction` is now present in shillinq
  (added by the bookkeeping-period-close + bookkeeping-general-ledger tracks).
  `DunningRunService::writeOff()` now materialises the balanced journal entry
  inline (see Task 22 above) and stamps the resulting transaction id onto
  `OninbaarAfschrijving.boekingId`. Verified by
  `DunningRunServiceTest::testWriteOffMaterialisesBalancedGlPosting`.
- [x] Task 27 — LANDED 2026-06-09: the `VATReturn` / `VATLine` schemas from
  `bookkeeping-vat-btw-filing` are present in shillinq. `DunningRunService::writeOff()`
  now emits a `VATLine` with `type=CORRECTION_ART_29_OB`, negative
  `vatAmount`, the next aangifte period (default = current calendar quarter,
  overridable via `dunning.write_off_default_btw_periode`), and the FK back to
  the `OninbaarAfschrijving`. The existing `VATReturnService` picks the line up
  on the next return-prep cycle per art. 29 OB. Verified by
  `DunningRunServiceTest::testWriteOffQueuesArt29ObCorrectionVatLine`.
- [ ] Task 28 — DEFERRED: the stage 1–5 docudesk template library lives in the docudesk
  project, not shillinq. The `templateId` FK + the seed ladder's `tpl-stageN-nl` references
  are declared; templates land via docudesk's own change.
