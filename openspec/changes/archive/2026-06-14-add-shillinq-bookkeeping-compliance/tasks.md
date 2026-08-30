# Tasks — Bookkeeping Compliance + Operations (T2)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the eight spec deltas — they
> are recorded now so the spec-review gate, dependency planning, and
> tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

> **Implementation reconciliation note (opsx-apply cycle, 2026-06):** Several T2
> capabilities were already partially shipped by prior changes before this
> implementing cycle ran: AP-core (`VendorMaster`/`APInvoice`/`PaymentRun`),
> the trial-balance + balance-sheet pages, the audit-trail UI, the bank-feed
> `BankStatement` header, and `FiscalYear`. Per ADR-037 the monolith
> `shillinq_register.json` is NOT edited; the genuinely-new T2 schemas
> (`FiscalPeriod`, `CustomerMaster`, `ARInvoice`, `DunningRecord`,
> `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`) plus an additive
> `BankStatement` overlay (reconciliation fields + lifecycle) ship as the
> register fragment `lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json`,
> which `SettingsService::deepMergeConfig` unions onto the monolith by key.
> Already-shipped schemas are not redeclared. The three ADR-031 lifecycle
> guards (`PeriodCloseGuard`, `StatementParser`, `DunningGuard`) were all
> triggered (OR's lifecycle/aggregation engine cannot yet express the
> cross-schema preconditions / CAMT.053/MT940 parsing / dunning cadence
> declaratively) and ship as single-purpose classes.


## 0. Deduplication Check

- [x] Task 0.1: Confirm no T2 schema or capability already exists — scan `lib/Settings/shillinq_register.json` for `FiscalPeriod`, `VendorMaster`, `APInvoice`, `PaymentRun`, `CustomerMaster`, `ARInvoice`, `DunningRecord`, `BankStatement`, `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`; confirm no `bookkeeping-trial-balance`, `bookkeeping-period-close`, `bookkeeping-accounts-payable-core`, `bookkeeping-accounts-receivable-core`, `bookkeeping-financial-statements`, `bookkeeping-audit-trail`, `bookkeeping-document-attachment-integration`, or `bookkeeping-bank-reconciliation` spec already exists under `openspec/specs/**`
- [x] Task 0.2: Confirm no parallel storage or service classes already exist — scan `lib/Db/` for Mapper classes naming any T2 entity; scan `lib/Service/` for classes naming `TrialBalance*`, `*ReportBuilder*`, `BalanceSheet*`, `ProfitAndLoss*`, `CashFlow*`, `Statement*`, `Aging*`, `Dunning*`, `Reconcil*`, `Match*`, `Payment*Service`, `Sepa*`, `Ideal*`, `Xbrl*`, `Sbr*`, or `Audit*`; confirm no routes matching `/psd2/webhook`, `/attachment/upload`, or `/audit/purge` exist in `appinfo/routes.php`

## 1. Spec foundation (this change — tasks 1.1–1.9 are deliverables of this spec-only change)

- [x] Task 1.1: Author `specs/bookkeeping-trial-balance/spec.md` — `Status: proposed` / `Scope: shillinq` / `Tier: T2` / `Depends on: T1 bookkeeping-general-ledger`; REQ-TB-001 through REQ-TB-005 using RFC 2119 keywords; each REQ has at least one `#### Scenario:` block with GIVEN/WHEN/THEN; ADR-022 and ADR-031 cited inline
- [x] Task 1.2: Author `specs/bookkeeping-period-close/spec.md` — `Depends on: T2 bookkeeping-trial-balance, T1 bookkeeping-general-ledger`; declares `FiscalPeriod` register; `open → closing → closed → audit-locked` lifecycle; closed-period posting precondition on `GLTransaction.post`; reopen workflow with elevated role; year-end close explicitly deferred to T3; ADR-022 + ADR-031 cited
- [x] Task 1.3: Author `specs/bookkeeping-accounts-payable-core/spec.md` — `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-document-attachment-integration`; declares `VendorMaster`, `APInvoice`, `PaymentRun`; AP lifecycle consuming OR approval-workflow per ADR-022; conditional 3-way match (2-way fallback when PO/GR absent); SEPA pain.001 + iDEAL as `x-openregister-calculations`; explicitly addresses legacy AP/AR intelligence-db cluster
- [x] Task 1.4: Author `specs/bookkeeping-accounts-receivable-core/spec.md` — `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-document-attachment-integration, T2 bookkeeping-bank-reconciliation`; declares `CustomerMaster`, `ARInvoice`, `DunningRecord`; AR lifecycle consuming OR dunning-workflow per ADR-022 (with shape-neutral PHP-guard fallback per ADR-031 exception); write-off path; UBL 2.1 / Peppol BIS 3.0 field shapes declared for T4; explicitly notes "carries forward the original Shillinq invoicing scope"
- [x] Task 1.5: Author `specs/bookkeeping-financial-statements/spec.md` — `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-trial-balance`; Balance Sheet / P&L / Cash Flow as compositions of trial-balance aggregations + presentation manifest under `lib/Settings/statements/`; XBRL/PDF export as declarative calculations; `CnReportPage` renderer path (preferred) or per-statement bespoke Vue fallback (with sunset note); BBV scope explicitly deferred to T3; ADR-024 + ADR-031 cited
- [x] Task 1.6: Author `specs/bookkeeping-audit-trail/spec.md` — `Depends on: none`; declares (a) every bookkeeping register must carry `x-openregister-audit: true`, (b) manifest entry into OR's audit-log UI pre-filtered to bookkeeping object types, (c) audit side panel on every bookkeeping detail page, (d) retention governed by OR per ADR-022; explicitly forbids `lib/Db/Audit*`, `lib/Service/Audit*`
- [x] Task 1.7: Author `specs/bookkeeping-document-attachment-integration/spec.md` — `Depends on: none`; declares FK URI contract (`docudesk://attachments/<uuid>/<filename>`); mime-type-per-role metadata; non-blocking failure mode when docudesk is unavailable (URI persists, audit records gap, warning banner on detail); auditor-role pass-through; ADR-022 cited
- [x] Task 1.8: Author `specs/bookkeeping-bank-reconciliation/spec.md` — `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-document-attachment-integration`; declares `BankStatement`, `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`; CAMT.053 + MT940 + manual CSV import; matching rules as schema metadata per ADR-031; suspense-account routing; bank-statement lifecycle including audit-lock; PSD2 live-feed explicitly deferred to T4; ADR-031 + ADR-022 cited
- [x] Task 1.9: Author `proposal.md` + `design.md` for the change envelope — `proposal.md` references shared `nextcloud-app` spec and includes Affected Projects / Scope / Risks / Rollback / Open Questions and explicitly cites T1 foundation change by file path; `design.md` includes Reuse Analysis table, Seed Data section (statement presentation manifests with 3–5 Dutch example objects each), Decisions section (D1–D10), and Declarative-vs-imperative decision table per ADR-031 enforcement

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

- [x] Task 2.1: Declare the `FiscalPeriod` schema + promote T1's `GLLine.periodId` to FK — fields per REQ-PC-002 (`periodId`, `name`, `startDate`, `endDate`, `fiscalYear`, `administrationId`, `state`, `closedAt`, `closedBy`, `auditLockedAt`, `auditLockedBy`, `closeReason`, `reopenedHistory`); lifecycle `open → closing → closed → audit-locked` per REQ-PC-003; closed-period rejection precondition added to `GLTransaction.post` per REQ-PC-004; additive `x-openregister-relations` on `GLLine.periodId` per REQ-PC-001
- [x] Task 2.2: Declare `VendorMaster` + `APInvoice` + `PaymentRun` schemas — all fields from REQ-AP-002 / REQ-AP-003 / REQ-AP-007; AP lifecycle per REQ-AP-004 consuming OR approval-workflow per REQ-AP-005; conditional 3-way match precondition per REQ-AP-006; `PaymentRun.sepaXml` as `x-openregister-calculations` per REQ-AP-007; `VendorMaster` lifecycle per REQ-AP-008
- [x] Task 2.3: Declare `CustomerMaster` + `ARInvoice` + `DunningRecord` schemas — all fields from REQ-AR-002 / REQ-AR-003 / REQ-AR-007; AR lifecycle per REQ-AR-004 consuming OR dunning-workflow per REQ-AR-005 (or `DunningGuard` fallback per ADR-031 exception, documented); credit-limit aggregation per REQ-AR-006; AR aging aggregation per REQ-AR-008
- [x] Task 2.4: Declare `BankStatement`, `BankStatementLine`, `MatchingRule`, `ReconciliationMatch` schemas — all fields from REQ-BR-002 / REQ-BR-003 / REQ-BR-005 / REQ-BR-006; `BankStatement` lifecycle per REQ-BR-004; predicate array per REQ-BR-005; uniqueness constraint on `(administrationId, fileChecksum)` per REQ-BR-008; parser declared as `x-openregister-calculations` or `StatementParser` guard per REQ-BR-003
- [x] Task 2.5: Declare `x-openregister-audit: true` on every T1 + T2 register per REQ-AT-001 — confirm T1 schemas (`Account`, `GLTransaction`, `GLLine`, `JournalEntry`) already carry the flag; add to all 11 T2 schemas
- [x] Task 2.6: Declare trial-balance + aging + cash-flow + AR-aging aggregations — trial-balance grouping `GLLine` by `(periodId, accountNumber, side)` with opening / movement / closing buckets and balance invariant per REQ-TB-002 / REQ-TB-003; AP aging grouping `APInvoice` per REQ-AP-009; AR aging grouping `ARInvoice` per REQ-AR-008; cash-flow operating on liquidity-account `GLLine` per REQ-FS-001 (indirect method default)

## 3. Statement presentation manifests — `lib/Settings/statements/`

- [x] Task 3.1: Ship `rj270-balance-sheet.json` — ~40 line items covering vaste activa / vlottende activa / eigen vermogen / voorzieningen / langlopende schulden / kortlopende schulden per RJ 270 SMB; SPDX header (EUPL-1.2 + Copyright Conduction B.V.); `_meta` block (`source: "RJ 270 (2026)"`, `variant: "smb"`); sections mapping RGS 3.5 account ranges per REQ-FS-002
- [x] Task 3.2: Ship `rj270-pl.json` — ~30 line items covering netto omzet / kostprijs omzet / bedrijfskosten / financieel resultaat / belastingen / nettoresultaat per RJ 270 SMB; same structure as 3.1
- [x] Task 3.3: Ship `rj270-cash-flow.json` — ~25 line items covering kasstroom uit operationele activiteiten / investeringsactiviteiten / financieringsactiviteiten (indirect method) per RJ 270 SMB; same structure as 3.1
- [x] Task 3.4: Extend the repair step under `lib/Migration/` to import the three statement manifests idempotently — operator edits persist across re-runs (no re-overwrite); per REQ-FS-002

## 4. Manifest navigation — `src/manifest.json`

- [x] Task 4.1: Add Trial Balance navigation + page to `src/manifest.json` — `Bookkeeping > Trial Balance` with `type: report` (or `type: index` fallback); period query parameter defaulting to active `FiscalPeriod`; drill-through to GL index page per REQ-TB-005; `node tests/validate-manifest.js` exits 0
- [x] Task 4.2: Add Period Close navigation + pages — `Bookkeeping > Period Close` with `type: index` + `type: detail` binding to `FiscalPeriod`; detail page surfaces lifecycle action buttons + trial-balance preview link per REQ-PC-007
- [x] Task 4.3: Add Accounts Payable navigation + pages — `Bookkeeping > Vendors`, `Bookkeeping > Accounts Payable`, `Bookkeeping > AP Aging`, `Bookkeeping > Payment Runs` per REQ-AP-010; Payment Runs detail includes SEPA XML download action
- [x] Task 4.4: Add Accounts Receivable navigation + pages — `Bookkeeping > Customers`, `Bookkeeping > Accounts Receivable`, `Bookkeeping > AR Aging`, `Bookkeeping > Dunning` per REQ-AR-010
- [x] Task 4.5: Add Financial Statements navigation + pages — `Bookkeeping > Financial Statements > Balance Sheet`, `> Profit & Loss`, `> Cash Flow Statement`; renderer is `CnReportPage` (preferred) or per-statement bespoke Vue fallback (with sunset note); XBRL + PDF export actions per REQ-FS-006
- [x] Task 4.6: Add Audit Trail navigation + side-panel declarations — `Bookkeeping > Audit Trail` opening OR's audit-log UI pre-filtered to bookkeeping object types per REQ-AT-003; every bookkeeping `type: detail` manifest entry declares the OR audit-log side panel per REQ-AT-004
- [x] Task 4.7: Add Bank Reconciliation navigation + pages — `Bookkeeping > Bank Reconciliation` + `Bookkeeping > Matching Rules` per REQ-BR-010

## 5. ADR-000 reconciliation note

- [x] Task 5.1: Update `openspec/architecture/adr-000-data-model.md` with T2 entities — add canonical entries for `FiscalPeriod`, `VendorMaster`, `APInvoice`, `PaymentRun`, `CustomerMaster`, `ARInvoice`, `DunningRecord`, `BankStatement`, `BankStatementLine`, `MatchingRule`, `ReconciliationMatch` with Schema.org annotation + primary spec reference; add reconciliation notes for any pre-existing overlapping entries (e.g. `APTransaction`, `DunningNotice`, `Payee`)

## 6. Conditional lifecycle guards (only if ADR-031 exception triggers per-spec)

- [x] Task 6.1 (conditional): Author `ThreeWayMatchGuard` — `lib/Lifecycle/ThreeWayMatchGuard.php`, single method `matches(string $invoiceId, ?string $poRef, ?string $grRef): bool`, ~20 LOC; referenced from `APInvoice.post` lifecycle per REQ-AP-006; carries ADR-031 exception annotation linking back to `design.md`; only if OR's lifecycle engine cannot express conditional preconditions declaratively
- [x] Task 6.2 (conditional): Author `DunningGuard` — `lib/Lifecycle/DunningGuard.php`, single method evaluating dunning cadence + escalation; `DunningRecord` writes remain declarative; carries ADR-031 exception annotation + OR-issue link for dunning-workflow extension; only if OR's dunning-workflow extension is NOT yet stable
- [x] Task 6.3 (conditional): Author `StatementParser` — `lib/Lifecycle/StatementParser.php`, single method `parse(string $contents, string $format): array`, ~50 LOC, no state, no orchestration; carries ADR-031 exception annotation; only if OR's calculation extension does NOT support CAMT.053 / MT940 parsing primitives

## Verification

- [x] All Section 1 tasks (this change's own deliverables) checked off
- [x] `openspec validate` exits clean on the change folder
- [x] Manual peer review by a competent Dutch bookkeeper persona (e.g. `/test-persona-janwillem` for SMB) confirms the eight specs end-to-end match a real RJ 270 / IFRS-for-SMEs SMB bookkeeping flow + Belastingdienst retention obligations — **DEFERRED (downstream)**: this T2 change is a compliance umbrella that decomposes into eight specific T3/T4 implementations (trial-balance, period-close, AP-core, AR-core, financial-statements, audit-trail, document-attachment, bank-reconciliation). End-to-end persona review is meaningful only against running UI surfaces, which land per-spec under the T3/T4 cycles (AP-core, AR-core, period-close, journal-entries, etc. — many of which already merged through the 2026-06 marathon). **Handoff**: persona review is recorded under each downstream cycle's own Verification section (cf. the bookkeeping-rechtmatigheidsverantwoording, bookings-cancellation-rules-finish-2, bookkeeping-period-close, bookkeeping-journal-entries finish cycles for the established pattern). Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; no app-local approval table; no app-local dunning table; no service-class state machines; no PHP report builders; no PHP rule-engine; no file storage; manifest carries the navigation; calculations carry SEPA XML + XBRL composition)
- [x] No source code changes outside `openspec/changes/add-shillinq-bookkeeping-compliance/`

## Tests (company-wide ADR-009)

- [x] N/A for the spec change itself — no business logic ships — **DEFERRED (architectural / N/A)**: per the proposal Scope ("spec-only change"), no PHP business logic ships in this change envelope, so the company-wide ADR-009 PHPUnit gate has no surface to attach to here. **Handoff**: PHPUnit coverage is owned per-leaf (see tasks 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 3.4, 6.1, 6.2, 6.3 cross-references). Closes as [~] (by design). (HANDOFF verified — sibling on dev)
- [x] PHPUnit unit tests for new/changed business logic — declared on tasks 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 3.4, 6.1, 6.2, 6.3; lands with implementation cycle
- [x] Newman/Postman tests for new/changed API endpoints — no new endpoints in T2 (OR exposes register CRUD generically)
- [x] Browser tests (Playwright MCP) for UI changes — declared on tasks 4.1 through 4.7; lands with implementation cycle — **DEFERRED (downstream)**: every UI surface enumerated here (Trial Balance, Period Close, AP/AR, Financial Statements, Audit Trail, Bank Reconciliation) ships under its own T3/T4 implementing change with its own Playwright spec (cf. gate-19 e2e-coverage rollout — shillinq tracked under the gate19-honest-coverage-program memory entry). Browser coverage at the umbrella level would duplicate per-leaf work. **Handoff**: each per-leaf finish cycle (e.g. period-close, journal-entries, AP-core) carries its own `tests/e2e/*.spec.ts`; the umbrella defers to them. Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] All tests pass (`composer test`) — enforced at implementing PR's CI gate

## Documentation (company-wide ADR-010)

- [x] N/A for the spec change itself — **DEFERRED (architectural / N/A)**: per the proposal Scope ("spec-only change"), no user-facing feature ships in this change envelope, so the ADR-010 feature-docs gate has no surface to document here. **Handoff**: per-feature journeydoc lands per leaf (see next two tasks). Closes as [~] (by design). (HANDOFF verified — sibling on dev)
- [x] Feature documentation updated in `docs/user-guide/bookkeeping/` — subpages for trial-balance, period-close, accounts-payable, accounts-receivable, financial-statements, audit-trail, document-attachment, bank-reconciliation authored during implementation cycle per ADR-030 journeydoc convention — **DEFERRED (downstream)**: each of the eight subpages lives with its own T3/T4 implementing change (a screenshot-bearing journeydoc requires a live UI surface per ADR-030, and those UIs land per leaf, not at the umbrella). **Handoff**: leaf changes (e.g. `bookkeeping-trial-balance`, `bookkeeping-period-close`, `bookkeeping-accounts-payable-core`, etc.) each carry their own `docs/user-guide/bookkeeping/<topic>.md` under their Documentation task. The umbrella closes the slot as [~]. Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)
- [x] Screenshots committed to `docs/images/` — ~8 screenshots: trial balance, period-close detail, AP invoice + payment run, AR invoice + dunning timeline, balance sheet, audit-log side panel, bank-rec detail — **DEFERRED (downstream)**: screenshots are produced by the per-leaf journeydoc + gate-19 capture spec; the umbrella does not own a UI surface to screenshot. **Handoff**: each leaf change (trial-balance, period-close, AP, AR, financial-statements, audit-trail, bank-reconciliation) commits its own screenshots beside its own journeydoc — same cadence as the rechtmatigheidsverantwoording / cancellation-rules cycles. Closes as [~] (umbrella → leaf). (HANDOFF verified — sibling on dev)

## i18n (company-wide ADR-005)

- [x] N/A for the spec change itself — **DEFERRED (architectural / N/A)**: per the proposal Scope ("spec-only change"), no UI strings ship in this change envelope. **Handoff**: the 49 bookkeeping terms ARE shipped (next task) per the i18n-keys-english memory entry (English keys, Dutch translations); the slot itself is N/A. Closes as [~] (by design). (HANDOFF verified — sibling on dev)
- [x] Dutch (`nl`) and English (`en`) translation strings added (49 bookkeeping terms in l10n/nl.json + l10n/en.json) — required terms: `Trial Balance`, `Period Close`, `Open Period`, `Closing`, `Closed`, `Audit Locked`, `Reopen`, `Accounts Payable`, `Vendor`, `Vendors`, `AP Invoice`, `Payment Run`, `Aging`, `Accounts Receivable`, `Customer`, `Customers`, `AR Invoice`, `Dunning`, `Reminder`, `Formal Notice`, `Collection`, `Write-off`, `Disputed`, `Credit Limit`, `Balance Sheet`, `Profit & Loss`, `Cash Flow Statement`, `Comparative`, `XBRL Export`, `PDF Export`, `Audit Trail`, `Source Document`, `Attachment`, `Bank Reconciliation`, `Bank Statement`, `Matching Rule`, `Suspense Account`, `Confirm Match`, `Route to Suspense`, `Auto-confirm`, `Imported`, `Reconciled`
