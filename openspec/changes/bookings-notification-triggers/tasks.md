# Tasks — Booking Notification Triggers

## Overview

This change adds a notification trigger system for the Bookings app, enabling
automated customer notifications on booking lifecycle events (created/changed/
cancelled/reminder) routed through openconnector channel adapters.

## Implementation Tasks

- [x] Task 1: Author `BookingNotificationTrigger` and `BookingNotificationTemplate` register schemas in `lib/Settings/bookings_register.json` per REQ-BNT-001, REQ-BNT-002; `openspec validate` exits 0
- [ ] Task 2: Create `BookingNotificationService.php` service class with methods: `evaluateEventTrigger(event, booking)`, `dispatchNotification(trigger, recipient, template, booking)`, `recordAuditTrail(notification, status, reason)` per REQ-BNT-001, REQ-BNT-004, REQ-BNT-005
- [ ] Task 3: Implement Twig template rendering engine for variable substitution; register `{{ booking.* }}`, `{{ recipient.* }}`, `{{ system.* }}` variable namespaces per REQ-BNT-002; unit tests cover missing variables (rendered as empty)
- [ ] Task 4: Implement recipient rule evaluation logic: parse recipient YAML/JSON rules, evaluate conditions (price > 100, status == confirmed), return ordered recipient list per REQ-BNT-003; unit tests cover conditional skip and role resolution
- [ ] Task 5: Integrate openconnector channel adapter API; implement fallback logic (email → SMS → chat) per REQ-BNT-004; mock tests simulate adapter failures and verify fallback behavior
- [ ] Task 6: Wire booking lifecycle events (`created`, `changed`, `cancelled`) from OpenRegister to `BookingNotificationService` event listener per REQ-BNT-001; events must pass full booking object payload
- [ ] Task 7: Implement Nextcloud Background Job scheduler for `booking.reminder` triggers (24h/1h/15m before event); schedule jobs at booking create time per REQ-BNT-001; cron task evaluates hourly
- [ ] Task 8: Implement audit trail recording for every notification dispatch; create named audit events in OpenRegister per ADR-022 with fields: triggerName, triggerType, bookingId, recipient, channel, status, failureReason, retryCount, sentAt per REQ-BNT-005
- [ ] Task 9: Implement rate-limiting: 10 notifications/booking/hour, 100/organizer/day; deduplication within 5-min window per REQ-BNT-006; backend middleware queues rate-limit violations for admin review
- [ ] Task 10: Create `BookingNotificationConfigModal.vue` modal component per ADR-004 (isolated in `src/modals/`) for organizers to enable/disable triggers, customize recipients, select channels per REQ-BNT-007; modal launched from booking detail page
- [ ] Task 11: Create notification management API endpoints: `GET /api/bookings/{id}/notification-triggers`, `PATCH /api/bookings/{id}/notification-triggers`, `GET /api/admin/notification-monitor`, `POST /api/admin/notification-monitor/disable-all` per REQ-BNT-007, REQ-BNT-008
- [ ] Task 12: Implement admin notification monitor dashboard (Settings > Bookings > Notification Monitor); display send counts, failure alerts, enable disable-all toggle, reset rate-limits per REQ-BNT-008; load <2s, refresh every 5 min
- [ ] Task 13: Implement recipient opt-out checking: query preferences before send, skip if opted out (log "skipped (opt-out)" in audit) per REQ-BNT-009; admin UI in settings for managing opt-outs
- [ ] Task 14: Seed three default notification templates (`booking.created`, `booking.changed`, `booking.cancelled`) in Dutch (nl_NL) and English (en_US) per design.md; templates loaded on app install/update
- [ ] Task 15: Add i18n strings for UI labels and template placeholders (nl_NL, en_US): "Notifications", "Send confirmations", "Enable reminders", "Email address", "SMS phone", "Chat ID", etc.
- [ ] Task 16: Add PHPUnit tests for all core logic: service methods, template rendering, recipient evaluation, rate-limiting, audit trail, openconnector fallback, retry logic; `composer test` green
- [ ] Task 17: Add Playwright MCP tests for modal UI: open/close modal, enable/disable triggers, save config, verify API calls; test both organizer and admin perspectives
- [ ] Task 18: Update `openspec/architecture/adr-000-data-model.md` with new entities: `BookingNotificationTrigger`, `BookingNotificationTemplate`, `NotificationDelivery` (audit); cite ADR-031 (schema-declarative) and ADR-004 (modal isolation)
- [ ] Task 19: Author user guide `docs/user-guide/bookings/notification-triggers.md` with screenshots of modal, example templates, recipient configuration, and troubleshooting; commit screenshots to `docs/images/`
- [ ] Task 20: Add error handling: openconnector adapter unavailable, Twig syntax error in template, missing booking fields, rate-limit exceeded; all errors logged and audited; graceful degradation (send via fallback or queue for retry)

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
