<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# @shillinq/booking-widget

Embeddable self-service booking widget for the [Shillinq](https://conduction.nl) Nextcloud app.
One bundle, four embed methods — iframe, script tag, npm package, and web component.

The widget reaches the host Shillinq instance's public, API-key-authenticated
endpoints. Customer details (name, email, phone) are write-only; the public API
never returns customer PII.

## Configuration

| Option         | Type    | Required | Description                                   |
| -------------- | ------- | -------- | --------------------------------------------- |
| `businessId`   | string  | yes      | Public business identifier.                   |
| `apiKey`       | string  | yes      | Per-business API key (Bearer token).          |
| `apiBase`      | string  | no       | Base URL of the Shillinq host.                |
| `lang`         | string  | no       | UI language (`en`, `nl`). Default `en`.       |
| `dark`         | boolean | no       | Dark theme. Default `false`.                  |
| `primaryColor` | string  | no       | Brand colour override (`--wsw-primary-color`).|

## 1. Script tag

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

## 2. Web component

```html
<nextcloud-booking-widget
  business-id="salon-demo"
  api-key="bk_live_..."
  api-base="https://bookings.example.com"
  lang="nl"
  primary-color="#21468b">
</nextcloud-booking-widget>
<script src="https://bookings.example.com/apps/shillinq/widget.js"></script>
```

## 3. npm (Vue / React via the component)

```bash
npm install @shillinq/booking-widget
```

```js
import { SelfServiceWidget } from '@shillinq/booking-widget'
// register SelfServiceWidget in your Vue app and pass businessId / apiKey props
```

## 4. iframe

Serve a minimal HTML page that calls `BookingWidget.init(...)` and embed it:

```html
<iframe src="https://bookings.example.com/booking-iframe.html?businessId=salon-demo"
        width="100%" height="800" frameborder="0"></iframe>
```

## Theming

Override the `--wsw-*` CSS custom properties before the widget loads:

```html
<style>
  .wsw {
    --wsw-primary-color: #ff6b6b;
    --wsw-border-radius: 8px;
  }
</style>
```

## Publishing

Publishing to a registry is performed out-of-band by the release pipeline
(`npm publish`); it is intentionally not part of the app build.
