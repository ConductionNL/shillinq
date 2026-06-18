---
status: done
---

# templates-notifications-settings Specification

## Purpose
Consolidates the scattered template and notification-configuration leaves from the bookings and inventory groups into a single Settings "Templates & Notifications" section while keeping every page directly routable via deep links and leaving the source manifest fragments unchanged. The relocation preserves each page's read/write posture: the NotificationMonitor stays a read-only delivery view, and the template, trigger, and SMS-channel pages remain editable configuration.
## Requirements
### Requirement: REQ-TMPLSET-001 — The system SHALL consolidate the scattered template and notification-config leaves into a single Settings "Templates & Notifications" section, keeping every page routable

The seven leaves — `ConfirmationTemplates`, `ReminderTemplates`, `CancellationTemplates`, `CountTemplates`, `NotificationTriggers`, `NotificationMonitor`, and `SmsReminderChannels` — currently scattered across the bookings and inventory groups (declared in `bookings-email-templates.json`, `inventory-cycle-count.json`, `bookings-notification-triggers.json`, `bookings-sms-reminder-channel.json`) MUST be removed from the transactional navigation tree by adding their leaf ids to `removals` in `src/menu-layout.json`, and re-homed under a
`templates-notifications` section declared in the existing `Settings`
(`type:"settings"`) page's `config.sections[]`. Each page's `route` MUST
continue to resolve directly (deep link). The four source `manifest.d` fragments
MUST remain unchanged — they still declare WHAT each page is; only WHERE it lives
in the menu moves, per ADR-037.

#### Scenario: Template and notification leaves are gone from the bookings and inventory groups

- **GIVEN** the rebuilt shillinq front-end with the updated `menu-layout.json`
- **WHEN** an operator opens the left navigation
- **THEN** `ConfirmationTemplates`, `ReminderTemplates`, `CancellationTemplates`,
  `CountTemplates`, `NotificationTriggers`, `NotificationMonitor`, and
  `SmsReminderChannels` MUST NOT appear as top-level transactional leaves in any
  bookings/inventory group

#### Scenario: All seven are reachable from one Settings section

- **GIVEN** the Settings page with the new `templates-notifications` section
- **WHEN** the operator opens Settings and selects "Templates & Notifications"
- **THEN** the section MUST list links to all seven relocated pages in one place,
  and selecting each one MUST open the same page (same register + schema) the
  old scattered leaf opened

#### Scenario: Source fragments are not edited

- **GIVEN** the change diff
- **WHEN** `src/manifest.d/bookings-email-templates.json`,
  `src/manifest.d/inventory-cycle-count.json`,
  `src/manifest.d/bookings-notification-triggers.json`, and
  `src/manifest.d/bookings-sms-reminder-channel.json` are inspected
- **THEN** none of them MUST be modified by this change — the relocation is
  expressed only in `src/menu-layout.json` (`removals`) and the Settings page's
  `config.sections[]`

### Requirement: REQ-TMPLSET-002 — The NotificationMonitor SHALL remain a read-only delivery view; the template, trigger, and SMS-channel leaves remain editable config

`NotificationMonitor` MUST remain a read-only view of notification delivery, while the remaining six leaves MUST remain editable configuration (e-mail/cancellation/count templates, notification triggers, SMS reminder channels); relocating them into Settings MUST preserve each one's existing read/write posture; no posture
changes.

#### Scenario: Posture preserved after relocation

- **GIVEN** the seven pages opened from Settings → Templates & Notifications
- **WHEN** each is inspected for affordances
- **THEN** `NotificationMonitor` MUST remain read-only, and
  `ConfirmationTemplates`, `ReminderTemplates`, `CancellationTemplates`,
  `CountTemplates`, `NotificationTriggers`, and `SmsReminderChannels` MUST remain
  editable config — identical to their pre-move behaviour

