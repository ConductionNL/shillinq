---
title: Booking Self-service Widget — embedding
---

The Booking Self-service Widget can be embedded in any partner website without
hosting a Nextcloud instance. The four supported embed methods all expose the
same configuration surface (REQ-WSW-004).

## Prerequisites

1. The administrator has minted a `WidgetAccessKey` for the partner business
   via the Shillinq admin settings. The plaintext key is shown once and
   stored only as a bcrypt hash (REQ-WSW-009).
2. The partner has noted the `businessId` and the plaintext key.
3. The partner has chosen the Shillinq instance API base URL, for example
   `https://shillinq.example.com/index.php/apps/shillinq`.

## 1. Iframe — maximum isolation

```html
<iframe src="https://shillinq.example.com/index.php/apps/shillinq/widget/iframe?businessId=salon-001&apiKey=bk_live_xxx&lang=nl"
        width="100%"
        height="800"
        frameborder="0">
</iframe>
```

Pros: full CSS isolation; partner CSS cannot affect the widget. Cons: requires
manual height management; cross-origin cookies are unavailable.

## 2. Script tag

```html
<div id="booking-widget"></div>
<script src="https://shillinq.example.com/index.php/apps/shillinq/widget.js"></script>
<script>
  BookingWidget.init({
    businessId: 'salon-001',
    apiBase: 'https://shillinq.example.com/index.php/apps/shillinq',
    apiKey: 'bk_live_xxx',
    containerId: 'booking-widget',
    lang: 'nl',
    primaryColor: '#ff6b6b',
  })
</script>
```

Pros: simple drop-in, partners can override CSS variables. Cons: partner CSS
may cascade into the widget if selectors collide.

## 3. npm

```bash
npm install @conduction/bookings-widget
```

```js
import { BookingWidget } from '@conduction/bookings-widget'

BookingWidget.init({
  businessId: 'salon-001',
  apiBase: 'https://shillinq.example.com/index.php/apps/shillinq',
  apiKey: process.env.SHILLINQ_API_KEY,
  containerId: 'booking-widget',
})
```

## 4. Web component

```html
<nextcloud-booking-widget
  business-id="salon-001"
  api-base="https://shillinq.example.com/index.php/apps/shillinq"
  api-key="bk_live_xxx"
  lang="nl"
  primary-color="#ff6b6b">
</nextcloud-booking-widget>

<script src="https://shillinq.example.com/index.php/apps/shillinq/widget-wc.js"></script>
```

## Customising appearance

The widget reads theme tokens from CSS custom properties. Drop a stylesheet
before the widget mounts:

```css
:root {
  --wsw-primary-color: #ff6b6b;
  --wsw-font-family: "Playfair Display", serif;
  --wsw-border-radius: 8px;
}
```

See [`src/styles/widget.css`](../../src/styles/widget.css) for the full token list.

## Error states

The widget renders user-friendly recovery flows for the documented error
conditions (REQ-WSW-008):

- 409 Conflict — "This slot was just booked. Please select another time."
  followed by a refreshed slot list.
- 404 Not Found — "This service is no longer available. Please refresh the page."
- 401/403 — "Configuration error. Please contact the website owner."
- 500 — "Something went wrong. Our team has been notified. Please try again later."
- Network failure — "Network error. Please check your connection and try again."
  with a retry button.

## Security notes

- Always rotate API keys every 30 days; the old key remains active 7 days as
  a grace period.
- Configure allowed CORS origins per business in the admin settings UI.
- Never embed the plaintext API key in client-side source committed to a public
  repository.
