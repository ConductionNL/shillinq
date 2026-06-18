---
status: done
---

# shillinq-bank-statement-wizard Specification

## Purpose
Provides a dashboard-launched modal wizard that guides a bookkeeper through importing a bank statement (CAMT.053, MT940, or CSV) without leaving the Financial overview. The wizard offers format-specific export guidance and a PSD2 discoverability link, maps the statement IBAN to a GL account with remembered mappings, posts the file to a server-administration-scoped import endpoint that persists the statement and its lines, and shows an import summary before refreshing the dashboard payables and receivables widgets.
## Requirements
### Requirement: REQ-BSW-001 — Entry point launches the wizard

The **Import bank** button in `FinancialDashboardActions.vue` SHALL open the
`BankStatementWizard` modal instead of navigating to the bank reconciliation
page. The dashboard context (open KPIs, payables and receivables widgets) MUST
remain visible behind the modal so the bookkeeper never loses their place.

#### Scenario: Clicking Import bank opens the wizard

- **Given** the bookkeeper is on the Financial overview dashboard
- **When** they click the **Import bank** action button
- **Then** the `BankStatementWizard` `NcDialog` opens on step 1
- **And** the dashboard is still mounted behind the modal (no route change)

#### Scenario: Closing the wizard returns to the dashboard intact

- **Given** the wizard is open
- **When** the bookkeeper closes it without importing
- **Then** the wizard emits `close`, the modal disappears
- **And** the Financial overview is shown unchanged

@e2e exclude The wizard modal and its launch button are net-new UI shipped in
this change; the deep-e2e harness for the bank-statement flow is added in a
follow-up slice. Component behaviour is covered by the vitest spec
`tests/vitest/bankStatementWizard.spec.js`.

### Requirement: REQ-BSW-002 — Step 1: file format selection

The wizard SHALL open with a format picker offering **CAMT.053 XML**, **MT940**
and **CSV**. Selecting a format MUST show format-specific export instructions
before the file picker is presented. The step MUST also offer a discoverable
**Connect via PSD2** link that routes to *Settings → Bank connections* so the
operator can choose automatic imports instead of a manual upload.

#### Scenario: Format selection reveals format-specific guidance

- **Given** the wizard is open on step 1
- **When** the bookkeeper selects **CAMT.053 XML**
- **Then** ING/Rabobank/ABN AMRO export instructions for CAMT.053 are shown
- **And** a file picker accepting the chosen format becomes available

#### Scenario: PSD2 link is discoverable from the import flow

- **Given** the wizard is open on step 1
- **When** the bookkeeper clicks **Connect via PSD2**
- **Then** they are routed to *Settings → Bank connections* (no new page is
  built in this change; the link is discoverability only)

### Requirement: REQ-BSW-003 — Step 2: account mapping with IBAN memory

After the file is chosen, the wizard SHALL show the statement's bank account
(IBAN / name) and ask the operator to map it to a GL account from their chart
of accounts via an accessible `NcSelect` (carrying an `inputLabel`). For repeat
imports from the same IBAN the mapping MUST be remembered and this step skipped.

#### Scenario: Operator maps the statement IBAN to a GL account

- **Given** the wizard is on step 2 with a parsed statement IBAN
- **When** the operator selects a GL account in the mapping `NcSelect`
- **Then** the chosen `glAccountId` is captured for the import

#### Scenario: A remembered IBAN skips the mapping step

- **Given** the statement IBAN already has a stored mapping in
  `localStorage['shillinq:bank-iban-map']`
- **When** the operator advances past file selection
- **Then** step 2 is skipped and the remembered GL account is reused

### Requirement: REQ-BSW-004 — Import endpoint parses and persists the statement

The wizard SHALL POST the chosen file to `POST /api/v1/bank-statements/import`.
The endpoint MUST parse the file with `StatementParser`, and on success create
exactly one `BankStatement` plus one `BankStatementLine` per parsed line, each
scoped to the **server-resolved** administration (the endpoint MUST NOT accept a
client-supplied `administrationId`). It MUST return the created `statementId`,
the `transactionCount`, and the matched / unmatched counts. Auto-matching is the
reconciliation engine's job, so the import endpoint honestly reports
`matchedCount: 0` and `unmatchedCount: transactionCount`. Unparseable or empty
input MUST yield HTTP 422; malformed input MUST yield 400. The summary screen
displays `transactionCount` and the matched / unmatched breakdown.

#### Scenario: A valid CAMT.053 upload creates a statement and lines

- **Given** a well-formed CAMT.053 file with N entries
- **When** the file is POSTed to `/api/v1/bank-statements/import`
- **Then** the endpoint creates one `BankStatement` (transactionCount = N) and
  N `BankStatementLine` records, all scoped to the server-resolved
  administration
- **And** returns `{ statementId, transactionCount: N, matchedCount: 0,
  unmatchedCount: N }`

#### Scenario: Unparseable input is rejected

- **Given** an empty or unparseable upload
- **When** it is POSTed to the import endpoint
- **Then** the endpoint returns HTTP 422 with a clear error and creates no
  objects

#### Scenario: The endpoint never trusts a client administrationId

- **Given** a request body carrying an `administrationId` field
- **When** the import runs
- **Then** the persisted objects use the server-resolved administration, never
  the client-supplied value (IDOR-safe per ADR-005)

### Requirement: REQ-BSW-005 — Import summary and return to dashboard

After parsing, the wizard SHALL show an import summary (transaction count plus
the matched / unmatched breakdown) with an **Import and review matches** action
and a **Cancel** action. **Import and review matches** is the only navigation
away from the dashboard: it closes the modal, persists a sessionStorage
breadcrumb flag, and routes to the bank reconciliation page. After an import
that returns to the dashboard, the wizard MUST emit `cn:widget:refresh` for the
payables and receivables widgets so they reload.

#### Scenario: Import and review navigates to reconciliation

- **Given** the wizard is on the summary step after a successful import
- **When** the operator clicks **Import and review matches**
- **Then** the modal closes, a sessionStorage breadcrumb flag is set
- **And** the operator is routed to the bank reconciliation page

#### Scenario: Returning to the dashboard refreshes the widgets

- **Given** an import completed and the operator returns to the Financial
  overview
- **When** the dashboard is shown
- **Then** the payables and receivables widgets are refreshed via
  `cn:widget:refresh`

@e2e exclude Net-new wizard UI in this change; the summary + navigation flow is
covered by the vitest spec until the deep-e2e slice lands.

### Requirement: REQ-BSW-006 — IBAN → account memory

The wizard SHALL persist a `{ [iban]: glAccountId }` map in `localStorage` under
the key `shillinq:bank-iban-map`, populated on each successful import and used to
skip step 2 on repeat imports. Entries MUST expire after one year and the
helpers MUST never throw when `localStorage` is unavailable.

#### Scenario: A successful import remembers the IBAN mapping

- **Given** an import mapping IBAN `NL91ABNA0417164300` to GL account `10100`
- **When** the import completes successfully
- **Then** `localStorage['shillinq:bank-iban-map']` contains
  `{ "NL91ABNA0417164300": "10100" }` with a saved timestamp

#### Scenario: Mappings older than one year are ignored

- **Given** a stored IBAN mapping whose timestamp is older than one year
- **When** the wizard reads the map for that IBAN
- **Then** the stale entry is treated as absent and the operator is asked to map
  again

