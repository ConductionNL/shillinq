# Tasks — IAS 37 / RJ 252 Provisions, Contingent Liabilities and Contingent Assets

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-voorzieningen-claims`
> spec — they are recorded now so the spec-review gate, dependency planning,
> and tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-voorzieningen-claims` capability spec
  already exists; verify no `provision`, `provision-movement`,
  `contingent-liability`, `pensioenvoorziening-detail`, `jubileumvoorziening-detail`,
  `herstructureringsvoorziening-detail`, `garantievoorziening-detail`,
  `milieuvoorziening-detail`, `claims-voorziening-detail` schemas are declared;
  verify no `lib/Service/Provision*`, `lib/Service/Contingent*` PHP classes
  present (per ADR-031 anti-pattern enumeration)
  - VERIFIED: no `openspec/specs/bookkeeping-voorzieningen-claims/` dir,
    no `lib/Settings/register.d/bookkeeping-voorzieningen-claims.json` fragment,
    no Provision*/Contingent* slugs in monolith schemas, no Provision*/Contingent*
    PHP files under lib/. Greenfield build.

- [x] Task 2: Author `specs/bookkeeping-voorzieningen-claims/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (regulatory + compliance)` /
  `Depends on: bookkeeping-general-ledger, bookkeeping-chart-of-accounts,
  bookkeeping-financial-statements, bookkeeping-pension-ias19,
  bookkeeping-deferred-tax` header; `REQ-PROV-NNN` requirements using RFC 2119
  keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN per each requirement;
  cite IAS 37 §XX + RJ 252 §XX inline. Complete 19 requirements:
  REQ-PROV-001 (three-criteria), REQ-PROV-002 (best-estimate + range),
  REQ-PROV-003 (discontering), REQ-PROV-004 (movement), REQ-PROV-005
  (herstructurering plan), REQ-PROV-006 (claims legal opinion), REQ-PROV-007
  (probability classification), REQ-PROV-008 (disclosure table),
  REQ-PROV-009 (annual herwaardering), REQ-PROV-010 (peer review),
  REQ-PROV-011 (jubileum CAO), REQ-PROV-012 (garantie claimrate),
  REQ-PROV-013 (milieu Wbb), REQ-PROV-014 (pensioen IAS 19),
  REQ-PROV-015 (contingent liability), REQ-PROV-016 (GL linked entries),
  REQ-PROV-017 (discontering unwinding), REQ-PROV-018 (materiaalsdrempel),
  REQ-PROV-019 (schattingswijziging prospectief)
  - DONE: `openspec/changes/bookkeeping-voorzieningen-claims/specs/bookkeeping-voorzieningen-claims/spec.md`
    holds all 19 REQ-PROV-NNN requirements with `#### Scenario:` blocks +
    IAS 37 / RJ 252 citations; mirrored to `openspec/specs/bookkeeping-voorzieningen-claims/spec.md`;
    `@e2e exclude pure backend/compliance` marker added (gate-19).

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec
  and including Affected Projects (shillinq, openregister, hrmq, docudesk,
  decidesk) / Scope (9 registers, three-criteria, best-estimate + sensitivity,
  discontering, period roll-forward, peer review, disclosure table) / Risks
  (subjective judgment, disconteringsvoet selection, herstructurering plan
  detail, claims legal privilege) / Rollback (non-reversible once disclosed) /
  Open Questions (best-estimate sourcing, herstructurering approval format,
  dubieuze debiteuren treatment) / Dependencies
  - DONE: `openspec/changes/bookkeeping-voorzieningen-claims/proposal.md` covers
    Summary / Motivation / Affected Projects / Scope (In + Out) / Risks /
    Rollback / Open Questions / Dependencies / Success Criteria.

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (three registers:
  core provision + movement + contingent liability + 6 type-specific details),
  D2 (three-criteria recognition gate at schema level), D3 (best-estimate +
  sensitivity range), D4 (disconteringsvoet when > 1 year), D5 (period
  immutability + annual herwaardering), D6 (peer-review EUR 100K+ / 1%+ balance),
  D7 (automatic probability classification), D8 (obligating-event documentation),
  D9 (type-specific detail extensions), D10 (jaarrekening disclosure table)
  - DONE: `openspec/changes/bookkeeping-voorzieningen-claims/design.md` carries
    Context / Goals / Non-Goals / D1..D10 / Reuse Analysis / Declarative-vs-imperative
    table / Seed Data / Risks / Migration Plan / Compliance & Standards.

- [ ] Task 5: Declare the `provision` schema in
  `lib/Settings/shillinq_register.json` with all REQ-PROV-001–010 fields
  (id, provisionType enum, description, recognitionDate, recognitionRationale,
  legalOrConstructiveObligation enum, obligatingEvent text, probabilityOfOutflow
  decimal, bestEstimate decimal, bestEstimateRationale text, rangeLow /
  rangeHigh decimal, expectedTiming object {shortTerm, mediumTerm, longTerm},
  discountRateApplied decimal, discountedValue computed, presentationOnBalanceSheet
  enum, linkedAccount FK, status enum, expert text, peerReviewer FK,
  peerReviewDate date, linkedClaim-voorziening-detail / jubileum-detail / etc FK).
  Validation: all three criteria OR creation blocked.

- [ ] Task 6: Declare the `provision-movement` schema in
  `lib/Settings/shillinq_register.json` with all REQ-PROV-004 fields (provision
  FK, period string, openingBalance decimal, additions decimal,
  additionsAcquired decimal, usedDuringPeriod decimal, releasedUnused decimal,
  unwindingOfDiscount decimal, effectOfChangeInDiscountRate decimal,
  effectOfChangeInEstimate decimal, translationDifferences decimal,
  closingBalance computed, linkedJournalEntries array FK GL entry). Immutability
  constraint: once period closes, movement is read-only.

- [ ] Task 7: Declare the `contingent-liability` schema in
  `lib/Settings/shillinq_register.json` with all disclosure fields (description
  text, obligationType enum, nature enum, estimatedAmount decimal optional,
  probabilityCategory enum {remote, possible, probable-but-no-reliable-estimate},
  expectedTiming text, disclosureNarrative text, relatedParty FK org).

- [ ] Task 8: Declare `pensioenvoorziening-detail` schema with fields: provision
  FK, pensionScheme enum, actuarialMethod enum {PUC}, discountRate decimal,
  salaryGrowthAssumption decimal, mortalityTable text, participantCount int,
  linkedActuaryReport FK docudesk. Link to `bookkeeping-pension-ias19` spec for
  detail.

- [ ] Task 9: Declare `jubileumvoorziening-detail` schema with fields: provision
  FK, caoReference text, eligibleEmployees int, averageServiceYears decimal,
  probabilityOfReachingMilestone decimal, actuarialModel text.

- [ ] Task 10: Declare `herstructureringsvoorziening-detail` schema with fields:
  provision FK, detailedPlanDate date (must be ≤ balance date per IAS 37 §72),
  planCommunicatedTo array text, affectedEmployees int, expectedRedundancyPayments
  decimal, expectedLeaseExitCosts decimal, expectedOnerousContractCosts decimal.
  Validation: detailedPlanDate required and ≤ balance date.

- [ ] Task 11: Declare `garantievoorziening-detail` schema with fields: provision
  FK, productCategories array text, historicalClaimRate decimal, averageClaimAmount
  decimal, warrantyPeriodMonths int, revenueBaseInPeriod decimal.

- [ ] Task 12: Declare `milieuvoorziening-detail` schema with fields: provision
  FK, contaminationLocation text, regulatoryFramework enum {Wbb, Wm, EU-IED},
  cleanupEstimate decimal, expertConsultant text, legallyRequiredCompletionDate
  date, phasedExecutionPlan text, ontmantelingsVerplichting boolean (for IFRS 16
  / IAS 16 §16(c) component activation).

- [ ] Task 13: Declare `claims-voorziening-detail` schema with fields: provision
  FK, caseReference text (court docket), court text, legalCounsel text, claimType
  enum, plaintiffOrClaimant text, amountClaimed decimal, bestEstimateSettlement
  decimal, legalAdviceMemo FK docudesk (restricted access: CFO, audit committee,
  accountant). Validation: legalAdviceMemo required (blocked if missing).

- [ ] Task 14: Author `x-openregister-lifecycle` state machine for `provision`:
  draft → active (on peer-review + CFO approval if > EUR 100K). Author second
  lifecycle for annual herwaardering: active → under-review (balansdatum) →
  active (changes recorded in schattingswijziging field of next provision-movement).

- [ ] Task 15: Author `x-openregister-aggregations` query to emit `provision-movement`
  records per period from completed `provision` valuations: opening balance (from
  prior period closing), + additions (dotatie), − usedDuringPeriod (betaling),
  − releasedUnused (vrijval), + unwindingOfDiscount (discontering rente),
  + effectOfChangeInEstimate (schattingswijziging), + effectOfChangeInDiscountRate
  (discontering assumption change), → closingBalance. Formula: opening + additions
  + acquired − used − released + unwinding + rate-change + estimate-change +
  translation = closing.

- [ ] Task 16: Author `x-openregister-calculations` formula for disconteringsvoet
  unwinding: unwindingOfDiscount = max(0, prior-period discountedValue ×
  discountRateApplied). Auto-calculate if discountRateApplied > 0.

- [ ] Task 17: Author `x-openregister-aggregations` query to emit
  `provision-disclosure-tabel` record per period: header (provision type, count,
  total opening), movement table per provision, contingent-liability section,
  materiality narrative (> EUR 100K or > 1% balance), sensitivity narrative
  (rangeLow / rangeHigh if material). Output suitable for jaarrekening notes.

- [ ] Task 18: Add manifest navigation entries in `lib/Settings/shillinq_register.json`:
  - "Provisions" → list all `provision` records filtered by type
  - "Provision Movements" → list all `provision-movement` records per period
  - "Contingent Liabilities" → list all `contingent-liability` records

- [ ] Task 19: Author schema-level validation rule for three-criteria gating:
  prevent status=active transition unless legalOrConstructiveObligation is set
  AND obligatingEvent text is provided AND probabilityOfOutflow ≥ 0.5 AND
  bestEstimate is provided AND bestEstimateRationale is provided. Error message:
  "IAS 37 / RJ 252 three-criteria recognition failed: [specific criterion]".

- [ ] Task 20: Author schema-level validation rule for herstructureringsvoorziening:
  detailedPlanDate MUST be ≤ balance date (IAS 37 §72 requirement). Error:
  "Herstructureringsvoorziening vereist gedetailleerd plan op of vóór
  balansdatum".

- [ ] Task 21: Author schema-level validation rule for claims-voorziening:
  legalAdviceMemo file FK MUST be provided before status=active. Error:
  "Claims-voorziening vereist juridische advies-memo; voeg document toe via
  docudesk".

- [ ] Task 22: Author schema-level peer-review approval gate: if bestEstimate >
  EUR 100K OR bestEstimate > 1% of prior-year total assets, block status=active
  transition until peerReviewer FK is set and peerReviewDate is populated.
  Prompt: "Voorziening materieel; selecteer peer-reviewer en roep CFO-goedkeuring
  op".

- [ ] Task 23: Author probability-classification enforcement rule: system
  blocks direct `provision` creation if probabilityOfOutflow ≤ 0.5; prompts
  user to create `contingent-liability` instead with probabilityCategory =
  possible (if 0.05 < prob ≤ 0.5) or remote (if prob ≤ 0.05).

- [ ] Task 24: Author disconteringsvoet enforcement rule: if any expectedTiming
  component > 1 year horizon AND bestEstimate material, require discountRateApplied
  field. Warn if rate appears to be government-bond (too low); recommend AA
  corporate + risk premium per IAS 37 BC §141.

- [ ] Task 25: Author immutability constraint on `provision-movement`: once
  period closes (explicit status=closed), no edits permitted. If correction
  needed, create new movement record in open period with effectOfChangeInEstimate
  (prospective per IAS 8).

- [ ] Task 26: Seed data: Create 3 specimen `provision` records (garantie EUR 120K,
  milieu EUR 800K, claims EUR 500K) with complete three-criteria documentation,
  best-estimate + range, and linked type-specific detail records. Operators
  delete and replace with actual data on first use.

- [ ] Task 27: Seed data: Create 2 specimen `contingent-liability` records
  (fiscaal geschil, huurgarantie) demonstrating disclosure-only entries for
  probabilityCategory=possible and remote scenarios.

- [ ] Task 28: Seed data: Create 1 specimen `provision-movement` record for
  guaranteed provision showing opening → additions → used → closing with linked
  GL entries.

- [ ] Task 29: Integration test: Verify three-criteria gating blocks provision
  without all three criteria.

- [ ] Task 30: Integration test: Verify disconteringsvoet automatic unwinding
  calculation and GL posting per period.

- [ ] Task 31: Integration test: Verify peer-review approval gate enforced for
  materiality > EUR 100K and > 1% balance.

- [ ] Task 32: Integration test: Verify provision-movement immutability after
  period close; verify prospective schattingswijziging in next open period.

- [ ] Task 33: Integration test: Verify jaarrekening disclosure-table aggregation
  emits correct movement table per provision type and contingent-liability
  narratives.

- [ ] Task 34: Integration test: Verify GL posting: provision dotation (COGS
  effect), vrijval (reversal), discontering unwinding (rente) all linked in
  linkedJournalEntries.

- [ ] Task 35: Integration test: Verify contingent-liability classification:
  probability 30% auto-prompts contingent-liability creation instead of provision.
