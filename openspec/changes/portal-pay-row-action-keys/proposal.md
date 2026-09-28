---
kind: code
depends_on: [arinvoice-lines-and-portal-amounts]
---

# Proposal: portal-pay-row-action-keys

## Summary

Portaliq #805 gives a guardian a Pay now button on each open school contribution
row, but only for a row action that says which body key carries the row's id
(`rowField`) and, optionally, which rows may offer it (`rowWhen`). Shillinq's
`pay` action declares neither, so the portal shows the list without a button.
This change declares them on `pay`, shows the voluntary notice on the parent's
invoice cards (`noticeField`), stops offering `pay` on payment request rows
(their id is a request, not an invoice), and lets an operator set where the
checkout returns to (`portal_payment_redirect_url`).

## Motivation

Decision D30 (Ruben, 2026-09-27): "The portaliq pay screen for a school
contribution is built now on shillinq's contribution invoices." Portaliq built
it in #805 (open, `feat/contribution-pay-screen`), and its PR body names the
shillinq follow-up. Reading `development` and #805's diff on 2026-09-28:

1. `RowActionResolver` keeps a row action only when the endpoint action has a
   `rowField`; `pay` has none, so no button appears.
2. #805 evaluates `rowWhen` as `$row[field]`, a flat key. The suggested
   `field: state` never matches an ARInvoice row, whose lifecycle field is
   `lifecycleState` (the round 2 lane fixed the same `state` versus
   `lifecycleState` bug in the pay receiver). The key must be `lifecycleState`.
3. `paymentRequests` names `pay` as its row action. Its rows are payment
   requests, so `{invoiceId: <request id>}` would always be refused.
4. The checkout's return address is read from `portal_payment_redirect_url`, an
   app config key no screen can set; without it the payer lands on the
   Nextcloud root instead of the portal.

## Affected Projects

- [ ] Project: `shillinq`: the `pay` action keys, the parent `noticeField`, the
  `paymentRequests` row action, the settings field and key.
- [ ] Project: `portaliq`: consumes the keys (#805). No code here.

## Scope

### In Scope

- `pay`: `rowField: invoiceId`, `rowWhen: {field: lifecycleState, in: [issued,
  partially-paid, overdue]}` (the receiver's own payable states).
- The parent `salesInvoices` collection: `noticeField: invoiceNote`.
- `paymentRequests` (customer and parent) no longer names a row action.
- `portal_payment_redirect_url` in the admin settings: a field in the settings
  form and a key the settings API reads and writes, accepted only as an absolute
  `https` address.

### Out of Scope

- `decline` as a row action. `rowWhen` reads a flat field, and whether a
  contribution is voluntary is nested (`contribution.voluntary`), so a row filter
  cannot offer it on voluntary rows only. It stays a plain action.
- Anything in portaliq.

## Approach

Pure manifest data on `PortalContributionProvider`, plus one settings key. The
`pay` action gains `rowField` and `rowWhen`; `rowWhen` names `lifecycleState` and
the states `PortalPaymentSessionService` accepts as payable, so the portal offers
the button exactly where the receiver would take it. The parent invoice cards
name `invoiceNote` as their notice. `paymentRequests` loses its row action;
`requestPayments` keeps `pay-request` from the previous change. The settings
service manages `portal_payment_redirect_url` and refuses anything but an empty
value or an absolute `https` address; the settings form shows the field.

## New Dependencies

None.

## Impact

- `lib/Portal/PortalContributionProvider.php`.
- `lib/Service/SettingsService.php`, `lib/Controller/SettingsController.php`,
  `src/views/settings/Settings.vue`, `l10n/*`.
- `docs/api/portal-payments.md`.

## Cross-Project Dependencies

- Stacked on `arinvoice-lines-and-portal-amounts` (#1728), which is stacked on
  #1724. All three edit the portal provider. Land in order.
- Portaliq #805 reads the keys. Until it merges, portaliq's current normaliser
  ignores `rowField`, `rowWhen` and `noticeField`, so nothing changes for users.

## Risks

### Risk 1: The button shows on a row the receiver refuses
**Severity:** Medium. **Mitigation:** `rowWhen` uses the receiver's own payable
states on the field the row carries; a test pins them to
`PortalPaymentSessionService::PAYABLE_STATES`.

### Risk 2: An operator points the return address somewhere unsafe
**Severity:** Low. **Mitigation:** only an absolute `https` address or empty is
stored; the setting is admin-only.

## Rollback Strategy

Revert the PR. The keys are extra manifest data; the setting key stays in app
config and the pay flow keeps reading it.
