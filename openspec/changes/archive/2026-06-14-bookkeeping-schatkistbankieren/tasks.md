# Tasks — Schatkistbankieren (Treasury Banking Compliance)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately
> out of scope here. The tasks below describe the work an `opsx-apply` cycle will
> execute against the `bookkeeping-schatkistbankieren` spec — they are recorded now
> so the spec-review gate, dependency planning, and tier-cascade impact are all visible
> at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-schatkistbankieren` capability spec already exists,
  no `TreasuryAccount`/`BankingRule`/`ComplianceReport` schemas are declared, and no
  `lib/Service/Compliance*` / `lib/Service/Treasury*` / `lib/Service/Report*` PHP classes
  are present (per ADR-031 anti-pattern enumeration)
  - **Verified 2026-06-09**: no `TreasuryAccount`/`BankingRule` schemas in any
    `lib/Settings/register.d/*.json`; no `treasury_account`/`banking_rule`/`schatkist_*`
    tables in `appinfo/info.xml`; no `lib/Db/` mappers for these entities. A
    pre-existing `lib/Service/ComplianceService.php` ships for BBV (waterschappen-bbv
    chain) — out of scope for the schatkist work; this spec adds NO new
    `Compliance{Report|Scoring|Aggregation}*.php` classes. The `ComplianceReport`
    name collides with an `obligation-financial-administration` entry in ADR-000;
    Task 15 reconciles that.
- [x] Task 2: Author `specs/bookkeeping-schatkistbankieren/spec.md` with `Status: proposed` /
  `Scope: shillinq` / `Tier: T2 (compliance + operations)` /
  `Depends on: bookkeeping-chart-of-accounts, bookkeeping-audit-trail` header;
  `REQ-SCHATKIST-NNN` requirements using RFC 2119 keywords; `#### Scenario:` blocks with
  GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline; explicitly address Dutch government
  treasury banking regulations (schatkistbankieren) and multi-administration governance
  - Authored as `specs/bookkeeping-schatkistbankieren/spec.md` with 10 REQ-SCHATKIST-NNN
    requirements; published to `openspec/specs/bookkeeping-schatkistbankieren/spec.md`.
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including
  Affected Projects / Scope / Risks (multi-criteria compliance preconditions, regulatory
  reporting format alignment) / Rollback / Open Questions
  - `proposal.md` carries all required sections; rollback + open questions present.
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (treasury accounts as master-list
  governance objects), D2 (banking rules are configurable), D3 (multi-criteria compliance
  precondition), D4 (compliance reports are snapshots), D5 (compliance metrics are aggregations)
  - `design.md` documents D1..D5 with alternatives-considered, declarative-vs-imperative
    table, and migration plan.
- [x] Task 5: Declare the `TreasuryAccount` schema in `lib/Settings/shillinq_register.json`
  with all REQ-SCHATKIST-002 fields (accountNumber, iban, bic, accountName, description,
  complianceClassification, masterListStatus, administrationId, linkedAccountNumber,
  requiresApproval, approvalStatus, lifecycleState)
  - Declared via ADR-037 fragment `lib/Settings/register.d/bookkeeping-schatkistbankieren.json`
    (never edit the monolith). Adds the optional `lastCompliantDate` for the aging
    aggregation (REQ-SCHATKIST-007). Dutch IBAN `pattern` is enforced at schema level.
- [x] Task 6: Declare the `BankingRule` schema in `lib/Settings/shillinq_register.json`
  with all REQ-SCHATKIST-003 fields (ruleNumber, name, description, ruleType,
  evaluationCriteria, severity, isActive, administrationId)
  - Declared in the same fragment per ADR-037. `ruleType` enum covers
    iban-format / segregation / approval-required / transaction-limit / reporting-period.
- [x] Task 7: Declare the `ComplianceReport` schema in `lib/Settings/shillinq_register.json`
  with all REQ-SCHATKIST-006 fields (reportNumber, reportPeriod, generatedAt,
  treasuryAccountId, complianceScore, criteriaResults, status, regulatoryExportFormat,
  regulatoryExportUri, administrationId)
  - Declared in the same fragment per ADR-037; marked `readonly: true` because
    `complianceScore` + `criteriaResults` are `x-openregister-calculations` outputs.
- [x] Task 8: Add `x-openregister-lifecycle` to `TreasuryAccount` declaring every transition
  in REQ-SCHATKIST-004 (`draft → configured → active → monitored → compliant` plus
  `suspended` / `archived`) consuming OR approval-workflow per REQ-SCHATKIST-005
  - Declared on `TreasuryAccount` with seven states and transitions configure, activate,
    monitor, signOffCompliant, suspend, suspendFromMonitored, reactivate,
    archiveFromConfigured, archiveFromActive. The `activate` and `reactivate` transitions
    carry multi-criteria preconditions per Task 9.
- [x] Task 9: Implement the multi-criteria compliance precondition on `TreasuryAccount.activate`
  per REQ-SCHATKIST-005 — declare it inside `x-openregister-lifecycle.requires` (preferred)
  OR if engine cannot express multi-criteria conditional clauses, register
  `OCA\Shillinq\Lifecycle\ComplianceValidator::isCompliant(string $accountId, array $rules): bool`
  (single-method, ~30 LOC, ADR-031 exception annotated)
  - Declared inside `x-openregister-lifecycle.transitions.activate.preconditions` (and
    reactivate). The precondition enumerates iban-format / segregation /
    approval-required rule shapes and pins the ADR-031 exception fallback
    `OCA\Shillinq\Lifecycle\ComplianceValidator::isCompliant` for engines that cannot
    yet evaluate multi-criteria preconditions declaratively. No PHP guard class is
    authored by this build because the declarative shape is the contract per ADR-031
    preferred path; if the engine reports the precondition as unsupported the
    one-method guard can be added without spec change (per design.md D3 + risk 1).
- [x] Task 10: Declare the compliance score calculation as `x-openregister-calculations`
  on `ComplianceReport.complianceScore` per REQ-SCHATKIST-006 (weighted rule-match aggregation);
  declare `ComplianceReport.criteriaResults` as calculated field with per-rule evaluation status
  - Declared as `x-openregister-calculations.complianceScore` (weighted_pass_ratio)
    and `x-openregister-calculations.criteriaResults` (evaluate_each per active rule).
- [x] Task 11: Declare compliance metrics as `x-openregister-aggregations` queries grouping
  `ComplianceReport` by `(administrationId, reportPeriod)` and per `ruleType` per REQ-SCHATKIST-007
  (compute compliance percentage, count pass/fail per rule, identify aging accounts)
  - Three aggregations declared: complianceByAdministrationAndPeriod (REQ-SCHATKIST-007.1),
    complianceByRuleType (REQ-SCHATKIST-007.2), agingByLastCompliantDate
    (REQ-SCHATKIST-007.3 — over TreasuryAccount with 0-30 / 31-60 / 60+ buckets).
- [x] Task 12: Declare audit-trail materialisation on every `TreasuryAccount` lifecycle transition
  per REQ-SCHATKIST-008 and T2 `bookkeeping-audit-trail` spec — events include state, actor,
  compliance results, blocking reasons
  - `x-openregister-audit-trail.enabled = true` declared on all three schemas
    (TreasuryAccount, BankingRule, ComplianceReport). The description on
    TreasuryAccount calls out compliance-rule-evaluation results on active/monitored
    transitions and blocking reasons on suspend transitions per REQ-SCHATKIST-008.
- [x] Task 13: Add 3 manifest navigation entries (`Treasury Accounts`, `Banking Rules`,
  `Compliance Reports`) + their `type: index` / `type: detail` / `type: report` pages to
  `src/manifest.json` per REQ-SCHATKIST-009; `node tests/validate-manifest.js` exits 0
  - Added via ADR-037 fragment `src/manifest.d/bookkeeping-schatkistbankieren.json`:
    Governance menu group with three children (Treasury Accounts, Banking Rules,
    Compliance Reports) routing to their respective index pages plus matching
    `type: detail` pages. Manifest version bumped 1.3.12 → 1.3.13 + info.xml 0.7.4
    → 0.7.5 for the NC `Cache-Control: immutable` cache-bust. `node tests/validate-manifest.js`
    exits 0 (structural lint + consistency PASS).
- [x] Task 14: Define and load three seed `BankingRule` records (rule-iban-format,
  rule-segregation, rule-approval-required) via `lib/Settings/shillinq_register.json`
  `components.objects[]` per REQ-SCHATKIST-010 and `ConfigurationService::importFromApp()`
  idempotency contract
  - Three seed objects declared under `components.objects[]` of the ADR-037 fragment
    with slugs `rule-iban-format`, `rule-segregation`, `rule-approval-required`. They
    load through the existing `SettingsService::loadRegisterConfigData` →
    `ConfigurationService::importFromApp` path; `force:false` re-runs are idempotent
    per the version-gated importer contract. Seeds use `administrationId: "default"`
    as the cross-administration baseline; operators duplicate per administration.
- [x] Task 15: Update `openspec/architecture/adr-000-data-model.md` with `TreasuryAccount`/
  `BankingRule`/`ComplianceReport` entries, reconciling against any existing `BankAccount`,
  `Treasury*`, or `Compliance*` data-model entries
  - Added `BankingRule` entry between `BankStatementLine` and `MatchingRule` and
    `TreasuryAccount` entry before `TreasuryTask` (alphabetical placement). Added a
    Reconciliation note on `TreasuryAccount` clarifying it does NOT overlap with the
    generic `BankAccount` (commercial) nor with `SchatkistbankierenSaldo` (Wet Fido T3
    per-period sweep balance). Extended the pre-existing `ComplianceReport` entry
    (primary spec: obligation-financial-administration) with an
    "Additive fields (bookkeeping-schatkistbankieren, REQ-SCHATKIST-006)" block that
    enumerates the schatkist-specific optional fields and the three aggregations per
    REQ-SCHATKIST-007 — the two spec usages never collide because they scope reports
    by `administrationId` + the natural primary-spec key.
- [x] Task 16: Deduplication check — verify no overlap with existing OR services
  (`ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService`) or
  `@conduction/nextcloud-vue` components; document findings in design review
  - **OR services**: shillinq does NOT declare local `ObjectService` / `RegisterService` /
    `SchemaService` / `ConfigurationService` classes; the schatkist work consumes the
    real OR services via `SettingsService::loadConfiguration` → `ConfigurationService::importFromApp`
    on the version-gated path (real OR API names per memory: `find` / `findAll` / `saveObject` /
    `createObject` / `updateObject` / `deleteObject`). No new shillinq service surface added.
  - **`@conduction/nextcloud-vue`**: the three pages dispatch through the manifest-v2
    `CnPageRenderer` (already wired in `src/main.js`) to the generic `CnIndexPage` and
    `CnDetailPage` components per ADR-024 Tier-4. No bespoke `.vue` files authored —
    rendering is fully driven by `src/manifest.d/bookkeeping-schatkistbankieren.json`.
  - **Existing `ComplianceService` reuse**: shillinq ships a BBV `lib/Service/ComplianceService.php`
    (waterschappen-bbv chain — caches the materialised BBV-aggregation envelope). It does
    NOT overlap with schatkist concerns; schatkist scoring is a declarative
    `x-openregister-calculations` extension, not a service. No new `Compliance*.php` /
    `Treasury*.php` / `Report*.php` classes are introduced by this build.
  - **`ComplianceReport` schema-name collision**: the same schema is already referenced
    by the obligation-financial-administration primary spec (ADR-000); reconciled in
    Task 15 via the "Additive fields" block. Reports remain disjoint by `administrationId`
    + primary spec key.

## Verification

`openspec validate` must exit clean on the change folder. Compliance-officer-persona peer
review (e.g., government stakeholder review) confirms the treasury banking flow matches Dutch
government schatkistbankieren requirements (account intake → compliance verification → monitoring →
regulatory reporting). Architecture reviewer confirms ADR-022 + ADR-031 compliance (no app-local
approval table; no PHP compliance-scoring service; lifecycle declarative or ADR-031-exception-annotated
validator; manifest carries the navigation). No source code changes outside
`openspec/changes/bookkeeping-schatkistbankieren/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`)
is responsible for: PHPUnit unit tests for treasury account lifecycle, multi-criteria compliance
precondition evaluation, compliance scoring, aggregation grouping per period/rule (pre-declared on
Tasks 5–12); Playwright MCP browser tests for the 3 manifest navigation entries (pre-declared on
Task 13); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors
`docs/user-guide/governance/treasury-banking-compliance.md` per ADR-030 journeydoc convention
and commits treasury account + compliance report screenshots to `docs/images/`.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch
(`nl_NL`) and English (`en_US`) translation strings for: `Treasury Accounts`, `Treasury Account`,
`Banking Rules`, `Banking Rule`, `Compliance Reports`, `Compliance Report`, `Master List`,
`Compliant`, `Non-Compliant`, `Segregation`, `IBAN Format`, `Approval Required`, `Compliance Score`,
`Regulatory Export`.
