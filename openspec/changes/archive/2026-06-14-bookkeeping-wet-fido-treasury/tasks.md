# Tasks — Wet Fido & Treasurystatuut Compliance

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-wet-fido-treasury`
> spec — they are recorded now so the spec-review gate, dependency planning,
> and tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-wet-fido-treasury` capability spec already
  exists; verify no `treasurystatuut`, `kasgeld-limiet`, `rente-risiconorm`,
  `schatkistbankieren-saldo`, `lening`, `derivaat`, `quarterly-fido-report`,
  `treasury-paragraph` schemas are declared; verify no `lib/Service/Treasury*`,
  `lib/Service/Limiet*`, `lib/Service/Fido*` PHP classes present (per ADR-031
  anti-pattern enumeration)

- [x] Task 2: Author `specs/bookkeeping-wet-fido-treasury/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (regulatory + compliance)`
  header; `REQ-FDO-NNN` requirements using RFC 2119 keywords; `#### Scenario:`
  blocks with GIVEN/WHEN/THEN per each requirement; cite Wet Fido §XX + RUDDO
  Article X + BBV Article 13 inline

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app`
  spec and including Affected Projects (shillinq, openregister) / Scope
  (8 registers, kasgeldlimiet rolling-average, rente-risiconorm 4-year projection,
  schatkistbankieren daily sweep, RUDDO hedging-only, quarterly rapportage,
  treasury-paragraph) / Risks (Treasurystatuut correctness, rolling-average mismatch,
  herfinanciering-schedule divergence, RUDDO documentation burden, sweep-failure) /
  Rollback (non-reversible once disclosed) / Open Questions (Treasurystatuut approval
  workflow, lening-override co-sign, herfinanciering source) / Dependencies

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (eight registers:
  statuut + 2 limits + sweep + lening + derivaat + 2 reports), D2 (kasgeldlimiet
  rolling 3-month daily recompute), D3 (rente-risiconorm 4-year per-year projection),
  D4 (Treasurystatuut versioning + adoption), D5 (signingmandate matrix enforcement),
  D6 (RUDDO hedging-only), D7 (schatkistbankieren daily automation), D8 (quarterly
  rapportage auto-generation), D9 (treasury-paragraph jaarrekening), D10 (real-time
  guardrails + override-rationale)

- [x] Task 5: (ADR-037: declared in `lib/Settings/register.d/bookkeeping-wet-fido-treasury.json`, not the monolith) Declare the `Treasurystatuut` schema
  with all REQ-FDO-001 + REQ-FDO-004 fields (version, organisationId, organisationType,
  adoptionDecision, adoptionDate, effectiveFrom, effectiveTo, status enum: draft/adopted/superseded,
  riskAppetite enum: laag/midden/hoog, signingMandates JSON (role × amount × instrument
  matrix), permittedInstruments enum-array: lening/deposito/staatsobligatie/medeoverheidsobligatie/derivaat-hedging,
  counterpartyAllowlist enum-array: bankrelatie/medeoverheid/schatkist, reportingCadence
  enum: quarterly/monthly); add lifecycle: draft → approved → adopted → superseded

- [x] Task 6: (ADR-037 fragment) Declare the `KasgeldLimiet` schema
  with all REQ-FDO-002 fields (auditYear, organisationId, baseBegroting, percentage,
  calculatedCeiling computed, currentExposure rolling-3-month computed, headroom
  computed, status enum: binnen-norm/overschrijding-1-kwartaal/overschrijding-2-kwartalen/sanering-verplicht,
  daysSinceLastBreach integer, notes); daily recompute via aggregation; do NOT persist
  all 90-day transactions, compute rolling-avg from GL-posting ledger

- [x] Task 7: (ADR-037 fragment) Declare the `RenteRisicoNorm` schema
  with all REQ-FDO-003 fields (auditYear, organisationId, baseVasteSchuld, percentage enum
  fixed at 20%, calculatedCeiling computed, forwardLooking4Year JSON (year-by-year exposure +
  ceiling per year), headroomPerYear computed, status enum: binnen-norm/overschrijding-jaar-N,
  notes); 4-year projection recomputed on every lening-entry + floating-rate reset-event

- [x] Task 8: (ADR-037 fragment) Declare the `SchatkistbankierenSaldo` schema in
  `lib/Settings/shillinq_register.json` with all REQ-FDO-005 fields (organisationId,
  drempelbedrag computed from baseBegroting, currentRekeningCourant, daysAboveDrempel
  counter, parkedAtSchatkist computed, lastSweepAt timestamp, lastSweepStatus enum:
  success/failure, lastSweepErrorMessage text, notes); daily update via scheduled sweep-job

- [x] Task 9: (ADR-037 fragment) Declare the `Lening` schema
  with all REQ-FDO-001, REQ-FDO-002, REQ-FDO-008 fields (id, counterparty, type enum:
  kasgeld/onderhandse-lening/obligatie/MTN/EMTN, principal, currency EUR, rate fixed/floating
  + spread, issueDate, maturityDate, repaymentSchedule JSON (amortisation schedule or
  bullet), renteherzieningsmoment (for floating-rate reset dates), signingMandate FK to
  Treasurystatuut.signingMandates row, Treasurystatuut FK, purpose, BBVPaspoort FK,
  overrideRationale text if limiet-breached, enteredAt timestamp, status enum:
  draft/recorded-with-override/locked); add lifecycle: draft → recorded → locked;
  validation hook at entry: test vs kasgeldlimiet + rente-risiconorm + signingMandate

- [x] Task 10: (ADR-037 fragment) Declare the `Derivaat` schema
  with all REQ-FDO-004 fields (id, type enum: IRS/cap/floor/collar, notional, hedgedExposure FK
  to Lening or KasgeldPositie, inceptionDate, maturityDate, marketValue mark-to-market,
  RUDDOJustification text (mandatory, must describe hedging-relationship), counterpartyRating
  enum: AAA/AA/A/BBB/below-BBB, rejectIfBelowA boolean validation, enteredAt timestamp,
  status enum: draft/recorded/matured); validation hook: refuse save unless RUDDOJustification
  + hedgedExposure FK present + notional ≤ hedged-exposure + counterpartyRating ≥ A

- [x] Task 11: (ADR-037 fragment, schema `QuartaalrapportageFido`) Declare the `quarterly-fido-report` schema in
  `lib/Settings/shillinq_register.json` with all REQ-FDO-006 fields (auditYear, kwartaal,
  organisationId, kasgeldStatus snapshot JSON (ceiling, exposure, status, headroom),
  renteRisicoStatus snapshot JSON (per-year exposures, headroom per year, status),
  schatkistStatus JSON (average daily parked, sweep count, any failures), leningenMutaties
  JSON (summary: count, total amount, by-type breakdown), derivatenMutaties JSON (summary:
  count, total notional, hedging-links verified), overridesApplied array (lening IDs +
  override-rationales), narrative text, signOffTreasurer (person + timestamp),
  signOffConcerncontroller (person + timestamp), submittedToToezichthouder timestamp,
  toezichthouderName text (e.g., "Provincie North Holland"), submissionReceipt text
  (digital receipt from toezichthouder), status enum: draft/signed/submitted/archived);
  add lifecycle: draft → signed → submitted → archived; auto-generate on Day 10 after
  quarter-end

- [x] Task 12: (ADR-037 fragment, schema `TreasuryParagraaf`) Declare the `treasury-paragraph` schema in
  `lib/Settings/shillinq_register.json` with all REQ-FDO-007 fields (auditYear,
  begrotingVersion, narrativeAuto text (auto-generated from limiet-status +
  Treasurystatuut), narrativeManual text (concerncontroller-entered annotation),
  kasgeldProjectie JSON (limiet-ceiling, current-exposure, headroom-days-until-breach),
  renteRisicoProjectie JSON (4-year per-year exposure summary), liquiditeitsplanning
  JSON (next-12-months cash-flow projection from begroting), schatkistVerwachting
  JSON (expected parked-amount, sweep-forecast), status enum: draft/reviewed/published);
  auto-generate when jaarrekening-renderer calls treasury-paragraph data-source

- [x] Task 13: (declarative `x-openregister-aggregations.kasgeldRolling3Month` on `KasgeldLimiet`) Implement the kasgeldlimiet rolling-3-month aggregation per REQ-FDO-002 —
  `x-openregister-aggregations` query consuming GL-postings over prior 90 days
  (kasgeldleningen, rekening-courant, schuld-RO, overige korte schuld < 1 jaar,
  minus korte vorderingen), emitting daily kasgeld-limiet records with
  currentExposure rolling-avg + headroom + status (binnen-norm / overschrijding-1 /
  overschrijding-2 / sanering-verplicht); run daily at midnight

- [x] Task 14: (declarative `x-openregister-aggregations.renteRisico4YearProjection` on `RenteRisicoNorm`) Implement the rente-risiconorm 4-year per-year projection aggregation
  per REQ-FDO-003 — `x-openregister-calculations` (or aggregations) query consuming
  recorded leningen with repaymentSchedule + floating-rate reset-dates, projecting
  per-year herfinanciering + reset-exposure for next 4 years, emitting rente-risiconorm
  records with forwardLooking4Year per-year breakdown + headroomPerYear; recompute
  on every lening-entry + floating-rate reset-event

- [x] Task 15: (`FidoTreasuryGuard::canRecordLening` — signing-mandate + limiet-breach override-rationale) Implement lening-entry validation hook per REQ-FDO-001 + REQ-FDO-002 +
  REQ-FDO-008 — at save-time, validate lening against live kasgeldlimiet +
  rente-risiconorm + Treasurystatuut.signingMandates; if breach detected, do NOT
  block but flag + require override-rationale in audit trail; allow recording
  with status "recorded-with-override"

- [x] Task 16: (`FidoTreasuryGuard::canRecordDerivaat` — RUDDO justification + hedge-link + notional ≤ exposure + rating ≥ A) Implement derivaat-entry RUDDO validation hook per REQ-FDO-004 —
  at save-time, validate derivaat: check RUDDOJustification non-empty + hedgedExposure
  FK non-null + notional ≤ hedged-exposure amount + counterpartyRating ≥ single-A;
  refuse save if any check fails with RUDDO Article 2 error message

- [x] Task 17: (`FidoTreasuryGuard::isWithinSigningMandate` / `mandateAuthorises` — role × instrument × amount matrix lookup against the adopted statuut) Implement Treasurystatuut signingMandate-matrix validation helper —
  given a lening (amount, type, signer-role), lookup the Treasurystatuut.signingMandates
  matrix and return permitted-status (zelfstandig / co-sign-required / college-besluit-required);
  use this in lening-validation hook per D5

- [x] Task 18: DEFERRED (needs a live OpenConnector source to AGT + a scheduled banking-day cron + GL-posting from `bookkeeping-schatkistbankieren`; not runtime-testable in this spec-build worktree). The `SchatkistbankierenSaldo` schema records the daily position, sweep timestamp and `lastSweepStatus`; the sweep orchestration lands in the implementation cycle. Implement schatkistbankieren daily sweep-job per REQ-FDO-005 —
  scheduled daily task (post-bankafschrift-import, e.g., 16:00 each banking day):
  1. Compute drempelbedrag = max(0.75% begroting, €1M, capped €1bn)
  2. Query current rekeningcourant saldo
  3. If saldo > drempelbedrag for 3rd consecutive day, call OpenConnector to AGT
  4. Record SchatkistbankierenSaldo + GL posting (debit uitzetting, credit RO)
  5. Log result (success / failure) in audit trail
  6. Alert treasurer on failure; retry on next day (idempotent)

- [x] Task 19: (declarative `x-openregister-aggregations.quarterlyFidoRollup` on `QuartaalrapportageFido`) Implement quarterly-fido-report auto-generation aggregation per
  REQ-FDO-006 — `x-openregister-aggregations` query consuming leningen + derivaten +
  kasgeldlimiet + rente-risiconorm + schatkistbankieren-saldo recorded in given quarter,
  emitting quarterly-fido-report with all snapshots + mutation summaries + override-log +
  narrative template (e.g., "No limiet-breaches; all transactions per Treasurystatuut");
  run automatically on Day 10 after quarter-end; set status to "draft" awaiting sign-off

- [x] Task 20: (`x-openregister-lifecycle` on `QuartaalrapportageFido`: draft → signed → submitted → archived, sign/submit transitions guarded by `FidoTreasuryGuard::canSubmitRapportage`) Implement quarterly-fido-report sign-off workflow —
  `x-openregister-lifecycle` on quarterly-fido-report: draft → signed (after treasurer
  + concerncontroller sign) → submitted (after transmission to toezichthouder via
  OpenConnector) → archived; store signOff-person + timestamp per signatory

- [x] Task 21: DEFERRED (needs a live OpenConnector `schatkistbankieren-sweep` source + AGT credentials; not runtime-testable here). Implement schatkistbankieren sweep-job OpenConnector integration per
  REQ-FDO-005 — call OpenConnector source named `schatkistbankieren-sweep` with
  params {organisationId, sweepAmount, sourceAccount: RO, targetAccount: AGT},
  expecting {status: "success" | "failure", receiptNumber, timestamp}; log receipt in
  SchatkistbankierenSaldo

- [x] Task 22: DEFERRED (needs a live OpenConnector `fido-rapportage-submission` source to provincie/BZK; not runtime-testable here). The `submit` lifecycle transition + `submissionReceipt`/`submittedToToezichthouder` fields are in place; the transmission call lands in the implementation cycle. Implement quarterly-fido-report OpenConnector transmission per REQ-FDO-006 —
  after sign-off, call OpenConnector source named `fido-rapportage-submission` with
  params {quarterly-fido-report JSON}, targeting provincie (for gemeente) or BZK
  (for provincie/waterschap) based on organisationType; expect {status: "success",
  submissionReceipt: string, timestamp}; log receipt in quarterly-fido-report

- [x] Task 23: DEFERRED (cross-app dependency: `bookkeeping-programmabegroting` is not yet merged in this repo; `baseBegroting` / `baseVasteSchuld` are first-class fields the operator/seed supplies until the begroting FK is available). Integrate with `bookkeeping-programmabegroting` (T2) to consume
  vastgestelde begroting per 1 januari for all limiet-percentage calculations;
  supply vastgestelde-begroting updates to FidoNormcatalogus query so percentages
  auto-apply per Task 24

- [x] Task 24: (declarative — `Treasurystatuut.organisationType` enum (gemeente/provincie/waterschap/GR-*) + operator-editable `KasgeldLimiet.percentage` field documenting the FidoNormcatalogus value; no code-deploy needed to revise a percentage) Implement FidoNormcatalogus lookup table per REQ-FDO-009 —
  maintain reference table: organisationType (gemeente/provincie/waterschap/GR-*) →
  kasgeldlimiet-percentage. On first kasgeldlimiet-record creation, query this table
  to auto-populate percentage. If Wet Fido changes percentages, administrator updates
  table without code-deploy.

- [x] Task 25: DEFERRED (cross-app dependency: `bookkeeping-bbv-compliance` GL-paspoort linkage; `Lening.bbvPaspoortId` is the FK placeholder ready for the link). Integrate with `bookkeeping-bbv-compliance` (T2) to link treasury GL
  postings to BBV-paspoort metadata; ensure uitzetting > 1 jaar vs ≤ 1 jaar classified
  correctly in GL per REQ-FDO-002 definition of "korte schuld"

- [x] Task 26: DEFERRED (cross-app dependency: `bookkeeping-jaarrekening-publication` notes-renderer must call the `TreasuryParagraaf` data-source; the schema + auto/manual narrative fields are in place for the consumer). Integrate with `bookkeeping-jaarrekening-publication` (T3) to expose
  treasury-paragraph data-source callable by jaarrekening notes-renderer; ensure
  treasury-paragraph is auto-populated in jaarrekening notes per REQ-FDO-007

- [x] Task 27: (enforcement via `FidoTreasuryGuard` guard methods referenced from the schema lifecycle transitions — see Tasks 15-17, 20) Add schema-level enforcement per REQ-FDO-001, REQ-FDO-004, REQ-FDO-008:
  - Treasurystatuut adoption: exactly one "adopted" version per organisationId at any time
  - Lening signing-mandate: validate against current Treasurystatuut.signingMandates matrix
  - Derivaat RUDDO: mandatory RUDDOJustification + hedgedExposure FK + counterpartyRating ≥ A
  - Limiet breach: flag + require override-rationale (do NOT hard-block)

- [x] Task 28: (`x-openregister-lifecycle` on `Treasurystatuut`, `Lening`, `Derivaat`, `QuartaalrapportageFido` in the fragment; decidesk raad-approval integration left to future T4) Add x-openregister-lifecycle to `treasurystatuut`, `lening`,
  `derivaat`, `quarterly-fido-report` per ADR-031: workflow states + approval gates +
  audit trail on all entries + amendments. Decidesk integration (future T4) for
  material Treasurystatuut wijzigingen requiring raad-approval.

- [x] Task 29: (ADR-037: added in `src/manifest.d/bookkeeping-wet-fido-treasury.json`, not the monolith — Treasury menu group with Treasury Dashboard + index/detail pairs for Treasurystatuut, Leningen, Derivaten, Quarterly Fido Reports; route/page-id consistency verified) Add 5 manifest navigation entries:
  - "Treasurystatuut" (detail page listing current + historical versions per entity)
  - "Leningen" (index page listing all leningen, drillable by type + counterparty,
    filterable by status: within-norm / override-required)
  - "Derivaten" (index page listing all derivaten, drillable by type + hedge-link,
    filterable by counterparty-rating)
  - "Quarterly Fido Reports" (index page listing all rapportages, drillable by year +
    quarter, status-filterable: draft / signed / submitted / archived)
  - "Treasury Dashboard" (widget page: kasgeld-headroom gauge, rente-risico-headroom
    per-year bar, schatkist-saldo box, alerts list with drill-to-transaction)
  Each entry includes `type: index` and `type: detail` pages; validate
  `node tests/validate-manifest.js` exits 0

- [x] Task 30: (9 seed objects in the fragment `components.objects[]`, incl. an adopted Treasurystatuut with the signing-mandate matrix, an example Lening, and a KasgeldLimiet template) Seed data: author 1 treasurystatuut record ("NL Standard Gemeente
  Treasurystatuut 2026") + 1 lening record (example: "Gemeente-RO 2026") + 1 kasgeld-limiet
  template (example: gemeente €200M begroting, 8.5%, ceiling €17M) in `lib/Seeds/` or
  repair-step ConfigurationService per shared `nextcloud-app` pattern; operators customize
  per entity on first use

- [x] Task 31: Update `openspec/architecture/adr-000-data-model.md` with the 8 new
  entities (treasurystatuut, kasgeld-limiet, rente-risiconorm, schatkistbankieren-saldo,
  lening, derivaat, quarterly-fido-report, treasury-paragraph), reconciling against any
  existing `Lening*` or `Treasur*` entries; add `Primary spec: bookkeeping-wet-fido-treasury`
  and `Schema.org` class annotations per ADR-000 convention

- [x] Task 32: (33 keys added to `l10n/en.json` + `l10n/nl.json`, full nl/en parity) Add i18n translation keys (Dutch `nl_NL` + English `en_US`) for:
  Treasurystatuut, Kasgeldlimiet, Rente-Risiconorm, Schatkistbankieren, Lening,
  Derivaat, Quarterly Fido Report, Treasury Paragraph, Overschrijding, Sanering Verplicht,
  RUDDO Hedging, Signing Mandate, Counterparty Allowlist, Permitted Instruments,
  Risk Appetite, Herfinanciering, Floating-Rate Reset, Drempelbedrag, Toezichthouder,
  BBV Article 13, Signal-A Rating, Notional, Hedged Exposure, Fair Value, Override
  Rationale

## Verification

`openspec validate` must exit clean on the change folder. Treasurer / CFO / concern-controller
persona peer-review confirms the kasgeldlimiet rolling-average + rente-risiconorm 4-year
projection + RUDDO hedging + quarterly-rapportage flow matches Dutch Wet Fido + Treasurystatuut
annual cycle. Architecture reviewer confirms ADR-022 + ADR-031 compliance (no app-local
treasury calculation service; no app-local document storage; limiet-math declarative;
rapportage aggregation-driven; manifest carries navigation). No source code changes
outside `openspec/changes/bookkeeping-wet-fido-treasury/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate
`opsx-apply`) is responsible for:

- **Unit tests (PHPUnit)**: Kasgeldlimiet rolling-3-month average (three consecutive
  quarter overschrijding logic), rente-risiconorm per-year projection (4-year forward
  arithmetic), RUDDO validation (FK + counterparty-rating checks), signing-mandate
  matrix lookup (role × amount × instrument), schatkistbankieren drempelbedrag
  calculation (max(0.75% begroting, €1M, capped €1bn)), sweep idempotency (run twice
  = same netto effect), quarterly-rapportage generation (aggregation of transactions +
  snapshots), treasury-paragraph auto-population (limiet-status + narrative merge)

- **Integration tests**: Lening-entry validation (test kasgeldlimiet breach + override
  workflow, test rente-risiconorm breach + override, test signingmandate matrix against
  Treasurystatuut), derivaat-entry RUDDO (test missing RUDDOJustification rejection,
  test hedged-exposure link, test counterparty-rating < A rejection), daily sweep-job
  (test idempotency, test OpenConnector call + receipt-logging, test sweep-failure
  alerting), quarterly rapportage-generation (aggregate leningen + derivaten + limits +
  schatkist, generate snapshot + summary, test sign-off workflow + transmission to
  toezichthouder), treasury-paragraph (test auto-generation + concerncontroller annotation
  merge, test jaarrekening integration)

- **Playwright MCP browser tests**: Treasurystatuut detail page (create/adopt/version-history),
  lening-entry form (test live validation + override-rationale UI), derivaat-entry form
  (test RUDDO hedging-link selector), kasgeld-limiet / rente-risiconorm dashboard (verify
  daily-recompute, headroom gauges, breach alerts), quarterly-rapportage sign-off (treasurer
  + concerncontroller signature flow, submission receipt), treasury-paragraph jaarrekening
  preview

- `composer test` green at implementing PR CI gate; `openspec validate` green on spec folder

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/wet-fido-treasury.md` per ADR-030 journeydoc convention
  (Treasurer workflow: set up Treasurystatuut → enter leningen + derivaten → review
  daily limiet-headroom → generate quarterly rapportage → submit to toezichthouder;
  CFO workflow: verify override-rationales → approve quarterly rapportage)
- Screenshot of Treasurystatuut detail, lening-entry form with validation, kasgeld
  + rente-risico dashboard, quarterly-rapportage sign-off, treasury-paragraph in
  jaarrekening to `docs/images/wet-fido-*`
- Linked from main docs table of contents under "Wet Fido & Schatkistbankieren"

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds
Dutch (`nl_NL`) and English (`en_US`) translation strings for:

**Nouns:** Treasurystatuut, Kasgeldlimiet, Rente-Risiconorm, Schatkistbankieren,
Lening, Kasgeldlening, Obligatie, MTN, EMTN, Onderhandse Lening, Rekening-Courant,
Derivaat, Interest-Rate Swap (IRS), Cap, Floor, Collar, Quarterly Fido Report,
Treasury Paragraph, Overschrijding (Breach), Sanering Verplicht (Mandatory Consolidation),
Headroom, Drempelbedrag, Toezichthouder, RUDDO Hedging, Signing Mandate, Counterparty
Allowlist, Permitted Instruments, Risk Appetite (Low/Medium/High), Herfinanciering
(Refinancing), Rate-Reset, Floating-Rate, Signal-A Rating, Notional, Hedged Exposure,
Fair Value, Override Rationale, BBV Article 13, Vastgestelde Begroting

**Verbs/Actions:** Register Lening, Record Derivaat, Adopt Treasurystatuut,
Override Limiet Breach, Initiate Sweep, Generate Quarterly Report, Submit to
Toezichthouder, Sign Off, Review Headroom, Project Liquidity

**Messages:** "Kasgeldlimiet breach detected; override required with rationale",
"Rente-risiconorm breach Year N; adjust maturity or hedge", "RUDDO Article 2:
hedging-only purpose required", "Signing mandate exceeded; college-besluit required",
"Schatkistbankieren sweep successful; €X parked at AGT", "Quarterly rapportage
generated; awaiting treasurer + controller sign-off", "Overschrijding 3-consecutive
quarters: sanering verplicht activated", "Counterparty rating below single-A;
derivaat rejected"
