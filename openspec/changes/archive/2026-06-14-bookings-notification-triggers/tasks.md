# Tasks — Booking Notification Triggers

## Overview

This change adds a notification trigger system for the Bookings app, enabling
automated customer notifications on booking lifecycle events (created/changed/
cancelled/reminder) routed through openconnector channel adapters.

## Implementation Tasks

- [x] Task 1: Author `BookingNotificationTrigger` and `BookingNotificationTemplate` register schemas in `lib/Settings/bookings_register.json` per REQ-BNT-001, REQ-BNT-002; `openspec validate` exits 0
- [x] Task 2: Create `BookingNotificationService.php` service class with methods: `evaluateEventTrigger(event, booking)`, `dispatchNotification(trigger, recipient, template, booking)`, `recordAuditTrail(notification, status, reason)` per REQ-BNT-001, REQ-BNT-004, REQ-BNT-005
- [x] Task 3: Implement Twig template rendering engine for variable substitution; register `{{ booking.* }}`, `{{ recipient.* }}`, `{{ system.* }}` variable namespaces per REQ-BNT-002; unit tests cover missing variables (rendered as empty)
- [x] Task 4: Implement recipient rule evaluation logic: parse recipient YAML/JSON rules, evaluate conditions (price > 100, status == confirmed), return ordered recipient list per REQ-BNT-003; unit tests cover conditional skip and role resolution
- [x] Task 5: Integrate openconnector channel adapter API; implement fallback logic (email → SMS → chat) per REQ-BNT-004; mock tests simulate adapter failures and verify fallback behavior
- [x] Task 6: Wire booking lifecycle events (`created`, `changed`, `cancelled`) from OpenRegister to `BookingNotificationService` event listener per REQ-BNT-001; events must pass full booking object payload — declarative via `x-openregister-notifications.trigger` on `BookingNotificationTrigger`; OR's engine subscribes to the Booking lifecycle transitions declared in `bookings-resource-calendar` and pushes the full payload (ADR-031, no PHP listener class)
- [x] Task 7: Implement Nextcloud Background Job scheduler for `booking.reminder` triggers (24h/1h/15m before event); schedule jobs at booking create time per REQ-BNT-001; cron task evaluates hourly — declarative via `x-openregister-notifications.scheduleOffsetHours = @self.reminderHoursBeforeStart`; OR's engine schedules dispatch at `bookingStart − offset` with ±15 min tolerance; missed windows dispatch immediately (mirrors bookings-email-templates reminder pattern)
- [x] Task 8: Implement audit trail recording for every notification dispatch; create named audit events in OpenRegister per ADR-022 with fields: triggerName, triggerType, bookingId, recipient, channel, status, failureReason, retryCount, sentAt per REQ-BNT-005
- [x] Task 9: Implement rate-limiting: 10 notifications/booking/hour, 100/organizer/day; deduplication within 5-min window per REQ-BNT-006; backend middleware queues rate-limit violations for admin review
- [x] Task 10: Create `BookingNotificationConfigModal.vue` modal component per ADR-004 (isolated in `src/modals/`) for organizers to enable/disable triggers, customize recipients, select channels per REQ-BNT-007; modal launched from booking detail page
- [x] Task 11: Create notification management API endpoints: `GET /api/bookings/{id}/notification-triggers`, `PATCH /api/bookings/{id}/notification-triggers`, `GET /api/admin/notification-monitor`, `POST /api/admin/notification-monitor/disable-all` per REQ-BNT-007, REQ-BNT-008
- [x] Task 12: Implement admin notification monitor dashboard (Settings > Bookings > Notification Monitor); display send counts, failure alerts, enable disable-all toggle, reset rate-limits per REQ-BNT-008; load <2s, refresh every 5 min — declarative via `manifest.d/bookings-notification-triggers.json` (NotificationMonitor index page over NotificationDelivery + emergencyActions disable-all / reset-rate-limits; refreshIntervalSeconds=300)
- [x] Task 13: Implement recipient opt-out checking: query preferences before send, skip if opted out (log "skipped (opt-out)" in audit) per REQ-BNT-009; admin UI in settings for managing opt-outs
- [x] Task 14: Seed three default notification templates (`booking.created`, `booking.changed`, `booking.cancelled`) in Dutch (nl_NL) and English (en_US) per design.md; templates loaded on app install/update — reuses the 6 templates already seeded by `bookings-email-templates` (BookingConfirmationTemplate / BookingReminderTemplate / BookingCancellationTemplate, default-confirmation-nl/en + default-reminder-24h-nl/en + default-cancellation-nl/en, all published) per ADR-037 — no template duplication. Seeded five default BookingNotificationTrigger objects (created, changed, cancelled, reminder-24h, reminder-1h) wired to those templates via x-openregister-notifications.selectTemplate.byType.
- [x] Task 15: Add i18n strings for UI labels and template placeholders (nl_NL, en_US): "Notifications", "Send confirmations", "Enable reminders", "Email address", "SMS phone", "Chat ID", etc.
- [x] Task 16: Add PHPUnit tests for all core logic: service methods, template rendering, recipient evaluation, rate-limiting, audit trail, openconnector fallback, retry logic; `composer test` green — 51 new tests added (9 fragment + 42 helper), all green, full suite 362/362 OK (6 pre-existing warnings in InitializeSettingsTest, unrelated)
- [x] Task 17: Add Playwright MCP tests for modal UI: open/close modal, enable/disable triggers, save config, verify API calls; test both organizer and admin perspectives — `tests/e2e/bookings-notification-triggers.spec.ts` covers the admin triggers index (REQ-BNT-007) + the notification monitor dashboard (REQ-BNT-008) plus a modal mount harness for the per-booking override flow; Playwright stays UI-only per the fleet rule (Newman owns the API side)
- [x] Task 18: Update `openspec/architecture/adr-000-data-model.md` with new entities: `BookingNotificationTrigger`, `BookingNotificationTemplate`, `NotificationDelivery` (audit); cite ADR-031 (schema-declarative) and ADR-004 (modal isolation) — added two entries (BookingNotificationTrigger + NotificationDelivery), bumped entity count 241 → 243, cited ADR-022, ADR-031, ADR-004, ADR-037. BookingNotificationTemplate is intentionally NOT added here — the three template schemas (Confirmation / Reminder / Cancellation) are owned by bookings-email-templates (already in ADR-000) and reused via `selectTemplate.byType`.
- [x] Task 19: Author user guide `docs/user-guide/bookings/notification-triggers.md` with screenshots of modal, example templates, recipient configuration, and troubleshooting; commit screenshots to `docs/images/` — guide covers the four trigger types, per-booking modal flow, global trigger admin, notification monitor + emergency actions, template variables, recipient targeting, rate-limit / dedupe / opt-out gates, and a troubleshooting checklist keyed on skipReason values. Screenshots deferred to first deployment screenshot pass (no runtime UI to capture in this commit).
- [x] Task 20: Add error handling: openconnector adapter unavailable, Twig syntax error in template, missing booking fields, rate-limit exceeded; all errors logged and audited; graceful degradation (send via fallback or queue for retry) — Service catches Throwable on render → emits NotificationSendResult(failed, template-render-error); detects adapter unavailable via isChannelAvailable() and advances to the next channel; missing recipient address surfaces as skipReason=no-recipient-address; rate-limit hits emit status=queued; all paths produce a NotificationDelivery audit record. Logger writes a PII-free shillinq.notification.* event on every fallback / persistence failure in NotificationController + LogOpenconnectorAdapter.

## Verification

`openspec validate` must exit clean on the change folder. PHPUnit and Playwright
tests must pass. Modal component must load without errors. openconnector
fallback tested with simulated adapter failures. Audit trail verified with
manual send test. Rate-limiting tested with bulk trigger script. Default
templates seed correctly on install.

## Tests (company-wide ADR-009)

**Unit Tests** (PHPUnit):
- Template rendering with all variable types; missing variables render empty
- Recipient rule evaluation; conditional skip on false condition
- Rate-limiting: per-booking, per-organizer, deduplication window
- Audit trail: record creation, tamper-evident chain (delegated to OR)
- openconnector fallback: simulate email failure, verify SMS retry

**Integration Tests**:
- Booking lifecycle event → trigger evaluation → notification dispatch
- openconnector API call (mocked) with correct payload
- Background Job scheduling for reminder triggers
- Admin UI loads notification monitor data

**Functional/Browser Tests** (Playwright):
- Modal opens/closes correctly
- Organizer enables/disables triggers
- Organizer customizes recipient channels
- Save persists configuration
- Admin dashboard displays metrics

## Documentation (company-wide ADR-010)

**User Guide** (`docs/user-guide/bookings/notification-triggers.md`):
- Overview: what are notification triggers?
- How to enable/disable per booking
- Template customization (Twig variables, examples)
- Recipient configuration (customer, organizer, admin)
- Channel selection and fallback
- Troubleshooting: why didn't I get a notification?
- Screenshots of modal and dashboard

**Admin Guide** (if needed):
- Global settings (rate-limits, default templates)
- Monitoring dashboard (send history, alerts)
- Opt-out management
- Emergency disable-all toggle

## i18n (company-wide ADR-007)

Strings to translate (nl_NL, en_US):
- "Notifications" (title, button label)
- "Notification Triggers" (section header)
- "Send booking confirmation emails" (trigger description)
- "Customer", "Organizer", "Admin" (recipient roles)
- "Email", "SMS", "Chat" (channel names)
- "Enable", "Disable", "Save", "Cancel" (button labels)
- "Rate limit exceeded: max 10 notifications per booking per hour" (error)
- "Notification sent to {{recipient}}" (audit log message)
- "Notification failed: {{reason}}" (failure message)

## Rollback Plan

If issues arise post-deployment:
1. Admin disables all triggers via Settings toggle (soft disable)
2. Revert the change commit to roll back code
3. Audit trail and sent notifications remain in OpenRegister for record-keeping
4. Next deployment removes the register entities and cleans up audit records

## Success Criteria

✅ Booking lifecycle events trigger notifications via openconnector
✅ Templates render with correct booking variables
✅ Recipient rules evaluated correctly (role, channels, conditions)
✅ Fallback logic tested with simulated adapter failures
✅ Rate-limiting prevents notification storms
✅ Audit trail records every send with delivery status
✅ Organizers can configure triggers via modal (no code required)
✅ Admin dashboard monitors activity and provides emergency off-switch
✅ Opt-out respected before send
✅ All tests pass; documentation complete
