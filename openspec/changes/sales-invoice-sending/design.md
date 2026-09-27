# Design: sales-invoice-sending

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**The invoice.** `ARInvoice` is declared in
`lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json:395`
(version 0.1.0) with the lifecycle `draft` to `issued` to `paid`, `overdue`,
`disputed` or `written-off`. It carries `dueDate` and, from
`register.d/add-shillinq-invoice-lines.json:98`, a free-text `paymentTerms`
(BT-20). Its Peppol send fields (`deliveryStatus`, `transmissionId`,
`payloadFileUri`) come from `register.d/add-shillinq-einvoicing-ubl-peppol.json:11`.
Nothing on it records an email send.

**The PDF.** `lib/Service/InvoicePdfGenerator.php` has two entry points.
`generatePdf()` (:89) returns HTML, which `InvoiceApiController::pdf()` serves
for a `BillableInvoice` at `/api/v1/invoices/{invoiceId}/pdf` with a `.html`
filename. `generateHybridPdf()` (:131) returns real PDF bytes with the UBL
embedded, and only `EInvoiceService::sendEInvoice()`
(`lib/Service/EInvoice/EInvoiceService.php:133`) calls it. The PDF is
therefore reachable for an `ARInvoice` only inside the Peppol path, and there
it is hashed into a placeholder `docudesk://file/<hash>` URI and discarded
(`EInvoiceService::storeArtefact()`, :309).

**Mail.** The only invoice-adjacent mail is
`PaymentRequestActionController::send()`
(`lib/Controller/PaymentRequestActionController.php:102-150`), which mails a
plain-text payment link through `IMailer` and stamps `linkSentAt`. The
e-invoice fallback tells the user to leave the app: "No Peppol participant
found for this debtor, use PDF + email instead"
(`src/components/ar-invoice/arEInvoiceActions.js:64`, paraphrased; the source
string uses a dash).

**The customer.** `CustomerMaster` is declared in
`register.d/add-shillinq-bookkeeping-compliance.json:215` with `email`,
`creditLimit`, `dunningPolicyRef` and eleven other fields. Its description
promises "invoice-delivery ... fields" and none exists; fragments add
`peppolParticipantId` (`add-shillinq-einvoicing-ubl-peppol.json:136`),
`defaultMandateId` (`bookkeeping-sepa-direct-debit.json:872`) and VIES fields
(`bookkeeping-icp-opgaaf.json:604`). The matrix is right, and understates it:
the `Customers` index (`src/manifest.json:7547`) lists `customerNumber`,
`name` and `paymentTermDays`, and all three are absent from the schema (the
real keys are `customerId` and `legalName`). The quick draft reads
`customer?.paymentTerms || 'net30'` (`src/modals/InvoiceQuickDraftModal.vue:424`),
a field that does not exist, so every quick draft falls back to 30 days.
`InvoiceGenerationService::draftInvoice()` hard-codes the due date to
`toDate + 30` (`lib/Service/InvoiceGenerationService.php:134`) and
`paymentTerms` to `'net 30'` (:161). `RecurringInvoiceProfileModal.vue:393`
defaults `paymentTermsDays` to 30.

**Pages.** `AccountsReceivable` (`src/manifest.json:7825`, index on
`ARInvoice`) and `ARInvoiceDetail`. The detail page is declared in
`src/manifest.json:7870` and replaced wholesale by the full copy in
`src/manifest.d/add-shillinq-einvoicing-ubl-peppol.json:12`, which sets
`actionsComponent: AREInvoiceActions`
(`src/components/ar-invoice/AREInvoiceActions.vue`). `CustomerDetail` is
`src/manifest.json:7587`. Index pages already support `selectable` and
`bulkActions` (for example `src/manifest.d/bookkeeping-provincies-bbv-variant.json:285,360`).

**Batch precedent.** `ContributionRaiseService` with
`ContributionInvoiceBuilder` (`lib/Service/ContributionInvoiceBuilder.php`,
`MAX_RECIPIENTS = 200` at :57) turns one charge plus a list of debtors into one
issued `ARInvoice` each, idempotent per debtor, behind
`POST /api/contributions/raise` (`appinfo/routes.php:763`). It is specific to
school contributions (a `contribution` group, a `PaymentRequest` per invoice)
and it does not send.

**Recurring.** `RecurringInvoiceProfile.deliveryChannel` (`email`, `peppol`,
`none`, `register.d/recurring-invoicing.json:181`) is declared and read by
nothing: `RecurringInvoiceGenerator::generateForProfile()`
(`lib/Service/RecurringInvoiceGenerator.php:151`) issues and stops.

## Goals / Non-Goals

**Goals**

- A bookkeeper sends an issued invoice to the customer by email with the PDF attached, from the invoice page, and sees that it was sent.
- A bookkeeper sends many issued invoices in one action and creates one invoice per customer for a list of customers in one action.
- A customer's payment term and delivery method are set once and used by every new invoice.

**Non-Goals**

- Changing what the PDF looks like (`sales-invoice-document`).
- Making the Peppol path reach integriq (`sales-einvoice-exchange`).
- Printing and posting letters.

## Decisions

### D1. Mail through Nextcloud's `IMailer`, not through integriq

The invoice mail is composed in shillinq and sent through `IMailer`, the same
channel `PaymentRequestActionController::send()` uses. SMTP is the instance's
own configuration, not a third-party API, so ADR-067 and ADR-091 do not move it
to integriq.

Alternative considered: integriq's `DeliveryRequestedEvent` (ADR-041) with an
email channel. Rejected for now: integriq routes that event to configured
subscriptions, so an instance without a matching route would refuse every
invoice mail, and the fleet's existing invoice-adjacent mail already runs
through `IMailer`. The service keeps the transport behind one private method so
a later move is one edit.

### D2. The send reads the PDF from the generator, and attaches real PDF bytes

The service calls `InvoicePdfGenerator::generateHybridPdf()` with the invoice's
own lines and the UBL from `ArInvoiceUblMapper::toNlciusXml()` when the
customer has a VAT id, and the plain rendering otherwise, and attaches the
bytes as `Factuur-<invoiceNumber>.pdf`. When `sales-invoice-document` moves
rendering to docudesk (ADR-075), the service follows that entry point; this
change does not add a second renderer.

Alternative considered: attach the HTML that `generatePdf()` returns.
Rejected: a customer receives an `.html` file named `.pdf`.

### D3. One router by delivery method

`InvoiceSendingService::send()` reads `CustomerMaster.deliveryMethod`:

| deliveryMethod | What happens | Recorded |
|---|---|---|
| `email` (default) | PDF mailed to `invoiceEmail`, else `email` | `sending.sentVia = email`, `sentTo`, `sentAt` |
| `peppol` | `EInvoiceService::sendEInvoice()`; on its `fallback` answer the service mails instead | `sentVia = peppol` or `email` with the fallback noted |
| `post` | nothing is mailed | `sentVia = post`, `sentAt` empty, a note that the letter is printed by hand |
| `none` | nothing | `sentVia = none` |

A per-send override on the action lets the user pick email for a Peppol
customer, which replaces the "use PDF + email instead" advice with a button.

### D4. The batch send is a queued job

`POST /api/ar-invoices/send-batch` takes up to 200 invoice ids, checks that the
caller may act on the administration, records an `InvoiceBatch` of kind `send`
and queues `SendInvoicesJob` (a `QueuedJob` in `lib/BackgroundJob/`, ADR-069).
The job calls `InvoiceSendingService::send()` per invoice and writes the
outcome per invoice onto the batch. An invoice not in `issued` or `overdue`,
or one already sent, is skipped with a reason unless the batch says resend.

Alternative considered: send synchronously in the request. Rejected: 200
PDFs and mails exceed a web request, and a timeout would leave the user unsure
which invoices went out.

### D5. The batch create reuses the contribution raise's shape, not its code

`POST /api/ar-invoices/batch` takes a charge (description, lines, VAT rates,
invoice date, optional due date) and up to 200 `CustomerMaster` ids. It
writes one `ARInvoice` per customer, keyed on `(batchId, customerId)` so a
retried call writes nothing twice, then optionally issues them and hands them
to D4. The due date per invoice is the invoice date plus that customer's
`paymentTermDays` unless the charge names one.

Alternative considered: call `ContributionRaiseService` with a generic kind.
Rejected: it writes a `contribution` group and a `PaymentRequest` per invoice,
resolves debtors from portal accounts, and its voluntary rule would leak into
ordinary sales.

### D6. The customer defaults are schema fields with defaults

`CustomerMaster` gains `paymentTermDays` (integer, 0 to 365, default 30),
`deliveryMethod` (enum `email`, `peppol`, `post`, `none`, default `email`) and
`invoiceEmail` (optional). Each path that sets a due date reads
`paymentTermDays`: the quick draft (replacing the `paymentTerms` read),
`InvoiceGenerationService::draftInvoice()` (replacing the literal 30 and
`'net 30'`), the batch create, and the recurring profile modal as its default.

Alternative considered: a separate `PaymentCondition` schema as Exact has.
Rejected for now: no competitor evidence in the matrix needs discount terms,
and a day count covers every quoted case.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Customer payment term and delivery method | Declarative: `CustomerMaster` properties with defaults | Plain data, no behaviour. |
| Due date from the customer's term | Imperative in each writer (quick draft, generator, batch) | OpenRegister's calculation evaluator reads the object's own properties; the term lives on another object. |
| Sending one invoice (PDF render, mail, outcome) | Imperative: `InvoiceSendingService` | Composing a mail with an attachment has no declarative form. |
| Sending a batch | Imperative: `SendInvoicesJob` (QueuedJob) | Long-running work belongs in a job (ADR-069). |
| Notifying the bookkeeper that a batch finished | Declarative: `x-openregister-notifications` on `InvoiceBatch` when `state` becomes `done` | A state-change notification, the dialect ADR-031 prescribes. |

## Seed Data

`InvoiceBatch` is added with this shape: `batchId`, `kind` (`create`, `send`),
`administrationId`, `requestedBy`, `charge` (object, create only),
`invoiceIds`, `resend`, `outcomes` (array of `{invoiceId, status, reason}`),
`state` (`queued`, `running`, `done`). `CustomerMaster` gains the three fields
of D6 and `ARInvoice` gains `sending` (`sentVia`, `sentTo`, `sentAt`,
`attempts`, `lastError`).

Seed objects for the administration "Adviesbureau Kade B.V.":

- `CustomerMaster` "Bakkerij De Korenaar B.V.", `paymentTermDays` 14, `deliveryMethod` email, `invoiceEmail` facturen@dekorenaar.example.
- `CustomerMaster` "Gemeente Voorbeeld", `paymentTermDays` 30, `deliveryMethod` peppol, `peppolParticipantId` 0190:00000000000000000000.
- `CustomerMaster` "Stichting Buurthuis De Linde", `paymentTermDays` 21, `deliveryMethod` post.
- `InvoiceBatch` kind create, "Onderhoudscontract oktober 2026", one line of EUR 250 excl. 21 percent VAT for the three customers above, state done, three outcomes.

## Risks / Trade-offs

- [The instance has no working SMTP] → the send records `lastError` and the action shows it; the batch outcome lists every failed invoice.
- [A large attachment is rejected by the receiving server] → invoices are a few hundred kilobytes; the service refuses to attach more than 10 MB and says so.
- [Fixing the `Customers` columns changes a list people know] → the change only replaces keys that render empty today.

## Migration Plan

No data migration. Existing customers read `paymentTermDays` 30 and
`deliveryMethod` email through the schema defaults, which is what every path
assumed. Rollback is reverting the PR.

## Open Questions

- Should the batch create also accept a `RecurringInvoiceProfile` style line set from a saved template? Not needed by any quoted competitor.
