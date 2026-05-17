# Tasks — Bookkeeping Operations + NL Compliance Core (T3)

> **Spec-only change.** Per `proposal.md` Scope, implementation code
> is deliberately out of scope here. The tasks below describe the
> work an `opsx-apply` cycle will execute against the 10 spec deltas
> — they are pre-declared so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time.
> No source files are edited by this change itself.

## 0. Deduplication Check (per ADR-012 + hydra `tasks.proposal` rule)

### Task 0.1: Confirm no T3 schema or capability already exists

- **spec_ref**: all 10 specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/architecture/adr-000-data-model.md`,
  T1's already-merged registers (Account, GLTransaction, GLLine,
  JournalEntry), T2's in-flight registers (Invoice, Payment,
  BankTransaction, FiscalPeriod, TrialBalance, FinancialStatement)
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no `VatReturn`, `IcpStatement`, `VatCorrection`, `VatTariff`,
    `BbvAccountMapping`, `BbvTaakveld`, `Iv3Export`, `BcfClaim`,
    `KorRegime`, `KorThreshold`, `UrenRegistratie`, `ZzpDeduction`,
    `IbAangifteExport`, `SchatkistPosition`, `Subsidie`,
    `RepaymentInstallment`, `RetentionRule`, `Project`,
    `ProjectAssignment`, `RateCard`, or `WipBalance` schema is already
    declared.
  - GIVEN `openspec/specs/` WHEN scanned THEN no `bookkeeping-vat-*`,
    `bookkeeping-bbv-*`, `bookkeeping-iv3-*`, `bookkeeping-bcf-*`,
    `bookkeeping-kor-*`, `bookkeeping-zzp-*`,
    `bookkeeping-schatkist-*`, `bookkeeping-subsidie-*`,
    `bookkeeping-archiefwet-*`, or `bookkeeping-consultancy-*`
    capability spec already exists.
  - GIVEN T1 + T2 schemas WHEN scanned THEN no overlapping field set
    duplicates a T3 capability (e.g. T1's `Account` does NOT carry
    `bcfCompensable` or `taakveld` — those live in T3's
    `BbvAccountMapping`).
  - GIVEN `openconnector` source registrations WHEN scanned THEN
    `digipoort-sbr`, `cbs-iv3`, `digikoppeling-bcf` source names are
    not yet registered (their registration lives in a separate
    change — `add-openconnector-nl-overheid-sources`).
- [ ] Implement
- [ ] Test (`openspec validate` clean; manual sibling-spec scan)

### Task 0.2: Confirm consumption of existing OR abstractions, not reinvention

- **spec_ref**: every spec (cross-cutting)
- **files**: every T3 spec's `## ADDED Requirements`
- **acceptance_criteria**:
  - GIVEN any T3 spec WHEN scanned for verbs like "implement an
    audit table", "build an approval queue", "write a retention
    sweep job" THEN no such phrasing SHALL appear — every audit,
    approval, retention reference MUST cite ADR-022 + consume the OR
    abstraction.
  - GIVEN any T3 spec WHEN scanned for state-machine descriptions
    THEN every state machine MUST be declared via
    `x-openregister-lifecycle` (ADR-031), NOT via a `*Service.transition*`
    method.
  - GIVEN any T3 spec mentioning external HTTP (SBR, CBS,
    DigiKoppeling) WHEN scanned THEN the submission MUST be expressed
    as an OR `ScheduledWorkflow` consuming an OpenConnector source
    (ADR-019 + ADR-022), never as a PHP `HttpClient` wrapper.
- [ ] Implement
- [ ] Test (reviewer manually confirms during spec review)

## 1. Spec foundation (this change)

### Task 1.1: Author bookkeeping-vat-btw-filing spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-vat-btw-filing/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` /
    `Tier: T3 (operations + NL compliance core)` /
    `Depends on: bookkeeping-general-ledger (T1), bookkeeping-period-close (T2)`
    header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-VBTW-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header per conduction-schema rule).
  - GIVEN the spec WHEN scanned THEN ADR-022 (audit), ADR-024
    (manifest), ADR-031 (declarative lifecycle), and ADR-019 (SBR
    via OpenConnector) are cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.2: Author bookkeeping-bbv-compliance spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-bbv-compliance/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-chart-of-accounts (T1), bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN every `REQ-BBV-NNN:` uses RFC
    2119 + has at least one `#### Scenario:` with GIVEN/WHEN/THEN.
  - GIVEN the spec WHEN scanned THEN the taakveld + RGS↔BBV mapping
    SHALL be described as a register (`BbvAccountMapping`), not an
    enum, per ADR-022.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.3: Author bookkeeping-iv3-reporting spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-iv3-reporting/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-bbv-compliance (T3), bookkeeping-period-close (T2)`.
  - GIVEN the spec WHEN scanned THEN the IV3 XML generation MUST
    be expressed via OR's mapping engine, with the ADR-031 exception
    path documented for the conditional thin PHP renderer.
  - GIVEN the spec WHEN scanned THEN the submission flow MUST be
    expressed as an OR `ScheduledWorkflow` consuming `cbs-iv3`
    source.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.4: Author bookkeeping-bcf-vat-compensation spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-bcf-vat-compensation/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-vat-btw-filing (T3), bookkeeping-bbv-compliance (T3)`.
  - GIVEN the spec WHEN scanned THEN BCF MUST be a separate
    register (`BcfClaim`), not co-mingled with `VatReturn`.
  - GIVEN the spec WHEN scanned THEN compensable-account flagging
    MUST be a field on `BbvAccountMapping` (NOT a parallel "BCF
    accounts" table) per ADR-022.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.5: Author bookkeeping-kor-kleine-ondernemersregeling spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-vat-btw-filing (T3)`.
  - GIVEN the spec WHEN scanned THEN the omzetdrempel threshold MUST
    ship as seed data (`kor-thresholds-2026.json`), not as schema
    enum.
  - GIVEN the spec WHEN scanned THEN auto-regime switch MUST be
    declared via `x-openregister-lifecycle` triggered by
    calculation-crossing — NOT by a daily cron job per ADR-031.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.6: Author bookkeeping-zzp-tax-regime spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-zzp-tax-regime/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN the 1225-urencriterium MUST
    be expressed as `x-openregister-calculations` aggregating
    `UrenRegistratie` (with the ADR-031 exception path documented
    for the conditional thin PHP aggregation guard).
  - GIVEN the spec WHEN scanned THEN deduction amounts MUST ship
    as seed data (`zzp-deduction-amounts-2026.json` +
    `urencriterium-thresholds.json`), per ADR-031.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.7: Author bookkeeping-schatkistbankieren spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-schatkistbankieren/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN schatkist MUST be modelled as
    a flag on T1 `Account` + an aggregated `SchatkistPosition` register
    — NOT a parallel ledger (per ADR-022).
  - GIVEN the spec WHEN scanned THEN the daily aggregation MUST be
    declared as an OR `ScheduledWorkflow`, never as a per-app
    `*Job` class (per ADR-031 §"Background jobs that walk an object
    queue" path 2).
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.8: Author bookkeeping-subsidie-verantwoording spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-subsidie-verantwoording/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN the ASV-model lifecycle MUST
    be declared via `x-openregister-lifecycle` per ADR-031, with
    inline citations to the Awb 4.2 articles.
  - GIVEN the spec WHEN scanned THEN the terugvordering settlement-
    plan MUST be modelled as a `RepaymentInstallment` register linked
    by FK — NOT as a parallel state machine inside `Subsidie` per
    ADR-022.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.9: Author bookkeeping-archiefwet-retention spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-archiefwet-retention/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1), consumes OR's lifecycle retention abstraction`.
  - GIVEN the spec WHEN scanned THEN retention enforcement MUST be
    explicitly declared as OR's responsibility per ADR-022, NOT
    reimplemented in shillinq (the spec's reviewer scenario REQ-ARC-001
    enforces this).
  - GIVEN the spec WHEN scanned THEN every existing shillinq schema
    (T1 + T2 + T3 itself) MUST be mapped to a Selectielijst code in
    REQ-ARC-003's table.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.10: Author bookkeeping-consultancy-project-accounting spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-consultancy-project-accounting/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1), bookkeeping-accounts-receivable-core (T2)`.
  - GIVEN the spec WHEN scanned THEN RJ 270 / IFRS 15 percentage-of-
    completion MUST be expressed as `x-openregister-calculations` per
    ADR-031, NOT a `RevenueRecognitionService`.
  - GIVEN the spec WHEN scanned THEN rate-card multi-rate boundaries
    MUST use snapshot-at-write (`BillableHour.recognisedRate`) per
    RJ 270 §3.2.4 with a tested scenario.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.11: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the shared
    `nextcloud-app` spec per shillinq config.yaml `rules.proposal`,
    includes Affected Projects / Scope / Risks / Rollback / Open
    Questions, AND classifies `kind: config` per ADR-032.
  - GIVEN `design.md` WHEN inspected THEN it includes a Reuse
    Analysis table, a Seed Data section, and a Declarative-vs-
    imperative decision table per hydra `rules.design` + ADR-031
    enforcement.
- [x] Implement
- [ ] Test (peer review — bookkeeper + compliance-officer personas
  read the model end-to-end and confirm regulatory citations)

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare VAT/BTW registers — `VatReturn`, `IcpStatement`, `VatCorrection`, `VatTariff`

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md` (REQ-VBTW-001 .. REQ-VBTW-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the four schemas WHEN loaded THEN every required field per
    REQ-VBTW-002 / 007 / 009 / 003 is present with declared typing.
  - GIVEN the `VatReturn.state` lifecycle WHEN scanned THEN the 6
    transitions from REQ-VBTW-005 are declared via
    `x-openregister-lifecycle`.
  - GIVEN the `draft → submitted` precondition WHEN inspected THEN
    `x-openregister-lifecycle.requires.approval-workflow` per REQ-VBTW-006
    is present.
  - GIVEN the `rubrieken` field WHEN inspected THEN it is declared as
    a derived field via `x-openregister-aggregations` per REQ-VBTW-004.
- [ ] Implement
- [ ] Test (PHPUnit: lifecycle transitions; aggregation correctness
  over seeded GL fixture; approval-gate honoured)

### Task 2.2: Declare BBV registers — `BbvAccountMapping`, `BbvTaakveld`

- **spec_ref**: `bookkeeping-bbv-compliance/spec.md` (REQ-BBV-001 .. REQ-BBV-009)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN `BbvAccountMapping` WHEN loaded THEN fields per REQ-BBV-002
    are present, AND `(administrationId, accountNumber)` is unique
    (declarative constraint).
  - GIVEN the T1 `GLTransaction.post` lifecycle precondition WHEN
    scanned THEN it asserts BBV-mapping existence for municipal
    administrations per REQ-BBV-003.
- [ ] Implement
- [ ] Test (PHPUnit: unmapped account fails posting for municipal
  admin; non-municipal admin bypasses the check; BBV aggregations
  return correct totals)

### Task 2.3: Declare IV3 register — `Iv3Export`

- **spec_ref**: `bookkeeping-iv3-reporting/spec.md` (REQ-IV3-001 .. REQ-IV3-008)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-IV3-002 are
    present.
  - GIVEN the lifecycle WHEN scanned THEN the 6 transitions from
    REQ-IV3-005 are declared.
  - GIVEN the `buckets` field WHEN inspected THEN it is declared as
    a derived field via `x-openregister-aggregations` per REQ-IV3-003.
- [ ] Implement
- [ ] Test (PHPUnit: aggregation correctness; XML validates against
  CBS schema; submission triggers via OpenConnector mock)

### Task 2.4: Declare BCF register — `BcfClaim`

- **spec_ref**: `bookkeeping-bcf-vat-compensation/spec.md` (REQ-BCF-001 .. REQ-BCF-009)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-BCF-002 are
    present.
  - GIVEN the lifecycle WHEN scanned THEN the transitions from
    REQ-BCF-006 are declared with the claim-arithmetic precondition.
  - GIVEN `BbvAccountMapping` WHEN extended THEN it carries
    `compensablePercentage` per REQ-BCF-005.
- [ ] Implement
- [ ] Test (PHPUnit: claim aggregation includes only compensable
  accounts at the correct percentage; submission via OpenConnector mock)

### Task 2.5: Declare KOR registers — `KorRegime`, `KorThreshold`

- **spec_ref**: `bookkeeping-kor-kleine-ondernemersregeling/spec.md` (REQ-KOR-001 .. REQ-KOR-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN `KorRegime` WHEN loaded THEN fields per REQ-KOR-002 are
    present.
  - GIVEN `KorRegime.state` lifecycle WHEN scanned THEN the auto-
    transitions on threshold crossings from REQ-KOR-005 are declared.
  - GIVEN the lifecycle's post-transition action WHEN inspected
    THEN the `threshold-exceeded → opted-out` action creates a
    `JournalEntry` in `state: pending` per REQ-KOR-006 (NOT auto-posted).
  - GIVEN `KorRegime.ytdRevenue` WHEN inspected THEN it is declared
    as `x-openregister-calculations` per REQ-KOR-004 (OR a referenced
    PHP guard with ADR-031 exception annotation).
- [ ] Implement
- [ ] Test (PHPUnit: threshold-crossing transitions; notification
  fires at 80% + 100%; opt-out journal is `pending` not `posted`)

### Task 2.6: Declare ZZP registers — `UrenRegistratie`, `ZzpDeduction`, `IbAangifteExport`

- **spec_ref**: `bookkeeping-zzp-tax-regime/spec.md` (REQ-ZZP-001 .. REQ-ZZP-009)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the three schemas WHEN loaded THEN fields per REQ-ZZP-002
    / 005 / 006 are present.
  - GIVEN `UrenRegistratie.category` WHEN inspected THEN excluded
    categories require `excludedReason` per REQ-ZZP-002.
  - GIVEN `ZzpDeduction.ytdQualifyingHours` WHEN inspected THEN it is
    declared as `x-openregister-calculations` per REQ-ZZP-003 (OR a
    referenced PHP guard with ADR-031 exception annotation).
- [ ] Implement
- [ ] Test (PHPUnit: excluded-hours filtering; deduction
  calculation correctness with starters scenarios)

### Task 2.7: Declare Schatkist register — `SchatkistPosition` + `Account` extension

- **spec_ref**: `bookkeeping-schatkistbankieren/spec.md` (REQ-SBK-001 .. REQ-SBK-011)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN T1 `Account` WHEN extended THEN it carries
    `isSchatkistAccount: boolean` (default `false`) per REQ-SBK-002.
  - GIVEN `SchatkistPosition` WHEN loaded THEN fields per REQ-SBK-003
    are present.
  - GIVEN the daily aggregation WHEN scanned THEN it is declared as
    `x-openregister-aggregations` filtered by `isSchatkistAccount`
    per REQ-SBK-004.
  - GIVEN the daily workflow WHEN scanned THEN it is declared as
    `ScheduledWorkflow`, NOT a `*Job` class, per REQ-SBK-007.
- [ ] Implement
- [ ] Test (PHPUnit: aggregation includes only flagged accounts;
  daily workflow generates one record per administration per day;
  threshold-crossing notification fires)

### Task 2.8: Declare Subsidie registers — `Subsidie`, `RepaymentInstallment`

- **spec_ref**: `bookkeeping-subsidie-verantwoording/spec.md` (REQ-SUB-001 .. REQ-SUB-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the two schemas WHEN loaded THEN fields per REQ-SUB-002 /
    007 are present.
  - GIVEN `Subsidie.state` lifecycle WHEN scanned THEN the 8
    transitions from REQ-SUB-003 are declared with approval-workflow
    requires on `verleen` + `terugvorder`.
  - GIVEN the `vastgesteld → uitbetaald` transition WHEN inspected
    THEN it creates a `JournalEntry` in `pending` state per REQ-SUB-005.
- [ ] Implement
- [ ] Test (PHPUnit: lifecycle transitions; approval-gates honoured;
  repayment-plan instalments created correctly)

### Task 2.9: Declare Retention register — `RetentionRule`

- **spec_ref**: `bookkeeping-archiefwet-retention/spec.md` (REQ-ARC-001 .. REQ-ARC-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN `RetentionRule` WHEN loaded THEN fields per REQ-ARC-002
    are present.
  - GIVEN every existing shillinq schema (T1 + T2 + the 9 other T3
    schemas) WHEN scanned THEN each carries
    `x-openregister-lifecycle.retention.rule` per the REQ-ARC-003
    table.
  - GIVEN the `daysUntilRetention` derived field WHEN inspected on
    every retention-bound schema THEN it is declared via
    `x-openregister-calculations` per REQ-ARC-007.
- [ ] Implement
- [ ] Test (PHPUnit: schema-load validator enforces retention rule
  presence; operator override prevails over seeded default;
  `daysUntilRetention` calculation correctness)

### Task 2.10: Declare Project accounting registers — `Project`, `ProjectAssignment`, `RateCard`, `WipBalance`

- **spec_ref**: `bookkeeping-consultancy-project-accounting/spec.md` (REQ-CPA-001 .. REQ-CPA-014)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the four schemas WHEN loaded THEN fields per REQ-CPA-002 /
    004 / 005 / 008 are present.
  - GIVEN `Project.recognisedRevenue` WHEN inspected THEN it is
    declared via `x-openregister-calculations` per REQ-CPA-007.
  - GIVEN `BillableHour` (UrenRegistratie extension) WHEN inspected
    THEN it carries `recognisedRate` snapshotted at write time per
    REQ-CPA-009.
  - GIVEN the WIP snapshot workflow WHEN scanned THEN it is declared
    as `ScheduledWorkflow` triggered by period close per REQ-CPA-008.
- [ ] Implement
- [ ] Test (PHPUnit: percentage-of-completion calculation correctness;
  rate-card snapshot honours work date not invoice date; WIP snapshot
  fires on period close)

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1: Ship BTW tariff seed

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md` (REQ-VBTW-003)
- **files**: `lib/Settings/seeds/btw-tariffs-2026.json`
- **acceptance_criteria**:
  - GIVEN the seed file WHEN loaded THEN it contains at minimum the
    canonical tariffs (21%, 9%, 0%, vrij, verlegd) with SPDX header +
    `_meta.source: "Wet OB 1968"` + version field.
- [ ] Implement
- [ ] Test (PHPUnit: parse + import + every record validates)

### Task 3.2: Ship BBV taakveld catalogue seed

- **spec_ref**: `bookkeeping-bbv-compliance/spec.md` (REQ-BBV-005)
- **files**: `lib/Settings/seeds/bbv-taakvelden-2024.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the full BBV
    bijlage IV taakveld catalogue (~50 codes), `_meta.bbvVersion:
    "2024"`, and SPDX header.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.3: Ship default RGS↔BBV mapping seed

- **spec_ref**: `bookkeeping-bbv-compliance/spec.md` (REQ-BBV-006)
- **files**: `lib/Settings/seeds/rgs-to-bbv-mapping.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN each record validates against
    `BbvAccountMapping` AND every record carries
    `_meta.source: "seeded"`.
- [ ] Implement
- [ ] Test (PHPUnit: load + per-admin idempotent seed; operator
  override preserved on re-run)

### Task 3.4: Ship Selectielijst Gemeenten retention seed

- **spec_ref**: `bookkeeping-archiefwet-retention/spec.md` (REQ-ARC-002)
- **files**: `lib/Settings/seeds/selectielijst-gemeenten-2020.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN every record validates against
    `RetentionRule` AND covers at minimum the categories enumerated in
    REQ-ARC-002.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.5: Ship KOR threshold seed

- **spec_ref**: `bookkeeping-kor-kleine-ondernemersregeling/spec.md` (REQ-KOR-003)
- **files**: `lib/Settings/seeds/kor-thresholds-2026.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains a record with
    `thresholdAmount: 20000` and `warningPercentage: 80`.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.6: Ship urencriterium and ZZP-deduction seed

- **spec_ref**: `bookkeeping-zzp-tax-regime/spec.md` (REQ-ZZP-007)
- **files**: `lib/Settings/seeds/urencriterium-thresholds.json`,
  `lib/Settings/seeds/zzp-deduction-amounts-2026.json`
- **acceptance_criteria**:
  - GIVEN the two files WHEN loaded THEN they contain the values
    enumerated in REQ-ZZP-007 with SPDX headers + citations.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.7: Ship ASV-model subsidie lifecycle seed

- **spec_ref**: `bookkeeping-subsidie-verantwoording/spec.md` (REQ-SUB-006)
- **files**: `lib/Settings/seeds/asv-model-lifecycle.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the 6 canonical
    lifecycle states with their Awb citations.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.8: Ship RJ-270 stage definitions seed

- **spec_ref**: `bookkeeping-consultancy-project-accounting/spec.md` (REQ-CPA-002)
- **files**: `lib/Settings/seeds/rj-270-stages.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the 4 canonical
    stages (`initiation`, `execution`, `closeout`, `complete`).
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.9: Ship schatkist drempelbedrag seed

- **spec_ref**: `bookkeeping-schatkistbankieren/spec.md` (REQ-SBK-005)
- **files**: `lib/Settings/seeds/schatkist-thresholds.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the 4 administration-
    type thresholds enumerated in REQ-SBK-005.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.10: Ship rate-card templates seed

- **spec_ref**: `bookkeeping-consultancy-project-accounting/spec.md` (REQ-CPA-005)
- **files**: `lib/Settings/seeds/rate-card-templates.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the 4 default
    levels (junior, medior, senior, partner).
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.11: Extend the repair step to import every T3 seed file

- **spec_ref**: every spec's seed-data REQ
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh shillinq install WHEN the repair step runs THEN
    each seed file's records appear in its target register,
    idempotent on re-run.
  - GIVEN per-administration override WHEN a record is edited THEN
    the operator edit persists across subsequent repair runs (no
    overwrite of operator-authored records).
  - GIVEN a `gemeente` administration WHEN the repair step runs THEN
    the BBV-mapping seed is applied for THAT administration;
    non-municipal admins skip the BBV seed.
- [ ] Implement
- [ ] Test (PHPUnit + browser smoke in dev container)

## 4. Manifest navigation — `src/manifest.json`

### Task 4.1: Add Belastingen menu (BTW, ICP, BTW-correcties)

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md` (REQ-VBTW-011)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares `Belastingen >
    BTW-aangiften`, `Belastingen > ICP-opgaaf`, `Belastingen >
    BTW-correcties` with `type: index` + `type: detail` pages.
  - GIVEN `node tests/validate-manifest.js` WHEN run THEN it exits 0.
- [ ] Implement
- [ ] Test (validate-manifest + browser smoke for each page)

### Task 4.2: Add Overheid menu (BBV, IV3, BCF, Schatkist)

- **spec_ref**: REQ-BBV-007 + REQ-IV3-007 + REQ-BCF-008 + REQ-SBK-009
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Overheid > BBV-mapping`, `Overheid > IV3-rapportages`,
    `Overheid > BCF-claims`, `Overheid > Schatkist-positie` with
    the appropriate `type` pages.
  - GIVEN the visibility predicate WHEN evaluated THEN these entries
    show only for `gemeente`/`provincie`/`waterschap` administrations.
- [ ] Implement
- [ ] Test (same as 4.1 + visibility predicate test)

### Task 4.3: Add KOR + ZZP menus

- **spec_ref**: REQ-KOR-009 + REQ-ZZP-008
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Belastingen > KOR-status`, `Belastingen > Urenregistratie`,
    `Belastingen > ZZP-aftrek`, `Belastingen > IB-aangifte` with
    appropriate `type` pages.
  - GIVEN the visibility predicate WHEN evaluated THEN these
    entries show only for `mkb`/`zzp` administrations.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.4: Add Subsidies + Projecten + Bewaartermijnen menus

- **spec_ref**: REQ-SUB-008 + REQ-CPA-012 + REQ-ARC-009
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Subsidies` (with sub-pages), `Projecten > Overzicht`,
    `Projecten > Tarieven`, `Projecten > Utilisatie`,
    `Administratie > Bewaartermijnen` with appropriate `type` pages.
- [ ] Implement
- [ ] Test (same as 4.1)

## 5. ScheduledWorkflow declarations

### Task 5.1: Declare quarterly SBR/Digipoort BTW workflow

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md` (REQ-VBTW-010)
- **files**: `lib/Settings/shillinq_register.json` (workflow block) or
  the OR scheduled-workflow seed
- **acceptance_criteria**:
  - GIVEN the workflow declaration WHEN scanned THEN cron defaults
    to monthly/quarterly aligned with `VatReturn.periodType`; the
    source name is `digipoort-sbr`.
- [ ] Implement
- [ ] Test (PHPUnit + integration test against an OpenConnector mock)

### Task 5.2: Declare quarterly IV3 workflow

- **spec_ref**: `bookkeeping-iv3-reporting/spec.md` (REQ-IV3-006)
- **files**: same
- **acceptance_criteria**:
  - GIVEN the workflow WHEN scanned THEN cron is `0 0 1 */3 *`
    (quarter starts) and source is `cbs-iv3`.
- [ ] Implement
- [ ] Test (same as 5.1)

### Task 5.3: Declare quarterly BCF workflow

- **spec_ref**: `bookkeeping-bcf-vat-compensation/spec.md` (REQ-BCF-007)
- **files**: same
- **acceptance_criteria**:
  - GIVEN the workflow WHEN scanned THEN cron is quarterly and source
    is `digikoppeling-bcf`.
- [ ] Implement
- [ ] Test (same as 5.1)

### Task 5.4: Declare daily schatkist-position workflow

- **spec_ref**: `bookkeeping-schatkistbankieren/spec.md` (REQ-SBK-007)
- **files**: same
- **acceptance_criteria**:
  - GIVEN the workflow WHEN scanned THEN cron is once-per-business-
    day (operator-configurable) and the aggregation declared per
    REQ-SBK-004 is invoked.
- [ ] Implement
- [ ] Test (PHPUnit + cron-trigger integration test)

### Task 5.5: Declare period-end WIP snapshot workflow

- **spec_ref**: `bookkeeping-consultancy-project-accounting/spec.md` (REQ-CPA-008)
- **files**: same
- **acceptance_criteria**:
  - GIVEN the workflow WHEN scanned THEN it is triggered by T2 period
    close events and generates a `WipBalance` record per active
    project.
- [ ] Implement
- [ ] Test (PHPUnit + period-close integration test)

## 6. ADR-000 reconciliation note

### Task 6.1: Update adr-000-data-model.md

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the ADR WHEN opened THEN the new 14+ T3 entities (VatReturn,
    IcpStatement, VatCorrection, VatTariff, BbvAccountMapping,
    BbvTaakveld, Iv3Export, BcfClaim, KorRegime, KorThreshold,
    UrenRegistratie, ZzpDeduction, IbAangifteExport, SchatkistPosition,
    Subsidie, RepaymentInstallment, RetentionRule, Project,
    ProjectAssignment, RateCard, WipBalance) are recorded with their
    `Primary spec:` references pointing at the new T3 specs.
  - GIVEN any pre-existing ADR-000 entries overlapping the new
    schemas WHEN read THEN reconciliation notes are appended (similar
    to T1's GLLine ↔ GeneralLedgerEntry note).
- [ ] Implement
- [ ] Test (peer review by the bookkeeper + compliance-officer
  personas)

## 7. Conditional thin PHP guards (only if Risk 3 confirms)

### Task 7.1 (conditional): Author KorThresholdGuard

- **spec_ref**: `bookkeeping-kor-kleine-ondernemersregeling/spec.md` (REQ-KOR-004)
- **files**: `lib/Lifecycle/KorThresholdGuard.php` (new, single
  method)
- **acceptance_criteria**:
  - GIVEN the discovery step concluded the engine cannot express
    cross-period revenue aggregation declaratively
    WHEN the guard is implemented
    THEN it has exactly one method `currentYtdRevenue(string
    $adminId, int $year): float` and is referenced from
    `x-openregister-lifecycle.requires` on the `KorRegime` lifecycle.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031
    exception annotation linking back to design.md's Declarative-vs-
    imperative decision table.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: invoice fixture sums correctly; edge cases for
  cancelled invoices, credit notes, partial periods)

### Task 7.2 (conditional): Author UrencriteriumGuard

- **spec_ref**: `bookkeeping-zzp-tax-regime/spec.md` (REQ-ZZP-003)
- **files**: `lib/Lifecycle/UrencriteriumGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN the discovery step concluded the engine cannot express the
    cross-period qualifying-hours sum declaratively
    WHEN the guard is implemented
    THEN it has exactly one method `currentYtdHours(string
    $personId, int $year): float` and is referenced from
    `x-openregister-lifecycle.requires` on the `ZzpDeduction`
    schema.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031
    exception annotation.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: hours fixture; excluded categories filter
  correctly; edge cases for start/end of year)

## 8. ADR-005 (security) compliance — per ADR-005 cross-cutting requirement

- **spec_ref**: every spec's authorization scenarios; the RBAC role
  inventory in `design.md`
- **acceptance_criteria**:
  - GIVEN every T3 register declaration WHEN scanned THEN every
    schema declares per-role permissions via OR's authorization
    abstraction (per ADR-022); shillinq does NOT author per-app
    RBAC code.
  - GIVEN every controller-equivalent surface (OR generic CRUD)
    WHEN scanned THEN no T3 spec authorises bypass of the RBAC layer
    (e.g. no `#[NoAdminRequired]` on lifecycle-trigger endpoints
    that grant cross-tenant access).
  - GIVEN external HTTP (SBR/Digipoort/CBS/DigiKoppeling) WHEN scanned
    THEN no PKI material or static credentials live in shillinq's
    `secrets/`; credentials are operator-managed via OpenConnector
    source config.
- [ ] Implement (verified during code review / security review)
- [ ] Test (security reviewer manual confirmation)

## 9. ADR-009 (testing) compliance — per ADR-009 cross-cutting requirement

- **spec_ref**: every spec
- **acceptance_criteria**:
  - GIVEN every register declaration WHEN tests are written THEN
    each schema has a PHPUnit unit test covering lifecycle
    transitions and aggregation correctness.
  - GIVEN every OR `ScheduledWorkflow` declaration WHEN tests are
    written THEN each has an integration test against an
    OpenConnector mock or local stub.
  - GIVEN every manifest entry WHEN tests are written THEN each has
    a Playwright MCP browser smoke test confirming the index/detail
    page renders correctly via `CnIndexPage`/`CnDetailPage`.
  - GIVEN every visibility predicate WHEN tests are written THEN
    each is exercised for both true (visible) and false (hidden)
    administration-type cases.
- [ ] Implement (lands with the implementing cycle, not the spec)
- [ ] Test (CI gate: `composer test` + `npm run test` + Playwright
  MCP smoke for each new menu entry)

## 10. ADR-010 (documentation) compliance — per ADR-010 cross-cutting requirement

- **spec_ref**: every spec
- **acceptance_criteria**:
  - GIVEN every T3 capability WHEN documented THEN
    `docs/user-guide/bookkeeping/` gains a per-capability page
    (bookkeeping-vat-btw-filing, bbv, iv3, bcf, kor, zzp, schatkist,
    subsidies, retention, project-accounting) per ADR-030 journeydoc
    convention.
  - GIVEN every new operator surface WHEN documented THEN screenshots
    are captured to `docs/images/` (e.g. BTW-aangifte index, KOR
    status widget, IV3 export detail, BCF claim drill-down, projecten
    overview).
  - GIVEN i18n strings WHEN scanned THEN Dutch (`nl_NL`) and English
    (`en_US`) translations exist for every operator-facing term
    introduced in T3 (BTW-aangifte, Belastingen, KOR-drempel,
    Urenregistratie, Schatkist-positie, Subsidieverlening,
    Terugvordering, Bewaartermijn, WIP, Utilisatie, etc.).
- [ ] Implement (lands with the implementing cycle, not the spec)
- [ ] Test (docs build clean; screenshots captured via Playwright MCP)

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a competent Dutch bookkeeper persona
      (`/test-persona-janwillem` for SMB, `/test-persona-priya` for
      ZZP, `/test-persona-noor` for municipal CISO/admin) confirms
      every regulatory citation is correctly stated
- [ ] Compliance reviewer confirms no parallel audit table, no
      parallel approval queue, no parallel retention sweep (ADR-022
      compliance)
- [ ] Architecture reviewer confirms every state machine is
      declarative per ADR-031 — zero new `*Service` classes for
      lifecycle/aggregation/calculation/notification
- [ ] T2 dependency check — T2 specs cited (`bookkeeping-trial-balance`,
      `bookkeeping-period-close`, `bookkeeping-accounts-payable`,
      `bookkeeping-accounts-receivable-core`,
      `bookkeeping-financial-statements`) are at minimum
      `Status: approved` when the implementing cycle starts
- [ ] OpenConnector source-registration dependency tracked —
      `digipoort-sbr`, `cbs-iv3`, `digikoppeling-bcf` sources
      registered before first end-to-end test of the implementing
      cycle
- [ ] No source code changes outside
      `openspec/changes/add-shillinq-bookkeeping-operations/`

## Tests (company-wide ADR-009)

<!-- T3 spec-only change. Implementation-cycle tests are pre-declared on tasks 2–7 above for completeness. -->

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic
      (`tests/Unit/`) — declared on tasks 2.1–2.10, 3.11, 7.1, 7.2;
      lands with implementation cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new
      app-specific endpoints in T3 (OR exposes register CRUD
      generically); tests cover the register HTTP surface per
      OR's contract
- [ ] Browser tests (Playwright MCP) for UI changes — declared on
      tasks 4.1–4.4; lands with implementation cycle
- [ ] All tests pass (`composer test`) — enforced at implementing PR's
      CI gate
- [ ] Integration tests against OpenConnector mocks for `digipoort-sbr`,
      `cbs-iv3`, `digikoppeling-bcf` source consumption (tasks 5.1–5.3)

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with the implementation cycle, not the spec. -->

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/user-guide/bookkeeping/`
      — per-capability pages authored during implementation cycle per
      ADR-030 journeydoc convention (10 pages, one per T3 spec)
- [ ] Screenshot captured and committed to `docs/images/` — authored
      during implementation cycle (~10 screenshots minimum, one per
      operator surface)
- [ ] Cross-references added to T1 + T2 docs noting the T3 capabilities
      that extend them

## i18n (company-wide ADR-005 + the i18n shared specs)

<!-- No user-facing strings in the spec; translation work lands with the implementation cycle. -->

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added
      during implementation cycle — required term clusters:
  - `Belastingen`, `BTW-aangifte`, `ICP-opgaaf`, `Suppletie`,
    `Verleggingsregeling`, `Indienen via Digipoort`
  - `BBV`, `Taakveld`, `Programma`, `Paragraaf`,
    `IV3-rapportage`, `BCF-claim`, `Compensabele BTW`
  - `KOR`, `Omzetdrempel`, `Vrijstelling`, `Opt-in`, `Opt-out`
  - `Urenregistratie`, `Urencriterium`, `Zelfstandigenaftrek`,
    `Startersaftrek`, `MKB-winstvrijstelling`, `IB-aangifte`
  - `Schatkist-positie`, `Drempelbedrag`, `Deposito`, `Opname`
  - `Subsidie`, `Aanvraag`, `Verleend`, `Vastgesteld`,
    `Uitbetaald`, `Teruggevorderd`, `Afbetalingsregeling`
  - `Bewaartermijn`, `Vernietigen`, `Archiveren`, `Anonimiseren`,
    `Archiefwet`, `Selectielijst`
  - `Project`, `Tarievenkaart`, `WIP`, `Onderhanden werk`,
    `Utilisatie`, `Percentage-of-completion`,
    `Omzetverantwoording`
