---
sidebar_position: 20
title: Booking self-service widget
description: Embed Shillinq self-service appointment booking on any salon or clinic website via iframe, script tag, npm package, or web component.
---

# Booking self-service widget

The booking self-service widget is a white-label, embeddable component that lets
any salon or clinic website offer appointment booking without hosting its own
Nextcloud instance. It talks to the host Shillinq instance over a public,
API-key-authenticated API and renders service selection, slot availability,
customer details, and confirmation entirely in the partner's page.

## How it works

```
Partner website  ──(Bearer API key)──▶  Shillinq widget API  ──▶  OpenRegister
   widget.js                              /api/widget/{businessId}/…    BookingService
                                                                       BookingResource
                                                                       Appointment
```

- **Read endpoints** (`services`, `slots`) return only safe-public fields — name,
  duration, optional price, and available times. No customer data is ever returned.
- **Write endpoint** (`appointments`) accepts customer name, email, phone, and
  notes; these are write-only and never echoed back.
- Every request must carry `Authorization: Bearer {api_key}` and is rate-limited
  to 100 requests/minute per business by default.

## 1. Issue an API key

An administrator opens **Settings → Widget API keys** in Shillinq, enters the
business identifier, and clicks **Generate key**. The plaintext key is shown
once and never re-readable — copy it immediately. Keys can be rotated (the
previous key keeps working for a 7-day grace window) or revoked instantly.

## 2. Embed methods

### Iframe

```html
<iframe src="https://bookings.example.com/booking-iframe.html?businessId=salon-demo"
        width="100%" height="800" frameborder="0"></iframe>
```

### Script tag

```html
<div id="booking-widget"></div>
<script src="https://bookings.example.com/apps/shillinq/widget.js"></script>
<script>
  BookingWidget.init({
    businessId: 'salon-demo',
    apiKey: 'bk_live_...',
    apiBase: 'https://bookings.example.com',
    containerId: 'booking-widget',
    lang: 'nl',
    primaryColor: '#21468b',
  })
</script>
```

### Web component

```html
<nextcloud-booking-widget
  business-id="salon-demo"
  api-key="bk_live_..."
  api-base="https://bookings.example.com"
  lang="nl">
</nextcloud-booking-widget>
<script src="https://bookings.example.com/apps/shillinq/widget.js"></script>
```

### npm package

```bash
npm install @shillinq/booking-widget
```

```js
import { SelfServiceWidget } from '@shillinq/booking-widget'
```

## 3. Customisation

Override the `--wsw-*` CSS custom properties (or pass `primaryColor`) to match
your brand. Dark mode is enabled with `--wsw-dark-mode: 1` or the `dark` config
flag.

```html
<style>
  .wsw {
    --wsw-primary-color: #ff6b6b;
    --wsw-font-family: "Playfair Display", serif;
    --wsw-border-radius: 8px;
  }
</style>
```

## API reference

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET`  | `/index.php/apps/shillinq/api/widget/{businessId}/services` | List public services. |
| `GET`  | `/index.php/apps/shillinq/api/widget/{businessId}/slots?serviceSlug=…&date=YYYY-MM-DD` | Available slots (ETag-cached). |
| `POST` | `/index.php/apps/shillinq/api/widget/{businessId}/appointments` | Create an appointment. |

All endpoints require `Authorization: Bearer {api_key}`. Responses:
`401` (invalid/missing key), `429` (`Retry-After: 60`), `404` (unknown service),
`409` (slot just booked), `400` (validation error), `201`/`200` on success.

## Internationalisation

The widget ships English and Dutch strings and is selected per embed via the
`lang` option. Times are shown in the visitor's browser timezone and stored in
UTC.

## Security notes

- API keys are stored only as password hashes; the plaintext is shown once.
- Customer PII is write-only and never returned by read endpoints.
- HTTPS is enforced by the Nextcloud framework; CORS origins are configurable
  per business (no wildcard).
