# Proposal: shillinq-config-to-settings

`kind: ia-refactor (config-surfacing)` per ADR-037 (canonical nav layout in
`src/menu-layout.json`) and ADR-022 (apps consume OpenRegister's generic object
surface). **No schema is added, removed, or changed; no new PHP runs.** This is a
pure information-architecture change: ~13 *configuration* and *audit-log* leaves
that today sit as top-level transactional navigation are relocated into a
**Settings** area with two sub-sections — "Governance & Compliance" and
"Templates & Notifications" — while every page stays routable for deep links and
e2e specs (the established `menu-layout.json` `removals` pattern, where a leaf is
dropped from the menu but its page keeps its route).

## Summary

Shillinq's left navigation currently mixes two fundamentally different kinds of
entries:

1. **Day-to-day transactional surfaces** — invoices, journals, the general
   ledger, stock movements, purchase orders — the things an operator touches
   every day.
2. **Configuration and append-only audit logs** — rule metadata that is set up
   once, e-mail/SMS templates, notification triggers, retention terms, and
   read-only compliance trails/exports.

The second category does not belong in the primary transactional tree. It is
"set up and forget" config plus read-only governance evidence — classic
**Settings** material. Surfacing ~13 such leaves at the top level inflates the
already-large Bookkeeping group (~55 leaves) and the bookings/inventory groups,
and buries the genuine daily-work entries.

This change moves them into a single **Settings** area (the existing
`type:"settings"` page already declared in `src/manifest.d/_…` and the existing
`section:"settings"` nav entry) organised as two sub-sections:

- **A. Governance & Compliance** (`GOVSET`): `AllocationRules` (pure rule
  metadata — "No `AllocationService.allocate()` ever runs"), the read-only
  bookkeeping log/export leaves (`BookkeepingAuditTrail`,
  `BookkeepingChangeHistory`, `BookkeepingActivityFeed`,
  `BookkeepingDestructionReport`, `BookkeepingComplianceExport`), and
  `Bewaartermijnen` (retention terms, today under the root `Administratie`
  group).
- **B. Templates & Notifications** (`TMPLSET`): the scattered template and
  notification-config leaves — `ConfirmationTemplates`, `ReminderTemplates`,
  `CancellationTemplates`, `CountTemplates`, `NotificationTriggers`,
  `NotificationMonitor`, `SmsReminderChannels`.

The mechanism is entirely declarative: add `removals` entries in
`src/menu-layout.json` for the relocated leaves, and declare the two
sub-sections under the existing Settings `type:"settings"` page's
`config.sections[]`. The schema fragments in `lib/Settings/register.d/*` and the
page declarations in `src/manifest.d/*` are **unchanged** — the pages still
exist, still bind the same schema, and stay reachable by route; only their menu
home moves. Audit logs remain strictly read-only.

This is a deduplication-safe IA move (ADR-012): nothing new is created, and the
relocated capabilities are not re-implemented anywhere — they are the same pages
re-homed.

**Depends on:**
- `src/menu-layout.json` (ADR-037 canonical nav layout — the single place that
  decides WHERE entries live; `relocations`/`removals` semantics documented in
  its `_meta`).
- The existing `Settings` page (`type:"settings"`, route `/settings`) and the
  existing `section:"settings"` nav entry in `src/manifest.json` — extended,
  not replaced.
- `shillinq-delegate-signing` (separate change) — that change owns
  `BookkeepingSigningTrail`. This change MUST NOT touch `BookkeepingSigningTrail`;
  it is deliberately excluded from cluster A so the two changes don't conflict.
- The page declarations and schema fragments stay authoritative for WHAT each
  page is:
  - `bookings-email-templates.json` (`ConfirmationTemplates`,
    `ReminderTemplates`, `CancellationTemplates`)
  - `inventory-cycle-count.json` (`CountTemplates`)
  - `bookings-notification-triggers.json` (`NotificationTriggers`,
    `NotificationMonitor`)
  - `bookings-sms-reminder-channel.json` (`SmsReminderChannels`)
  - the Bookkeeping group + root `Administratie` group in `src/manifest.json`
    (`AllocationRules`, the five log/export leaves, `Bewaartermijnen`).

## Why now

The 2026-06 fleet IA refactor is collapsing shillinq's sprawling top-level nav
into intent-shaped groups (`BankingTreasury`, `Sales`, `Purchasing`,
`Compliance`, …) via `menu-layout.json` relocations. The leftover after that
pass is config/log noise that no relocation target fits, because these are not
transactional domains — they are Settings. Re-homing them now finishes the IA
pass and gives the app a conventional "everything you configure lives under
Settings" model, matching the rest of the fleet.

## Out of scope

- **No schema or data change.** Every relocated page binds the same register +
  schema it does today. No `register.d` fragment is edited.
- **No `BookkeepingSigningTrail`** — owned by `shillinq-delegate-signing`.
- **No new audit/log capability** — the five log/export leaves are surfaced
  read-only exactly as built; this change does not add write paths.
- **No removal of routes** — `removals` only drops the *menu* entry; routes
  survive for deep links and e2e (per `menu-layout.json` `_meta`).
- **No backend/PHP work** — there is no service to add; ADR-031 config stays in
  schema metadata, ADR-022 CRUD stays on OpenRegister's object surface.
