# Tasks — Financial Administration Foundation

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookkeeping-financial-administration` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Validate that the `Administration` entity exists in ADR-000 and has fields: administrationNumber, businessYear, accountingPeriod, startDate, endDate (scan ADR-000 and verify; if missing, create `corporations-enterprise` as a prerequisite spec)
- [x] Task 2: Confirm that OpenRegister's `x-openregister-lifecycle` and `x-openregister-relations` features exist and are documented in ADR-031 (scan ADR-031 and verify support for lifecycle transitions and FK relations)
- [x] Task 3: Author `specs/bookkeeping-financial-administration/spec.md` with Status: proposed / Scope: shillinq / Tier: T1 header, REQ-WBSO-NNN requirements per RFC 2119, and `#### Scenario:` blocks with GIVEN/WHEN/THEN per hydra conventions
- [ ] Task 4: Declare the `Account` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-001 fields (accountNumber, name, accountType, parentAccountNumber, status, currency, administrationId, description, vatApplicable) with correct types and required flags; add unique constraint on (accountNumber, administrationId)
- [ ] Task 5: Add validation to the `Account` schema: accountType enum values [assets, liabilities, equity, revenue, expenses]; status enum values [active, blocked, archived]; currency enum value [EUR] for phase 1; hierarchy depth max 5 levels via `x-openregister-constraint`
- [ ] Task 6: Add parent-account relation validation: if parentAccountNumber is set, must reference an existing Account.accountNumber with status=active in the same administration; prevent circular references via `x-openregister-relation` configuration
- [ ] Task 7: Declare the `Transaction` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-002 fields (transactionNumber, transactionType, transactionDate, amount, description, status, administrationId, createdAt, createdBy) with correct types and required flags; add unique constraint on (transactionNumber, administrationId)
- [ ] Task 8: Add validation to the `Transaction` schema: transactionType enum [invoice, receipt, journal-entry, credit-note, debit-note]; status enum [draft, posted, reversed] with initial state draft; amount must be ≥ 0, 2 decimal places
- [ ] Task 9: Declare transaction state machine using `x-openregister-lifecycle`: draft → posted → (terminal); draft → reversed (separate terminal path); require approval-workflow on posted→reversed transition per REQ-WBSO-008
- [ ] Task 10: Declare the `Document` schema in `lib/Settings/shillinq_register.json` with all REQ-WBSO-003 fields (documentType, documentNumber, documentDate, status, fileReference, administrationId, createdAt, createdBy, filedAt) with correct types and required flags; add unique constraint on (documentNumber, administrationId)
- [ ] Task 11: Add validation to the `Document` schema: documentType enum [invoice, receipt, contract, tax-form, bank-statement, memo]; status enum [draft, filed, archived] with initial state draft
- [ ] Task 12: Declare document lifecycle using `x-openregister-lifecycle`: draft → filed → archived; require approval-workflow on draft→filed and filed→archived transitions per REQ-WBSO-007 and REQ-WBSO-009
- [ ] Task 13: Add audit-trail immutability configuration to all three schemas (Account, Transaction, Document) using `x-openregister-audit-trail` with immutability flags and signed entry requirement per ADR-022
- [ ] Task 14: Add RBAC constraints to all three schemas per REQ-WBSO-005: Account read/write roles, Transaction read/write roles with draft/posted state differentiation, Document read/write roles with archival restrictions
- [ ] Task 15: Register 3–5 seed accounts in `lib/Settings/shillinq_register.json` mock data section (RGS chart-of-accounts: assets, revenue, expenses examples with parent hierarchy)
- [ ] Task 16: Register 2–3 seed transactions in mock data (one draft, one posted, one reversed with GL-posting reference)
- [ ] Task 17: Register 1 seed document in mock data (invoice with fileReference to docudesk)
- [ ] Task 18: Create docudesk template `lib/Settings/docudesk-templates.json` entry for "Document Storage" supporting all documentTypes (invoice, receipt, contract, tax-form, bank-statement, memo)
- [ ] Task 19: Add manifest navigation entry to `src/manifest.json` with:
  - Menu item: "Bookkeeping" (or extends existing Financial menu)
  - Sub-pages: "Chart of Accounts" (tree view), "Transactions" (table view), "Documents" (table view)
  - Feature flag: `featureFlags.bookkeeping` (default: enabled)
  - Icons: ledger icon for main menu, hierarchy icon for CoA, document icon for docs
- [ ] Task 20: Create `src/views/ChartOfAccountsView.vue` displaying accounts in hierarchical tree with expand/collapse, detail navigation, and "Add Account" action for authorized users
- [ ] Task 21: Create `src/views/TransactionsView.vue` displaying transactions in table format (date, amount, description, status columns) with filters (date range, status, type) and "Create Transaction" action
- [ ] Task 22: Create `src/views/DocumentsView.vue` displaying documents in table format (documentNumber, type, documentDate, status columns) with filters (type, status, filing-date) and "Upload Document" action
- [ ] Task 23: Implement `src/Service/AccountService.php` with methods:
  - `getAccountsByAdministration(administrationId)` — returns all accounts for an administration
  - `getAccountHierarchy(administrationId)` — returns tree-formatted accounts
  - `getAccountByNumber(administrationId, accountNumber)` — returns single account with parent/child links
  - `createAccount(DTO)` — creates account with hierarchy validation
  - `updateAccount(accountId, DTO)` — updates account properties
  - Validation: parent exists, circular-ref check, status enforcement
- [ ] Task 24: Implement `src/Service/TransactionService.php` with methods:
  - `createTransaction(DTO)` — creates transaction in draft state
  - `postTransaction(transactionId)` — validates and transitions to posted (GL posting deferred to tier-2)
  - `reverseTransaction(transactionId, reason)` — creates reversal transaction with approval-workflow
  - Validation: date in fiscal year, amount validation, status state machine
- [ ] Task 25: Implement `src/Service/DocumentService.php` with methods:
  - `createDocument(DTO)` — creates document in draft state
  - `fileDocument(documentId, approver)` — transitions draft→filed with approval-workflow binding
  - `archiveDocument(documentId, reason)` — transitions filed→archived with 7-year retention check
  - `getDocumentsByAdministration(administrationId)` — returns all documents
  - `getDocumentsByType(administrationId, type)` — filters by document type
- [ ] Task 26: Implement `src/Controller/AccountApiController.php` with endpoints:
  - `GET /ocs/v2.php/apps/shillinq/api/v1/accounts` — list all accounts (RBAC: bookkeeper+)
  - `GET /ocs/v2.php/apps/shillinq/api/v1/accounts/{id}` — get single account with children
  - `POST /ocs/v2.php/apps/shillinq/api/v1/accounts` — create account (RBAC: administrator)
  - `PUT /ocs/v2.php/apps/shillinq/api/v1/accounts/{id}` — update account (RBAC: administrator)
  - `GET /ocs/v2.php/apps/shillinq/api/v1/accounts/hierarchy` — get tree view (RBAC: bookkeeper+)
  - All endpoints return 200/201 on success, 400/403/409 on validation/auth/conflict errors
- [ ] Task 27: Implement `src/Controller/TransactionApiController.php` with endpoints:
  - `GET /ocs/v2.php/apps/shillinq/api/v1/transactions` — list all transactions (with filters: date, status, type)
  - `GET /ocs/v2.php/apps/shillinq/api/v1/transactions/{id}` — get single transaction
  - `POST /ocs/v2.php/apps/shillinq/api/v1/transactions` — create transaction (RBAC: bookkeeper)
  - `POST /ocs/v2.php/apps/shillinq/api/v1/transactions/{id}/post` — post transaction (RBAC: bookkeeper)
  - `POST /ocs/v2.php/apps/shillinq/api/v1/transactions/{id}/reverse` — reverse transaction (RBAC: admin; triggers approval-workflow)
- [ ] Task 28: Implement `src/Controller/DocumentApiController.php` with endpoints:
  - `GET /ocs/v2.php/apps/shillinq/api/v1/documents` — list all documents (with filters: type, status)
  - `GET /ocs/v2.php/apps/shillinq/api/v1/documents/{id}` — get single document
  - `POST /ocs/v2.php/apps/shillinq/api/v1/documents` — create document with file upload to docudesk
  - `POST /ocs/v2.php/apps/shillinq/api/v1/documents/{id}/file` — transition draft→filed with approval-workflow
  - `POST /ocs/v2.php/apps/shillinq/api/v1/documents/{id}/archive` — transition filed→archived (RBAC: auditor)
- [ ] Task 29: Create `lib/Cron/DocumentArchiveCron.php` that runs nightly to:
  - Query all documents with documentDate > 7 years ago in `filed` state
  - For each, trigger approval-workflow request (auto-assign to first available auditor/compliance officer)
  - Transition approved documents to `archived` state
  - Log results to system audit trail
- [ ] Task 30: Implement RBAC enforcement per REQ-WBSO-005:
  - Add role checks to all API endpoints (check `$this->getCurrentUserRole()` and enforce per spec)
  - Account read: bookkeeper, auditor, administrator
  - Account write: administrator only
  - Transaction read: bookkeeper, auditor, administrator
  - Transaction write (draft): bookkeeper
  - Transaction post: bookkeeper (with admin override)
  - Transaction reverse: administrator only
  - Document read: all roles
  - Document write (draft): bookkeeper
  - Document file/archive: approver roles (admin, auditor, compliance)
- [ ] Task 31: Create `tests/Unit/Service/AccountServiceTest.php` covering:
  - Happy path: create account, retrieve account, list accounts
  - Hierarchy validation: parent exists check, circular-ref prevention, depth limit
  - Status enforcement: active accounts can be parents, archived accounts cannot
  - Edge case: accountNumber uniqueness per administration
- [ ] Task 32: Create `tests/Unit/Service/TransactionServiceTest.php` covering:
  - Happy path: create transaction (draft), post transaction (draft→posted), reverse transaction
  - State machine validation: invalid state transitions rejected
  - Date validation: transactionDate must be in fiscal year
  - Amount validation: must be ≥ 0, 2 decimal places
  - Edge case: posting with GL failure (mocked), reversal with missing approver
- [ ] Task 33: Create `tests/Unit/Service/DocumentServiceTest.php` covering:
  - Happy path: create document (draft), file document (draft→filed), archive document (filed→archived)
  - Lifecycle validation: filed requires fileReference set, archived enforces 7-year gate
  - Approval-workflow: filing requires approver, archival requires compliance approval
  - Edge case: premature archival (< 7 years) blocked, manual override with admin
- [ ] Task 34: Create `tests/Integration/Api/AccountApiControllerTest.php` covering:
  - GET /accounts (auth, filtering)
  - POST /accounts (validation, hierarchy check, RBAC)
  - GET /accounts/hierarchy (tree structure)
  - Concurrent requests (race condition on account creation)
- [ ] Task 35: Create `tests/Integration/Api/TransactionApiControllerTest.php` covering:
  - GET /transactions (auth, filtering by date/status/type)
  - POST /transactions (validation, state machine)
  - POST /transactions/{id}/post (GL posting success/failure)
  - POST /transactions/{id}/reverse (approval-workflow trigger, RBAC enforcement)
- [ ] Task 36: Create `tests/Integration/Api/DocumentApiControllerTest.php` covering:
  - GET /documents (auth, filtering)
  - POST /documents (file upload to docudesk, fileReference set)
  - POST /documents/{id}/file (approval-workflow, lifecycle transition)
  - POST /documents/{id}/archive (7-year gate, RBAC)
- [ ] Task 37: Create `tests/Fixtures/AccountFixtures.php` with sample RGS accounts (5 accounts: assets, liabilities, revenue, expenses, equity with parent hierarchy)
- [ ] Task 38: Create `tests/Fixtures/TransactionFixtures.php` with sample transactions (draft, posted, reversed with mock GL posting)
- [ ] Task 39: Create `tests/Fixtures/DocumentFixtures.php` with sample documents (invoice PDF, receipt, tax form in various states)
- [ ] Task 40: Add i18n strings to `src/locales/en_US.json` and `src/locales/nl_NL.json` for:
  - Account-related: "Account", "Chart of Accounts", "Account Number", "Account Type", "Add Account", "Parent Account", "Assets", "Liabilities", "Equity", "Revenue", "Expenses"
  - Transaction-related: "Transaction", "Create Transaction", "Post Transaction", "Reverse Transaction", "Transaction Date", "Amount", "Description", "Draft", "Posted", "Reversed"
  - Document-related: "Document", "Upload Document", "File Document", "Archive Document", "Document Type", "Document Number", "Filed", "Archived"
  - Approval-related: "Approval Required", "Awaiting Approval", "Approved", "Rejected"
- [ ] Task 41: Create `docs/user-guide/bookkeeping/chart-of-accounts.md` journeydoc (per ADR-030) covering:
  - Chart-of-accounts tree navigation (1–2 screenshots)
  - Creating a new account (1 screenshot showing account form)
  - Example RGS codes and account types (table)
- [ ] Task 42: Create `docs/user-guide/bookkeeping/transactions.md` journeydoc covering:
  - Creating a transaction (1–2 screenshots)
  - Posting a transaction (1 screenshot)
  - Reversing a transaction with approval (1 screenshot showing approval dialog)
- [ ] Task 43: Create `docs/user-guide/bookkeeping/documents.md` journeydoc covering:
  - Uploading a document (1–2 screenshots)
  - Filing a document with approval (1 screenshot)
  - Automatic archive workflow after 7 years (explanatory text)
- [ ] Task 44: Create API documentation in `docs/api/accounts.md`, `docs/api/transactions.md`, `docs/api/documents.md` with:
  - Endpoint summary tables (method, path, auth, description)
  - Request/response examples (JSON)
  - Error codes and meanings
  - Filtering/pagination examples
- [ ] Task 45: Run `composer test` to ensure all unit + integration tests pass; run `npm run lint` to ensure Vue component linting passes; verify `node tests/validate-manifest.js` exits 0
- [ ] Task 46: Create a PR with all implementation changes, link to the spec proposal in PR description, request review from @shillinq-team, @bookkeeping-team, and @product
- [ ] Task 47: Product personas (bookkeeper, auditor, administrator) review the implementation and confirm:
  - Chart-of-accounts tree is intuitive and hierarchical navigation is fast
  - Transaction posting workflow is clear and post/reversal approval gates are enforced
  - Document upload and filing workflow supports typical invoicing and receipt scenarios
  - Approval-workflow interactions are non-blocking and appear at the right workflow moments
- [ ] Task 48: Compliance review confirms:
  - Audit-trail immutability is enforced (records cannot be modified post-creation)
  - RBAC enforcement matches REQ-WBSO-005 (roles and read/write permissions aligned)
  - 7-year document retention rule is implemented per Archiefwet 1995
  - All seed data is marked as synthetic and can be purged from demo instances

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
