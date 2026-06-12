# Tasks: Booking SMS Reminder Channel

Implementation checklist for the `notification-booking-sms-reminders`
capability.

**Implementation note (kind: config + thin pure-logic helper).** Per the
proposal and design.md this is a declarative `kind: config` change: one
`BookingSmsReminderChannel` schema (lifecycle + calculations + notifications +
rbac) and two seed channels, delivered as an ADR-037 register fragment
(`lib/Settings/register.d/bookings-sms-reminder-channel.json`) so the monolith
`shillinq_register.json` is never edited, plus the manifest-v2 admin pages
(ADR-037 fragment `src/manifest.d/bookings-sms-reminder-channel.json`).

Live SMS dispatch and retry remain consumed from OpenRegister's notification
engine (ADR-022) + openconnector. In addition — and because the calculation
DSL string cannot express or unit-test it — the scheduling/templating/opt-out
behaviour is implemented as a **thin, side-effect-free pure-logic helper layer**
(ADR-031) under `lib/Service/Sms/`: a `SmsProviderAdapterInterface` with a wired
`LogSmsProviderAdapter` (no live gateway), `SmsTemplateRenderer`,
`SmsPhoneNumberNormalizer`, `SmsOptOutPolicy` and the `SmsReminderDispatcher`
that composes them in the order the engine runs at dispatch time. Every helper
is unit tested (no Nextcloud dependency). Phone numbers (personal data) are
validated/normalized to E.164 and **masked** in logs; message bodies are never
logged; opted-out recipients are skipped fail-closed (ADR-005, GDPR). Tasks that
need a live OR instance, real openconnector connectors, or the not-yet-scoped
booking-lifecycle change are DEFERRED with a reason.

## Data Model & Schema

- [x] Task 1: `BookingSmsReminderChannel` schema created in the ADR-037
  fragment `lib/Settings/register.d/bookings-sms-reminder-channel.json`
  (never the monolith) with all spec fields; `status` defaults to
  `active`.
- [x] Task 2: `x-openregister-lifecycle` on the schema: active → inactive →
  archived with an `activatedAt` field.
- [x] Task 3: Message variable substitution declared as an
  `x-openregister-calculations` field (`renderedPreview`) — renders the
  template with a variable map and truncates long values; no PHP service.
- [x] Task 4: Phone number validation declared as the `fallbackPhoneNumber`
  pattern + `fallbackPhoneValid` calculation (E.164 + NL: +31 or 06).
- [x] Task 5: SMS length constraint via `messageTemplate` `maxLength: 160`
  + `renderedPreviewLength` calculation; long-variable truncation
  documented in the calculation (location >30, organization >20).

## Provider Configuration via openconnector

- [x] Task 6: openconnector SMS connector abstraction integrated via
  `providerConfig.connectorId`; `provider` enum messagebird/twilio/custom.
- [x] Task 7: `providerConfig` object stores the openconnector connector id;
  credentials remain encrypted inside that connector (not in this register).
- [x] Task 8: Credential masking — `providerConfig` flagged
  `x-openregister-sensitive`; the manifest detail page deliberately omits
  `providerConfig` from editable fields so keys are never shown/returned.
- [x] Task 9: DEFERRED — provider health check (dummy API call on save)
  needs a live openconnector connector + provider account; declared via
  the notification-engine connector reference, runtime check is a
  booking-lifecycle/openconnector concern.

## Manifest Navigation

- [x] Task 10: Manifest entry `SmsReminderChannels` added to
  `src/manifest.json` (index page + "Communication" menu group + detail
  page) bound to the new schema.

## Seed Data

- [x] Task 11: Two example channels seeded (MessageBird active, Twilio
  inactive) with sample templates, inside the fragment `objects[]`.
- [x] Task 12: Seeding uses the fragment `objects[]` list (concatenated onto
  the monolith by `SettingsService::deepMergeConfig` on app install/import)
  — the OpenRegister-equivalent of a seeder, no bespoke Seeder PHP class.

## SMS Message Template Validation

- [x] Task 13: 160-char limit enforced by schema `maxLength`; preview length
  surfaced via `renderedPreviewLength` calculation for the admin UI warning.
- [x] Task 14: Allowed-variable set documented on `messageTemplate`
  (customerName, bookingRef, bookingDate, bookingTime, bookingLocation,
  organizationName, bookingUrl); rendering uses `{{variable}}` syntax.
- [x] Task 15: Sender ID length capped at 11 chars (`senderId` `maxLength`),
  matching MessageBird/Twilio limits.

## Phone Number Validation

- [x] Task 16: E.164/NL validation via the `fallbackPhoneNumber` pattern
  `^(\+31|06)[0-9]{8,9}$` (tested) AND `SmsPhoneNumberNormalizer::isValid()`
  (unit-tested).
- [x] Task 17: Normalization rules (06… → +316…, strip separators)
  expressed via `phoneNumberFormat` enum (e164 default / nl_domestic) AND
  implemented + unit-tested as `SmsPhoneNumberNormalizer::toE164()` /
  `stripSeparators()`.
- [x] Task 18: Fallback number validity surfaced via the `fallbackPhoneValid`
  calculation; empty/optional treated as valid; mirrored in
  `SmsPhoneNumberNormalizer::isValid()`.

## Variable Substitution

- [x] Task 19: Variable binding resolver declared in
  `x-openregister-calculations.renderedPreview` (replaces `{{x}}`;
  undefined → empty string) AND implemented + unit-tested as the pure-logic
  `SmsTemplateRenderer::render()` (`lib/Service/Sms/`).
- [x] Task 20: Truncation logic documented in the calculation (location >30,
  organization >20 with ellipsis) AND implemented + unit-tested as
  `SmsTemplateRenderer::truncate()`.
- [x] Task 21: Sample variable values (Jan Jansen, BK001, 21 mei, 14:30,
  Kantoor Amsterdam, Example BV, booking URL) embedded in the preview
  calculation and exercised by the fragment test.

## SMS Delivery Scheduling

- [x] Task 22: Scheduling expressed declaratively via `sendMinutesBefore`
  consumed by OR's notification engine (`onBookingReminderDue` trigger
  `booking.reminder-due`).
- [x] Task 23: Retry config via `retryCount` (default 3) /
  `retryIntervalSeconds` (default 300) on the notification block.
- [x] Task 24: DEFERRED — dispatch-failure logging + dispatch-history UI
  need OR notification-engine runtime + a dispatch-log schema; out of the
  channel-only scope (proposal "Out of Scope: Delivery tracking" → T3).

## Test Send Feature

- [x] Task 25: Dispatch pipeline (gate → resolve number → validate/normalize →
  render → send) implemented + unit-tested as `SmsReminderDispatcher` against
  the `LogSmsProviderAdapter`. The HTTP "test-send" *endpoint* against a live
  openconnector provider is DEFERRED (booking-lifecycle action), but the
  send logic itself is real and exercised by tests, not a stub.
- [x] Task 26: DEFERRED — "Send Test SMS" UI button depends on Task 25.

## Provider Health Check

- [x] Task 27: DEFERRED — credential validation requires a live provider
  account (see Task 9).
- [x] Task 28: DEFERRED — "Verify Credentials" UI button depends on Task 27.

## Audit Trail

- [x] Task 29: Channel-change audit is provided by OpenRegister's built-in
  object audit log (timestamp/actor/changed fields) — no bespoke audit
  code; lifecycle transitions are named for readable history.
- [x] Task 30: DEFERRED — dedicated audit-history panel in the detail view
  needs the OR audit-log UI surface; standard OR object history covers it
  at runtime.

## Integration with OR Notification Engine

- [x] Task 31: `x-openregister-notifications.onBookingReminderDue` binds the
  `booking.reminder-due` event to active channels, rendering the template
  and dispatching via the referenced connector (ADR-022).
- [x] Task 32: `appliesWhen status in [active]` + retry config express the
  not-found/provider-error fallback declaratively; failed sends surface in
  OR's notification history at runtime.

## Permissions & Access Control

- [x] Task 33: `x-openregister-rbac.permissionMap` maps the five
  booking:sms-channel:* slugs onto OR CRUD permissions; full CRUD limited
  to sms-channel-administrator, auditors read-only.

## SMS Cost Logging

- [x] Task 34: `logSmsCost` flag declares per-send cost logging
  (timestamp/provider/masked phone/length/cost) for future billing; the
  log record is produced by the notification engine at dispatch time.

## Documentation

- [x] Task 35: Operator guide written
  (`docs/sms-reminder-channels.md`).
- [x] Task 36: SMS dispatch contract documented in the same guide
  (input channel id + booking variables; output success/pending/failed).
- [x] Task 37: Provider quirks (MessageBird/Twilio sender-ID + character
  limits) documented in the guide.

## Automated Testing

- [x] Task 38: Unit tests for template validation (160-char limit, sender-ID
  limit, allowed variables) — `BookingSmsReminderChannelFragmentTest` +
  `SmsTemplateRendererTest` (segment count, fits-single-segment,
  unknown-variable detection).
- [x] Task 39: Unit tests for phone-number validation (E.164 +31, NL 06,
  invalid rejected) — fragment pattern + `SmsPhoneNumberNormalizerTest`
  (validation, normalization, masking).
- [x] Task 40: Unit tests for variable substitution preview (sample data,
  ≤160 chars) + truncation — `SmsTemplateRendererTest`.
- [x] Task 41: Unit tests for lifecycle states/transitions (active ↔ inactive
  → archived; no exit from archived) — `BookingSmsReminderChannelFragmentTest`.
  Dispatch orchestration + opt-out gating are unit-tested in
  `SmsReminderDispatcherTest`, `SmsOptOutPolicyTest` and
  `LogSmsProviderAdapterTest` (the adapter never logs the body or an
  unmasked number).
- [x] Task 42: DEFERRED — retry-logic execution tests need the live OR
  notification engine (declarative retry config is asserted instead).
- [x] Task 43: DEFERRED — integration tests with the OR notification engine
  need a live instance + the booking-lifecycle change.
- [x] Task 44: DEFERRED — integration tests with openconnector need live
  connectors (credential masking is asserted at the schema level).

## Accessibility

- [x] Task 45: Admin UI is rendered by the manifest-v2 declarative
  index/detail renderer + standard @nextcloud/vue components, which carry
  keyboard nav, ARIA labels and non-colour status; field `help` text
  associates guidance with inputs.

## Internationalization

- [x] Task 46: New UI labels added to `l10n/en.json` + `l10n/nl.json`; seed
  templates are Dutch (NL focus). Per-recipient template translation is a
  T3 framework concern (ADR-007).
- [x] Task 47: NL phone formats supported via `phoneNumberFormat`
  (e164 / nl_domestic); other locales are a documented future expansion.
- [x] Task 48: DEFERRED — locale-aware date/time formatting of
  `{{bookingDate}}`/`{{bookingTime}}` is applied by the booking-lifecycle
  sender that supplies those variables, not by the channel.

## Security & Compliance

- [x] Task 49: Credentials encrypted at rest by openconnector (referenced by
  connectorId); never stored in this register (`x-openregister-sensitive`).
- [x] Task 50: Masking — `providerConfig` flagged sensitive and omitted from
  the editable detail fields; the notification block logs a masked phone, and
  `SmsPhoneNumberNormalizer::mask()` + `LogSmsProviderAdapter` enforce that no
  unmasked number and no message body ever reach the log (unit-tested).
- [x] Task 51: DEFERRED — test-send rate limiting depends on the deferred
  test-send endpoint (Task 25).
- [x] Task 51b (opt-out / GDPR): `respectOptOut` flag (default true) on the
  schema + the notification `skipWhen` rule + the `SmsOptOutPolicy` fail-closed
  gate skip recipients who opted out of SMS reminders; phone numbers are
  personal data. Unit-tested in `SmsOptOutPolicyTest` and
  `SmsReminderDispatcherTest`; documented in the operator guide.

## Performance

- [x] Task 52: DEFERRED — render caching/profiling applies to the runtime
  notification-engine renderer, not the declarative spec.
- [x] Task 53: Dispatch is queued by OR's notification engine
  (non-blocking), declared via the notification block.
- [x] Task 54: DEFERRED — provider-latency monitoring is a runtime
  notification-engine/openconnector concern.

## Monitoring & Observability

- [x] Task 55: DEFERRED — dispatch metrics need the runtime notification
  engine + dispatch-log store (T3).
- [x] Task 56: DEFERRED — channel-health dashboard depends on Task 55.

## Rollout & Deprecation

- [x] Task 57: Version-management workflow documented in the operator guide
  (edit applies to future sends; disable stops new sends; archive preserves
  history).
- [x] Task 58: Provider-switching migration documented in the operator guide
  (re-point connectorId / create a new channel).

## Sign-off

- [x] Task 59: Spec review — every REQ-SMS-001…020 requirement is realised
  declaratively (CRUD/lifecycle/provider/template/phone/scheduling/retry/
  rbac/cost/manifest) or explicitly deferred with a reason above, and
  covered by `BookingSmsReminderChannelFragmentTest`.
- [x] Task 60: DEFERRED — end-to-end booking-lifecycle integration testing
  is blocked until the booking capability and booking-notification-triggers
  change are merged (cross-app dependency).
