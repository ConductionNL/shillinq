# Spec: bookkeeping-accounts-payable-core (delta)

## ADDED Requirements

### Requirement: REQ-AP-012 — `APTransaction` SHALL declare web-push notification rules using the title-vs-body content model

The `APTransaction` schema SHALL declare an `x-openregister-notifications` block (ADR-031
dialect) with two rules — `approvalNeeded` and `overdue` — each delivered on the
`nc-notification` AND `web-push` channels, each carrying `originApp: shillinq`, each declaring
a bilingual `subject` (resolved as the notification TITLE) and a bilingual `message` (resolved
as the notification BODY, distinct from the title), and each declaring exactly one primary
`actions[]` entry whose `target` is `{ kind: object-detail }` so the action deeplinks to the
triggering AP invoice.

shillinq SHALL register a deeplink for the `APTransaction` schema with OpenRegister's
deeplink registry (`DeepLinkRegistrationListener`) targeting the existing `APInvoiceDetail`
manifest route (`/bookkeeping/accounts-payable/:id`), using the admin-configured register slug
(L3) rather than a hardcoded register, so the `object-detail` action target resolves to a real
shillinq URL.

The `approvalNeeded` rule SHALL fire when an AP invoice enters the approval queue. Because
`APTransaction` declares no approver, assignee, or `approvalState` field, the rule keys on the
lifecycle `state` field (`updated` trigger, condition `state == received`, the documented
"awaiting approval/posting" state) and recipients fall back to the `shillinq-finance` group —
mirroring the AR/PO notification rules — rather than a per-invoice approver. The `overdue` rule
SHALL be a daily `scheduled` rule (`intervalSec 86400`) filtering `state notIn [paid,
written-off, voided]` AND `dueDate before now`, mirroring the AR overdue filter shape.

No existing notification rule SHALL be clobbered (the schema declared none before this change).

#### Scenario: AP invoice enters the approval queue raises an approvalNeeded push

- **WHEN** an `APTransaction` is updated so its `state` becomes `received`
- **THEN** the engine dispatches a notification on `nc-notification` and `web-push` with
  `originApp: shillinq`, TITLE "Approval needed: {{invoiceNumber}}" / "Goedkeuring nodig:
  {{invoiceNumber}}" and BODY "This supplier invoice is in Shillinq. Open it?" / "Deze
  inkoopfactuur staat in Shillinq. Openen?", to the `shillinq-finance` group

#### Scenario: Title and body are distinct (title-vs-body content model)

- **WHEN** the `approvalNeeded` or `overdue` rule fires
- **THEN** the notification title is the resolved `subject` and the notification body is the
  resolved `message`, and the two are not forced to be identical

#### Scenario: Open invoice action deeplinks to the AP invoice detail

- **WHEN** a recipient clicks the primary "Open invoice" / "Factuur openen" action on either
  rule's notification
- **THEN** the engine resolves the `object-detail` target to the triggering `APTransaction`
  via shillinq's registered deeplink and opens `/apps/shillinq/bookkeeping/accounts-payable/{uuid}`

#### Scenario: Background web-push delivery when all tabs are closed

- **WHEN** the `approvalNeeded` or `overdue` rule fires for a finance-group recipient who has
  an active push subscription but no open Nextcloud tab
- **THEN** the encrypted web-push is delivered and the Service Worker shows the rich
  notification in the background, and a click opens the resolved AP invoice deeplink

#### Scenario: Overdue sweep notifies on unpaid past-due invoices

- **WHEN** the daily `overdue` scheduled rule (`intervalSec 86400`) runs and an `APTransaction`
  has `dueDate` before now and `state` not in `[paid, written-off, voided]`
- **THEN** the engine dispatches a notification to the `shillinq-finance` group with TITLE
  "Invoice overdue: {{invoiceNumber}}" / "Factuur over de vervaldatum: {{invoiceNumber}}" and
  BODY "This invoice is overdue in Shillinq. Open it?" / "Deze factuur is vervallen in
  Shillinq. Openen?"
