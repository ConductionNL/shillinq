# Design: receivables-object-request-settlement

Read at shillinq development `8bd50b5b` on 28 September 2026, and larpinq development `f6a55a5` for the requesting change.

## Context

- `PaymentRequest` (`lib/Settings/register.d/ar-invoice-payment-links.json`): `subjectKind` (`invoice`, `object`), `subject`, `requestType` (enum `leges`, `dwangsom`, `deposit`, `other`, `contribution`), `description`, `debtor` (`customerMasterId` or a name and an email), `amount`, `state` (lifecycle `pending`, `authorized`, `captured`, `captured_unapplied`, `failed`, `expired`, `voided`), `invoiceReference`, `confirmationSummary`, `settlements`, `settledAt`, `settledVia` (`provider`, `cash`, `pin`, `bank-transfer`, `waived`, `other`). The `paymentReceived` notification goes to the object's managers and `shillinq-finance`, not to the debtor.
- `lib/Service/ObjectPaymentRequestValidator.php:58` `REQUEST_TYPES` repeats the enum; `validate()` (`:104`) refuses an unknown type and a second pending request per subject and type.
- `lib/Integration/PaymentRequestLeafProvider.php:330-381` `create()` copies `requestType`, `amount`, `currency`, `description`, `paymentGateway`, `debtor` and `dueAt` from the payload. Anything else is dropped.
- `lib/Service/PaymentReconciliationService.php:249` `reconcile()`: on a provider capture of an object request without an invoice it books the receipt (`bookObjectReceipt()`, `:551`, account from `PaymentRevenueAccountResolver::resolve()` by `requestType`), writes `confirmationSummary` (`:638`) and stamps `settledAt`. A request with `invoiceReference` settles that invoice instead, so income is never booked twice (`:335-343`, REQ-SCON-006).
- `lib/Service/PaymentSettlementService.php`: `METHODS` (`:49`), `build()` (`:129`), `stampSettled()` (`:191`), `append()` (`:226`). `lib/Controller/PaymentRequestActionController.php:173` `settle()` records money that arrived another way through them; `send()` (`:102`) mails the payment link with `IMailer`.
- Bank statements: `lib/Controller/BankStatementImportController.php:120` `import()` saves one `BankStatementLine` per parsed line (`:186`; fields `reference`, `remittanceInfo`, `amount`, `valueDate`). `MatchingRule` predicates are rule constants (a regex, an amount), so no declared rule can compare a line with each open request's own reference. The `candidateMatches` aggregation declared on `BankStatementLine` has no implementation in OpenRegister (`lib/` at openregister `555af72` has no `candidateMatches`), and `lib/Service/BankRulePreviewService.php` evaluates rules only as a dry run.
- `ReconciliationMatch` (`lib/Settings/shillinq_register.json`): `targetType` (`ap-invoice`, `ar-invoice`, `gl-transaction`), `targetRefs`, `bankLineRefs`, lifecycle `candidate`, `confirmed`, `rejected`. `lib/Listener/ReconciliationMatchToReportListener.php` shows how shillinq reacts to a confirmed match.
- Invoices from a chargeable: `lib/Service/ContributionInvoiceBuilder.php:244` `buildInvoice()` and `lib/Service/ContributionDebtorResolver.php` build an issued `ARInvoice` and resolve a `CustomerMaster` for a debtor.

## Decisions

### D1. `event-fee` is a request type like the others

Add `event-fee` to the schema enum and to `REQUEST_TYPES`. The revenue account comes from the existing `paymentRevenueAccounts` app config, so an administrator maps it once. A request of a type without a mapping lands in `captured_unapplied` at capture, as today.

### D2. The reference is a field, not a sentence in the description

`paymentReference` (string, optional) on `PaymentRequest`, accepted by the leaf's `create`. The validator refuses a reference that another `pending` or `authorized` request already carries, and a reference shorter than 6 characters, so a stray number in a remittance text cannot match. Larpinq's `WC26-0042` fits.

### D3. A small matcher on new bank lines

`BankLineObjectRequestListener` listens to OpenRegister's `ObjectCreatedEvent` for `BankStatementLine` in the shillinq register, so lines from a file import and from a connected bank account are both seen. It calls `ObjectRequestBankMatcher::match(line)`, which searches `pending` object requests whose `paymentReference` occurs in the line's `reference` or `remittanceInfo` (case-insensitive, whole token) and compares the line's amount with the amount still open (`PaymentSettlementService::report()`).

- Exactly one request, exact amount: a `ReconciliationMatch` with `targetType` `payment-request`, confirmed at once.
- A reference match with another amount, or more than one request: a `candidate` match for the bookkeeper, nothing settled.
- No reference match: nothing, the line stays for the existing rules.

This is an ADR-031 exception of the same kind as `BankfeedMatcher`: a per-target comparison no declared predicate can express. It writes nothing but the match.

### D4. A confirmed match settles the request

On a confirmed `payment-request` match (auto or by the bookkeeper), the listener appends a settlement with method `bank-transfer`, the line's amount and the bank line's reference through `PaymentSettlementService`, stamps `settledAt`, and books the receipt the way `bookObjectReceipt()` does, with the bank account of the statement on the debit side instead of the provider clearing account. A request with an invoice behind it settles the invoice instead (D6), as the provider path does.

### D5. One receipt mail for every settlement path

`ObjectRequestSettledListener` listens to `ObjectUpdatedEvent` on `PaymentRequest` and acts when `settledAt` goes from empty to set on a request with `subjectKind` `object`. When `debtor.email` is known, `ObjectRequestReceiptMailer` sends one mail: the description, the amount, the date, the `paymentReference` and the `confirmationSummary`, in the instance's default language. It records `receiptSentAt`, so a replayed event does not mail twice. A failed mail is logged and leaves `receiptSentAt` empty; the request stays settled.

The notification dialect's email channel addresses Nextcloud users. A debtor is often not one, so the mail goes through `IMailer`, as `send()` does for the payment link.

### D6. An invoice asked for at create stands behind the request

When `create()` receives `invoiceRequested: true`, `ObjectRequestInvoiceService` resolves or creates the debtor's `CustomerMaster` (as `ContributionDebtorResolver` does), builds one issued `ARInvoice` with one line (the description and the amount, gross, VAT from the `paymentRequestVatRates` config for the type, default exempt), and sets the request's `invoiceReference`. From then on capture settles the invoice and books nothing on the object (REQ-SCON-006), so income is booked once. The invoice goes out through the normal invoice sending.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Request type, reference and invoice flag | Declarative: schema properties | Properties. |
| Reference uniqueness | Imperative, one check in the existing validator | The validator already owns the per-subject uniqueness. |
| Bank line to request | Imperative, `ObjectRequestBankMatcher` | Per-target comparison no predicate expresses; external data matching. |
| Settlement on a confirmed match | Imperative, listener | Reacts to a lifecycle transition, like `ReconciliationMatchToReportListener`. |
| Receipt mail | Imperative, listener and mailer | The recipient is an email address, not a Nextcloud user. |
| Invoice at create | Imperative, a service called from the leaf | Builds another object in the same save path. |

## Risks

- A reference quoted by two requests: refused at create (D2).
- A partial transfer: a candidate for the bookkeeper, never an automatic settlement (D3).
- A second payment by provider after a bank settlement: the existing append-not-overwrite rule on `settlements` reports the overpayment (REQ-FPCR-003).
