# Inbound payable: another app asks shillinq to pay someone

Some apps owe money. A case app owes an applicant a penalty for deciding late,
a grants app pays an advance, an events app returns a deposit. None of them
should keep its own payment run. They raise a payable in shillinq and finance
pays it out with everything else.

## Raise a payable

Dispatch `PayableRaisedEvent` with `IEventDispatcher::dispatchTyped()` and read
the answer from the same object. Look the class up with `class_exists()` first,
so your app keeps working without shillinq.

```php
$event = new \OCA\Shillinq\Event\PayableRaisedEvent(
    sourceApp: 'dossiq',
    amount: 1442.00,
    payeeName: 'J. Jansen',
    payeeIban: 'NL91ABNA0417164300',
    reference: 'DWS-2026-0042',
    description: 'Penalty for a late decision, case Z-2026-17',
    callbackUrl: $urlGenerator->linkToRouteAbsolute('dossiq.payableCallback.callback'),
    category: 'dwangsom',
    correlationId: $calculationId,
    administrationId: $administrationId,
    dueOn: '2026-11-07',
);
$dispatcher->dispatchTyped($event);
if ($event->isHandled()) {
    $payableId = $event->getResult()['payableId']; // state: requested
} else {
    $error = $event->getError();
}
```

- `amount` is in euros with two decimals, not cents.
- `reference` is yours: at least 6 characters, one per app. Raising the same
  reference again with the same amount and IBAN answers the payable you already
  have, so a retry is safe. A different amount or IBAN under it is refused.
- The app must be installed, the amount above zero, the IBAN valid (mod-97) and
  the payee named. Otherwise the event comes back refused and nothing is stored.

## What finance does

Finance needs the `payment.administer` action for each step.

| Step | Route | Booking |
|---|---|---|
| Approve | `POST /api/inbound-payables/{id}/approve` | debit the expense account, credit `inboundPayableAccount` |
| Mark paid | `POST /api/inbound-payables/{id}/paid` with `bankReference`, `bankAccount`, `paidOn` | debit `inboundPayableAccount`, credit the bank |
| Reject | `POST /api/inbound-payables/{id}/reject` with `reason` | none |

The expense account comes from `inboundPayableExpenseAccounts`, a JSON map keyed
by the payable's `category` first and your app id second:

```bash
occ config:app:set shillinq inboundPayableAccount --value '1610'
occ config:app:set shillinq inboundPayableExpenseAccounts --value '{"dwangsom":"4390","dossiq":"4999"}'
```

## The callback

After every step shillinq posts JSON to your `callbackUrl`:

```json
{"contractVersion": 1, "payableId": "…", "reference": "DWS-2026-0042", "correlationId": "…",
 "status": "paid", "amount": 1442.0, "currency": "EUR",
 "actualPaymentDate": "2026-10-20", "bankReference": "SEPA-777", "reason": ""}
```

`status` is `approved`, `paid` or `rejected`. The body is signed: the
`X-Shillinq-Signature` header is the hex HMAC-SHA256 of the raw body under your
app's secret. Check it with `hash_equals()` before you trust the body. An
administrator sets the secret on both sides:

```bash
occ config:app:set shillinq inboundPayableCallbackSecrets --value '{"dossiq":"<secret>"}'
```

Without a secret for your app shillinq sends nothing, and the payable records
why in `callbackError`. A callback that fails does not undo the step; the
payable keeps `callbackError` until the next step reaches you.

Next: add a route in your app that verifies the signature and updates your own
record from `status`.
