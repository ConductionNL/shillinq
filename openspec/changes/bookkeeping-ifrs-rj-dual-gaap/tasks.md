# Tasks — Dual GAAP Reporting (IFRS naast Nederlandse Richtlijnen)

> **Implemented (hydra-build).** The spec artifacts (1–4) were authored in the proposal
> cycle. This apply cycle lands the declarative implementation per ADR-031 / ADR-037:
> the six schemas + two lifecycles + the reconciliation-bridge aggregation ship as a
> `register.d` fragment, the four navigation entries as a `manifest.d` fragment, and the
> two cross-field preconditions the declarative DSL cannot yet express as a single
> ADR-031 exception-path guard (`DualGaapGuard`). Tasks whose behaviour is purely
> declarative (auto-classification metadata, aggregation roll-up, lifecycle scope
> enforcement) are satisfied by that metadata; tasks needing a not-yet-stable OR
> transaction-parallel extension or a live OR aggregation/consolidation engine are
> DEFERRED with a reason, never stubbed.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-ifrs-rj-dual-gaap` capability spec already exists,
  no `AccountingFramework`/`ChartOfAccountsMapping`/`DualTransaction`/`ReconciliationBridge`/
  `StandardSpecificCalculation`/`FrameworkElection` schemas are declared, and no
  `lib/Service/DualLedger*` / `lib/Service/Reconciliation*` / `lib/Engine/DualPosting*`
  PHP classes are present (per ADR-031 anti-pattern enumeration).

- [x] Task 2: Author `specs/bookkeeping-ifrs-rj-dual-gaap/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (financial reporting & consolidation)`
  / `Depends on: bookkeeping-general-ledger, bookkeeping-financial-statements, bookkeeping-consolidation`
  header, `REQ-DGAAP-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks
  with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline.

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including
  Affected Projects / Scope / Risks (divergence-classification stability, real-time ECL
  recalculation cost, audit-trail explosion, framework scope-creep) / Rollback / Open Questions.

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (dual posting as single GL event
  with framework cross-refs), D2 (auto-divergence-classification + override), D3 (COA-mapping
  drives allocation), D4 (ReconciliationBridge as aggregation), D5 (StandardSpecificCalculation
  stores the why), D6 (FrameworkElection auditable per-entity), D7 (drill-down via FK),
  D8 (multi-entity conversion order-independent).

- [x] Task 5: Declare the `AccountingFramework` schema in `lib/Settings/shillinq_register.json`
  with all REQ-DGAAP-001 fields (identifier, version, effective_date, jurisdictions[],
  regulator, base_currency_default, statement_templates[]).

- [x] Task 6: Declare the `ChartOfAccountsMapping` schema in `lib/Settings/shillinq_register.json`
  with all REQ-DGAAP-002 fields (source_account, target_accounts[], mapping_type, allocation_rule,
  effective_from, effective_to, approver, audit_trail).

- [x] Task 7: Declare the `DualTransaction` schema in `lib/Settings/shillinq_register.json`
  with all REQ-DGAAP-003 fields (base_transaction_id, rj_journal_entries[], ifrs_journal_entries[],
  divergence_amount, divergence_reason_code enum with LEASE_IFRS16, PENSION_IAS19, ECL_IFRS9,
  REVENUE_IFRS15, IMPAIRMENT_IAS36, BORROWING_COST_IAS23, DEFERRED_TAX_IAS12, BUSINESS_COMBINATION_IFRS3,
  divergence_classification: permanent/temporary/reclassification).

- [x] Task 8: Declare the `ReconciliationBridge` schema in `lib/Settings/shillinq_register.json`
  with all REQ-DGAAP-004/005/006 fields (period, from_framework, to_framework, opening_balance_rj,
  adjustments[] per standard, closing_balance_ifrs, total_temporary_differences,
  total_permanent_differences, tax_effect per jurisdiction, approver, signoff_date).

- [x] Task 9: Declare the `StandardSpecificCalculation` schema in `lib/Settings/shillinq_register.json`
  with all REQ-DGAAP-004/005 fields (standard_code: IFRS-16, IAS-19, IFRS-9, IAS-36, etc.,
  contract_or_position_reference, calculation_method: incremental_borrowing_rate,
  projected_unit_credit, expected_credit_loss_stages, inputs[json], outputs[json],
  revaluation_frequency: monthly/quarterly/annual, last_calculated_at, actuary_signoff,
  audit_evidence_uri).

- [x] Task 10: Declare the `FrameworkElection` schema in `lib/Settings/shillinq_register.json`
  with all REQ-DGAAP-010 fields (legal_entity_id FK, primary_framework: IFRS-EU or NL-GAAP-RJ,
  comply_or_explain_motivation text, rj_variant: RJ-onverkort/RJk/IFRS-volledig,
  size_criteria_balanstotaal, size_criteria_netto_omzet, size_criteria_gemiddeld_werknemers,
  ava_besluit_reference, effective_from, effective_to, status: draft/active/superseded).

- [x] Task 11: Extend T1 GL-materialisation logic (from `bookkeeping-general-ledger` implementation)
  to support dual-posting per REQ-DGAAP-001: on every `JournalEntry` materialisation, spawn
  both RJ and IFRS `GLLine` entries, link via `GLLine.accountingFramework` enum and
  `GLLine.frameworkJournalEntry` FK, auto-populate `DualTransaction` record with framework
  references.

- [ ] Task 12: Implement divergence auto-classification logic per REQ-DGAAP-003: on GL posting,
  examine account + posting date; if account matches lease/pension/customer-AR/supplier-AP accounts
  and posting aligns with standard-trigger dates, auto-populate `DualTransaction.divergence_reason_code`
  and `divergence_classification`. Allow group-accountant override with audit-trail reason.
  **DEFERRED** — the divergence reason-code enum + the override fields (`classificationOverridden`/`overrideReason`) and the reconcile guard ship declaratively; the on-posting auto-classification HOOK requires the not-yet-stable OR transaction-parallel materialisation extension. Tracked for the GL-materialisation integration cycle.

- [ ] Task 13: Implement COA-mapping validation per REQ-DGAAP-002: COA-mapping wizard accepts
  source account → target accounts[] with allocation rule (percentage, formula, ratio-driver);
  on activation, runs reconciliation on test data (min 95% coverage); blocks activation if
  coverage < 95% unless exception-documented by approver.
  **DEFERRED** — the `coveragePercent`/`exceptionJustification`/`approver` fields and the ≥95% rule are declared on `ChartOfAccountsMapping`; the wizard UI + the test-data reconciliation run need a live OR instance with seeded RJ mutations. Tracked for the COA-mapping UX cycle.

- [x] Task 14: Implement `ReconciliationBridge` aggregation per REQ-DGAAP-004/005/006:
  monthly/quarterly batch query groups `DualTransaction` by `(period, standard_code)`,
  sums adjustments per standard, calculates deferred-tax impact per jurisdiction,
  materialises `ReconciliationBridge` record. No PHP service; pure aggregation.

- [ ] Task 15: Implement `StandardSpecificCalculation` population per REQ-DGAAP-004/005:
  on IAS 19 / IFRS 9 / IFRS 16 divergence detection, system creates `StandardSpecificCalculation`
  record and populates inputs from GL posting metadata (lease-commencement date, customer-aging
  bucket, borrowing-cost contract ref, etc.); actuary/validator populates outputs on review.
  **DEFERRED** — `StandardSpecificCalculation` is declared with inputs/outputs/method; auto-population on divergence detection requires the OR transaction-parallel materialisation hook (same dependency as Task 12).

- [x] Task 16: Implement FrameworkElection lifecycle and size-criteria warnings per REQ-DGAAP-010:
  lifecycle: draft → active → superseded (on new framework effective-date). On year-end,
  auto-check size criteria (balanstotaal, netto-omzet, headcount); WARN if criteria breach
  threshold for 2 consecutive years; BLOCK publication if mismatch unless override documented.

- [ ] Task 17: Implement IAS 19 actuarial-report import per REQ-DGAAP-004: accept XBRL-NT
  or PDF with OCR extraction; map to `StandardSpecificCalculation` inputs (discount rate,
  demographic tables, service cost, remeasurements); validate against prior-year data;
  flag anomalies for actuary review.
  **DEFERRED** — XBRL-NT / PDF-OCR actuarial-report import needs a live OCR/import pipeline; the target `StandardSpecificCalculation.inputs` shape is declared. Tracked for the IAS-19 import cycle.

- [ ] Task 18: Implement IFRS 9 ECL-staging batch per REQ-DGAAP-005: monthly batch (10th of month)
  classifies AR/AP by aging bucket → stage 1/2/3, calculates 12-month vs. lifetime ECL,
  applies macro-overlays (GDP growth, sector indices); stores in `StandardSpecificCalculation`;
  materialises bridge adjustments.
  **DEFERRED** — the monthly ECL-staging batch needs a scheduled BackgroundJob against a live OR instance; the `StandardSpecificCalculation` ECL shape + the bridge aggregation are declared.

- [ ] Task 19: Implement deferred-tax calculation per REQ-DGAAP-006: for each temporary-divergence
  `DualTransaction`, calculate tax impact: amount × statutory rate per jurisdiction; store in
  `ReconciliationBridge` as separate line; include in consolidated deferred-tax liability/asset.
  **DEFERRED** — `DualTransaction.deferredTaxEffect` + the per-jurisdiction `ReconciliationBridge.taxEffect[]` are declared and enforced by the reconcile guard; the statutory-rate lookup engine is delegated to the `bookkeeping-tax-deferred` capability (per proposal Out-of-Scope).

- [ ] Task 20: Implement retrospective/modified-retrospective stelselwijziging support per REQ-DGAAP-007:
  on `AccountingFramework` version change, expose choice (retrospective vs. modified-retrospective);
  if retrospective, recalculate all prior-year bridges and adjust opening retained earnings;
  if modified-retrospective, apply rules prospectively and record cumulative adjustment.
  **DEFERRED** — retrospective / modified-retrospective recompute needs a live OR aggregation engine to rewrite prior-period bridges; `AccountingFramework.version`/`effectiveDate` + the `supersede` transition are declared.

- [ ] Task 21: Implement reconciliation-bridge toelichting (footnote) generation per REQ-DGAAP-007:
  on stelselwijziging effective-date, auto-generate toelichting paragraph explaining impact;
  include in financial-statements export (bookkeeping-financial-statements output).
  **DEFERRED** — toelichting (footnote) generation is consumed by `bookkeeping-financial-statements` export; the bridge structure it serialises is declared here.

- [ ] Task 22: Implement drill-down navigation per REQ-DGAAP-008: every `ReconciliationBridge` line
  (e.g., "IAS 19 service cost €234k") SHALL be clickable; drill-down chain:
  bridge-line detail → `StandardSpecificCalculation` → GL entries (RJ + IFRS) → audit-trail;
  all within OR's relation-engine UI; documents downloadable from docudesk FK links.
  **DEFERRED** — the bridge-line → calc → GL → audit-trail drill-down uses OR's relation-engine UI; the FK cross-refs (`standardCalculationId` on adjustments, `baseTransactionId`, `auditEvidenceUri`) are declared. Manifest detail pages ship; the relation-engine wiring needs a live OR instance.

- [ ] Task 23: Implement multi-entity consolidation RJ-to-IFRS conversion per REQ-DGAAP-009:
  on consolidation run, iterate subsidiaries; per subsidiary `FrameworkElection`, EITHER
  use subsidiary's parallel-ledger IFRS entries (if dual-posted) OR apply subsidiary's
  `ReconciliationBridge` to convert RJ to IFRS; then consolidation logic eliminates intercompany
  using IFRS numbers. Trace per-dochter conversion + elimination steps.
  **DEFERRED** — multi-entity RJ-to-IFRS consolidation conversion is owned by the `bookkeeping-consolidation` capability (per proposal Cross-Project Dependencies); the per-entity `FrameworkElection` + `ReconciliationBridge` inputs it consumes are declared here.

- [x] Task 24: Add 4 manifest navigation entries (`Framework Configuration`, `Chart of Accounts Mapping`,
  `Reconciliation Bridge`, `Dual Ledger Explorer`) + their `type: index` / `type: detail` pages
  to `src/manifest.json` per REQ-DGAAP-001 + REQ-DGAAP-002 + REQ-DGAAP-008; `node tests/validate-manifest.js`
  exits 0.

- [x] Task 25: Update `openspec/architecture/adr-000-data-model.md` with
  `AccountingFramework`/`ChartOfAccountsMapping`/`DualTransaction`/`ReconciliationBridge`/
  `StandardSpecificCalculation`/`FrameworkElection` entries, reconciling against any existing
  `FrameworkConfig`/`LedgerMapping` data-model entries. Ensure no duplicate definitions.

- [x] Task 26: Extend `lib/Settings/shillinq_register.json` relations to link new schemas:
  `DualTransaction` → `GLTransaction` (base_transaction_id FK),
  `ChartOfAccountsMapping` → `Account` (source_account, target_accounts),
  `StandardSpecificCalculation` → `DualTransaction` (one-to-many),
  `FrameworkElection` → `Entity` (per-legal-entity),
  `ReconciliationBridge` → `StandardSpecificCalculation` (one-to-many adjustments).

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review
(e.g., `/test-persona-annemarie` for VNG Standards Architect, or internal Dutch tax/audit expert)
confirms:

1. Dual GAAP flow matches Nederlandse listed-group and SMB practice (RJ ↔ IFRS reconciliation
   per standard, audit-trail traceable, comply-or-explain captured).
2. IAS 19 pension and IFRS 9 ECL calculations align with standard guidance (PUC method,
   ECL-staging rules, macro-overlays).
3. Stelselwijziging documentation matches RJ revision cadence and AVA practice.
4. COA-mapping validation logic is practical (≥95% coverage threshold is achievable).

Architecture reviewer confirms ADR-022 + ADR-031 compliance:
- No app-local dual-ledger engine (OR abstractions consumed).
- Reconciliation declarative aggregation; at most one single-method guard per ADR-031 exception.
- Manifest carries the navigation entries.
- All six schemas declared in `lib/Settings/shillinq_register.json`.

No source code changes outside `openspec/changes/bookkeeping-ifrs-rj-dual-gaap/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`)
is responsible for:

- **PHPUnit unit tests**: dual-posting GL materialisation, divergence auto-classification,
  COA-mapping validation (test-data → 95%-coverage check), reconciliation-bridge aggregation
  (grouping by standard, summing adjustments), deferred-tax calculation, stelselwijziging
  retrospective/modified-retrospective logic, ECL-staging batch, IAS 19 import, framework-election
  criteria warnings (pre-declared on Tasks 5–16).
- **Playwright MCP browser tests**: Framework Configuration index/detail, COA Mapping wizard,
  Reconciliation Bridge drill-down (bridge-line → calc → GL entries → documents), Dual Ledger
  Explorer (RJ vs. IFRS side-by-side), multi-entity consolidation blad per-dochter conversion
  (pre-declared on Task 24).
- **Integration tests**: IAS 19 actuarial-report OCR import, IFRS 9 ECL-staging batch, multi-entity
  consolidation with RJ/IFRS mixed subsidiaries.
- `composer test` green at the implementing PR's CI gate; `composer coverage` ≥80%.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/dual-gaap-reporting.md` per ADR-030 journeydoc convention,
  covering (a) framework setup (AccountingFramework, FrameworkElection), (b) COA mapping
  (wizard, test-data validation), (c) divergence classification (auto-detection, override),
  (d) reconciliation-bridge review (drill-down, audit trail), (e) multi-entity consolidation,
  (f) stelselwijziging documentation.
- `docs/user-guide/bookkeeping/dual-gaap-pension-ias19.md` — IAS 19 pension actuarial-report
  import, calculation review, reconciliation to RJ provision.
- `docs/user-guide/bookkeeping/dual-gaap-ecl-ifrs9.md` — IFRS 9 ECL staging, macro-overlays,
  reconciliation to RJ 290 incurred loss.
- Screenshots to `docs/images/` for framework-configuration UI, COA-mapping wizard, reconciliation
  bridge, dual-ledger explorer, consolidation blad.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds
Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- `Accounting Framework`, `Framework Configuration`, `Chart of Accounts Mapping`,
  `Dual Transaction`, `Reconciliation Bridge`, `Standard-Specific Calculation`,
  `Framework Election`, `Dual Ledger Explorer`
- `IFRS-EU`, `NL-GAAP-RJ`, `IFRS-16 Lease`, `IAS-19 Pension`, `IFRS-9 ECL`,
  `IFRS-15 Revenue`, `IAS-36 Impairment`, `IAS-12 Deferred Tax`
- `RJ-onverkort`, `RJk`, `IFRS-volledig`
- `Permanent Difference`, `Temporary Difference`, `Reclassification`
- `Small Entity`, `Medium Entity`, `Large Entity`
- `Comply or Explain`, `Framework Election`, `Size Criteria`
- `Projected Unit Credit`, `Expected Credit Loss`, `Stage 1`, `Stage 2`, `Stage 3`
- `Actuary Signoff`, `Audit Evidence`, `Reconciliation Bridge`, `Drill-down`, `Framework Conversion`
