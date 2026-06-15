# Spec: templates-notifications-settings

**Status:** proposed
**Scope:** shillinq
**Tier:** T1 (information architecture / configuration surfacing)
**Depends on:**
- `src/menu-layout.json` (ADR-037 canonical nav layout — `removals` semantics:
  leaf drops from menu, page route survives).
- The existing `Settings` page (`type:"settings"`, route `/settings`) in
  `src/manifest.json` — extended with a `templates-notifications` section.
- The existing `manifest.d` fragments (unchanged): `bookings-email-templates.json`
  (`ConfirmationTemplates`, `ReminderTemplates`, `CancellationTemplates`),
  `inventory-cycle-count.json` (`CountTemplates`),
  `bookings-notification-triggers.json` (`NotificationTriggers`,
  `NotificationMonitor`), `bookings-sms-reminder-channel.json`
  (`SmsReminderChannels`).

## ADDED Requirements

@e2e exclude unbuilt UI: the Settings "Templates & Notifications" sub-section
container is not yet rendered; the relocated pages themselves already exist and
stay routable.

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

## REMOVED Requirements

### Requirement: REQ-TMPLSET-900 — Template and notification-config leaves SHALL NOT appear scattered across top-level transactional navigation

These seven leaves SHALL NOT be surfaced as scattered top-level transactional
nav; their single home MUST be Settings → Templates & Notifications
(REQ-TMPLSET-001). This REMOVED block records that the scattered top-level menu
placement is retired (the pages/routes are NOT removed; the `manifest.d`
fragments are NOT edited).

#### Scenario: Routes survive the menu removal

- **GIVEN** the relocated leaves were dropped from the menu via `removals`
- **WHEN** a user navigates directly to any of the seven routes (e.g. the
  `SmsReminderChannels` or `CountTemplates` route)
- **THEN** the page MUST render exactly as before — the menu entry is gone but
  the route is preserved
