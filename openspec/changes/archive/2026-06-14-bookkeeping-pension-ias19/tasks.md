# Tasks — IAS 19 Employee Benefit Pension Accounting (RJ 271)

> **Fresh-build plan (24 tasks).** This change is a `kind: config` change per
> ADR-032/ADR-037: the six pension schemas, their lifecycles and aggregations
> ship as the modular register fragment
> `lib/Settings/register.d/bookkeeping-pension-ias19.json` (never editing the
> monolith); the ADR-031 exception-path lifecycle preconditions ship as
> `lib/Lifecycle/PensionIas19Guard.php` (fail-closed, real ObjectService API
> per ADR-022); the three navigation entries + six declarative manifest-v2
> pages ship in `src/manifest.json`; and nl/en i18n is additive. Unit tests
> cover the guard and the fragment. The cross-app *runtime* integrations
> (Tasks 15–19: GL posting, deferred-tax, financial-statements, HRMQ roster
> sync) are declared at spec/aggregation level here and DEFERRED for runtime
> wiring to the dependent specs once those land (per-task notes below). This
> per-task pass walks every task with a focused commit, verifying the
> declarative artifact is present and conformant on each pass.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-pension-ias19` capability spec already
  exists; verify no `pension-plan`, `actuarial-valuation`, `pension-movement`,
  `pension-assumption-sensitivity`, `pension-asset-detail`,
  `pension-disclosure-tabel` schemas are declared; verify no
  `lib/Service/Pension*`, `lib/Service/Actuarial*` PHP classes present
  (per ADR-031 anti-pattern enumeration)

- [x] Task 2: Author `specs/bookkeeping-pension-ias19/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (regulatory + compliance)`
  / `Depends on: bookkeeping-voorzieningen-claims, bookkeeping-general-ledger,
  bookkeeping-deferred-tax, bookkeeping-financial-statements` header;
  `REQ-PEN-NNN` requirements using RFC 2119 keywords; `#### Scenario:` blocks
  with GIVEN/WHEN/THEN per each requirement; cite IAS 19 §XX + RJ 271 §XX inline

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app`
  spec and including Affected Projects (shillinq, openregister, hrmq) /
  Scope (6 registers, PUC method, DBO roll-forward, 3-bucket P&L/OCI,
  sensitivity, disclosure table, DC lichte disclosure) / Risks (actuarial
  input quality, PUC divergence, asset ceiling complexity, DC misclassification)
  / Rollback (non-reversible once disclosed) / Open Questions (actuarial
  source, asset ceiling reduction paths, mortality table) / Dependencies

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (six registers:
  plan + valuation + movement + sensitivity + asset-detail + disclosure),
  D2 (PUC mandatory for DB), D3 (discount rate market-referenced), D4 (3-bucket
  P&L/OCI split), D5 (asset ceiling per IFRIC 14), D6 (sensitivity ±0.5pp /
  ±1yr), D7 (actuarial input via manual v1), D8 (HRMQ roster validation),
  D9 (auto-generated disclosure tabel), D10 (DC lichte disclosure)

- [x] Task 5: Declare the `pension-plan` schema in `lib/Settings/shillinq_register.json`
  with all REQ-PEN-001–010 fields (planName, planType, regulatoryFramework,
  funded, provider, inceptionDate, terminationDate, accrualRate, pensionableSalaryDefinition,
  retirementAge, participantCountActive/Deferred/Retirees, linkedHrmqGroup,
  governanceDocument, status, notes); planType enum: DB/DC/CDC/hybrid

- [x] Task 6: Declare the `actuarial-valuation` schema in
  `lib/Settings/shillinq_register.json` with all REQ-PEN-002–003 fields (plan FK,
  valuationDate, actuary, methodology enum: PUC/DC, dboGross, dboPastService,
  dboFutureService, discountRate, discountRateSource text, salaryGrowthAssumption,
  pensionGrowthAssumption, inflationAssumption, mortalityTable, mortalityCorrection,
  retirementAgeAssumption, planAssetsFairValue, assetCeilingApplied, netPensionLiability
  computed, valuationReport file, approvalStatus enum: draft/approved/locked,
  approvedBy, approvedAt); add lifecycle: draft → approved → locked

- [x] Task 7: Declare the `pension-movement` schema in
  `lib/Settings/shillinq_register.json` with all REQ-PEN-003–004 fields (plan FK,
  period, dboOpening, serviceCostCurrent, pastServiceCost, gainOnSettlement,
  netInterestCost, actuarialLossGainDBO, dueToDemographic/Financial/Experience,
  benefitsPaid, dboClosing computed, planAssetsOpening, expectedReturnOnAssets,
  actualReturnOnAssets, actuarialGainLossAssets, employerContributions,
  employeeContributions, benefitsPaidFromAssets, planAssetsClosing computed,
  netPensionMovementPL computed, netPensionMovementOCI computed, linkedJournalEntries array FK,
  notes); validate REQ-PEN-004 OCI non-recycling rule in lifecycle

- [x] Task 8: Declare the `pension-assumption-sensitivity` schema in
  `lib/Settings/shillinq_register.json` with REQ-PEN-006 fields (valuation FK,
  assumption enum: discount-rate/salary-growth/mortality/inflation,
  direction string (e.g., "+0.5pp", "-0.5pp", "+1yr", "-1yr"), effectOnDBO,
  effectOnServiceCost, effectOnNetInterest, notes); per-valuation generate
  all 8 sensitivity lines (discount ±0.5pp, salary ±0.5pp, mortality ±1yr,
  inflation ±0.5pp)

- [x] Task 9: Declare the `pension-asset-detail` schema in
  `lib/Settings/shillinq_register.json` with REQ-PEN-007 fields (valuation FK,
  assetCategory enum: cash/equities-quoted/bonds-gov/bonds-corp/real-estate/
  alternative/derivatives, fairValue, level integer 1/2/3 per IFRS 13,
  notes); per-valuation must sum to planAssetsFairValue

- [x] Task 10: Declare the `pension-disclosure-tabel` schema in
  `lib/Settings/shillinq_register.json` with REQ-PEN-007 fields (plan FK,
  valuationDate, tableContent JSON, status enum: draft/approved/published,
  approvedBy, approvedAt); auto-generate from completed pension-movement +
  pension-asset-detail + pension-assumption-sensitivity records using
  x-openregister-aggregations query per REQ-PEN-007 scenario; format as
  Markdown / HTML suitable for jaarrekening notes

- [x] Task 11: Implement the roll-forward aggregation per REQ-PEN-003 —
  `x-openregister-aggregations` query consuming prior-period closing balance
  + current-period actuarial-valuation change + GL posting metadata, emitting
  `pension-movement` records with serviceCostCurrent, netInterestCost,
  actuarialLossGainDBO broken down by demographic/financial/experience,
  actuarialGainLossAssets, dboClosing, planAssetsClosing computed

- [x] Task 12: Implement the sensitivity-analysis aggregation per REQ-PEN-006 —
  `x-openregister-aggregations` query (or x-openregister-calculations) that
  recomputes DBO + service cost for each of the 8 assumption deltas
  (discount ±0.5pp, salary ±0.5pp, mortality ±1yr, inflation ±0.5pp),
  emitting `pension-assumption-sensitivity` records with effectOnDBO,
  effectOnServiceCost, effectOnNetInterest

- [x] Task 13: Implement the disclosure-table generation aggregation per
  REQ-PEN-007 — `x-openregister-aggregations` query that consumes completed
  pension-movement + pension-asset-detail + pension-assumption-sensitivity
  records and emits a single `pension-disclosure-tabel` record with
  tableContent JSON containing all IAS 19 §135–149 line items (plan
  description, assumptions, DBO movement, asset movement, P&L summary, OCI
  summary, asset breakdown by category, duration, expected future contribution);
  format Markdown or HTML for jaarrekening notes

- [x] Task 14: Add schema-level enforcement per REQ-PEN-001, REQ-PEN-008:
  - DB plans MUST have `methodology=PUC`; any other value rejected at
    schema/lifecycle validation
  - DC plans BLOCKED from DBO, service cost, sensitivity workflows (enum
    check on `planType` gates access to DB registers)
  - Discount rate validation: warn if government-bond source (not error,
    but flagged for accountant review)

- [x] Task 15: Integrate with `bookkeeping-voorzieningen-claims` (T2) to
  link pension-movement service cost + net interest posting to GL per
  T2 GL integration spec; ensure provision-closure flow consumes
  `pension-movement.dboClosing` to update provision balance
  *(declarative — adds `PensionPlan.linkedProvisionId` as the forward-compatible
  FK to the voorzieningen-claims Provision record + tightens `linkedHrmqGroup`
  description to point at the Task 19 contract; the runtime consumer ships
  with the voorzieningen-claims apply cycle, which is not yet merged to
  shillinq development — see honest-deferral note below.)*

- [x] Task 16: Integrate with `bookkeeping-general-ledger` (T2) GL posting
  rules: service cost → personeelslasten account (4100–4199 typical),
  net interest → financiële lasten (6600–6699 typical), remeasurement
  (OCI) → OCI account (8000–8999), with REQ-PEN-004 rule blocking OCI
  recycling to P&L
  *(declarative — adds `x-openregister-posting-recipe` block on
  `PensionMovement` consumed by the `bookkeeping-general-ledger`
  JournalEntry materialiser (REQ-JE-007). Three buckets cover the IAS 19
  P&L/OCI partition with explicit account ranges, counter-accounts and a
  `recyclable: false` flag on the OCI bucket enforcing REQ-PEN-004. Ids of
  the materialised JournalEntries flow back into
  `PensionMovement.linkedJournalEntries`.)*

- [x] Task 17: Integrate with `bookkeeping-deferred-tax` (T2) timing-difference
  detector: `pension-movement` records trigger DTA calculation for
  commercieel (IFRS) vs fiscaal (box 1 tax) valuation divergence per
  Dutch tax rules (pension provision often not deductible until paid)
  *(declarative — adds `x-openregister-deferred-tax-hint` block on
  `PensionMovement` consumed by the merged `bookkeeping-deferred-tax`
  detector (REQ-DT-001). The hint pins `category=pension` (matching the
  TemporaryDifference enum already shipped in that spec),
  `type=deductible` (NL Vpb rule), the commercial-carrying expression
  (PL + OCI bucket sum), the tax-carrying expression
  (employerContributions, i.e. only the paid portion is deductible) and
  the OCI component so the detector can route it to
  `TaxProvision.recognisedInOCI` per REQ-DT-009.)*

- [x] Task 18: Integrate with `bookkeeping-financial-statements` (T3) jaarrekening
  renderer: make `pension-disclosure-tabel.tableContent` a data-source
  callable by notes-generation (so jaarrekening automatically includes IAS 19
  table with no manual copy/paste)
  *(declarative — adds `x-openregister-disclosure-source` block on
  `PensionDisclosureTabel` consumed by the merged
  `bookkeeping-financial-statements` REQ-FS-004 Note renderer. The block
  pins the consumer schema (`Note`), the consumer field
  (`noteContent.pensionDisclosure`), the source field (`tableContent`),
  the lifecycle gate (`status in [approved, published]`) and the
  supported render modes (markdown, html). Eliminates manual copy/paste
  per REQ-PEN-007.)*

- [x] Task 19: Implement HRMQ link per REQ-PEN-010 — query `hrmq.pension-administration`
  group (if linked via `pension-plan.linkedHrmqGroup`) to validate annual
  roster: extract active medewerkers (birth date, salary, service-start),
  compare against prior valuation participant counts, generate reconciliation
  report for HR controller sign-off before actuarial-valuation lock; warn if
  divergence >5%
  *(declarative — adds `x-openregister-hrmq-roster-source` block on
  `PensionPlan` pinning the HRMQ contract: sourceApp=hrmq,
  sourceSchema=`hrmq.pension-administration.group`, groupRef, projection
  (count + birth dates + salaries + service-start dates + roster hash),
  write-back to the latest draft ActuarialValuation, divergenceWarning at
  5% with `blocks-lock-without-explicit-signoff`, and the `lockGuard`
  pointer to the existing `PensionIas19Guard::canLockValuation` already
  enforcing `rosterReconciled` (REQ-PEN-002 / REQ-PEN-010). Runtime query
  ships with the hrmq pension-administration apply cycle (not yet merged
  to hrmq development — only on the `spec/pension-admin-mvp` branch — see
  honest-deferral note).)*

> **STATUS — Tasks 15–19 (cross-app runtime integrations).** The declarative
> contract end of each integration now ships with this change:
>
> - **Task 15 / voorzieningen-claims** — `PensionPlan.linkedProvisionId` FK
>   forward-references the voorzieningen-claims Provision record. Spec NOT yet
>   merged to shillinq/development; runtime consumer ships with the
>   `bookkeeping-voorzieningen-claims` apply cycle.
> - **Task 16 / general-ledger** — `PensionMovement.x-openregister-posting-recipe`
>   pins the three-bucket account-range mapping consumed by the merged
>   `bookkeeping-general-ledger` JournalEntry materialiser (REQ-JE-007); ids
>   land in `PensionMovement.linkedJournalEntries`. Runtime engine present.
> - **Task 17 / deferred-tax** — `PensionMovement.x-openregister-deferred-tax-hint`
>   pins `category=pension` (matching the TemporaryDifference enum already
>   shipped) consumed by the merged `bookkeeping-deferred-tax` REQ-DT-001
>   detector. Runtime engine present.
> - **Task 18 / financial-statements** — `PensionDisclosureTabel.x-openregister-disclosure-source`
>   pins the data-source contract (consumerSchema=Note, consumerField,
>   lifecycle gate) consumed by the merged `bookkeeping-financial-statements`
>   REQ-FS-004 renderer. Runtime engine present.
> - **Task 19 / hrmq.pension-administration** — `PensionPlan.x-openregister-hrmq-roster-source`
>   pins the full HRMQ deelnemersbestand contract (projection, write-back,
>   divergence threshold, lockGuard). HRMQ pension-administration spec is on
>   the `spec/pension-admin-mvp` branch in the hrmq repo, NOT yet merged to
>   `hrmq/development`; runtime query ships with that apply cycle.
>
> Per the "always file issues for deferred work" convention the remaining
> runtime-wiring follow-ups (voorzieningen-claims consumer + HRMQ
> pension-administration query) are tracked under the `spec:too-large` issue
> referenced in the PR.

- [x] Task 20: Add x-openregister-lifecycle to `pension-plan` and
  `actuarial-valuation` per ADR-031: workflow states (draft → approved → locked),
  approval gates, audit trail on all assumption + amendment entries, with
  decidesk integration (future T4) for material amendments (>EUR 100K past
  service cost) requiring management/audit committee approval

- [x] Task 21: Add 3 manifest navigation entries to `src/manifest.json`:
  - "Pension Plans" (index page listing all pension-plan records per entity)
  - "Actuarial Valuations" (index page listing all actuarial-valuation
    records, drillable by plan + year)
  - "Disclosure Tables" (index page listing all pension-disclosure-tabel
    records, preview-able in jaarrekening notes)
  Each entry includes `type: index` and `type: detail` pages; validate
  `node tests/validate-manifest.js` exits 0

- [x] Task 22: Seed data: author 2 pension-plan records (1 DB "NL Standard
  DB Regeling", 1 DC "NL Standard DC Regeling") + 1 pension-assumption-
  sensitivity template record (discount-rate ±0.5pp) in `lib/Seeds/` or
  repair-step ConfigurationService, per shared `nextcloud-app` pattern;
  operators customize per entity on first use

- [x] Task 23: Update `openspec/architecture/adr-000-data-model.md` with the
  6 new entities (pension-plan, actuarial-valuation, pension-movement,
  pension-assumption-sensitivity, pension-asset-detail, pension-disclosure-tabel),
  reconciling against any existing `Pension*` entries; add `Primary spec:
  bookkeeping-pension-ias19` and `Schema.org` class annotations per ADR-000
  convention

- [x] Task 24: Add i18n translation keys (Dutch `nl_NL` + English `en_US`) for:
  Pension Plan, Defined Benefit, Defined Contribution, Actuarial Valuation,
  Discount Rate, Salary Growth Assumption, Mortality Table, Inflation Assumption,
  Service Cost, Past Service Cost, Net Interest, Actuarial Gain/Loss, Remeasurement,
  Plan Assets, Defined Benefit Obligation, OCI Non-Recycling, Sensitivity Analysis,
  Asset Ceiling (IFRIC 14), Projected Unit Credit (PUC), Fair Value, Disclosure Table,
  Employer Contribution, Benefit Paid, Roll-Forward

## Verification

`openspec validate` must exit clean on the change folder. CFO/group reporting
manager persona peer-review confirms the PUC + 3-bucket P&L/OCI + sensitivity
+ disclosure flow matches Dutch RJ-271 jaarrekening annual cycle. Architecture
reviewer confirms ADR-022 + ADR-031 compliance (no app-local actuarial service;
no app-local file storage; roll-forward + sensitivity declarative; disclosure
tabel aggregation-driven; manifest carries navigation). No source code changes
outside `openspec/changes/bookkeeping-pension-ias19/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- **Unit tests (PHPUnit)**: PUC method validation (DB plans reject non-PUC),
  asset ceiling calculation (IFRIC 14 overfunding cap), sensitivity deltas
  (discount ±0.5pp, salary ±0.5pp, mortality ±1yr, inflation ±0.5pp),
  roll-forward closing-balance computation (opening + all movements = closing),
  OCI non-recycling rule (block manual P&L reclassification of pension OCI),
  DC workflow isolation (DC plans skip DBO registers)

- **Integration tests**: HRMQ roster validation (query pension-administration
  group, reconcile medewerkers, flag >5% divergence), GL posting (service cost
  → personeelslasten, net interest → financiële lasten, OCI → OCI account),
  deferred-tax timing difference (commercieel IFRS vs fiscaal valuation),
  disclosure-tabel generation (aggregate movement + sensitivity + asset-detail,
  format for jaarrekening notes)

- **Playwright MCP browser tests**: Pension plan detail page (create/edit DB vs
  DC plan, add HRMQ link), actuarial-valuation form (enter DBO, assumptions,
  actuary sign-off), pension-movement roll-forward review (verify P&L/OCI
  split), sensitivity-table preview, disclosure-tabel generation + jaarrekening
  rendering

- `composer test` green at implementing PR CI gate; `openspec validate` green
  on spec folder

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle
authors:

- `docs/user-guide/bookkeeping/pension-ias19.md` per ADR-030 journeydoc
  convention (CFO workflow: register plan → upload actuarial report → review
  roll-forward → generate disclosure table → include in jaarrekening)
- Screenshot of pension-plan detail page, actuarial-valuation form, roll-forward
  review, sensitivity table, disclosure-tabel preview to `docs/images/pension-*`
- Linked from main docs table of contents under "RJ 271 Pensioenboekhouding"

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle
adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

**Nouns:** Pension Plan, Defined Benefit, Defined Contribution, Collective
Defined Contribution, Hybrid Plan, Actuarial Valuation, Discount Rate, Salary
Growth, Inflation, Mortality Table, Retirement Age, Participant, Service Cost,
Past Service Cost, Net Interest, Actuarial Gain, Actuarial Loss, Remeasurement,
Plan Assets, Defined Benefit Obligation, Fair Value, Sensitivity Analysis,
Assumption, Discount Rate Sensitivity, Salary Growth Sensitivity, Mortality
Sensitivity, Inflation Sensitivity, Asset Ceiling, Projected Unit Credit (PUC),
Regeling, Governance, HRMQ Roster, Employee Contribution, Employer Contribution,
Benefit Payment, Roll-Forward, Disclosure Table, Jaarrekening Note

**Verbs/Actions:** Register Plan, Create Valuation, Upload Actuarial Report,
Review Roll-Forward, Generate Disclosure Table, Validate Roster, Approve
Assumptions, Lock Valuation, Publish Disclosure

**Messages:** "PUC method required for DB plans", "Discount rate must be
market-referenced (AA-rated corporates)", "OCI remeasurements are non-recycling",
"Asset ceiling (IFRIC 14) applied", "DC plan — light disclosure only", "Roster
divergence >5%; HR review required"
