# Design: shillinq-ap-push-notifications

## Context

- Dialect: `x-openregister-notifications` (ADR-031, owned by OpenRegister).
- Engine capabilities consumed:
  - `subject` → notification TITLE; `message` → notification BODY (per
    `notification-message-and-body`). The two are independently resolved, so the title and
    body are no longer forced identical.
  - `actions[]` → notification action buttons; each action has an i18n `label`, optional
    `primary`, and a `target`. `target.kind: object-detail` deeplinks to the triggering object
    (per `notification-actions-and-web-push`). Max 2 actions (Web Notification API limit) — we
    declare exactly 1.
  - `originApp: shillinq` → drives icon/badge and the deeplink base for `object-detail`.
  - `channels: [nc-notification, web-push]` → `web-push` delivers via Web Push + VAPID even
    when all Nextcloud tabs are closed; `nc-notification` is the in-app/foreground popup.

## Schema field mapping

`APTransaction` real fields (from `bookkeeping-accounts-payable-core.json`):

| Concern | Field | Notes |
|---|---|---|
| Invoice reference | `invoiceNumber` | Vendor invoice number, unique per (administrationId, vendorId). Used in `{{invoiceNumber}}`. |
| Status | `state` | enum: draft / received / issued / partially-paid / paid / overdue / disputed / written-off / voided. |
| Due date | `dueDate` | ISO date; auto-set from `invoiceDate` + `Payee.paymentTermDays`. |
| Approver / assignee | — | **None.** No approver, assignee, or `approvalState` field on AP. |

## Rule decisions

### approvalNeeded

- **Trigger:** `updated` with `condition {field: state, operator: equals, value: "received"}`.
  Rationale: the `received` state is documented "awaiting approval/posting"; the `received →
  issued` transition is the approval+posting step. This is the moment the invoice enters the
  approval queue. The schema has no submit/approval action or `approvalState` field to key a
  `transition`-type trigger on, so `updated`-on-`received` is the faithful workable signal.
- **Recipients:** `{kind: groups, groups: [shillinq-finance]}` — the schema declares no
  approver field, so we mirror the AR/PO rules' finance group rather than invent an approver
  field (compromise, documented in proposal).
- **subject (TITLE):** `nl: "Goedkeuring nodig: {{invoiceNumber}}"`,
  `en: "Approval needed: {{invoiceNumber}}"`.
- **message (BODY):** `nl: "Deze inkoopfactuur staat in Shillinq. Openen?"`,
  `en: "This supplier invoice is in Shillinq. Open it?"`.
- **action:** one primary action, label `nl: "Factuur openen"` / `en: "Open invoice"`,
  `target: {kind: object-detail}`.

### overdue

- **Trigger:** `scheduled`, `intervalSec: 86400` (daily), with a `filter.all` of
  `state notIn [paid, written-off, voided]` AND `dueDate before now` — the exact filter shape
  used by the AR `ARInvoice.overdue` rule in `register.d/shillinq-notifications.json`,
  adapted to the AP `state` enum.
- **Recipients:** `{kind: groups, groups: [shillinq-finance]}`.
- **subject (TITLE):** `nl: "Factuur over de vervaldatum: {{invoiceNumber}}"`,
  `en: "Invoice overdue: {{invoiceNumber}}"`.
- **message (BODY):** `nl: "Deze factuur is vervallen in Shillinq. Openen?"`,
  `en: "This invoice is overdue in Shillinq. Open it?"`.
- **action:** same primary "Open invoice" `object-detail` action.

## Deeplink

The `object-detail` target resolves through OR's deeplink registry. shillinq registers its
patterns in `DeepLinkRegistrationListener::handle()`. Before this change only the `account`
schema was registered. We add:

```
$event->register(
    appId: 'shillinq',
    registerSlug: $registerSlug,   // config 'register' key, default 'shillinq'
    schemaSlug: 'APTransaction',
    urlTemplate: '/apps/shillinq/bookkeeping/accounts-payable/{uuid}'
);
```

The URL mirrors the existing `account` pattern and points at the `APInvoiceDetail` manifest
route (`route: /bookkeeping/accounts-payable/:id`, `schema: APTransaction`) already declared
in `src/manifest.json`. The register slug is read from app config (not hardcoded) so deeplinks
keep working when an admin binds the app to a non-default register (L3), exactly as the
`account` registration does.

## Alternatives considered

- **Key approvalNeeded on a `transition` trigger to `issue`.** Rejected — `issue` is the
  *completion* of approval (posting), not the request for it; firing then is too late.
- **Add an `approvalState` field to APTransaction.** Rejected — out of scope, and the JE-style
  approval workflow is owned by OR's approval-workflow extension (ADR-022); inventing a local
  field would duplicate it. Keyed on the existing lifecycle `state` instead.
- **A second action targeting the vendor (Payee) via an `object-detail` relation.** Rejected —
  one action keeps the notification focused on the invoice; the 2-action budget is reserved.
