# Design: extracurricular-fee-to-shillinq

Kind: code. One raise service with two pure helpers, one controller, one
dunning policy, and targeted changes on the payment request path.

## Context

What exists on `development` (read 2026-09-27):

- `PaymentRequest` (`lib/Settings/register.d/ar-invoice-payment-links.json`,
  0.3.0) already stands on an object: `subjectKind`, `subject {type, register,
  schema, id}`, `requestType`, `debtor`, `settlements`, `legalBasis`
  (`case-payment-requests`, `fees-payments-and-the-contract-register`).
- `ObjectPaymentRequestValidator` holds one pending request per
  `(subject, requestType)`.
- `PaymentReconciliationService::reconcile()` books an object receipt for every
  `subjectKind: object` capture and settles an invoice only for
  `subjectKind: invoice`.
- `PortalPaymentSessionService` resolves the subject's `customerMasterId` claim
  from portaliq's `portalAccount`, finds an owned payable `ARInvoice`, reuses its
  pending request or mints one, and opens a Mollie session. It serves audience
  `customer` only and reads `ARInvoice.state`.
- `ARInvoice`'s lifecycle field is `lifecycleState`
  (`add-shillinq-bookkeeping-compliance.json`); there is no `state` property.
- `DunningRunService::tickInvoice()` picks the ladder stage by days in arrears;
  `executeStage()` writes the `DunningRun`; `transferToIncasso()` hands a dossier
  to a collection agency.
- `RecurringInvoiceGenerator` is the precedent for an invoice with no order,
  created `issued` in one save.
- Portaliq ships `PortalAccountClaimRequestedEvent(appId, subjectRef, claimName,
  value)` for an app to link its record to a portal account. Learniq's guardians
  sign in with audience `parent`.

## Goals / Non-Goals

Goals: bill a set of guardians for one chargeable in one call; keep the
reference to the chargeable on every request; settle through the invoice; honour
the Wet vrijwillige ouderbijdrage; give the owning app a signal with no coupling;
let a parent pay from the portal.

Non-goals: a school screen, learniq's migration, a reduction scheme, direct debit,
refunds (see proposal).

## Decisions

### D1. The request is `subjectKind: object` AND invoice-backed

A raised request carries `subjectKind = object`, `subject` = the chargeable,
`requestType = contribution` and `invoiceReference` = the raised invoice.

- Why not `subjectKind: invoice` with the reference on the invoice only: the
  owning app reads payment requests (leaf `list`, the object event), and a request
  with no subject cannot be found by the chargeable.
- Why not an object request without an invoice: the invoice is what the portal
  lists, what dunning ages and what the debtor ledger shows. D19 says "shillinq
  invoices".

Consequence, and the reason for D4: the reconciliation must settle the invoice,
not book a receipt, whenever `invoiceReference` is set.

### D2. `PaymentRequest` 0.4.0, additive

| property | change |
|---|---|
| `subject.app` | new, string: the app owning the referenced object. Describes; never part of identity. |
| `beneficiary` | new, object `{type, register, schema, id}`: who the charge is for. `type` and `id` required when present. Joins the uniqueness key. |
| `requestType` | enum gains `contribution`. |
| `voluntary` | new, boolean, default false: copied from the charge. |
| `raiseBatchId` | new, string: the raise that wrote it. |
| `settledAt` | new, date-time: the settled edge (D6). |
| `settledVia` | new, enum `provider`, `cash`, `pin`, `bank-transfer`, `waived`, `other`. |

`ObjectPaymentRequestValidator::assertNoOpenRequest()` compares the beneficiary
key (`type|register|schema|id`, empty when absent) next to the subject key. Two
requests without a beneficiary compare equal on it, so the leges and dwangsom
behaviour is unchanged. `REQUEST_TYPES` gains `contribution`.

### D3. `ARInvoice.contribution`, in its own fragment

`lib/Settings/register.d/school-contributions.json` adds a nullable group:
`kind` (`parental-contribution`, `lunch-supervision`, `school-trip`, `activity`,
`other`), `voluntary`, `chargeable`, `beneficiary`, `raiseBatchId` and
`revenueAccount` (ARInvoice's EN 16931 `invoiceLines` carry no account). The one
line goes in `invoiceLines` (`itemName`, `netPrice`, `netAmount`, `unitCode`
C62, `vatCategory` E, `vatRate` 0): `lines` is not a property ARInvoice declares,
and OpenRegister drops an undeclared property in silence (gate 108). The fragment
sorts after every other `ARInvoice` fragment, so its `version` (0.14.0) is the
merged one; today's merged version is 0.6.0 (last writer `checks-vat.json`), and
an import only updates a schema whose version rises.

### D4. The reconciliation branch keys on `invoiceReference`

`reconcile()` on a capture: `invoiceReference` set, settle the invoice
(`settleLinkedInvoice`); otherwise, `subjectKind = object`, book the object
receipt. `settleLinkedInvoice()` reads `lifecycleState ?? state` and writes
`lifecycleState = paid` (and `state` only when the row carried one). This fixes
the inherited defect for every invoice request, not only contributions; it sits
inside the method this change must edit anyway.

### D5. The raise

`ContributionRaiseService::raise(array $payload): array`, used by
`ContributionController::raise()` and callable in process.

1. Authorize: `PaymentActionAuthorizer::may('payment.request')`, else
   `RuntimeException('403 ...')`.
2. Validate the payload whole (`ContributionInvoiceBuilder::normaliseCharge`); a
   bad payload writes nothing.
3. Load every request already on the chargeable once
   (`PaymentRequestFinder::onSubject`, all pages, read past the caller's rights
   so the duplicate check sees every request) and index them by beneficiary key.
4. Per recipient, in a `try`: resolve the debtor (D7); derive the beneficiary
   (the recipient's, else `{type: customer, register: <shillinq>, schema:
   CustomerMaster, id: <customerMasterId>}`); skip when a request that is not
   `voided` stands for it, or when an earlier recipient in this call took it;
   build and save the invoice (`issued`); build, validate and save the request;
   record the result.
5. Return `{batchId, raised, skipped, failed, results[]}`.

The batch id is `ctb-<yyyymmdd>-<8 hex>`; invoice numbers are
`CTB-<yyyy>-<8 hex>-<nnnn>`, unique per batch. The invoice is created `issued` in
one save, as `RecurringInvoiceGenerator` does for `auto-issue`.

`ContributionInvoiceBuilder` is pure (no I/O): charge normalisation, the invoice
payload, the request payload and the voluntary texts, so the shape is tested
without OpenRegister.

### D6. The settled signal

**Definition.** A request is settled the first time
`PaymentSettlementService::report()` answers `paid` or `overpaid`. At that moment
`settledAt` and `settledVia` are written in the same save as the change that
caused it. They are never cleared or moved.

**Where it is stamped.** `PaymentSettlementService::stampSettled(array $request,
string $at, string $via): array` returns the request with the two fields set when
the report is paid and `settledAt` is empty, and unchanged otherwise. Two callers:

- `PaymentReconciliationService::reconcile()` on a capture that lands `captured`
  (invoice or object branch), with `via = provider` and `at = capturedAt`. A
  capture routed to `captured_unapplied` is not stamped: the money arrived but the
  request did not settle what it was for, and an operator resolves it.
- `PaymentRequestActionController::settle()` after appending a settlement, with
  `via` = the method of the settlement that completed it.

**How a consumer hears it.** Shillinq saves through OpenRegister, which fires
`OCA\OpenRegister\Event\ObjectUpdatedEvent` with both objects. The signal is the
edge: old object without `settledAt`, new object with it. In process, the owning
app subscribes with `ObjectEventSubscription::subscribe()` from `boot()`, narrowed
to register `shillinq` and schema `PaymentRequest`, and filters on
`subject.app`. Out of process, integriq's `CloudEventListener` forwards the update
as `com.nextcloud.openregister.object.updated` with `data.attributes` and
`data.previous.attributes`; a jsonlogic subscription filter matches the edge. The
exact listener code and the subscription body are in contract.md.

**Why an edge on a field, not a new event class.** A shillinq event class forces
the listener to name a shillinq type, which is the dependency the brief rules
out. OpenRegister's `ObjectTransitionedEvent` only fires through its
`TransitionEngine`, and the webhook path saves directly; routing the capture
through the engine would make a webhook without a session depend on OpenRegister's
permission checks. `ObjectUpdatedEvent` fires on every save, and the field makes
the edge unambiguous across both routes to paid.

**Idempotency.** The reconciliation's `ALREADY_SETTLED` guard turns a replayed
capture into a no-op before any save, so no second update event carries a new
edge. A manual settlement on an already settled request finds `settledAt` set and
leaves it.

### D7. Debtor resolution

`ContributionDebtorResolver::resolve(array $debtor, string $administrationId):
array{customerMasterId, portalLinked}`:

1. `customerMasterId`: read it (`ObjectIdentifier::findOne`); missing means the
   recipient fails.
2. `portalSubjectRef`: read portaliq's `portalAccount` rows for that subject
   and use the first `claims.shillinq.customerMasterId` that names an existing
   customer.
3. `email`: the first `CustomerMaster` with that email in the administration.
4. Otherwise create one: `customerId = G-<first 10 of sha1(lowercased email)>`,
   `legalName = name`, `email`, `administrationId`, `lifecycleState = active`.
5. When a `portalSubjectRef` was given and no claim existed, dispatch
   `OCA\Portaliq\Event\PortalAccountClaimRequestedEvent('shillinq', subjectRef,
   'customerMasterId', uuid)` through `IEventDispatcher` behind `class_exists()`,
   and read `getResult() === 'ok'` into `portalLinked`.

### D8. The voluntary rule

Text: `ContributionInvoiceBuilder` asks `IFactory::get('shillinq', $language)` for
"This contribution is voluntary. Your child takes part whether you pay or not."
(Dutch: "Deze bijdrage is vrijwillig. Uw kind doet mee, of u nu betaalt of
niet.") for `invoiceNote`, and "(voluntary)" / "(vrijwillig)" as the suffix of the
line and the request description.

Dunning: `VoluntaryContributionPolicy` (pure) answers `isVoluntary(invoice)`,
`allowsStage(invoice, stageNr, runsSoFar)` and `stripCosts(params)`.
`DunningRunService` consults it in three places:

- `tickInvoice()`: a voluntary invoice gets stage 1 only, once; the costs are
  stripped from the params.
- `executeStage()`: reads the invoice by id (`ARInvoice`); a voluntary invoice
  refuses stage above 1 or a second run, and the run it allows carries no
  collection costs or interest.
- `transferToIncasso()`: refuses a voluntary invoice.

A missing or unreadable invoice is not voluntary (it carries no contribution
group, so it was never raised by this change).

### D9. The portal

`PortalContributionProvider::getAudiences()` gains `parent`; `getContribution()`
answers it with a manifest of the two AR collections (`salesInvoices`,
`paymentRequests`) and the `pay` action, built from the same arrays as the
customer manifest. `PortalPaymentInitiationController` and `PortalPaymentSessionService` accept
audiences `customer` and `parent`. The session service reads the invoice state
from `lifecycleState ?? state` and the amount from `totalAmount ?? grossAmount`
(ARInvoice declares `grossAmount`), and when it mints a request for an invoice
carrying a `contribution` group, copies `subjectKind = object`, `subject`,
`beneficiary`, `requestType = contribution`, `voluntary`, `raiseBatchId` and the
debtor from it. The parent manifest names the amounts by the fields ARInvoice
declares (`grossAmount`, `vatAmount`, where the customer manifest lists
`totalAmount`, `taxAmount`), and adds `invoiceNote` to the invoices and
`description` to the requests.

### D10. The leaf reads every page

`PaymentRequestLeafProvider::requestsOn()` read one page of 200 object requests
and filtered in PHP. With contributions, a shillinq holds far more than 200 object
requests, and a case's leges request would drop off the page. The read moves to
`PaymentRequestFinder::onSubject()`, which pages until a short page (capped at
500 pages); the leaf reads as the caller and the raise as the system. The
projection gains `beneficiary`, `invoiceReference`, `voluntary`, `settledAt` and
`settledVia`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `PaymentRequest` and `ARInvoice` fields, enums | declarative (register fragment) | schema data |
| Request lifecycle | declarative, unchanged | existing `x-openregister-lifecycle` |
| Settled signal | declarative carrier (a field) plus OpenRegister's own event | no new event class, no imperative dispatch to a consumer (gate-18 stays clean) |
| Bulk raise | imperative (`ContributionRaiseService`) | ADR-031 exception: a bulk write across three schemas with per-row failure isolation, triggered by a caller, is not a derived field or a lifecycle rule |
| Debtor resolution and portal claim | imperative | ADR-031 exception: external integration (portaliq's typed event) |
| Voluntary dunning cap | imperative guard in `DunningRunService` | ADR-031 exception: domain rule selector inside the existing imperative dunning orchestrator |
| `settledAt` stamping | imperative, inside the two existing write paths | the two routes to paid are already imperative; a calculation cannot express "first time, never again" |

## API Design

### `POST /apps/shillinq/api/contributions/raise`

Request and response in contract.md. `#[NoAdminRequired]`; the body of the method
refuses without a session (401) and without `payment.request` (403).

## Nextcloud Integration

- Controllers: `ContributionController` (new).
- Services: `ContributionRaiseService`, `ContributionInvoiceBuilder`,
  `ContributionDebtorResolver`, `Dunning\VoluntaryContributionPolicy` (new);
  `PaymentSettlementService`, `PaymentReconciliationService`,
  `ObjectPaymentRequestValidator`, `DunningRunService`,
  `Payment\PortalPaymentSessionService` (changed).
- OCP: `IEventDispatcher` (portal claim), `IL10N` via `OCP\L10N\IFactory`
  (invoice text in the requested language), `IUserSession`/`IGroupManager`
  through `PaymentActionAuthorizer`.
- OpenRegister: `ObjectServiceInterface` (ADR-083); `ObjectUpdatedEvent` as the
  signal carrier.

## Security Considerations

- The raise is gated on `payment.request`; an admin passes. The controller checks
  the session before anything else (gate-7).
- Nothing from the owning app is trusted beyond the reference it passes: shillinq
  reads no other app's rows, so a forged chargeable only labels invoices the
  caller was allowed to raise anyway.
- The portal `parent` manifest scopes on the same `customerMasterId` claim as
  `customer`, which portaliq writes server-side under shillinq's app id.
- No PCI data: the request stores opaque references only, as before.
- A guardian's email is stored on the `CustomerMaster` (already a required field
  of that schema); it is not logged.

## File Structure

```
lib/
  Controller/ContributionController.php                 (new)
  Service/ContributionRaiseService.php                  (new)
  Service/ContributionInvoiceBuilder.php                (new)
  Service/ContributionDebtorResolver.php                (new)
  Service/PaymentRequestFinder.php                      (new)
  Util/ObjectIdentifier.php                             (recordWithId keeps the uuid)
  Controller/PortalPaymentInitiationController.php      (parent audience)
  Service/Dunning/VoluntaryContributionPolicy.php       (new)
  Service/ObjectPaymentRequestValidator.php             (beneficiary, contribution)
  Service/PaymentReconciliationService.php              (invoice branch, lifecycleState, settled edge)
  Service/PaymentSettlementService.php                  (stampSettled)
  Service/DunningRunService.php                         (policy in three places)
  Service/Payment/PortalPaymentSessionService.php       (parent, lifecycleState, contribution re-mint)
  Controller/PaymentRequestActionController.php         (settled edge on settle)
  Integration/PaymentRequestLeafProvider.php            (all pages, projection)
  Portal/PortalContributionProvider.php                 (parent audience)
  Settings/register.d/ar-invoice-payment-links.json     (PaymentRequest 0.4.0)
  Settings/register.d/school-contributions.json         (ARInvoice.contribution, seed rows)
appinfo/routes.php                                      (one route)
l10n/en.json, l10n/nl.json (+ .js)                      (two strings)
tests/Unit/...                                          (see test-plan.md)
```

## Seed Data

The school demo lives beside the existing shillinq demo administration
`adm-shillinq-demo`. Seed rows sit in `school-contributions.json`.

### Schema: `ARInvoice` (contribution rows)

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `ar-invoice-ctb-2026-ouderbijdrage-1` | `ar-invoice-ctb-2026-schoolreis-1` | `ar-invoice-ctb-2026-overblijf-1` |
| invoiceNumber | CTB-2026-DEMO0001-0001 | CTB-2026-DEMO0002-0001 | CTB-2026-DEMO0003-0001 |
| customerId | nil UUID (demo) | nil UUID (demo) | nil UUID (demo) |
| lifecycleState | issued | paid | issued |
| grossAmount | 60.00 | 35.00 | 120.00 |
| contribution.kind | parental-contribution | school-trip | lunch-supervision |
| contribution.voluntary | true | true | false |
| invoiceNote | the voluntary notice (Dutch) | the voluntary notice (Dutch) | none |

### Schema: `PaymentRequest` (contribution row)

| Field | Object 1 |
|-------|----------|
| slug | `payment-request-ctb-2026-ouderbijdrage-1` |
| subjectKind | object |
| subject | `{app: learniq, type: fee-item, register: learniq, schema: FeeItem, id: nil UUID}` |
| beneficiary | `{type: learner, register: learniq, schema: LearnerProfile, id: nil UUID}` |
| requestType | contribution |
| invoiceReference | `ar-invoice-ctb-2026-ouderbijdrage-1` |
| amount | 60.00 |
| voluntary | true |
| state | pending |

Related items: none (payment evidence, no files or notes).

## Trade-offs

- Beneficiary in the uniqueness key rather than the debtor: one child is billed
  once per chargeable, even when two guardians are passed. Split billing between
  two guardians is out of scope; the second guardian is reported `skipped`, never
  billed twice.
- Invoice created `issued` in one save, as the recurring generator does, rather
  than draft-then-issue: halves the writes for a school of 400 and keeps the batch
  inside one request. The issue-time compliance guard is therefore not consulted;
  a school contribution to a private person carries none of the EN 16931 fields
  that guard checks.
- A cap of 200 recipients per call rather than a queued job: synchronous results
  per guardian are what the owning app needs to write its own reference, and
  idempotency makes chunking safe.

## Risks / Trade-offs

- [A voluntary invoice dunned by a path outside `DunningRunService`] → the three
  entry points are the only ones in `lib/` (`git grep executeStage` shows the
  controller and the service); a test per entry point.
- [Portaliq absent or its event renamed] → the claim is skipped and reported
  `portalLinked: false`; the invoice and its mailed link still work.
- [A consumer treats every `ObjectUpdatedEvent` as settled] → contract.md gives
  the edge test in code; the field never moves once set.

## Migration Plan

No data migration. The schema changes are additive; existing requests have no
`beneficiary`, which keeps their uniqueness behaviour. Deploy is the normal app
update, which re-imports the register (the fragment signature changes). Rollback
is a revert.

## Open Questions

- A voluntary reminder template of its own (proposal, open question). The cap and
  the cost stripping do not depend on it.
