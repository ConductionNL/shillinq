# Proposal: bookings-confirm-flow

`kind: feature` — implement the appointment confirmation flow with email notification, ICS calendar attachment, and calendar integration.

## Summary

Introduce the **appointment confirmation capability** for the Nextcloud Bookings app, enabling customers to confirm pending appointments via email links, calendar integration, and web portals. This change defines the confirmation workflow, ICS calendar generation, email delivery via openconnector, and confirmation-link token management.

The implementation provides:
- Confirmation tokens for email-based confirmation links
- ICS (iCalendar) attachment generation for email clients and calendar apps
- Email delivery via openconnector integration
- Web-based confirmation portal for authenticated customers
- Audit trail of confirmations per ADR-022
- Auto-confirmation rules (optional business logic triggers)

This change conforms to the shared `nextcloud-app` spec for app structure and integrates with OpenRegister's lifecycle patterns per ADR-031.

**Depends on:** `bookings-create-appointment` (provides `Appointment` register with pending confirmation status), `bookings-notification-triggers` (reuses notification infrastructure for confirmation emails).

## Motivation

Competitors unanimously require appointment confirmation workflows. Customers expect:
- Confirmation email with calendar attachment (Outlook, Google Calendar, Apple Calendar support)
- One-click confirmation link or calendar invite acceptance
- Timezone-aware calendar display
- Clear confirmation deadline before appointment cancels

Without confirmation, bookings are unreliable — no-shows remain uncaught, double-bookings unresolved. The confirmation flow is the operational bridge between customer booking and confirmed appointment.

The ICS standard (RFC 5545) enables seamless calendar integration: customers clicking a calendar app link confirm automatically; email-only workflows support a fallback web link.

This proposal is phase 2 of the multi-phase bookings feature set:

1. `bookings-create-appointment` (phase 1) — core appointment creation
2. `bookings-confirm-flow` (phase 2, this change) — appointment confirmation
3. `bookings-availability-slots` (phase 2) — availability computation
4. `bookings-reminder-notifications` (phase 3) — email/SMS reminders

## Affected Projects

- [x] **Project: nextcloud-bookings** — adds 1 new register/schema (`ConfirmationToken`), extends `Appointment` lifecycle with confirmation transitions, adds web confirmation portal `src/views/ConfirmationPortal.vue`, adds ICS generation utility, adds REST API endpoint `PATCH /ocs/v2.php/apps/bookings/api/v1/appointments/{id}/confirm`, adds manifest navigation entry
- [x] **Project: openregister** — consumes existing OR abstractions (lifecycle state machine per ADR-031, audit trail); no new OR features required beyond existing `x-openregister-lifecycle`
- [x] **Project: openconnector** — routes confirmation emails; no new adapters required (standard email channel)

## Scope

### In Scope

- **One new capability spec** (`bookings-confirm-flow`) defining the confirmation workflow, token lifecycle, ICS generation, and email delivery
- **Confirmation token register schema** — stores secure tokens with appointment references, expiration, status
- **Appointment lifecycle extension** — adds confirmation state transitions (`pending_confirmation → confirmed → completed` or `pending_confirmation → cancelled`)
- **ICS calendar file generation** — RFC 5545 compliant iCalendar with appointment details (DTSTART, DTEND, SUMMARY, LOCATION, DESCRIPTION, ATTACH with FMTTYPE)
- **Email delivery integration** — sends confirmation email via openconnector with ICS attachment + fallback web link
- **Web confirmation portal** — `src/views/ConfirmationPortal.vue` for token-based confirmation and password-protected re-confirmation
- **Confirmation token validation** — time-based expiration, one-time use (optional), rate limiting
- **Confirmation deadline** — appointments auto-cancel if not confirmed by deadline (e.g., 48 hours before appointment start)
- **Audit trail** — every token generation, confirmation attempt, and state change logged via OpenRegister
- **Timezone handling** — ICS includes TZID; email displays appointment in customer's local timezone

### Out of Scope

- **SMS confirmation** — owned by phase 3 spec `bookings-reminder-notifications`
- **Custom confirmation message templates** — uses templates from `bookings-notification-triggers`; template builder is phase 3+
- **Webhook callbacks** — confirmation status callbacks to external systems; phase 3+
- **Recurring appointment confirmation** — single appointments only; phase 2+
- **Group confirmation** — multi-attendee confirmation workflows; phase 3+

## Approach

One delta, adding ADDED and CHANGED Requirements to one brand-new spec + one extension to existing `bookings-create-appointment`:

**`bookings-confirm-flow`** — declares:
1. `ConfirmationToken` register with appointment reference, token string, expiration, status
2. Confirmation email delivery via openconnector
3. ICS calendar file generation per RFC 5545
4. Web confirmation portal
5. Confirmation state transitions and business rules (REQ-BCF-NNN series)
6. Audit trail integration

**Extension to `bookings-create-appointment`**:
- CHANGED REQ-BCA-005 to include confirmation workflow (was admin-only / customer-pending; now adds confirmation state machine)

The spec follows conduction-schema format (RFC 2119, `### REQ-BCF-NNN`, `#### Scenario:` with GIVEN/WHEN/THEN).

## New Dependencies

- `sabre/icalendar@^3.0` or `illuminate/ical@^1.0` — ICS generation (composer dependency)
- `openconnector` email channel adapter (existing; no new dependency)
- No new npm packages (use Nextcloud Vue modal + form components per ADR-004)

## Impact

- `lib/Settings/bookings_register.json` — adds 1 schema (`ConfirmationToken`); extends `Appointment` lifecycle
- `src/views/ConfirmationPortal.vue` — new file, token-based confirmation form
- `src/components/IcsGenerator.js` — new file, RFC 5545 ICS composition
- `src/api/confirmationApi.js` — new file, confirm endpoint client
- `lib/Controller/ConfirmationApiController.php` — new file, handles `PATCH /ocs/v2.php/apps/bookings/api/v1/appointments/{id}/confirm`
- `lib/Service/IcsService.php` — new file, ICS generation service
- Tests — 10+ unit + integration tests covering token generation, ICS schema, confirmation flows, timezone handling
- `src/manifest.json` — adds 1 navigation entry (Confirmations) if admin dashboard for pending confirmations is exposed

## Cross-Project Dependencies

- **OpenRegister** — depends on: `x-openregister-lifecycle` (state machine for confirmation transitions), audit trail (existing), relation validation (existing)
- **bookings-create-appointment** — depends on `Appointment` register with `pending_confirmation` status
- **bookings-notification-triggers** — reuses notification template system for confirmation email composition
- **openconnector** — depends on email channel adapter for delivery

## Risks

### Risk 1: ICS Calendar App Acceptance Behavior Varies

**Severity**: Low
**Description**: Different calendar apps (Outlook, Google Calendar, Apple Mail) interpret ICS ATTACH differently. Some auto-import; others prompt for confirmation.
**Mitigation**: ICS is dual-purpose: auto-confirm for calendar apps that support ACCEPT method; fallback to web link in email body for others. Test with 3+ major clients (Gmail, Outlook, Apple).

### Risk 2: Token Expiration and Re-confirmation Complexity

**Severity**: Medium
**Description**: If a customer loses the confirmation email, they need a way to re-request it. This requires either token regeneration (new email) or a password-protected re-confirmation portal.
**Mitigation**: Phase 1 uses email resend (customer portal shows "resend confirmation" button, generates new token). Phase 2+ supports password-protected re-confirmation without token if account is authenticated.

### Risk 3: Confirmation Deadline Automation

**Severity**: Medium
**Description**: Auto-cancelling unconfirmed appointments requires a background job / cron. If not implemented, unconfirmed appointments linger forever.
**Mitigation**: Declare confirmation deadline as a configurable business rule in the schema. Implementation cycle includes a scheduled task (`lib/BackgroundJob/CancelUnconfirmedAppointments.php`) that runs daily and cancels `pending_confirmation` appointments past their deadline.

### Risk 4: Timezone Information in ICS

**Severity**: Low
**Description**: ICS TZID references require the calendar app to have matching timezone definitions. Mismatches can cause double-booked displays.
**Mitigation**: Include VTIMEZONE block in ICS (RFC 5545 §3.6.5) with full DAYLIGHT rules for the customer's timezone. Test with UTC+1 (Amsterdam) and UTC+8 (Asia) timezones.

## Rollback Strategy

Spec-only change (phase 1). To roll back:
1. Revert the commit
2. Delete the change folder
3. No runtime impact; `ConfirmationToken` register remains queryable but unreferenced

After implementation, rollback follows standard pattern: revert the implementing PR; `ConfirmationToken` records remain unharmed; `Appointment.status` remains queryable.

## Open Questions

1. **Confirmation deadline duration** — should it be globally configured (e.g., 48h), per-appointment, or per-service? Resolved during design review with stakeholders.
2. **ICS METHOD property** — should be REQUEST (customer must explicitly confirm) or PUBLISH (calendar app auto-imports as tentative)? Resolved during dev against customer feedback.
3. **Re-confirmation UX** — self-serve button in customer portal, or admin manual re-send? Affects customer autonomy vs. data control.
4. **Multi-language ICS** — should ICS body be in customer language or Nextcloud default language? Resolved during i18n review (ADR-007, ADR-025).
