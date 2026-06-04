# Tasks — Bookkeeping Operations + NL Compliance Core (T3)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the 10 spec deltas — they are pre-declared so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## 0. Deduplication Check (per ADR-012 + hydra `tasks.proposal` rule)

### Task 0.1: Confirm no T3 schema or capability already exists

- **spec_ref**: all 10 specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/architecture/adr-000-data-model.md`, T1's already-merged registers (Account, GLTransaction, GLLine, JournalEntry), T2's in-flight registers (Invoice, Payment, BankTransaction, FiscalPeriod, TrialBalance, FinancialStatement)
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of `feat/bookkeeping-engine` WHEN `lib/Settings/shillinq_register.json` is inspected THEN no `VatReturn`, `IcpStatement`, `VatCorrection`, `VatTariff`, `BbvAccountMapping`, `BbvTaakveld`, `Iv3Export`, `BcfClaim`, `KorRegime`, `KorThreshold`, `UrenRegistratie`, `ZzpDeduction`, `IbAangifteExport`, `SchatkistPosition`, `Subsidie`, `RepaymentInstallment`, `RetentionRule`, `Project`, `ProjectAssignment`, `RateCard`, or `WipBalance` schema is already declared.
  - GIVEN `openspec/specs/` WHEN scanned THEN no `bookkeeping-vat-*`, `bookkeeping-bbv-*`, `bookkeeping-iv3-*`, `bookkeeping-bcf-*`, `bookkeeping-kor-*`, `bookkeeping-zzp-*`, `bookkeeping-schatkist-*`, `bookkeeping-subsidie-*`, `bookkeeping-archiefwet-*`, or `bookkeeping-consultancy-*` capability spec already exists.
  - GIVEN T1 + T2 schemas WHEN scanned THEN no overlapping field set duplicates a T3 capability (e.g. T1's `Account` does NOT carry `bcfCompensable` or `taakveld` — those live in T3's `BbvAccountMapping`).
  - GIVEN `openconnector` source registrations WHEN scanned THEN `digipoort-sbr`, `cbs-iv3`, `digikoppeling-bcf` source names are not yet registered (their registration lives in a separate change — `add-openconnector-nl-overheid-sources`).
- [ ] Implement
- [ ] Test (`openspec validate` clean; manual sibling-spec scan)

### Task 0.2: Confirm consumption of existing OR abstractions, not reinvention

- **spec_ref**: every spec (cross-cutting)
- **files**: every T3 spec's `## ADDED Requirements`
- **acceptance_criteria**:
  - GIVEN any T3 spec WHEN scanned for verbs like "implement an audit table", "build an approval queue", "write a retention sweep job" THEN no such phrasing SHALL appear — every audit, approval, retention reference MUST cite ADR-022 + consume the OR abstraction.
  - GIVEN any T3 spec WHEN scanned for state-machine descriptions THEN every state machine MUST be declared via `x-openregister-lifecycle` (ADR-031), NOT via a `*Service.transition*` method.
  - GIVEN any T3 spec mentioning external HTTP (SBR, CBS, DigiKoppeling) WHEN scanned THEN the submission MUST be expressed as an OR `ScheduledWorkflow` consuming an OpenConnector source (ADR-019 + ADR-022), never as a PHP `HttpClient` wrapper.
- [ ] Implement
- [ ] Test (reviewer manually confirms during spec review)

## 1. Spec foundation (this change)

### Task 1.1: Author 10 capability specs

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-*/spec.md`
- **files**: one per capability (bookkeeping-vat-btw-filing, bookkeeping-bbv-compliance, bookkeeping-iv3-reporting, bookkeeping-bcf-vat-compensation, bookkeeping-kor-kleine-ondernemersregeling, bookkeeping-zzp-tax-regime, bookkeeping-schatkistbankieren, bookkeeping-subsidie-verantwoording, bookkeeping-archiefwet-retention, bookkeeping-consultancy-project-accounting)
- **acceptance_criteria**:
  - GIVEN each spec WHEN opened THEN it carries the `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: [T1/T2 siblings]` header.
  - GIVEN each spec WHEN scanned THEN every requirement uses `### REQ-<ABBR>-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4 hashtags on the scenario header per conduction-schema rule).
  - GIVEN each spec WHEN scanned THEN ADR-022 (audit), ADR-024 (manifest), ADR-031 (declarative lifecycle), and relevant ADR-019 (external integrations) are cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.2: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the shared `nextcloud-app` spec per shillinq config.yaml `rules.proposal`, includes Affected Projects / Scope / Risks / Rollback / Open Questions, AND classifies `kind: config` per ADR-032.
  - GIVEN `design.md` WHEN inspected THEN it includes a Reuse Analysis table, a Seed Data section, and a Declarative-vs-imperative decision table per hydra `rules.design` + ADR-031 enforcement.
- [x] Implement
- [ ] Test (peer review — bookkeeper + compliance-officer personas read the model end-to-end and confirm regulatory citations)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare VAT/BTW registers — `VatReturn`, `IcpStatement`, `VatCorrection`, `VatTariff`

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the four schemas WHEN loaded THEN every required field per spec is present with declared typing.
  - GIVEN the `VatReturn.state` lifecycle WHEN scanned THEN the transitions are declared via `x-openregister-lifecycle`.
  - GIVEN the `draft → submitted` precondition WHEN inspected THEN `x-openregister-lifecycle.requires.approval-workflow` is present.
  - GIVEN the `rubrieken` field WHEN inspected THEN it is declared as a derived field via `x-openregister-aggregations`.
- [x] Implement
- [ ] Test (PHPUnit: lifecycle transitions; aggregation correctness over seeded GL fixture; approval-gate honoured)

### Task 2.2: Declare BBV registers — `BbvAccountMapping`, `BbvTaakveld`

- **spec_ref**: `bookkeeping-bbv-compliance/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN `BbvAccountMapping` WHEN loaded THEN fields per spec are present, AND `(administrationId, accountNumber)` is unique (declarative constraint).
  - GIVEN the T1 `GLTransaction.post` lifecycle precondition WHEN scanned THEN it asserts BBV-mapping existence for municipal administrations.
- [x] Implement
- [ ] Test (PHPUnit: unmapped account fails posting for municipal admin; non-municipal admin bypasses the check; BBV aggregations return correct totals)

### Task 2.3: Declare IV3 register — `Iv3Export`

- **spec_ref**: `bookkeeping-iv3-reporting/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per spec are present.
  - GIVEN the lifecycle WHEN scanned THEN the transitions are declared.
  - GIVEN the `buckets` field WHEN inspected THEN it is declared as a derived field via `x-openregister-aggregations`.
- [ ] Implement
- [ ] Test (PHPUnit: aggregation correctness; XML validates against CBS schema; submission triggers via OpenConnector mock)

### Task 2.4: Declare BCF register — `BcfClaim`

- **spec_ref**: `bookkeeping-bcf-vat-compensation/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per spec are present.
  - GIVEN the lifecycle WHEN scanned THEN the transitions are declared with the claim-arithmetic precondition.
  - GIVEN `BbvAccountMapping` WHEN extended THEN it carries `compensablePercentage` per spec.
- [x] Implement
- [ ] Test (PHPUnit: claim aggregation includes only compensable accounts at the correct percentage; submission via OpenConnector mock)

### Task 2.5: Declare KOR registers — `KorRegime`, `KorThreshold`

- **spec_ref**: `bookkeeping-kor-kleine-ondernemersregeling/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN `KorRegime` WHEN loaded THEN fields per spec are present.
  - GIVEN `KorRegime.state` lifecycle WHEN scanned THEN the auto-transitions on threshold crossings are declared.
  - GIVEN the lifecycle's post-transition action WHEN inspected THEN the `threshold-exceeded → opted-out` action creates a `JournalEntry` in `state: pending` (NOT auto-posted).
  - GIVEN `KorRegime.ytdRevenue` WHEN inspected THEN it is declared as `x-openregister-calculations` per spec (OR a referenced PHP guard with ADR-031 exception annotation).
- [ ] Implement
- [ ] Test (PHPUnit: threshold-crossing transitions; notification fires at 80% + 100%; opt-out journal is `pending` not `posted`)

### Task 2.6: Declare ZZP registers — `UrenRegistratie`, `ZzpDeduction`, `IbAangifteExport`

- **spec_ref**: `bookkeeping-zzp-tax-regime/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the three schemas WHEN loaded THEN fields per spec are present.
  - GIVEN `UrenRegistratie.category` WHEN inspected THEN excluded categories require `excludedReason` per spec.
  - GIVEN `ZzpDeduction.ytdQualifyingHours` WHEN inspected THEN it is declared as `x-openregister-calculations` per spec (OR a referenced PHP guard with ADR-031 exception annotation).
- [x] Implement
- [ ] Test (PHPUnit: excluded-hours filtering; deduction calculation correctness with starters scenarios)

### Task 2.7: Declare Schatkist register — `SchatkistPosition` + `Account` extension

- **spec_ref**: `bookkeeping-schatkistbankieren/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN T1 `Account` WHEN extended THEN it carries `isSchatkistAccount: boolean` (default `false`) per spec.
  - GIVEN `SchatkistPosition` WHEN loaded THEN fields per spec are present.
  - GIVEN the daily aggregation WHEN scanned THEN it is declared as `x-openregister-aggregations` filtered by `isSchatkistAccount` per spec.
  - GIVEN the daily workflow WHEN scanned THEN it is declared as `ScheduledWorkflow`, NOT a `*Job` class, per spec.
- [x] Implement
- [ ] Test (PHPUnit: aggregation includes only flagged accounts; daily workflow generates one record per administration per day; threshold-crossing notification fires)

### Task 2.8: Declare Subsidie registers — `Subsidie`, `RepaymentInstallment`

- **spec_ref**: `bookkeeping-subsidie-verantwoording/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the two schemas WHEN loaded THEN fields per spec are present.
  - GIVEN `Subsidie.state` lifecycle WHEN scanned THEN the transitions are declared with approval-workflow requires on `verleen` + `terugvorder`.
  - GIVEN the `vastgesteld → uitbetaald` transition WHEN inspected THEN it creates a `JournalEntry` in `pending` state per spec.
- [x] Implement
- [ ] Test (PHPUnit: lifecycle transitions; approval-gates honoured; repayment-plan instalments created correctly)

### Task 2.9: Declare Retention register — `RetentionRule`

- **spec_ref**: `bookkeeping-archiefwet-retention/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN `RetentionRule` WHEN loaded THEN fields per spec are present.
  - GIVEN every existing shillinq schema (T1 + T2 + the 9 other T3 schemas) WHEN scanned THEN each carries `x-openregister-lifecycle.retention.rule` per the spec table.
  - GIVEN the `daysUntilRetention` derived field WHEN inspected on every retention-bound schema THEN it is declared via `x-openregister-calculations` per spec.
- [ ] Implement
- [ ] Test (PHPUnit: schema-load validator enforces retention rule presence; operator override prevails over seeded default; `daysUntilRetention` calculation correctness)

### Task 2.10: Declare Project accounting registers — `Project`, `ProjectAssignment`, `RateCard`, `WipBalance`

- **spec_ref**: `bookkeeping-consultancy-project-accounting/spec.md`
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the four schemas WHEN loaded THEN fields per spec are present.
  - GIVEN `Project.recognisedRevenue` WHEN inspected THEN it is declared via `x-openregister-calculations` per spec.
  - GIVEN `BillableHour` (UrenRegistratie extension) WHEN inspected THEN it carries `recognisedRate` snapshotted at write time per spec.
  - GIVEN the WIP snapshot workflow WHEN scanned THEN it is declared as `ScheduledWorkflow` triggered by period-end per spec.
- [ ] Implement
- [ ] Test (PHPUnit: percentage-of-completion calculation correctness; rate-card snapshot honours work date not invoice date; WIP snapshot fires on period close)

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1–3.10: Ship seed files (all 9 required seed files)

- **spec_ref**: all 10 specs
- **files**: `lib/Settings/seeds/btw-tariffs-2026.json`, `bbv-taakvelden-2024.json`, `rgs-to-bbv-mapping.json`, `selectielijst-gemeenten-2020.json`, `kor-thresholds-2026.json`, `urencriterium-thresholds.json`, `asv-model-lifecycle.json`, `rj-270-stages.json`, `rate-card-templates.json`, `schatkist-thresholds.json` (note: schatkist seed mentioned in design.md)
- **acceptance_criteria**:
  - GIVEN each seed file WHEN loaded THEN it contains the complete set of values enumerated in the relevant spec.
  - GIVEN each file WHEN opened THEN it has an SPDX header and `_meta` block with source + version.
  - GIVEN a fresh shillinq install WHEN the repair step runs THEN each seed file's records appear in its target register, idempotent on re-run.
  - GIVEN per-administration override WHEN a record is edited THEN the operator edit persists across subsequent repair runs (no overwrite of operator-authored records).
- [x] Implement
- [ ] Test (PHPUnit: parse + import + every record validates; per-admin idempotent seed; operator override preserved on re-run)

### Task 3.11: Extend the repair step to import every T3 seed file

- **spec_ref**: every spec's seed-data requirement
- **files**: existing repair class under `lib/Migration/` or `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh shillinq install WHEN the repair step runs THEN each seed file's records appear in its target register, idempotent on re-run.
  - GIVEN per-administration override WHEN a record is edited THEN the operator edit persists across subsequent repair runs.
  - GIVEN a `gemeente` administration WHEN the repair step runs THEN the BBV-mapping seed is applied for THAT administration; non-municipal admins skip the BBV seed.
- [x] Implement
- [ ] Test (PHPUnit + browser smoke in dev container)

## 4. Manifest navigation — `src/manifest.json`

### Task 4.1: Add Belastingen menu (BTW, ICP, BTW-correcties)

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares `Belastingen > BTW-aangiften`, `Belastingen > ICP-opgaaf`, `Belastingen > BTW-correcties` with `type: index` + `type: detail` pages.
  - GIVEN `node tests/validate-manifest.js` WHEN run THEN it exits 0.
- [x] Implement
- [ ] Test (validate-manifest + browser smoke for each page)

### Task 4.2: Add Overheid menu (BBV, IV3, BCF, Schatkist)

- **spec_ref**: BBV + IV3 + BCF + Schatkist specs
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares `Overheid > BBV-mapping`, `Overheid > IV3-rapportages`, `Overheid > BCF-claims`, `Overheid > Schatkist-positie` with appropriate `type` pages.
  - GIVEN the visibility predicate WHEN evaluated THEN these entries show only for `gemeente`/`provincie`/`waterschap` administrations.
- [x] Implement
- [ ] Test (validate-manifest + visibility predicate test)

### Task 4.3: Add KOR + ZZP menus

- **spec_ref**: KOR + ZZP specs
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares `Belastingen > KOR-status`, `Belastingen > Urenregistratie`, `Belastingen > ZZP-aftrek`, `Belastingen > IB-aangifte` with appropriate `type` pages.
  - GIVEN the visibility predicate WHEN evaluated THEN these entries show only for `mkb`/`zzp` administrations.
- [x] Implement
- [ ] Test (validate-manifest + browser smoke)

### Task 4.4: Add Subsidies + Projecten + Bewaartermijnen menus

- **spec_ref**: Subsidie + CPA + Retention specs
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares `Subsidies` (with sub-pages), `Projecten > Overzicht`, `Projecten > Tarieven`, `Projecten > Utilisatie`, `Administratie > Bewaartermijnen` with appropriate `type` pages.
- [x] Implement
- [ ] Test (validate-manifest + browser smoke)

## 5. ScheduledWorkflow declarations

### Task 5.1–5.5: Declare 5 external submission + aggregation workflows

- **spec_ref**: VBTW + IV3 + BCF + Schatkist + CPA specs
- **files**: `lib/Settings/shillinq_register.json` (workflow block) or the OR scheduled-workflow seed
- **acceptance_criteria**:
  - GIVEN each workflow declaration WHEN scanned THEN it specifies the cron schedule per the spec (monthly/quarterly for SBR/IV3/BCF; daily for schatkist; period-end for WIP).
  - GIVEN each workflow WHEN invoked THEN it consumes the correct OpenConnector source or OR abstraction.
- [ ] Implement
- [ ] Test (PHPUnit + integration test against OpenConnector mocks)

## 6. ADR-000 reconciliation note

### Task 6.1: Update adr-000-data-model.md

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the ADR WHEN opened THEN the new 14+ T3 entities (VatReturn, IcpStatement, VatCorrection, VatTariff, BbvAccountMapping, BbvTaakveld, Iv3Export, BcfClaim, KorRegime, KorThreshold, UrenRegistratie, ZzpDeduction, IbAangifteExport, SchatkistPosition, Subsidie, RepaymentInstallment, RetentionRule, Project, ProjectAssignment, RateCard, WipBalance) are recorded with their `Primary spec:` references pointing at the new T3 specs.
  - GIVEN any pre-existing ADR-000 entries overlapping the new schemas WHEN read THEN reconciliation notes are appended (similar to T1's GLLine ↔ GeneralLedgerEntry note).
- [ ] Implement
- [ ] Test (peer review by the bookkeeper + compliance-officer personas)

## 7. Conditional thin PHP guards (only if Risk 3 confirms)

### Task 7.1 (conditional): Author KorThresholdGuard

- **spec_ref**: `bookkeeping-kor-kleine-ondernemersregeling/spec.md`
- **files**: `lib/Lifecycle/KorThresholdGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN the discovery step concluded the engine cannot express cross-period revenue aggregation declaratively WHEN the guard is implemented THEN it has exactly one method `currentYtdRevenue(string $adminId, int $year): float` and is referenced from `x-openregister-lifecycle.requires` on the `KorRegime` lifecycle.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031 exception annotation linking back to design.md's Declarative-vs-imperative decision table.
- [x] Implement (only if conditional triggered)
- [x] Test (PHPUnit: invoice fixture sums correctly; edge cases for cancelled invoices, credit notes, partial periods)

### Task 7.2 (conditional): Author UrencriteriumGuard

- **spec_ref**: `bookkeeping-zzp-tax-regime/spec.md`
- **files**: `lib/Lifecycle/UrencriteriumGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN the discovery step concluded the engine cannot express the cross-period qualifying-hours sum declaratively WHEN the guard is implemented THEN it has exactly one method `currentYtdHours(string $personId, int $year): float` and is referenced from `x-openregister-lifecycle.requires` on the `ZzpDeduction` schema.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031 exception annotation.
- [x] Implement (only if conditional triggered)
- [x] Test (PHPUnit: hours fixture; excluded categories filter correctly; edge cases for start/end of year)

## 8. ADR-005 (security) compliance — per ADR-005 cross-cutting requirement

- **spec_ref**: every spec's authorization scenarios; the RBAC role inventory in `design.md`
- **acceptance_criteria**:
  - GIVEN every T3 register declaration WHEN scanned THEN every schema declares per-role permissions via OR's authorization abstraction (per ADR-022); shillinq does NOT author per-app RBAC code.
  - GIVEN every controller-equivalent surface (OR generic CRUD) WHEN scanned THEN no T3 spec authorises bypass of the RBAC layer (e.g. no `#[NoAdminRequired]` on lifecycle-trigger endpoints that grant cross-tenant access).
  - GIVEN external HTTP (SBR/Digipoort/CBS/DigiKoppeling) WHEN scanned THEN no PKI material or static credentials live in shillinq's `secrets/`; credentials are operator-managed via OpenConnector source config.
- [ ] Implement (verified during code review / security review)
- [ ] Test (security reviewer manual confirmation)

## 9. ADR-009 (testing) compliance — per ADR-009 cross-cutting requirement

- **spec_ref**: every spec
- **acceptance_criteria**:
  - GIVEN every register declaration WHEN tests are written THEN each schema has a PHPUnit unit test covering lifecycle transitions and aggregation correctness.
  - GIVEN every OR `ScheduledWorkflow` declaration WHEN tests are written THEN each has an integration test against an OpenConnector mock or local stub.
  - GIVEN every manifest entry WHEN tests are written THEN each has a Playwright MCP browser smoke test confirming the index/detail page renders correctly via `CnIndexPage`/`CnDetailPage`.
  - GIVEN every visibility predicate WHEN tests are written THEN each is exercised for both true (visible) and false (hidden) administration-type cases.
- [ ] Implement (lands with the implementing cycle, not the spec)
- [ ] Test (CI gate: `composer test` + `npm run test` + Playwright MCP smoke for each new menu entry)

## 10. ADR-010 (documentation) compliance — per ADR-010 cross-cutting requirement

- **spec_ref**: every spec
- **acceptance_criteria**:
  - GIVEN every T3 capability WHEN documented THEN `docs/user-guide/bookkeeping/` gains a per-capability page (bookkeeping-vat-btw-filing, bbv, iv3, bcf, kor, zzp, schatkist, subsidies, retention, project-accounting) per ADR-030 journeydoc convention.
  - GIVEN every new operator surface WHEN documented THEN screenshots are captured to `docs/images/` (e.g. BTW-aangifte index, KOR status widget, IV3 export detail, BCF claim drill-down, projecten overview).
  - GIVEN i18n strings WHEN scanned THEN Dutch (`nl_NL`) and English (`en_US`) translations exist for every operator-facing term introduced in T3.
- [ ] Implement (lands with the implementing cycle, not the spec)
- [ ] Test (docs build clean; screenshots captured via Playwright MCP)

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a competent Dutch bookkeeper persona confirms every regulatory citation is correctly stated
- [ ] Compliance reviewer confirms no parallel audit table, no parallel approval queue, no parallel retention sweep (ADR-022 compliance)
- [ ] Architecture reviewer confirms every state machine is declarative per ADR-031 — zero new `*Service` classes for lifecycle/aggregation/calculation/notification
- [ ] T2 dependency check — T2 specs cited are at minimum `Status: approved` when the implementing cycle starts
- [ ] OpenConnector source-registration dependency tracked — `digipoort-sbr`, `cbs-iv3`, `digikoppeling-bcf` sources registered before first end-to-end test
- [ ] No source code changes outside `openspec/changes/add-shillinq-bookkeeping-operations/`

## Tests (company-wide ADR-009)

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — declared on tasks 2.1–2.10, 3.11, 7.1, 7.2; lands with implementation cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new app-specific endpoints in T3 (OR exposes register CRUD generically); tests cover the register HTTP surface per OR's contract
- [ ] Browser tests (Playwright MCP) for UI changes — declared on tasks 4.1–4.4; lands with implementation cycle
- [ ] All tests pass (`composer test`) — enforced at implementing PR's CI gate
- [ ] Integration tests against OpenConnector mocks for `digipoort-sbr`, `cbs-iv3`, `digikoppeling-bcf` source consumption (tasks 5.1–5.3)

## Documentation (company-wide ADR-010)

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/user-guide/bookkeeping/` — per-capability pages authored during implementation cycle per ADR-030 journeydoc convention (10 pages, one per T3 spec)
- [ ] Screenshot captured and committed to `docs/images/` — authored during implementation cycle (~10 screenshots minimum, one per operator surface)
- [ ] Cross-references added to T1 + T2 docs noting the T3 capabilities that extend them

## i18n (company-wide ADR-005 + the i18n shared specs)

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added during implementation cycle — required term clusters:
  - `Belastingen`, `BTW-aangifte`, `ICP-opgaaf`, `Suppletie`, `Verleggingsregeling`, `Indienen via Digipoort`
  - `BBV`, `Taakveld`, `Programma`, `Paragraaf`, `IV3-rapportage`, `BCF-claim`, `Compensabele BTW`
  - `KOR`, `Omzetdrempel`, `Vrijstelling`, `Opt-in`, `Opt-out`
  - `Urenregistratie`, `Urencriterium`, `Zelfstandigenaftrek`, `Startersaftrek`, `MKB-winstvrijstelling`, `IB-aangifte`
  - `Schatkist-positie`, `Drempelbedrag`, `Deposito`, `Opname`
  - `Subsidie`, `Aanvraag`, `Verleend`, `Vastgesteld`, `Uitbetaald`, `Teruggevorderd`, `Afbetalingsregeling`
  - `Bewaartermijn`, `Vernietigen`, `Archiveren`, `Anonimiseren`, `Archiefwet`, `Selectielijst`
  - `Project`, `Tarievenkaart`, `WIP`, `Onderhanden werk`, `Utilisatie`, `Percentage-of-completion`, `Omzetverantwoording`

## Implementation note — hydra build 2026-06

Implemented in this build (production code, ADR-037 fragment, real OR ObjectService API):

- **register.d fragment** `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json` adding the 12 schemas that were missing from the monolith (`VatReturn`, `IcpStatement`, `VatCorrection`, `VatTariff`, `BbvAccountMapping`, `BbvTaakveld`, `BcfClaim`, `ZzpDeduction`, `IbAangifteExport`, `SchatkistPosition`, `Subsidie`, `RepaymentInstallment`) + additive `Account.isSchatkistAccount`. Sibling schemas (`KorRegime`, `Iv3Export`, `Project`, `RetentionRule`, `UrenRegistratie`, `RateCard`, `WipBalance`) already shipped in the monolith via prior merged changes — not duplicated.
- **Seeds** `btw-tariffs-2026.json`, `bbv-taakvelden-2024.json`, `urencriterium-thresholds.json`, `zzp-deduction-amounts-2026.json`, `asv-model-lifecycle.json`, `schatkist-thresholds.json` (others — `kor-thresholds-2026`, `rgs-bbv`, `rj-270-stages`, `selectielijst-gemeenten-2020`, `rate-card-templates` — already present).
- **Seeders + repair wiring**: `SettingsService::seedBtwTariffs()` / `seedBbvTaakvelden()` via the existing `seedGenericFile()` helper; `InitializeSettings::seedComplianceReferenceData()` calls them idempotently.
- **Two ADR-031 exception guards** with real unit tests: `KorThresholdGuard::currentYtdRevenue`, `UrencriteriumGuard::currentYtdHours`.
- **Manifest**: Belastingen (BTW/ICP/correcties/Urenregistratie/ZZP-aftrek/IB-aangifte), Overheid (BBV-mapping/BCF-claims/Schatkist-positie), and a new Subsidies menu, each with index/detail/dashboard pages.
- **i18n**: nl + en additive term clusters.
- **Tests**: `BookkeepingOperationsFragmentTest` (fragment validity + additive merge), `KorThresholdGuardTest`, `UrencriteriumGuardTest`.

Deferred (need a live instance / cross-app dependency, documented per ADR-009):

- Tasks 5.1–5.5 `ScheduledWorkflow` declarations for SBR/Digipoort, CBS-IV3, DigiKoppeling-BCF, daily schatkist, WIP — depend on the `digipoort-sbr` / `cbs-iv3` / `digikoppeling-bcf` OpenConnector source registrations (`add-openconnector-nl-overheid-sources`, separate change) and an OR ScheduledWorkflow runtime; the IV3 workflow is already registered by a prior change's repair step.
- Task 9 Playwright UI smoke + Task 10 journeydoc + screenshots — require a running dev container.
