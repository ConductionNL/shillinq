# Tasks — Bank Reconciliation

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-bank-reconciliation` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are all
> visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-bank-reconciliation` capability spec already exists, no `BankStatement` / `BankStatementLine` / `MatchingRule` / `ReconciliationMatch` schemas are declared, and no `lib/Service/Reconcil*` / `lib/Service/Match*` PHP classes are present (per ADR-031 anti-pattern enumeration)
      - Verified 2026-06-08: the parent `add-shillinq-bookkeeping-compliance` change shipped `BankStatement` overlay + `BankStatementLine` + `MatchingRule` + `ReconciliationMatch` via `lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json` (ADR-037 fragment, the monolith is NOT edited). No `lib/Db/` Mapper classes for any bank-rec entity exist. No `lib/Service/Reconcil*` or `lib/Service/Match*` classes exist (pre-existing `BankfeedMatcher` is `zzp-cashflow-13wk` ADR-031 exception, "Bankfeed" not "Match"). The single ADR-031 exception for this spec, `lib/Lifecycle/StatementParser.php`, is the documented sunset path per REQ-BR-003.
- [x] Task 2: Author `specs/bookkeeping-bank-reconciliation/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger, bookkeeping-document-attachment-integration` header, `REQ-BR-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-031 (declarative rule evaluation over service) + ADR-022 (consume audit + docudesk) inline
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (CAMT format drift, parsing-extension availability, suspense designation, duplicate import) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (two registers + matching-rule), D2 (operator confirms), D3 (suspense designation), D4 (lifecycle), D5 (parser shape-neutral), D6 (duplicate constraint), D7 (docudesk archival)
- [x] Task 5: Declare the `BankStatement` schema in `lib/Settings/shillinq_register.json` with all REQ-BR-002 fields (statementId, importedAt, importedBy, format, fileChecksum, periodStart, periodEnd, openingBalance, closingBalance, currency, sourceDocumentUri, state, administrationId)
      - Reconciliation note: per ADR-037 the monolith is NOT edited; the BankStatement overlay ships in `lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json`. This task added the missing `importedAt`, `importedBy`, `transactionCount` fields + `x-openregister-audit-trail` flag + `x-openregister-uniqueness` block (REQ-BR-009).
- [x] Task 6: Declare the `BankStatementLine` schema in `lib/Settings/shillinq_register.json` with all REQ-BR-004 fields (statementId, lineNumber, valueDate, amount, currency, counterpartyName, counterpartyIban, reference, narrative, rawPayload, matchState)
      - Reconciliation note: shipped via the same ADR-037 fragment. This task added `lineNumber`, `narrative`, `rawPayload` + `x-openregister-audit-trail` flag. `matchState` is the existing `status` field; `reference` is `remittanceInfo`.
- [x] Task 7: Declare the `MatchingRule` schema in `lib/Settings/shillinq_register.json` with REQ-BR-005 predicate metadata (`exact-amount + exact-reference`, `amount-range + customer-name`, `amount + counterparty-iban`, `partial-amount + multi-line aggregation`); confidence scoring per predicate
      - Reconciliation note: shipped via the same ADR-037 fragment. This task added the REQ-BR-004/REQ-BR-005 fields: `targetType`, `autoConfirm`, `lifecycleState`, `confidenceScore`, and expanded predicate `op` enum to include `exact-amount`, `amount-range`, `reference-match`, `counterparty-fuzzy`, `date-window` plus the per-op shape fields (amount/min/max/pattern/name/threshold/days). Legacy `exact/contains/regex` ops retained for back-compat.
- [x] Task 8: Declare the `ReconciliationMatch` schema in `lib/Settings/shillinq_register.json` with fields (statementLineId, candidateType, candidateId, confidence, state, confirmedBy, confirmedAt, rejectedReason); lifecycle `candidate → confirmed` / `candidate → rejected`
      - Reconciliation note: shipped via the same ADR-037 fragment. This task added the REQ-BR-006 lifecycle (`pending → confirmed` / `pending → rejected`), `bankLineRefs`/`targetRefs` N×M arrays, `partial` flag, `confidenceScore`, `rejectedReason`. `state` is the existing `status` field; `candidateType` is `matchType`; `candidateId` is `matchedObjectId`.
- [x] Task 9: Declare the matching aggregation per REQ-BR-006 — `x-openregister-aggregations` query consuming `MatchingRule` predicates and emitting `ReconciliationMatch` candidates against AP / AR invoices for unmatched `BankStatementLine` records
      - Enriched the existing `matchCandidates` aggregation with explicit `targets` (ar-invoice, ap-invoice, gl-transaction, customer, vendor), `dedupe: first-match-wins` per REQ-BR-004 priority, and an `emits` block declaring the auto-confirm-when-rule.autoConfirm-true path per REQ-BR-006.
- [x] Task 10: Declare the parser path per REQ-BR-003 — EITHER `x-openregister-calculations` for CAMT.053 + MT940 + CSV parsing OR a single-method `OCA\Shillinq\Lifecycle\StatementParser::parse(string $contents, string $format): array` (~50 LOC, ADR-031 exception annotated) if extension unavailable
      - Shipped as `lib/Lifecycle/StatementParser.php` (parse + allLinesResolved, ~305 LOC) via the prior compliance change task 6.3. Added a second `@spec` cross-reference to this change's task 10 to make the sunset-path ownership explicit.
- [x] Task 11: Add `x-openregister-lifecycle` to `BankStatement` declaring `imported → in-progress → reconciled → audit-locked` per REQ-BR-008; suspense-routing transition on unmatched line; declarative uniqueness on file checksum + period overlap per REQ-BR-009
      - BankStatement lifecycle was already present from the compliance change; this task added the `x-openregister-uniqueness` block (composite checksum key + range-overlap on bank account × period per REQ-BR-009) and a fresh `x-openregister-lifecycle` on BankStatementLine for the `unmatched → matched` / `unmatched → routed-to-suspense` transitions per REQ-BR-007.
- [x] Task 12: Resolve suspense-account designation in `design.md` discovery (schema flag `Account.isSuspenseAccount: true` additive boolean OR administration setting); if schema-flag path chosen, augment T1's `Account` schema additively
      - Resolved as the schema-flag path (symmetry with the pre-existing `Account.isClosingAccount` flag). `Account.isSuspenseAccount: boolean` ships as an additive overlay in the new `lib/Settings/register.d/add-shillinq-bank-reconciliation.json` per ADR-037; design.md D3 + Open Questions updated.
- [x] Task 13: Add 2 manifest navigation entries (`Bank Reconciliation`, `Matching Rules`) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-BR-010; `node tests/validate-manifest.js` exits 0
      - Added menu items BankReconciliation (order=26) + MatchingRules (order=27) in the Bookkeeping section; pages BankReconciliation (index), BankReconciliationDetail (detail with lines sub-grid + candidate-matches sub-grid + lifecycle actions for open/complete/audit-lock), MatchingRules (index), MatchingRuleDetail (detail with predicate array). Manifest version bumped 1.3.9 → 1.3.10 for cache-bust. `node tests/validate-manifest.js` PASS.
- [x] Task 14: Update `openspec/architecture/adr-000-data-model.md` with the 4 new entities, reconciling against any existing `BankStatement` / `Reconciliation` data-model entries
      - BankStatement section rewritten with the T2 overlay fields + reconciliation note (T2 overlay vs T4 PSD2 path); BankStatementLine / MatchingRule / ReconciliationMatch sections added with Schema.org annotations + relations + primary-spec references.

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review confirms the rule-based matching + suspense-routing flow matches Dutch SMB daily reconciliation practice. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local matching service; no app-local file storage; lifecycle declarative; parser declarative or ADR-031-exception-annotated; manifest carries the navigation). No source code changes outside `openspec/changes/add-shillinq-bank-reconciliation/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for CAMT.053 25-line parsing, MT940 25-line parsing, CSV import, match emission against AP / AR, suspense routing on unmatched, duplicate import rejection, audit-lock transition (pre-declared on Tasks 5–11); Playwright MCP browser tests for the bank-rec detail page operator confirmation + matching-rule editor (pre-declared on Task 13); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/bank-reconciliation.md` per ADR-030 journeydoc convention and commits a bank-rec detail screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Bank Reconciliation`, `Bank Statement`, `Statement Line`, `Matching Rule`, `Suspense Account`, `Confirm Match`, `Reject Match`, `Route to Suspense`, `Imported`, `In Progress`, `Reconciled`, `Audit Locked`.
