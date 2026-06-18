---
status: done
---

# Spec: bookings-self-service-widget

**Status:** proposed
**Scope:** nextcloud-bookings
**Tier:** T1 (core feature)
**Depends on:** bookings-create-appointment, bookings-resource-calendar, bookings-service-catalog

## Purpose

This specification defines the requirements for bookings self service widget in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: self-service booking widget not yet implemented


### REQ-WSW-001: Widget API SHALL expose public endpoints for service list, slot availability, and appointment creation

The widget backend MUST provide the following HTTP endpoints under `/ocs/v2.php/apps/bookings/api/v1/public/widget/`:

1. **GET `/services`** — returns available services (name, duration, price, description)
2. **GET `/slots`** — returns available appointment slots for a given service + date range
3. **POST `/appointments`** — creates a new appointment from widget

All endpoints MUST require authentication via `Authorization: Bearer {api_key}` header. Responses MUST include only safe-public fields (no customer PII). Rate-limiting MUST be enforced: 100 requests/minute per `business_id`.

#### Scenario: Partner retrieves available services via widget API

- **GIVEN** a widget API key for business "salon-001"
- **WHEN** calling `GET /ocs/v2.php/apps/bookings/api/v1/public/widget/services` with header `Authorization: Bearer {api_key}`
- **THEN** the response MUST be HTTP 200 with JSON array: `[{serviceId, name, duration, price, description}, ...]`

#### Scenario: Unauthenticated request is rejected

- **GIVEN** no API key provided
- **WHEN** calling the widget API endpoint
- **THEN** the response MUST be HTTP 401 Unauthorized with error `{error: "Invalid or missing API key"}`

#### Scenario: Rate-limit exceeded

- **GIVEN** business "salon-001" has made 100 requests in the current minute
- **WHEN** the 101st request arrives
- **THEN** the response MUST be HTTP 429 Too Many Requests with header `Retry-After: 60`

### REQ-WSW-002: Widget SHALL list available appointment slots with conflict detection

The `GET /slots` endpoint MUST return all available time slots for a given service and date range. Slots MUST exclude:
- Times outside resource operational hours
- Times with existing confirmed appointments (double-booking prevention)
- Times before the current time (past slots)
- Times when the service is marked unavailable

#### Scenario: Retrieve slots for a service on a given date

- **GIVEN** Service "haircut" (duration: 45 min) and Resource "chair-1" (availability: 09:00–18:00)
- **AND** existing confirmed appointment: 10:00–10:45 on 2026-05-22
- **WHEN** calling `GET /slots?serviceId=haircut&resourceId=chair-1&date=2026-05-22`
- **THEN** the response MUST include slots: 09:00–09:45, 10:45–11:30, 11:30–12:15, ... (excluding 10:00–10:45)

#### Scenario: Slots endpoint caches results for 5 minutes

- **GIVEN** the first request for slots on 2026-05-22 at 10:00 UTC
- **WHEN** the same request is made at 10:02 UTC (2 minutes later)
- **THEN** the response MUST include ETag header and be served from cache (no re-computation)

#### Scenario: Cache is invalidated after appointment creation

- **GIVEN** cached slots for 2026-05-22
- **WHEN** a new appointment is created for a slot on 2026-05-22
- **THEN** the cache MUST be invalidated
- **AND** the next `GET /slots` request MUST re-compute and return updated availability

### REQ-WSW-003: Widget frontend component SHALL support service and resource selection with time slot picker

The Vue component `SelfServiceWidget.vue` MUST render:

1. **Service selector** — dropdown or carousel listing available services with durations and prices
2. **Resource selector** (optional) — if service has multiple resources, dropdown to select preferred resource
3. **Date picker** — calendar allowing customer to select a date (must prevent past dates)
4. **Time slot picker** — list or grid of available times for selected date, displayed in customer's local timezone
5. **Customer details form** — fields for name, email, phone (phone optional), notes
6. **Confirmation summary** — review screen showing selected service, date, time, customer name before submit
7. **Submit button** — creates appointment; shows loading state during submission

All fields MUST use i18n strings (English and Dutch). The component MUST be accessible (WCAG 2.1 AA) with keyboard navigation and ARIA labels.

#### Scenario: Customer selects service and books a time slot

- **GIVEN** widget is loaded with businessId: "salon-001"
- **WHEN** customer selects "Haircut" (45 min) and date 2026-05-22
- **THEN** the widget MUST fetch available slots and display: 09:00, 09:45, 10:45, 11:30, ... (skipping booked 10:00–10:45)
- **AND** customer selects 09:45 and enters name: "Alice Smith", email: "alice@example.com"
- **AND** customer clicks "Confirm Booking"
- **THEN** the widget MUST POST appointment with startTime: "2026-05-22T09:45:00Z", endTime: "2026-05-22T10:30:00Z"

#### Scenario: Widget prevents booking in the past

- **GIVEN** today is 2026-05-21
- **WHEN** customer attempts to select a past date (e.g., 2026-05-20)
- **THEN** the date picker MUST disable past dates (gray out)
- **AND** user MUST NOT be able to select past dates

#### Scenario: Widget displays error when slot becomes unavailable during selection

- **GIVEN** customer is selecting appointment details for slot 10:00–10:45 on 2026-05-22
- **WHEN** another customer books the same slot (race condition)
- **THEN** on submit, the API MUST return HTTP 409 Conflict
- **AND** the widget MUST display error: "This slot was just booked. Please select another time."
- **AND** the widget MUST refresh available slots and allow re-selection

### REQ-WSW-004: Widget SHALL support 4 embed methods with equivalent UX

The widget MUST be deployable via:

1. **Iframe** — `<iframe src="https://bookings.example.com/ocs/v2.php/apps/bookings/widget/iframe?businessId=..."></iframe>`
2. **Script tag** — `<script src="https://bookings.example.com/widget.js"></script>` with `BookingWidget.init({...})`
3. **npm package** — `npm install @nextcloud-bookings/widget; import { BookingWidget } from '@nextcloud-bookings/widget'`
4. **Web component** — `<nextcloud-booking-widget business-id="..."></nextcloud-booking-widget>`

All 4 methods MUST render identical UX and accept identical configuration options (businessId, lang, primaryColor, etc.). The app MUST publish the npm package to npmjs.com with proper TypeScript definitions.

#### Scenario: Partner embeds widget via iframe

- **GIVEN** partner website at salons.example.com
- **WHEN** partner adds: `<iframe src="https://bookings.example.com/ocs/v2.php/apps/bookings/widget/iframe?businessId=salon-001"></iframe>`
- **THEN** the iframe MUST load and render the booking widget
- **AND** partner CSS MUST NOT affect widget styling (style isolation guaranteed by iframe boundary)

#### Scenario: Partner embeds widget via script tag

- **GIVEN** partner website markup with `<div id="booking-widget"></div>`
- **WHEN** partner adds: `<script src="https://bookings.example.com/widget.js"></script>` + `BookingWidget.init({businessId: 'salon-001', containerId: 'booking-widget'})`
- **THEN** the widget MUST render inside the `<div>`
- **AND** the widget MUST be themable via CSS variables

#### Scenario: Partner imports widget as npm package in React app

- **GIVEN** React component `src/pages/booking.js`
- **WHEN** code imports: `import { BookingWidget } from '@nextcloud-bookings/widget'` and renders: `<BookingWidget businessId="salon-001" />`
- **THEN** the widget MUST render with React prop binding
- **AND** prop changes (e.g., businessId) MUST re-render widget (standard React behavior)

### REQ-WSW-005: Widget CSS SHALL be customizable via CSS variables

The widget MUST expose the following CSS custom properties (defaults shown):

```css
--wsw-primary-color: #0082c9 (Nextcloud blue)
--wsw-secondary-color: #f1f1f1 (light gray)
--wsw-text-color: #333333 (dark gray)
--wsw-border-color: #cccccc (gray)
--wsw-success-color: #2d7500 (green)
--wsw-error-color: #f83838 (red)
--wsw-font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif
--wsw-font-size-base: 16px
--wsw-spacing-unit: 8px (base unit for margin/padding)
--wsw-border-radius: 4px
--wsw-shadow: 0 1px 3px rgba(0, 0, 0, 0.12)
--wsw-dark-mode: 0 (set to 1 for dark theme)
```

Partners MAY override variables via `<style>` or inline styles before loading the widget. The widget MUST respect all customizations.

#### Scenario: Partner customizes widget colors

- **GIVEN** partner website with custom brand colors (primary: #ff6b6b, secondary: #ffa94d)
- **WHEN** partner adds: `<style>:root { --wsw-primary-color: #ff6b6b; --wsw-secondary-color: #ffa94d; }</style>` before the widget script
- **THEN** the widget MUST load and apply the custom colors to buttons, links, and accents

### REQ-WSW-006: Widget SHALL validate customer email and phone with user-friendly error messages

The customer details form MUST:

1. **Email validation** — enforce valid RFC 5322 format; required field
2. **Phone validation** — accept international format (optional); regex: `^\+?[1-9]\d{1,14}$`
3. **Name validation** — require 1–255 characters; no special characters except space and hyphen
4. **Notes validation** — allow 0–500 characters; no script injection

Validation errors MUST be shown inline with user-friendly messages (e.g., "Please enter a valid email address").

#### Scenario: Widget rejects invalid email

- **GIVEN** customer form with email field
- **WHEN** customer enters "alice@invalid" (no domain TLD)
- **THEN** the widget MUST show inline error: "Please enter a valid email address"
- **AND** the submit button MUST remain disabled until valid email is entered

#### Scenario: Widget accepts international phone numbers

- **GIVEN** customer form with phone field
- **WHEN** customer enters "+31612345678" (Dutch phone)
- **THEN** the widget MUST validate successfully
- **AND** the appointment MUST be created with phone: "+31612345678"

### REQ-WSW-007: Widget SHALL display timezone in local time and convert to UTC for storage

The widget MUST:

1. **Display times in customer's browser timezone** — detect via `Intl.DateTimeFormat()` or `moment-timezone`
2. **Store times in UTC** — convert selected local time to UTC before POST
3. **Label timezone** — display "Local Time" and timezone abbreviation (e.g., "CEST") below the time picker

#### Scenario: Customer in Amsterdam books appointment

- **GIVEN** customer's browser timezone is Europe/Amsterdam (CEST, UTC+2)
- **WHEN** customer selects "10:00" in the time picker
- **THEN** the widget MUST display: "10:00 AM (CEST)"
- **AND** on POST, startTime MUST be converted to UTC: "2026-05-22T08:00:00Z" (10:00 CEST = 08:00 UTC)

### REQ-WSW-008: Widget error states SHALL be user-friendly and provide recovery paths

The widget MUST handle these error conditions gracefully:

1. **Slot unavailable** (409 Conflict) — "This slot was just booked. Please select another time." + refresh slot list
2. **Service unavailable** (404 Not Found) — "This service is no longer available. Please refresh the page."
3. **Network error** — "Network error. Please check your connection and try again." + retry button
4. **Server error** (500) — "Something went wrong. Our team has been notified. Please try again later."
5. **Invalid API key** (401/403) — "Configuration error. Please contact the website owner."

All errors MUST be user-friendly (no stack traces or technical jargon). The widget MUST log errors server-side for debugging.

#### Scenario: Slot booking fails due to race condition

- **GIVEN** customer submits appointment for slot 10:00–10:45
- **WHEN** another customer just booked the same slot (409 Conflict response)
- **THEN** the widget MUST display: "This slot was just booked. Please select another time."
- **AND** the widget MUST reload available slots
- **AND** customer MUST be returned to the slot picker (not the form)

### REQ-WSW-009: Widget SHALL generate and store a business API key with secure rotation policy

The system MUST:

1. **Generate API keys** — upon partner signup, generate a strong 32-character random key (base64)
2. **Store securely** — keys MUST be hashed with bcrypt before storage (never plaintext)
3. **Rotation policy** — keys auto-rotate every 30 days; old key remains active for 7 days (grace period)
4. **Rate-limiting per key** — 100 requests/minute per business ID + API key
5. **Audit trail** — log all API key operations (created, rotated, revoked) with timestamp and actor

#### Scenario: Partner receives API key upon signup

- **GIVEN** partner signs up for Nextcloud Bookings SaaS
- **WHEN** signup completes
- **THEN** the system MUST email the partner an API key: `bk_live_xxxxx...` (never repeats)
- **AND** the key MUST be displayed once in the partner dashboard (not stored in plaintext)

#### Scenario: API key is rotated after 30 days

- **GIVEN** API key created on 2026-04-21
- **WHEN** current date is 2026-05-21 (30 days later)
- **THEN** the system MUST generate a new API key and email the partner
- **AND** the old key MUST remain active for 7 more days (until 2026-05-28)
- **AND** after 2026-05-28, the old key MUST be revoked (new key only)

### REQ-WSW-010: Widget SHALL support internationalization (i18n) for English and Dutch

The widget MUST display all user-facing strings in the customer's selected language. Supported languages:

- **English (en_US)** — default
- **Dutch (nl_NL)** — for Dutch salon/clinic operators and customers

Language is set at embed time via `lang` parameter (e.g., `?lang=nl`) and MUST NOT change during widget session. Strings MUST include all form labels, validation messages, error messages, button text, and success confirmations.

#### Scenario: Partner loads widget in Dutch

- **GIVEN** partner embeds widget with `?lang=nl`
- **WHEN** the widget loads
- **THEN** all strings MUST be in Dutch: "Afspraak Maken", "Dienst", "Datum", "Bevestigen", etc.

#### Scenario: Validation error message is localized

- **GIVEN** widget loaded with lang=nl
- **WHEN** customer enters invalid email
- **THEN** error message MUST display in Dutch: "Voer een geldig e-mailadres in"

## Implementation Notes

### Frontend Stack

- **Framework**: Vue.js 3 (follows nextcloud-bookings convention)
- **Styling**: CSS custom properties + scoped styles (avoid global CSS)
- **Date/Time**: browser-native `<input type="datetime-local">` for simplicity (converts to UTC server-side)
- **Validation**: custom validators (email, phone, name) + server-side validation re-check
- **Accessibility**: semantic HTML + ARIA labels (tested with WCAG validator)

### Backend Stack

- **Controller**: `WidgetApiController` extends `OCSController`
- **Auth**: `WidgetAuthService` validates `business_id` + `api_key` per request
- **Caching**: Redis (if available) or in-memory cache for slot availability (5-min TTL)
- **Rate-limiting**: Nextcloud middleware or custom rate-limiter per business_id

### Database Schema (new table: WidgetAccessKey)

```sql
CREATE TABLE oc_bookings_widget_access_keys (
  id INT PRIMARY KEY AUTO_INCREMENT,
  business_id VARCHAR(255) NOT NULL UNIQUE,
  api_key_hash VARCHAR(255) NOT NULL,
  rate_limit INT DEFAULT 100,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  rotated_at DATETIME NULL,
  revoked_at DATETIME NULL,
  is_active BOOLEAN DEFAULT TRUE,
  INDEX (business_id)
);
```

### Security Considerations

- **HTTPS only** — widget endpoints require HTTPS (enforced by Nextcloud framework)
- **CORS** — only cross-origin requests from authorized domains (configurable per business)
- **API key rotation** — keys rotated automatically every 30 days
- **Input validation** — all inputs (email, phone, notes) validated server-side before persistence
- **SQL injection** — not applicable (using OR CRUD + parameterized queries)
- **XSS** — Vue.js auto-escapes output; user-provided strings sanitized
- **CSRF** — not applicable (API key auth, not session-based)

## Test Coverage

- **Unit tests** (8 tests)
  - WidgetAuthService: valid/invalid key, rotation, revocation
  - WidgetApiController: endpoint routing, response schema validation
  - Slot computation: conflict detection, boundary conditions (past slots, operational hours)

- **Integration tests** (7 tests)
  - Widget API endpoints: 200/401/429 responses, cache behavior
  - Appointment creation via widget: valid/invalid inputs, timezone conversion

- **Accessibility tests** (2 tests)
  - Keyboard navigation (tab, enter, escape)
  - ARIA labels and form associations

- **Manual QA** (partner testing)
  - Embed all 4 methods (iframe, script, npm, web component) on test sites
  - Verify styling in different browsers (Chrome, Firefox, Safari)
  - Test on mobile (iOS Safari, Chrome Android)
