# Design: banking-payment-run

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**The run and its file.** `PaymentRun` is declared in
`lib/Settings/register.d/bookkeeping-accounts-payable-core.json:1017`, with
lifecycle draft, approved, exported, reconciled. `approve` requires
`FourEyesPaymentRunGuard`; `export` requires `PaymentRunDuplicateGuard`
(`lib/Lifecycle/PaymentRunDuplicateGuard.php`), which refuses a line whose
`apTransactionRef` is already paid or already on another run in draft,
approved or exported. Each `paymentLines[]` item carries `payeeId`,
`payeeName`, `creditorIban`, `amount`, `remittanceInfo` and
`apTransactionRef`; nothing else. `PaymentRunExportService::export()`
(`lib/PaymentRun/PaymentRunExportService.php:120`) checks the run is
approved, refuses lines without `creditorIban` (line 127) and renders the
generators. `SepaPain001Generator::render()`
(`lib/PaymentRun/Generator/SepaPain001Generator.php:69`) writes one `PmtInf`
block with `ReqdExctnDt` taken from the run's `executionDate` (line 74, written at line 111).

**The pages.** `PaymentRuns` (`/bookkeeping/payment-runs`) is a plain index;
`PaymentRunDetail` (`/bookkeeping/payment-runs/:id`) has the header actions
"Export to bank" (`POST /apps/shillinq/api/v1/payment-runs/@objectId/export`,
`appinfo/routes.php:802`) and "Reconcile / import statement"
(`src/manifest.d/bookkeeping-accounts-payable-core.json:400` to `:470`).
`APTransactionDetail` and `PayeeDetail` have no header actions.

**What a run pays.** Payment lines point at `APTransaction`
(`bookkeeping-accounts-payable-core.json:298`), not at `APInvoice`. The
`APInvoice` schema the matrix note cites (`lib/Settings/shillinq_register.json:20925`)
has no page and one reader (`lib/Lifecycle/OpenBalanceGuard.php`); its
`post` transition declares `materialise-gl-transaction`, which
`ledger-posting-path` registers. `APTransaction`'s lifecycle (field `state`)
runs draft, received, issued, then partially-paid, paid, overdue, disputed,
written-off, voided, and `dispute` goes from issued to disputed.

**A correction to the matrix note.** The note says posted "is only reached
through post, which declares the unregistered action". That holds for
`APInvoice`. For `APTransaction`, the schema a run actually pays, the block
is earlier: `issue` (`bookkeeping-accounts-payable-core.json:536`) requires
`OCA\Shillinq\Lifecycle\BalanceGuard::isBalanced`. That literal tag is not
registered in `lib/AppInfo/Application.php` (only the two `APGuard::` tags
are, lines 908 and 919), and `BalanceGuard::isBalanced(string $transactionId)`
(`lib/Lifecycle/BalanceGuard.php:77`) takes a GL transaction id, not the
object `RegisterRequiresGuardAdapter` hands a wrapped method. So no
`APTransaction` reaches `issued`, none is ever due, and a proposal would
always be empty. The same bug class is shillinq#433.

**The supplier.** `Payee` (`bookkeeping-accounts-payable-core.json:11`) has
a lifecycle active, blocked, archived. Its `block` transition (line 250)
means "Suspend new AP invoicing for this vendor", not a payment hold, and
no guard reads it.

**The provider path.** The open change `fees-payments-and-the-contract-register`
REQ-FPCR-004 submits an approved run to a provider through integriq's
`live-payment-providers`. It is specified, not built.

## Goals / Non-Goals

**Goals**

- A bookkeeper gets a draft run of every open, unblocked invoice due by a chosen date, in one action, and edits it before approval.
- A blocked invoice or a blocked supplier is never paid by a run, whenever the block was set.
- Each payment in a run can go out on its own day.

**Non-Goals**

- Changing the four-eyes approval or the duplicate control.
- Submitting to a provider, or talking to a bank.
- Early-payment discount optimisation. No row asks for it.

## Decisions

### D1. Make `APTransaction.issue` passable with an object-shaped guard

Add `BalanceGuard::isInvoiceBalanced(array $object): bool`, true when the
sum of `lines[].amount` plus `taxAmount` equals `totalAmount` in integer
cents, and register the literal tag the schema declares through
`RegisterRequiresGuardAdapter`, the way `APGuard::isInvoiceNumberUnique` is
registered. The schema's `requires` string changes to the new method name.

Alternative considered: register the existing `isBalanced` tag as it is.
Rejected because it takes a GL transaction id that does not exist before
the invoice is issued, so the adapter would call it with the wrong argument
and deny every time.

### D2. The proposal is a service, and it writes a draft run only

`PaymentRunProposalService::propose(administrationId, dueOnOrBefore,
debtorAccountIban, executionDate)` reads `APTransaction` in state issued,
overdue or partially-paid with `dueDate` on or before the chosen date,
drops every invoice that is blocked, whose payee is blocked, or that is
already on a run in draft, approved or exported, and writes one `PaymentRun`
in draft with one line per remaining invoice: `creditorIban` from
`Payee.bankAccount.iban`, `amount` the open amount, `remittanceInfo` the
invoice number. It returns the run and the list of invoices it skipped,
each with its reason. It is reached by
`POST /api/v1/payment-runs/propose` and a header action on `PaymentRuns`.

Alternative considered: a declarative calculation on `PaymentRun`. Rejected
because OpenRegister has no declarative primitive that creates an object
from a query over two other schemas; the selection is the one imperative
part, and it writes through `ObjectService` like any other writer.

### D3. The block is a field pair, not a lifecycle state

`APTransaction` and `Payee` each get `paymentBlocked` (boolean, default
false) and `paymentBlockReason` (string, required when blocked). The
detail pages get "Block payment" and "Release payment" header actions that
patch the object through OpenRegister; the audit trail already enabled on
both schemas records who and when.

Alternatives considered: reuse `APTransaction.disputed`, and reuse
`Payee.blocked`. Rejected. A block is orthogonal to the invoice's state: an
overdue invoice can be blocked and stays overdue. And `Payee.blocked`
suspends invoicing, which would stop the liability being booked; a payment
block must leave booking alone. A disputed invoice is treated as blocked
(D4), so the dispute state keeps its meaning.

### D4. One check, used by the proposal, the export guard and the provider submission

`PaymentBlockChecker::blockedLines(array $paymentRun): array` returns each
line whose invoice is blocked or disputed, or whose payee is blocked, with
the reason. `PaymentRunDuplicateGuard` calls it and denies the export with
a message naming the invoice numbers. The provider submission of
REQ-FPCR-004, when built, calls the same checker.

Alternative considered: a second guard on `export`. Rejected because the
lifecycle engine resolves one `requires` tag per transition (the guard's
own docblock says so), and the slot is taken.

### D5. One `PmtInf` block per requested date

`paymentLines[]` gets an optional `requestedExecutionDate`. The generator
groups lines by that date, falling back to the run's `executionDate`, and
writes one `PmtInf` per group with its own `NbOfTxs`, `CtrlSum` and
`ReqdExctnDt`; the group header keeps the run totals. The proposal fills the
date with the later of the invoice's `dueDate` and the run's
`executionDate` when "pay on the due date" is ticked, and leaves it empty
otherwise.

Alternative considered: one run per date. Rejected because it multiplies
four-eyes approvals for what the user sees as one payment round.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Invoice issue precondition | Declarative `requires` tag, served by a registered adapter | The declaration exists; it needs a resolvable executor. |
| Selecting due invoices into a draft run | Imperative, `PaymentRunProposalService` | A cross-schema selection that creates an object has no declarative primitive. |
| Payment block on invoice and payee | Declarative, two schema fields and header actions patching the object | No state machine is needed; the audit trail is already declared. |
| Refusing a blocked line at export | Imperative, inside the existing `export` guard | One `requires` slot per transition. |
| Per-line execution date | Declarative field, read by the existing generator | Rendering already lives in the generator. |

## Seed Data

Schema fields are added; seed objects in the same fragment gain them.
For "Gemeente Voorbeeld", debtor account NL91ABNA0417164300:

- Payee "Drukkerij Van der Meer B.V.", IBAN NL20INGB0001234567, not blocked, with invoice 2026-0412 of EUR 1,815.00 due 2026-10-01.
- Payee "Schoonmaakbedrijf De Vries", IBAN NL44RABO0123456789, blocked with reason "IBAN change under verification", with invoice DV-7781 of EUR 2,420.00 due 2026-09-30.
- Invoice 2026-0419 from Drukkerij Van der Meer of EUR 605.00 due 2026-10-15, blocked with reason "Wacht op creditnota".

A proposal for due on or before 2026-10-01 yields one line (2026-0412) and
two skipped invoices with their reasons.

## Risks / Trade-offs

- [The issue guard starts letting invoices through] → it only checks the invoice's own arithmetic, which is what the schema always said it checked; the period and payee checks in the transition description stay unimplemented and are named in the release note.
- [A proposal with hundreds of lines] → the service pages through `ObjectService` and writes one run; the four-eyes approval sees the same list as today.
- [Several dates in one file] → a run with one date still renders exactly one `PmtInf`; the existing generator test stays green.

## Migration Plan

No data migration. Existing runs have no per-line date and render as they
do today. Existing invoices and payees are unblocked by default.

## Open Questions

None.

## Build notes (2026-09-29)

What changed against this design while building it at the stack head:

- The export service checks the block itself, before it renders or stores a file. The guard runs on the save that moves the run to exported, which comes after the file is written into Files, so a guard alone would leave a bank file behind for a refused run. The guard still checks too.
- The open amount of a partly paid invoice is its total less what exported or reconciled runs already paid on it; the AP transaction carries no paid amount of its own.
- A proposal with no payable invoice writes no run and answers 422 with the invoices it left out.
- Non-euro invoices are left out with a reason; pain.001 is a euro file.
- The seed uses the existing demo administration (`adm-shillinq-demo`) instead of a new "Gemeente Voorbeeld" one.
- Validating against the ISO 20022 XSD showed every pain.001 file written so far was invalid (`DbtrAgt/FinInstnId/Othr` as a text node). Fixed: `Othr/Id`.
