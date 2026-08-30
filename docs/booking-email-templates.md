---
sidebar_position: 21
title: Customizing Booking Email Templates
description: Operator guide for branded booking email templates — creating drafts, branding (logo, colours, footer), template variables, preview, publishing, and lifecycle.
---

# Customizing Booking Email Templates

Shillinq sends three kinds of branded transactional emails for every booking
lifecycle event:

- **Booking Confirmation** — sent immediately after a booking is confirmed.
- **Booking Reminder** — sent a configurable number of hours before the
  booking starts.
- **Booking Cancellation** — sent when a booking is cancelled.

Templates are **declarative**: subject, HTML body, plain-text body, branding
and lifecycle live on the template record. Dispatch and retries are handled
by OpenRegister's notification engine — there is no separate email service
to operate.

## Outcomes

- One published template per type, per locale, branded for your organization.
- Operator-only customization of branding (logo, accent colour, footer) and
  text — no developer involvement required.
- Versioned rollout: a published template stays active until the new draft is
  published.

## Prerequisites

- The `template-administrator` (or `booking-administrator`) role.
- A CDN or document-store URL hosting the organization logo image.

## 1. Open the template list

In the Shillinq navigation choose **Communication →**:

- **Confirmation Templates**
- **Reminder Templates**
- **Cancellation Templates**

Each list shows every template with its locale (nl/en), subject line, status
(`draft`, `published`, `archived`) and first-activated timestamp. Six default
templates ship with the app — two per type, one Dutch (nl) and one English
(en) — already published. Use them as a starting point or clone them to a
draft for editing.

## 2. Create a draft

Click **Create** and fill in:

| Field | Notes |
|---|---|
| Name | Internal label shown in the admin list. |
| Locale | `nl` or `en`. The notification engine picks the locale matching the customer's language preference at dispatch. |
| Subject line | ≤78 characters **after** variable substitution. Test with a long booking reference to be safe. |
| HTML body | Inline styles only. Allowed elements: `div`, `p`, `h1`-`h6`, `a`, `img`, `table`, `tr`, `td`, `th`, `tbody`, `thead`, `ul`, `ol`, `li`, `br`, `span`, `strong`, `em`, `b`, `i`, `hr`. No `<style>`, `<link>`, `<script>` or JavaScript. |
| Plain-text body | Always required as the fallback for email clients without HTML rendering. |
| Logo URL | CDN or document-store URI. Inline base64 images are rejected — they bloat email size and cannot be refreshed without resending. |
| Accent colour | Six-digit hex code (e.g. `#0066cc`). |
| Footer text | Address, contact, legal notices. Free text. |
| Sender name / address | Optional. Overrides the global default at dispatch. |

For reminder templates also set **Hours before booking** (1–720). For
cancellation templates the **Include cancellation reason** toggle exposes
`{{cancellationReason}}` as a variable in the template body.

The template saves as `draft` — it is **not yet dispatched**.

## 3. Variable reference

Templates use `{{variableName}}` placeholders. The notification engine
substitutes them at dispatch.

### Standard variables (all templates)

| Variable | Example | Description |
|---|---|---|
| `{{customerName}}` | Femke Jansen | Full name of the customer. |
| `{{bookingRef}}` | BK20260521042 | Booking reference / ID. |
| `{{bookingDate}}` | 22 mei 2026 | Booking date in the customer locale. |
| `{{bookingTime}}` | 10:00 | Booking start time in the customer locale. |
| `{{bookingLocation}}` | Kantoor Amsterdam, Kamer 3 | Physical or virtual location. |
| `{{organizationName}}` | Example BV | Organization or business name. |

### Reminder-only variables

| Variable | Example | Description |
|---|---|---|
| `{{hoursUntilBooking}}` | 24 | Hours until booking start (calculated at dispatch). |

### Cancellation-only variables (when "Include cancellation reason" is on)

| Variable | Example | Description |
|---|---|---|
| `{{cancellationReason}}` | Customer requested cancellation | Reason recorded at cancellation time. |

Undefined variables render as empty string — never `undefined` or
`{{variableName}}`.

## 4. Preview before publishing

The detail view shows two read-only fields rendered with sample data:

- **Subject preview (sample data)** — your subject with example values.
- **Body preview (sample data)** — your plain-text body with the same
  example values.

Additional calculated fields warn before publish:

- **Rendered subject length** — turns red at 78 (NFR-BET-002).
- **Body size (bytes)** — turns red near 102 KB (NFR-BET-003).
- **HTML whitelist valid** — `false` means the body contains a forbidden
  tag, `<script>`, `<style>`, or `<link>`.

Treat any red indicator as a publish blocker.

## 5. Publish

When the previews look right and all warnings are clear, choose **Publish**
from the lifecycle actions. Status changes from `draft` to `published` and
`First activated` is stamped with the current timestamp.

A published template is **immediately active** — the next matching booking
event dispatches it. Publishing a new version automatically supersedes the
previous one for that locale and template type.

## 6. Archive an obsolete template

When a template is no longer in use:

- **Concept archiveren** (draft) — never went live; archive directly.
- **Archiveren** (published) — retire an active template. Dispatched
  emails remain visible in history; no new dispatches use this template.

Archived templates are terminal — they cannot be reactivated. Clone them to a
new draft if you need the content back.

## 7. Branding customization

Three fields cover most operator branding needs without code or theme
changes:

- **Logo URL** — host the image on a stable CDN or your document store. The
  email renders the image inline; if the URL fails to load, recipients see
  the `{{organizationName}}` text fallback in the surrounding markup.
- **Accent colour** — pick one hex code; apply it consistently in the HTML
  body (the seed templates use it for the heading colour).
- **Footer text** — postal address, contact, legal lines. Free text.

You can use a **different logo or colour per template** (for example a
partner-branded cancellation template). Operators do not need to manage a
separate "branding profile" — every template carries its own.

## 8. Plain-text fallback best practices

Always write the plain-text body — many corporate Outlook installs strip
HTML rendering, and accessibility tools prefer plain-text.

- Keep paragraphs short.
- Repeat the booking reference and date clearly.
- Use `\n` for line breaks (the form input accepts them as Enter).
- Never put critical information in the HTML body only.

## 9. Email client rendering tips

Email rendering varies wildly. Stay defensive:

- **Inline styles only.** No `<style>` blocks. No external CSS via `<link>`.
- **Avoid CSS floats.** Use `<table>` for layout if a column structure is
  needed.
- **Keep images small** and host them on a CDN — Gmail caches images via
  proxy, so they need to be publicly reachable.
- **Test in Gmail (web + mobile app), Outlook (web + desktop), Apple Mail,
  Thunderbird, iOS Mail and the Gmail app** before publishing a new
  template — the [QA test plan](./Technical) lists this matrix.
- Don't rely on background images.
- Sub-7 KB HTML bodies render best across the spectrum (the 102 KB ceiling
  is the hard SMTP/anti-spam limit, not a target).

## 10. Permissions

| Permission slug | Role |
|---|---|
| `booking:template:list` | template-administrator, booking-administrator, auditor |
| `booking:template:create` | template-administrator |
| `booking:template:edit` | template-administrator, booking-administrator |
| `booking:template:publish` | template-administrator, booking-administrator |
| `booking:template:delete` | template-administrator |

`auditor` is read-only.

## 11. Version management

The draft → published → archived lifecycle gives operators a simple
versioning workflow:

1. **Clone a published template to draft.** The clone is independent — edit
   text and branding freely.
2. **Publish the new version.** The previous version is automatically
   superseded for that locale and template type — no dispatches use it
   anymore.
3. **Archive the old version** when you are confident the new one works.
   The history of dispatched emails using the old version stays queryable.

There is no scheduled-rollout feature in this release; publish takes effect
immediately.

## Troubleshooting

| Symptom | Likely cause | Action |
|---|---|---|
| "HTML whitelist valid" is false | Body contains `<style>`, `<script>`, `<link>` or an unsupported tag. | Remove the disallowed element; use inline styles only. |
| Subject is truncated in inbox | Rendered length exceeds 78 characters. | Shorten the static text — variable substitutions can push real subjects past the warning. |
| Variable shows as `{{customerName}}` literally in the email | Typo in variable name — `{{Customer Name}}` (with space) is undefined. | Match the case-sensitive variable list above exactly. |
| Logo not visible to recipients | Logo URL not publicly reachable, or Gmail's image proxy blocked it. | Host the logo on a public CDN or your document-store; do **not** use inline base64. |
| Reminder didn't fire | Reminder template is `draft`, the booking system event has not fired, or the dispatch window was missed by more than the tolerance. | Confirm the reminder is `published`; check the booking schedule and the notification engine logs. |
| Cancellation email has empty `{{cancellationReason}}` | "Include cancellation reason" was off at cancellation time, or the booking system did not record a reason. | Toggle the flag on; verify the booking-cancellation flow records the reason. |

## See also

- [SMS Reminder Channels](./sms-reminder-channels) — multi-channel companion
  surface for reminders.
- [Booking Templates API contract](./booking-email-templates-api) —
  developer-facing template dispatch contract.
