# Design: Accounts Payable & Receivable — Shillinq

## Architecture Overview

This change introduces the complete accounts payable and receivable layer for Shillinq. All entities follow the OpenRegister thin-client pattern: no custom database tables, all data via `ObjectService`. PHP services encapsulate domain logic and are injected into controllers and background jobs. The UBL/Peppol ingestion pipeline, two-way/three-way matching engine, credit note workflow, approval routing, and R2P payment initiation are implemented as dedicated PHP services.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (Account, Contact, Invoice, InvoiceLine,
    │                          JournalEntry, JournalLine, Payment,
    │                          PaymentTerm, Receipt CRUD)
    │
    └─ Shillinq OCS API
            ├─ InvoiceController        (post, credit-note, reject, approve)
            ├─ MatchingController       (two-way match, three-way match trigger)
            ├─ UblController            (Peppol ingest endpoint)
            ├─ PaymentController        (R2P initiate, status webhook)
            └─ AccountContextController (active account switch)
                    │
                    └─ PHP Services
                            ├─ UblIngestionService
                            ├─ InvoiceMatchingService
                            ├─ CreditNoteService
                            ├─ ApprovalRoutingService
                            ├─ PaymentTermService
                            ├─ R2PService
                            └─ TimesheetVerificationService
                    │
                    └─ Background Jobs
                            ├─ InvoiceOverdueJob      (daily)
                            ├─ ApprovalEscalationJob  (daily)
                            └─ PeppolIngestJob        (every 15 min)
```

## Data Model

### Account (`schema:FinancialProduct`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| accountNumber | string | Yes | — | GL account number, e.g. 1000, 4000 |
| name | string | Yes | — | Account name, e.g. Kas, Debiteuren |
| nameNl | string | No | — | Dutch display name |
| accountType | string | Yes | — | Enum: asset, liability, equity, revenue, expense |
| accountSubType | string | No | — | Enum: current-asset, fixed-asset, accounts-receivable, accounts-payable, bank, cash, equity, revenue, cost-of-sales, operating-expense |
| parentAccount | string | No | — | Parent account number for hierarchy |
| taxCode | string | No | — | Default tax code for transactions |
| isActive | boolean | Yes | true | Accepts new transactions |
| isBankAccount | boolean | No | false | Bank or cash account flag |
| normalBalance | string | Yes | — | Enum: debit, credit |
| description | string | No | — | Usage notes |

### Contact (`schema:Person`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| displayName | string | Yes | — | Person or company name |
| contactType | string | Yes | — | Enum: customer, supplier, both, employee, other |
| isCompany | boolean | Yes | false | Company or individual flag |
| email | string | No | — | Primary email |
| phone | string | No | — | Primary phone |
| website | string | No | — | Website URL |
| kvkNumber | string | No | — | KvK (Kamer van Koophandel) number |
| vatNumber | string | No | — | BTW identification number |
| iban | string | No | — | IBAN bank account |
| streetAddress | string | No | — | Street and house number |
| postalCode | string | No | — | Postal code |
| city | string | No | — | City |
| country | string | No | NL | ISO 3166-1 alpha-2 |
| defaultPaymentTermDays | integer | No | 30 | Payment term in days |
| creditLimit | number | No | — | Maximum outstanding credit |
| notes | string | No | — | Internal notes |

### Invoice (`schema:Invoice`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| invoiceNumber | string | Yes | — | Auto-generated unique identifier |
| invoiceType | string | Yes | — | Enum: sales, purchase, credit, proforma |
| invoiceDate | datetime | Yes | — | Date of issue |
| dueDate | datetime | Yes | — | Payment due date (from PaymentTerm) |
| subtotal | number | Yes | — | Sum of lines excl. tax |
| taxAmount | number | Yes | — | Total BTW amount |
| totalAmount | number | Yes | — | Total incl. tax |
| currency | string | Yes | EUR | ISO 4217 currency code |
| exchangeRate | number | No | 1.0 | Exchange rate to base currency |
| paymentStatus | string | Yes | draft | Enum: draft, sent, viewed, partial, paid, overdue, void |
| paidAmount | number | No | 0 | Amount already paid |
| paymentReference | string | No | — | Betalingskenmerk (structured reference) |
| poReference | string | No | — | Purchase order reference |
| notes | string | No | — | Internal notes |
| termsAndConditions | string | No | — | Printed terms text |
| peppolId | string | No | — | Peppol participant ID |
| ublFormat | boolean | No | false | Send as UBL e-invoice |
| matchStatus | string | No | unmatched | Enum: unmatched, two-way-matched, three-way-matched, mismatch, partial |
| matchTolerance | number | No | 2.0 | Tolerance percentage for matching |
| creditedInvoiceId | string | No | — | Original invoice ID (for credit notes) |
| rejectionReason | string | No | — | Reason for invoice rejection |

### InvoiceLine (`schema:OrderItem`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| lineNumber | integer | Yes | — | Sequence number |
| description | string | Yes | — | Item or service description |
| quantity | number | Yes | — | Quantity |
| unitPrice | number | Yes | — | Price per unit excl. tax |
| taxRate | number | No | 21.0 | BTW percentage |
| lineTotal | number | Yes | — | Line total incl. tax |
| accountCode | string | No | — | GL account code |
| costCenterCode | string | No | — | Cost centre allocation |
| taakveld | string | No | — | Municipal taakveld code (BBV) |

### JournalEntry (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| entryNumber | string | Yes | — | Auto-generated journal entry number |
| entryDate | datetime | Yes | — | Posting date |
| description | string | Yes | — | Memo or entry description |
| entryType | string | Yes | — | Enum: manual, auto, reversal, closing |
| totalDebit | number | Yes | — | Sum of debit lines |
| totalCredit | number | Yes | — | Sum of credit lines |
| posted | boolean | Yes | false | Whether entry is posted to ledger |
| fiscalYear | string | Yes | — | Fiscal year (e.g. 2026) |
| period | string | Yes | — | Accounting period (e.g. 2026-05) |

### JournalLine (`schema:MonetaryAmount`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| accountCode | string | Yes | — | GL account code |
| debitAmount | number | No | 0 | Debit amount (0 if credit line) |
| creditAmount | number | No | 0 | Credit amount (0 if debit line) |
| description | string | No | — | Line description |
| costCenterCode | string | No | — | Cost centre allocation |
| projectCode | string | No | — | Project allocation |

### Payment (`schema:Order`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| paymentRequestId | string | Yes | — | Unique R2P request identifier |
| referenceNumber | string | No | — | External bank reference |
| amount | number | Yes | — | Payment amount |
| currency | string | Yes | EUR | ISO 4217 currency code |
| status | string | Yes | pending | Enum: pending, approved, rejected, completed |
| expiresAt | datetime | No | — | Expiry of payment request |
| createdAt | datetime | Yes | — | Request creation timestamp |

### PaymentTerm (`custom`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| name | string | Yes | — | Term name (e.g. Net 30, Net 60) |
| daysDue | number | Yes | — | Days until payment is due |
| description | string | No | — | Description of payment term |
| isDefault | boolean | No | false | Whether this is the system default |

### Receipt (`schema:Receipt`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| receiptNumber | string | No | — | Auto-generated identifier |
| amount | number | Yes | — | Receipt amount |
| date | datetime | Yes | — | Receipt date |
| vendor | string | Yes | — | Vendor or merchant name |
| description | string | No | — | Description or notes |
| uploadMethod | string | No | web | Enum: mobile, web, email |
| filePath | file | No | — | Attached image or document |
| status | string | Yes | pending | Enum: pending, verified, rejected |
| receiptType | string | No | goods | Enum: goods, services |
| timesheetId | string | No | — | Linked timesheet object ID (services) |
| verifiedHours | number | No | — | Approved hours (service receipts) |
| verifiedRate | number | No | — | Approved hourly rate (service receipts) |

## Frontend Structure

### Views

| Entity | Index view | Detail view | Form dialog |
|--------|-----------|------------|------------|
| Account | `src/views/account/AccountIndex.vue` | `src/views/account/AccountDetail.vue` | `src/views/account/AccountForm.vue` |
| Contact | `src/views/contact/ContactIndex.vue` | `src/views/contact/ContactDetail.vue` | `src/views/contact/ContactForm.vue` |
| Invoice | `src/views/invoice/InvoiceIndex.vue` | `src/views/invoice/InvoiceDetail.vue` | `src/views/invoice/InvoiceForm.vue` |
| InvoiceLine | `src/views/invoiceLine/InvoiceLineIndex.vue` | `src/views/invoiceLine/InvoiceLineDetail.vue` | `src/views/invoiceLine/InvoiceLineForm.vue` |
| JournalEntry | `src/views/journalEntry/JournalEntryIndex.vue` | `src/views/journalEntry/JournalEntryDetail.vue` | `src/views/journalEntry/JournalEntryForm.vue` |
| JournalLine | `src/views/journalLine/JournalLineIndex.vue` | `src/views/journalLine/JournalLineDetail.vue` | `src/views/journalLine/JournalLineForm.vue` |
| Payment | `src/views/payment/PaymentIndex.vue` | `src/views/payment/PaymentDetail.vue` | `src/views/payment/PaymentForm.vue` |
| PaymentTerm | `src/views/paymentTerm/PaymentTermIndex.vue` | `src/views/paymentTerm/PaymentTermDetail.vue` | `src/views/paymentTerm/PaymentTermForm.vue` |
| Receipt | `src/views/receipt/ReceiptIndex.vue` | `src/views/receipt/ReceiptDetail.vue` | `src/views/receipt/ReceiptForm.vue` |

### Pinia Stores

Each store uses `createObjectStore` from `@conduction/nextcloud-vue`:

- `src/store/modules/account.js`
- `src/store/modules/contact.js`
- `src/store/modules/invoice.js`
- `src/store/modules/invoiceLine.js`
- `src/store/modules/journalEntry.js`
- `src/store/modules/journalLine.js`
- `src/store/modules/payment.js`
- `src/store/modules/paymentTerm.js`
- `src/store/modules/receipt.js`

### Multi-Account Context Store

`src/store/modules/accountContext.js` — tracks the active `Account` object ID. All entity fetch calls include an `accountId` filter. The active account is displayed in a `NcSelect` dropdown in the top navigation. Switching accounts re-fetches all active entity lists. Maximum 10 accounts per user enforced via `AccessControl`.

### Key UI Components

- **InvoiceMatchPanel** (`src/components/InvoiceMatchPanel.vue`) — shows two-way/three-way match status, PO details, receipt details, and mismatch reasons. Used in `InvoiceDetail.vue`.
- **CreditNoteButton** (`src/components/CreditNoteButton.vue`) — creates a linked credit note from a posted invoice with a single click; opens `InvoiceForm.vue` pre-populated with reversed lines.
- **AccountSwitcher** (`src/components/AccountSwitcher.vue`) — `NcSelect` bound to `accountContext` store; appears in `NavigationHeader.vue`.
- **R2PInitiateButton** (`src/components/R2PInitiateButton.vue`) — visible on approved invoices; calls `PaymentController@initiate` and shows status badge.
- **BudgetWarningBanner** (`src/components/BudgetWarningBanner.vue`) — shown in `InvoiceLineForm.vue` when the coded amount would cause a cost centre overspend.

## Service Design

### UblIngestionService

Parses UBL 2.1 XML using PHP's `DOMDocument`. Extracts:
- `cbc:ID` → `invoiceNumber`
- `cac:AccountingSupplierParty` → matched to `Contact` by VAT number or created as new
- `cac:InvoiceLine` → creates `InvoiceLine` objects
- `cac:OrderReference` → `poReference` for matching

On validation failure: quarantines the document to `Document` schema with `status: quarantine` and sends Nextcloud notification to the AP clerk group.

### InvoiceMatchingService

**Two-way match**: compares `Invoice.poReference` to `PurchaseOrder.poNumber`, verifies `Invoice.contactId` matches PO supplier, checks `Invoice.totalAmount` within `matchTolerance` of PO total.

**Three-way match**: after two-way match succeeds, fetches `Receipt` objects linked to the PO. Sums `Receipt.amount` (goods) or `verifiedHours × verifiedRate` (services). Compares to invoiced amount within tolerance.

Outcomes:
- `two-way-matched` or `three-way-matched` → moves to payment queue
- `mismatch` → assigns to AP clerk with reason code
- Unknown PO → sets `matchStatus: unmatched`, adds to unmatched queue

### CreditNoteService

On `POST /api/v1/invoices/{id}/credit-note`:
1. Reads original `Invoice` and its `InvoiceLine` objects
2. Creates new `Invoice` with `invoiceType: credit`, `creditedInvoiceId: {id}`, all line amounts negated
3. Generates reversal `JournalEntry` with `entryType: reversal` mirroring the original entry's debits and credits swapped
4. Sets `paymentStatus: void` on the original invoice if fully credited

### ApprovalRoutingService

On invoice submission where `totalAmount > clerkApprovalLimit`:
1. Looks up `Budget` for the coded cost centre
2. Finds the `Budget.ownerId` as the budget holder
3. Creates `ApprovalWorkflow` entry and sends Nextcloud notification
4. `ApprovalEscalationJob` checks daily: if `ApprovalWorkflow.createdAt` is more than 5 working days ago and `status: pending`, sends escalation notification to the budget holder's line manager (`Budget.lineManagerId`)

### PaymentTermService

`calculateDueDate(invoiceDate, paymentTermId)`:
- Fetches `PaymentTerm.daysDue`
- Returns `invoiceDate + daysDue` as `dueDate`
- Called automatically on `Invoice` creation if `paymentTermId` is present on the linked `Contact`

### R2PService

`initiate(invoiceId)`:
1. Fetches `Invoice` and linked `Contact.iban`
2. Constructs an open banking R2P payload (amount, reference, IBAN, expiry)
3. Posts to the configured open banking endpoint (set in `AppSettings`)
4. Creates `Payment` object with `status: pending` and stores `paymentRequestId`
5. Webhook receiver at `POST /api/v1/payments/webhook` updates `Payment.status` and marks `Invoice.paymentStatus: paid` on completion

### TimesheetVerificationService

For `Receipt` objects with `receiptType: services`:
- Fetches the linked timesheet (`timesheetId`) from the Nextcloud timesheet integration
- Compares `verifiedHours × verifiedRate` to `Invoice.totalAmount`
- If within `matchTolerance`: sets `Receipt.status: verified`
- If outside tolerance: sets `Receipt.status: rejected`, notifies the AP clerk

## Background Jobs

### InvoiceOverdueJob (daily)

Fetches all `Invoice` objects where `dueDate < today` and `paymentStatus` is `sent` or `partial`. Sets `paymentStatus: overdue` and sends Nextcloud notification to the assigned AP clerk.

### ApprovalEscalationJob (daily)

Fetches `ApprovalWorkflow` objects where `status: pending`, `entityType: invoice`, and `createdAt < today - 5 working days`. Sends escalation notification to `Budget.lineManagerId` and adds an escalation entry to the workflow audit log.

### PeppolIngestJob (every 15 minutes)

Polls the configured Peppol access point inbox for new UBL e-invoices. For each new message, calls `UblIngestionService` and then `InvoiceMatchingService`. Sets a `lastPolledAt` timestamp in `AppSettings`.

## Seed Data (ADR-016)

### Account seed objects

```json
[
  {
    "accountNumber": "1300",
    "name": "Debiteuren",
    "nameNl": "Handelsdebiteuren",
    "accountType": "asset",
    "accountSubType": "accounts-receivable",
    "isActive": true,
    "isBankAccount": false,
    "normalBalance": "debit",
    "description": "Vorderingen op klanten uit hoofde van verkopen op rekening."
  },
  {
    "accountNumber": "1600",
    "name": "Crediteuren",
    "nameNl": "Handelscrediteuren",
    "accountType": "liability",
    "accountSubType": "accounts-payable",
    "isActive": true,
    "isBankAccount": false,
    "normalBalance": "credit",
    "description": "Schulden aan leveranciers uit hoofde van inkopen op rekening."
  },
  {
    "accountNumber": "4000",
    "name": "Inkoopkosten",
    "nameNl": "Inkoopkosten goederen",
    "accountType": "expense",
    "accountSubType": "cost-of-sales",
    "isActive": true,
    "isBankAccount": false,
    "normalBalance": "debit",
    "description": "Directe inkoopkosten van verkochte goederen."
  }
]
```

### Contact seed objects

```json
[
  {
    "displayName": "Bouwbedrijf De Vries B.V.",
    "contactType": "supplier",
    "isCompany": true,
    "email": "inkoop@bouwbedrijfdevries.nl",
    "phone": "+31 20 123 4567",
    "kvkNumber": "12345678",
    "vatNumber": "NL123456789B01",
    "iban": "NL91ABNA0417164300",
    "streetAddress": "Industrieweg 12",
    "postalCode": "1234 AB",
    "city": "Amsterdam",
    "country": "NL",
    "defaultPaymentTermDays": 30
  },
  {
    "displayName": "Gemeente Rotterdam — Interne Dienst",
    "contactType": "customer",
    "isCompany": true,
    "email": "crediteuren@rotterdam.nl",
    "phone": "+31 10 267 1234",
    "kvkNumber": "24370906",
    "vatNumber": "NL001234567B01",
    "iban": "NL86INGB0002445588",
    "streetAddress": "Coolsingel 40",
    "postalCode": "3011 AD",
    "city": "Rotterdam",
    "country": "NL",
    "defaultPaymentTermDays": 30,
    "creditLimit": 500000
  },
  {
    "displayName": "IT Consultancy Jansen",
    "contactType": "supplier",
    "isCompany": false,
    "email": "facturen@jansen-it.nl",
    "phone": "+31 6 12345678",
    "kvkNumber": "98765432",
    "vatNumber": "NL987654321B01",
    "iban": "NL18RABO0123459876",
    "streetAddress": "Keizersgracht 123",
    "postalCode": "1015 CJ",
    "city": "Amsterdam",
    "country": "NL",
    "defaultPaymentTermDays": 14
  }
]
```

### Invoice seed objects

```json
[
  {
    "invoiceNumber": "INK-2026-00142",
    "invoiceType": "purchase",
    "invoiceDate": "2026-04-01T00:00:00Z",
    "dueDate": "2026-05-01T00:00:00Z",
    "subtotal": 25000.00,
    "taxAmount": 5250.00,
    "totalAmount": 30250.00,
    "currency": "EUR",
    "paymentStatus": "sent",
    "paymentReference": "INK2026001420",
    "poReference": "PO-2026-0089",
    "matchStatus": "three-way-matched",
    "ublFormat": true,
    "notes": "Levering bouwmaterialen fase 2"
  },
  {
    "invoiceNumber": "VRK-2026-00078",
    "invoiceType": "sales",
    "invoiceDate": "2026-03-15T00:00:00Z",
    "dueDate": "2026-04-14T00:00:00Z",
    "subtotal": 12400.00,
    "taxAmount": 2604.00,
    "totalAmount": 15004.00,
    "currency": "EUR",
    "paymentStatus": "overdue",
    "paymentReference": "VRK2026000780",
    "matchStatus": "unmatched",
    "notes": "Dienstverlening Q1 2026"
  },
  {
    "invoiceNumber": "CN-2026-00012",
    "invoiceType": "credit",
    "invoiceDate": "2026-04-05T00:00:00Z",
    "dueDate": "2026-04-05T00:00:00Z",
    "subtotal": -3500.00,
    "taxAmount": -735.00,
    "totalAmount": -4235.00,
    "currency": "EUR",
    "paymentStatus": "paid",
    "creditedInvoiceId": "seed-invoice-verk-2026-00060",
    "notes": "Creditnota wegens retourzending artikel 7B"
  }
]
```

### PaymentTerm seed objects

```json
[
  {
    "name": "Net 14",
    "daysDue": 14,
    "description": "Betaling binnen 14 dagen na factuurdatum.",
    "isDefault": false
  },
  {
    "name": "Net 30",
    "daysDue": 30,
    "description": "Betaling binnen 30 dagen na factuurdatum.",
    "isDefault": true
  },
  {
    "name": "Net 60",
    "daysDue": 60,
    "description": "Betaling binnen 60 dagen na factuurdatum.",
    "isDefault": false
  },
  {
    "name": "Net 90",
    "daysDue": 90,
    "description": "Betaling binnen 90 dagen na factuurdatum.",
    "isDefault": false
  },
  {
    "name": "Direct",
    "daysDue": 0,
    "description": "Directe betaling op factuurdatum.",
    "isDefault": false
  }
]
```

### Receipt seed objects

```json
[
  {
    "receiptNumber": "ONT-2026-00089",
    "amount": 25000.00,
    "date": "2026-03-30T00:00:00Z",
    "vendor": "Bouwbedrijf De Vries B.V.",
    "description": "Ontvangstbevestiging bouwmaterialen fase 2 — 500 stuks dakpannen",
    "uploadMethod": "web",
    "status": "verified",
    "receiptType": "goods"
  },
  {
    "receiptNumber": "ONT-2026-00091",
    "amount": 12400.00,
    "date": "2026-04-02T00:00:00Z",
    "vendor": "IT Consultancy Jansen",
    "description": "Dienstontvangstbevestiging — 80 uur advieswerk Q1 2026",
    "uploadMethod": "email",
    "status": "verified",
    "receiptType": "services",
    "verifiedHours": 80,
    "verifiedRate": 155.00
  },
  {
    "receiptNumber": "ONT-2026-00095",
    "amount": 4800.00,
    "date": "2026-04-08T00:00:00Z",
    "vendor": "Kantoorartikelen Visser",
    "description": "Levering kantoorartikelen — 48 dozen printpapier A4",
    "uploadMethod": "mobile",
    "status": "pending",
    "receiptType": "goods"
  }
]
```

## Security Considerations

- All entity endpoints are guarded by Nextcloud `AccessControl` — only users with the `invoicing` role may create or edit `Invoice` and `InvoiceLine` objects.
- UBL ingestion endpoint (`POST /api/v1/ubl/ingest`) requires an API key passed in `X-Shillinq-Token`; this prevents unauthenticated document submission.
- `Payment.paymentRequestId` and R2P payloads are stored encrypted using Nextcloud `ICrypto`; plaintext values are only materialised in memory during API calls.
- `CreditNoteService` requires the original `Invoice` to be in `posted` or `sent` state; draft invoices cannot be credited to prevent misuse.
- `ApprovalWorkflow` audit log entries are append-only; no API endpoint allows deletion or modification of existing log entries.
- Budget overspend warnings are advisory only for clerks; posting of an over-budget invoice requires an explicit override confirmed by a user with the `budget-manager` role.
- Invoice `poReference` is validated server-side to prevent PO number manipulation; if the referenced PO does not belong to the active `Account` context, a 403 is returned.
- Multi-account context switching logs an audit entry (user, from account, to account, timestamp) via `AuditTrail`.
