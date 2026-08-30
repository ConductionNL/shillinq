# Change: shillinq-ap-push-notifications

## Why

shillinq's accounts-payable register (`APTransaction`, declared in
`lib/Settings/register.d/bookkeeping-accounts-payable-core.json`) already carries a full
draft → received → issued → paid lifecycle with an `overdue` branch, but it dispatches **no
notifications** of its own. The companion `shillinq-notifications` change (kind: config)
overlaid `x-openregister-notifications` rules onto the AR side (`ARInvoice`) and the
purchase-order side (`PurchaseOrder`), and its `_meta` note claimed "APInvoice + PaymentRun
rules were already published with the AP-core schemas" — but the AP-core fragment in fact has
**no** notification block at all. Bookkeepers therefore get no signal when a supplier invoice
lands in the approval queue, and no reminder when one goes past its due date.

This change closes that gap by attaching two rules to `APTransaction` using the canonical
`x-openregister-notifications` dialect (ADR-031), and — crucially — uses the new
**title-vs-body content model** (`subject` → notification TITLE, `message` → BODY, per the
`notification-message-and-body` engine change) plus the **`web-push` delivery channel** and
**`actions[]` with an `object-detail` target** (per the `notification-actions-and-web-push`
engine change). The result: a bookkeeper whose browser is closed still gets a background
push reading "Approval needed: ENECO-2026-04-0001" with a one-tap "Open invoice" button that
deeplinks straight to the AP invoice detail view.

The "Open invoice" button needs a deeplink. `DeepLinkRegistrationListener` previously only
registered the `account` schema (T1). This change registers the `APTransaction` schema against
the existing `APInvoiceDetail` manifest route (`/bookkeeping/accounts-payable/:id`) so the
`object-detail` action target resolves to a real shillinq URL.

## What Changes

- **ADDED** `REQ-AP-012` — `APTransaction` SHALL declare two `x-openregister-notifications`
  rules (`approvalNeeded`, `overdue`) on the `nc-notification` + `web-push` channels, with
  `originApp: shillinq`, bilingual `subject` (title) + `message` (body), and a single primary
  `object-detail` action "Open invoice" / "Factuur openen".
- `lib/Settings/register.d/bookkeeping-accounts-payable-core.json` — adds the
  `x-openregister-notifications` block to the `APTransaction` schema (config-only overlay; no
  existing rule clobbered because the schema had none).
- `lib/Listener/DeepLinkRegistrationListener.php` — registers a deeplink for the
  `APTransaction` schema (`/apps/shillinq/bookkeeping/accounts-payable/{uuid}`) mirroring the
  existing `account` registration, so the `object-detail` action target resolves.

### Trigger compromise (noted)

`APTransaction` has **no approver / assignee field and no dedicated `approvalState` field** —
the `approvalState` enum lives on the `JournalEntry` schema (foundation fragment), not on AP.
The AP lifecycle's `received` state is documented as "awaiting approval/posting", and the
`received → issued` ("Issue / post invoice") transition is the approval+posting step. The
`approvalNeeded` rule therefore keys on `updated` with `condition {state == received}` — the
moment an invoice enters the approval queue — and recipients fall back to the
`shillinq-finance` group (mirroring the AR/PO rules) rather than a per-invoice approver. This
is a faithful mapping to the fields the schema actually declares; no fields were invented.

## Impact

- Affected spec: `bookkeeping-accounts-payable-core` (ADDED `REQ-AP-012`).
- Affected code: one register fragment (config overlay) + one event listener (one new
  `$event->register(...)` call).
- Runtime: depends on the OR notification engine supporting `message`, `actions`, `originApp`,
  and the `web-push` channel (the `notification-message-and-body` +
  `notification-actions-and-web-push` engine changes in hydra/openspec). Until `web-push` is
  live the rules still deliver via `nc-notification`; `web-push` is additive and degrades
  gracefully per the engine contract.
