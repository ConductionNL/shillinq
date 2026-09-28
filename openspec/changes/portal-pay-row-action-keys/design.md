# Design: portal-pay-row-action-keys

## Architecture Overview

```
PortalContributionProvider (pure data)
  actions.pay        + rowField invoiceId, rowWhen lifecycleState in PAYABLE_STATES
  actions.pay-request  (previous change) rowField paymentRequestId, rowWhen state pending
  customer.salesInvoices   rowAction pay
  customer.paymentRequests (no rowAction)
  customer.requestPayments rowAction pay-request
  parent.salesInvoices     rowAction pay, noticeField invoiceNote
portaliq #805 RowActionResolver -> PortalRowActionController -> {invoiceId: id} -> shillinq pay endpoint

Admin settings form -> PUT/POST /api/settings -> SettingsService (portal_payment_redirect_url, https or empty)
PortalPaymentSessionService reads the key as the checkout return address (unchanged)
```

## Decisions

### D1: `lifecycleState`, not `state`

#805 evaluates `rowWhen` as `$row[field]` on a flat key. An `ARInvoice` row has
`lifecycleState`; `state` does not exist on it, so `field: state` would hide the
button everywhere (or refuse every row with a 409). The previous lane fixed the
same mix-up in the pay receiver. The collection lists `lifecycleState` in its
fields (previous change), so the client can evaluate it too.

### D2: The receiver's states, in one place

`rowWhen.in` is `PortalPaymentSessionService::PAYABLE_STATES`. The provider stays
dependency-free (ADR-046 A1), so the list is written out, and a test compares it
with the receiver's constant through reflection.

### D3: `decline` stays a plain action

`rowWhen` cannot read `contribution.voluntary` (nested, and field names are flat).
Offering "I will not pay" on every open row would show a compulsory row a button
the receiver always refuses. A flat voluntary marker on the invoice would enable
it later; not added here.

### D4: The return address is validated where it is stored

`SettingsService::updateSettings()` refuses a non-empty value that is not an
absolute `https` URL by throwing `InvalidArgumentException`; the controller maps
that to a 400 and stores nothing. Empty keeps the instance-root fallback.

## Security Considerations

The row keys are data; portaliq still reads the row under the subject's scope and
shillinq's receiver still checks ownership. The return address is admin-only and
`https`-only, so it cannot become a `javascript:` or plain-http redirect.

## File Structure

```
lib/Portal/PortalContributionProvider.php
lib/Service/SettingsService.php
lib/Controller/SettingsController.php
src/views/settings/Settings.vue
l10n/en.json, l10n/nl.json (+ built js)
docs/api/portal-payments.md
```

## Seed Data

None: no schema changes.

## Declarative-vs-imperative decision

| Behaviour | Path | Why |
|---|---|---|
| row keys, notice field | declarative manifest data | ADR-046 |
| return address validation | imperative, settings service | input validation on a config write |
