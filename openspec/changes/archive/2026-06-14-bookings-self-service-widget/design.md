# Design — Booking Self-service Widget

**status: draft**

## Context

Partner websites (salons, clinics, beauty studios) need to offer appointment booking without hosting their own Nextcloud instance. The Nextcloud Bookings app currently provides:

- `bookings-create-appointment` — core appointment creation flow (admin + REST API)
- `bookings-resource-calendar` — resource availability management
- `bookings-service-catalog` — service definitions (duration, pricing)

What is missing: a white-label, embeddable JavaScript widget that partners can drop into their website and offer instant booking to customers.

## Goals

- **Partner self-service** — partners can embed the widget with minimal configuration (one API key + business ID)
- **Zero Nextcloud knowledge** — partners don't need to understand Nextcloud, PHP, or databases; widget is a drop-in script
- **White-label branding** — widget respects partner's brand colors, fonts, and logo via CSS variables
- **Multi-deployment** — iframe, script tag, npm package, and web component for flexibility
- **Performance** — slot availability is cached; widget loads in <2s on average internet connection
- **Mobile-friendly** — responsive, touch-optimized date/time picker
- **Accessibility** — WCAG 2.1 AA compliance (keyboard navigation, ARIA labels)

## Non-Goals

- **Payment integration** — payments handled by separate spec (bookings-deposits)
- **Appointment management** — customers cannot cancel/reschedule via widget (separate portal)
- **Admin features** — no admin dashboard; widget is customer-facing only
- **Offline support** — widget requires internet connectivity

## Decisions

### D1 — Separate Public API Controller for Widget

**Decision**: Create a new `WidgetApiController` at `lib/Controller/WidgetApiController.php` with routes prefixed `/ocs/v2.php/apps/bookings/api/v1/public/widget/...`.

**Why**: Cleanly separates public widget API from admin/protected APIs. Rate-limiting and auth rules are explicit. Widget endpoints return minimal data (safe public subset only).

**Alternative**: Reuse existing appointment API controller. Rejected — mixes public + admin concerns, harder to audit data exposure.

### D2 — Business ID + API Key Authentication (Not OAuth)

**Decision**: Widget authentication uses a simple `business_id` + `api_key` pair (HTTP header: `Authorization: Bearer {api_key}`). No OAuth, no OIDC.

**Why**: Partners are developers/integrators; OAuth adds complexity. API keys are easier to rotate monthly. business_id in the key itself allows efficient audit logging.

**Alternative**: OAuth2 with Nextcloud IDP. Rejected — requires partner to run Nextcloud OAuth server; adds friction.

### D3 — CSS Variables for Customization (Not Component Props)

**Decision**: Widget uses CSS custom properties (`--wsw-primary-color`, `--wsw-font-family`, etc.) for theming. Partners set these via `<style>` tags or inline styles on parent element.

**Why**: CSS variables are standard, work in all modern browsers, no JavaScript required to theme. Partner can inline theme in HTML without API calls.

**Alternative**: Pass theme config via JavaScript API (e.g., `BookingWidget.init({primaryColor: '#...'})`. Rejected — requires JavaScript knowledge; CSS variables are simpler.

### D4 — Iframe for Maximum Isolation

**Decision**: iframe mode is the recommended embed method for maximum style isolation. Script tag mode is also supported but CSS leakage is possible.

**Why**: iframe prevents partner CSS from breaking the widget. Cross-origin iframe (same-origin recommended) enforces security boundary.

**Alternative**: Shadow DOM only. Rejected — doesn't prevent all CSS cascade; iframe is more robust.

### D5 — Slot Caching (5-Minute TTL)

**Decision**: Available time slots are cached for 5 minutes. Cache is invalidated on appointment create or resource/service changes.

**Why**: Slot computation (checking conflicts with existing appointments) is expensive. Caching reduces queries by 95% on typical high-traffic days.

**Alternative**: Real-time slots (no cache). Rejected — would require WebSocket or frequent polling; unsustainable at scale.

### D6 — Public Data Subset Only

**Decision**: Widget API returns only safe-public fields:
- **Service**: `serviceId`, `name`, `duration`, `description`, `price` (if enabled)
- **Resource**: `resourceId`, `name`, `location` (if enabled)
- **Available Slots**: `startTime`, `endTime`, `resourceId` (no customer/appointment details)
- **Appointment create response**: `appointmentId`, `status`, `confirmationMessage` only (no customer email echoed back)

**Why**: Prevents accidental data leaks. Widget never exposes customer PII (email, phone) via public API.

**Alternative**: Return all fields, filter on client. Rejected — client-side filtering is unreliable; backend must enforce.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Appointment create | `bookings-create-appointment` spec + REST API | Widget calls `POST /ocs/v2.php/apps/openregister/api/objects/bookings/Appointment` via public widget key |
| Service list | `bookings-service-catalog` Service register | Widget queries `GET /ocs/v2.php/apps/bookings/api/v1/public/widget/services` (new endpoint wrapping Service schema) |
| Resource availability | `bookings-resource-calendar` Resource register | Widget queries `GET /ocs/v2.php/apps/bookings/api/v1/public/widget/slots` (computes from Resource + Appointment conflicts) |
| Time slot picker | (new) | Custom Vue component with browser-local timezone handling + UTC conversion |
| Email validation | (new) | HTML5 `<input type="email">` + RFC 5322 regex server-side validation |
| Rate-limiting | Nextcloud rate-limit middleware (existing) | Apply per `business_id` + IP via custom middleware |
| CORS handling | Nextcloud CORS (existing) | Enable CORS for iframe cross-origin access (if partner domain differs) |
| Audit logging | OR audit trail + Nextcloud system log | Widget API calls logged with business_id, endpoint, timestamp, response code |

## Seed Data

No seed data for widgets (widgets are created at runtime via partner signup). Test fixtures include:

- **Sample business**: businessId: "demo-001", name: "Demo Salon"
- **Sample service**: serviceId: "svc-haircut", name: "Haircut", duration: 45 min, price: 35 EUR
- **Sample resource**: resourceId: "res-chair-1", name: "Chair 1", availability: 09:00–18:00
- **Sample appointments**: 3–5 booked slots on demo day for testing conflict detection

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Controller/WidgetApiController.php` is created with public widget endpoints
2. `src/Service/WidgetAuthService.php` is created for API key validation
3. `src/components/SelfServiceWidget.vue` is created (main widget component)
4. `src/components/WidgetEmbed.js` is created (embed loader)
5. `src/styles/widget.css` is added (widget base styles + CSS variables)
6. `widget/` directory is created (npm package source)
7. `widget/package.json` is created with exports for all 4 embed methods
8. Database migration adds `WidgetAccessKey` table: `{keyId, businessId, apiKey, created, rotatedAt, rateLimit, isActive}`
9. `src/manifest.json` is patched with a new "API Keys" admin page for key management (settings)
10. Tests added: 15+ unit + integration tests

Down-direction: delete widget API routes, remove embed loaders, delete WidgetAccessKey table (non-destructive, allows data archival).

## Example: Embed Methods

### 1. Iframe (Recommended)

```html
<iframe src="https://bookings.example.com/ocs/v2.php/apps/bookings/widget/iframe?businessId=salon-001&lang=nl"
        width="100%"
        height="800"
        frameborder="0"
        allow="geolocation">
</iframe>
```

**Pros**: Maximum isolation, no CSS leakage, works on any website.
**Cons**: Requires sizing management, cookie access limited.

### 2. Script Tag

```html
<div id="nextcloud-booking-widget"></div>
<script src="https://bookings.example.com/widget.js"></script>
<script>
  BookingWidget.init({
    businessId: "salon-001",
    containerId: "nextcloud-booking-widget",
    lang: "nl",
    primaryColor: "#ff6b6b"
  });
</script>
```

**Pros**: Simple, inlines in page, CSS variables accessible.
**Cons**: CSS cascade possible, requires JavaScript.

### 3. npm Package

```bash
npm install @nextcloud-bookings/widget
```

```javascript
import { BookingWidget } from '@nextcloud-bookings/widget';

export default function App() {
  return (
    <BookingWidget
      businessId="salon-001"
      lang="nl"
      primaryColor="#ff6b6b"
    />
  );
}
```

**Pros**: Framework-native, TypeScript support, tree-shakeable.
**Cons**: Requires build step, more complex for non-developers.

### 4. Web Component

```html
<nextcloud-booking-widget
  business-id="salon-001"
  lang="nl"
  primary-color="#ff6b6b">
</nextcloud-booking-widget>

<script src="https://bookings.example.com/widget-wc.js"></script>
```

**Pros**: Framework-agnostic, works in any HTML.
**Cons**: Custom elements have limited browser support in older versions.

## CSS Customization Example

```html
<style>
  :root {
    --wsw-primary-color: #ff6b6b;
    --wsw-secondary-color: #ffa94d;
    --wsw-font-family: "Playfair Display", serif;
    --wsw-spacing-unit: 16px;
    --wsw-border-radius: 8px;
    --wsw-dark-mode: 0; /* 0=light, 1=dark */
  }
</style>
<div id="booking-widget"></div>
<script src="https://bookings.example.com/widget.js"></script>
```

## Open Questions

1. **Multi-language strings** — should language be hardcoded per business, or selected by customer at load time? If runtime selection, does widget need to request translation strings dynamically?
2. **CAPTCHA selection** — Google reCAPTCHA, hCaptcha, or custom challenge? Partners may have privacy concerns with reCAPTCHA.
3. **Theme inheritance** — should widget detect parent page colors (e.g., via CSS variables) automatically, or require explicit configuration? How much auto-detection vs. manual control?
4. **Appointment confirmation** — should confirmation email be mandatory, or optional per business? Some businesses may not have email set up.
5. **Phone field requirement** — should phone be mandatory, optional, or hidden? Salons need phone; clinics may not. Per-business setting needed.
