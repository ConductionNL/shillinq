# School contributions API

**Endpoint:** `POST /index.php/apps/shillinq/api/contributions/raise`
**Spec:** [`extracurricular-fee-to-shillinq`](../../openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md), REQ-SCON-001 to REQ-SCON-010. The full contract is in [contract.md](../../openspec/changes/extracurricular-fee-to-shillinq/contract.md).
**Consumers:** learniq (fee items), portaliq (activities).

## Purpose

Bill a set of guardians for one school contribution in one call: the
ouderbijdrage, the overblijfbijdrage, a schoolreisje, a club. Each guardian
gets one issued invoice and one payment request. The request names the fee item
or activity in the owning app and the child it is for, so that app can find the
payment state later.

## Authentication

A Nextcloud session whose user carries the `payment.request` action, or an
admin. Map the action to groups in the `paymentActionGroups` app config:

```bash
occ config:app:set shillinq paymentActionGroups --value '{"payment.request":["school-coordinators"]}'
```

An app that already runs inside the request can call
`OCA\Shillinq\Service\ContributionRaiseService::raise()` with the same array.
Guard the call with `class_exists()`; shillinq is optional.

## Request

```json
{
  "chargeable": { "app": "learniq", "type": "fee-item", "register": "learniq", "schema": "FeeItem", "id": "<fee-item-uuid>" },
  "kind": "parental-contribution",
  "description": "Ouderbijdrage 2026-2027",
  "amount": 60.0,
  "voluntary": true,
  "administrationId": "adm-school-1",
  "recipients": [
    {
      "debtor": { "portalSubjectRef": "<guardian-subject-ref>", "name": "J. de Vries", "email": "j.devries@example.nl" },
      "beneficiary": { "type": "learner", "id": "<learner-id>" }
    }
  ]
}
```

- `kind` is one of `parental-contribution`, `lunch-supervision`, `school-trip`, `activity`, `other`.
- Send at most 200 recipients per call. Send the rest in the next call.
- A recipient's own `amount` overrides the charge, for a reduction.
- Leave out `beneficiary` to bill per household.
- Optional: `currency` (EUR), `invoiceDate` (today), `dueDate` (30 days later), `revenueAccount`, `language` (`nl`).

## Response

`200` with one result per recipient:

```json
{
  "batchId": "ctb-20261001-1a2b3c4d",
  "raised": 1,
  "skipped": 0,
  "failed": 0,
  "results": [
    { "index": 0, "status": "raised", "invoiceId": "<uuid>", "invoiceNumber": "CTB-2026-1A2B3C4D-0001", "paymentRequestId": "<uuid>", "customerMasterId": "<uuid>", "portalLinked": true }
  ]
}
```

- `skipped` means this child already has a request on this fee item. The result names it, so a retried call bills nobody twice.
- `failed` carries the reason. The other recipients are still billed.
- `400` for a call that cannot be raised as a whole, `401` without a session, `403` without the action.

## Voluntary contributions

With `voluntary: true` the invoice says the contribution is voluntary and that
the child takes part either way. The guardian gets one reminder at most,
without collection costs or interest, and the invoice never goes to a
collection agency.

## Knowing when a payment is in

Shillinq writes `settledAt` and `settledVia` on the payment request the first
time it counts as paid, and never moves them. Listen for that field appearing:

- inside Nextcloud, on OpenRegister's `ObjectUpdatedEvent` for register `shillinq`, schema `PaymentRequest`;
- outside Nextcloud, through an integriq event subscription on `com.nextcloud.openregister.object.updated`.

The listener code and the subscription filter are in contract.md.

## Where the guardian pays

In the portal, under their contributions, with the pay button. Guardians sign in
with audience `parent`. Without a portal account, send the payment link from the
payment request panel.
