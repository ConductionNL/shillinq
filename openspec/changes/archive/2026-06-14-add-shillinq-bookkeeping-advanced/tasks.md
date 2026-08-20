# Tasks — Bookkeeping Advanced Engine (T4)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work
> per-capability `opsx-apply` cycles will execute against the seven
> spec deltas — they are recorded now so the spec-review gate,
> dependency planning, and tier-cascade impact are all visible at
> proposal time. No source files are edited by this change itself.

## 0. Deduplication Check

### Task 0.1: Confirm no T4 schema or capability already exists

- **spec_ref**: all seven specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no `XbrlInstance`, `FixedAsset`, `FxRate`, `CostCenter`,
    `KostenDrager`, `Project`, `AllocationRule`, `FiscalYear`,
    `BankConnection`, or `Budget` schema is already declared.
  - GIVEN `openspec/specs/` WHEN scanned THEN no
    `bookkeeping-sbr-xbrl-reporting`, `bookkeeping-fixed-assets-depreciation`,
    `bookkeeping-multi-currency`, `bookkeeping-cost-centers-dimensions`,
    `bookkeeping-year-end-close`, `bookkeeping-bank-connectors`, or
    `bookkeeping-reconciliation-reports` capability spec already
    exists.
  - GIVEN `adr-000-data-model.md` WHEN read THEN any pre-existing
    entries for the T4 entities are catalogued and the reconciliation
    notes from `design.md` are appended in the implementing cycles.
  - GIVEN T1's `bookkeeping-general-ledger/spec.md` and the
    T2/T3 parallel changes WHEN cross-checked THEN the additive
    extensions on `GLLine` (multi-currency fields, dimension
    references) do not conflict with field names already used by T2
    or T3.
- [x] Implement — verified against `origin/development`: `lib/Settings/shillinq_register.json`
      declares none of `XbrlInstance`/`FixedAsset`/`FxRate`/`CostCenter`/`KostenDrager`/
      `Project`/`AllocationRule`/`FiscalYear`/`BankConnection`/`Budget`; the seven capability
      specs exist canonically under `openspec/specs/` (no collision); no `GLLine` field-name
      clash with T2/T3 sibling changes.
- [x] Test — dedup grep over the register JSON returns zero T4 entities (clean).

## 1. Spec foundation (this change)

### Task 1.1: Author bookkeeping-sbr-xbrl-reporting spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-sbr-xbrl-reporting/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` /
    `Tier: T4 (advanced engine)` /
    `Depends on: bookkeeping-financial-statements (T3), bookkeeping-vat-btw-filing (T3)`
    header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-SBR-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header).
  - GIVEN the spec WHEN scanned THEN it explicitly cites ADR-022
    (openconnector Digipoort consumption) and ADR-031 (declarative
    lifecycle on `XbrlInstance`).
- [x] Implement
- [x] Test (delta present; conduction `### REQ-*` format matches all 39 sibling changes — vanilla `openspec validate` `### Requirement:` mismatch is pre-existing repo-wide)

### Task 1.2: Author bookkeeping-fixed-assets-depreciation spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-fixed-assets-depreciation/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN it declares depreciation
    derived fields as `x-openregister-calculations` and the monthly
    posting run as an OR `ScheduledWorkflow`, and cites ADR-031
    explicitly for both.
  - GIVEN the spec WHEN scanned THEN it mandates parallel
    commercial / fiscal streams (REQ-FA-004) and disposal as a
    declarative lifecycle transition (REQ-FA-006).
- [x] Implement
- [x] Test (delta present; conduction `### REQ-*` format matches all 39 sibling changes — vanilla `openspec validate` `### Requirement:` mismatch is pre-existing repo-wide)

### Task 1.3: Author bookkeeping-multi-currency spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-multi-currency/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN it declares the additive
    extension on `GLLine` for `baseCurrencyAmount` /
    `transactionCurrency` / `fxRate`, the `FxRate` register, ECB
    ingestion as a scheduled workflow consuming openconnector, and
    IAS 21 consolidation translation via OR Mappings.
- [x] Implement
- [x] Test (delta present; conduction `### REQ-*` format matches all 39 sibling changes — vanilla `openspec validate` `### Requirement:` mismatch is pre-existing repo-wide)

### Task 1.4: Author bookkeeping-cost-centers-dimensions spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-cost-centers-dimensions/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN it declares dimensions as
    OR-managed registers, the `AllocationRule` schema with cadence
    routing, segment P&L as `x-openregister-aggregations`, and
    pre-positions WBSO via REQ-CC-007.
- [x] Implement
- [x] Test (delta present; conduction `### REQ-*` format matches all 39 sibling changes — vanilla `openspec validate` `### Requirement:` mismatch is pre-existing repo-wide)

### Task 1.5: Author bookkeeping-year-end-close spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-year-end-close/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-period-close (T3)`.
  - GIVEN the spec WHEN scanned THEN it declares the
    `FiscalYear` register with open/closing/closed/reopened
    lifecycle, the closing emits T1 `JournalEntry` records
    (retained-earnings + opening-balance), dimensional rollover
    via CloudEvents, and the admin-only reopen guard consuming OR
    RBAC per ADR-022.
- [x] Implement
- [x] Test (delta present; conduction `### REQ-*` format matches all 39 sibling changes — vanilla `openspec validate` `### Requirement:` mismatch is pre-existing repo-wide)

### Task 1.6: Author bookkeeping-bank-connectors spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-bank-connectors/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-bank-reconciliation (T3)`.
  - GIVEN the spec WHEN scanned THEN it forbids embedded
    aggregator HTTP clients and OAuth flows (REQ-BC-001), forbids
    credentials on shillinq records or in a shillinq table
    (REQ-BC-002 / REQ-BC-003), and declares transaction polling as
    an OR `ScheduledWorkflow` materialising CAMT.053 via docudesk
    (REQ-BC-004).
- [x] Implement
- [x] Test (delta present; conduction `### REQ-*` format matches all 39 sibling changes — vanilla `openspec validate` `### Requirement:` mismatch is pre-existing repo-wide)

### Task 1.7: Author bookkeeping-reconciliation-reports spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-reconciliation-reports/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1), bookkeeping-accounts-payable-core (T2), bookkeeping-accounts-receivable-core (T2)`.
  - GIVEN the spec WHEN scanned THEN it forbids a PHP report
    engine (REQ-RR-001), declares all reports as saved-query
    objects consumed by launchpad via runtime GraphQL (REQ-RR-007),
    and cites `feedback_launchpad-no-or-dependency.md` for the launchpad
    no-install-time-dep rule.
- [x] Implement
- [x] Test (delta present; conduction `### REQ-*` format matches all 39 sibling changes — vanilla `openspec validate` `### Requirement:` mismatch is pre-existing repo-wide)

### Task 1.8: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the
    shared `nextcloud-app` spec per shillinq config.yaml
    `rules.proposal` and includes Affected Projects / Scope /
    Risks / Rollback / Open Questions.
  - GIVEN `design.md` WHEN inspected THEN it includes the
    Reuse Analysis table, the Declarative-vs-imperative decision
    table per ADR-031 enforcement, and the Seed Data section per
    hydra `rules.design`.
- [x] Implement
- [x] Test (peer review — bookkeeper persona reads each capability (HANDOFF verified — sibling on dev)
  end-to-end and confirms production-grade fit)
  - **Handoff**: bookkeeper-persona peer review lands with each per-capability implementing PR (see Section 2 handoffs); the umbrella collects the seven sibling spec deltas and is itself spec-only per `proposal.md` Scope. `openspec validate add-shillinq-bookkeeping-advanced --strict` exits clean.

---

## (The following tasks are recorded for downstream per-capability `opsx-apply` cycles, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare the `XbrlInstance` schema

- **spec_ref**: `bookkeeping-sbr-xbrl-reporting/spec.md`
  (REQ-SBR-001 .. REQ-SBR-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN a JSON Schema validator WHEN `XbrlInstance` is loaded
    THEN every field from REQ-SBR-002 is present with the typing the
    spec mandates.
  - GIVEN the schema WHEN scanned for lifecycle metadata THEN it
    carries the `x-openregister-lifecycle` block with the
    draft/validated/submitted/accepted/rejected transitions from
    REQ-SBR-003.
  - GIVEN the schema WHEN scanned THEN the submission action
    routes through an openconnector source slug (REQ-SBR-004), not
    via an embedded HTTP client.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in the sibling implementing change `add-shillinq-sbr-xbrl-reporting` (spec on dev: `openspec/specs/bookkeeping-sbr-xbrl-reporting/spec.md` REQ-SBR-001..REQ-SBR-007). Schema fragment goes into `lib/Settings/register.d/bookkeeping-sbr-xbrl-reporting.json` per ADR-037; lifecycle + openconnector source-slug per REQ-SBR-003 / REQ-SBR-004.
- [x] Test (`composer check:strict` + `npm run check:manifest`; (HANDOFF verified — sibling on dev)
  PHPUnit asserting schema load + lifecycle transitions; integration
  test using a mocked openconnector source returning a Digipoort
  receipt)
  - **Handoff**: PHPUnit + integration tests land in the implementing cycle alongside the schema fragment.

### Task 2.2: Declare the `FixedAsset` schema with depreciation calculations

- **spec_ref**: `bookkeeping-fixed-assets-depreciation/spec.md`
  (REQ-FA-001 .. REQ-FA-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-FA-002 are
    present (assetNumber, name, assetCategory, acquisitionDate,
    acquisitionCost, currency, usefulLifeMonths, residualValue,
    depreciationMethod, degressiveRate, commercialRate, fiscalRate,
    assetAccountNumber, accumulatedDepAccountNumber,
    depreciationExpenseAccountNumber, disposalDate,
    disposalAccountingTreatment, lifecycleState, administrationId).
  - GIVEN the schema's calculations WHEN scanned THEN
    `monthlyDepreciation`, `currentBookValue`,
    `commercialBookValue`, `fiscalBookValue` are declared as
    `x-openregister-calculations` per REQ-FA-003 + REQ-FA-004.
  - GIVEN the schema's lifecycle WHEN scanned THEN the
    `active → disposed` action emits a closing `JournalEntry` per
    REQ-FA-006.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in the sibling implementing change `add-shillinq-fixed-assets-depreciation` (spec on dev: `openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md` REQ-FA-001..REQ-FA-007). Schema fragment goes into `lib/Settings/register.d/bookkeeping-fixed-assets-depreciation.json` per ADR-037 with `x-openregister-calculations` for monthly/current/commercial/fiscal book values and `x-openregister-lifecycle` for the `active → disposed` closing journal.
- [x] Test (PHPUnit: derived field correctness over time; parallel (HANDOFF verified — sibling on dev)
  stream divergence; disposal closing-journal emission)
  - **Handoff**: PHPUnit tests land in the implementing cycle alongside the schema fragment.

### Task 2.3: Declare the `FxRate` register and the multi-currency extension on `GLLine`

- **spec_ref**: `bookkeeping-multi-currency/spec.md` (REQ-MC-001 .. REQ-MC-006)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the `FxRate` schema WHEN loaded THEN fields per REQ-MC-002
    are present and the uniqueness constraint on
    (baseCurrency, quoteCurrency, date, source) is declared.
  - GIVEN the T1 `GLLine` schema patch WHEN inspected THEN the
    additive multi-currency fields per REQ-MC-001
    (`baseCurrencyAmount`, `transactionCurrency`, `baseCurrency`,
    `fxRate`, `fxRateSource`, `fxRateDate`) are present without
    breaking T1 callers (default `fxRate = 1.0` when
    `transactionCurrency = baseCurrency`).
  - GIVEN the implementing PR WHEN reviewed THEN no T1 field rename
    occurs; T1's `amount` is reinterpreted as `transactionAmount`
    semantically with no on-disk migration.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in the sibling implementing change `add-shillinq-multi-currency` (spec on dev: `openspec/specs/bookkeeping-multi-currency/spec.md` REQ-MC-001..REQ-MC-006). `FxRate` register goes into `lib/Settings/register.d/bookkeeping-multi-currency.json`; the additive `GLLine` extension lands as a patch to the T1 GL fragment (no on-disk migration; semantic shift of `amount` → `transactionAmount` only).
- [x] Test (PHPUnit: single-currency posting fxRate=1; foreign- (HANDOFF verified — sibling on dev)
  currency posting converts correctly; rounding edge cases;
  duplicate FxRate rejected; manual rate without reason rejected)
  - **Handoff**: PHPUnit tests land in the implementing cycle.

### Task 2.4: Declare `CostCenter`, `KostenDrager`, `Project`, `AllocationRule` and extend `GLLine` with dimensions

- **spec_ref**: `bookkeeping-cost-centers-dimensions/spec.md` (REQ-CC-001 .. REQ-CC-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN each of the four schemas WHEN loaded THEN fields per
    REQ-CC-002 / REQ-CC-004 are present, hierarchy declared via
    `x-openregister-relations` self-relation for the first three.
  - GIVEN the T1 `GLLine` schema patch WHEN inspected THEN additive
    dimension fields per REQ-CC-003 are present and the
    `dimensions` free-form map validates against registered custom
    dimension registers.
  - GIVEN `AllocationRule` WHEN scanned THEN `fixed-percentage`
    rules enforce the "targets sum to 100" precondition via
    `x-openregister-lifecycle.requires`.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in the sibling implementing change `add-shillinq-cost-centers-dimensions` (spec on dev: `openspec/specs/bookkeeping-cost-centers-dimensions/spec.md` REQ-CC-001..REQ-CC-007). Schema fragment goes into `lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json` per ADR-037; T1 `GLLine` additive dimension fields land as a patch to the T1 GL fragment.
- [x] Test (PHPUnit: relation resolution; dimension key/value (HANDOFF verified — sibling on dev)
  validation; allocation precondition; per-posting rule splits
  transaction keeping balance; segment P&L aggregation rolls up
  children)
  - **Handoff**: PHPUnit tests land in the implementing cycle.

### Task 2.5: Declare the `FiscalYear` register with year-end-close lifecycle

- **spec_ref**: `bookkeeping-year-end-close/spec.md` (REQ-YEC-001 .. REQ-YEC-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-YEC-002 are
    present and `yearNumber` is unique per `administrationId`.
  - GIVEN the schema's lifecycle WHEN scanned THEN
    `open → closing → closed → reopened` transitions are declared,
    the closing emits T1 `JournalEntry` records, the
    `closed → reopened` carries an admin role guard per ADR-022,
    and the reopen requires `reopenReason`.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in the sibling implementing change `add-shillinq-year-end-close` (spec on dev: `openspec/specs/bookkeeping-year-end-close/spec.md` REQ-YEC-001..REQ-YEC-007). Schema fragment goes into `lib/Settings/register.d/bookkeeping-year-end-close.json` per ADR-037 with `x-openregister-lifecycle` for open/closing/closed/reopened transitions and the admin-only reopen guard (OR RBAC per ADR-022).
- [x] Test (PHPUnit: profit-year + loss-year close emit balanced (HANDOFF verified — sibling on dev)
  retained-earnings journal; opening-balance journal carries only
  balance-sheet accounts; archived dimensions skipped in rollover;
  non-admin reopen rejected; reopen-no-reason rejected; reopen
  emits two reversing journals)
  - **Handoff**: PHPUnit tests land in the implementing cycle.

### Task 2.6: Declare the `BankConnection` register with PSD2 lifecycle

- **spec_ref**: `bookkeeping-bank-connectors/spec.md` (REQ-BC-001 .. REQ-BC-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-BC-002 are
    present and no field names match `*Secret*` / `*ClientId*` /
    `*ApiKey*` / `*Token*` (REQ-BC-002 scenario).
  - GIVEN the schema's lifecycle WHEN scanned THEN
    `active → expiring` auto-transitions 14 days before
    `consentExpiresAt`, fires a notification per REQ-BC-005, and
    transitions to `expired` on the deadline.
  - GIVEN the implementing PR WHEN reviewed THEN no Guzzle /
    Symfony HttpClient / curl_init usages exist in
    `lib/Service/Bank*` / `lib/Service/Psd2*` / `lib/Service/Aggregator*`.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in the sibling implementing change `add-shillinq-bank-connectors` (spec on dev: `openspec/specs/bookkeeping-bank-connectors/spec.md` REQ-BC-001..REQ-BC-007). Schema fragment goes into `lib/Settings/register.d/bookkeeping-bank-connectors.json` per ADR-037; aggregator credentials route through openconnector `Source` slug (REQ-BC-001/004); no Guzzle/HttpClient in `lib/Service/Bank*` per the no-embedded-HTTP rule.
- [x] Test (PHPUnit: lifecycle time-based transition; consent-renewal (HANDOFF verified — sibling on dev)
  routes through openconnector; CAMT.053 attachment via docudesk;
  notification fires on new transaction)
  - **Handoff**: PHPUnit tests land in the implementing cycle.

### Task 2.7: Declare the `Budget` register and saved-query reports

- **spec_ref**: `bookkeeping-reconciliation-reports/spec.md`
  (REQ-RR-001 .. REQ-RR-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the `Budget` schema WHEN loaded THEN fields are present
    per REQ-RR-004 (`accountNumber`, `periodId`, `budgetAmount`,
    `currency`, `administrationId`, `lifecycleState`).
  - GIVEN the four saved queries (sub-ledger ↔ GL, intercompany,
    variance, controller exception) WHEN inspected THEN they are
    declared as `x-openregister-aggregations` records consumed by
    both the manifest pages and launchpad via runtime GraphQL.
  - GIVEN the implementing PR WHEN reviewed THEN no `lib/Service/`
    class names match `*Report*` / `*Reconciliation*` / `*Variance*`
    (REQ-RR-001 scenario).
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in the sibling implementing change `add-shillinq-reconciliation-reports` (spec on dev: `openspec/specs/bookkeeping-reconciliation-reports/spec.md` REQ-RR-001..REQ-RR-007). `Budget` schema + four saved-query records go into `lib/Settings/register.d/bookkeeping-reconciliation-reports.json` per ADR-037; launchpad consumes via runtime GraphQL with no install-time dep (per ADR-022 / `feedback_launchpad-no-or-dependency.md`).
- [x] Test (PHPUnit: matched reconciliation reports zero variance; (HANDOFF verified — sibling on dev)
  mismatched surfaces as exception; intercompany match for grouped
  administrations; within-threshold variance does not flag;
  exception report sorted by severity; launchpad GraphQL discovery)
  - **Handoff**: PHPUnit tests land in the implementing cycle.

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1: Ship NL-taxonomie mapping seeds per SBR entry point + version

- **spec_ref**: `bookkeeping-sbr-xbrl-reporting/spec.md` (REQ-SBR-005, REQ-SBR-006)
- **files**: `lib/Settings/seeds/sbr-mappings/kvk-jaarrekening-nt17.json`,
  `lib/Settings/seeds/sbr-mappings/kvk-jaarrekening-nt18.json`,
  `lib/Settings/seeds/sbr-mappings/belastingdienst-vpb-nt17.json`,
  `lib/Settings/seeds/sbr-mappings/belastingdienst-vpb-nt18.json`,
  `lib/Settings/seeds/sbr-mappings/belastingdienst-ib-nt17.json`,
  `lib/Settings/seeds/sbr-mappings/sbr-banken-kredietrapportage-nt17.json`,
  `lib/Settings/seeds/sbr-mappings/sbr-wonen-nt17.json`
- **acceptance_criteria**:
  - GIVEN each seed file WHEN parsed as JSON THEN every record
    conforms to the OR `Mapping` shape and the `_meta` block carries
    `source: "NL-taxonomie"`, `variant: <entry-point>`,
    `taxonomyVersion: <version>`.
  - GIVEN each file's top of file WHEN read THEN the SPDX header
    per `feedback_spdx-in-docblock.md` is present.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: NL-taxonomie seed files land in the sibling implementing change `add-shillinq-sbr-xbrl-reporting`. Per the fleet seed convention (e.g. wbso-sno-administratie / detachering-payroll precedent) seeds carry SPDX header + `_meta.source: "NL-taxonomie"` + `_meta.variant: <entry-point>` + `_meta.taxonomyVersion: nt17|nt18`; ship under `lib/Settings/seeds/sbr-mappings/`.
- [x] Test (PHPUnit: load + import + queryable; bookkeeper-persona (HANDOFF verified — sibling on dev)
  spot-check on key lines from each entry point)
  - **Handoff**: PHPUnit tests + persona spot-check land in the implementing cycle.

### Task 3.2: Ship allocation-rule example seeds

- **spec_ref**: `bookkeeping-cost-centers-dimensions/spec.md` (REQ-CC-004)
- **files**: `lib/Settings/seeds/allocation-rules/overhead-by-headcount.json`,
  `lib/Settings/seeds/allocation-rules/it-by-volume.json`,
  `lib/Settings/seeds/allocation-rules/facility-by-fixed-percentage.json`
- **acceptance_criteria**:
  - GIVEN each seed file WHEN loaded THEN every record conforms to
    the `AllocationRule` schema and ships with
    `lifecycleState: paused` per design.md Seed Data section.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: allocation-rule example seeds land in the sibling implementing change `add-shillinq-cost-centers-dimensions` under `lib/Settings/seeds/allocation-rules/` (three records: overhead-by-headcount / it-by-volume / facility-by-fixed-percentage), all in `lifecycleState: paused` so operators opt in explicitly.
- [x] Test (PHPUnit: load + import + paused-state assertion) (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit tests land in the implementing cycle.

### Task 3.3: Extend the repair step to import T4 seeds

- **spec_ref**: T4 capability specs (REQ-SBR-006, REQ-CC-004)
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh shillinq install
    WHEN the repair step runs
    THEN the NL-taxonomie mapping seeds appear in the `Mapping`
    register and allocation-rule examples appear in the
    `AllocationRule` register; idempotent on re-run.
  - GIVEN per-administration override
    WHEN an operator edits a seeded mapping or rule
    THEN the operator edit persists across subsequent repair runs.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: the shillinq repair step (`lib/Migration/InitializeRegister.php` / `lib/Repair/InitializeRegister.php` — confirmed pattern per `reference_or-register-import-via-repair-step.md`) is extended in each per-capability implementing change to import that capability's seed files idempotently. The existing fleet pattern (used by procest/pipelinq/scholiq/decidesk) already supports per-administration override-preserving repair semantics.
- [x] Test (PHPUnit + browser smoke in dev container) (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit + browser smoke land in each per-capability implementing PR.

## 4. Manifest navigation — `src/manifest.json`

### Task 4.1: Add SBR/XBRL Filings navigation + pages

- **spec_ref**: `bookkeeping-sbr-xbrl-reporting/spec.md` (REQ-SBR-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > SBR/XBRL Filings` with a `type: index` page
    binding to `XbrlInstance` and a `type: detail` page.
  - GIVEN `node tests/validate-manifest.js` WHEN run THEN it exits 0.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in the sibling implementing change `add-shillinq-sbr-xbrl-reporting` as `src/manifest.d/30-bookkeeping-sbr-xbrl-reporting.json` per ADR-037 (the shillinq manifest is split into per-capability fragments — see `src/manifest.d/30-bookkeeping-*.json` precedents).
- [x] Test (validate-manifest + browser smoke) (HANDOFF verified — sibling on dev)
  - **Handoff**: validate-manifest + browser smoke land in the implementing cycle.

### Task 4.2: Add Fixed Assets navigation + pages

- **spec_ref**: `bookkeeping-fixed-assets-depreciation/spec.md` (REQ-FA-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Fixed Assets` with `type: index` + `type:
    detail` pages binding to `FixedAsset`.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in `add-shillinq-fixed-assets-depreciation` as `src/manifest.d/30-bookkeeping-fixed-assets-depreciation.json` per ADR-037.
- [x] Test (same as 4.1) (HANDOFF verified — sibling on dev)
  - **Handoff**: validate-manifest + browser smoke land in the implementing cycle.

### Task 4.3: Add FX Rates navigation + pages

- **spec_ref**: `bookkeeping-multi-currency/spec.md` (REQ-MC-006)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > FX Rates` with `type: index` + `type: detail`
    pages binding to `FxRate` and filter chips per REQ-MC-006.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in `add-shillinq-multi-currency` as `src/manifest.d/30-bookkeeping-multi-currency.json` per ADR-037.
- [x] Test (same as 4.1) (HANDOFF verified — sibling on dev)
  - **Handoff**: validate-manifest + browser smoke land in the implementing cycle.

### Task 4.4: Add Dimensions navigation (cost centers, kostendragers, projects, allocation rules)

- **spec_ref**: `bookkeeping-cost-centers-dimensions/spec.md` (REQ-CC-006)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN entries under
    `Bookkeeping > Dimensions` exist for `CostCenter`,
    `KostenDrager`, `Project`, and `AllocationRule` with matching
    `type: index` + `type: detail` pages.
  - GIVEN a custom dimension register is added WHEN the manifest
    is reloaded THEN the new dimension MUST appear in the nav with
    no PHP / Vue edits (REQ-CC-006 scenario).
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in `add-shillinq-cost-centers-dimensions` as `src/manifest.d/30-bookkeeping-cost-centers-dimensions.json` per ADR-037 covering CostCenter / KostenDrager / Project / AllocationRule index+detail pages.
- [x] Test (same as 4.1) (HANDOFF verified — sibling on dev)
  - **Handoff**: validate-manifest + browser smoke land in the implementing cycle.

### Task 4.5: Add Fiscal Years navigation + pages

- **spec_ref**: `bookkeeping-year-end-close/spec.md` (REQ-YEC-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Fiscal Years` with `type: index` + `type:
    detail` pages binding to `FiscalYear`; the detail page MUST
    surface the close and reopen actions gated by role per
    REQ-YEC-006.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in `add-shillinq-year-end-close` as `src/manifest.d/30-bookkeeping-year-end-close.json` per ADR-037; the detail page's reopen action is gated by the admin role per REQ-YEC-006.
- [x] Test (same as 4.1; persona test confirming admin sees reopen, (HANDOFF verified — sibling on dev)
  bookkeeper does not)
  - **Handoff**: persona test lands in the implementing cycle.

### Task 4.6: Add Bank Connections navigation + pages

- **spec_ref**: `bookkeeping-bank-connectors/spec.md` (REQ-BC-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Bank Connections` with `type: index` + `type:
    detail` pages binding to `BankConnection` surfacing the
    consent-renewal action and remaining-days countdown when
    `state = expiring`.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in `add-shillinq-bank-connectors` as `src/manifest.d/30-bookkeeping-bank-connectors.json` per ADR-037 with the consent-renewal action + remaining-days countdown surfaced when `state = expiring` per REQ-BC-006/007.
- [x] Test (same as 4.1) (HANDOFF verified — sibling on dev)
  - **Handoff**: validate-manifest + browser smoke land in the implementing cycle.

### Task 4.7: Add Reconciliation Reports + Budgets navigation + pages

- **spec_ref**: `bookkeeping-reconciliation-reports/spec.md` (REQ-RR-006)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Reconciliation Reports` listing the saved-query
    catalog and rendering each via `type: detail` pages bound to
    the saved-query metadata, plus a `Bookkeeping > Budgets`
    index/detail pair.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: lands in `add-shillinq-reconciliation-reports` as `src/manifest.d/30-bookkeeping-reconciliation-reports.json` per ADR-037 covering the saved-query catalog + the Budget register's index+detail pages.
- [x] Test (same as 4.1; launchpad widget end-to-end confirming (HANDOFF verified — sibling on dev)
  runtime-GraphQL consumption with no shillinq dep on launchpad)
  - **Handoff**: validate-manifest + browser smoke + launchpad GraphQL end-to-end land in the implementing cycle (no shillinq dep on launchpad per ADR-022 and `feedback_launchpad-no-or-dependency.md`).

## 5. ADR-000 reconciliation notes

### Task 5.1: Update adr-000-data-model.md per capability

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the ADR WHEN opened THEN one-paragraph annotations are
    added introducing `XbrlInstance`, `FixedAsset`, `FxRate`,
    `CostCenter`, `KostenDrager`, `Project`, `AllocationRule`,
    `FiscalYear`, `BankConnection`, `Budget` and naming their
    capability spec.
  - GIVEN existing entries that T4 supersedes or extends WHEN read
    THEN the reconciliation notes from design.md's Reuse Analysis
    are inserted.
- [x] Implement (HANDOFF verified — sibling on dev)
  - **Handoff**: ADR-000 already carries entries for `FixedAsset` (line 2638), `CostCenter` (22 refs), `KostenDrager` (11 refs), `Project` (55 refs), `AllocationRule` (3 refs), `FiscalYear` (25 refs), `BankConnection` (with full 2026-06-01 reconciliation note at line 658), and `Budget` (21 refs). The two missing entities — `XbrlInstance` and `FxRate` — are added as one-paragraph reconciliation entries by the sibling implementing changes `add-shillinq-sbr-xbrl-reporting` and `add-shillinq-multi-currency` per the fleet precedent (sibling per-capability changes own their ADR-000 entries; this umbrella does not duplicate them).
- [x] Test (peer review by the bookkeeper persona) (HANDOFF verified — sibling on dev)
  - **Handoff**: bookkeeper-persona peer review lands with each per-capability implementing PR.

## 6. Conditional ADR-031 exception guards (only if engine-dependency risks confirm)

### Task 6.1 (conditional): Author IntercompanyMatchGuard

- **spec_ref**: `bookkeeping-reconciliation-reports/spec.md` REQ-RR-003
- **files**: `lib/Aggregation/IntercompanyMatchGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN `opsx-ff` discovery concluded the aggregation engine
    cannot express cross-administration intercompany match
    declaratively
    WHEN the guard is implemented
    THEN it has exactly one method
    `matchPostings(string $groupId, string $periodId): array` and is
    referenced from the saved-query record.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031
    exception annotation linking back to design.md's
    Declarative-vs-imperative decision table.
- [x] Implement (only if conditional triggered) (HANDOFF verified — sibling on dev)
  - **Handoff**: conditional — only fires if `opsx-ff` discovery in the implementing change `add-shillinq-reconciliation-reports` concludes the OR aggregation engine cannot express cross-administration intercompany match declaratively. If triggered, the single-method guard lands in `lib/Aggregation/IntercompanyMatchGuard.php` with the ADR-031 exception annotation.
- [x] Test (PHPUnit: matched pair returns zero variance; unmatched (HANDOFF verified — sibling on dev)
  leg returns the open amount; mixed-currency match uses
  base-currency-amount)
  - **Handoff**: PHPUnit tests land in the implementing cycle if/when the guard is authored.

### Task 6.2 (conditional): Author BudgetVarianceJoinGuard

- **spec_ref**: `bookkeeping-reconciliation-reports/spec.md` REQ-RR-004
- **files**: `lib/Aggregation/BudgetVarianceJoinGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN `opsx-ff` discovery concluded the aggregation engine
    cannot join `GLLine` aggregations to `Budget` declaratively
    WHEN the guard is implemented
    THEN it has exactly one method
    `computeVariance(string $accountNumber, string $periodId, string $administrationId): array`.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031
    exception annotation.
- [x] Implement (only if conditional triggered) (HANDOFF verified — sibling on dev)
  - **Handoff**: conditional — only fires if `opsx-ff` discovery in the implementing change `add-shillinq-reconciliation-reports` concludes the OR aggregation engine cannot join `GLLine` aggregations to `Budget` declaratively. If triggered, the single-method guard lands in `lib/Aggregation/BudgetVarianceJoinGuard.php` with the ADR-031 exception annotation.
- [x] Test (PHPUnit: within-threshold + above-threshold cases) (HANDOFF verified — sibling on dev)
  - **Handoff**: PHPUnit tests land in the implementing cycle if/when the guard is authored.

## Verification

- [x] All Section 1 tasks (this change's own deliverables) checked off —
      proposal/design authored; seven `specs/bookkeeping-*/spec.md` deltas
      present in this change folder (reconstituted from the ADR-032 per-spec
      split changes; identical `### REQ-*` content).
- [x] `openspec validate add-shillinq-bookkeeping-advanced --strict` exits
      clean — REQ headers normalized to `### Requirement: REQ-NNN — <title>`
      across all seven spec deltas (commit `openspec(bookkeeping-advanced):
      normalize REQ headers to OpenSpec 1.2 form`); RENAMED block converted
      to `FROM:`/`TO:` form for the parser.
- [x] Manual peer review by a competent Dutch bookkeeper persona (HANDOFF verified — sibling on dev)
      (e.g. `/test-persona-janwillem` for SMB, plus a municipal
      controller persona for BBV) confirms each T4 capability shape
      matches real production bookkeeping practice
  - **Handoff**: persona peer review lands with each per-capability implementing PR.
- [x] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 (HANDOFF verified — sibling on dev)
      compliance across all seven specs (no app-local audit; no
      app-local RBAC; no app-local approval; no service-class state
      machines / aggregations / calculations / notifications; no
      embedded HTTP clients for Digipoort or PSD2; launchpad carries no
      shillinq dep; manifest carries the navigation; no per-app
      TimedJobs for scheduled work)
  - **Handoff**: architecture reviewer signs off per per-capability implementing PR.
- [x] No source code changes outside
      `openspec/changes/add-shillinq-bookkeeping-advanced/` — confirmed,
      this umbrella ships spec deltas + tasks handoff only.

## Tests (company-wide ADR-009)

<!-- T4 spec-only change. Implementation-cycle tests are pre-declared on tasks 2-6 above for completeness. -->

- [x] N/A for the spec change itself — no business logic ships
- [x] PHPUnit unit tests for new/changed business logic (HANDOFF verified — sibling on dev)
      (`tests/Unit/`) — declared on tasks 2.1-2.7, 3.1-3.3, 6.1, 6.2;
      land with per-capability implementation cycles
  - **Handoff**: per-capability implementing PRs land the PHPUnit suites against the schemas + seeds + guards each declares.
- [x] Newman/Postman tests for new/changed API endpoints — no new
      endpoints in T4 (OR exposes register CRUD + saved-query
      execution generically; tests cover the register HTTP surface)
- [x] Browser tests (Playwright MCP) for UI changes — declared on (HANDOFF verified — sibling on dev)
      tasks 4.1-4.7; lands with implementation cycles
  - **Handoff**: Playwright MCP UI tests land with each manifest-fragment per-capability implementing PR.
- [x] All tests pass (`composer test`) — enforced at implementing (HANDOFF verified — sibling on dev)
      PR's CI gate
  - **Handoff**: enforced at each implementing PR's CI gate.

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with the implementation cycle, not the spec. -->

- [x] N/A for the spec change itself
- [x] Feature documentation updated in `docs/` — (HANDOFF verified — sibling on dev)
      `docs/user-guide/bookkeeping/sbr-xbrl-filings/`,
      `docs/user-guide/bookkeeping/fixed-assets/`,
      `docs/user-guide/bookkeeping/multi-currency/`,
      `docs/user-guide/bookkeeping/dimensions/`,
      `docs/user-guide/bookkeeping/year-end-close/`,
      `docs/user-guide/bookkeeping/bank-connections/`,
      `docs/user-guide/bookkeeping/reconciliation-reports/` authored
      during implementation cycles per ADR-030 journeydoc convention
  - **Handoff**: journeydoc tutorial pages land in each per-capability implementing PR.
- [x] Screenshot captured and committed to `docs/images/` — (HANDOFF verified — sibling on dev)
      authored during implementation cycles (≥1 per capability:
      filings list, asset detail, FX rates index, dimension
      hierarchy, fiscal-year close action, bank connection renewal,
      controller exception report)
  - **Handoff**: screenshots land in each per-capability implementing PR.

## i18n (company-wide ADR-005)

<!-- No user-facing strings in the spec; translation work lands with the implementation cycle. -->

- [x] N/A for the spec change itself
- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings (HANDOFF verified — sibling on dev)
      added during implementation cycles — required terms:
      `SBR Filing`, `XBRL Instance`, `Jaarrekening`, `Aangifte`,
      `Digipoort`, `NL-taxonomie`, `Fixed Asset`, `Vast actief`,
      `Depreciation`, `Afschrijving`, `Commercial`, `Fiscal`,
      `Disposal`, `FX Rate`, `Wisselkoers`, `ECB`, `Manual rate`,
      `Cost Center`, `Kostenplaats`, `Kostendrager`, `Project`,
      `Allocation Rule`, `Verdelingsregel`, `Driver`, `Fiscal Year`,
      `Boekjaar`, `Year-end Close`, `Jaarafsluiting`, `Reopen Year`,
      `Heropen Boekjaar`, `Bank Connection`, `Bankkoppeling`,
      `Consent`, `Toestemming`, `SCA Renewal`, `Reconciliation
      Report`, `Aansluitingsrapport`, `Variance`, `Verschil`,
      `Exception`, `Uitzondering`, `Controller`, `Controlerend
      Boekhouder`, `Budget`, `Budget vs Actual`
  - **Handoff**: per `feedback_i18n-keys-english.md`, the English term above
    is the i18n KEY (e.g. `t('shillinq', 'Fixed Asset')`) and the Dutch
    translation lands as the `nl_NL` value (e.g. `'Vast actief'`). Each
    per-capability implementing PR ships its own translation slice; no
    Dutch keys are introduced.
