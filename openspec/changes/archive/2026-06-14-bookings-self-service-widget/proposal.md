# Proposal: Booking Self-service Widget (embeddable JS)

`kind: feature` — deliver an embeddable JavaScript widget enabling any salon/clinic website to offer booking self-service without custom development.

## Summary

Introduce the **Booking Self-service Widget**, a white-label, embeddable JavaScript component that brings appointment booking to partner websites via iframe, script tag, or React/Vue integration. The widget displays available services, resources, time slots, and customer booking confirmation—all without requiring the partner to host a Nextcloud instance.

This change defines:

1. **Widget API contract** — how partners embed the widget and configure appearance/behavior
2. **Widget backend API** — RESTful endpoints for slot availability, booking confirmation, and customer notifications
3. **Branding & customization** — CSS variables, dark mode, multi-language support
4. **Deployment methods** — iframe, npm package, script tag, framework integrations

The widget reuses the existing `Appointment`, `Service`, and `Resource` schemas (dependencies) but exposes them via a new, partner-safe public API with strict authentication and rate-limiting.

## Motivation

Competitor analysis (21/21 benchmarks: Acuity, Bookly, Fresha, cal.com, OpenTable, etc.) shows that **embeddable booking widgets are table-stakes**. Salon/clinic operators need:

- **Zero hosting overhead** — partner website operators don't manage Nextcloud; widget is SaaS-hosted
- **White-label appearance** — widget inherits partner's brand (logo, colors, fonts)
- **Easy integration** — copy-paste HTML or npm install; no backend work required
- **Mobile-first** — responsive design, works on phones and tablets
- **Accessibility** — WCAG 2.1 AA compliance for all user personas

Without an embeddable widget, Nextcloud Bookings cannot compete in the market. This feature directly addresses **demand evidence: 21/21 competitors**.

## Affected Projects

- [x] **Project: nextcloud-bookings** — adds widget frontend (`src/components/SelfServiceWidget.vue`, `src/components/widget-embed.js`), new controller `lib/Controller/WidgetApiController.php` for public widget endpoints, widget styling (`src/styles/widget.css`), npm package (`widget/`), documentation
- [x] **Project: openregister** — no new features required; widget consumes existing `Appointment`, `Service`, `Resource` schemas via public read-only API
- [x] **Project: distribution/hosting** — widget backend is hosted on partner's Nextcloud instance (or SaaS); deployment guide is new

## Scope

### In Scope

- **Widget frontend component** — Vue.js component (`SelfServiceWidget.vue`) supporting service + resource selection, time slot picker, appointment confirmation, and customer email validation
- **Widget embed methods** — 4 deployment options:
  1. Iframe: `<iframe src="https://bookings.example.com/widget?businessId=..."></iframe>`
  2. Script tag: `<script src="https://bookings.example.com/widget.js"></script>; <div id="booking-widget"></div>`
  3. npm package: `npm install @nextcloud-bookings/widget` for React/Vue apps
  4. Web component: `<nextcloud-booking-widget business-id="..."></nextcloud-booking-widget>` (custom element)
- **Widget API endpoints** — new public controller routes:
  - `GET /ocs/v2.php/apps/bookings/api/v1/public/widget/services` — list available services
  - `GET /ocs/v2.php/apps/bookings/api/v1/public/widget/slots` — list available time slots
  - `POST /ocs/v2.php/apps/bookings/api/v1/public/widget/appointments` — create appointment from widget
- **Customization** — CSS variables (brand colors, fonts, spacing), dark mode, per-language strings
- **Authentication** — business ID + API key (time-limited, rate-limited) for partners
- **Rate-limiting** — 100 requests/minute per business to prevent abuse
- **GDPR compliance** — privacy policy link, data collection transparency, customer consent (optional phone field)

### Out of Scope

- **Payment integration** — bookings-deposits and payment processors handled separately (phase 2)
- **Booking management dashboard** — customers cannot modify/cancel appointments via widget (separate portal spec)
- **Multi-language dynamic switching** — language is set at embed time, not runtime
- **Mobile apps** — widget is web-only; iOS/Android apps are separate specs
- **Calendar sync** — Google Calendar, Outlook sync handled in separate spec
- **Custom fields** — fixed field set (name, email, phone, notes) in phase 1

## Approach

Five deltas across two new specs:

**`bookings-self-service-widget`** — declares:
1. Widget API contract (REQ-WSW-NNN series)
2. Embed methods (iframe, script, npm, web component)
3. Customization schema (CSS variables, theming)
4. Rate-limiting and auth rules

**`bookings-widget-backend-api`** — declares:
1. Public widget endpoints (`/ocs/v2.php/apps/bookings/api/v1/public/widget/...`)
2. Authentication (business ID, API key)
3. Data exposure rules (which Service/Resource/Appointment fields are public)

Follows hydra conduction-schema format (RFC 2119, `### REQ-WSW-NNN`, `#### Scenario:` with GIVEN/WHEN/THEN).

## New Dependencies

**Existing dependencies assumed available**:
- `bookings-create-appointment` — provides `Appointment` register and create flow
- `bookings-resource-calendar` — provides `Resource` register with calendar
- `bookings-service-catalog` — provides `Service` register with services

**New external dependencies**:
- None (widget is vanilla JS + Vue; optional: Tailwind CSS for styling base)

## Impact

- `src/components/SelfServiceWidget.vue` — new file, main widget component
- `src/components/WidgetEmbed.js` — new file, embed loader for script tag / iframe modes
- `lib/Controller/WidgetApiController.php` — new file, public API endpoints
- `src/Service/WidgetAuthService.php` — new file, business ID + API key validation
- `src/styles/widget.css` — new file, widget styles + CSS variables
- `widget/` — new directory, npm package source (`package.json`, build config, exports)
- `docs/integration/widget-embed.md` — new guide for partners integrating the widget
- Tests — 15+ unit + integration tests covering embed methods, slots API, rate-limiting
- `src/manifest.json` — no changes (widget is public-facing, not admin navigation)

## Cross-Project Dependencies

- **Nextcloud Core** — depends on: public-access patterns (existing), CORS for iframe (existing)
- **OpenRegister** — depends on: public read-only API for Service/Resource/Appointment schemas (new, but low-effort wrapper)

## Risks

### Risk 1: Branding Leak via CSS/Theme Override

**Severity**: Medium
**Description**: Partner website CSS can unintentionally cascade into the widget iframe, breaking layout or exposing unintended styling.
**Mitigation**: Widget uses CSS-in-JS (styled-components or Vue scoped styles) and shadow DOM to isolate styling. iframe mode adds additional style boundary. CSS variable customization is explicit and validated.

### Risk 2: Rate-Limiting Bypass via Distributed Requests

**Severity**: Medium
**Description**: Attackers spoof multiple business IDs to circumvent per-business rate limits.
**Mitigation**: Rate limit is enforced per IP + business ID pair. API key must be rotated monthly. Widget requests include browser fingerprint (user agent + IP hash) logged for anomaly detection.

### Risk 3: Data Exposure: Leaking Private Booking Data

**Severity**: High
**Description**: Public widget API returns sensitive appointment details (customer email, phone, notes) to unauthenticated users.
**Mitigation**: Widget API endpoints return only safe-public data: service name, duration, price (if visible), resource name, available slots. Customer details are captured POST-appointment only, not queryable. Appointment details (customer name, email) are never returned via public API.

### Risk 4: Appointment Creation Spam

**Severity**: Medium
**Description**: Attackers bulk-create appointments via the public widget API, blocking legitimate customers.
**Mitigation**: Rate-limiting + CAPTCHA (optional per business setting) + appointment confirmation email (customer must confirm ownership).

### Risk 5: Performance: Slot Computation on High-Traffic Sites

**Severity**: Medium
**Description**: Slot availability computation is expensive (N+1 queries across Service + Resource + Appointment). High-traffic partner sites may see timeouts or slowness.
**Mitigation**: Phase 1 caches available slots for 5 minutes. Phase 2 introduces background job for slot pre-computation. Slots endpoint includes ETags and 304 Not Modified for further caching.

## Rollback Strategy

Spec-only change initially. To roll back:

1. Remove widget routes from `WidgetApiController`
2. Remove widget component and embed loader
3. Delete npm package build artifacts
4. Keep partner API keys in database (non-destructive)

After implementation:

1. Redirect widget embed URLs to a "deprecated" notice page
2. Keep public widget API available for 90 days (backward compatibility)
3. Notify active partners 30 days in advance
4. Decommission widget routes in a separate release

No data loss risk; widget records are queryable (appointments created via widget are regular appointments).

## Open Questions

1. **Free vs. paid tiers** — should the widget be free for all Nextcloud instances, or paid/limited per tier? Pricing decision needed before implementation.
2. **Multi-language widget** — should language be fixed at embed time (e.g., `?lang=nl`), or should the widget detect user browser language? UX decision needed.
3. **CAPTCHA requirement** — should CAPTCHA be mandatory to prevent spam, or optional per business? Security policy needed.
4. **Email confirmation** — should customers confirm their email before the appointment is confirmed, or is immediate confirmation acceptable? Define per business setting before implementation.
5. **Customer phone field** — is phone mandatory, optional, or hidden per business? Collect requirements from personas (salon vs clinic) before implementation.

## Timeline

- **Spec review & approval** — 1 week
- **Implementation (opsx-apply)** — 2–3 weeks (widget frontend + backend API + tests + docs)
- **Partner testing** — 1 week (test with 3–5 real partners)
- **Launch** — 1 week (npm package publish, docs, announcement)
