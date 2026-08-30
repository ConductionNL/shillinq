# @conduction/bookings-widget

Embeddable booking widget for [Shillinq](https://github.com/ConductionNL/shillinq) /
Conduction bookings (REQ-WSW-004).

## Embed methods

### 1. Iframe (maximum isolation)

```html
<iframe src="https://shillinq.example.com/index.php/apps/shillinq/widget/iframe?businessId=salon-001&apiKey=bk_live_xxx"
        width="100%" height="800" frameborder="0"></iframe>
```

### 2. Script tag

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

### 3. npm (React / Vue / framework apps)

```bash
npm install @conduction/bookings-widget
```

```js
import { BookingWidget } from '@conduction/bookings-widget'

BookingWidget.init({
  businessId: 'salon-001',
  apiBase: 'https://shillinq.example.com/index.php/apps/shillinq',
  apiKey: 'bk_live_xxx',
  containerId: 'booking-widget',
})
```

### 4. Web component

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

## CSS customisation

All four methods read theme tokens from CSS custom properties. The available
tokens are documented in `src/styles/widget.css`.

```html
<style>
  :root {
    --wsw-primary-color: #ff6b6b;
    --wsw-secondary-color: #ffa94d;
    --wsw-font-family: "Playfair Display", serif;
    --wsw-border-radius: 8px;
  }
</style>
```

## License

EUPL-1.2.
