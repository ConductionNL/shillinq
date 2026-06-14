# Design — shillinq-config-to-settings

## Problem

Shillinq's left nav conflates daily transactional work with one-time
configuration and read-only governance evidence. Concretely, ~13 leaves are
config/logs, not transactions:

| Leaf | Today's home (manifest.json) | Kind |
|---|---|---|
| `AllocationRules` | Bookkeeping group (order 91) | Pure rule metadata (ADR-031); no service runs |
| `BookkeepingAuditTrail` | Bookkeeping group | Read-only audit log |
| `BookkeepingChangeHistory` | Bookkeeping group | Read-only change log |
| `BookkeepingActivityFeed` | Bookkeeping group | Read-only activity feed |
| `BookkeepingDestructionReport` | Bookkeeping group | Read-only retention/destruction report |
| `BookkeepingComplianceExport` | Bookkeeping group | Read-only compliance export |
| `Bewaartermijnen` | root `Administratie` group | Retention-term config |
| `ConfirmationTemplates` | `bookings-email-templates` fragment | E-mail template config |
| `ReminderTemplates` | `bookings-email-templates` fragment | E-mail template config |
| `CancellationTemplates` | `bookings-email-templates` fragment | E-mail template config |
| `CountTemplates` | `inventory-cycle-count` fragment | Cycle-count template config |
| `NotificationTriggers` | `bookings-notification-triggers` fragment | Notification rule config |
| `NotificationMonitor` | `bookings-notification-triggers` fragment | Notification delivery monitor (read) |
| `SmsReminderChannels` | `bookings-sms-reminder-channel` fragment | SMS channel config |

`BookkeepingSigningTrail` sits next to the log leaves in the Bookkeeping group
but is **deliberately excluded** — `shillinq-delegate-signing` retires/federates
it. Touching it here would collide with that change.

## Key decisions

### D1 — Use `menu-layout.json` `removals`, not deletion of pages

The canonical nav layout (`src/menu-layout.json`, ADR-037) already distinguishes
`relocations` (move a leaf to another *group*) from `removals` (drop a leaf from
the menu while its **page stays routable**, per the file's own `_meta`:
"their PAGES stay routable for deep links and e2e specs"). Because the Settings
target is a `type:"settings"` page rather than an ordinary nav group, the clean
mechanism is:

1. Add each relocated leaf id to `menu-layout.json` `removals` → it disappears
   from the transactional tree but keeps its `route`.
2. Declare it under the Settings page's `config.sections[]` so it is reachable
   and grouped under Settings.

This is preferred over `relocations` because `relocations` dissolves a source
*group* into a target *group* and reparents leaves as ordinary menu children;
the Settings page is not an ordinary menu group, and we want the Settings
sub-nav to be declared on the Settings page itself (single source of truth for
the Settings IA), not as floating menu children.

### D2 — Two sub-sections on the existing Settings page

`src/manifest.json` already declares one `type:"settings"` page (id `Settings`,
route `/settings`) whose `config.sections[]` currently holds `configuration` and
`version`. We **extend** that `sections` array with two new sections rather than
inventing a new Settings page:

- `governance-compliance` (title "Governance & Compliance")
- `templates-notifications` (title "Templates & Notifications")

Each new section presents its member pages as a sub-nav (links to the existing
routes). This keeps the existing `configuration`/`version` sections intact and
gives a single declared home for the relocated leaves. The existing
`section:"settings"` top-level nav entry (id `Settings`) is the menu anchor; no
second anchor is added.

### D3 — Audit logs stay read-only

The five governance log/export leaves (`BookkeepingAuditTrail`,
`BookkeepingChangeHistory`, `BookkeepingActivityFeed`,
`BookkeepingDestructionReport`, `BookkeepingComplianceExport`) and
`NotificationMonitor` are surfaced exactly as built — read-only views over the
existing register objects. This change does not add or expose any write path on
them. `AllocationRules` and the template/SMS/trigger leaves remain editable
config exactly as today; only their location moves.

### D4 — No schema, no register fragment, no PHP touched

Every page already binds its register + schema in its `manifest.d` fragment, and
those schemas live in `lib/Settings/register.d/*` (ADR-037). None of that is
edited. CRUD stays on OpenRegister's generic object surface (ADR-022); ADR-031
business logic stays in schema metadata. The diff is confined to two front-end
files: `src/menu-layout.json` and the Settings page block in `src/manifest.json`
(or, if the Settings page is later split into a `manifest.d` fragment, that
fragment).

## Alternatives considered

- **`relocations` into a new "Settings" menu group.** Rejected: it would create
  a second Settings surface parallel to the existing `type:"settings"` page,
  splitting the Settings IA across two places and breaking D2's single source of
  truth. `removals` + Settings-page sections keeps one Settings home.
- **Delete the leaves' pages outright.** Rejected: violates the
  "pages stay routable" rule in `menu-layout.json` `_meta`; deep links and e2e
  specs reference these routes. Removal must be menu-only.
- **Move `BookkeepingSigningTrail` here too.** Rejected: owned by
  `shillinq-delegate-signing`; would conflict. Explicitly excluded.
- **Hide config behind a feature flag / admin-only group instead of Settings.**
  Rejected: Settings is the conventional fleet home for config; an ad-hoc hidden
  group is non-discoverable and inconsistent with the rest of shillinq.

## Migration / rollout

No data migration — schemas and objects are untouched, so no `lib/Repair/*`
step is needed (the brief's migration guard applies only when objects are
moved/dropped; here they are not). Rollout is a pure manifest/menu-layout edit:

1. Add the 13 leaf ids to `menu-layout.json` `removals` (cluster A: 6 + cluster
   B: 7).
2. Add the two sections to the Settings page `config.sections[]`.
3. Rebuild the front-end bundle; `applyMenuRelocations` in `main.js` consumes
   `menu-layout.json` after the `manifest.d` fragments merge, so the leaves drop
   from the tree and the routes survive automatically.

Deep links (`/allocation-rules`, `/bewaartermijnen`, the log routes, the
template routes, …) keep working immediately. Existing e2e specs that navigate
by route are unaffected; specs that navigate by clicking the old menu leaf must
re-target via the Settings sub-nav (flagged in tasks).

## Risks

- **R1 — A relocated route is only reachable by deep link, not by any visible
  control, if the Settings sub-nav is mis-declared.** Mitigation: REQ-GOVSET-001
  / REQ-TMPLSET-001 require each relocated page to be linked from its Settings
  section; the verify step navigates Settings → section → page for all 13.
- **R2 — `BookkeepingSigningTrail` accidentally included.** Mitigation: it is
  named in the REMOVED/exclusion notes and asserted absent in Phase 0; verify
  step checks it is NOT in this change's `removals` additions.
- **R3 — Bundle cache (no `?v=` on shillinq main.js) hides the menu change in a
  browser.** Mitigation (ops, not spec): verify via fetch no-store / hard
  reload; documented in tasks Phase 4.
- **R4 — Drift between the page's `manifest.d` fragment label and the Settings
  sub-nav label.** Mitigation: the Settings section links by route/id and reuses
  the page's existing label; no relabel in this change.
