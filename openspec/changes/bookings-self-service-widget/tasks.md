# Tasks — Booking Self-service Widget

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookings-self-service-widget` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [ ] Task 1: Verify dependencies exist: scan `openspec/specs/` for `bookings-create-appointment`, `bookings-resource-calendar`, and `bookings-service-catalog`; confirm all are marked `status: approved`
- [ ] Task 2: Author `specs/bookings-self-service-widget/spec.md` with Status: proposed / Scope: nextcloud-bookings / Tier: T1 header, REQ-WSW-NNN requirements per RFC 2119, and `#### Scenario:` blocks with GIVEN/WHEN/THEN per hydra conventions (DONE — this artifact)
- [ ] Task 3: Create database migration `lib/Migration/VersionXXXX_AddWidgetAccessKeyTable.php` with table: `{id, business_id UNIQUE, api_key_hash, rate_limit, created_at, rotated_at, revoked_at, is_active}` with indexes on business_id and is_active
- [ ] Task 4: Implement `lib/Controller/WidgetApiController.php` with endpoints:
  - `GET /ocs/v2.php/apps/bookings/api/v1/public/widget/services` — return service list (serviceId, name, duration, price, description)
  - `GET /ocs/v2.php/apps/bookings/api/v1/public/widget/slots?serviceId=...&resourceId=...&date=...` — return available slots with conflict detection + ETag caching
  - `POST /ocs/v2.php/apps/bookings/api/v1/public/widget/appointments` — create appointment from widget payload
  - All endpoints require Authorization header with API key; enforce rate-limiting (100 req/min per business)
- [ ] Task 5: Implement `src/Service/WidgetAuthService.php` with methods:
  - `validateApiKey($businessId, $apiKey)` — hash-compare API key; return boolean + error message
  - `rotateApiKey($businessId)` — generate new key, hash, store in DB; keep old key active 7 days
  - `revokeApiKey($businessId)` — mark key as revoked; log to audit trail
  - `getBusinessIdFromKey($apiKey)` — reverse-lookup business ID from hashed key (for rate-limiting)
- [ ] Task 6: Implement slot availability computation in `src/Service/SlotService.php`:
  - `getAvailableSlots($serviceId, $resourceId, $dateStart, $dateEnd)` — query Resource calendar + Appointment records; return gaps without conflicts
  - Cache results for 5 minutes with ETag per service/resource/date combination
  - Validate times against resource operational hours and service duration
- [ ] Task 7: Implement `src/components/SelfServiceWidget.vue` Vue component with:
  - Service selector (dropdown or carousel with name, duration, price)
  - Resource selector (if applicable; optional based on service config)
  - Date picker (calendar; disable past dates)
  - Time slot picker (grid/list of available times in local timezone)
  - Customer form fields: name, email (required), phone (optional), notes (optional)
  - Confirmation summary screen (review before submit)
  - Submit button with loading state + success/error handling
  - Keyboard accessible (tab, enter, escape) + ARIA labels per WCAG 2.1 AA
- [ ] Task 8: Implement `src/components/WidgetEmbed.js` (embed loader):
  - Script tag mode: expose `window.BookingWidget.init({businessId, containerId, lang, primaryColor, ...})` API
  - iframe mode: generate iframe URL with query params; serve iframe content at `/ocs/v2.php/apps/bookings/widget/iframe`
  - Validate config parameters; set sensible defaults (lang=en_US, primaryColor=#0082c9)
- [ ] Task 9: Implement `src/styles/widget.css` with:
  - CSS custom properties for theming (--wsw-primary-color, --wsw-font-family, --wsw-spacing-unit, etc.)
  - Base widget styles (card, form, buttons, date picker)
  - Responsive breakpoints (mobile 320px, tablet 768px, desktop 1024px)
  - Dark mode support (toggle via --wsw-dark-mode: 0/1)
  - Print styles (hide widget in print context)
- [ ] Task 10: Create `widget/` npm package with:
  - `widget/package.json` with name: `@nextcloud-bookings/widget`, exports for all 4 embed methods (iframe, script, vue, web-component), TypeScript definitions
  - `widget/index.js` (main entry point; exports { BookingWidget, default: BookingWidget })
  - `widget/web-component.js` (custom element: `<nextcloud-booking-widget>` with attribute binding)
  - `widget/vue.js` (Vue component export; compatible with Vue 3)
  - `widget/tsconfig.json` with declaration generation
  - Build script: `npm run build` to bundle widget.js (minified) and generate .d.ts types
- [ ] Task 11: Implement error handling and user-friendly error messages:
  - 409 Conflict (slot unavailable) → "This slot was just booked. Please select another time." + refresh slots
  - 404 Not Found (service unavailable) → "This service is no longer available. Please refresh the page."
  - Network error → "Network error. Please check your connection and try again." + retry button
  - 500 Server error → "Something went wrong. Our team has been notified. Please try again later."
  - Invalid API key (401/403) → "Configuration error. Please contact the website owner."
  - Log all errors server-side with business_id, endpoint, timestamp, response code
- [ ] Task 12: Implement i18n strings in:
  - `src/locales/en_US.json` — English strings (Appointment, Create Appointment, Date, Time, Service, Resource, Confirm, Booked, etc.)
  - `src/locales/nl_NL.json` — Dutch strings (`Afspraak`, `Afspraak Maken`, `Datum`, `Tijd`, `Dienst`, `Middel`, `Bevestigen`, `Geboekt`, etc.)
  - Include all validation messages and error messages (e.g., "Please enter a valid email address", "Voer een geldig e-mailadres in")
- [ ] Task 13: Create `tests/Unit/Service/WidgetAuthServiceTest.php` covering:
  - Valid API key validation (correct hash match)
  - Invalid API key rejection (incorrect hash, revoked key)
  - API key rotation (new key generated, old key grace period)
  - Rate-limiting per business_id (first 100 OK, 101st returns 429)
- [ ] Task 14: Create `tests/Unit/Service/SlotServiceTest.php` covering:
  - Available slots without conflicts (resource free, no appointments)
  - Slots excluding booked times (conflicts detected)
  - Slots excluding past times (current time filtering)
  - Slots respecting resource hours (outside 09:00–18:00 excluded)
  - Timezone conversion (local time to UTC)
  - Caching behavior (5-min TTL, ETag validation)
- [ ] Task 15: Create `tests/Integration/Api/WidgetApiControllerTest.php` covering:
  - `GET /widget/services` — returns 200 with service list
  - `GET /widget/slots` — returns 200 with available slots; returns 304 Not Modified on cache hit
  - `POST /widget/appointments` — returns 201 Created on success; returns 400 Bad Request on validation error; returns 409 Conflict on double-booking
  - Authorization: 401 Unauthorized without API key; 403 Forbidden with invalid key
  - Rate-limiting: 429 Too Many Requests after 100 requests/minute
  - Concurrent requests: two simultaneous booking attempts for same slot (one succeeds, one fails with 409)
- [ ] Task 16: Create component tests for `SelfServiceWidget.vue` covering:
  - Service selection dropdown changes available slots
  - Date picker disables past dates
  - Time slot selection updates confirmation summary
  - Form validation (email required, phone optional, name required)
  - Timezone display (shows "10:00 AM (CEST)" format)
  - Error states (slot unavailable, network error, server error) with recovery options
  - Accessibility: keyboard navigation (tab, enter, escape)
- [ ] Task 17: Create `tests/Fixtures/WidgetFixtures.php` with sample data:
  - Sample business: businessId: "salon-demo", name: "Demo Salon"
  - Sample API key: valid key for "salon-demo"
  - Sample services: "haircut" (45 min, €35), "color" (120 min, €75), "manicure" (30 min, €25)
  - Sample resources: "chair-1", "chair-2" (availability: 09:00–18:00)
  - Sample appointments: 3–5 booked slots on 2026-05-22 (10:00–10:45, 11:30–12:30, 14:00–14:45)
- [ ] Task 18: Create admin UI for API key management in `src/views/WidgetSettings.vue`:
  - Display current API key (masked; show full key once on generation)
  - Button to generate new API key
  - Button to rotate API key (force immediate rotation; old key has 7-day grace period)
  - Display key creation date, last rotation date, revocation date (if applicable)
  - Audit log view (list of key operations: created, rotated, revoked with timestamp)
  - Settings for rate-limit (default 100 req/min; admin can adjust per business)
  - Settings for allowed CORS origins (for iframe cross-origin)
- [ ] Task 19: Create documentation:
  - `docs/integration/widget-embed.md` — guide for partners on 4 embed methods with code examples (iframe, script, npm, web component)
  - `docs/integration/widget-customization.md` — guide for CSS customization (variables, dark mode, responsive design)
  - `docs/integration/widget-api-reference.md` — OpenAPI/Swagger spec for widget API endpoints (services, slots, appointments)
  - `docs/integration/widget-faq.md` — FAQ (timezone, multi-language, payment integration, CORS, mobile)
  - `docs/user-guide/bookings/widget-overview.md` — journeydoc (per ADR-030) with screenshots of all 4 embed methods
- [ ] Task 20: Implement npm package publishing:
  - Configure `widget/package.json` with correct dependencies (@vue/runtime-dom for Vue 3)
  - Create `.npmignore` to exclude source maps, test files, src/
  - Generate TypeScript definitions via `widget/tsconfig.json` with `declaration: true`
  - Build script: `npm run build` runs webpack/rollup to bundle widget.js (minified + sourcemap)
  - Publish to npmjs.com under `@nextcloud-bookings/widget` scope (requires npm account + 2FA)
  - Tag version as `v1.0.0-beta` (beta until general release)
- [ ] Task 21: Create `tests/E2E/WidgetEmbed.spec.js` (Playwright browser tests) covering:
  - Widget loads via iframe (service list visible, can select and book)
  - Widget loads via script tag (booking widget appears in container)
  - Widget responds to prop changes (businessId, lang, primaryColor)
  - CSS customization works (primary color changes visible)
  - Error recovery (slot unavailable → refresh → rebooking succeeds)
  - Mobile responsiveness (tested on 375px viewport)
- [ ] Task 22: Create security audit checklist:
  - Input validation: email (RFC 5322), phone (+international), name (1–255 chars, no injection), notes (<500 chars, XSS escape)
  - API key: hashed with bcrypt, not plaintext; never logged
  - Rate-limiting: enforced per business_id + IP
  - HTTPS only: widget endpoints require HTTPS (Nextcloud framework enforces)
  - CORS: configurable per business; no wildcard origins
  - Output encoding: Vue.js auto-escapes; no raw HTML injection
  - No SQL injection: parameterized queries via OR
  - No CSRF: API key auth, not session-based
- [ ] Task 23: Run `npm run lint` on `src/components/SelfServiceWidget.vue` and `widget/` to ensure code style passes (eslint, prettier)
- [ ] Task 24: Run `composer test` to ensure all unit + integration tests pass (target: 100% coverage for widget-related code)
- [ ] Task 25: Run `npm run build` to generate minified `widget.js` and TypeScript definitions in `widget/dist/`
- [ ] Task 26: Verify widget embed on 3 test partner sites:
  - Test 1: Salon website (iframe mode, custom branding, nl_NL language)
  - Test 2: Clinic website (script tag mode, multiple services, en_US)
  - Test 3: React app (npm package, TypeScript props, theme customization)
  - For each: test full booking flow, error states, mobile responsiveness, accessibility (keyboard + screen reader)
- [ ] Task 27: Create a PR with all implementation changes, link to the spec proposal in PR description, request review from @bookings-team, @frontend-team, @security-team
- [ ] Task 28: After PR approval, publish npm package: `npm publish --registry https://registry.npmjs.org/` (requires npm account with 2FA)
- [ ] Task 29: Create partner onboarding guide with step-by-step instructions (signup → API key → code example → live booking)

## Verification

`openspec validate` must exit clean on the change folder. Product personas (salon owner, clinic operator) review the spec and confirm:
- Widget UX is intuitive (service selection → date/time → confirmation)
- Branding customization works (colors, fonts, logo placement)
- All 4 embed methods work on their websites (iframe, script, npm, web component)
- Error messages are helpful and recovery paths are clear
- Mobile experience is smooth (responsive, touch-friendly date picker)
- Booking confirmation is received (email with appointment details)

Security reviewer confirms:
- API key auth is enforced on all widget endpoints
- Rate-limiting prevents abuse (100 req/min per business)
- Input validation prevents XSS/injection (email, phone, name, notes)
- No customer PII exposed via public API
- Timezone conversion is correct (local → UTC)

## Tests (company-wide ADR-008, ADR-009)

Implementation cycle is responsible for:

- **Unit tests** (Task 13, 14): WidgetAuthService, SlotService logic
- **Integration tests** (Task 15): API controller endpoints, authorization, rate-limiting
- **Component tests** (Task 16): SelfServiceWidget Vue component behavior, accessibility
- **E2E tests** (Task 21): widget embed methods (iframe, script, npm, web component) in real browser
- **Manual QA** (partners): booking flow, error recovery, mobile, accessibility (screen reader)

`composer test` and `npm run test` MUST pass green at PR merge gate. `npm run build` MUST generate minified widget without errors. Playwright E2E tests MUST pass on Chrome, Firefox, Safari.

## Documentation (company-wide ADR-009, ADR-030)

Implementation cycle authors:

- `docs/integration/widget-embed.md` — partner integration guide (4 methods, code examples)
- `docs/integration/widget-customization.md` — CSS customization guide (variables, dark mode)
- `docs/integration/widget-api-reference.md` — OpenAPI spec for widget API
- `docs/user-guide/bookings/widget-overview.md` — journeydoc (screenshots, flows)
- `docs/integration/widget-faq.md` — FAQ (timezone, multi-language, CORS, payment)

Screenshots:
- Widget service selection (desktop + mobile)
- Widget date/time picker (desktop + mobile)
- Widget confirmation screen
- Admin API key management page
- Embed method code examples (all 4)

## i18n (company-wide ADR-007)

Implementation cycle adds translation strings:

- `src/locales/en_US.json`: English strings (Service, Date, Time, Confirm, Booked, Error messages, etc.)
- `src/locales/nl_NL.json`: Dutch translations (`Dienst`, `Datum`, `Tijd`, `Bevestigen`, `Geboekt`, etc.)

All form labels, validation messages, error messages, button text, and success confirmations MUST be translatable.

## API Key Generation & Rotation (Security)

- **Generation**: 32-character random base64 string, never stored plaintext (bcrypt hash only)
- **Distribution**: Emailed to partner upon creation (one-time; not recoverable later)
- **Rotation**: Auto-rotate every 30 days; old key remains active 7 days (grace period)
- **Revocation**: Admin can manually revoke key immediately (no grace period)
- **Audit trail**: Log all key operations (created, rotated, revoked) with actor and timestamp

## Rate-Limiting Policy

- **Limit**: 100 requests/minute per business_id
- **Enforcement**: Counter incremented per request; reset every 60 seconds
- **Graceful degradation**: If rate limit exceeded, return HTTP 429 with `Retry-After: 60` header
- **Monitoring**: Alert if business exceeds 80% of limit (e.g., 80 req/min for sustained period)

## CORS Policy

- **Default**: Only same-origin requests (iframe mode)
- **Configurable**: Admin can add allowed origins per business (e.g., `salons.example.com`, `www.salons.example.com`)
- **Wildcard origins** (`*`) NOT allowed (security risk)
- **Credentials**: Not supported (no session cookies in widget API)
