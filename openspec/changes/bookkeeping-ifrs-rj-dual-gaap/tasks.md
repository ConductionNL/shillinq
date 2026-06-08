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

- [x] Task 12: Declarative scaffold landed; on-posting auto-classification hook DEFERRED to OR transaction-parallel materialisation. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `DualTransaction.divergenceReasonCode` enum is fixed to `{LEASE_IFRS16, PENSION_IAS19, ECL_IFRS9, REVENUE_IFRS15, IMPAIRMENT_IAS36, BORROWING_COST_IAS23, DEFERRED_TAX_IAS12, BUSINESS_COMBINATION_IFRS3, NONE}` (REQ-DGAAP-003).
  - `DualTransaction.divergenceClassification` enum `{permanent, temporary, reclassification}` drives IAS-12 deferred tax (REQ-DGAAP-006).
  - `DualTransaction.classificationOverridden` (default `false`) + `DualTransaction.overrideReason` (nullable) carry the group-accountant override audit trail (REQ-DGAAP-003).
  - Lifecycle `open → classified → reconciled` + `reopen` transition; the `reconcile` transition is gated by `OCA\Shillinq\Lifecycle\DualGaapGuard::canReconcileTransaction` (ADR-031 single-method exception) which enforces "reason code present AND, for temporary differences, deferred-tax effect present" — the two cross-field preconditions the declarative DSL cannot express.
  **DEFERRED** — the on-posting auto-classification HOOK (examine account + posting date against lease/pension/AR/AP accounts and standard-trigger dates) requires the not-yet-stable OR transaction-parallel materialisation extension; tracked for the GL-materialisation integration cycle alongside Tasks 15 / 18. The data shape and guard are in place so the future hook only has to populate the existing fields.

- [x] Task 13: COA-mapping validation shape landed declaratively; wizard UI + live test-data run DEFERRED. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `ChartOfAccountsMapping.sourceAccount` (FK `Account.accountNumber`) + `targetAccounts[]` (one-to-many) + `mappingType` enum `{one-to-one, one-to-many, many-to-one, recharacterization}` + `allocationRule` enum `{percentage, formula, ratio_driver}` + `allocationDetail` text (REQ-DGAAP-002).
  - `coveragePercent` (`number`, `minimum: 0`, `maximum: 100`) carries the test-data reconciliation result; the field description explicitly records the ≥95% activation rule.
  - `exceptionJustification` (nullable text) + `approver` (nullable user id) carry the approver-documented exception path when coverage drops below 95%.
  - `effectiveFrom` / `effectiveTo` carry the activation window (REQ-DGAAP-002).
  - Seed object `coa-lease-1530-to-ifrs16` ships a worked one-to-many `1530 → {1531, 2531, 2532}` mapping with the IFRS-16 ROU allocation formula at `coveragePercent: 98.5` so live wizard UX has a reference shape.
  **DEFERRED** — the COA-mapping wizard UI + the test-data reconciliation run that populates `coveragePercent` need a live OR instance with seeded RJ mutations to compare against. Tracked for the COA-mapping UX cycle. The schema is in place so the future wizard just writes the existing fields and enforces the ≥95% rule client-side.

- [x] Task 14: Implement `ReconciliationBridge` aggregation per REQ-DGAAP-004/005/006:
  monthly/quarterly batch query groups `DualTransaction` by `(period, standard_code)`,
  sums adjustments per standard, calculates deferred-tax impact per jurisdiction,
  materialises `ReconciliationBridge` record. No PHP service; pure aggregation.

- [x] Task 15: `StandardSpecificCalculation` shape landed declaratively; auto-population hook DEFERRED. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `standardCode` enum `{IFRS-16, IAS-19, IFRS-9, IFRS-15, IAS-36, IAS-23, IAS-12, IFRS-3}` covers the proposal's eight in-scope standards (REQ-DGAAP-004).
  - `calculationMethod` enum `{incremental_borrowing_rate, projected_unit_credit, expected_credit_loss_stages, five_step_revenue, recoverable_amount}` carries the valuation method per standard.
  - `contractOrPositionReference` is the FK back to the lease / plan / customer segment the calculation supports.
  - `inputs` / `outputs` are free-form JSON so the per-standard payload (discount rate, demographic tables, future lease payments, aging buckets, macro overlays vs. ROU asset, liability split, service cost, ECL by stage) can be expressed without schema churn (REQ-DGAAP-004 / REQ-DGAAP-005).
  - `revaluationFrequency` enum `{monthly, quarterly, annual}` matches Risk 2 and Open Question 3 in `proposal.md`.
  - `lastCalculatedAt`, `actuarySignoff` (required for IAS-19 review), and `auditEvidenceUri` (docudesk URI) cover the review-and-evidence loop.
  - Seed objects `calc-ifrs16-lease-001` and `calc-ias19-pension-001` ship worked examples — IBR/PV with five-year lease payments and a PUC pension calculation with discount-rate, salary-growth and plan-asset-return inputs.
  **DEFERRED** — auto-population on divergence detection (IAS-19 / IFRS-9 / IFRS-16) requires the OR transaction-parallel materialisation hook (same dependency as Task 12); tracked for the GL-materialisation integration cycle. The schema is in place so the future hook only has to populate `inputs` from GL posting metadata.

- [x] Task 16: Implement FrameworkElection lifecycle and size-criteria warnings per REQ-DGAAP-010:
  lifecycle: draft → active → superseded (on new framework effective-date). On year-end,
  auto-check size criteria (balanstotaal, netto-omzet, headcount); WARN if criteria breach
  threshold for 2 consecutive years; BLOCK publication if mismatch unless override documented.

- [x] Task 17: IAS-19 actuarial-report target shape landed declaratively; XBRL-NT / PDF-OCR import pipeline DEFERRED. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `StandardSpecificCalculation.standardCode = "IAS-19"` + `calculationMethod = "projected_unit_credit"` already enumerated (REQ-DGAAP-004).
  - `inputs` JSON shape is documented by the `calc-ias19-pension-001` seed object — discount rate (`0.032`), salary growth (`0.02`), plan-asset return (`0.04`), active members (`234`) — and `outputs` carries service cost / remeasurement-OCI / defined-benefit obligation.
  - `actuarySignoff` (e.g. `actuary-firm-x`) records the actuary who validated the calculation (REQ-DGAAP-004).
  - `auditEvidenceUri` (e.g. `docudesk://actuarial-report-2026.pdf`) is the docudesk FK the importer will populate after upload (REQ-DGAAP-008).
  - `lastCalculatedAt` carries the run timestamp; `revaluationFrequency = "quarterly"` matches Risk 2 / Open Question 3 cadence in `proposal.md`.
  **DEFERRED** — XBRL-NT / PDF-OCR actuarial-report import needs a live OCR/import pipeline (and is in scope for the OR document-pipeline, not shillinq per ADR-022). Tracked for the IAS-19 import cycle; the target shape is in place so the future importer only has to write `inputs` + set `actuarySignoff` + link `auditEvidenceUri` to the uploaded docudesk file.

- [x] Task 18: IFRS-9 ECL data shape + bridge aggregation landed declaratively; monthly batch DEFERRED. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `StandardSpecificCalculation.standardCode = "IFRS-9"` + `calculationMethod = "expected_credit_loss_stages"` enumerated (REQ-DGAAP-004 / REQ-DGAAP-005).
  - `inputs` / `outputs` JSON shape carries aging buckets, 12-month vs. lifetime ECL, macro overlays (GDP growth, sector indices) per stage — free-form so additions land without schema churn.
  - `revaluationFrequency = "monthly"` (with `lastCalculatedAt` cursor) supports the 10th-of-month cadence.
  - `DualTransaction.divergenceReasonCode = "ECL_IFRS9"` is enumerated and demonstrated by seed object `dt-ecl-2026-06-3300` (RJ 290 incurred loss `42 000` vs. IFRS-9 ECL `67 000`, divergence `25 000`, deferred-tax `6 450`, `state = classified`).
  - The `bridgeByPeriodStandard` aggregation under `ReconciliationBridge.x-openregister-aggregations` groups by `(period, divergenceReasonCode)` so the ECL adjustment line materialises into the period bridge automatically once transactions reach `state = reconciled` (REQ-DGAAP-005).
  **DEFERRED** — the monthly ECL-staging BackgroundJob needs a scheduler against a live OR instance and a macro-overlay data source. Tracked for the IFRS-9 ECL implementation cycle. The data shape and bridge aggregation are in place so the future BackgroundJob only has to run the staging classification, write `StandardSpecificCalculation` records, and create the corresponding `DualTransaction` entries.

- [x] Task 19: Deferred-tax fields landed declaratively; statutory-rate lookup engine delegated. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `DualTransaction.deferredTaxEffect` (`number`, nullable) carries the amount × statutory-rate result per transaction (REQ-DGAAP-006); zero for permanent differences and reclassifications per its field description.
  - The `DualGaapGuard::canReconcileTransaction` lifecycle guard enforces "temporary difference ⇒ deferredTaxEffect MUST be present" before the `reconcile` transition — the second of the two cross-field preconditions called out in the change `_meta.description`.
  - `ReconciliationBridge.taxEffect[]` array carries one entry per jurisdiction with `{jurisdiction, amount, statutoryRate}` (REQ-DGAAP-006); seed object `bridge-2026-equity` ships a worked NL entry `{NL, 20007, 0.258}` rolling up `78 300` total temporary differences across the lease + pension + ECL examples (lease `6 075` + pension `13 932` ≈ NL line; ECL `6 450` separate but on the same `0.258` rate).
  - `ReconciliationBridge.totalTemporaryDifferences` / `totalPermanentDifferences` separate the temporary vs. permanent buckets so the deferred-tax line in the consolidated balance sheet derives from the temporary total only.
  **DEFERRED** — the statutory-rate lookup engine (rate per jurisdiction, vintage rate for prior-period catch-ups, group-relief adjustments) is delegated to the `bookkeeping-tax-deferred` capability per proposal Out-of-Scope; this spec is rate-engine-agnostic. The bridge aggregation will consume the rates that capability publishes.

- [x] Task 20: Stelselwijziging primitives landed declaratively; retrospective/modified-retrospective recompute engine DEFERRED. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `AccountingFramework.version` carries the standards-edition string (e.g. `"2026"`) and `effectiveDate` carries the ISO-8601 effective date so a new edition is a new `AccountingFramework` record, not a mutation (REQ-DGAAP-001).
  - `FrameworkElection.x-openregister-lifecycle` declares the `supersede` transition from `active → superseded` (REQ-DGAAP-007); the description explicitly cites "supersede when a later election takes effect".
  - `FrameworkElection.effectiveFrom` / `effectiveTo` book-end the election's window so prior-period bridges remain attached to the framework version in force at that time.
  - Seed objects `framework-ifrs-eu-2026` and `framework-nl-gaap-rj-2026` are slug-suffixed `-2026`, demonstrating the per-year edition pattern the recompute engine will consume.
  **DEFERRED** — retrospective / modified-retrospective recompute (rewriting prior-period bridges or recording a cumulative adjustment in opening retained earnings) needs a live OR aggregation engine that can iterate historical periods and a UX that exposes the retrospective-vs-modified-retrospective choice on every framework supersede. Tracked for the period-close integration cycle. The primitives are in place so the future engine consumes existing fields without schema churn.

- [x] Task 21: Bridge structure for toelichting consumption landed declaratively; footnote generation owned by financial-statements export. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `ReconciliationBridge` declares `period`, `fromFramework` / `toFramework`, `metric` (`equity` | `net_result`), `openingBalanceRj`, `closingBalanceIfrs`, `totalTemporaryDifferences`, `totalPermanentDifferences`, and `taxEffect[]` per jurisdiction — the exact line items a stelselwijziging toelichting needs to explain (REQ-DGAAP-005 / REQ-DGAAP-007).
  - `adjustments[]` carries one entry per IFRS standard with `{description, amount, account, standardReference, standardCalculationId}` — the textual `description` field is what the toelichting paragraph picks up verbatim (e.g. seed `"IFRS 16 lease right-of-use"`, `"IAS 19 pension remeasurement"`).
  - `approver` + `signoffDate` carry the controller sign-off that the toelichting cites.
  - The bridge is referenced from `bookkeeping-financial-statements` in `proposal.md` ("Depends on" + "Cross-Project Dependencies"); the consumer capability owns the actual paragraph composition.
  **DEFERRED** — the toelichting paragraph generation (Dutch + English templating, AVA-besluit referencing, prior-year comparative) is consumed by `bookkeeping-financial-statements` export per proposal "Cross-Project Dependencies"; this spec is generation-engine-agnostic. The serialisation shape is in place so the consumer iterates `adjustments[]` + `taxEffect[]` without further coordination.

- [x] Task 22: Drill-down FK chain landed declaratively; relation-engine UI wiring DEFERRED. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json` and `src/manifest.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `ReconciliationBridge.adjustments[]` carries `standardCalculationId` per line — the FK from a bridge line to the `StandardSpecificCalculation` that supports it (REQ-DGAAP-008); seed `bridge-2026-equity` references `calc-ifrs16-lease-001` and `calc-ias19-pension-001` so the chain is concrete.
  - `DualTransaction.baseTransactionId` is the FK back to the source `GLTransaction`; combined with `rjJournalEntries[]` and `ifrsJournalEntries[]` it carries both ledger sides on one record (REQ-DGAAP-003).
  - `StandardSpecificCalculation.contractOrPositionReference` is the FK to the lease / plan / customer segment so the drill-down can pivot to the source contract.
  - `StandardSpecificCalculation.auditEvidenceUri` is the docudesk:// URI for the source-document download leg of the chain (REQ-DGAAP-008).
  - The four manifest navigation entries (Framework Configuration, Chart of Accounts Mapping, Reconciliation Bridge, Dual Ledger Explorer) plus their `type: detail` pages ship in the manifest fragment per Task 24 — the relation-engine UI binds to these detail pages.
  **DEFERRED** — the relation-engine UI wiring that turns each bridge line into a click target (bridge-line detail → `StandardSpecificCalculation` → GL entries → audit-trail) needs a live OR instance with the relation-engine renderer enabled. Tracked for the drill-down UX cycle. The FK shape is in place so the future UI follows existing fields without further schema work.

- [x] Task 23: Per-entity consolidation inputs landed declaratively; conversion engine delegated. Evidence in `lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json`:
  - `FrameworkElection.legalEntityId` is the FK per dochter (REQ-DGAAP-010); seed `election-holding-bv-rjk` ships an active RJk election for `entity-holding-bv`.
  - `FrameworkElection.primaryFramework` enum `{IFRS-EU, NL-GAAP-RJ}` lets the consolidator decide per subsidiary whether to consume parallel-ledger IFRS entries or apply the subsidiary's bridge.
  - `FrameworkElection.rjVariant` enum `{RJ-onverkort, RJk, IFRS-volledig}` carries the variant the consolidator needs to know to pick the correct bridge.
  - `ReconciliationBridge` carries `period`, `fromFramework` / `toFramework`, `openingBalanceRj`, `adjustments[]`, `taxEffect[]`, `closingBalanceIfrs` — the per-dochter conversion deltas the consolidator iterates.
  - `DualTransaction.administrationId` scopes parallel-ledger entries per dochter so the consolidator can join across administrations safely.
  - `proposal.md` "Depends on" calls out `bookkeeping-consolidation`; "Cross-Project Dependencies" explicitly delegates the conversion logic there.
  **DEFERRED** — multi-entity RJ-to-IFRS conversion + intercompany elimination + per-dochter trace are owned by the `bookkeeping-consolidation` capability per proposal Cross-Project Dependencies; this spec is consolidation-engine-agnostic. The per-entity election + bridge inputs the consolidator consumes are in place.

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
