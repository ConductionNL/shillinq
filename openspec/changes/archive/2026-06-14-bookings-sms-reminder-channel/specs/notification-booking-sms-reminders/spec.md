# Spec: Booking SMS Reminder Channel

**Scope:** notification-booking-sms-reminders
**Tier:** T2 — capability
**Status:** draft
**Applies to:** Nextcloud Booking

## Overview

Configurable SMS reminder channels for booking reminders with pluggable
provider support (MessageBird, Twilio). Operators configure SMS channel,
select provider, customize message template, set delivery timing and
retry behavior. Channels integrate with OR's notification engine for
SMS dispatch on booking lifecycle events.

## Data Model

### BookingSmsReminderChannel

SMS reminder channel with provider configuration and message template.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unique channel identifier |
| name | string | Yes | Human-readable channel name |
| status | enum | Yes | `active` \| `inactive` \| `archived` |
| provider | string | Yes | SMS provider: `messagebird` \| `twilio` \| openconnector custom |
| providerConfig | object | Yes | Provider-specific config (API key, account ID, etc.) |
| messageTemplate | string | Yes | SMS message text with {{variables}}; ≤160 chars |
| sendMinutesBefore | integer | Yes | Minutes before booking start to send reminder (e.g., 1440 = 24h) |
| fallbackPhoneNumber | string | No | Default phone number if booking contact unavailable (E.164 format) |
| senderId | string | No | Sender ID/name displayed to recipient (provider-dependent) |
| phoneNumberFormat | string | No | Locale phone format: `e164` (default), `nl_domestic`, etc. |
| retryCount | integer | No | Number of delivery retry attempts (default: 3) |
| retryIntervalSeconds | integer | No | Seconds between retries (default: 300) |
| createdAt | datetime | Yes | Channel creation timestamp |
| updatedAt | datetime | Yes | Last modification timestamp |
| activatedAt | datetime | No | When channel was first activated |

**Relations:**
- Implies variable bindings (implicit via {{}} placeholders in messageTemplate)

## SMS Message Variables

All SMS channels support the following variable substitutions via
`{{variableName}}` syntax in messageTemplate field:

| Variable | Type | Example | Description |
|----------|------|---------|-------------|
| `{{customerName}}` | string | "Jan van der Berg" | Customer full name |
| `{{bookingRef}}` | string | "BK20260521001" | Booking reference/ID |
| `{{bookingDate}}` | date | "21 mei" | Booking date in locale format |
| `{{bookingTime}}` | time | "14:30" | Booking start time in locale format |
| `{{bookingLocation}}` | string | "Kantoor Amsterdam, K3" | Physical or virtual location (truncated if >30 chars) |
| `{{organizationName}}` | string | "Example BV" | Organization/business name (truncated if >20 chars) |
| `{{bookingUrl}}` | uri | "https://example.nl/book/ABC" | Booking confirmation or reschedule URL |

Variables are substituted at dispatch time by OR's notification engine.
Undefined variables rendered as empty string. Variable values exceeding
SMS character limits (e.g., location >30 chars) truncated with ellipsis.

## Requirements

### Requirement: REQ-SMS-001: Channel CRUD operations

#### Scenario: Create a new SMS reminder channel

**GIVEN** an operator accessing the SMS Reminder Channels admin page,
**WHEN** the operator clicks "Create New Channel" and fills in:
- Channel name: "SMS Reminders via MessageBird"
- Provider: "messagebird" (dropdown)
- API key: "live_XXXXXXXXXXXX" (securely stored via openconnector)
- Sender ID: "Bookings"
- Send time: "24 hours before booking" (1440 minutes)
- Message template: "Hallo {{customerName}}, herinnering: uw boeking op {{bookingDate}} om {{bookingTime}}. Ref: {{bookingRef}}"
**THEN** the channel is saved with status `active` and shows in the
channel list. Channel is immediately available for booking dispatch.

### Requirement: REQ-SMS-002: Channel provider selection via openconnector

#### Scenario: Switch SMS provider

**GIVEN** a channel configured with MessageBird,
**WHEN** the operator navigates to channel edit, selects provider
dropdown, and switches to "twilio",
**THEN** the UI requests new Twilio credentials, old credentials
discarded, and channel applies new provider on next send.

### Requirement: REQ-SMS-003: Message template validation and character limit

#### Scenario: Validate SMS message length

**GIVEN** an operator editing the message template,
**WHEN** the operator enters a template: "Hallo {{customerName}}, uw boeking op {{bookingDate}} om {{bookingTime}} in {{bookingLocation}}. Meer info: {{bookingUrl}}",
**THEN** the system displays character count (with sample variable
values substituted) and warns if total exceeds 160 characters. Save is
rejected if template is too long.

### Requirement: REQ-SMS-004: Phone number validation per locale

#### Scenario: Validate fallback phone number

**GIVEN** an operator setting fallback phone number,
**WHEN** the operator enters "+31612345678",
**THEN** the system validates E.164 format and NL-specific rules
(starts with +31 or 06), accepts valid number, and rejects invalid
format with error message.

### Requirement: REQ-SMS-005: Delivery scheduling with send time

#### Scenario: Configure reminder send time

**GIVEN** a channel with `sendMinutesBefore: 1440` (24 hours),
**WHEN** a booking is created with start time "2026-05-22 14:30",
**THEN** OR's notification engine schedules SMS dispatch for
"2026-05-21 14:30" (±15 min tolerance window). SMS is sent at
scheduled time if channel is active.

### Requirement: REQ-SMS-006: Channel lifecycle — active to inactive

#### Scenario: Disable SMS channel temporarily

**GIVEN** a channel in status `active`,
**WHEN** the operator clicks "Disable Channel",
**THEN** the channel status changes to `inactive`. No SMS reminders
are dispatched from this channel. Operator can re-enable by clicking
"Activate Channel" (status back to `active`).

### Requirement: REQ-SMS-007: Channel lifecycle — to archived

#### Scenario: Archive a channel

**GIVEN** a channel in status `active` or `inactive`,
**WHEN** the operator clicks "Archive Channel",
**THEN** the channel status changes to `archived`. Channel is removed
from active channel list; previous SMS messages remain in history.
Archived channels cannot be reactivated; operator must create new
channel.

### Requirement: REQ-SMS-008: Retry logic for failed SMS sends

#### Scenario: Retry on SMS delivery failure

**GIVEN** a channel with `retryCount: 3` and `retryIntervalSeconds: 300`,
**WHEN** SMS dispatch fails (provider API error, network timeout),
**THEN** OR's notification engine retries send after 300 seconds
(5 minutes). Retry repeats up to 3 times. After 3rd failure, SMS is
marked failed; error logged for operator review.

### Requirement: REQ-SMS-009: Message variable substitution

#### Scenario: Render SMS with booking variables

**GIVEN** an active SMS channel with template:
"Hallo {{customerName}}, boeking {{bookingRef}} op {{bookingDate}} om {{bookingTime}}",
**WHEN** a booking is created for "Jan Jansen" with ref "BK001", date
"21 mei", time "14:30",
**THEN** the system substitutes variables and renders:
"Hallo Jan Jansen, boeking BK001 op 21 mei om 14:30". SMS is sent with
substituted message.

### Requirement: REQ-SMS-010: Long variable truncation for SMS character limit

#### Scenario: Truncate long variable values

**GIVEN** an SMS channel with template:
"Hallo {{customerName}}, uw boeking in {{bookingLocation}}. Ref: {{bookingRef}}",
**WHEN** booking location is "Amsterdam Hoofdkantoor, Kamer 3 Zuid-Oost",
**THEN** location is truncated to "Amsterdam Hoofdkantoor, K..." (≤30
chars). Final SMS is "Hallo Jan Jansen, uw boeking in Amsterdam Hoofdkantoor, K.... Ref: BK001".

### Requirement: REQ-SMS-011: Sender ID customization per provider

#### Scenario: Set custom sender ID

**GIVEN** an SMS channel with provider MessageBird,
**WHEN** the operator sets `senderId: "Bookings2026"`,
**THEN** SMS messages sent from this channel display "Bookings2026" as
sender (or number, provider-dependent). Operator can change sender ID
without reactivating channel.

### Requirement: REQ-SMS-012: Fallback phone number for missing contact

#### Scenario: Use fallback when booking contact unavailable

**GIVEN** a channel with `fallbackPhoneNumber: "+31123456789"` and a
booking with no customer phone number,
**WHEN** SMS dispatch is triggered,
**THEN** SMS is sent to fallback number. If both booking contact and
fallback missing, SMS dispatch fails with error logged.

### Requirement: REQ-SMS-013: Provider credential storage via openconnector

#### Scenario: Securely store provider credentials

**GIVEN** an operator configuring MessageBird API key "live_XXXXX",
**WHEN** the operator saves channel,
**THEN** credentials are stored via openconnector (centralized,
encrypted, not in Nextcloud schema). Credentials never exposed in logs
or admin UI (masked as "●●●●●●").

### Requirement: REQ-SMS-014: Test send for channel validation

#### Scenario: Send test SMS before activation

**GIVEN** a channel in draft/configuration state,
**WHEN** the operator clicks "Send Test SMS" with phone "+316...",
**THEN** the system renders template with mock variables
(customerName: "Test Customer", bookingRef: "TEST001", etc.) and sends
test SMS. Operator sees success or failure message.

### Requirement: REQ-SMS-015: Channel manifest entry and admin navigation

#### Scenario: Navigate to SMS Reminder Channels admin

**GIVEN** an operator logged in with booking admin permissions,
**WHEN** the operator navigates to Nextcloud Booking settings,
**THEN** a new "SMS Reminder Channels" menu item is visible. Clicking
opens the channel list and admin interface.

### Requirement: REQ-SMS-016: Audit trail for channel changes

#### Scenario: Track channel configuration changes

**GIVEN** a channel edited (e.g., sender ID changed),
**WHEN** the operator saves changes,
**THEN** a change is recorded in audit trail (timestamp, operator,
field changed, old value, new value). Audit trail visible in channel
detail history.

### Requirement: REQ-SMS-017: Multi-channel support (multiple SMS providers)

#### Scenario: Create fallback SMS channel

**GIVEN** an operator already has MessageBird channel active,
**WHEN** the operator creates a second channel with Twilio as
fallback provider,
**THEN** both channels exist independently. Booking dispatch uses
primary channel; if dispatch fails, future booking-lifecycle
integration can try fallback channel.

### Requirement: REQ-SMS-018: Provider API error handling

#### Scenario: Handle provider unavailability gracefully

**GIVEN** a channel configured with MessageBird and the provider API is
unreachable,
**WHEN** SMS dispatch is triggered,
**THEN** OR's notification engine catches API error, retries per
`retryCount` setting, and logs failure. Operator sees error status in
dispatch history; channel remains active for future sends.

### Requirement: REQ-SMS-019: SMS cost logging for billing integration

#### Scenario: Log SMS send cost for future billing

**GIVEN** a channel dispatches SMS via provider,
**WHEN** SMS send succeeds,
**THEN** the system logs: timestamp, provider, phone number (masked),
message length (single SMS or multi-part), cost (if available from
provider). Log available for future billing integration (T3).

### Requirement: REQ-SMS-020: Permissions and access control

#### Scenario: Restrict channel management to authorized operators

**GIVEN** an operator accessing SMS Reminder Channels admin,
**WHEN** the operator attempts channel CRUD operations,
**THEN** permission checks apply:
- `booking:sms-channel:list` — view all channels
- `booking:sms-channel:create` — create new channels
- `booking:sms-channel:edit` — edit active channels
- `booking:sms-channel:activate` — activate/deactivate channels
- `booking:sms-channel:delete` — archive channels

Unauthorized users see permission denied message.
