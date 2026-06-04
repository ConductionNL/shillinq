# Design — Bank Connectors

status: pr-created

## Decisions

### D1 — PSD2 credentials live in openconnector, NC AppConfig holds shillinq-side settings only

Aggregator OAuth client id/secret, refresh tokens, and consent-flow
state MUST live in openconnector's `Source` registry — that's what
openconnector is for. shillinq's `BankConnection` record carries only
the consent reference (a non-credential identifier issued by the
aggregator) and the source slug pointing at the openconnector source.

shillinq-side connector settings (default aggregator for new
administrations, default consent-renewal notification recipient) live
in NC's `IAppConfig` via the existing `SettingsController` /
`SettingsService`. No `shillinq_bank_*` database table.

This split makes credential rotation an openconnector operation (no
shillinq deploy needed) and keeps shillinq's data model credential-
free (auditor-friendly: no risk of leaking secrets via register
exports).

**Alternative considered**: Store credentials in shillinq with
encrypted fields. Rejected — duplicates openconnector's reason for
existing, forces shillinq to track PSD2 protocol changes, and creates
an audit-blind spot for credential rotation.

### D2 — Declarative state machine with time-based transition

Per ADR-031, `BankConnection` lifecycle is `pending → active →
expiring → expired / revoked`, declared as `x-openregister-lifecycle`.
The `active → expiring` transition is **time-based**: fires
automatically 14 days before `consentExpiresAt`. The expiring state
fires a notification to the configured recipient (per REQ-BC-006).

**Alternative considered**: Run a cron poll to detect expiring
connections. Rejected — that's a TimedJob anti-pattern when OR
declares time-based transitions as a first-class lifecycle feature.

### D3 — Transaction polling is an OR `ScheduledWorkflow`, not a TimedJob

Per ADR-031 §"Background jobs" path 2, transaction polling MUST be an
OR `ScheduledWorkflow` calling an n8n workflow that pulls transactions
from the aggregator (via openconnector source), normalises them to
CAMT.053 format, attaches via docudesk, and creates `BankStatement`
records. No shillinq-side `BankPollingJob extends TimedJob`.

**Alternative considered**: Author a per-app TimedJob. Rejected per
ADR-031.

### D4 — New-transaction notifications via `x-openregister-notifications`

Per ADR-031, new-transaction notifications are declared as
`x-openregister-notifications` on `BankStatement`. The notification
fires on record creation, resolves recipients (e.g. the connection's
configured operator), and fans out across channels (in-app, email,
n8n). No PHP `BankNotificationService`.

### D5 — Consent-renewal action routes to openconnector SCA

When operators click the consent-renewal action on a connection in
`expiring` or `expired` state, the UI hands off to openconnector's
SCA endpoint, which redirects to the bank's SCA UI. After SCA
completion, the bank redirects back to openconnector, which updates
the `Source` record's consent state, fires a CloudEvent, and
shillinq's `BankConnection` lifecycle transitions back to `active`.
The operator clicks once; the SCA flow itself lives entirely in the
bank's UI; no SCA logic in shillinq.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| PSD2 aggregator integration | openconnector `Source` registry | Consumed by slug per ADR-022; no aggregator HTTP client in shillinq |
| Bank transaction CAMT.053 generation | OR `ScheduledWorkflow` (workflow normalises aggregator JSON) | Workflow attaches the CAMT.053 via docudesk |
| Bank connection lifecycle | `x-openregister-lifecycle` on `BankConnection` | Declarative; consent expiry warning is time-based auto-transition |
| New-transaction notifications | `x-openregister-notifications` on `BankStatement` | Declarative recipient resolution + channel fan-out per ADR-031 |
| Consent expiry warning | `x-openregister-lifecycle` time-based transition | Fires 14 days before `consentExpiresAt`; emits notification |
| Consent-renewal SCA flow | openconnector SCA endpoint | Operator handed off; no SCA logic in shillinq |
| Audit trail | OR audit-trail-immutable | Consumed automatically |
| RBAC | OR authorization | `treasurer` role for connection management |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4) | Adds 1 menu entry + 1 index/detail page pair with renewal action |
| Statement attachment | docudesk attachment URI | Referenced from `BankStatement` |

**Net new code in implementation cycle**: 1 schema declaration + 1
manifest entry pair + 1 scheduled-workflow record. No new PHP service.

## Seed Data

None. Bank connections are operator-configured per administration
through the SCA consent flow; no template ships in this change.
