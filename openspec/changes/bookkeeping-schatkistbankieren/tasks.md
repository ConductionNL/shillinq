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
- [x] Task 2: Author `specs/bookkeeping-schatkistbankieren/spec.md` with `Status: proposed` /
  `Scope: shillinq` / `Tier: T2 (compliance + operations)` /
  `Depends on: bookkeeping-chart-of-accounts, bookkeeping-audit-trail` header;
  `REQ-SCHATKIST-NNN` requirements using RFC 2119 keywords; `#### Scenario:` blocks with
  GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline; explicitly address Dutch government
  treasury banking regulations (schatkistbankieren) and multi-administration governance
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including
  Affected Projects / Scope / Risks (multi-criteria compliance preconditions, regulatory
  reporting format alignment) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (treasury accounts as master-list
  governance objects), D2 (banking rules are configurable), D3 (multi-criteria compliance
  precondition), D4 (compliance reports are snapshots), D5 (compliance metrics are aggregations)
- [x] Task 5: Declare the `TreasuryAccount` schema in `lib/Settings/shillinq_register.json`
  with all REQ-SCHATKIST-002 fields (accountNumber, iban, bic, accountName, description,
  complianceClassification, masterListStatus, administrationId, linkedAccountNumber,
  requiresApproval, approvalStatus, lifecycleState)
- [x] Task 6: Declare the `BankingRule` schema in `lib/Settings/shillinq_register.json`
  with all REQ-SCHATKIST-003 fields (ruleNumber, name, description, ruleType,
  evaluationCriteria, severity, isActive, administrationId)
- [x] Task 7: Declare the `ComplianceReport` schema in `lib/Settings/shillinq_register.json`
  with all REQ-SCHATKIST-006 fields (reportNumber, reportPeriod, generatedAt,
  treasuryAccountId, complianceScore, criteriaResults, status, regulatoryExportFormat,
  regulatoryExportUri, administrationId)
- [x] Task 8: Add `x-openregister-lifecycle` to `TreasuryAccount` declaring every transition
  in REQ-SCHATKIST-004 (`draft → configured → active → monitored → compliant` plus
  `suspended` / `archived`) consuming OR approval-workflow per REQ-SCHATKIST-005
- [x] Task 9: Implement the multi-criteria compliance precondition on `TreasuryAccount.activate`
  per REQ-SCHATKIST-005 — declare it inside `x-openregister-lifecycle.requires` (preferred)
  OR if engine cannot express multi-criteria conditional clauses, register
  `OCA\Shillinq\Lifecycle\ComplianceValidator::isCompliant(string $accountId, array $rules): bool`
  (single-method, ~30 LOC, ADR-031 exception annotated)
- [x] Task 10: Declare the compliance score calculation as `x-openregister-calculations`
  on `ComplianceReport.complianceScore` per REQ-SCHATKIST-006 (weighted rule-match aggregation);
  declare `ComplianceReport.criteriaResults` as calculated field with per-rule evaluation status
- [x] Task 11: Declare compliance metrics as `x-openregister-aggregations` queries grouping
  `ComplianceReport` by `(administrationId, reportPeriod)` and per `ruleType` per REQ-SCHATKIST-007
  (compute compliance percentage, count pass/fail per rule, identify aging accounts)
- [x] Task 12: Declare audit-trail materialisation on every `TreasuryAccount` lifecycle transition
  per REQ-SCHATKIST-008 and T2 `bookkeeping-audit-trail` spec — events include state, actor,
  compliance results, blocking reasons
- [x] Task 13: Add 3 manifest navigation entries (`Treasury Accounts`, `Banking Rules`,
  `Compliance Reports`) + their `type: index` / `type: detail` / `type: report` pages to
  `src/manifest.json` per REQ-SCHATKIST-009; `node tests/validate-manifest.js` exits 0
- [x] Task 14: Define and load three seed `BankingRule` records (rule-iban-format,
  rule-segregation, rule-approval-required) via `lib/Settings/shillinq_register.json`
  `components.objects[]` per REQ-SCHATKIST-010 and `ConfigurationService::importFromApp()`
  idempotency contract
- [x] Task 15: Update `openspec/architecture/adr-000-data-model.md` with `TreasuryAccount`/
  `BankingRule`/`ComplianceReport` entries, reconciling against any existing `BankAccount`,
  `Treasury*`, or `Compliance*` data-model entries
- [x] Task 16: Deduplication check — verify no overlap with existing OR services
  (`ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService`) or
  `@conduction/nextcloud-vue` components; document findings in design review

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
