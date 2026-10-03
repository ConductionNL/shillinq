# Payment request leaf: raising a request from another app

Another app asks shillinq for money through the `shillinq-payment-requests` leaf.
It reaches the leaf through OpenRegister's integration registry, in PHP. The leaf
offers two ways to raise a request. They differ in who is allowed to ask.

## A person asks: `create`

```php
$provider = $registry->get('shillinq-payment-requests');
$request  = $provider->create($register, $schema, $objectId, $payload);
```

The signed-in user must carry the `payment.request` action. Administrators carry
it. Map the action to groups in the `paymentActionGroups` app config:

```bash
occ config:app:set shillinq paymentActionGroups --value '{"payment.request":["finance"]}'
```

`requestedBy` on the request is the user id. This is also the path OpenRegister's
HTTP route takes (`POST /api/objects/{register}/{schema}/{id}/integrations/shillinq-payment-requests`).

## An app asks: `createAsApp`

Use this when nobody who may ask is signed in. Larpinq does: a player takes a free
place, or its daily job moves a waitlisted registration up.

```php
$provider = $registry->get('shillinq-payment-requests');
if (method_exists($provider, 'createAsApp') === true) {
    $request = $provider->createAsApp('larpinq', $register, $schema, $objectId, $payload);
}
```

An administrator grants the action to the app in the `paymentActionApps` app
config. No app carries it by default:

```bash
occ config:app:set shillinq paymentActionApps --value '{"payment.request":["larpinq"]}'
```

- The app must be named for `payment.request` and be enabled. Otherwise the leaf
  throws a `RuntimeException` whose message starts with `403`, and writes nothing.
- A signed-in administrator does not lend the action to an app.
- `requestedBy` on the request is `app:<appId>`, for example `app:larpinq`.
- Shillinq reads and writes the request as the system, so it works without a user.
- The one-open-request-per-type rule still holds. A second pending request of the
  same type on the same object throws an `InvalidArgumentException`.
- OpenRegister's HTTP route never calls `createAsApp`. A request body that names
  an app does not open `create`.

## Payload

Both methods take the same fields: `amount`, `currency` (default `EUR`),
`requestType`, `description`, `debtor`, `dueAt`, `subjectType` and
`paymentGateway` (default `mollie`). The host object becomes the request's
`subject`. Unknown keys are ignored.

`requestType` is one of `leges`, `dwangsom`, `deposit`, `contribution`, `event-fee`
(the fee for taking part in an event) and `other`. An administrator maps each
type to a revenue account in `paymentRevenueAccounts`, for example
`{"event-fee": "8050"}`.

`paymentReference` is the reference the payer quotes on a bank transfer, for
example `WC26-0042`. It is optional, at least 6 characters, and only one open
(`pending` or `authorized`) request may carry it, compared without case; the
leaf refuses a second one. `invoiceRequested: true` records that the payer
wants an invoice for the payment.

When the request is settled (paid online, or money recorded by hand) and the
`debtor` has an `email`, shillinq mails the debtor one receipt with the
description, the amount, the date, the reference and the confirmation
summary, and records `receiptSentAt`.

A bank statement line (file import or connected bank account) that quotes a
pending request's `paymentReference` as a whole word is matched to it. With
exactly one such request and the full open amount, the match is confirmed at
once: the request gets a `bank-transfer` settlement and `settledVia:
bank-transfer`, and the receipt is posted with the bank account debited (or
the invoice behind the request is paid). Another amount, or two quoted
requests, leaves a pending match for the bookkeeper to confirm or reject.

Both return the created request as an array, with its `id` and `state: pending`.
Read its later state from the `PaymentRequest` object events, or with `list`.

## Refund or credit a settled request

The app a request stands on can ask shillinq to give the money back, or to
keep it as credit for the payer's next request. It dispatches one of two typed
events with `IEventDispatcher::dispatchTyped()` and reads the answer from the
same object:

```php
$event = new \OCA\Shillinq\Event\PaymentRefundRequestedEvent(
    sourceApp: 'larpinq',
    paymentRequestId: $paymentRequestId,
    reason: 'Cancelled by the player',
    correlationId: $registrationId,
);
$dispatcher->dispatchTyped($event);
if ($event->isHandled()) {
    $state = $event->getResult()['state']; // refund_requested
} else {
    $error = $event->getError();
}
```

Look the class up with `class_exists()` first, so your app keeps working
without shillinq. The answer is `{contractVersion: 1, paymentRequestId, state}`.

Shillinq accepts the command only for a request on an object that your app
owns (named on the subject, raised by your app through `createAsApp`, or on
your app's register), that is settled, and that was not refunded or credited
before. Anything else comes back with an error and changes nothing.

- `PaymentRefundRequestedEvent`: the request moves to `refund_requested` with
  the full amount in `refunds`. Finance approves and pays it; the request then
  reads `refunded`.
- `PaymentCreditRequestedEvent`: shillinq moves the income to the customer
  credit account set in `paymentCreditAccount`, records a `DebtorCredit` for
  the payer's customer record or, without one, their email address, and the
  request reads `credited`. A debtor with neither gets an error and no credit.
