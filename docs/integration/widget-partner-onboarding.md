---
title: Booking Self-service Widget — partner onboarding guide
---

This guide walks an operator and a partner through a complete onboarding
cycle: from a fresh Shillinq instance to a live booking on the partner's
website. It targets REQ-WSW-008 (operator workflow), REQ-WSW-009 (key
lifecycle / audit), and REQ-WSW-005 (partner integration). Each step ends
with a runnable command or a visible signal so the operator can verify
progress without round-tripping the partner.

> **Status**: phase-1 partner onboarding. The dedicated admin UI tracked
> under the follow-up `bookings-widget-admin-ui` slice (Task 18) will
> replace step 3 with a click-through experience; the OCC / API path
> below is the authoritative onboarding flow until that ships.

## Outcomes

- One `WidgetAccessKey` record per partner business, bcrypt-hashed at rest,
  with the plaintext key handed to the partner exactly once.
- Partner site embeds the widget through one of the four supported methods
  (REQ-WSW-004) and serves real availability from the Shillinq instance.
- Operator has an auditable lifecycle trail (created → rotated → revoked)
  for every issued key (REQ-WSW-009).

## Prerequisites

- Shillinq installed and enabled on a Nextcloud instance at, for example,
  `https://shillinq.example.com`.
- Administrator account with shell access to the Nextcloud container or
  REST access to the Shillinq admin API.
- Partner business already represented as a `business` record in
  Shillinq (this is the `administrationId` foreign key on the access
  key — set up during the standard Shillinq business onboarding).
- Partner has confirmed they need bookings (one or more `Service` and
  `Resource` records already configured for that business).

## Step 1 — confirm services and resources

Before issuing a key, verify the partner has at least one active
`Service` and one `Resource` so the widget has slots to render. From the
container shell:

```bash
docker exec nextcloud bash -c \
  "cd /var/www/html/custom_apps/shillinq && \
   php occ openregister:object:list shillinq Service \
     --filter status=active --filter administrationId=<admin-id>"
```

Expected output: at least one row. If empty, ask the partner to create
their service catalogue first; the widget has nothing useful to show
otherwise.

## Step 2 — mint the partner access key

Use the OCC bridge that wraps `WidgetAuthService::createApiKey()`. The
operator supplies the `administrationId` (the partner's business record
id) and a stable `businessId` (the slug shown to partners and stored in
the widget config). The plaintext key is returned **once** in stdout.

```bash
docker exec -u www-data nextcloud bash -c \
  "cd /var/www/html/custom_apps/shillinq && \
   php occ shillinq:widget:key:create \
     --administration-id=<admin-id> \
     --business-id=salon-001 \
     --actor=admin"
```

Expected output (sample):

```json
{
  "businessId":  "salon-001",
  "apiKey":      "bk_live_oH2bC4eU7fW9...truncated",
  "apiKeyPrefix":"bk_live_oH2bC4",
  "rateLimit":   100,
  "status":      "active"
}
```

> **Operator must capture the `apiKey` value at this moment.** The record
> in `oc_openregister_table_*_widgetaccesskey` only stores
> `apiKeyHash` + `apiKeyPrefix`; the plaintext is never queryable again.

If the OCC command is not yet wired, the same operation is available via
the admin REST endpoint:

```bash
curl -X POST \
  "https://shillinq.example.com/index.php/apps/shillinq/api/admin/widget/keys" \
  -H "OCS-APIREQUEST: true" \
  -u admin:<admin-password> \
  -H "Content-Type: application/json" \
  -d '{"administrationId":"<admin-id>","businessId":"salon-001"}'
```

## Step 3 — confirm the audit trail

Every key lifecycle event writes a `WidgetAccessKeyAuditEntry`
(REQ-WSW-009). Verify the `created` entry is present before handing the
key to the partner:

```bash
docker exec nextcloud bash -c \
  "cd /var/www/html/custom_apps/shillinq && \
   php occ openregister:object:list shillinq WidgetAccessKeyAuditEntry \
     --filter businessId=salon-001"
```

Expected: at least one entry with `action: created`, `actor: admin`,
`createdAt: <recent timestamp>`. If the entry is missing, do NOT hand
the key to the partner — investigate first.

## Step 4 — hand the key to the partner

Send the partner an out-of-band message (Signal, encrypted email, or
password manager share) containing:

- `businessId` — the public identifier shown in widget config
- `apiKey` — the plaintext key from Step 2
- `apiBase` — `https://shillinq.example.com/index.php/apps/shillinq`
- A pointer to [Widget Embed methods](./widget-embed.md) and the
  [Widget API reference](./widget-api-reference.md).

Recommend the partner store the key in their secrets manager and never
commit it to source control.

## Step 5 — partner installs the widget

The partner picks one of the four embed methods (REQ-WSW-004). The
quickest end-to-end smoke test is the script-tag method, which works on
any plain HTML page:

```html
<!doctype html>
<html>
  <body>
    <div id="booking-widget"></div>
    <script src="https://shillinq.example.com/apps/shillinq/widget.js"></script>
    <script>
      BookingWidget.init({
        businessId: 'salon-001',
        apiBase: 'https://shillinq.example.com/index.php/apps/shillinq',
        apiKey: 'bk_live_oH2bC4eU7fW9...',
        containerId: 'booking-widget',
        lang: 'nl',
        primaryColor: '#ff6b6b',
      })
    </script>
  </body>
</html>
```

Expected: the widget renders with the service list pulled from
`GET /api/widget/services`. The partner can navigate
service → date+time → details → review and create an appointment.

For the iframe, npm, and web-component methods, see
[Widget Embed methods](./widget-embed.md).

## Step 6 — verify a live booking end-to-end

Operator confirms the widget round-trips through the backend:

```bash
docker exec nextcloud bash -c \
  "cd /var/www/html/custom_apps/shillinq && \
   php occ openregister:object:list shillinq Appointment \
     --filter administrationId=<admin-id> \
     --order createdAt:desc --limit 1"
```

Expected: one row whose `customerEmail` matches the value the partner
typed into the widget. If empty, check the Nextcloud log for entries
prefixed `[WidgetApi]` — every server-side error path logs the businessId
and exception message (REQ-WSW-007).

## Step 7 — set the rotation reminder

API keys rotate every 30 days; the predecessor remains valid for a
7-day grace window (REQ-WSW-009). Schedule a calendar reminder for
day 23 to issue the next key.

```bash
docker exec -u www-data nextcloud bash -c \
  "cd /var/www/html/custom_apps/shillinq && \
   php occ shillinq:widget:key:rotate \
     --business-id=salon-001 \
     --actor=admin"
```

The command returns a new plaintext key (Step 2 semantics). The previous
key transitions from `active` to `rotating` and remains valid until the
grace window closes.

## Troubleshooting

| Symptom                                | Likely cause                                       | Fix                                                                 |
| -------------------------------------- | -------------------------------------------------- | ------------------------------------------------------------------- |
| Widget shows "Configuration error"     | API key was revoked or the businessId is wrong    | Re-issue the key via Step 2 and update the partner's embed config. |
| Widget shows "Too many requests"       | Per-business rate limit (default 100/min) hit     | Investigate partner traffic or raise `rateLimit` on the key record. |
| Widget shows "This slot was just booked"| Real race — slot collision detected (409)         | Slots auto-refresh; user picks another slot. Expected behaviour.   |
| Slots panel is empty                   | Resource opening/closing window excludes the date | Verify Resource.openingTimes for the partner's business.            |
| No `Appointment` rows after a booking  | OR write rejected — check NC logs                 | `docker exec nextcloud tail -n 200 data/nextcloud.log`.            |
| Audit trail entry missing              | OR persistence error — fail closed                | Re-run Step 2; investigate `oc_openregister_*` table state.        |

## Revocation

If a key is leaked or the partner offboards:

```bash
docker exec -u www-data nextcloud bash -c \
  "cd /var/www/html/custom_apps/shillinq && \
   php occ shillinq:widget:key:revoke \
     --business-id=salon-001 \
     --actor=admin"
```

Revocation is immediate (no grace window). The audit trail records an
entry with `action: revoked` and the actor.

## References

- [Widget embed methods](./widget-embed.md) — the four supported
  integration methods (iframe, script, npm, web component) and CSS
  customisation surface.
- [Widget API reference](./widget-api-reference.md) — REST contract for
  `GET /services`, `GET /slots`, `POST /appointments`, error tables.
- `openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md`
  — authoritative requirements (REQ-WSW-*) and scenarios.
