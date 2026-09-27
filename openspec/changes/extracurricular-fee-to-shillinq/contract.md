# Contract: extracurricular-fee-to-shillinq

Version 1, 2026-09-27. Shillinq owns this contract. The owning apps depend on it
duck-typed: no `use` of a shillinq class, no `info.xml` dependency.

## Consumers

- `learniq` (`payments-to-shillinq-migration`): raises the contributions of a
  `FeeItem` for the guardians of its learners, and activates an `Entitlement`
  when the settled signal arrives for it.
- `portaliq` (`extracurricular-activity-offer`): raises the contribution of an
  `activityOffer` for the guardians of its confirmed places, and writes its own
  `activitySignup.paymentRequestRef` from the raise response.
- Any system outside Nextcloud: subscribes to the settled signal through
  integriq's outbound webhooks.

## Endpoints

### `POST /apps/shillinq/api/contributions/raise`

**Auth**: a Nextcloud session whose user carries the `payment.request` action
(`paymentActionGroups` app config, ADR-023), or an admin. CSRF token required, as
for every session route.

The same call exists in process for an app that already runs inside the request:

```php
$class = 'OCA\\Shillinq\\Service\\ContributionRaiseService';
if (class_exists($class) === true) {
    $result = \OCP\Server::get($class)->raise($payload); // same array in, same array out
}
```

The in-process call checks the same action against the session user. It throws
`InvalidArgumentException` where the endpoint answers 400 and `RuntimeException`
with a message starting `403` where the endpoint answers 403.

**Request:**
```json
{
  "chargeable": {
    "app": "learniq",
    "type": "fee-item",
    "register": "learniq",
    "schema": "FeeItem",
    "id": "00000000-0000-0000-0000-000000000000"
  },
  "kind": "parental-contribution",
  "description": "Ouderbijdrage 2026-2027",
  "amount": 60.0,
  "currency": "EUR",
  "voluntary": true,
  "administrationId": "adm-school-1",
  "invoiceDate": "2026-10-01",
  "dueDate": "2026-11-01",
  "revenueAccount": "8400",
  "language": "nl",
  "recipients": [
    {
      "debtor": { "portalSubjectRef": "<guardian-subject-ref>", "name": "J. de Vries", "email": "j.devries@example.nl" },
      "beneficiary": { "type": "learner", "register": "learniq", "schema": "LearnerProfile", "id": "00000000-0000-0000-0000-000000000001" }
    },
    {
      "debtor": { "customerMasterId": "00000000-0000-0000-0000-000000000002" },
      "beneficiary": { "type": "learner", "id": "<nextcloud-user-id>" },
      "amount": 30.0
    }
  ]
}
```

| Field | Required | Meaning |
|---|---|---|
| `chargeable.app`, `register`, `schema`, `id` | yes | The definition in the owning app. Stamped on every invoice and request. |
| `chargeable.type` | no | The owning app's word for it; defaults to `chargeable`. |
| `kind` | yes | `parental-contribution`, `lunch-supervision`, `school-trip`, `activity` or `other`. |
| `description` | yes | The line text and the checkout text. |
| `amount` | yes | Above zero. A recipient's own `amount` overrides it (a reduction). |
| `voluntary` | yes | `true` under the Wet vrijwillige ouderbijdrage. |
| `administrationId` | yes | The school's shillinq administration. |
| `currency`, `invoiceDate`, `dueDate`, `revenueAccount`, `language` | no | Defaults: `EUR`, today, invoice date plus 30 days, none, `nl`. |
| `recipients` | yes | 1 to 200 entries. |
| `recipients[].debtor` | yes | One of: `customerMasterId`; `portalSubjectRef` with `name` and `email`; `name` and `email`. |
| `recipients[].beneficiary` | no | The child. `type` and `id` required when present; `register` and `schema` when the child is an object. Without it the debtor's `CustomerMaster` is the beneficiary, which bills per household. |

**Response (200):**
```json
{
  "batchId": "ctb-20261001-1a2b3c4d",
  "raised": 1,
  "skipped": 1,
  "failed": 0,
  "results": [
    {
      "index": 0,
      "status": "raised",
      "invoiceId": "00000000-0000-0000-0000-000000000010",
      "invoiceNumber": "CTB-2026-1a2b3c4d-0001",
      "paymentRequestId": "00000000-0000-0000-0000-000000000011",
      "customerMasterId": "00000000-0000-0000-0000-000000000012",
      "portalLinked": true
    },
    {
      "index": 1,
      "status": "skipped",
      "reason": "already-raised",
      "paymentRequestId": "00000000-0000-0000-0000-000000000013"
    }
  ]
}
```

A recipient that fails (`status: failed`, `reason` in plain words) never stops
the others. `skipped` means a request that is not voided already stands for this
chargeable and beneficiary; the response names it, so a retried chunk is safe.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | The chargeable misses a part, `kind` is unknown, `amount` is not above zero, `recipients` is empty or longer than 200, or `administrationId` is missing |
| 401  | No session |
| 403  | The user does not carry `payment.request` |

## The settled signal

### What "settled" means

A `PaymentRequest` is settled the first time `PaymentSettlementService::report()`
reports it `paid` or `overpaid`: the provider captured it, or money recorded by
hand covers the amount. At that moment shillinq writes two fields in the same
save, once, and never clears or moves them:

| Field | Type | Meaning |
|---|---|---|
| `settledAt` | date-time | When the request first counted as settled. |
| `settledVia` | enum | `provider`, `cash`, `pin`, `bank-transfer`, `waived` or `other`: the route that completed it. |

**The signal is the edge: the old object has no `settledAt`, the new one has.**
A listener keys on that edge and on `subject.app`, and on nothing else.

Fields a listener can rely on in the new object: `subjectKind` (`object`),
`subject.app`, `subject.type`, `subject.register`, `subject.schema`, `subject.id`,
`beneficiary`, `invoiceReference`, `requestType` (`contribution`), `amount`,
`currency`, `voluntary`, `state`, `settledAt`, `settledVia`, `raiseBatchId`.

### In process: OpenRegister's object event

Shillinq saves the request through OpenRegister, which dispatches
`OCA\OpenRegister\Event\ObjectUpdatedEvent` with the old and the new object. Register
the listener from `boot()`, narrowed to shillinq's register and schema:

```php
if (class_exists('OCA\\OpenRegister\\Event\\ObjectEventSubscription') === true) {
    \OCA\OpenRegister\Event\ObjectEventSubscription::subscribe(
        dispatcher: $dispatcher,
        event: \OCA\OpenRegister\Event\ObjectUpdatedEvent::class,
        listener: ContributionSettledListener::class,
        registers: ['shillinq'],
        schemas: ['PaymentRequest'],
    );
}
```

```php
$new = $event->getNewObject()->getObject();
$old = $event->getOldObject()?->getObject() ?? [];
if (empty($new['settledAt']) === true || empty($old['settledAt']) === false) {
    return; // not the edge
}
if (($new['subject']['app'] ?? '') !== 'learniq') {
    return; // not ours
}
// activate the Entitlement for $new['subject']['id'] and $new['beneficiary']
```

When a request is moved through OpenRegister's transition API instead of a save,
OpenRegister also fires `ObjectTransitionedEvent`. Key on the `ObjectUpdatedEvent`
edge, which fires on both paths.

### Out of process: integriq's outbound webhook

Integriq forwards every OpenRegister update as the CloudEvent
`com.nextcloud.openregister.object.updated`, with the new object under
`data.attributes` and the old one under `data.previous.attributes`
(integriq spec `events-cloudevents`, REQ-004). Subscribe with a jsonlogic filter
on the edge:

```json
{
  "types": ["com.nextcloud.openregister.object.updated"],
  "style": "push",
  "sink": "https://example.nl/hooks/contribution-settled",
  "filters": [
    {
      "jsonlogic": {
        "and": [
          { "!!": [{ "var": "data.attributes.settledAt" }] },
          { "!": [{ "var": "data.previous.attributes.settledAt" }] },
          { "==": [{ "var": "data.attributes.requestType" }, "contribution"] },
          { "==": [{ "var": "data.attributes.subject.app" }, "learniq"] }
        ]
      }
    }
  ]
}
```

Leave `source` empty: integriq derives it from a `type` property that a payment
request does not carry.

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 200 | Processed | Every recipient has a result: raised, skipped or failed |
| 400 | Bad request | See the endpoint table |
| 401 | Unauthenticated | No Nextcloud session |
| 403 | Forbidden | The user lacks `payment.request` |

## Versioning

Version 1. Additive changes (a new optional request field, a new response field,
a new `kind`, a new `settledVia` value) are not breaking. A listener MUST ignore
fields it does not know.

## Breaking Change Policy

Renaming or removing `settledAt`, `settledVia`, `subject.app`, `beneficiary`, the
`contribution` request type, or any required raise field breaks both consumers.
Such a change names learniq and portaliq as consumers, and lands only after both
have moved.

## SLA

Synchronous. A call of 200 recipients does at most four OpenRegister writes per
recipient (a customer, an invoice, a request, a portal claim) and two reads per
call to load the requests already standing on the chargeable.
