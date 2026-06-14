# Spec — Financial Administration Foundation

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T1 (Foundation)  
**Primary spec:** bookkeeping-financial-administration

---

## Overview

This spec establishes the foundational data registers (`Account`, `Transaction`, `Document`) and document lifecycle management that enable all downstream financial administration specs in Shillinq.

---

## REQ-WBSO-001: Account Register Schema

The app MUST declare an `Account` register in `lib/Settings/shillinq_register.json` conforming to the RGS (Referentie Grootboek Schema) standard for Dutch chart-of-accounts.

**GIVEN** a Dutch SME bookkeeping administration  
**WHEN** a bookkeeper creates an account in the chart-of-accounts  
**THEN** the account is persisted with the following properties:
- `accountNumber` (string, required): RGS-style code (e.g., "1000", "4100"). MUST be unique per administration.
- `name` (string, required): Human-readable account name (e.g., "Kas en bank", "Omzet diensten").
- `accountType` (enum, required): One of `assets`, `liabilities`, `equity`, `revenue`, `expenses` per Dutch Accounting Standard.
- `parentAccountNumber` (string, optional): FK to parent Account.accountNumber for hierarchy navigation (e.g., "1000" has parent "1"). If null, account is a root.
- `status` (enum, required): One of `active`, `blocked`, `archived`. Default: `active`.
- `currency` (string, required): ISO 4217 code. Default: `EUR`. Phase 1 allows EUR only.
- `administrationId` (string, required): FK to Administration entity.
- `description` (string, optional): Free-text notes on the account purpose.
- `vatApplicable` (boolean, optional): Whether VAT applies to transactions on this account. Default: `false`.

**Account hierarchy validation:**
- Parent must exist and have `status: active` to be referenced by a child.
- Hierarchy depth capped at 5 levels (enforced by constraint in schema).
- Circular references forbidden (child cannot be ancestor of parent).

---

## REQ-WBSO-002: Transaction Register Schema

The app MUST declare a `Transaction` register in `lib/Settings/shillinq_register.json` to record financial events.

**GIVEN** a bookkeeper recording a financial event (invoice received, payment made, GL entry)  
**WHEN** the transaction is created  
**THEN** the transaction is persisted with the following properties:
- `transactionNumber` (string, required): Unique transaction identifier (e.g., "INV-2026-001", "REC-2026-001"). MUST be unique per administration per year.
- `transactionType` (enum, required): One of `invoice`, `receipt`, `journal-entry`, `credit-note`, `debit-note`.
- `transactionDate` (date, required): Date the transaction occurred (ISO 8601). MUST be in the active fiscal year.
- `amount` (decimal, required): Transaction amount in base currency (EUR), rounded to 2 decimal places. MUST be ≥ 0.
- `description` (string, required): Human-readable description of the transaction (e.g., "Invoice from supplier XYZ", "Petty cash withdrawal").
- `status` (enum, required): One of `draft`, `posted`, `reversed`. Default: `draft`. Immutable once `posted`.
- `administrationId` (string, required): FK to Administration entity.
- `createdAt` (datetime, required): Timestamp of creation. Set by system.
- `createdBy` (string, required): User ID of creator. Set by system.

**Transaction state rules:**
- New transactions start in `draft` state.
- `draft → posted` transition requires bookkeeper authorization and GL posting confirmation.
- `posted → reversed` transition requires approval-workflow (admin or auditor only). A reversal creates a new transaction with `status: reversed` and a negating amount-entry to the GL.
- Once `posted`, the original transaction record is immutable; reversals are recorded as separate transactions.

---

## REQ-WBSO-003: Document Register Schema

The app MUST declare a `Document` register in `lib/Settings/shillinq_register.json` to track bookkeeping documents.

**GIVEN** a bookkeeper attaching a file (invoice PDF, receipt scan, contract) to a transaction or account  
**WHEN** the document is uploaded and filed  
**THEN** the document is persisted with the following properties:
- `documentType` (enum, required): One of `invoice`, `receipt`, `contract`, `tax-form`, `bank-statement`, `memo`.
- `documentNumber` (string, required): Unique document identifier (e.g., "INV-2026-001", "FORM-IB2026"). MUST be unique per administration.
- `documentDate` (date, required): Date the document was created or issued (ISO 8601).
- `status` (enum, required): One of `draft`, `filed`, `archived`. Default: `draft`. Lifecycle: `draft → filed → archived`.
- `fileReference` (string, optional): URI pointing to the file in docudesk storage (e.g., "docudesk://invoices/inv-2026-001.pdf").
- `administrationId` (string, required): FK to Administration entity.
- `createdAt` (datetime, required): Timestamp of creation. Set by system.
- `createdBy` (string, required): User ID of creator. Set by system.
- `filedAt` (datetime, optional): Timestamp when document transitioned to `filed`. Set by system.

**Document lifecycle:**
- Documents start in `draft` state. A draft document with no `fileReference` is incomplete.
- `draft → filed` transition requires: (1) fileReference is set (file uploaded), (2) documentNumber is set, (3) approval-workflow passes (if configured).
- `filed → archived` transition is automatic after 7 years per Archiefwet 1995, or manual by authorized user with approval-workflow.
- Archived documents MAY be purged from docudesk storage per retention policy, but metadata remains for audit trail.

---

## REQ-WBSO-004: Audit Trail Immutability

The app MUST enforce immutable audit-trail logging on all Account, Transaction, and Document changes per ADR-022.

**GIVEN** any create, update, or delete operation on an Account, Transaction, or Document  
**WHEN** the operation completes  
**THEN** an immutable audit log entry is created recording:
- Entity type and ID
- Operation (create, update, delete)
- User ID and timestamp
- Before/after snapshots (for updates)
- Reason / approval note (if applicable)

Audit log entries MUST NOT be modifiable or deletable. System MUST prevent accidental purging of audit trail even when documents are archived.

---

## REQ-WBSO-005: RBAC for Accounts and Transactions

The app MUST enforce role-based access control on financial data per ADR-023.

**GIVEN** a user with a specific role (bookkeeper, auditor, administrator)  
**WHEN** the user attempts to read or write Account, Transaction, or Document data  
**THEN** access is controlled by:
- **Read**: Roles `bookkeeper`, `auditor`, `administrator` can read all accounts and transactions within their organization.
- **Write**:
  - `bookkeeper` can create/edit `draft` transactions and documents; cannot modify `posted` transactions.
  - `administrator` can create/edit accounts, approve `draft → posted` transitions, and approve reversals.
  - `auditor` can read all data and approve document archive transitions, but cannot create or edit.
- **Sensitive account access** (e.g., bank accounts, tax payables): restricted to `bookkeeper` and `administrator` only. `auditor` can read but not write.

---

## REQ-WBSO-006: Account Hierarchy Navigation

The app MUST support querying and displaying the chart-of-accounts hierarchy.

**GIVEN** a list of all accounts in the administration  
**WHEN** a bookkeeper views the chart-of-accounts tree  
**THEN**:
1. Accounts are displayed in a hierarchical tree, with parent accounts collapsible and child accounts nested.
2. Each account shows: accountNumber, name, accountType, status, and children count.
3. Clicking a parent expands its children; clicking a child navigates to the account detail view.
4. The detail view shows: full properties, parent account link, all child accounts, and an "Add child account" action (if user has write permission).

---

## REQ-WBSO-007: Document Filing Workflow

The app MUST support document lifecycle transitions with optional approval-workflow.

**GIVEN** a document in `draft` state with a file uploaded (fileReference set)  
**WHEN** a bookkeeper clicks "File this document"  
**THEN**:
1. The system validates: fileReference is set, documentNumber is set, status is `draft`.
2. If approval-workflow is configured for this document type, the system presents an approval-request dialog (approver selection, due date, message).
3. Upon approval, the document transitions to `filed` and `filedAt` is set to the approval timestamp.
4. An audit-trail entry is created noting the filing approval and approver ID.

---

## REQ-WBSO-008: Transaction Post and Reversal Workflow

The app MUST support transaction posting and reversal with approval gates.

**Posting:** 
**GIVEN** a transaction in `draft` state  
**WHEN** a bookkeeper clicks "Post transaction"  
**THEN**:
1. The system validates: amount > 0, transactionDate is in active fiscal year, description is not empty.
2. The system triggers GL posting logic (deferred to tier-2 `bookkeeping-general-ledger`); if GL posting succeeds, transaction status becomes `posted`.
3. An audit-trail entry records the posting and GL posting confirmation.
4. Once `posted`, the transaction is immutable.

**Reversal:**
**GIVEN** a transaction in `posted` state  
**WHEN** an administrator clicks "Reverse transaction"  
**THEN**:
1. An approval-workflow is triggered (admin or auditor approval required).
2. Upon approval, a new transaction is created with:
   - `transactionNumber`: original-number + "-REV"
   - `amount`: negation of original amount
   - `description`: "Reversal of " + original description
   - `status`: `reversed`
3. The original transaction remains `posted` in the audit trail; the reversal appears as a separate posted transaction.
4. Both transactions are linked via audit trail for reconciliation.

---

## REQ-WBSO-009: Document Archive Workflow (7-Year Retention)

The app MUST enforce 7-year document retention per Dutch Archiefwet 1995 and support manual archival.

**GIVEN** a document in `filed` state  
**WHEN** a scheduled job runs (once per night) or an administrator manually initiates archival  
**THEN**:
1. If the document's `documentDate` is > 7 years in the past, or if manual archive is triggered:
   - An approval-workflow is shown (auditor or compliance officer approval required).
   - Upon approval, the document transitions to `archived`.
   - An audit-trail entry records the archival timestamp and approver.
2. Archived documents remain in the audit trail but MAY be purged from active document storage (docudesk quota management).
3. The system MUST NOT allow a user to delete or purge archived documents without a separate compliance audit approval (out of scope for this spec; recorded for future compliance module).

---

## REQ-WBSO-010: Manifest Navigation Entry

The app MUST register a navigation entry for Bookkeeping in the manifest.

**GIVEN** the app is loaded in Nextcloud  
**WHEN** a bookkeeper views the app menu  
**THEN**:
1. A "Bookkeeping" menu entry appears (or is added to an existing Financial menu) behind a general feature flag (e.g., `featureFlags.bookkeeping`).
2. The menu entry navigates to `/apps/shillinq/bookkeeping` showing:
   - Chart of Accounts (tree view with account list)
   - Transactions (table view with date, amount, status filters)
   - Documents (table view with type, status filters)
   - Reports section (deferred to tier-2+; placeholder nav entry)

---

## REQ-WBSO-011: Seed Data for Testing

The app MUST provide seed data for testing and demo purposes.

**GIVEN** a development or demo environment  
**WHEN** the repair step `SettingsLoadService::load()` runs  
**THEN**:
1. Default chart-of-accounts is imported (3–5 sample RGS accounts covering assets, revenue, expenses).
2. 2–3 sample transactions are created (one posted, one draft, one reversed) for demo data.
3. 1 sample document is created (invoice PDF reference) in filed state for demo purposes.
4. All seed data is marked with `_synthetic: true` in the audit trail so demo instances can be purged.

---

## Test Scenarios

### Scenario 1: Create and Post a Transaction

**GIVEN** a bookkeeper viewing the Transactions list  
**WHEN** they click "Create Transaction" and fill in:
- transactionType: "invoice"
- transactionDate: "2026-05-21"
- amount: 1500.00
- description: "Invoice from supplier ABC"  
**AND** click "Save"  
**THEN**:
1. The transaction is created with status `draft`.
2. The system shows "Transaction saved. Ready to post?" prompt.
3. Clicking "Post" triggers the post workflow (REQ-WBSO-008).
4. Upon success, the transaction shows status `posted` and is highlighted (immutable).

### Scenario 2: File a Document with Approval

**GIVEN** a bookkeeper with an uploaded invoice PDF (fileReference set)  
**WHEN** they click "File Document"  
**AND** an approval-workflow is triggered (admin approval required)  
**AND** an administrator approves  
**THEN**:
1. The document transitions from `draft` to `filed`.
2. The document detail view shows `filedAt` timestamp and approver name.
3. The document appears in "Filed Documents" tab.

### Scenario 3: Reverse a Posted Transaction

**GIVEN** a posted transaction (status `posted`)  
**WHEN** an administrator clicks "Reverse" and submits an approval request  
**AND** an auditor approves the reversal  
**THEN**:
1. A new transaction is created with status `reversed` and negated amount.
2. The original and reversal transactions are linked in the audit trail.
3. The GL impact is a net-zero pair (original + reversal).

### Scenario 4: Archive a Filed Document (Automatic)

**GIVEN** a document with documentDate > 7 years ago in `filed` state  
**WHEN** the nightly archival job runs  
**AND** an approval-workflow is triggered (compliance officer approval)  
**AND** approval is granted  
**THEN**:
1. The document transitions from `filed` to `archived`.
2. The audit trail shows the archival timestamp and approver.
3. The document is removable from active storage (optional cleanup).

---

## Acceptance Criteria

- [ ] All three schemas (Account, Transaction, Document) are declared in `shillinq_register.json` per ADR-031
- [ ] Account hierarchy validation (depth ≤ 5, no circular refs) is enforced at schema level
- [ ] Transaction post and reversal workflows are defined with approval-workflow bindings
- [ ] Document lifecycle (draft → filed → archived) is declared with `x-openregister-lifecycle`
- [ ] RBAC roles (bookkeeper, auditor, administrator) are mapped to read/write permissions
- [ ] Audit-trail immutability is confirmed (records cannot be modified post-creation)
- [ ] Seed data (3–5 accounts, 2–3 transactions, 1 document) is loaded on `SettingsLoadService::load()`
- [ ] Manifest navigation entry is registered for Bookkeeping
- [ ] All test scenarios (S1–S4) pass with manual QA verification
