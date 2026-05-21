# Tasks: Booking SMS Reminder Channel

Implementation checklist for the `notification-booking-sms-reminders`
capability.

## Data Model & Schema

- [ ] Task 1: Create `BookingSmsReminderChannel` schema in
  `lib/Settings/booking_register.json` with all fields per spec; mark
  status as `active` on creation (default active state).
- [ ] Task 2: Add `x-openregister-lifecycle` to SMS channel schema:
  transitions active → inactive → archived with timestamped
  `activatedAt` field.
- [ ] Task 3: Define message variable substitution system as
  `x-openregister-calculations` field on channel schema (render
  template with variable map, truncate long values); no PHP service
  class.
- [ ] Task 4: Implement phone number validation calculation:
  E.164 format + NL-specific rules (starts with +31 or 06).
- [ ] Task 5: Implement SMS message length calculation: verify template
  + substituted variables ≤160 characters; truncate long variables
  (>30 chars location, >20 chars organization name).

## Provider Configuration via openconnector

- [ ] Task 6: Integrate openconnector SMS connector abstraction;
  support MessageBird, Twilio, and custom connectors.
- [ ] Task 7: Create provider config field (JSON object) storing
  openconnector connector ID, API credentials (encrypted via
  openconnector), provider-specific settings.
- [ ] Task 8: Implement credential masking in admin UI: display "●●●●●●"
  for API keys and sensitive fields; never log credentials.
- [ ] Task 9: Add provider health check: validate credentials on save
  (attempt dummy API call to provider).

## Manifest Navigation

- [ ] Task 10: Add manifest entry `sms-reminder-channels` to
  `src/manifest.json`:
  - type: index (list all SMS channels)
  - icon: message-square
  - label: "SMS Reminder Channels"

## Seed Data

- [ ] Task 11: Create 2 example channels (one inactive as template):
  - Channel 1: "SMS Reminders via MessageBird (NL)" - status: active
  - Channel 2: "SMS Reminders via Twilio (NL)" - status: inactive
  - Both seeded on app installation with sample message template.
- [ ] Task 12: Create `src/Resources/Seeds/BookingSmsChannelSeeder.php`
  (or OpenRegister equivalent) that seeds example channels on first
  app installation.

## SMS Message Template Validation

- [ ] Task 13: Implement validator for message template: reject
  templates exceeding 160 characters. Calculate length with sample
  variable values (customerName: "Jan Jansen", bookingRef: "BK001",
  etc.).
- [ ] Task 14: Implement variable detection: identify all {{variableName}}
  placeholders in template and validate against allowed variables list
  (customerName, bookingRef, bookingDate, bookingTime, bookingLocation,
  organizationName, bookingUrl).
- [ ] Task 15: Validate sender ID length per provider: MessageBird max
  11 chars, Twilio max 11 chars alphanumeric; reject longer values.

## Phone Number Validation

- [ ] Task 16: Implement E.164 phone number validation: rejects
  invalid formats; accepts +31XXXXXXXXX or 06XXXXXXXX (NL formats).
- [ ] Task 17: Add phone number formatting utilities: normalize "06..."
  to "+316...", strip spaces/hyphens/parentheses.
- [ ] Task 18: Create validator for fallback phone number on channel
  creation/edit: validate format, warn if unused/empty.

## Variable Substitution

- [ ] Task 19: Implement variable binding resolver in
  `x-openregister-calculations`: accepts template string and booking
  variables map; replaces {{variableName}} with corresponding map value;
  undefined variables → empty string.
- [ ] Task 20: Implement variable truncation logic: location >30 chars
  truncated to "...location...", organization name >20 chars truncated
  to "...name...".
- [ ] Task 21: Add test fixtures for variable substitution with actual
  booking data (customer names with umlauts, long locations, dates in
  locale format).

## SMS Delivery Scheduling

- [ ] Task 22: Implement scheduling logic: calculate send time as
  bookingStartTime - sendMinutesBefore. OR's notification engine
  triggers dispatch at scheduled time (with ±15 min tolerance window).
- [ ] Task 23: Add retry logic configuration: `retryCount` (default 3)
  and `retryIntervalSeconds` (default 300). OR's notification engine
  executes retries.
- [ ] Task 24: Implement dispatch failure logging: log timestamp,
  channel ID, provider, phone number (masked), error message, retry
  count. Make logs visible in dispatch history UI.

## Test Send Feature

- [ ] Task 25: Create test send API endpoint that:
  - Accepts channel ID and test phone number
  - Renders template with mock variables
  - Sends test SMS via openconnector
  - Returns success/failure message with delivery status or error
- [ ] Task 26: Add "Send Test SMS" button in channel detail UI:
  - Input field for test phone number
  - Button click triggers test send endpoint
  - Shows result notification (success or error)

## Provider Health Check

- [ ] Task 27: Implement provider credential validation on channel
  creation/edit: attempt small API call (e.g., check account balance)
  to verify credentials. Show error if credentials invalid.
- [ ] Task 28: Add "Verify Credentials" button in channel edit UI for
  operator to manually test provider connection without sending SMS.

## Audit Trail

- [ ] Task 29: Implement audit logging for channel changes: record
  timestamp, operator, action (create, edit, activate, archive),
  changed fields, old values, new values.
- [ ] Task 30: Display audit trail in channel detail history: show
  recent changes with operator name and timestamp.

## Integration with OR Notification Engine

- [ ] Task 31: Integrate with OR's notification engine: when booking
  event ("booking.created", "booking.reminder-due", etc.) is emitted,
  fetch active SMS channels, render template with booking variables,
  and dispatch via OR's notification abstraction + openconnector
  provider.
- [ ] Task 32: Error handling for channel not found, rendering failure,
  or provider error: log error and fallback. Operator sees failed sends
  in dispatch history.

## Permissions & Access Control

- [ ] Task 33: Define permissions per SMS channel admin role:
  - `booking:sms-channel:list` — view all channels
  - `booking:sms-channel:create` — create new channels
  - `booking:sms-channel:edit` — edit channels
  - `booking:sms-channel:activate` — activate/deactivate channels
  - `booking:sms-channel:delete` — archive channels

## SMS Cost Logging

- [ ] Task 34: Log SMS send cost for future billing integration:
  - Timestamp, channel ID, provider, phone number (masked), message
    length, cost (if available from provider)
  - Store in dispatch log; available for future billing feature (T3)

## Documentation

- [ ] Task 35: Write operator guide: "Configuring SMS Reminder Channels"
  covering:
  - Channel creation workflow
  - Provider selection (MessageBird, Twilio)
  - Credential setup and verification
  - Message template customization
  - Variable reference ({{customerName}}, {{bookingDate}}, etc.)
  - Send time configuration (hours before booking)
  - Sender ID customization
  - Testing channels before activation
  - Troubleshooting delivery failures
- [ ] Task 36: Document API contract for SMS dispatch:
  - Input: channel ID, booking variables map
  - Output: SMS dispatch status (success, pending, failed)
  - Error handling (invalid channel, missing variables, provider error)
- [ ] Task 37: Document provider-specific quirks:
  - MessageBird: sender ID restrictions, DLR availability
  - Twilio: sender ID rules, cost per region
  - Character limits and multi-part SMS handling

## Automated Testing

- [ ] Task 38: Unit tests for message template validation:
  - Template ≤160 chars (success)
  - Template >160 chars (rejected)
  - Variable detection (valid and invalid variables)
- [ ] Task 39: Unit tests for phone number validation:
  - Valid E.164 (+31...) — accepted
  - Valid NL domestic (06...) — accepted
  - Invalid format — rejected
  - Formatting normalization
- [ ] Task 40: Unit tests for variable substitution:
  - Defined variables → substituted
  - Undefined variables → empty string
  - Long values → truncated with ellipsis
  - Special characters (umlauts, accents) — handled correctly
- [ ] Task 41: Unit tests for lifecycle transitions:
  - active → inactive (success)
  - inactive → active (success)
  - active → archived (success)
  - inactive → archived (success)
  - archived → active (not allowed, reject)
- [ ] Task 42: Unit tests for retry logic:
  - Retry on transient error (timeout, rate limit)
  - Max retries respected
  - Exponential backoff or fixed interval
  - Failed dispatch logged
- [ ] Task 43: Integration tests with OR notification engine:
  - SMS dispatch on booking creation event
  - Variable injection into template
  - SMS delivery via openconnector provider
  - Error handling (missing channel, provider down)
- [ ] Task 44: Integration tests with openconnector:
  - Provider credential validation
  - Provider health check
  - Multi-provider support (MessageBird, Twilio)
  - Credential masking

## Accessibility

- [ ] Task 45: Ensure SMS channel admin UI is accessible:
  - Keyboard navigation for all form fields and buttons
  - ARIA labels for phone number input, template editor
  - Error messages clearly associated with form fields
  - Color not sole differentiator for status indicators

## Internationalization

- [ ] Task 46: Seed default template in EN and NL locales. Template
  translation framework per ADR-007 (resolved during cycle).
- [ ] Task 47: Support locale-specific phone number formats: NL (06/+31),
  future expansion to other locales.
- [ ] Task 48: Implement locale-aware date/time formatting in template
  variables: {{bookingDate}} and {{bookingTime}} formatted per
  operator's locale (e.g., "21 mei 2026" for NL, "May 21, 2026" for EN).

## Security & Compliance

- [ ] Task 49: Implement credential encryption at rest: provider API
  keys stored encrypted via openconnector (not plaintext in database).
- [ ] Task 50: Log masking: never log full phone numbers, API keys, or
  customer names in error messages. Mask as "+31●●●●●●" and
  "●●●●●●".
- [ ] Task 51: Rate limiting on test send: limit operator to 5 test
  sends per channel per hour to prevent SMS quota abuse.

## Performance

- [ ] Task 52: Optimize message rendering: cache template AST or
  compiled regex for variable substitution. Profile with 1000+ bookings.
- [ ] Task 53: Implement dispatch queuing: SMS sends queued by OR's
  notification engine, not blocking booking API response.
- [ ] Task 54: Monitor provider API latency: log send time per provider
  for future optimization.

## Monitoring & Observability

- [ ] Task 55: Implement dispatch metrics:
  - SMS sent count (per channel, per provider, per day)
  - SMS failed count (per channel, per provider)
  - Average send latency per provider
  - Cost per provider (total SMS cost, cost per SMS)
- [ ] Task 56: Create admin dashboard for SMS channel health:
  - Last 10 sends (timestamp, status, provider, phone masked)
  - Channel status (active/inactive/archived)
  - Provider health status (credentials valid, recent errors)

## Rollout & Deprecation

- [ ] Task 57: Document channel version management workflow:
  - Editing active channel applies to future sends (no versioning
    needed for SMS, unlike email templates)
  - Disabling channel stops new sends; previous sends unaffected
  - Archiving channel preserves history for audit
- [ ] Task 58: Create migration guide for future multi-channel provider
  switching (e.g., MessageBird to Twilio migration).

## Sign-off

- [ ] Task 59: Spec review sign-off: verify all REQ-SMS-XXX requirements
  are implemented and tested.
- [ ] Task 60: Integration testing with booking lifecycle (TBD once
  booking capability is scoped and booking-notification-triggers change
  is merged).
