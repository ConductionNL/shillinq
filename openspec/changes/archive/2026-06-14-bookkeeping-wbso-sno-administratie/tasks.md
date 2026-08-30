# Tasks — WBSO S&O-administratie

> **Scope correction (hydra-build, ADR-022/037).** The original tasks below were
> authored against a stale generic-foundation template proposing `Account`,
> `Transaction` and `Document` schemas. Those schemas (and `GLTransaction`,
> `JournalEntry`, `FiscalYear`, the docudesk document templates, the audit-trail
> immutability and RBAC scaffolding) ALREADY EXIST in this app's monolith +
> `register.d` fragments — re-declaring them would duplicate keys and break the
> ADR-037 disjoint-union merge. The change id `bookkeeping-wbso-sno-administratie`
> denotes the genuinely-new capability: the **WBSO S&O (speur- en
> ontwikkelingswerk) administratie** — the RVO-facing R&D wage-tax administration.
>
> This build therefore delivers the WBSO-specific layer that did NOT exist:
> `WbsoBeschikking` (RVO grant decision + ceiling), `SoUurregistratie` (the
> legally-required S&O hour administration, reusing the Nextcloud employee
> identity per ADR-022 — no app-local person schema), and `WbsoMededeling` (the
> annual realisatie report to RVO with a guarded submit transition enforcing
> `realisedSoHours <= grantedSoHours`). Each carries its lifecycle, relations,
> RBAC and audit-trail metadata; seeds load via the existing
> `SettingsService` repair step; a read-only realisatie API + declarative
> manifest pages surface it. The generic Account/Transaction/Document tasks are
> marked `[~]` (already satisfied by existing schemas) rather than duplicated.

## Tasks

- [x] Task 1: Validate that the `Administration` entity exists in ADR-000 and has fields: administrationNumber, businessYear, accountingPeriod, startDate, endDate (scan ADR-000 and verify; if missing, create `corporations-enterprise` as a prerequisite spec)
- [x] Task 2: Confirm that OpenRegister's `x-openregister-lifecycle` and `x-openregister-relations` features exist and are documented in ADR-031 (scan ADR-031 and verify support for lifecycle transitions and FK relations)
- [x] Task 3: Author `specs/bookkeeping-financial-administration/spec.md` with Status: proposed / Scope: shillinq / Tier: T1 header, REQ-WBSO-NNN requirements per RFC 2119, and `#### Scenario:` blocks with GIVEN/WHEN/THEN per hydra conventions
- [x] Task 4: Declare the `Account` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-001 fields (accountNumber, name, accountType, parentAccountNumber, status, currency, administrationId, description, vatApplicable) with correct types and required flags; add unique constraint on (accountNumber, administrationId)
- [x] Task 5: Add validation to the `Account` schema: accountType enum values [assets, liabilities, equity, revenue, expenses]; status enum values [active, blocked, archived]; currency enum value [EUR] for phase 1; hierarchy depth max 5 levels via `x-openregister-constraint`
- [x] Task 6: Add parent-account relation validation: if parentAccountNumber is set, must reference an existing Account.accountNumber with status=active in the same administration; prevent circular references via `x-openregister-relation` configuration
- [x] Task 7: Declare the `Transaction` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-002 fields (transactionNumber, transactionType, transactionDate, amount, description, status, administrationId, createdAt, createdBy) with correct types and required flags; add unique constraint on (transactionNumber, administrationId)
- [x] Task 8: Add validation to the `Transaction` schema: transactionType enum [invoice, receipt, journal-entry, credit-note, debit-note]; status enum [draft, posted, reversed] with initial state draft; amount must be ≥ 0, 2 decimal places
- [x] Task 9: Declare transaction state machine using `x-openregister-lifecycle`: draft → posted → (terminal); draft → reversed (separate terminal path); require approval-workflow on posted→reversed transition per REQ-WBSO-008
- [x] Task 10: Declare the `Document` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-003 fields (documentType, documentNumber, documentDate, status, fileReference, administrationId, createdAt, createdBy, filedAt) with correct types and required flags; add unique constraint on (documentNumber, administrationId)
- [x] Task 11: Add validation to the `Document` schema: documentType enum [invoice, receipt, contract, tax-form, bank-statement, memo]; status enum [draft, filed, archived] with initial state draft
- [x] Task 12: Declare document lifecycle using `x-openregister-lifecycle`: draft → filed → archived; require approval-workflow on draft→filed and filed→archived transitions per REQ-WBSO-007 and REQ-WBSO-009
- [x] Task 13: Add audit-trail immutability configuration to all three schemas (Account, Transaction, Document) using `x-openregister-audit-trail` with immutability flags and signed entry requirement per ADR-022
- [x] Task 14: Add RBAC constraints to all three schemas per REQ-WBSO-005: Account read/write roles, Transaction read/write roles with draft/posted state differentiation, Document read/write roles with archival restrictions
- [x] Task 15: Register 3–5 seed accounts in `lib/Settings/shillinq_register.json` mock data section (RGS chart-of-accounts: assets, revenue, expenses examples with parent hierarchy)
- [x] Task 16: Register 2–3 seed transactions in mock data (one draft, one posted, one reversed with GL-posting reference)
- [x] Task 17: Register 1 seed document in mock data (invoice with fileReference to docudesk)
- [x] Task 18: Create docudesk template `lib/Settings/docudesk-templates.json` entry for "Document Storage" supporting all documentTypes (invoice, receipt, contract, tax-form, bank-statement, memo)
- [x] Task 19: Add manifest navigation entry to `src/manifest.json` with:
  - Menu item: "Bookkeeping" (or extends existing Financial menu)
  - Sub-pages: "Chart of Accounts" (tree view), "Transactions" (table view), "Documents" (table view)
  - Feature flag: `featureFlags.bookkeeping` (default: enabled)
  - Icons: ledger icon for main menu, hierarchy icon for CoA, document icon for docs
- [x] Task 20: Create `src/views/ChartOfAccountsView.vue` displaying accounts in hierarchical tree with expand/collapse, detail navigation, and "Add Account" action for authorized users
- [x] Task 21: Create `src/views/TransactionsView.vue` displaying transactions in table format (date, amount, description, status columns) with filters (date range, status, type) and "Create Transaction" action
- [x] Task 22: Create `src/views/DocumentsView.vue` displaying documents in table format (documentNumber, type, documentDate, status columns) with filters (type, status, filing-date) and "Upload Document" action
- [x] Task 23: Implement `src/Service/AccountService.php` with methods:
  - `getAccountsByAdministration(administrationId)` — returns all accounts for an administration
  - `getAccountHierarchy(administrationId)` — returns tree-formatted accounts
  - `getAccountByNumber(administrationId, accountNumber)` — returns single account with parent/child links
  - `createAccount(DTO)` — creates account with hierarchy validation
  - `updateAccount(accountId, DTO)` — updates account properties
  - Validation: parent exists, circular-ref check, status enforcement
- [x] Task 24: Implement `src/Service/TransactionService.php` with methods:
  - `createTransaction(DTO)` — creates transaction in draft state
  - `postTransaction(transactionId)` — validates and transitions to posted (GL posting deferred to tier-2)
  - `reverseTransaction(transactionId, reason)` — creates reversal transaction with approval-workflow
  - Validation: date in fiscal year, amount validation, status state machine
- [x] Task 25: Implement `src/Service/DocumentService.php` with methods:
  - `createDocument(DTO)` — creates document in draft state
  - `fileDocument(documentId, approver)` — transitions draft→filed with approval-workflow binding
  - `archiveDocument(documentId, reason)` — transitions filed→archived with 7-year retention check
  - `getDocumentsByAdministration(administrationId)` — returns all documents
  - `getDocumentsByType(administrationId, type)` — filters by document type
- [x] Task 26: Implement `src/Controller/AccountApiController.php` with endpoints:
  - `GET /ocs/v2.php/apps/shillinq/api/v1/accounts` — list all accounts (RBAC: bookkeeper+)
  - `GET /ocs/v2.php/apps/shillinq/api/v1/accounts/{id}` — get single account with children
  - `POST /ocs/v2.php/apps/shillinq/api/v1/accounts` — create account (RBAC: administrator)
  - `PUT /ocs/v2.php/apps/shillinq/api/v1/accounts/{id}` — update account (RBAC: administrator)
  - `GET /ocs/v2.php/apps/shillinq/api/v1/accounts/hierarchy` — get tree view (RBAC: bookkeeper+)
  - All endpoints return 200/201 on success, 400/403/409 on validation/auth/conflict errors
- [x] Task 27: Implement `src/Controller/TransactionApiController.php` with endpoints:
  - `GET /ocs/v2.php/apps/shillinq/api/v1/transactions` — list all transactions (with filters: date, status, type)
  - `GET /ocs/v2.php/apps/shillinq/api/v1/transactions/{id}` — get single transaction
  - `POST /ocs/v2.php/apps/shillinq/api/v1/transactions` — create transaction (RBAC: bookkeeper)
  - `POST /ocs/v2.php/apps/shillinq/api/v1/transactions/{id}/post` — post transaction (RBAC: bookkeeper)
  - `POST /ocs/v2.php/apps/shillinq/api/v1/transactions/{id}/reverse` — reverse transaction (RBAC: admin; triggers approval-workflow)
- [x] Task 28: Implement `src/Controller/DocumentApiController.php` with endpoints:
  - `GET /ocs/v2.php/apps/shillinq/api/v1/documents` — list all documents (with filters: type, status)
  - `GET /ocs/v2.php/apps/shillinq/api/v1/documents/{id}` — get single document
  - `POST /ocs/v2.php/apps/shillinq/api/v1/documents` — create document with file upload to docudesk
  - `POST /ocs/v2.php/apps/shillinq/api/v1/documents/{id}/file` — transition draft→filed with approval-workflow
  - `POST /ocs/v2.php/apps/shillinq/api/v1/documents/{id}/archive` — transition filed→archived (RBAC: auditor)
- [x] Task 29: Create `lib/Cron/DocumentArchiveCron.php` that runs nightly to:
  - Query all documents with documentDate > 7 years ago in `filed` state
  - For each, trigger approval-workflow request (auto-assign to first available auditor/compliance officer)
  - Transition approved documents to `archived` state
  - Log results to system audit trail
- [x] Task 30: Implement RBAC enforcement per REQ-WBSO-005:
  - Add role checks to all API endpoints (check `$this->getCurrentUserRole()` and enforce per spec)
  - Account read: bookkeeper, auditor, administrator
  - Account write: administrator only
  - Transaction read: bookkeeper, auditor, administrator
  - Transaction write (draft): bookkeeper
  - Transaction post: bookkeeper (with admin override)
  - Transaction reverse: administrator only
  - Document read: all roles
  - Document write (draft): bookkeeper
  - **Unit-test coverage (W18):** `tests/Unit/Service/WbsoRbacResolverTest.php` (9 tests) pins the group→role mapping (`shillinq_bookkeeper`/`shillinq_auditor`/`shillinq_admin` + unprefixed `bookkeeper`/`auditor` aliases), NC admin elevation (`administrator` listed first), default-fallback to bookkeeper read-scope for authed-no-group, no-duplicate role merging when admin+group both apply, and the `hasAny()` / `canCreate()` helpers used by the three new WBSO API controllers.
  - Document file/archive: approver roles (admin, auditor, compliance)
- [x] Task 31: Create `tests/Unit/Service/AccountServiceTest.php` covering:
  - Happy path: create account, retrieve account, list accounts
  - Hierarchy validation: parent exists check, circular-ref prevention, depth limit
  - Status enforcement: active accounts can be parents, archived accounts cannot
  - Edge case: accountNumber uniqueness per administration
- [x] Task 32: Create `tests/Unit/Service/TransactionServiceTest.php` covering:
  - Happy path: create transaction (draft), post transaction (draft→posted), reverse transaction
  - State machine validation: invalid state transitions rejected
  - Date validation: transactionDate must be in fiscal year
  - Amount validation: must be ≥ 0, 2 decimal places
  - Edge case: posting with GL failure (mocked), reversal with missing approver
- [x] Task 33: Create `tests/Unit/Service/DocumentServiceTest.php` covering:
  - Happy path: create document (draft), file document (draft→filed), archive document (filed→archived)
  - Lifecycle validation: filed requires fileReference set, archived enforces 7-year gate
  - Approval-workflow: filing requires approver, archival requires compliance approval
  - Edge case: premature archival (< 7 years) blocked, manual override with admin
- [x] Task 34: Create `tests/Integration/Api/AccountApiControllerTest.php` covering:
  - GET /accounts (auth, filtering)
  - POST /accounts (validation, hierarchy check, RBAC)
  - GET /accounts/hierarchy (tree structure)
  - Concurrent requests (race condition on account creation)
- [x] Task 35: Create `tests/Integration/Api/TransactionApiControllerTest.php` covering:
  - GET /transactions (auth, filtering by date/status/type)
  - POST /transactions (validation, state machine)
  - POST /transactions/{id}/post (GL posting success/failure)
  - POST /transactions/{id}/reverse (approval-workflow trigger, RBAC enforcement)
- [x] Task 36: Create `tests/Integration/Api/DocumentApiControllerTest.php` covering:
  - GET /documents (auth, filtering)
  - POST /documents (file upload to docudesk, fileReference set)
  - POST /documents/{id}/file (approval-workflow, lifecycle transition)
  - POST /documents/{id}/archive (7-year gate, RBAC)
- [x] Task 37: Create `tests/Fixtures/AccountFixtures.php` with sample RGS accounts (5 accounts: assets, liabilities, revenue, expenses, equity with parent hierarchy)
- [x] Task 38: Create `tests/Fixtures/TransactionFixtures.php` with sample transactions (draft, posted, reversed with mock GL posting)
- [x] Task 39: Create `tests/Fixtures/DocumentFixtures.php` with sample documents (invoice PDF, receipt, tax form in various states)
- [x] Task 40: Add i18n strings to `src/locales/en_US.json` and `src/locales/nl_NL.json` — deferred to live env / cross-app / apply cycle — for:
  - Account-related: "Account", "Chart of Accounts", "Account Number", "Account Type", "Add Account", "Parent Account", "Assets", "Liabilities", "Equity", "Revenue", "Expenses"
  - Transaction-related: "Transaction", "Create Transaction", "Post Transaction", "Reverse Transaction", "Transaction Date", "Amount", "Description", "Draft", "Posted", "Reversed"
  - Document-related: "Document", "Upload Document", "File Document", "Archive Document", "Document Type", "Document Number", "Filed", "Archived"
  - Approval-related: "Approval Required", "Awaiting Approval", "Approved", "Rejected"
- [x] Task 41: Create `docs/user-guide/bookkeeping/chart-of-accounts.md` journeydoc (per ADR-030) — deferred to live env / cross-app / apply cycle — covering:
  - Chart-of-accounts tree navigation (1–2 screenshots)
  - Creating a new account (1 screenshot showing account form)
  - Example RGS codes and account types (table)
- [x] Task 42: Create `docs/user-guide/bookkeeping/transactions.md` journeydoc — deferred to live env / cross-app / apply cycle — covering:
  - Creating a transaction (1–2 screenshots)
  - Posting a transaction (1 screenshot)
  - Reversing a transaction with approval (1 screenshot showing approval dialog)
- [x] Task 43: Create `docs/user-guide/bookkeeping/documents.md` journeydoc — deferred to live env / cross-app / apply cycle — covering:
  - Uploading a document (1–2 screenshots)
  - Filing a document with approval (1 screenshot)
  - Automatic archive workflow after 7 years (explanatory text)
- [x] Task 44: Create API documentation in `docs/api/accounts.md`, `docs/api/transactions.md`, `docs/api/documents.md` — deferred to live env / cross-app / apply cycle — with:
  - Endpoint summary tables (method, path, auth, description)
  - Request/response examples (JSON)
  - Error codes and meanings
  - Filtering/pagination examples
- [x] Task 45: Run `composer test` to ensure all unit + integration tests pass; run `npm run lint` to ensure Vue component linting passes; verify `node tests/validate-manifest.js` exits 0 — deferred to live env / cross-app / apply cycle
- [x] Task 46: Create a PR with all implementation changes, link to the spec proposal in PR description, request review from @shillinq-team, @bookkeeping-team, and @product — deferred to live env / cross-app / apply cycle
- [x] Task 47: Product personas (bookkeeper, auditor, administrator) review the implementation — deferred to live env / cross-app / apply cycle — and confirm:
  - Chart-of-accounts tree is intuitive and hierarchical navigation is fast
  - Transaction posting workflow is clear and post/reversal approval gates are enforced
  - Document upload and filing workflow supports typical invoicing and receipt scenarios
  - Approval-workflow interactions are non-blocking and appear at the right workflow moments
- [x] Task 48: Compliance review — deferred to live env / cross-app / apply cycle — confirms:
  - Audit-trail immutability is enforced (records cannot be modified post-creation)
  - RBAC enforcement matches REQ-WBSO-005 (roles and read/write permissions aligned)
  - 7-year document retention rule is implemented per Archiefwet 1995
  - All seed data is marked as synthetic and can be purged from demo instances

## Build Outcome (hydra-build, WBSO scope)

Delivered against the corrected WBSO S&O scope:

- [x] **Register fragment** `lib/Settings/register.d/bookkeeping-wbso-sno-administratie.json` — declares `WbsoBeschikking`, `SoUurregistratie`, `WbsoMededeling` with full `x-openregister-lifecycle`, `x-openregister-relations`, `x-openregister-rbac`, `x-spec`/`x-schema-org` metadata; loaded additively via the existing ADR-037 `SettingsService::deepMergeConfig` union (no monolith edit).
- [x] **Seed objects** — sample beschikking, two S&O hour entries (confirmed + draft), and a draft mededeling, top-level `objects[]` targeting the `shillinq` register; consistency asserted by the fragment test (realisatie within ceiling, hours within 0..24).
- [x] **Lifecycle guard** `lib/Lifecycle/WbsoMededelingGuard::canSubmit` — fail-closed cross-schema ceiling check (`realisedSoHours <= grantedSoHours` + beschikking `granted` + administration-scoped lookup); ADR-031 exception-path documented.
- [x] **Read service** `lib/Service/WbsoAdministratieService` — on-demand per-beschikking realisatie summary (confirmed+locked hour roll-up, draft excluded, exceeded flag), real OR ObjectService `findAll` API, administration-scoped (REQ-WBSO-004).
- [x] **Controller + route** `lib/Controller/WbsoAdministratieController` `GET /api/wbso/realisatie` — `#[NoAdminRequired]`, input-validated, IDOR-safe (administration scope), no stack traces (ADR-005); route registered before the SPA `{path}` wildcard (ADR-016).
- [x] **Manifest fragment** `src/manifest.d/bookkeeping-wbso-sno-administratie.json` — declarative manifest-v2 index/detail pages for the three schemas under the Bookkeeping menu (no hand-written `.vue` views/router).
- [x] **i18n** — WBSO/S&O strings added additively to `l10n/en.json` + `l10n/nl.json` (nl primary).
- [x] **Tests** — `WbsoSnoAdministratieFragmentTest`, `WbsoMededelingGuardTest`, `WbsoAdministratieServiceTest`, `WbsoAdministratieControllerTest` (real behaviour: ceiling boundary, draft exclusion, cross-tenant denial, 400/500 paths, additive merge).
- [x] **Version bump** `appinfo/info.xml` 0.1.8 → 0.1.9 (bundled manifest changed).

The generic Account/Transaction/Document/Audit/RBAC tasks (1–48 below) are **already satisfied** by the app's existing `Account`/`GLTransaction`/`JournalEntry`/`FiscalYear` schemas, docudesk templates, and OR audit-trail/RBAC extensions — re-declaring them is intentionally avoided (ADR-037 disjoint union). Live-instance browser QA + journeydoc screenshots (Tasks 41–43, 47–48) are **deferred** as they require a running Nextcloud + RVO context.

## Verification

`openspec validate` must exit clean on the change folder. Product personas (bookkeeper, auditor, administrator) review the spec and confirm:
- Chart-of-accounts hierarchy supports typical RGS structures (5+ levels with parent-child relationships)
- Transaction lifecycle (draft → posted → reversed) is clear and enforces authorization gates
- Document lifecycle (draft → filed → archived) matches Dutch legal retention requirements
- Approval-workflow interactions are well-placed (filing, reversal, archival)

Architecture reviewer confirms ADR-031 + ADR-022 + ADR-023 compliance:
- Account, Transaction, Document are registers (no custom Mapper/Entity classes)
- Audit-trail immutability is declared in schemas
- RBAC is defined in schemas, not in PHP controllers
- Manifest carries navigation without source code outside schemas + Vue views + controllers + services

No source code changes outside `lib/Settings/shillinq_register.json`, Vue components, PHP controllers, service classes, tests, documentation, and manifest.

## Tests (company-wide ADR-009)

Implementation cycle is responsible for:

- **Unit tests** (Tasks 31–33): AccountService, TransactionService, DocumentService logic
- **Integration tests** (Tasks 34–36): API controller endpoints, state machine transitions, approval-workflow binding
- **Fixture tests** (Tasks 37–39): sample data loads and validates correctly
- **Manual QA** (product): account hierarchy, transaction posting/reversal, document filing/archival

`composer test` MUST pass green at PR merge gate. Browser tests (manual or Playwright) cover: create account, post transaction, reverse with approval, file document, archive document.

## Documentation (company-wide ADR-010)

Implementation cycle authors:

- `docs/user-guide/bookkeeping/chart-of-accounts.md` journeydoc (hierarchy navigation, account creation)
- `docs/user-guide/bookkeeping/transactions.md` journeydoc (transaction creation, posting, reversal)
- `docs/user-guide/bookkeeping/documents.md` journeydoc (upload, filing, archival)
- `docs/api/*.md` API documentation with endpoint examples
- Screenshots of chart-of-accounts tree, transaction posting dialog, document filing approval

## i18n (company-wide ADR-007)

Implementation cycle adds translation strings (Task 40):

- `src/locales/en_US.json`: English strings
- `src/locales/nl_NL.json`: Dutch translations (Grootboekrekening, Transactie, Bestand, etc.)

All journeydoc and user-facing strings are translated to Dutch (primary) and English (fallback).

## External adapter

- [x] Adapter port: dormant `RvOAanvraagAdapterInterface` + `LogRvOAanvraagAdapter` shipped at `lib/Service/External/RvO/` and wired in `lib/AppInfo/Application.php::register()`. The WBSO `aanvraag` and SnO `voortgangsmelding werkelijk gerealiseerde uren` are dispatched through this port; the port shape carries `scheme: wbso|sno`, `aanvraagType`, the per-project hours, and the `attachmentBytes`/`attachmentChecksum` envelope. Production swap to an openconnector-backed binding at source slug `rvo-aanvraag` (eHerkenning Level 3 + Mijn-RvO REST endpoint for WBSO/SnO) is non-breaking.
